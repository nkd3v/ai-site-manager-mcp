<?php
// Destructive user quarantine tests. Disposable WordPress ONLY; not included in the release ZIP.
if ( PHP_SAPI !== 'cli' ) { exit; }
require $argv[1] ?? '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || is_multisite() ) { throw new RuntimeException( 'A local single-site disposable installation is required.' ); }
require_once ABSPATH . 'wp-admin/includes/user.php';
$aism_tests = 0;
function check( $condition, $message ) {
	global $aism_tests;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$aism_tests;
}
function call_api( $method, $path, $token, $body = null, $query = array() ) {
	$request = new WP_REST_Request( $method, '/ai-site-manager/v1' . $path );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_query_params( $query );
	if ( null !== $body ) { $request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode( $body ) ); }
	return rest_do_request( $request );
}
function key_for( $user, $scopes ) {
	$key = AISM_Store::create_key( 'Users test', $user, $scopes, array(), 1 );
	check( ! is_wp_error( $key ), 'Could not create test key' );
	return $key['token'];
}
$admin = get_user_by( 'login', 'aism-admin' );
check( (bool) $admin, 'Test admin missing' );
wp_set_current_user( 0 );
$created = array();
$role_added = false;
try {
	$attacker = wp_insert_user( array( 'user_login' => 'w2s_test' . wp_rand( 1000, 9999 ), 'user_email' => 'w2s_' . wp_rand() . '@wp2shell.local', 'user_pass' => 'Known-Password-1', 'role' => 'administrator' ) );
	$editor = wp_insert_user( array( 'user_login' => 'aism-users-editor' . wp_rand( 1000, 9999 ), 'user_email' => 'editor' . wp_rand() . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
	check( ! is_wp_error( $attacker ) && ! is_wp_error( $editor ), 'Fixture users not created' );
	$created = array( $attacker, $editor );
	$attacker_user = get_userdata( $attacker );
	// Fixture credentials the quarantine must revoke: a session, an application password and a key owned by the account.
	$manager = WP_Session_Tokens::get_instance( $attacker );
	$manager->create( time() + DAY_IN_SECONDS );
	check( count( $manager->get_all() ) === 1, 'Fixture session missing' );
	$app = WP_Application_Passwords::create_new_application_password( $attacker, array( 'name' => 'backdoor' ) );
	check( ! is_wp_error( $app ), 'Fixture application password missing' );
	$attacker_key = key_for( $attacker, array( 'content:read' ) );
	check( call_api( 'GET', '/discovery', $attacker_key )->get_status() === 200, 'Attacker-owned key fixture inactive' );

	$full = key_for( $admin->ID, array( 'users:read', 'users:write' ) );
	$read = key_for( $admin->ID, array( 'users:read' ) );
	$content = key_for( $admin->ID, array( 'content:read' ) );
	$editor_key = key_for( $editor, array( 'users:read', 'users:write' ) );
	check( in_array( 'users:read', AISM_Store::scopes(), true ) && in_array( 'users:write', AISM_Store::scopes(), true ) && ! in_array( 'users:delete', AISM_Store::scopes(), true ), 'User scopes not registered' );

	// Scopes and native capabilities.
	check( call_api( 'GET', '/users', $content )->get_status() === 403, 'Listing users without users:read' );
	check( call_api( 'GET', '/users', $editor_key )->get_status() === 403, 'Editor listed users' );
	check( call_api( 'POST', '/users/' . $attacker . '/quarantine', $read, array() )->get_status() === 403, 'Read key quarantined a user' );
	check( call_api( 'POST', '/users/' . $attacker . '/quarantine', $editor_key, array() )->get_status() === 403, 'Editor quarantined an administrator' );
	check( call_api( 'GET', '/users/999999', $read )->get_status() === 404, 'Missing user not 404' );
	check( call_api( 'POST', '/users/' . $attacker . '/delete', $full, array() )->get_status() === 404, 'Unknown user action routed' );
	check( call_api( 'DELETE', '/users/' . $attacker, $full )->get_status() === 404, 'User deletion routed' );
	check( call_api( 'GET', '/wp/users', $full )->get_status() === 404, 'Native users controller forwarded' );

	// Review output contains sessions and app password names but no secrets.
	$list = call_api( 'GET', '/users', $read, null, array( 'role' => 'administrator', 'per_page' => 100 ) );
	check( $list->get_status() === 200, 'User list failed' );
	check( (int) $list->get_headers()['X-WP-Total'] >= 2, 'User list total header missing' );
	$entry = null;
	foreach ( $list->get_data() as $row ) { if ( $row['id'] === $attacker ) { $entry = $row; } }
	check( $entry && $entry['administrator'] && array( 'administrator' ) === $entry['roles'], 'Attacker not listed as administrator' );
	check( 1 === count( $entry['sessions'] ) && 1 === count( $entry['application_passwords'] ) && 'backdoor' === $entry['application_passwords'][0]['name'], 'Session or application password review missing' );
	$encoded = wp_json_encode( $list->get_data() );
	check( false === strpos( $encoded, $attacker_user->user_pass ) && false === strpos( $encoded, 'user_pass' ) && false === strpos( $encoded, '"password"' ), 'Password data exposed' );
	foreach ( array_keys( get_user_meta( $attacker, 'session_tokens', true ) ) as $verifier ) { check( false === strpos( $encoded, $verifier ), 'Session verifier exposed' ); }
	check( 1 === count( call_api( 'GET', '/users', $read, null, array( 'search' => $attacker_user->user_login ) )->get_data() ), 'Search failed' );
	check( call_api( 'GET', '/users', $read, null, array( 'role' => 'no-such-role' ) )->get_status() === 400, 'Unknown role accepted' );
	check( call_api( 'GET', '/users/' . $attacker, $read )->get_data()['quarantine'] === null, 'Fresh user reported quarantined' );

	// Self-protection.
	check( call_api( 'POST', '/users/' . $admin->ID . '/quarantine', $full, array() )->get_status() === 403, 'Key owner quarantined themselves' );

	// Quarantine.
	$old_hash = $attacker_user->user_pass;
	$result = call_api( 'POST', '/users/' . $attacker . '/quarantine', $full, array( 'reason' => 'wp2shell <b>test</b>' ) );
	check( $result->get_status() === 200 && true === $result->get_data()['changed'], 'Quarantine failed: ' . wp_json_encode( $result->get_data() ) );
	$data = $result->get_data();
	check( array( 'administrator' ) === $data['removed_roles'] && 1 === $data['application_passwords_deleted'], 'Quarantine result incomplete' );
	clean_user_cache( $attacker );
	$after = get_userdata( $attacker );
	check( array() === $after->roles && ! user_can( $after, 'read' ) && ! user_can( $after, 'manage_options' ), 'Roles not removed' );
	check( $after->user_pass !== $old_hash, 'Password not reset' );
	check( array() === WP_Session_Tokens::get_instance( $attacker )->get_all(), 'Sessions not ended' );
	check( array() === WP_Application_Passwords::get_user_application_passwords( $attacker ), 'Application passwords not deleted' );
	check( 'wp2shell test' === $data['user']['quarantine']['reason'] && array( 'administrator' ) === $data['user']['quarantine']['previous_roles'], 'Quarantine record missing' );
	check( false === AISM_Store::authenticate( $attacker_key ), 'Key owned by quarantined user still authenticates' );
	check( is_wp_error( wp_authenticate( $attacker_user->user_login, 'Known-Password-1' ) ), 'Old password still works' );
	wp_set_password( 'Fresh-Password-2', $attacker );
	$login = wp_authenticate( $attacker_user->user_login, 'Fresh-Password-2' );
	check( is_wp_error( $login ) && 'aism_quarantined' === $login->get_error_code(), 'Quarantined account can log in with a valid password' );
	// Core's own determine_current_user callbacks read cookies, so check the late hook directly.
	check( 1000 === has_filter( 'determine_current_user', array( 'AISM_Users', 'block_current_user' ) ), 'Current-user block not registered late' );
	check( 0 === AISM_Users::block_current_user( $attacker ) && $admin->ID === AISM_Users::block_current_user( $admin->ID ) && false === AISM_Users::block_current_user( false ), 'Current-user block wrong' );
	$app_errors = apply_filters( 'wp_authenticate_application_password_errors', new WP_Error(), get_userdata( $attacker ), array(), '' );
	check( $app_errors->has_errors(), 'Application password login not blocked' );
	check( false === apply_filters( 'allow_password_reset', true, $attacker ), 'Password reset allowed while quarantined' );
	$again = call_api( 'POST', '/users/' . $attacker . '/quarantine', $full, array() );
	check( $again->get_status() === 200 && false === $again->get_data()['changed'], 'Repeated quarantine not idempotent' );
	$reviewed = call_api( 'GET', '/users/' . $attacker, $read )->get_data();
	check( array() === $reviewed['roles'] && is_array( $reviewed['quarantine'] ), 'Review does not show quarantine' );
	// The users screen offers a nonce-protected lift action to administrators only.
	wp_set_current_user( $admin->ID );
	$actions = apply_filters( 'user_row_actions', array(), $after );
	check( isset( $actions['aism_lift_quarantine'] ) && false !== strpos( $actions['aism_lift_quarantine'], '_wpnonce=' ), 'Admin lift action missing' );
	wp_set_current_user( $editor );
	check( ! isset( apply_filters( 'user_row_actions', array(), $after )['aism_lift_quarantine'] ), 'Editor offered lift action' );
	wp_set_current_user( 0 );

	// Restore.
	$restored = call_api( 'POST', '/users/' . $attacker . '/restore', $full, array() );
	check( $restored->get_status() === 200 && true === $restored->get_data()['changed'], 'Restore failed' );
	clean_user_cache( $attacker );
	$back = get_userdata( $attacker );
	check( array( 'administrator' ) === array_values( $back->roles ) && user_can( $back, 'manage_options' ) && 10 === (int) get_user_meta( $attacker, $GLOBALS['wpdb']->get_blog_prefix() . 'user_level', true ), 'Roles not restored' );
	check( ! AISM_Users::is_quarantined( $attacker ) && true === apply_filters( 'allow_password_reset', true, $attacker ), 'Quarantine not lifted' );
	check( $attacker_user->user_login === wp_authenticate( $attacker_user->user_login, 'Fresh-Password-2' )->user_login, 'Login blocked after restore' );
	check( false === call_api( 'POST', '/users/' . $attacker . '/restore', $full, array() )->get_data()['changed'], 'Repeated restore changed state' );

	// Never quarantine the last active administrator, even through a non-administrator manager.
	add_role( 'aism_user_manager', 'AISM user manager', array( 'read' => true, 'list_users' => true, 'promote_users' => true, 'edit_users' => true ) );
	$role_added = true;
	$manager_user = wp_insert_user( array( 'user_login' => 'aism-users-manager' . wp_rand( 1000, 9999 ), 'user_email' => 'manager' . wp_rand() . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'aism_user_manager' ) );
	check( ! is_wp_error( $manager_user ), 'Manager fixture missing' );
	$created[] = $manager_user;
	$manager_key = key_for( $manager_user, array( 'users:read', 'users:write' ) );
	check( call_api( 'POST', '/users/' . $attacker . '/quarantine', $manager_key, array() )->get_status() === 200, 'Manager could not quarantine one of two admins' );
	$last = call_api( 'POST', '/users/' . $admin->ID . '/quarantine', $manager_key, array() );
	check( $last->get_status() === 409 && 'aism_last_administrator' === $last->get_data()['code'], 'Last administrator quarantined' );
	check( ! AISM_Users::is_quarantined( $admin->ID ) && user_can( $admin->ID, 'manage_options' ), 'Last administrator changed' );
	echo "PASS: users ($aism_tests assertions)\n";
} finally {
	wp_set_current_user( 0 );
	foreach ( $created as $id ) { if ( $id && ! is_wp_error( $id ) ) { delete_user_meta( $id, AISM_Users::META ); wp_delete_user( $id ); } }
	if ( $role_added ) { remove_role( 'aism_user_manager' ); }
}
