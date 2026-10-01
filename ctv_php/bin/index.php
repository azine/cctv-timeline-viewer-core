#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CtvPhp\Indexer;
use function CtvPhp\config;
use function CtvPhp\database;

$options = getopt('', ['discover', 'camera:', 'date:', 'days:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<TXT
CCTV Timeline Viewer PHP indexer

Usage:
  php ctv_php/bin/index.php --discover
  php ctv_php/bin/index.php [--camera=ID] [--date=YYYY-MM-DD] [--days=N]

Without --date, partitioned cameras index today in each camera timezone.
--days=N also indexes the preceding N-1 days. Full-directory cameras ignore
--date/--days and reconcile their whole source.
TXT);
    exit(0);
}

$database = database();
$pdo = $database->pdo();
$indexer = new Indexer($database, config());

if (isset($options['discover'])) {
    $count = $indexer->discover();
    fwrite(STDOUT, "Discovered $count new camera(s).\n");
}

$params = [];
$sql = 'SELECT * FROM cameras';
if (isset($options['camera'])) {
    $sql .= ' WHERE id = ?';
    $params[] = (int) $options['camera'];
}
$sql .= ' ORDER BY name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cameras = $stmt->fetchAll();

if (!$cameras) {
    fwrite(STDOUT, "No cameras configured. Use --discover or add them in the web UI.\n");
    exit(0);
}

$days = max(1, (int) ($options['days'] ?? 1));
foreach ($cameras as $camera) {
    try {
        if ($camera['indexing_mode'] === 'full') {
            $result = $indexer->indexFull($camera);
            fwrite(STDOUT, sprintf("%s: %s\n", $camera['name'], json_encode($result, JSON_UNESCAPED_SLASHES)));
            continue;
        }
        $zone = new \DateTimeZone($camera['timezone']);
        $base = isset($options['date'])
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $options['date'], $zone)
            : new \DateTimeImmutable('today', $zone);
        if ($base === false) {
            throw new RuntimeException('Invalid --date value. Use YYYY-MM-DD.');
        }
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = $base->modify("-$offset days")->format('Y-m-d');
            $result = $indexer->indexPartition($camera, $date);
            fwrite(STDOUT, sprintf("%s %s: %s\n", $camera['name'], $date, json_encode($result, JSON_UNESCAPED_SLASHES)));
        }
    } catch (Throwable $error) {
        fwrite(STDERR, sprintf("%s: %s\n", $camera['name'], $error->getMessage()));
    }
}
