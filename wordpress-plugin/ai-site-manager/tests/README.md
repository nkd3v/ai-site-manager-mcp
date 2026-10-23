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
docker exec aism-test-wordpress-1 php /var/www/html/wp-content/plugins/ai-site-manager/tests/integration.php /var/www/html/wp-load.php
uv run --system-certs ai-site-manager/tests/http-integration.py http://127.0.0.1:PORT aism-test-wordpress-1
# Run last: verifies audit cleanup and uninstall, then recreates empty API tables.
docker exec aism-test-wordpress-1 php /var/www/html/wp-content/plugins/ai-site-manager/tests/lifecycle.php /var/www/html/wp-load.php

# PHP compatibility / syntax:
docker run --rm -v "$PWD/ai-site-manager:/plugin:ro" php:7.4-cli sh -c 'find /plugin -name "*.php" -exec php -l {} \;'

# Destroy only this test project's containers and volumes when finished:
docker compose -p aism-test -f ai-site-manager/tests/compose.yaml down -v
```

The PHP suite exercises authentication, expiration, revocation, hashed storage, independent scopes, native user capabilities, ID override resistance, blocked routes, drafts, partial updates, metadata, taxonomy assignment, option allowlists and validation, plugin access, audit records, and request-local identity restoration. The HTTP suite tests admin key creation and one-time display, CSRF nonce checks, privilege checks for key owners, both authentication headers, non-URL authentication, cache headers, pagination, real multipart/raw image uploads, featured images, actual plugin ZIP installation, activation/deactivation/deletion, an advertised update through WordPress's real upgrader, and WordPress.org installation.

The update test adds a temporary MU plugin and header-only fixture plugin inside the disposable container. It advertises a ZIP on the private Docker network. The repository-install check downloads Hello Dolly from WordPress.org and requires outbound access. Tests do not contact or modify any other WordPress installation. Fixtures and credentials are test-only and are excluded from the distributable ZIP. A failed suite may leave fixtures behind; destroy the disposable volumes before starting over.

The local-HTTP exception requires both `WP_ENVIRONMENT_TYPE=local` and `AISM_ALLOW_INSECURE_LOCAL_HTTP=true`. To exercise the normal HTTPS guard without modifying the configuration, run:

```sh
docker exec aism-test-wordpress-1 php -r 'define("AISM_ALLOW_INSECURE_LOCAL_HTTP", false); require "/var/www/html/wp-load.php"; $r=rest_do_request(new WP_REST_Request("GET", "/ai-site-manager/v1/discovery")); echo $r->get_status()." ".$r->get_data()["code"].PHP_EOL;'
# Expected: 403 aism_https_required
```

Additional host/cloud upload limits and third-party plugin update providers depend on the deployment environment and are not covered by the local fixtures.
