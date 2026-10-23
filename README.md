# AI Site Manager MCP

An MCP server for the **AI Site Manager** WordPress plugin. Connect an MCP client to your site's scoped API key to work with posts, pages, custom post types, taxonomies, comments, media, approved settings, plugins and themes.

This is a local stdio MCP adapter. The AI Site Manager plugin must already be installed and activated on your WordPress site. It connects to that plugin's HTTPS API; it does not install the WordPress plugin or provide a hosted MCP endpoint.

## Install the WordPress companion

1. Download the [current ai-site-manager.zip](releases/ai-site-manager.zip?raw=true) from this repository. Tagged versions are also available on the [releases page](https://github.com/nkd3v/ai-site-manager-mcp/releases).
2. Upload it in **WordPress → Plugins → Add New → Upload Plugin**, then activate it. Requires WordPress 6.6+ and PHP 7.4+.
3. Open **Settings → AI Site Manager**. Create an expiring key, choose its user and scopes, and approve exact option names if settings access is needed.
4. Save the one-time key privately and copy the API base URL. Configure the MCP client below.

The companion source and disposable integration tests are in [wordpress-plugin/ai-site-manager](wordpress-plugin/ai-site-manager). Its [API guide](wordpress-plugin/ai-site-manager/docs/AI-GUIDE.md) describes native fields, permissions and operational limits. The companion's Python example sends an explicit User-Agent, and its HTTP test image has a valid PNG checksum.

## Run with npx

Requires **Node.js 22 or later**, npm, and Git for the GitHub package specifier.

```sh
export WP_AI_BASE='https://example.com/wp-json/ai-site-manager/v1'
# Supply WP_AI_KEY from your private environment or secret storage.
npx --yes github:nkd3v/ai-site-manager-mcp
```

The server waits for an MCP client to communicate over stdin/stdout. It does not open a browser. To check installation without credentials:

```sh
npx --yes github:nkd3v/ai-site-manager-mcp --version
```

The package runs directly from GitHub; npm registry publication is not required. To pin the current release, use `github:nkd3v/ai-site-manager-mcp#v1.1.1` instead of the unpinned specifier. Use a full commit SHA for an immutable pin.

For a local install:

```sh
npm install --global github:nkd3v/ai-site-manager-mcp
ai-site-manager-mcp --help
```

## Update an existing installation

Updating the MCP adapter and the WordPress companion are separate steps. An already running MCP server keeps using the code it started with until restarted.

1. Stop the server, or disconnect it in your MCP client.
2. Fetch and check the current MCP version without supplying credentials:

   ```sh
   npx --yes --prefer-online github:nkd3v/ai-site-manager-mcp --version
   ```

   For the theme-management update, expect `1.1.0` or later. npm caches packages; [`--prefer-online`](https://docs.npmjs.com/cli/npm-exec/#prefer-online) forces it to check for updates. If your MCP configuration pins an older tag or commit, such as `#v1.0.0`, change that package specifier too; checking the unpinned version does not update a pinned configuration. Use the unpinned specifier to follow the repository's default branch, or pin the commit you intend to run.
3. Download the [current WordPress companion ZIP](releases/ai-site-manager.zip?raw=true). In **WordPress → Plugins → Add New → Upload Plugin**, upload it and choose **Replace current with uploaded** when prompted. Theme support requires companion version `1.1.0` or later. Replacing the plugin preserves its keys and settings; do not uninstall it as part of the update.
4. To grant theme access, create a new key in **Settings → AI Site Manager** with the needed `themes:read`, `themes:write` and/or `themes:delete` permissions, plus any permissions the client already needs. Replace the key in your MCP client's environment or key file. Existing keys retain their previous permissions and remain usable; they do not gain theme access automatically. Revoke the old key after verifying the replacement works.
5. Restart or reconnect the MCP server so the client refreshes its tool list and credentials. Call `aism_discover` to verify the companion version and key scopes, then `aism_themes_list` if you granted `themes:read`.

The MCP configuration below includes `--prefer-online` so subsequent launches check for updates. This does not update an already running process. If you installed the adapter globally instead of using npx, rerun `npm install --global github:nkd3v/ai-site-manager-mcp`, check `ai-site-manager-mcp --version`, and restart it.

## MCP client configuration

Add this entry to a client that accepts `mcpServers` configuration:

```json
{
  "mcpServers": {
    "ai-site-manager": {
      "command": "npx",
      "args": ["--yes", "--prefer-online", "github:nkd3v/ai-site-manager-mcp"],
      "env": {
        "WP_AI_BASE": "https://example.com/wp-json/ai-site-manager/v1",
        "WP_AI_KEY_FILE": "/absolute/path/to/ai-site-manager.key"
      }
    }
  }
}
```

The key file must contain **only the API key**, with an optional trailing newline. Keep it outside your repositories and restrict access to its owner (`chmod 600 /absolute/path/to/ai-site-manager.key`). An environment export file is a different format: source it in your shell, then launch the server using `WP_AI_KEY`, or use your MCP client's secret/environment mechanism. MCP clients launched from a desktop application may not inherit your shell environment. Client-specific configuration formats vary.

You can supply `WP_AI_KEY` directly instead of `WP_AI_KEY_FILE`; do not set both. Never place the key in command arguments, URLs, public examples or committed configuration.

When run in a terminal, the server prompts for a missing `WP_AI_BASE` and, if neither credential variable is set, `WP_AI_KEY_FILE`. Enter the HTTPS API namespace URL and the path to an existing plain-text key file. Values apply only to that process and are not saved. Existing environment values are kept, and supplying `WP_AI_KEY` skips the key-file prompt. Press Ctrl+C to cancel.

Prompts go to stderr, keeping stdout available for MCP messages. Automatic prompting requires both stdin and stderr to be terminals. Desktop MCP clients and other noninteractive launches must supply the variables in their configuration; missing values produce a startup error listing the variables to set.

## Configuration

| Variable | Purpose | Default |
| --- | --- | --- |
| `WP_AI_BASE` | HTTPS API namespace URL; supports WordPress installed in a subdirectory and `https://example.com/?rest_route=/ai-site-manager/v1` | Required |
| `WP_AI_KEY` | Scoped API key | Required unless using a key file |
| `WP_AI_KEY_FILE` | Absolute path to a plain-text key file | Alternative to `WP_AI_KEY` |
| `WP_AI_AUTH_HEADER` | `Authorization` or `X-AISM-Key` | `Authorization` |
| `WP_AI_TIMEOUT_MS` | Request timeout, 1000–600000 milliseconds | `120000` |
| `WP_AI_MAX_UPLOAD_BYTES` | Local upload limit, up to 1 GiB; WordPress/host limits also apply | `33554432` (32 MiB) |

## Tools

| Tools | Operations |
| --- | --- |
| `aism_discover`, `aism_guide`, `aism_schema` | Inspect scopes, expiry, resources, approved options and native schemas |
| `aism_content_list`, `aism_content_get` | Read/search content, terms, comments and native type/taxonomy/status discovery; pagination headers are included |
| `aism_content_create`, `aism_content_update`, `aism_content_delete` | Create drafts, update native fields/metadata, assign taxonomy/featured images, moderate comments, trash or permanently delete |
| `aism_revisions` | Read a revision list or one revision |
| `aism_media_upload` | Upload an operator-provided local image/media file; optional title, alt text, caption and parent post |
| `aism_settings_list`, `aism_settings_get`, `aism_settings_update` | Read or replace values of exactly approved settings |
| `aism_plugins_list`, `aism_plugin_install`, `aism_plugin_upload`, `aism_plugin_action` | List/install/upload/activate/deactivate/update/delete plugins |
| `aism_themes_list`, `aism_theme_install`, `aism_theme_upload`, `aism_theme_action` | List/install/upload/activate/update/delete themes |

The authenticated site guide is also available as the MCP resource `aism://guide`. Successful tool results include `{status, headers, data}`; native pagination headers appear in `headers`. API failures set MCP `isError` and include the status and error code. Credentials are redacted from results.

Start with `aism_discover`, then `aism_schema` for the relevant resource. `aism_content_create` defaults posts and pages to `draft`; publication requires an explicit `status`. Native JSON fields and query parameters pass through to the plugin. Use `context: "edit"` to read raw Gutenberg content; preserve it when editing. `aism_content_update` sends only the supplied fields. Structured settings updates replace the whole value, so read immediately before editing.

The server exposes all tools; the API independently enforces the key's scopes, approved option names and owner's current WordPress capabilities on every request. Read scopes do not permit edits or deletions. Plugin and theme installation adds executable code; grant those permissions only when intended. The gateway has no arbitrary SQL, PHP execution, user administration, private metadata access or remote-URL fetching. The tool hints identify reads and destructive operations; clients must still honor the operator's authorization.

Theme support requires companion plugin 1.1.0 or later and a key issued with `themes:read`, `themes:write` and/or `themes:delete` as needed. Existing keys gain no additional permissions; create a new key to grant theme access. `aism_schema` accepts `themes`. Use the `stylesheet` identifier from `aism_themes_list` as the `theme` argument for actions. Installation leaves themes inactive and ZIP uploads never replace installed themes. Switching to another theme deactivates the current one; there is no standalone deactivate action. Deletion rejects the active theme and its parent. Updates use WordPress-advertised packages. Theme mutations support single-site WordPress only.

## Network behavior and troubleshooting

- HTTPS certificate validation remains enabled. Configure trusted certificates through Node's supported certificate configuration if your environment requires a private CA.
- Credentials go only to the configured API origin. Redirects are refused, including same-origin redirects. Configure the final canonical URL.
- The explicit `AI-Site-Manager-MCP/1.0` User-Agent avoids the default Python-urllib signature that some Cloudflare rules block. Host/firewall policies still apply.
- Requests are never automatically retried. After a timeout or failed upload, search/read state before retrying: WordPress may already have created the object or file. There is no idempotency, atomic compare-and-swap or rollback.
- Responses are limited to 16 MiB; use `per_page`, `page` and `_fields` for large collections. Uploads use multipart and enforce the local file limit before sending.
- A `401` means the key is missing, invalid, expired or revoked. A `403` can mean missing scopes, native capabilities, an unapproved setting, or a host/firewall rejection. Use `X-AISM-Key` if your host strips Authorization.
- The normal server writes only MCP protocol messages to stdout. Startup errors go to stderr. `--help` and `--version` print human-readable output and exit.

## Development

```sh
git clone https://github.com/nkd3v/ai-site-manager-mcp.git
cd ai-site-manager-mcp
npm ci
npm run check
npm test
npm pack --dry-run
```

The upload ZIP is built from an explicit allowlist of companion files; tests, credentials and local artifacts are excluded. To rebuild and verify it, install [uv](https://docs.astral.sh/uv/) and run:

```sh
uv run --system-certs scripts/package-plugin.py
```

Tests exercise credential isolation, URL handling, pagination, redirects, uncertain writes, upload limits and real MCP tool/resource calls against simulated API responses. They never modify a live WordPress site. The source is plain JavaScript with no build or install scripts. Package contents are explicitly limited to source, documentation and license.

Every push to `main` must increase the MCP and companion versions together and rebuild the companion ZIP. The Release workflow rejects unchanged or reused versions, runs validation, and publishes `v<version>` at the pushed commit with the MCP tarball, WordPress upload ZIP, and `SHA256SUMS`. Check that the workflow and release succeeded after each push. Repository contributors and agents must follow [AGENTS.md](AGENTS.md).

Built using the [official MCP SDK](https://github.com/modelcontextprotocol/typescript-sdk). The initial source commit is created through GitHub's commit API and verified for GitHub's GPG signature.

The JavaScript MCP adapter is MIT licensed. The separately distributed WordPress companion is GPL-2.0-or-later; see its [license](wordpress-plugin/ai-site-manager/LICENSE).
