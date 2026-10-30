=== AI Site Manager ===
Contributors: site-owner
Tags: rest-api, ai, api-key, content, configuration
Requires at least: 6.6
Requires PHP: 7.4
Stable tag: 1.6.2
License: GPLv2 or later

Scoped API keys for AI-assisted WordPress content, media uploads, approved settings, plugin/theme management, site migration, and user account review and quarantine.

== Installation ==
1. Upload ai-site-manager.zip through Plugins > Add New > Upload Plugin, then activate.
2. Open Settings > AI Site Manager.
3. Create a named key, select its WordPress user, expiry and permissions, and approve specific option names.
4. Copy the displayed key once. Give your AI the API base URL, secret key, and the guide/starter prompt from the admin page.

== Features ==
* Independent read/edit/delete scopes, editable live key permissions, WordPress capabilities, HTTPS, expiring and revocable hashed keys.
* Posts, pages, REST-exposed custom post types, registered metadata, categories, tags, comments and revisions.
* Upload images and other WordPress-approved media; set alt text, captions and featured images.
* Read/write explicitly approved wp_options settings through WordPress APIs.
* List, install, upload ZIPs, activate, deactivate, update and delete plugins on single-site WordPress.
* List, install, upload ZIPs, activate, update and delete themes on single-site WordPress, with active/parent theme protection.
* Migrate between WordPress sites: content of every post type, original media files, menus, templates, comments, settings, widgets and plugin/theme packages, with ID remapping, URL rewriting, incremental re-sync and rollback.
* Review user accounts (roles, login sessions, application passwords) and quarantine a suspicious account: remove its roles, end its sessions, delete its application passwords, randomize its password and block every login path until restored.
* Optional read-only database access (every table except accounts, sessions and API keys), diagnostics (environment, plugins, Site Health, cron schedule, error logs) and maintenance (run scheduled events, flush caches and permalinks, remove expired transients).
* Authenticated live discovery, native resource schemas, and an AI usage guide.
* Updates itself from GitHub releases through Dashboard > Updates, with SHA-256 verification and optional WordPress auto-updates.
* Metadata-only API audit log with 30-day WP-Cron retention.

== Documentation ==
See README.md for operational details and docs/AI-GUIDE.md for the full API guide.

== Changelog ==
= 1.6.2 =
Fix site migrations from page-builder themes such as Flatsome. Finalize now remaps attachment and post IDs in shortcode attributes (for example [ux_image id], [ux_banner bg], [featured_box img], [ux_gallery ids] and [contact-form-7 id]) and in theme image settings such as site_logo, which previously kept the source IDs and showed missing or wrong images. An identical file already at the source path on the target is reused instead of saved under a "-1" name, and rollback keeps it. Empty references are no longer reported as unresolved. The MCP adapter imports site settings before media so images are generated with the source's sizes.
= 1.6.1 =
Fix read-only database queries hanging on hosts with many databases on the same server, such as Wasmer: the check for references to other databases now looks up only the names used in the query instead of listing every database.
= 1.6.0 =
Add three optional permissions for administrator keys, never selected by default. database:read lists and describes tables and runs read-only SQL (SELECT, WITH, TABLE, SHOW, DESCRIBE, EXPLAIN) in a read-only transaction with time and row limits; comments, writes, locking, side-effect functions, other databases and the users, user meta and API key tables are refused, and secret-looking values are redacted. diagnostics:read reports environment, configuration, plugin versions, Site Health results, the WP-Cron schedule and the end of the debug or PHP error log. maintenance:write runs an already-scheduled cron event now, flushes the object cache and permalinks, and removes expired transients. The activity log now records the SQL of database queries and the maintenance action.
= 1.5.0 =
Add user account review and reversible quarantine for incident response. New users:read and users:write scopes (never selected by default); GET /users and /users/{id} show roles, login sessions and application password names without secrets; POST /users/{id}/quarantine saves and removes roles, ends sessions, deletes application passwords, randomizes the password and blocks logins and password resets; POST /users/{id}/restore and Users > Lift quarantine restore the roles. Refuses the key owner and the last active administrator.
= 1.4.1 =
Edit active API key permissions and approved option names from Settings without replacing the key. Changes apply on the next API request; owner checks, protected settings, expiry and revocation remain enforced.
= 1.4.0 =
Add site-to-site migration: manifest, paged export, original media and plugin/theme package downloads, idempotent import with source→target ID mapping, a finalize pass that remaps IDs in blocks, shortcodes, metadata and settings and rewrites URLs, status, and rollback. New migration:read, migration:write and migration:delete scopes.
= 1.3.0 =
Check GitHub releases for new versions and update through WordPress's normal update screens, verifying the ZIP against the release's SHA256SUMS.
= 1.2.0 =
Add explicit local ZIP updates for installed plugins, with target/version validation and preserved activation.
= 1.1.1 =
Synchronize companion and MCP release versions and refresh the installable ZIP.
= 1.1.0 =
Add scoped theme listing, installation, ZIP upload, activation, updates and deletion.
= 1.0.0 =
Initial release.
