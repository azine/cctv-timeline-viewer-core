# Stabilization handoff — 2026-10-05

## Validation on 2026-10-05

The user authorized simulated tests and publication after the implementation pause.
144 backend tests and all eight JavaScript suites pass locally. Regression checks
cover bounded Ingress ranges with exact bytes, unsatisfiable ranges, event replay,
history gaps, sanitization, thread-safe SSE delivery, cache concurrency and source
isolation. Process tests pass three shutdown/restart cycles with open clients.

Chrome playback through an aiohttp proxy reproducing Supervisor's 4,194,000-byte
buffering threshold passed in actual Home Assistant deployment mode: four real
recordings, speeds 1/4/16, finite event polling with no SSE request, individual
and all-camera decoder failures, and a physically unreadable indexed file followed
by a valid recording. The unreadable file was requested once, its tile retained
the error while the timeline crossed its interval, and the next file played.
The proxy emitted no `INGRESS ERROR` messages in these scenarios.

Container build, backup and shutdown checks are delegated to required GitHub CI
before tagging the candidate. See RELEASING.md for the immutable release pipeline.

## Findings and changes in the working tree

- `player.js` used a global stop for one video's media error, admission failure,
  exhausted realignment or buffering timeout. `failVideo` now detaches only that
  recording, preserves its interval and shows its tile error. Failed videos
  cannot hold the shared buffering barrier or provide the master clock. A map
  prevents automatic rerenders from loading the same failed file again. Explicit
  Play after Pause, quality changes and index rebuilds allow a fresh attempt.
  Removed automatic native retries/codec fallback: they kept failed files in
  the global barrier and generated more requests before reporting the error.
- `api/events.py` had worker threads writing directly into asyncio queues.
  SSE now uses the listener loop's `call_soon_threadsafe`. A bounded, locked
  event history supports `/api/events/poll` with cursor replay, restart/gap reset,
  payload sanitization, batch size limits and finite JSON responses.
- `app.js` uses polling in Home Assistant and SSE in standalone mode, starts
  after session resolution, closes on page hide, resumes on return, backs off
  failures, and coalesces state refreshes from a batch of events.
- Supervisor Ingress streams responses without Content-Length or with length
  >= 4,194,000 bytes; browser cancellation can then fail inside response.write.
  Native single-range responses in Home Assistant are limited to 2 MiB, with
  Starlette generating their Content-Range and Content-Length. The original
  offset is preserved. Full GET, HEAD, If-Range and multipart handling stay
  distinct. Unsatisfiable ranges return 416 instead of silently becoming a
  full 200 response. Multipart responses are not patched as a single file body.
- `native_media.py` formerly switched between remuxed/original bytes at the
  same URL based on current cache pressure, and ran FFmpeg under a global lock.
  Oversized files now consistently use the original; eligible files always use
  indexed copies. Capacity reservations include in-flight builds; preparation
  happens outside the lock. Concurrent requests for one file wait for its build.
  Pinned copies cannot be evicted. Capacity exhaustion returns 503 rather than
  switching representations. Source mutation during remux is rejected.

The particular recording the user plans to provide has not been analyzed; the
unreadable file above is a controlled fixture. These local results do not prove
that every disconnect on the user's Home Assistant deployment is eliminated.

## Primary references read during diagnosis

- Supervisor implementation:
  https://raw.githubusercontent.com/home-assistant/supervisor/main/supervisor/api/ingress.py
  (`_handle_request`, buffered vs streaming response at lines 251–279).
- RFC 9110 §15.3.7 permits a 206 response to contain a subset of requested data:
  https://www.rfc-editor.org/rfc/rfc9110.html#section-15.3.7

Remaining scope limits: full native GETs without Range and transcoded progressive
streams can still take the Supervisor streaming path. An app cannot suppress
Supervisor's own logs for every downstream disconnect. Check the actual request
headers in validation before attributing any remaining messages to a cause.

## Resource exhaustion follow-up

User confirmed the old global failure banner was a cached frontend. A later
beta.8 failure had `RuntimeError: can't start new thread` on health, event polls,
static pages and video endpoints. This is server resource exhaustion, not proof
that every recording is invalid.

Found an unbounded thread-per-scan thumbnail scheduling path: a semaphore
serialized execution but left one waiting daemon thread for each pending scan.
Replaced it with one worker and a bounded coalescing queue. Also capped AnyIO
request/file workers at 12, asyncio I/O workers at 4, native remux builds at 2,
and ffprobe/native-remux codec threads at 1. Health has a separate capacity slot;
event polling runs on the event loop without requiring a worker thread.

147 backend tests and all JavaScript suites pass. The real-server resource test
saturates all 12 request slots, verifies health/poll responses, queues 1000
thumbnail requests and serves 120 video ranges with 15 Python threads locally.
CI additionally runs this test inside the production image with a 64-task cgroup
limit and 256 MiB memory limit before a candidate can be tagged.

The user's traceback alone does not distinguish PID/thread quota exhaustion from
memory exhaustion, nor provide a measured thread count on the affected host.
The unbounded scheduling path above is directly established from the code and
covered by a regression test; the new limits address both resource pressures.

## Beta.10 — worker exhaustion during cancelled reads

The beta.9 response used asyncio.Task.cancel() on the file sender. Raw task
cancellation bypasses AnyIO's cooperative shield: a blocked read continues while
its capacity token is released. A regression against the original beta.9 response
created 102 total Python threads with a two-token limit after 100 disconnected
requests. This reproduces a concrete exhaustion mechanism; host resource metrics
are still unavailable.

Video delivery now cancels through AnyIO CancelScope and waits for the read and
shielded file close before releasing the cache pin. Simple, single-range and
multipart delivery use the same protected close. HEAD and If-Range are covered.
The slow-read regression keeps only two workers, verifies all 100 pins release,
and runs in CI with a 32-task container limit. Local backend: 149 tests pass;
JavaScript tests pass. A full-day continuous test on the user's HA host remains
unperformed; do not describe local accelerated fixtures as that test.
