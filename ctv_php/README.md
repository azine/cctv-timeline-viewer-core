# PHP shared-host backend

This directory adds a PHP/SQLite backend for the upstream `ctv_web` frontend.
It is intended for shared hosting where Python, FFmpeg and FFprobe cannot be
installed, while recordings already exist as H.264 MP4 files on the local
filesystem (for example Reolink FTP/FTPS uploads).

The original Python backend remains untouched. The PHP backend deliberately
supports **native playback only**: it does not implement the upstream Balanced
or Fast transcoding profiles, Home Assistant event enrichment, or the Python
background watcher.

## Requirements

- PHP 8.2+ (tested for the PHP 8.5 target environment)
- PDO SQLite / SQLite3
- Apache-compatible rewrites (`.htaccess`; LiteSpeed is compatible)
- Read access to the recording tree
- Write access to `ctv_php/var` (or the configured data directory)

No Composer packages, FFmpeg/FFprobe, Python, MySQL, or daemon process are
required. MP4 duration is read directly from ISO-BMFF metadata. Reolink
fragmented MP4s with zero `mvhd` duration are supported by summing their
`moof/traf/trun` sample durations.

## Configure

Copy the example and edit the recording root:

```bash
cp ctv_php/config.example.php ctv_php/config.php
```

For Reolink uploads such as:

```text
reolink-backup/
  FishEye1/2026/09/30/Lager FishEye 1_00_20260930001821.mp4
  FishEye2/2026/09/30/...
  FishEye3/2026/09/30/...
  Wand1/2026/09/30/...
```

set `source_roots` to the `reolink-backup` directory. The standard camera
configuration is:

- Indexing: **Partitioned by date**
- Directory pattern: `{YYYY}/{MM}/{DD}`
- Timezone: `Europe/Zurich`

The timestamp parser understands Reolink's embedded `YYYYMMDDHHMMSS` filename
format. JPEG snapshots uploaded next to the MP4 files are matched to nearby
recordings and reused as timeline thumbnails.

## Initial camera setup

The quickest setup is:

```bash
php ctv_php/bin/index.php --discover
php ctv_php/bin/index.php
```

`--discover` creates one camera for every first-level directory below the
configured source root. Cameras can also be added/edited from the upstream
**Cameras** view.

## Keep today's archive indexed

The web UI indexes a requested date synchronously when that day is opened. For
faster access to newly uploaded footage, add a shared-host cron entry. Every
five minutes is a reasonable starting point for four cameras:

```cron
*/5 * * * * /usr/local/bin/php /absolute/path/cctv-timeline-viewer-core/ctv_php/bin/index.php >/dev/null 2>&1
```

Adjust the PHP path to the hosting account (`which php`). To catch up yesterday
as well:

```bash
php ctv_php/bin/index.php --days=2
```

The indexer compares file size/mtime and does not re-parse unchanged MP4 files.
Files modified within the configured settle window (30 seconds by default) are
skipped so partially uploaded FTP files are not indexed.

## Web deployment

Point the addon/subdomain document root at:

```text
/path/to/cctv-timeline-viewer-core/ctv_php/public
```

`public/index.php` serves the existing `ctv_web` HTML/CSS/JS and implements
the JSON/video routes expected by that frontend. `.htaccess` rewrites
application routes to the front controller.

### Protect it

The PHP backend intentionally follows upstream standalone mode and does not
implement its own login. **Protect the entire document root with the hosting
provider's Directory Privacy / HTTP Basic Authentication feature.** Because the
video endpoint is under the same document root, that also protects original
recordings.

Do not expose the raw Reolink upload directory as an unauthenticated static web
directory.

## Video delivery

Native playback uses `/video/{recording_id}`. PHP validates the database record
and serves the original MP4 with HTTP byte-range support, which allows normal
HTML5 seeking. The original recording is never copied or transcoded.

For high-concurrency installations a web-server-native internal file handoff
would be more efficient than PHP streaming, but for a small four-camera archive
with a handful of viewers this keeps deployment portable on shared hosting.

## CLI options

```text
--discover             Add first-level source directories as cameras
--camera=ID            Index only one camera
--date=YYYY-MM-DD      Index a specific date for partitioned cameras
--days=N               Include N days ending on --date/today
--help                 Show usage
```

## Tests

```bash
find ctv_php -name '*.php' -print0 | xargs -0 -n1 php -l
php ctv_php/tests/run.php
node --check ctv_web/js/app.js
```
