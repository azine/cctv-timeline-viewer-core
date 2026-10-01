<?php

declare(strict_types=1);

return [
    // One or more directories containing the per-camera folders. Keep this
    // outside the public document root if possible.
    'source_roots' => [
        '/home/USER/path/to/reolink-backup',
    ],

    // SQLite lives here. The default (ctv_php/var) is already outside
    // ctv_php/public and is suitable for shared hosting.
    'data_dir' => __DIR__ . '/var',

    // Reuse the upstream vanilla-JS frontend directly from the fork.
    'web_root' => dirname(__DIR__) . '/ctv_web',

    'timezone' => 'Europe/Zurich',

    // Ignore files that may still be uploading over FTP/FTPS.
    'file_settle_seconds' => 30,

    // Reolink snapshots are matched to nearby recordings for thumbnails.
    'snapshot_max_distance_seconds' => 90,

    // Authentication is expected to be provided by the web server / hosting
    // control panel. Setting this false makes the camera configuration API
    // read-only, but does not itself add authentication.
    'admin' => true,
];
