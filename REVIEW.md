# iWorks.ie Migrate 1.0.0 review

Independent review of `claude/migrate-1.0.0` after Part A setup on Cormac's iMac (Europe/Dublin). A later pass on the same branch applied triage fixes F1–F7, F14 and F15 (see Fixes applied below). F8–F13 remain deferred in `DECISIONS.md`.

**Verdict: ready for a two-site test.** The claimed Media Library pull design holds in code and in the automated suite. Hardlinks, seq CAS, 2 GB / 32-bit notice, NFC detect-only, private URL notice, receiver key clearing and atomic replace are in. Local lint, analyse, unit, integration, Plugin Check, Playwright admin smoke and GitHub CI should be re-checked after that fix pass.

---

## Fixes applied

| ID | Status | Notes |
|----|--------|-------|
| F1 | Fixed | `Paths::unsafe_read_reason` refuses `nlink > 1`; Walker skips as `hardlink`; `chunk` / `files` refuse. |
| F2 | Fixed | `KeyStore::claim_seq` uses MySQL `GET_LOCK` + compare-and-swap. |
| F3 | Fixed | `Paths::safe_filesize`; skip `too_large`; hello reports `php_int_size`; pre-flight recommended on 32-bit. |
| F4 | Fixed | Detect-only via `Paths::is_nfc` + Walker `nfc` flag; pre-flight info; FAQ/README. No rewrite. |
| F5 | Fixed | Pre-flight recommended when key REST URL is private/loopback/local; still uses `wp_remote_post`. |
| F6 | Fixed | `PullJob::finish` deletes `iwiemg_receiver_key` after success. |
| F7 | Fixed | Replace renames existing aside, then part into place, then deletes aside. |
| F14 | Fixed | Unit/integration coverage for hardlinks, seq claim, NFC, private URL, custom `upload_path`, key clear. |
| F15 | Fixed | README Local development notes Plugin Check on `build/iworksie-migrate`. |
| F8–F13 | Deferred | Reasons in `DECISIONS.md`. |

---

## Findings

| ID | Severity | Area | File:line | What's wrong | Suggested fix |
|----|----------|------|-----------|--------------|---------------|
| F1 | High | Security | `src/Giver/Api.php:227-229`, `src/Support/Walker.php:143-152` | Symlinks are refused, but hardlinks are not. A hardlink inside uploads that points at the same inode as a file outside uploads (for example a planted link to `wp-config.php`) passes `is_link` (false), `realpath`, `is_file` and `Paths::is_inside` (realpath returns the path under uploads). The giver would then serve that file over `chunk` / `files`. | On read, compare `fileinode($real)` against a rule that the path must be a normal file with no alternate links outside the tree, or `stat` the path and refuse if `nlink > 1` when the realpath-equivalent outside uploads also exists. Simplest robust approach: after `realpath`, also `lstat` the requested path and refuse if it is not a regular file with `nlink === 1`, or resolve via open+`fstat` and verify the directory entry is not a link *and* the inode is only reachable under uploads. |
| F2 | Medium | Security | `src/Giver/Api.php:96-100`, `src/Giver/KeyStore.php:104-105` | Sequence check and `touch()` are not atomic. Two concurrent signed requests with seq N and N+1 can both pass against the same stored last-seq; if the lower seq wins the last write, the higher seq can be replayed. Practical impact is low while a single PullJob runs steps serially, but a second client with the secret (or parallel misuse) can open a replay window. | Compare-and-swap the seq option (read, compare, write in one `UPDATE ... WHERE` / `add_option` ticket lock), or store last-seq in a transient with `wp_cache` locking / `$wpdb` conditional update. |
| F3 | Medium | Correctness | `src/Giver/Api.php:235`, `src/Receiver/PullJob.php:286-288`, `src/Support/Walker.php:177` | File size and offsets are cast to `(int)`. On 32-bit PHP, files over about 2 GB truncate; `fseek` / chunk math then fail or loop. | Detect `PHP_INT_SIZE < 8` in pre-flight and refuse (or warn Critical) when any listed size exceeds `PHP_INT_MAX`, and keep sizes as strings for comparison where needed. Document "64-bit PHP required for libraries over 2 GB". |
| F4 | Medium | Correctness | (cross-cutting; no NFC normalise) | Relative paths are compared and written as raw bytes. A macOS giver with NFD filenames and a Linux receiver (NFC) can keep "duplicates", fail verify, or leave lookalike paths. | Document the limitation; optionally Normalizer::normalize(FORM_C) on both sides when `ext-intl` is present, with a pre-flight note when it is missing. |
| F5 | Medium | Security / ops | `src/Receiver/Client.php:74-89`, `DECISIONS.md` (wp_remote_post) | Admin-pasted key URL is fetched with `wp_remote_post` (not `wp_safe_remote_post`), so private/link-local targets are reachable. Intentional for LAN/local migrations, but a mistaken or malicious key can SSRF from the receiver. | Keep the filter, add a clear pre-flight warning when the host is private/reserved, and optionally require an explicit "allow private address" checkbox before storing the key. |
| F6 | Medium | Security hygiene | `src/Receiver/PullJob.php:29`, `Ajax.php:58` | The receiver stores the full key string (including secret) in `iwiemg_receiver_key` until forgotten or uninstall. DB backups and clones of the receiver during a migration window contain a live giver credential. | Document in FAQ/readme; consider encrypting at rest with wp salts, shorter receiver retention, or storing only id+secret separately with a clear "key kept for resume" notice and one-click forget after success. |
| F7 | Medium | Robustness | `src/Receiver/PullJob.php:646-651` | On replace, the existing file is deleted before `rename` of the part file. A crash between those calls loses the previous file and the new one. | Rename part to a temp neighbour, then `rename` over the destination (atomic on same filesystem), or rename dest aside, rename part into place, then delete the aside copy. |
| F8 | Low | Security | `src/Giver/Api.php:65-75` | Distinct error codes (`iwiemg_not_enabled` vs `iwiemg_unknown_key` vs `iwiemg_bad_signature`) let a network observer probe whether a key id is active. | Collapse unauthenticated failures to one generic 401/403 message (log the detail server-side). |
| F9 | Low | Security | Giver REST (no throttle) | No rate limit on failed signatures. Brute-forcing the HMAC is impractical; this is defence-in-depth only. | Soft throttle by IP / key id after N failures (transient counter). |
| F10 | Low | Correctness | `src/Receiver/PullJob.php:930-935` | "Identical" means size + mtime only. Filesystems with coarse mtime (FAT, some mounts) or touch skew can re-copy or falsely skip. | Optional size+mtime+first/last-chunk hash, or document the trade-off (already partly implied by verify pass). |
| F11 | Low | WordPress.org / product | `readme.txt`, handover brief | Handover described pairing codes, tree picker, compression and DB; this v1 correctly follows the build plan (media-only pull). Listing copy is accurate for v1, but reviewers may expect "migrate" to mean full site. | Keep the "What this version copies" section prominent; consider the wordpress.org short description "Media Library copy" wording already used. |
| F12 | Improvement | Core reuse | `assets/js/admin.js` (job runner), `DECISIONS.md` | Custom JS runner duplicates what core `startJob` almost does (params, finish, retries, pause). Called out as a core candidate; right call for v1. | Promote a richer runner into `iwt-core.js` in a later core minor and thin this file. |
| F13 | Improvement | UX | `src/Admin/Screens.php` receive intro | No empty-state illustration on Report tab beyond core empty pattern (already used). Progress and stats match Health Check language well. | Add a one-line estimate after pre-flight ("about N files / size") before Start, using hello + a cheap count if available. |
| F14 | Improvement | Tests | `tests/` | No tests for hardlinks (F1), >2 GB / 32-bit (F3), NFC/NFD (F4), custom `UPLOADS` / `upload_path`, or concurrent seq CAS (F2). Existing suite strongly covers the happy path and safety skips. | Add focused unit/integration cases for F1–F4 before .org submit. |
| F15 | Improvement | Docs | `PROGRESS.md` / setup | Local Plugin Check on the git symlink hangs (scans vendor/tests). Built zip / `build/iworksie-migrate` is the right target (same as CI). | Note in README under Local development. |

---

## Claims checklist (readme.txt / DECISIONS.md vs code)

| Claim | Status | Evidence |
|-------|--------|----------|
| No archive | **holds** | Receiver pulls `chunk` / `files` over REST; no zip/tar staging (`PullJob`, `Client`). |
| No overwrite by default | **holds** | `replace` defaults false; different existing files go to `kept` (`PullJob.php:421-428`, UI checkbox off). |
| Resumable (exact byte / part file) | **holds** | `.iwiemg-part` + offset restore (`PullJob.php:550-556`, `download_chunk`); cursor listing (`Walker.php`). Integration `test_network_failures_pause_then_resume_finishes`. |
| SHA-1 verification report | **holds** | Verify phase with `manifest` `hash=1` (`PullJob.php:728-736`); report via `ReportBuilder` / `iwiemg_last_report`. |
| Disk-space check before writing | **holds** | After count, before copy phase (`PullJob.php:304-317`) with 50 MB margin. Mid-copy OOS fail at `:617-618`. |
| Dead-weight skip (default) | **holds** | `Paths::dead_weight_reason`; Walker + count record skips; UI checkbox on. Plain zips without "backup" copied (DECISIONS). |
| Scripts + server config blocked on **both** sides | **holds** | Giver: Walker + `blocked_reason` before chunk (`Api.php:223-224`). Receiver: `begin_file` (`PullJob.php:411-412`). Double-ext `x.php.jpg` and case variants unit-tested. |
| Never follow symlinks | **holds** | Walker skips `is_link`; Api refuses `is_link`. Hardlinks refused via `nlink > 1` (F1 fixed). |
| Expiring keys, shown once | **holds** | Lifetimes 1h/24h/7d (`KeyStore`); UI output hidden until create; `active()` null when expired. Not single-*use* (many requests until expiry), which matches DECISIONS ("single-use display"). |
| CLI and browser share job state | **holds** | CLI `drive()` saves via core Jobs (`Command.php:135-146`); same `migrate_pull` id. |
| Multisite refused | **holds** | `iworksie-migrate.php:105-109` notice, no boot. |
| Uninstall cleanup | **holds** | Deletes `iwiemg_*` options/transients, site meta, and current part file (`uninstall.php`). |
| HMAC + timing-safe compare | **holds** | `hash_hmac` + `hash_equals` (`Api.php:82-84`, chunk/file payload hashes too). |
| ±15 minute clock window | **holds** | `MigrationKey::MAX_SKEW` 900; `Api.php:86-94`. |
| Sequence digit-string compare (32-bit safe) | **holds** | `MigrationKey::seq_greater`; unit-tested beyond int range. Replay tested in integration. |
| Path traversal refused | **holds** | `normalize_relative` rejects `..`, absolute, `\`, `:`, NUL, empty parts; unit + integration chunk refusals. |
| Capability + nonce on admin/AJAX | **holds** | `Screens::render` `manage_options`; `Ajax::guard` nonce + cap; `AjaxAuthTest`. Core Jobs use `iwiemg_jobs` nonce. |
| REST `permission_callback` | **holds** | All four routes use `authorize` (`Api.php:51`). Unsigned request → 403 (integration). |
| `sslverify` default true | **holds** | Filter `iwiemg_sslverify` default true (`Client.php:72-79`). |
| Custom uploads path / `UPLOADS` | **holds** | Both sides use `wp_upload_dir()` → `Paths::uploads_base()`; relative paths only. |
| mtime preserved | **holds** | `touch( $local, mtime )` after rename (`PullJob.php:657-658`); integration asserts. |

---

## Part A setup results

| Check | Result |
|-------|--------|
| Bundle | `~/Downloads/iworksie-migrate.bundle` verified; refs `main` + `claude/migrate-1.0.0` |
| GitHub repo | Created empty public: https://github.com/littlegreenman70/iworksie-migrate |
| Branches pushed | `main` @ `3559ba7`, `claude/migrate-1.0.0` @ `a31071d` |
| PR | https://github.com/littlegreenman70/iworksie-migrate/pull/1 (open, **not merged**) |
| Core path repo | Resolves: `vendor/iworksie/tools-core` → `../iworks-tools-core` (local tree at tag `v1.1.2` + 1 commit, `237eee4`) |
| `composer install` | OK (Strauss + core assets copied) |
| `composer lint` | Clean (WPCS + PHPCompatibilityWP `7.4-` on plugin PHP) |
| `composer analyse` | Clean (PHPStan level 6) |
| PHPCompatibilityWP on `src/` **and** `vendor-prefixed/` | Clean (exit 0) |
| `composer test:unit` | 25 tests, 92 assertions, OK |
| `composer test:integration` | 12 tests, 190 assertions, OK (`iworks_tests_mg`) |
| `composer build` | OK → `build/iworksie-migrate.zip` (117290 bytes), SHA-256 `5ac47cea17c0a5958a954c139dc93bfd67649ea6da20c0412c61315c14fd3232` |
| Plugin Check (built zip contents) | `Success: Checks complete. No errors found.` (same exclude pattern as CI). Note: checking the git symlink hung; use the build dir. |
| Symlink + activate | `www.iworks.new/wp-content/plugins/iworksie-migrate` → this repo; activated |
| WP-CLI | `wp iworksie-migrate key-create --expires=1h` printed an `iwiemg1.…` key; `key-revoke` → Success. **No pull run** (needs a second site). |
| Playwright | Admin receive + give tabs: `.iwt-page__header`, notices slot, both screens, `iwt-core.css` / `iwt-core.js` fetch 200, no console errors from our code. Local smoke under `tests/e2e/` (not committed). |
| CI on PR | All green (~2 m): PHP 8.3 quality, Integration, Plugin Check, PHP 7.4 syntax. Run `45977793212`. |

### Setup decisions (recorded here; plugin code untouched)

- Opened the PR with `gh pr create` because the workspace git root is Homebrew/brew and the PR tool could not target this repo.
- Plugin Check was run against `build/iworksie-migrate` after the symlink scan did not finish.
- Local Playwright helpers were copied from Health Check for verification only and left untracked.

---

## Security notes (giver exposes files over REST)

- Auth model is sound for a short-lived shared secret: canonical string includes protocol, route, time, seq and body hash; comparisons use `hash_equals`.
- Read surface is limited to real files under uploads with extension and config denylists on both ends.
- Residual risks before .org: intentional private-URL contact when the admin pastes such a key (warned in pre-flight, F5), and deferred oracles/throttling (F8/F9).
- A malicious giver still cannot plant `.php` / `.htaccess` on the receiver; it can supply large or tricky media (SVG/HTML) like any upload path. Replace mode is opt-in.

## Tests vs claims

Integration `PullFlowTest` proves full copy + SHA-1, skip of php/htaccess/backup, keep vs replace, pause/resume mid-file, revoke, bad signature, replay, expiry, traversal/script chunk refusal, unsigned refusal, small-file batching, hardlink refusal, seq claim, custom upload_path, and receiver key clear after finish. Unit tests cover path rules, hardlinks, private URL detection, NFC detection, key crypto, walker paging/skips.

## Consistency with Health Check / core

- Boots core `^1.1` with Strauss prefix `iwiemg`; submenu under iWorks Tools; Jobs, UI, Page, Report used correctly.
- UI matches core 1.1.x header band, version pill, tabs, notices below header (`iwt-notices`), empty report state, progress widget.
- Cooperation is via normal WP hooks / core APIs only (no cross-plugin class sharing). Own job JS is the main duplication (F12).
