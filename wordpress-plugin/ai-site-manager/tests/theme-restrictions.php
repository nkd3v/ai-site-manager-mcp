<?php
// Disposable WordPress ONLY. Run each mode in a separate PHP process.
if ( PHP_SAPI !== 'cli' ) { exit; }
$mode = $argv[2] ?? '';
if ( ! in_array( $mode, array( 'filemods', 'multisite' ), true ) ) { throw new RuntimeException( 'Choose filemods or multisite.' ); }
if ( 'filemods' === $mode ) { define( 'DISALLOW_FILE_MODS', true ); }
require $argv[1] ?? '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'A local disposable installation is required.' ); }
wp_set_current_user( get_user_by( 'login', 'aism-admin' )->ID );
if ( ( 'multisite' === $mode ) !== is_multisite() ) { throw new RuntimeException( 'Run multisite mode only after converting the disposable site to a network.' ); }
if ( 'multisite' === $mode ) {
	$listing = AISM_Themes::dispatch( new WP_REST_Request( 'GET', '/ai-site-manager/v1/themes' ), '/themes' );
	if ( is_wp_error( $listing ) || 200 !== $listing->get_status() ) { throw new RuntimeException( 'Multisite theme listing failed.' ); }
}
$cases = array(
	'/themes/install' => array( 'slug' => 'twentytwentyone' ),
	'/themes/upload' => null,
);
foreach ( array( 'update', 'delete' ) as $action ) { $cases[ '/themes/action:' . $action ] = array( 'theme' => get_stylesheet(), 'action' => $action ); }
if ( 'multisite' === $mode ) { $cases['/themes/action:activate'] = array( 'theme' => get_stylesheet(), 'action' => 'activate' ); }
foreach ( $cases as $path => $body ) {
	$route = explode( ':', $path )[0];
	$request = new WP_REST_Request( 'POST', '/ai-site-manager/v1' . $route );
	if ( null !== $body ) { $request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode( $body ) ); }
	$result = AISM_Themes::dispatch( $request, $route );
	if ( ! is_wp_error( $result ) || 403 !== $result->get_error_data()['status'] ) { throw new RuntimeException( 'Restriction bypassed: ' . $path ); }
	if ( 'multisite' === $mode && 'aism_multisite_themes' !== $result->get_error_code() ) { throw new RuntimeException( 'Multisite guard missing.' ); }
}
echo 'PASS: ' . count( $cases ) . " theme $mode restrictions\n";
