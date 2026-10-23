=== AI Site Manager ===
Contributors: site-owner
Tags: rest-api, ai, api-key, content, configuration
Requires at least: 6.6
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later

Scoped API keys for AI-assisted WordPress content, media uploads, approved settings, and plugin/theme management.

== Installation ==
1. Upload ai-site-manager.zip through Plugins > Add New > Upload Plugin, then activate.
2. Open Settings > AI Site Manager.
3. Create a named key, select its WordPress user, expiry and permissions, and approve specific option names.
4. Copy the displayed key once. Give your AI the API base URL, secret key, and the guide/starter prompt from the admin page.

== Features ==
* Independent read/edit/delete scopes, WordPress capabilities, HTTPS, expiring and revocable hashed keys.
* Posts, pages, REST-exposed custom post types, registered metadata, categories, tags, comments and revisions.
* Upload images and other WordPress-approved media; set alt text, captions and featured images.
* Read/write explicitly approved wp_options settings through WordPress APIs.
* List, install, upload ZIPs, activate, deactivate, update and delete plugins on single-site WordPress.
* List, install, upload ZIPs, activate, update and delete themes on single-site WordPress, with active/parent theme protection.
* Authenticated live discovery, native resource schemas, and an AI usage guide.
* Metadata-only API audit log with 30-day WP-Cron retention.

== Documentation ==
See README.md for operational details and docs/AI-GUIDE.md for the full API guide.

== Changelog ==
= 1.2.0 =
Add explicit local ZIP updates for installed plugins, with target/version validation and preserved activation.
= 1.1.1 =
Synchronize companion and MCP release versions and refresh the installable ZIP.
= 1.1.0 =
Add scoped theme listing, installation, ZIP upload, activation, updates and deletion.
= 1.0.0 =
Initial release.
