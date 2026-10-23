<?php
// Disposable WordPress ONLY. Run each mode in a separate PHP process.
if ( PHP_SAPI !== 'cli' ) { exit; }
$mode = $argv[2] ?? '';
if ( ! in_array( $mode, array( 'filemods', 'multisite' ), true ) ) { throw new RuntimeException( 'Choose filemods or multisite.' ); }
if ( 'filemods' === $mode ) { define( 'DISALLOW_FILE_MODS', true ); }
require $argv[1] ?? '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ( 'multisite' === $mode ) !== is_multisite() ) { throw new RuntimeException( 'Use the expected local disposable installation.' ); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( get_user_by( 'login', 'aism-admin' )->ID );
$dir = WP_PLUGIN_DIR . '/aism-zip-restrictions';
wp_mkdir_p( $dir );
file_put_contents( $dir . '/main.php', "<?php\n/* Plugin Name: ZIP restrictions fixture\nVersion: 1.0.0\n*/" );
wp_clean_plugins_cache();
try {
	$request = new WP_REST_Request( 'POST', '/ai-site-manager/v1/plugins/update' );
	$request->set_body_params( array( 'plugin' => 'aism-zip-restrictions/main.php' ) );
	$result = AISM_Plugins::dispatch( $request, '/plugins/update' );
	if ( ! is_wp_error( $result ) || 403 !== $result->get_error_data()['status'] ) { throw new RuntimeException( 'ZIP update restriction bypassed.' ); }
	if ( 'multisite' === $mode && 'aism_multisite_plugins' !== $result->get_error_code() ) { throw new RuntimeException( 'Multisite guard missing.' ); }
	echo "PASS: plugin ZIP update $mode restriction\n";
} finally {
	unlink( $dir . '/main.php' );
	rmdir( $dir );
	wp_clean_plugins_cache();
}
