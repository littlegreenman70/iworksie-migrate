# Decisions

Choices made while building v1.0.0 overnight without Cormac's input (he asked for best judgment; review in the morning).

| Date (Europe/Dublin) | Decision | Reason |
|----------------------|----------|--------|
| 2026-11-26 | v1 scope = **Media Library files only** (`wp-content/uploads`), pulled site to site | Handover brief's phasing (v1 media, v2 full files, v3 DB + search-replace). Uploads is the part archive tools fail on (disk space, upload caps). |
| 2026-11-26 | Receiver **pulls** from giver over the giver's REST API; giver is read-only | Build plan: direct streaming, no archive, no third party. Pull keeps every write on the site whose admin started it. |
| 2026-11-26 | Pairing key = `iwiemg1.` + base64url JSON {REST URL, home, key id, secret}; one active key per site; lifetimes 1h / 24h (default) / 7d; shown once | Like UpdraftPlus's migration key, but expiring and single-use display. |
| 2026-11-26 | Requests signed with HMAC-SHA256 over route, time, sequence and body hash; ±15 min clock window; strictly increasing sequence number per key | Secret never travels. Sequence number gives replay protection with one small option write instead of a nonce table (thousands of chunk requests would create thousands of transients). |
| 2026-11-26 | Sequence numbers compared as digit strings | 32-bit PHP builds overflow on microsecond timestamps. |
| 2026-11-26 | Never copy scripts (`.php*`, `.phtml`, `.phar`, `.cgi`, `.pl`, `.py`, `.sh`, `.asp*`, `.jsp`, `.shtml`, and PHP hidden in a middle segment like `x.php.jpg`) or server config (`.htaccess`, `.htpasswd`, `.user.ini`, `php.ini`, `web.config`); never follow symlinks. Enforced on **both** sides | Uploads should hold media; these files are how malware hops between sites and a compromised giver must not be able to plant them. Listed in the report so nothing disappears silently. Middle-segment check is PHP-only so `brochure.pl.pdf` is not blocked. |
| 2026-11-27 | Also refuse hardlinks (`lstat` nlink > 1) on the giver when listing and reading; filter `iwiemg_allow_hardlinks` (default false); list FIFOs/sockets/devices as `not_regular` | A hardlink under uploads can share an inode with a file outside uploads; realpath + is_inside alone would serve that file. Refusing every nlink > 1 is the simplest robust rule (F1/G6). |
| 2026-11-27 | Sequence claim uses MySQL `GET_LOCK` + compare-and-swap on `iwiemg_giver_seq` | Closes the non-atomic read/compare/`touch` race under concurrent signed requests (F2). |
| 2026-11-27 | 32-bit PHP: pre-flight recommended warning; files whose size cannot be read safely are skipped as `too_large`; count phase hard-stops | `(int)` offsets truncate above about 2 GB on 32-bit builds (F3/G3). 64-bit PHP is documented for large libraries. |
| 2026-11-27 | Unicode NFC/NFD: detect and document only; do not rewrite names | Cross-platform lookalikes stay a known limitation for v1 (F4). Pre-flight info when intl is present or missing. |
| 2026-11-27 | Keep `wp_remote_post` (private URLs allowed); add a pre-flight recommended warning when the key host is private/loopback/local | LAN and local migrations must keep working (F5). |
| 2026-11-27 | Delete `iwiemg_receiver_key` in `PullJob::finish` after a successful (non-failed, non-cancelled) run | Shortens the window where a receiver DB backup holds a live giver secret (F6). Resume still works until finish; re-run needs a new paste. |
| 2026-11-27 | Replace: try `rename(part, dest)` first (atomic on POSIX); fall back to fixed `.iwiemg-old` aside swap; restore on resume | Matches the 05b F7 shape; the random `.iwiemg-old-*` form leaked between sites in the Docker test (G1/G2). |
| 2026-11-27 | On 32-bit PHP, probe past 2 GB with fseek/fread; hard-stop the count phase when any file is too large | `filesize()` wraps on real 32-bit builds, so the old `=== PHP_INT_MAX` check never fired (G3). |
| 2026-11-27 | Clear `iwiemg_receiver_key` when the giver returns `iwiemg_unknown_key` / `iwiemg_not_enabled`; show a Done hint after success | F6 was only half done: revoke left the secret on the receiver, and the empty key form had no message (G4). |
| 2026-11-27 | F2 accepted as-is: MySQL `GET_LOCK` around read/compare/write, one seq option per site | Claude's concurrency test passed (1 of 6 accepted). Creating or revoking a key resets the seq, so per-key options are not required. |
| 2026-11-26 | Dead-weight skip (default on) is narrower than Health Check's: backup/cache/VCS folders, logs, SQL dumps, `.wpress`/`.bak`, archives with "backup" in the name. Plain `.zip` files are copied | Zips and PDFs in uploads are often real downloads. |
| 2026-11-26 | Default **never overwrites** an existing different file ("Replace" checkbox, off). Identical = same size + mtime, always skipped; mtime is preserved on copy | Safe-by-default positioning; makes re-runs incremental. |
| 2026-11-26 | Three phases: count (list + exact disk-space check before writing anything), copy, verify (SHA-1 via `manifest?hash=1`) | Fixes the #1 competitor failure (disk full at 99%) and adds the verify report none of them have. |
| 2026-11-26 | Listing = deterministic depth-first walk in byte order with a path cursor, compared component by component | Resumable without storing a file list; plain string comparison of full paths breaks on `a/b` vs `a.txt`. |
| 2026-11-26 | Download into `file.iwiemg-part`, rename when size matches; part file size is the source of truth after a crash | Atomic files; resume from exact byte. |
| 2026-11-26 | Chunks are base64 in JSON, 2 MB default (filter `iwiemg_chunk_bytes`, 64 KB to 8 MB), halving after a failed chunk and doubling after fast ones; each chunk carries a SHA-1 | Works through any REST stack without custom output handling; adapts to slow links so a slow connection doesn't just time out. |
| 2026-11-26 | Own JS job runner (calls core's `iwiemg_job_*` endpoints) instead of core's `startJob` | Core's runner sends empty params, has no finish callback and stops silently on network errors. We need options, retries with backoff, a paused state and live counters. Candidate for core later. |
| 2026-11-26 | Transient errors (network, 408/429/5xx, replay) retry with backoff 2s→30s; after 6 in a row the job **pauses** (not fails) with a Resume button | Survives connection drops (#2 competitor failure) without burning through retries. |
| 2026-11-26 | Fatal errors (key revoked/expired, bad signature, clock skew, plugin missing on giver, disk full) stop the job with a plain-English cause and fix | Every error code maps to "what happened / what to do" (`Client::explain`). |
| 2026-11-26 | `wp_remote_post` (not `wp_safe_remote_post`), `redirection => 0` | Admin-entered URL; the safe variant blocks local/private IPs, breaking local and LAN migrations. Redirects are reported (they usually mean a wrong WordPress Address) because a POST redirect silently becomes a GET. |
| 2026-11-26 | TLS verification on by default; `iwiemg_sslverify` filter for self-signed local sites | No checkbox to switch it off in the UI. |
| 2026-11-26 | Multisite not supported in v1: plugin shows a notice and does not boot | Per-site upload paths and network capabilities need their own design. |
| 2026-11-26 | Receiving site stores the pasted key (non-autoloaded option) until "Use a different key" or uninstall | Allows Resume after closing the tab and incremental re-runs; the key is read-only and expires on the giver. |
| 2026-11-26 | Report persisted in `iwiemg_last_report`; Copy + Download via core `Report` | Same pattern as Health Check. |
| 2026-11-26 | WP-CLI `key-create`, `key-revoke`, `pull`, `resume`; CLI and browser share job state | Huge sites can run without a browser tab or AJAX timeouts. |
| 2026-11-26 | `Client` is not `final` | Integration tests subclass it to dispatch signed requests to the real REST server in-process. |
| 2026-11-26 | Requires tools-core `^1.1` (same as Health Check); no core changes needed | Uses Jobs, UI, Page, Report, Menu as they are. |
| 2026-11-27 | `bin/install-wp-tests.sh` resolves X.Y to the latest X.Y.Z and checks out matching `tags/X.Y.Z` tests (not trunk) | Preventive parity with SMTP / A11y / Security Audit so a future X.Y matrix pin does not pull trunk tests. |

## Candidates for core

- A job runner in `iwt-core.js` that accepts params, exposes a finish callback and handles transient errors/pause (what `assets/js/admin.js` does here).
- `Transfer` module (key, signing, chunk client) once Backup or a v2 full-files Migrate needs it.

## Deferred from review (F8–F13)

| ID | Why deferred |
|----|--------------|
| F8 | Collapsing auth error oracles to one generic 401/403 helps probing resistance but makes support harder while the plugin is new; revisit after first public release. |
| F9 | HMAC brute-force is already impractical; soft IP/key throttling is defence-in-depth for a later hardening pass. |
| F10 | Size+mtime identical heuristic is intentional for incremental re-runs; optional hash-on-identical stays a later toggle once real FAT/mtime skew shows up. |
| F11 | Listing copy already says "Media Library"; no product rename needed for v1. |
| F12 | Promoting the job runner into tools-core is a core minor, not a Migrate patch. |
| F13 | Pre-start file/size estimate is UX polish after the controlled two-site trial. |

## Before wordpress.org submission

- Real screenshots (Playwright, like Health Check) and final banner/icon artwork (none yet in `.wordpress-org/`).
- A real two-site test on Cormac's machines (e.g. live iworks.ie → www.iworks.new), see PROGRESS.md.
