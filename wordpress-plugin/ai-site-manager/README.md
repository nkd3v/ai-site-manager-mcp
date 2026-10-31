# AI Site Manager

A WordPress plugin that gives AI clients scoped API keys for content, image uploads, approved configuration, plugin/theme management, site-to-site migration, and user account review with reversible quarantine. Requires WordPress 6.6+ and PHP 7.4+.

## Install and connect

1. Upload `ai-site-manager.zip` in **Plugins → Add New → Upload Plugin**, then activate it (or copy this directory to `wp-content/plugins/ai-site-manager`).
2. Open **Settings → AI Site Manager**.
3. Name the key, choose the user it runs as, expiration (1–365 days), permissions, and exact option names it may access. Read permissions are selected by default; edits, deletions and plugin/theme installation require explicit selection.
4. Copy the full key from the creation response. Only its hash and a display prefix are stored. The secret cannot be recovered later.
5. Give the AI the admin page's base URL, the secret through its secret storage, and [the AI guide](docs/AI-GUIDE.md). The admin page includes a copyable starter prompt. The same guide is available at authenticated `GET /guide`.

Keys work only under `ai-site-manager/v1`, using `Authorization: Bearer <key>` or `X-AISM-Key: <key>`. Login cookies alone do not grant access to these endpoints. The key owner must still have the WordPress capability needed for each action. From version 1.4.1, choose **Edit permissions** beside an active key to change its scopes and approved option names, then **Save permissions**. Changes apply to the next API request using the same secret; its owner and expiry stay the same. Only administrators managing their own keys or users whose roles they can manage may edit permissions. Revoked and expired keys cannot be edited. Revoke keys in the same admin page. Disabling the plugin disables its endpoints; uninstalling deletes its keys and audit logs while preserving site content and settings.

## What the API manages

- Posts, pages and REST-exposed custom post types, metadata exposed by their REST schemas, revisions (read), terms and comments.
- Image/media uploads, alt text, captions, featured images, and media deletion.
- Existing or registered options approved by exact name for each key. Writes use `update_option`, sanitizers and cache handling. Sensitive core settings and credential-like option names are blocked.
- Plugins: listing, WordPress.org installation, ZIP upload (new installs), activation, deactivation, updates from WordPress's update system or a local ZIP, and deletion of inactive plugins. AI Site Manager itself must be managed through wp-admin. Plugin changes require a single-site installation; listing also supports multisite.
- Themes: listing, WordPress.org installation, ZIP upload (new installs), activation, updates from WordPress's update system, and deletion of inactive themes. The active theme and its parent cannot be deleted. Switch to another theme to deactivate the current theme. Theme changes require a single-site installation; listing also supports multisite.

Content and settings use WordPress's APIs and their permission checks, rather than raw SQL. There is no database write or PHP execution endpoint. Custom tables and other data can be read only through the optional read-only database permission below. Third-party plugin configuration can be approved through its wp_options names; custom options may contain private data or control powerful behavior, so review each name before approval. Installing plugins or themes inherently adds executable code and should be granted only to an AI authorized to do so.

- User accounts (1.5.0+): list accounts with their roles, active login sessions (time, IP, browser) and application password names, and quarantine a suspicious account: its roles are saved and removed, its sessions end, its application passwords are deleted, its password is randomized, and every login path is blocked until it is restored. Accounts are never created or deleted, and roles cannot otherwise be edited. Restore through the API or **Users → Lift quarantine**. See the [user guide](docs/AI-GUIDE.md#review-and-quarantine-user-accounts-150).
- Database, diagnostics and maintenance (1.6.0+), each a separate permission that is never selected by default and requires an administrator key owner: read-only SQL queries on every table except user accounts, sessions and API keys (in a read-only transaction with time and row limits, recorded in the activity log); an environment and configuration overview with optional Site Health results, the WP-Cron schedule and the end of the debug/PHP error log; and maintenance actions — run an already-scheduled cron event now, flush the object cache, refresh permalinks, remove expired transients. `database:read` can read plugin settings, private content and commenter emails, including secrets plugins store in options; grant it only to an AI you trust with that. See the [guide](docs/AI-GUIDE.md#database-diagnostics-and-maintenance-160).
- Migration (1.4.0+): export a site's content, original media files, plugin/theme packages and portable settings, and import them into another AI Site Manager site with ID remapping, URL rewriting, incremental re-sync and rollback. See the [migration guide](docs/AI-GUIDE.md#migrate-between-sites-140).

New theme scopes are `themes:read`, `themes:write` (installation, upload, activation, updates), and `themes:delete`. Existing keys are unchanged until you grant the needed theme permissions through **Edit permissions** (1.4.1+), or issue a new key. Read the [theme API guide](docs/AI-GUIDE.md#manage-themes) for endpoints and examples.

## Operations

HTTPS is required. If behind a reverse proxy, ensure WordPress correctly recognizes the original HTTPS request through your trusted proxy configuration. Configure the host to preserve Authorization headers, or use X-AISM-Key. Do not expose the API through a cache that ignores `Cache-Control: private, no-store` or strips authentication headers. JSON keys in URLs are never accepted as authentication.

WordPress file modification restrictions, upload limits, filesystem access, and plugin/theme permissions apply. The API never accepts filesystem credentials. Use hosting tools to configure access when needed. Plugin and theme uploads install inactive and do not replace an existing installation. Plugin updates can use WordPress-advertised packages or explicit local ZIP replacement via `POST /plugins/update` with multipart `plugin` and `pluginzip` fields (1.2.0+). ZIP updates preserve activation, require update/upload capabilities, and validate the installed target, matching folder/main file, newer version and compatibility before replacement. Only plugins in their own top-level folder support ZIP updates. Theme updates use WordPress-advertised packages. Test significant plugin/theme changes on a staging site and maintain backups as part of your normal site workflow.

### Updating AI Site Manager

From 1.3.0, the plugin checks the latest [GitHub release](https://github.com/nkd3v/ai-site-manager-mcp/releases) (cached for 6 hours) and offers newer versions in **Dashboard → Updates** and the plugin list, like any other plugin. Use **Check for updates** in the plugin's row to check immediately, and WordPress's **Enable auto-updates** link if you want it to update itself. Before installing, it downloads the release's `SHA256SUMS` and refuses a ZIP that does not match, is not AI Site Manager, or is not newer than the installed version. Keys and settings are preserved, and the API cannot update this plugin. The `Update URI` header also stops WordPress.org from offering an unrelated plugin with the same slug. Define `AISM_DISABLE_GITHUB_UPDATES` as `true` in `wp-config.php` to turn off GitHub checks; updates by ZIP upload still work.

User scopes (`users:read`, `users:write`) are never selected by default. `users:read` exposes account emails and login IP addresses; `users:write` can lock out any account except the key owner and the last active administrator. Uninstalling AI Site Manager lifts the login block but leaves quarantined accounts without roles; restore them first if needed.

Migration scopes (`migration:read`, `migration:write`, `migration:delete`) are never selected by default. `migration:read` exports everything, including private content, comment emails and plugin/theme code; grant it only for a migration and revoke the key afterwards. Imports run as the key owner with `manage_options` and `import`; without `unfiltered_html` WordPress filters imported content, and free-form metadata and site settings are skipped. With `users:read` on the source key and `users:write` on the target key (1.7.0+), migrations also copy user accounts with their roles, profile and password hashes; existing target accounts with the same login or email are never changed. The source export then contains every password hash, so revoke that key after the migration. Rollback deletes only objects and accounts a migration created and restores settings it changed. Uninstalling removes the migration ID map but keeps imported content.

Each authenticated dispatched request and valid-key permission denial is recorded with key/user IDs, method, route, status and UTC timestamp. Bodies, query strings, tokens and IP addresses are not recorded. Invalid-key and pre-authentication errors are not logged. Retention cleanup runs daily through WP-Cron; if cron is disabled, call WordPress cron through your normal scheduler. There is no built-in request rate limit or automatic content rollback. See the AI guide for retry and concurrency limitations.

Keys and options are site-specific on multisite. Activate per site; plugin and theme mutation through this API is intentionally unavailable on multisite. Users with `manage_options` can issue keys for themselves, or for users whose roles they can manage; scopes do not elevate those users' capabilities.

## Client and tests

[examples/client.py](examples/client.py) is a dependency-free CLI client with support for pretty and query-string REST URLs. It never follows redirects carrying credentials to another origin. For example:

```sh
export WP_AI_BASE='https://example.com/wp-json/ai-site-manager/v1'
# Set WP_AI_KEY through your environment's secret storage.
uv run --system-certs examples/client.py GET /discovery
uv run --system-certs examples/client.py POST /wp/posts --json '{"title":"A draft","status":"draft"}'
uv run --system-certs examples/client.py POST /wp/media --file ./photo.jpg --field file
```

Run [tests/README.md](tests/README.md) against a disposable WordPress installation. The provided Docker Compose setup is isolated from existing sites. For this environment only, `WP_ENVIRONMENT_TYPE=local` plus `AISM_ALLOW_INSECURE_LOCAL_HTTP=true` enables HTTP testing. Either setting alone is insufficient. Never enable this exception on a deployed site.

## Development references

The gateway uses WordPress's [REST request dispatch](https://developer.wordpress.org/rest-api/requests/), [posts controller](https://developer.wordpress.org/reference/classes/wp_rest_posts_controller/), [plugin controller](https://developer.wordpress.org/reference/classes/wp_rest_plugins_controller/), [plugin upgrader](https://developer.wordpress.org/reference/classes/plugin_upgrader/), [theme controller](https://developer.wordpress.org/reference/classes/wp_rest_themes_controller/), and [theme upgrader](https://developer.wordpress.org/reference/classes/theme_upgrader/). Native schemas are read live, so AI clients should discover fields on each installation.
