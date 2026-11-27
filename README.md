# iWorks.ie Migrate

Free WordPress plugin for [wordpress.org](https://wordpress.org/plugins/iworksie-migrate/) that copies the Media Library (`wp-content/uploads`) straight from one site to another: no archive, no third party, resumable, with a SHA-1 verification report. Built on [`iworks-tools-core`](https://github.com/littlegreenman70/iworks-tools-core).

## How it works

```
 Giving site (old)                                Receiving site (new)
 ─────────────────                                ────────────────────
 Admin creates key  ──── key pasted by admin ───▶ Pre-flight (both sides)
 (id + secret + REST URL, 1h/24h/7d)              │
                                                  ▼
 POST /wp-json/iworksie-migrate/v1/manifest ◀──── 1. count: list files, total bytes, disk check
 POST /wp-json/iworksie-migrate/v1/chunk    ◀──── 2. copy: 2 MB chunks → file.iwiemg-part → rename
 POST /wp-json/iworksie-migrate/v1/manifest ◀──── 3. verify: SHA-1 per file, report
      (hash=1)
```

- **Read-only on the giving site.** Three POST endpoints (`hello`, `manifest`, `chunk`) that only read inside `wp-content/uploads`.
- **Auth:** every request carries `X-Iwiemg-Key`, `X-Iwiemg-Time`, `X-Iwiemg-Seq` and `X-Iwiemg-Signature` = HMAC-SHA256(secret, `iwiemg1\n{route}\n{time}\n{seq}\n{sha256(body)}`). The giver checks the key id, signature, a ±15 minute clock window and a strictly increasing sequence number (replay protection). The secret is never sent.
- **Resumable:** the listing is a deterministic depth-first walk with a path cursor (`src/Support/Walker.php`), so no file list is stored. The current file's byte offset is kept in the job state; the `.iwiemg-part` file on disk is the source of truth after a crash.
- **Never copied:** scripts (`.php`, `.phtml`, `.phar`, `.cgi`, `.pl`, `.py`, `.sh` …, including `x.php.jpg`) and server config (`.htaccess`, `.user.ini`, `php.ini`, `web.config`), symlinks and hardlinks. Checked on both sides.
- **Dead weight** (default skipped): backup/cache/VCS folders, logs, SQL dumps, `.wpress`, backup archives.
- **Never overwrites** unless "Replace" is ticked. Identical files (same size + mtime) are always skipped; mtimes are preserved so re-runs are incremental.
- Uses core `Jobs` (chunked AJAX steps) with its own JS runner for retries/backoff/pause, core `UI`, `Report` and the shared iWorks Tools menu.

## Requirements

- WordPress 6.5+, single site (multisite refused with a notice in v1)
- PHP 7.4+ (64-bit PHP required for Media Library files over about 2 GB)
- Composer (development)

## Local development

```bash
composer install
ln -sfn "$(pwd)" /opt/homebrew/var/www/www.iworks.new/wp-content/plugins/iworksie-migrate
wp plugin activate iworksie-migrate --path=/opt/homebrew/var/www/www.iworks.new
```

To try a real copy locally you need two WordPress sites. If one uses a self-signed certificate, add `add_filter( 'iwiemg_sslverify', '__return_false' );` in a mu-plugin on the receiving site.

```bash
composer lint
composer analyse
composer test:unit
bin/install-wp-tests.sh iworks_tests_mg root '' 127.0.0.1 latest
composer test:integration
composer build
```

Run Plugin Check against the **built** plugin (`build/iworksie-migrate` or the zip contents), not the git working tree. Checking the development symlink scans `vendor/` and `tests/` and can hang; CI uses the same build directory.

## WP-CLI

```bash
wp iworksie-migrate key-create --expires=24h      # on the giving site
wp iworksie-migrate key-revoke
wp iworksie-migrate pull <key> [--replace] [--include-dead-weight] [--no-verify]   # on the receiving site
wp iworksie-migrate resume
```

## Filters

| Filter | Default | Purpose |
|---|---|---|
| `iwiemg_sslverify` | `true` | Verify the giving site's TLS certificate. |
| `iwiemg_chunk_bytes` | `2097152` | Bytes per chunk (64 KB to 8 MB). |
| `iworks_tools_job_time_budget` | core | Seconds per AJAX step. |

## License

GPL-2.0-or-later
