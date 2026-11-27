=== iWorks.ie Migrate ===
Contributors: littlegreenman
Tags: migrate, migration, media library, uploads, transfer
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Copy your Media Library straight from one site to another. No archive, no third party, resumable, with a verification report.

== Description ==

Moving a site usually fails on the uploads folder: the backup archive fills the disk, the import hits an upload limit, or the connection drops at 97% and you start again.

iWorks.ie Migrate copies the files in **wp-content/uploads** directly from one WordPress site to another, file by file:

* **No archive.** Nothing is zipped up first, so the old site does not need free disk space for a copy of itself.
* **No upload limits.** The new site pulls the files in small pieces over HTTPS, so PHP upload limits do not apply.
* **No third party.** Files go straight from one server to the other. Nothing passes through our servers or anyone else's (good news for GDPR).
* **Resumable.** Close the tab or lose the connection and press Resume: it carries on with the file it was on.
* **Never overwrites by surprise.** Files already on the new site are kept unless you tick "Replace". Identical files are skipped, so running it again only fetches what changed.
* **Verified.** When it finishes, every file is checked against the original (SHA-1) and you get a report you can copy or download.
* **Skips dead weight.** Old backups, caches, logs and database dumps in uploads are left behind (you can include them if you want).
* **Safe by design.** The old site only gives read access to its uploads folder, for as long as the key you create lasts (1 hour to 7 days). Script files and server config files (.php, .htaccess and similar) are never copied in either direction.

= How it works =

1. Install iWorks.ie Migrate on both sites.
2. On the site you are copying **from**, go to **iWorks Tools > Migrate > Let another site copy this one** and create a key.
3. On the site you are copying **to**, go to **iWorks Tools > Migrate > Copy from another site**, paste the key and press **Check connection**. Both sites are checked before anything is copied.
4. Press **Start copying**. The exact disk space needed is worked out first, and the copy stops cleanly if there is not enough.

= What this version copies =

This version copies the **Media Library files** (wp-content/uploads). The database (posts, pages, settings and the Media Library entries themselves) and themes and plugins are not copied yet; use your usual tool for those. Moving the uploads folder is the part that most often fails with archive-based tools, which is why it comes first.

If the site address changes, update the old URLs in the database afterwards, for example with the free **iWorks.ie Search & Replace** plugin.

= WP-CLI =

For very large sites you can run the copy from the command line:

`wp iworksie-migrate key-create --expires=24h` (on the old site)
`wp iworksie-migrate pull <key>` (on the new site; add `--replace`, `--include-dead-weight` or `--no-verify`)
`wp iworksie-migrate resume`

Part of the free iWorks Tools suite, which shares one "iWorks Tools" menu. No upsells, no tracking, nothing locked.

Need a custom plugin? [iWorks builds them](https://www.iworks.ie/?utm_source=wordpress.org&utm_medium=iworksie-migrate).

== Installation ==

1. Install and activate iWorks.ie Migrate on both the old and the new site (Plugins > Add New, search for "iWorks.ie Migrate").
2. Follow the steps under "How it works" above.

== Frequently Asked Questions ==

= Does it copy the database? =

Not in this version. It copies the Media Library files. Database and full-site copying are planned.

= Can the other site change anything on my site? =

No. The key only lets the other site list and read files in your uploads folder. It cannot write, delete, see your database or reach any other folder. Every request is signed with the key and expires with it. You can revoke the key at any time.

= Is the key sent over the internet? =

The key's secret is never sent. Each request carries a signature made with it, and each signature can only be used once.

= Which files are never copied? =

Script files (.php, .phtml, .phar, .cgi, .pl, .py, .sh and similar) and server configuration files (.htaccess, .user.ini, php.ini, web.config). These do not belong in uploads and are a common way malware spreads between sites. They are listed in the report so you can review them. Plugins that rely on an .htaccess file in uploads (for example to protect downloads) normally recreate it themselves.

= What happens if the connection drops? =

The copy retries automatically. If the other site stays unreachable it pauses and shows a Resume button. Resuming carries on from the exact byte where it stopped.

= My other site is a local development site with a self-signed certificate =

Add this to a small must-use plugin on the receiving site while you copy, then remove it:
`add_filter( 'iwiemg_sslverify', '__return_false' );`

= Does it work on multisite? =

Not yet. It works on single WordPress sites.

= Is it really free? =

Yes. No paid version, no upsells, no data sent anywhere except between your own two sites.

== Screenshots ==

1. Copy from another site: paste the key and check both sites.
2. A finished copy: live counters, verification result and a link to the full report.
3. Let another site copy this one: create a time-limited key.
4. The verification report.

== Changelog ==

= 1.0.0 =
* Initial release: site-to-site copy of the uploads folder with pairing key, pre-flight checks on both sites, disk space check, resumable chunked transfer, SHA-1 verification report and WP-CLI commands.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
