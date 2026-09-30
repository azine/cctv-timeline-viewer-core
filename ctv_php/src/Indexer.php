<?php

declare(strict_types=1);

namespace CtvPhp;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

final class Indexer
{
    private PDO $db;
    private array $config;

    public function __construct(Database $database, array $config)
    {
        $this->db = $database->pdo();
        $this->config = $config;
    }

    /** @return array{new:int,updated:int,missing:int,skipped:int,total:int} */
    public function indexPartition(array $camera, string $day): array
    {
        $path = $this->partitionPath($camera['source_path'], $camera['directory_pattern'], $day);
        if (!is_dir($path)) {
            $this->savePartitionState((int) $camera['id'], $day, 'missing', 0, 0);
            $missing = $this->db->prepare("UPDATE recordings SET availability='missing' WHERE camera_id=? AND partition_key=? AND availability='available'");
            $missing->execute([$camera['id'], $day]);
            $this->db->prepare('UPDATE cameras SET source_status = ?, source_error = NULL, last_scan_completed = ? WHERE id = ?')
                ->execute([is_dir($camera['source_path']) ? 'online' : 'offline', microtime(true), $camera['id']]);
            return ['new' => 0, 'updated' => 0, 'missing' => $missing->rowCount(), 'skipped' => 0, 'total' => 0];
        }
        return $this->indexDirectory($camera, $path, $day, true);
    }

    /** @return array{new:int,updated:int,missing:int,skipped:int,total:int} */
    public function indexFull(array $camera): array
    {
        return $this->indexDirectory($camera, $camera['source_path'], null, true);
    }

    public function discover(): int
    {
        $insert = $this->db->prepare(
            "INSERT OR IGNORE INTO cameras (name, source_path, timezone, indexing_mode, directory_pattern, source_status) VALUES (?, ?, ?, 'partitioned', '{YYYY}/{MM}/{DD}', 'unknown')"
        );
        $count = 0;
        foreach ($this->config['source_roots'] as $root) {
            if (!is_dir($root)) {
                continue;
            }
            foreach (new \DirectoryIterator($root) as $entry) {
                if ($entry->isDot() || !$entry->isDir()) {
                    continue;
                }
                $insert->execute([$entry->getFilename(), $entry->getRealPath(), $this->config['timezone']]);
                $count += $insert->rowCount();
            }
        }
        return $count;
    }

    public function extractTimestamp(string $filename, string $timezone): ?float
    {
        $patterns = [
            '/(\d{4})(\d{2})(\d{2})_?(\d{2})(\d{2})(\d{2})/',
            '/(\d{4})-(\d{2})-(\d{2})_?(\d{2})-?(\d{2})-?(\d{2})/',
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $filename, $matches)) {
                continue;
            }
            try {
                $zone = new DateTimeZone($timezone);
                $stamp = sprintf('%04d%02d%02d%02d%02d%02d', ...array_map('intval', array_slice($matches, 1, 6)));
                $date = DateTimeImmutable::createFromFormat('!YmdHis', $stamp, $zone);
                if ($date !== false) {
                    return (float) $date->getTimestamp();
                }
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    public function partitionPath(string $root, string $pattern, string $day): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new \InvalidArgumentException('Invalid date: ' . $day);
        }
        $relative = $this->validatePattern($pattern);
        $relative = str_replace(
            ['{YYYY}', '{YY}', '{MM}', '{DD}'],
            [$date->format('Y'), $date->format('y'), $date->format('m'), $date->format('d')],
            $relative
        );
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function validatePattern(string $pattern): string
    {
        $pattern = trim($pattern, " \t\n\r\0\x0B/");
        if ($pattern === '' || str_starts_with($pattern, DIRECTORY_SEPARATOR) || in_array('..', explode('/', $pattern), true)) {
            throw new \InvalidArgumentException('Invalid directory pattern.');
        }
        $remaining = str_replace(['{YYYY}', '{YY}', '{MM}', '{DD}'], '', $pattern);
        if (str_contains($remaining, '{') || str_contains($remaining, '}')) {
            throw new \InvalidArgumentException('Supported tokens: {YYYY}, {YY}, {MM}, {DD}.');
        }
        if ((!str_contains($pattern, '{YYYY}') && !str_contains($pattern, '{YY}')) || !str_contains($pattern, '{MM}') || !str_contains($pattern, '{DD}')) {
            throw new \InvalidArgumentException('Pattern must contain year, month and day tokens.');
        }
        return $pattern;
    }

    /** @return array{new:int,updated:int,missing:int,skipped:int,total:int} */
    private function indexDirectory(array $camera, string $directory, ?string $partitionKey, bool $reconcileMissing): array
    {
        $cameraId = (int) $camera['id'];
        $started = microtime(true);
        $this->db->prepare('UPDATE cameras SET source_status = ?, source_error = NULL, last_scan_started = ? WHERE id = ?')
            ->execute(['scanning', $started, $cameraId]);
        if ($partitionKey !== null) {
            $this->savePartitionState($cameraId, $partitionKey, 'scanning', 0, 0);
        }

        try {
            [$videos, $snapshots] = $this->listMedia($directory, $camera['timezone']);
            $total = count($videos);
            if ($partitionKey !== null) {
                $this->savePartitionState($cameraId, $partitionKey, 'scanning', 0, $total);
            }

            $existingSql = 'SELECT id, path, size, mtime, duration, thumbnail_path, availability FROM recordings WHERE camera_id = ?';
            $params = [$cameraId];
            if ($partitionKey !== null) {
                $existingSql .= ' AND partition_key = ?';
                $params[] = $partitionKey;
            }
            $stmt = $this->db->prepare($existingSql);
            $stmt->execute($params);
            $existing = [];
            foreach ($stmt as $row) {
                $existing[$row['path']] = $row;
            }

            $upsert = $this->db->prepare(<<<'SQL'
INSERT INTO recordings (camera_id, path, filename, start_ts, end_ts, duration, codec, resolution, fps, size, mtime, thumbnail_path, partition_key, media_kind, availability)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'video', 'available')
ON CONFLICT(camera_id, path) DO UPDATE SET
    filename=excluded.filename,
    start_ts=excluded.start_ts,
    end_ts=excluded.end_ts,
    duration=excluded.duration,
    codec=excluded.codec,
    resolution=excluded.resolution,
    fps=excluded.fps,
    size=excluded.size,
    mtime=excluded.mtime,
    thumbnail_path=excluded.thumbnail_path,
    partition_key=excluded.partition_key,
    availability='available'
SQL);
            $seen = [];
            $counts = ['new' => 0, 'updated' => 0, 'missing' => 0, 'skipped' => 0, 'total' => $total];
            $now = time();
            $settle = max(0, (int) $this->config['file_settle_seconds']);

            $this->db->beginTransaction();
            try {
                foreach ($videos as $index => $video) {
                    $path = $video['path'];
                    $seen[$path] = true;
                    $previous = $existing[$path] ?? null;
                    $unchanged = $previous !== null
                        && (int) $previous['size'] === $video['size']
                        && abs((float) $previous['mtime'] - $video['mtime']) < 0.001
                        && (float) $previous['duration'] > 0;

                    if ($unchanged) {
                        $thumbnail = $previous['thumbnail_path'];
                        if ($thumbnail === null || !is_file($thumbnail)) {
                            $start = $this->extractTimestamp($video['filename'], $camera['timezone']) ?? $video['mtime'];
                            $thumbnail = $this->nearestSnapshot($start, $snapshots);
                        }
                        if ($previous['availability'] !== 'available' || $thumbnail !== $previous['thumbnail_path']) {
                            $restore = $this->db->prepare('UPDATE recordings SET availability = ?, thumbnail_path = ? WHERE id = ?');
                            $restore->execute(['available', $thumbnail, $previous['id']]);
                        }
                        $counts['skipped']++;
                        continue;
                    }
                    if ($settle > 0 && ($now - (int) $video['mtime']) < $settle) {
                        $counts['skipped']++;
                        continue;
                    }

                    $start = $this->extractTimestamp($video['filename'], $camera['timezone']) ?? $video['mtime'];
                    $duration = Mp4Metadata::duration($path);
                    $end = $duration > 0 ? $start + $duration : null;
                    $thumbnail = $this->nearestSnapshot($start, $snapshots);
                    $upsert->execute([
                        $cameraId,
                        $path,
                        $video['filename'],
                        $start,
                        $end,
                        $duration,
                        '',
                        '',
                        0,
                        $video['size'],
                        $video['mtime'],
                        $thumbnail,
                        $partitionKey,
                    ]);
                    $counts[$previous === null ? 'new' : 'updated']++;
                }

                if ($reconcileMissing) {
                    $markMissing = $this->db->prepare('UPDATE recordings SET availability = ? WHERE id = ?');
                    foreach ($existing as $path => $row) {
                        if (!isset($seen[$path])) {
                            $markMissing->execute(['missing', $row['id']]);
                            $counts['missing']++;
                        }
                    }
                }
                $this->db->commit();
            } catch (\Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }

            $finished = microtime(true);
            $this->db->prepare('UPDATE cameras SET source_status = ?, source_error = NULL, last_scan_completed = ? WHERE id = ?')
                ->execute(['online', $finished, $cameraId]);
            if ($partitionKey !== null) {
                $this->savePartitionState($cameraId, $partitionKey, 'ready', $total, $total);
            }
            return $counts;
        } catch (\Throwable $error) {
            $this->db->prepare('UPDATE cameras SET source_status = ?, source_error = ?, last_scan_completed = ? WHERE id = ?')
                ->execute(['offline', $error->getMessage(), microtime(true), $cameraId]);
            if ($partitionKey !== null) {
                $this->savePartitionState($cameraId, $partitionKey, 'error', 0, 0);
            }
            throw $error;
        }
    }

    /** @return array{0:list<array{path:string,filename:string,size:int,mtime:float}>,1:list<array{path:string,ts:float}>} */
    private function listMedia(string $directory, string $timezone): array
    {
        $videos = [];
        $snapshots = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if ($extension === 'mp4') {
                $videos[] = [
                    'path' => $file->getPathname(),
                    'filename' => $file->getFilename(),
                    'size' => (int) $file->getSize(),
                    'mtime' => (float) $file->getMTime(),
                ];
            } elseif ($extension === 'jpg' || $extension === 'jpeg') {
                $ts = $this->extractTimestamp($file->getFilename(), $timezone);
                if ($ts !== null) {
                    $snapshots[] = ['path' => $file->getPathname(), 'ts' => $ts];
                }
            }
        }
        usort($videos, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        usort($snapshots, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
        return [$videos, $snapshots];
    }

    private function nearestSnapshot(float $start, array $snapshots): ?string
    {
        if ($snapshots === []) {
            return null;
        }
        $maxDistance = max(0, (int) $this->config['snapshot_max_distance_seconds']);
        $best = null;
        $bestDistance = INF;
        foreach ($snapshots as $snapshot) {
            $distance = abs($snapshot['ts'] - $start);
            if ($distance < $bestDistance) {
                $best = $snapshot['path'];
                $bestDistance = $distance;
            }
            if ($snapshot['ts'] > $start && $distance > $bestDistance) {
                break;
            }
        }
        return $best !== null && $bestDistance <= $maxDistance ? $best : null;
    }

    private function savePartitionState(int $cameraId, string $key, string $status, int $done, int $total): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO partitions (camera_id, partition_key, status, progress_done, progress_total, last_scanned)
VALUES (?, ?, ?, ?, ?, ?)
ON CONFLICT(camera_id, partition_key) DO UPDATE SET
    status=excluded.status,
    progress_done=excluded.progress_done,
    progress_total=excluded.progress_total,
    last_scanned=excluded.last_scanned
SQL);
        $stmt->execute([$cameraId, $key, $status, $done, $total, microtime(true)]);
    }
}
