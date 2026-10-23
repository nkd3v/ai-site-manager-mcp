<?php
/**
 * GitHub self-update tests with mocked GitHub responses.
 * Usage: php updater.php /var/www/html/wp-load.php        (main suite)
 *        php updater.php /var/www/html/wp-load.php verify (afterwards, separate process)
 *
 * WARNING: the final step really updates the installed plugin folder. Run only on a disposable
 * site where the plugin is copied in, never bind-mounted from a working copy (see tests/README.md).
 */
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) ) { fwrite( STDERR, "Usage: php updater.php /path/to/wp-load.php\n" ); exit( 2 ); }
require $argv[1];
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/update.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

$failures = 0;
function aism_check( $ok, $label ) { global $failures; echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL; if ( ! $ok ) { $failures++; } }

$file = AISM_Updater::plugin_file();
if ( 'verify' === ( $argv[2] ?? '' ) ) {
	// Run as a separate process after the main suite: the update must survive the end of its request.
	$plugins = get_plugins();
	$ok = '9.9.9' === ( $plugins[ $file ]['Version'] ?? '' ) && file_exists( WP_PLUGIN_DIR . '/' . dirname( $file ) . '/UPDATED-FIXTURE.txt' ) && is_plugin_active( $file ) && ! is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/' . dirname( $file ) );
	echo ( $ok ? 'PASS' : 'FAIL' ) . " update persisted after the request ended\n";
	exit( $ok ? 0 : 1 );
}
$dir = WP_PLUGIN_DIR . '/' . dirname( $file );
$base = 'https://github.com/' . AISM_Updater::REPO . '/releases/download/v9.9.9';
aism_check( is_plugin_active( $file ), "plugin active as $file" );
aism_check( ! is_link( $dir ) && ! file_exists( $dir . '/UPDATED-FIXTURE.txt' ), 'plugin folder is a fresh copy' );

/** Builds a release ZIP from the installed plugin with a new version and a marker file. */
function aism_fixture( $version, $folder = 'ai-site-manager' ) {
	global $dir;
	$path = wp_tempnam( 'aism-fixture.zip' );
	$zip = new ZipArchive();
	$zip->open( $path, ZipArchive::OVERWRITE );
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $item ) {
		$relative = substr( $item->getPathname(), strlen( $dir ) + 1 );
		$content = file_get_contents( $item->getPathname() );
		if ( 'ai-site-manager.php' === $relative ) { $content = preg_replace( array( '/Version: [0-9.]+/', "/'AISM_VERSION', '[0-9.]+'/" ), array( "Version: $version", "'AISM_VERSION', '$version'" ), $content ); }
		$zip->addFromString( "$folder/$relative", $content );
	}
	$zip->addFromString( "$folder/UPDATED-FIXTURE.txt", $version );
	$zip->close();
	return $path;
}

$state = array( 'code' => 200, 'tag' => 'v' . AISM_VERSION, 'assets' => array( 'ai-site-manager.zip', 'SHA256SUMS' ), 'zip' => null, 'sum' => null, 'api_calls' => 0, 'sent_to_wporg' => false );
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$state, $base, $file ) {
	$reply = function ( $code, $body ) use ( $args ) { return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null ); };
	if ( 'https://api.github.com/repos/' . AISM_Updater::REPO . '/releases/latest' === $url ) {
		$state['api_calls']++;
		$assets = array();
		foreach ( $state['assets'] as $name ) { $assets[] = array( 'name' => $name, 'browser_download_url' => "$base/$name" ); }
		return $reply( $state['code'], wp_json_encode( array( 'tag_name' => $state['tag'], 'html_url' => 'https://github.com/' . AISM_Updater::REPO . '/releases/tag/' . $state['tag'], 'body' => "Fixture <b>notes</b>", 'published_at' => '2026-10-23T00:00:00Z', 'assets' => $assets ) ) );
	}
	if ( "$base/SHA256SUMS" === $url ) { return $reply( 200, $state['sum'] . "  ai-site-manager.zip\n" ); }
	if ( "$base/ai-site-manager.zip" === $url ) { copy( $state['zip'], $args['filename'] ); return $reply( 200, '' ); }
	if ( false !== strpos( $url, 'api.wordpress.org/plugins/update-check' ) ) {
		if ( false !== strpos( (string) ( $args['body']['plugins'] ?? '' ), $file ) ) { $state['sent_to_wporg'] = true; }
		return $reply( 200, wp_json_encode( array( 'plugins' => array(), 'translations' => array(), 'no_update' => array() ) ) );
	}
	return $pre;
}, 10, 3 );
function aism_refresh() { AISM_Updater::clear(); delete_site_transient( 'update_plugins' ); wp_clean_plugins_cache( false ); wp_update_plugins(); return get_site_transient( 'update_plugins' ); }

// Same version: listed as up to date, and never sent to WordPress.org.
$updates = aism_refresh();
aism_check( isset( $updates->no_update[ $file ] ) && ! isset( $updates->response[ $file ] ), 'same version is reported as current' );
aism_check( ! $state['sent_to_wporg'], 'Update URI keeps the plugin out of WordPress.org update checks' );
aism_check( 1 === $state['api_calls'], 'one GitHub API call per check' );
AISM_Updater::release();
aism_check( 1 === $state['api_calls'], 'release is cached' );

// Failures are reported and cached briefly.
$state['code'] = 403;
AISM_Updater::clear();
$error = AISM_Updater::release();
AISM_Updater::release();
aism_check( is_wp_error( $error ) && false !== strpos( $error->get_error_message(), '403' ) && 2 === $state['api_calls'], 'GitHub errors are reported and cached' );
$state['code'] = 200;
$state['tag'] = 'v9.9.9';
$state['assets'] = array( 'ai-site-manager.zip' );
aism_check( is_wp_error( AISM_Updater::release( true ) ), 'release without SHA256SUMS is ignored' );
$state['assets'] = array( 'ai-site-manager.zip', 'SHA256SUMS' );
$state['tag'] = 'latest';
aism_check( is_wp_error( AISM_Updater::release( true ) ), 'non-version tag is ignored' );

// Newer release is offered.
$state['tag'] = 'v9.9.9';
$updates = aism_refresh();
$offer = $updates->response[ $file ] ?? null;
aism_check( $offer && '9.9.9' === $offer->new_version && "$base/ai-site-manager.zip" === $offer->package, 'newer GitHub release is offered' );
$info = plugins_api( 'plugin_information', array( 'slug' => 'ai-site-manager' ) );
aism_check( ! is_wp_error( $info ) && '9.9.9' === $info->version && false !== strpos( $info->sections['changelog'], '&lt;b&gt;' ), 'details dialog shows escaped release notes' );

// "Check for updates" link and handler.
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );
$meta = AISM_Updater::row_meta( array(), $file );
aism_check( 1 === count( $meta ) && false !== strpos( $meta[0], 'aism_check_updates' ), 'plugin row has a Check for updates link' );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'aism_check_updates' );
$_SERVER['HTTP_REFERER'] = admin_url( 'plugins.php' );
$redirect = '';
add_filter( 'wp_redirect', function ( $location ) use ( &$redirect ) { $redirect = $location; throw new RuntimeException( 'redirect' ); } );
try { AISM_Updater::check_now(); } catch ( RuntimeException $e ) {}
aism_check( false !== strpos( $redirect, 'aism_update_check=available' ), 'Check for updates reports the new version' );
wp_set_current_user( 0 );

/** Same path as the plugin list, Dashboard > Updates, WP-CLI and auto-updates. */
function aism_upgrade() {
	global $file;
	aism_refresh(); // Core clears its update list after every attempt.
	$skin = new WP_Ajax_Upgrader_Skin();
	$results = ( new Plugin_Upgrader( $skin ) )->bulk_upgrade( array( $file ) );
	$result = is_array( $results ) ? ( $results[ $file ] ?? false ) : $results;
	$errors = $skin->get_errors();
	return is_wp_error( $result ) ? $result : ( $errors->has_errors() ? $errors : ( is_array( $result ) ? true : $result ) );
}
function aism_unchanged() { global $dir, $file; return ! file_exists( "$dir/UPDATED-FIXTURE.txt" ) && false !== strpos( file_get_contents( "$dir/ai-site-manager.php" ), 'Version: ' . AISM_VERSION ) && is_plugin_active( $file ); }

// Wrong checksum: nothing changes.
$state['zip'] = aism_fixture( '9.9.9' );
$state['sum'] = str_repeat( '0', 64 );
$result = aism_upgrade();
aism_check( is_wp_error( $result ) && in_array( 'aism_update_checksum', $result->get_error_codes(), true ) && aism_unchanged(), 'checksum mismatch is rejected without changes' );

// Correct checksum but not a newer version: rejected.
$state['zip'] = aism_fixture( '1.0.0' );
$state['sum'] = hash_file( 'sha256', $state['zip'] );
$result = aism_upgrade();
aism_check( is_wp_error( $result ) && in_array( 'aism_update_package', $result->get_error_codes(), true ) && aism_unchanged(), 'older package is rejected without changes' );

// Each failed attempt above queued core's "restore backup at shutdown". In production every
// attempt is a separate request; here it would undo the next, successful update at exit.
remove_all_actions( 'shutdown' );

// Valid update, even if the ZIP folder name differs from the installed folder.
$state['zip'] = aism_fixture( '9.9.9', 'ai-site-manager-9.9.9' );
$state['sum'] = hash_file( 'sha256', $state['zip'] );
$result = aism_upgrade();
if ( is_wp_error( $result ) ) { echo 'Upgrade error: ' . implode( '; ', $result->get_error_messages() ) . PHP_EOL; }
wp_clean_plugins_cache( false );
$plugins = get_plugins();
aism_check( true === $result && '9.9.9' === ( $plugins[ $file ]['Version'] ?? '' ) && file_exists( "$dir/UPDATED-FIXTURE.txt" ), 'verified update installs into the existing folder' );
aism_check( ! file_exists( WP_PLUGIN_DIR . '/ai-site-manager-9.9.9' ) && ( 'ai-site-manager' === dirname( $file ) || ! file_exists( WP_PLUGIN_DIR . '/ai-site-manager' ) ), 'no extra plugin folder was created' );
aism_check( in_array( $file, (array) get_option( 'active_plugins' ), true ), 'plugin stays active after update' );

AISM_Updater::clear();
echo $failures ? "$failures failure(s)\n" : "All updater tests passed\n";
exit( $failures ? 1 : 0 );
