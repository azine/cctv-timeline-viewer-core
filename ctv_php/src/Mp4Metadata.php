<?php

declare(strict_types=1);

namespace CtvPhp;

/**
 * Minimal ISO-BMFF/MP4 metadata reader.
 *
 * Reolink FTP clips are fragmented MP4s: mvhd/mdhd duration is often zero,
 * while the real duration lives in moof/traf/trun sample tables. This reader
 * handles both ordinary MP4 duration and that fragmented layout without
 * requiring ffprobe or loading mdat video payloads into memory.
 */
final class Mp4Metadata
{
    public static function duration(string $path): float
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return 0.0;
        }

        try {
            $fileSize = filesize($path);
            if ($fileSize === false || $fileSize < 8) {
                return 0.0;
            }
            $fileSize = (int) $fileSize;

            $moov = self::findBox($handle, 0, $fileSize, 'moov');
            if ($moov === null) {
                return 0.0;
            }

            $mvhd = self::findBox($handle, $moov['payload'], $moov['end'], 'mvhd');
            if ($mvhd !== null) {
                [$timescale, $duration] = self::readMediaHeaderDuration($handle, $mvhd);
                if ($timescale > 0 && $duration > 0) {
                    return $duration / $timescale;
                }
            }

            [$timescales, $trexDurations] = self::readTrackDefaults($handle, $moov);
            if ($timescales === []) {
                return 0.0;
            }

            $trackDurations = array_fill_keys(array_keys($timescales), 0);
            foreach (self::boxes($handle, 0, $fileSize) as $box) {
                if ($box['type'] !== 'moof') {
                    continue;
                }
                foreach (self::boxes($handle, $box['payload'], $box['end']) as $traf) {
                    if ($traf['type'] !== 'traf') {
                        continue;
                    }
                    $tfhd = self::findBox($handle, $traf['payload'], $traf['end'], 'tfhd');
                    if ($tfhd === null) {
                        continue;
                    }
                    [$trackId, $tfhdDefaultDuration] = self::readTfhd($handle, $tfhd);
                    if ($trackId <= 0 || !isset($timescales[$trackId])) {
                        continue;
                    }
                    $defaultDuration = $tfhdDefaultDuration > 0
                        ? $tfhdDefaultDuration
                        : (int) ($trexDurations[$trackId] ?? 0);

                    foreach (self::boxes($handle, $traf['payload'], $traf['end']) as $child) {
                        if ($child['type'] !== 'trun') {
                            continue;
                        }
                        $trackDurations[$trackId] += self::readTrunDuration($handle, $child, $defaultDuration);
                    }
                }
            }

            $seconds = 0.0;
            foreach ($trackDurations as $trackId => $duration) {
                $timescale = (int) ($timescales[$trackId] ?? 0);
                if ($timescale > 0 && $duration > 0) {
                    $seconds = max($seconds, $duration / $timescale);
                }
            }
            return $seconds;
        } finally {
            fclose($handle);
        }
    }

    /** @return array{0:array<int,int>,1:array<int,int>} */
    private static function readTrackDefaults($handle, array $moov): array
    {
        $timescales = [];
        $trexDurations = [];

        foreach (self::boxes($handle, $moov['payload'], $moov['end']) as $child) {
            if ($child['type'] === 'trak') {
                $tkhd = self::findBox($handle, $child['payload'], $child['end'], 'tkhd');
                $mdia = self::findBox($handle, $child['payload'], $child['end'], 'mdia');
                if ($tkhd === null || $mdia === null) {
                    continue;
                }
                $trackId = self::readTkhdTrackId($handle, $tkhd);
                $mdhd = self::findBox($handle, $mdia['payload'], $mdia['end'], 'mdhd');
                if ($trackId <= 0 || $mdhd === null) {
                    continue;
                }
                [$timescale] = self::readMediaHeaderDuration($handle, $mdhd);
                if ($timescale > 0) {
                    $timescales[$trackId] = $timescale;
                }
            } elseif ($child['type'] === 'mvex') {
                foreach (self::boxes($handle, $child['payload'], $child['end']) as $trex) {
                    if ($trex['type'] !== 'trex') {
                        continue;
                    }
                    if (fseek($handle, $trex['payload']) !== 0) {
                        continue;
                    }
                    $payload = fread($handle, min(24, $trex['end'] - $trex['payload']));
                    if ($payload === false || strlen($payload) < 20) {
                        continue;
                    }
                    $trackId = self::u32(substr($payload, 4, 4));
                    $defaultDuration = self::u32(substr($payload, 12, 4));
                    if ($trackId > 0) {
                        $trexDurations[$trackId] = $defaultDuration;
                    }
                }
            }
        }
        return [$timescales, $trexDurations];
    }

    /** @return array{0:int,1:int} timescale,duration */
    private static function readMediaHeaderDuration($handle, array $box): array
    {
        if (fseek($handle, $box['payload']) !== 0) {
            return [0, 0];
        }
        $payload = fread($handle, min(40, $box['end'] - $box['payload']));
        if ($payload === false || strlen($payload) < 20) {
            return [0, 0];
        }
        $version = ord($payload[0]);
        if ($version === 1) {
            if (strlen($payload) < 32) {
                return [0, 0];
            }
            return [
                self::u32(substr($payload, 20, 4)),
                self::u64(substr($payload, 24, 8)),
            ];
        }
        return [
            self::u32(substr($payload, 12, 4)),
            self::u32(substr($payload, 16, 4)),
        ];
    }

    private static function readTkhdTrackId($handle, array $box): int
    {
        if (fseek($handle, $box['payload']) !== 0) {
            return 0;
        }
        $payload = fread($handle, min(32, $box['end'] - $box['payload']));
        if ($payload === false || strlen($payload) < 16) {
            return 0;
        }
        $version = ord($payload[0]);
        $offset = $version === 1 ? 20 : 12;
        return strlen($payload) >= $offset + 4 ? self::u32(substr($payload, $offset, 4)) : 0;
    }

    /** @return array{0:int,1:int} trackId,defaultSampleDuration */
    private static function readTfhd($handle, array $box): array
    {
        if (fseek($handle, $box['payload']) !== 0) {
            return [0, 0];
        }
        $payload = fread($handle, min(64, $box['end'] - $box['payload']));
        if ($payload === false || strlen($payload) < 8) {
            return [0, 0];
        }
        $flags = self::u24(substr($payload, 1, 3));
        $trackId = self::u32(substr($payload, 4, 4));
        $offset = 8;
        if ($flags & 0x000001) {
            $offset += 8;
        }
        if ($flags & 0x000002) {
            $offset += 4;
        }
        $defaultDuration = 0;
        if ($flags & 0x000008) {
            if (strlen($payload) < $offset + 4) {
                return [$trackId, 0];
            }
            $defaultDuration = self::u32(substr($payload, $offset, 4));
        }
        return [$trackId, $defaultDuration];
    }

    private static function readTrunDuration($handle, array $box, int $defaultDuration): int
    {
        if (fseek($handle, $box['payload']) !== 0) {
            return 0;
        }
        $fixed = fread($handle, min(20, $box['end'] - $box['payload']));
        if ($fixed === false || strlen($fixed) < 8) {
            return 0;
        }
        $flags = self::u24(substr($fixed, 1, 3));
        $sampleCount = self::u32(substr($fixed, 4, 4));
        if ($sampleCount <= 0) {
            return 0;
        }

        $offset = 8;
        if ($flags & 0x000001) {
            $offset += 4;
        }
        if ($flags & 0x000004) {
            $offset += 4;
        }

        if (!($flags & 0x000100)) {
            return $defaultDuration > 0 ? $defaultDuration * $sampleCount : 0;
        }

        $bytesPerSample = 4;
        if ($flags & 0x000200) $bytesPerSample += 4;
        if ($flags & 0x000400) $bytesPerSample += 4;
        if ($flags & 0x000800) $bytesPerSample += 4;
        $required = $offset + ($sampleCount * $bytesPerSample);
        if (($box['end'] - $box['payload']) < $required) {
            return 0;
        }
        if (fseek($handle, $box['payload'] + $offset) !== 0) {
            return 0;
        }

        $total = 0;
        for ($i = 0; $i < $sampleCount; $i++) {
            $durationBytes = fread($handle, 4);
            if ($durationBytes === false || strlen($durationBytes) !== 4) {
                return 0;
            }
            $total += self::u32($durationBytes);
            $skip = $bytesPerSample - 4;
            if ($skip > 0 && fseek($handle, $skip, SEEK_CUR) !== 0) {
                return 0;
            }
        }
        return $total;
    }

    /** @return array{type:string,payload:int,end:int}|null */
    private static function findBox($handle, int $from, int $to, string $wanted): ?array
    {
        foreach (self::boxes($handle, $from, $to) as $box) {
            if ($box['type'] === $wanted) {
                return $box;
            }
        }
        return null;
    }

    /** @return \Generator<int,array{type:string,payload:int,end:int}> */
    private static function boxes($handle, int $from, int $to): \Generator
    {
        $offset = $from;
        while ($offset + 8 <= $to) {
            if (fseek($handle, $offset) !== 0) {
                return;
            }
            $header = fread($handle, 8);
            if ($header === false || strlen($header) !== 8) {
                return;
            }
            $boxSize = self::u32(substr($header, 0, 4));
            $type = substr($header, 4, 4);
            $headerSize = 8;
            if ($boxSize === 1) {
                $large = fread($handle, 8);
                if ($large === false || strlen($large) !== 8) {
                    return;
                }
                $boxSize = self::u64($large);
                $headerSize = 16;
            } elseif ($boxSize === 0) {
                $boxSize = $to - $offset;
            }
            if ($boxSize < $headerSize || $offset + $boxSize > $to) {
                return;
            }
            yield [
                'type' => $type,
                'payload' => $offset + $headerSize,
                'end' => $offset + $boxSize,
            ];
            $offset += $boxSize;
        }
    }

    private static function u24(string $bytes): int
    {
        if (strlen($bytes) !== 3) {
            return 0;
        }
        return (ord($bytes[0]) << 16) | (ord($bytes[1]) << 8) | ord($bytes[2]);
    }

    private static function u32(string $bytes): int
    {
        if (strlen($bytes) !== 4) {
            return 0;
        }
        $value = unpack('Nvalue', $bytes);
        return (int) ($value['value'] ?? 0);
    }

    private static function u64(string $bytes): int
    {
        if (strlen($bytes) !== 8) {
            return 0;
        }
        $parts = unpack('Nhigh/Nlow', $bytes);
        return (int) ($parts['high'] * 4294967296 + $parts['low']);
    }
}
