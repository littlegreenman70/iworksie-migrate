# Progress

## 1.0.0 (2026-11-27, built overnight in a Claude thread, not Cursor)

### Done

- [x] Plugin skeleton matching iworksie-health-check (Strauss-bundled tools-core `^1.1`, prefix `iwiemg`, slug `iworksie-migrate`, submenu under iWorks Tools, CI workflow, uninstall).
- [x] Giving site: one time-limited key (1h/24h/7d), shown once; read-only signed REST endpoints `hello`, `manifest`, `chunk`, `files`.
- [x] Receiving site: pre-flight on both sides, count → exact disk-space check → copy → SHA-1 verify; resumable from the exact byte; pause after repeated network failures; never overwrites unless asked; small files batched.
- [x] Safety: scripts, server config files and symlinks never copied (checked on both sides); dead weight skipped by default; path traversal refused.
- [x] Admin screens (Copy from another site / Let another site copy this one / Report), live counters, Copy + Download report.
- [x] WP-CLI: `key-create`, `key-revoke`, `pull`, `resume`.
- [x] readme.txt, README.md, DECISIONS.md, .pot, 4 real screenshots in `.wordpress-org/`.

### Verified (all on 2026-11-27)

| Check | Result |
|---|---|
| PHPCS (WordPress standard + PHPCompatibilityWP, testVersion 7.4-) | clean |
| PHPStan level 6 | clean |
| `php -l` on PHP 7.4 for every file | clean |
| Unit tests (25) + WP integration tests (12) on PHP 7.4, 8.0, 8.2, 8.4 × WP 6.5.15, 6.8.3, 7.1.5, plus PHP 8.3 × WP 7.1.5 | 13/13 combinations pass, no notices from plugin code |
| Real two-site copy over HTTP: giver PHP 7.4 + WP 6.5.15, receiver PHP 8.4 + WP 7.1.5 (plain permalinks) | 305 files / 28 MB copied and SHA-1 verified via WP-CLI in ~4 s; re-run skipped all 305 in ~2 s; existing different file kept; `.php`, `x.php.jpg`, `.htaccess`, symlink, backup/cache dirs, log skipped and listed |
| Browser flow (Playwright, receiver admin) | bad key rejected; good key → pre-flight cards; copy finished with outcome + report; give tab create/revoke works; no JS errors |
| Resume | giver stopped mid-way through a 300 MB file: UI paused after ~60 s with part file kept at 90 MB; giver restarted, Resume finished; hashes match |

Integration tests (`tests/Integration/PullFlowTest.php`) run the real REST endpoints and signature checks in-process via `LoopbackClient`: full copy, re-run/keep/replace, pause/resume mid-file, revoked key, bad signature, replayed sequence, expired key, traversal/script requests refused, unsigned request refused, small-file batching.

### Not done / next

- [ ] Plugin Check (PCP) not run locally (wordpress.org downloads are blocked in the build environment); CI runs it on the PR.
- [ ] Real test between Cormac's own sites (e.g. live iworks.ie → www.iworks.new; local HTTPS may need `add_filter( 'iwiemg_sslverify', '__return_false' );` on the receiving site).
- [ ] Banner/icon artwork for `.wordpress-org/`.
- [ ] v2: themes/plugins/full wp-content; v3: database + Search & Replace engine; multisite.
