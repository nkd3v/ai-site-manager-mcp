<?php
// Destructive integration tests. Disposable WordPress ONLY; not included in the release ZIP.
if ( PHP_SAPI !== 'cli' ) { exit; }
require $argv[1] ?? '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'A local disposable installation is required.' ); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
$aism_tests = 0;
function check( $condition, $message ) {
	global $aism_tests;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$aism_tests;
}
function call_api( $method, $path, $token = '', $body = null, $query = array() ) {
	$request = new WP_REST_Request( $method, '/ai-site-manager/v1' . $path );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_query_params( $query );
	if ( null !== $body ) { $request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode( $body ) ); }
	return rest_do_request( $request );
}
function key_for( $user, $scopes, $options = array() ) {
	$key = AISM_Store::create_key( 'Integration test', $user, $scopes, $options, 1 );
	check( ! is_wp_error( $key ), 'Could not create test key' );
	return $key;
}
$admin = get_user_by( 'login', 'aism-admin' );
check( (bool) $admin, 'Test admin missing' );
wp_set_current_user( 0 );
$full = key_for( $admin->ID, AISM_Store::scopes(), AISM_Store::default_options() );
$read = key_for( $admin->ID, array( 'content:read', 'settings:read', 'plugins:read' ), array( 'blogname' ) );
$write = key_for( $admin->ID, array( 'content:write', 'settings:write', 'plugins:write' ), array( 'blogname' ) );
check( call_api( 'GET', '/discovery' )->get_status() === 401, 'Missing key accepted' );
check( call_api( 'GET', '/discovery', 'aism_' . str_repeat( '0', 64 ) )->get_status() === 401, 'Unknown key accepted' );
check( call_api( 'GET', '/discovery', $full['token'] )->get_status() === 200, 'Discovery failed' );
check( get_current_user_id() === 0, 'Key identity leaked outside dispatch' );
check( call_api( 'GET', '/guide', $full['token'] )->get_status() === 200, 'Guide failed' );
check( call_api( 'GET', '/schema', $full['token'], null, array( 'resource' => 'posts' ) )->get_status() === 200, 'Live schema failed' );
check( call_api( 'POST', '/wp/posts', $read['token'], array( 'title' => 'Denied' ) )->get_status() === 403, 'Read key created content' );
$created = call_api( 'POST', '/wp/posts', $write['token'], array( 'title' => 'AI test draft', 'content' => '<p>Original</p>', 'status' => 'draft' ) );
check( $created->get_status() === 201, 'Draft create failed: ' . wp_json_encode( $created->get_data() ) );
$post_id = $created->get_data()['id'];
check( call_api( 'GET', '/wp/posts/' . $post_id, $write['token'] )->get_status() === 403, 'Write key gained read access' );
$fetched = call_api( 'GET', '/wp/posts/' . $post_id, $read['token'], null, array( 'context' => 'edit' ) );
check( $fetched->get_data()['content']['raw'] === '<p>Original</p>', 'Raw content read failed' );
$updated = call_api( 'PATCH', '/wp/posts/' . $post_id, $full['token'], array( 'title' => 'Updated title' ) );
check( $updated->get_status() === 200 && get_post( $post_id )->post_content === '<p>Original</p>', 'Partial update lost content' );
// Path parameters must take precedence over query/body parameters.
$other = wp_insert_post( array( 'post_title' => 'Other', 'post_status' => 'draft' ) );
call_api( 'POST', '/wp/posts/' . $post_id, $full['token'], array( 'id' => $other, 'title' => 'Path wins' ), array( 'id' => $other, 'resource' => 'users' ) );
check( get_post( $post_id )->post_title === 'Path wins' && get_post( $other )->post_title === 'Other', 'Path override was accepted' );
check( call_api( 'DELETE', '/wp/posts/' . $post_id, $write['token'] )->get_status() === 403, 'Write scope gained delete' );
foreach ( array( '/wp/users', '/wp/settings', '/wp/plugins', '/wp/themes', '/wp/batch', '/wp/users/1', '/wp/posts/1/autosaves', '/wp/posts/../users' ) as $blocked ) {
	check( call_api( 'GET', $blocked, $full['token'] )->get_status() >= 400, 'Unexpected proxy access: ' . $blocked );
}
check( call_api( 'POST', '/wp/posts/' . $post_id . '/revisions', $full['token'], array() )->get_status() === 405, 'Revision mutation accepted' );
$term = call_api( 'POST', '/wp/categories', $full['token'], array( 'name' => 'AI test ' . wp_generate_uuid4() ) );
check( $term->get_status() === 201, 'Category creation failed' );
$term_id = $term->get_data()['id'];
call_api( 'POST', '/wp/posts/' . $post_id, $full['token'], array( 'categories' => array( $term_id ) ) );
check( in_array( $term_id, wp_get_post_categories( $post_id ), true ), 'Taxonomy assignment failed' );
register_post_meta( 'post', 'aism_test_meta', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'auth_callback' => function () { return current_user_can( 'edit_posts' ); } ) );
// Reset the REST server to rebuild its schema after registering test metadata.
$GLOBALS['wp_rest_server'] = null;
$meta = call_api( 'POST', '/wp/posts/' . $post_id, $full['token'], array( 'meta' => array( 'aism_test_meta' => 'works' ) ) );
check( $meta->get_status() === 200 && get_post_meta( $post_id, 'aism_test_meta', true ) === 'works', 'Registered metadata failed' );
$original_name = get_option( 'blogname' );
check( call_api( 'GET', '/settings', $full['token'] )->get_status() === 200, 'Options discovery failed' );
check( call_api( 'PUT', '/settings/blogname', $read['token'], array( 'value' => 'Denied' ) )->get_status() === 403, 'Read key changed settings' );
check( call_api( 'GET', '/settings/blogdescription', $read['token'] )->get_status() === 403, 'Per-key option allowlist bypassed' );
foreach ( array( 'active_plugins', 'siteurl', 'home', 'default_role', $GLOBALS['wpdb']->prefix . 'user_roles', '_transient_test', 'my_api_key', 'aism_version' ) as $option ) {
	check( ! AISM_Store::option_allowed( $option ), 'Protected option accepted: ' . $option );
	check( call_api( 'PUT', '/settings/' . $option, $full['token'], array( 'value' => 'bad' ) )->get_status() === 403, 'Protected option written' );
}
check( call_api( 'PUT', '/settings/blogname', $full['token'], array( 'value' => 'AI test title' ) )->get_status() === 200 && get_option( 'blogname' ) === 'AI test title', 'Setting update failed' );
check( call_api( 'PUT', '/settings/posts_per_page', $full['token'], array( 'value' => 'wrong' ) )->get_status() === 400, 'Invalid setting type accepted' );
check( call_api( 'PUT', '/settings/timezone_string', $full['token'], array( 'value' => 'Not/AZone' ) )->get_status() === 400, 'Invalid timezone accepted' );
check( call_api( 'PUT', '/settings/blogname', $full['token'], array( 'value' => 'test', 'other' => 1 ) )->get_status() === 400, 'Extra setting fields accepted' );
update_option( 'blogname', $original_name );
$subscriber_id = wp_create_user( 'aism-subscriber-' . wp_generate_password( 6, false ), wp_generate_password(), 'subscriber-' . wp_generate_uuid4() . '@example.test' );
$subscriber = new WP_User( $subscriber_id );
$subscriber->set_role( 'subscriber' );
$limited = key_for( $subscriber_id, AISM_Store::scopes(), array( 'blogname' ) );
check( call_api( 'POST', '/wp/posts', $limited['token'], array( 'title' => 'Bad' ) )->get_status() === 403, 'Native content capabilities bypassed' );
check( call_api( 'GET', '/settings/blogname', $limited['token'] )->get_status() === 403, 'Settings capability bypassed' );
check( call_api( 'GET', '/plugins', $limited['token'] )->get_status() === 403, 'Plugin read capabilities bypassed' );
check( call_api( 'POST', '/plugins/install', $limited['token'], array( 'slug' => 'hello-dolly' ) )->get_status() === 403, 'Plugin install capability bypassed' );
check( call_api( 'GET', '/plugins', $full['token'] )->get_status() === 200, 'Plugin listing failed' );
check( call_api( 'POST', '/plugins/action', $read['token'], array( 'plugin' => 'hello', 'action' => 'activate' ) )->get_status() === 403, 'Read scope activated plugin' );
check( call_api( 'POST', '/plugins/action', $write['token'], array( 'plugin' => 'hello', 'action' => 'delete' ) )->get_status() === 403, 'Write scope deleted plugin' );
check( call_api( 'POST', '/plugins/action', $full['token'], array( 'plugin' => 'ai-site-manager/ai-site-manager', 'action' => 'deactivate' ) )->get_status() === 403, 'API could deactivate itself' );
check( call_api( 'POST', '/plugins/action', $full['token'], array( 'plugin' => '../wp-config.php', 'action' => 'activate' ) )->get_status() === 404, 'Plugin path traversal accepted' );
$revoked = key_for( $admin->ID, array( 'content:read' ) );
AISM_Store::revoke( $revoked['id'] );
check( call_api( 'GET', '/discovery', $revoked['token'] )->get_status() === 401, 'Revoked key accepted' );
$expired = key_for( $admin->ID, array( 'content:read' ) );
$wpdb->update( AISM_Store::table( 'keys' ), array( 'expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $expired['id'] ) );
check( call_api( 'GET', '/discovery', $expired['token'] )->get_status() === 401, 'Expired key accepted' );
$subscriber->set_role( '' );
check( call_api( 'GET', '/discovery', $limited['token'] )->get_status() === 401, 'User without read capability accepted' );
$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . AISM_Store::table( 'keys' ) . ' WHERE id = %d', $full['id'] ), ARRAY_A );
check( $stored['key_hash'] === hash( 'sha256', $full['token'] ) && strpos( wp_json_encode( $stored ), $full['token'] ) === false, 'Plaintext key stored' );
$log = $wpdb->get_row( 'SELECT * FROM ' . AISM_Store::table( 'audit' ) . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
check( isset( $log['status'], $log['key_id'] ) && strpos( wp_json_encode( $log ), $full['token'] ) === false, 'Audit data invalid' );
check( get_current_user_id() === 0, 'User identity was not restored' );
check( call_api( 'DELETE', '/wp/posts/' . $post_id, $full['token'] )->get_status() === 200 && get_post_status( $post_id ) === 'trash', 'Trash failed' );
call_api( 'DELETE', '/wp/posts/' . $post_id, $full['token'], null, array( 'force' => true ) );
wp_delete_post( $other, true );
wp_delete_term( $term_id, 'category' );
wp_delete_user( $subscriber_id );
foreach ( array( $full, $read, $write, $limited, $expired, $revoked ) as $key ) { AISM_Store::revoke( $key['id'] ); }
echo "PASS: $aism_tests integration assertions\n";
