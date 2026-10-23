# Isolated integration tests

These tests change content, options, users and plugin files. Use only a disposable local WordPress installation. The Compose project below has separate containers, network and volumes from other sites; it mounts this plugin source into the test site. Do not point tests at an existing installation.

```sh
# From the wp-plugins repository root:
docker compose -p aism-test -f ai-site-manager/tests/compose.yaml up -d
# Wait until the database and WordPress containers are ready.
docker compose -p aism-test -f ai-site-manager/tests/compose.yaml port wordpress 80
# Use the returned port in the following two URL arguments:
docker compose -p aism-test -f ai-site-manager/tests/compose.yaml run --rm cli wp core install \
  --url=http://localhost:PORT --title='AI Site Manager Test' \
  --admin_user=aism-admin --admin_password=isolated-test-password \
  --admin_email=aism@example.test --skip-email
docker compose -p aism-test -f ai-site-manager/tests/compose.yaml run --rm cli wp plugin activate ai-site-manager
docker exec --user 33:33 aism-test-wordpress-1 php /var/www/html/wp-content/plugins/ai-site-manager/tests/integration.php /var/www/html/wp-load.php
docker exec --user 33:33 aism-test-wordpress-1 php /var/www/html/wp-content/plugins/ai-site-manager/tests/theme-restrictions.php /var/www/html/wp-load.php filemods
docker exec --user 33:33 aism-test-wordpress-1 php /var/www/html/wp-content/plugins/ai-site-manager/tests/plugin-restrictions.php /var/www/html/wp-load.php filemods
docker exec --user 33:33 aism-test-wordpress-1 php /var/www/html/wp-content/plugins/ai-site-manager/tests/migration.php /var/www/html/wp-load.php
uv run --system-certs ai-site-manager/tests/http-integration.py http://127.0.0.1:PORT aism-test-wordpress-1
# After HTTP tests: verifies audit cleanup and uninstall, then recreates empty API tables.
docker exec aism-test-wordpress-1 php /var/www/html/wp-content/plugins/ai-site-manager/tests/lifecycle.php /var/www/html/wp-load.php
# Convert only this disposable site after the single-site suites finish.
docker compose -p aism-test -f ai-site-manager/tests/compose.yaml run --rm cli wp option update siteurl http://aism.test
docker compose -p aism-test -f ai-site-manager/tests/compose.yaml run --rm cli wp option update home http://aism.test
docker compose -p aism-test -f ai-site-manager/tests/compose.yaml run --rm cli wp core multisite-convert --title='AI Site Manager Test Network'
docker exec --user 33:33 aism-test-wordpress-1 php /var/www/html/wp-content/plugins/ai-site-manager/tests/theme-restrictions.php /var/www/html/wp-load.php multisite
docker exec --user 33:33 aism-test-wordpress-1 php /var/www/html/wp-content/plugins/ai-site-manager/tests/plugin-restrictions.php /var/www/html/wp-load.php multisite

# PHP compatibility / syntax:
docker run --rm -v "$PWD/ai-site-manager:/plugin:ro" php:7.4-cli sh -c 'find /plugin -name "*.php" -exec php -l {} \;'

# Destroy only this test project's containers and volumes when finished:
docker compose -p aism-test -f ai-site-manager/tests/compose.yaml down -v
```

The PHP suite exercises authentication, expiration, revocation, hashed storage, independent scopes, native user capabilities, ID override resistance, blocked routes, drafts, partial updates, metadata, taxonomy assignment, option allowlists and validation, plugin/theme access, independent theme scopes, native theme capabilities, broken/incompatible theme activation, audit records, and request-local identity restoration. The HTTP suite tests admin key creation and one-time display, CSRF nonce checks, privilege checks for key owners, both authentication headers, non-URL authentication, cache headers, pagination, real multipart/raw image uploads, featured images, actual plugin ZIP installation, activation/deactivation/deletion, an advertised update through WordPress's real upgrader, active/inactive ZIP updates with preserved settings, mismatched/invalid/incompatible/same-version/older ZIP rejection, and WordPress.org plugin/theme installation. Theme tests also cover duplicate ZIP rejection, active-theme updates, child activation, and active child/parent deletion protection.

The update test adds a temporary MU plugin and header-only fixture plugin inside the disposable container. It advertises a ZIP on the private Docker network. The repository-install checks download Hello Dolly and Twenty Twenty-One from WordPress.org and require outbound access. Tests do not contact or modify any other WordPress installation. Fixtures and credentials are test-only and are excluded from the distributable ZIP. A failed suite may leave fixtures behind; destroy the disposable volumes before starting over.

The migration suite imports into the same site under a fake source UUID and URL. It covers migration scopes and capabilities, same-site refusal, credential/object metadata filtering, tampered attachment paths, package guards, slug/template matching, author mapping, idempotent re-import and interrupted-write recovery, `status=draft`, untrusted (no `unfiltered_html`) imports, comments, protected options, finalize ID remapping in blocks/menus/widgets/theme mods, URL boundaries and escaped JSON, finalize running once, and rollback. A two-site end-to-end run through the MCP adapter lives in the MCP repository (`scripts/migration-e2e.mjs`).

## GitHub self-update

`updater.php` mocks GitHub's release API and downloads, then really replaces the installed plugin folder. Run it only where the plugin is **copied** into a disposable site. Never run it against the Compose setup above: that bind-mounts this working copy, so an update would overwrite or delete your source files.

```sh
# A separate project without the plugin bind mount:
sed '/- \.\.:\/var\/www\/html\/wp-content\/plugins\/ai-site-manager/d' ai-site-manager/tests/compose.yaml > /tmp/aism-upd.yaml
docker compose -p aism-upd-test -f /tmp/aism-upd.yaml up -d
# Install WordPress as above (with -p aism-upd-test -f /tmp/aism-upd.yaml), then copy the plugin in:
tar --exclude .DS_Store --exclude __pycache__ -cf - ai-site-manager | docker exec -i aism-upd-test-wordpress-1 tar -xf - -C /var/www/html/wp-content/plugins
docker exec aism-upd-test-wordpress-1 chown -R 33:33 /var/www/html/wp-content
docker compose -p aism-upd-test -f /tmp/aism-upd.yaml run --rm cli wp plugin activate ai-site-manager
docker exec --user 33:33 aism-upd-test-wordpress-1 php /var/www/html/wp-content/plugins/ai-site-manager/tests/updater.php /var/www/html/wp-load.php
docker exec --user 33:33 aism-upd-test-wordpress-1 php /var/www/html/wp-content/plugins/ai-site-manager/tests/updater.php /var/www/html/wp-load.php verify
docker compose -p aism-upd-test -f /tmp/aism-upd.yaml down -v
```

It covers up-to-date and newer releases, keeping the plugin out of WordPress.org update checks, caching, GitHub errors, releases missing `SHA256SUMS` or a version tag, the details dialog, the **Check for updates** link, checksum mismatches, older packages, ZIP folder renaming, preserved activation, and that the update survives the end of the request. To try the real GitHub release, copy the plugin in, lower its `Version` header and `AISM_VERSION`, then run `wp plugin update ai-site-manager`; it requires outbound access to GitHub.

The local-HTTP exception requires both `WP_ENVIRONMENT_TYPE=local` and `AISM_ALLOW_INSECURE_LOCAL_HTTP=true`. To exercise the normal HTTPS guard without modifying the configuration, run:

```sh
docker exec aism-test-wordpress-1 php -r 'define("AISM_ALLOW_INSECURE_LOCAL_HTTP", false); require "/var/www/html/wp-load.php"; $r=rest_do_request(new WP_REST_Request("GET", "/ai-site-manager/v1/discovery")); echo $r->get_status()." ".$r->get_data()["code"].PHP_EOL;'
# Expected: 403 aism_https_required
```

Additional host/cloud upload limits and third-party plugin update providers depend on the deployment environment and are not covered by the local fixtures.

The restriction suite verifies `DISALLOW_FILE_MODS` and the multisite mutation guard in separate processes. The multisite mode runs after converting the disposable site to a network and also verifies that theme listing still works. Run CLI theme tests as the web user (33:33) so WordPress filesystem ownership checks match HTTP requests.
