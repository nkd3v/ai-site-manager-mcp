<?php
// Destructive migration tests for a disposable local WordPress ONLY; not included in the release ZIP.
// The site imports from itself under a fake source UUID and home URL, so no second site is needed.
if ( PHP_SAPI !== 'cli' ) { exit; }
require $argv[1] ?? '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'A local disposable installation is required.' ); }
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
	$key = AISM_Store::create_key( 'Migration test', $user, $scopes, array( 'blogname' ), 1 );
	check( ! is_wp_error( $key ), 'Could not create test key' );
	return $key['token'];
}
$admin = get_user_by( 'login', 'aism-admin' );
check( (bool) $admin, 'Test admin missing' );
wp_set_current_user( 0 );
$all = array( 'migration:read', 'migration:write', 'migration:delete' );
$full = key_for( $admin->ID, $all );
$read = key_for( $admin->ID, array( 'migration:read' ) );
$write = key_for( $admin->ID, array( 'migration:read', 'migration:write' ) );
$content = key_for( $admin->ID, array( 'content:read', 'content:write' ) );
$editor_id = wp_create_user( 'aism-mig-editor-' . wp_generate_password( 6, false ), wp_generate_password(), 'mig-' . wp_generate_uuid4() . '@example.test' );
( new WP_User( $editor_id ) )->set_role( 'editor' );
$editor = key_for( $editor_id, $all );

// Scopes and capabilities.
check( in_array( 'migration:delete', AISM_Store::scopes(), true ), 'Migration scopes missing' );
check( call_api( 'GET', '/migration/manifest', $content )->get_status() === 403, 'Content key read the migration manifest' );
check( call_api( 'GET', '/migration/manifest', $editor )->get_status() === 403, 'Editor without export/manage_options exported' );
$manifest = call_api( 'GET', '/migration/manifest', $read );
check( $manifest->get_status() === 200, 'Manifest failed' );
$manifest = $manifest->get_data();
check( preg_match( '/^[a-f0-9-]{36}$/', $manifest['site_uuid'] ) && $manifest['site_uuid'] === AISM_Migration::site_uuid() && isset( $manifest['post_types']['post'], $manifest['taxonomies']['category'] ), 'Manifest shape wrong' );
check( call_api( 'POST', '/migration/begin', $read, array( 'source' => $manifest ) )->get_status() === 403, 'Read scope began an import' );
check( call_api( 'POST', '/migration/rollback', $write, array( 'source' => $manifest['site_uuid'] ) )->get_status() === 403, 'Write scope rolled back' );
check( call_api( 'POST', '/migration/begin', $full, array( 'source' => $manifest ) )->get_status() === 409, 'Same-site migration accepted' );
check( call_api( 'POST', '/migration/begin', $full, array( 'source' => array( 'site_uuid' => 'not-a-uuid', 'home' => 'x' ) ) )->get_status() === 400, 'Invalid source accepted' );
check( call_api( 'POST', '/migration/begin', $full, array( 'source' => array( 'site_uuid' => wp_generate_uuid4(), 'home' => 'javascript:alert(1)' ) ) )->get_status() === 400, 'Non-HTTP source URL accepted' );
$unknown = wp_generate_uuid4();
check( call_api( 'POST', '/migration/import', $full, array( 'source' => $unknown, 'kind' => 'posts', 'items' => array() ) )->get_status() === 409, 'Import without begin accepted' );

// Export filters credential-like and non-portable metadata.
$src_post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Export source', 'post_content' => 'x' ) );
update_post_meta( $src_post, 'stripe_secret_key', 'sk_live_x' );
update_post_meta( $src_post, 'plain_meta', 'kept' );
update_post_meta( $src_post, 'object_meta', new ArrayObject( array( 1 ) ) );
update_post_meta( $src_post, 'std_meta', (object) array( 'a' => 1 ) );
$export = call_api( 'GET', '/migration/export', $read, null, array( 'kind' => 'posts', 'ids' => (string) $src_post ) )->get_data();
$meta = (array) $export['items'][0]['meta'];
check( 1 === $export['total'] && isset( $meta['plain_meta'] ) && ! isset( $meta['stripe_secret_key'] ) && ! isset( $meta['object_meta'] ) && array( array( 'a' => 1 ) ) === $meta['std_meta'], 'Export meta filtering wrong: ' . wp_json_encode( $meta ) );
check( in_array( 'stripe_secret_key', $export['skipped_meta_keys'], true ) && in_array( 'object_meta', $export['skipped_meta_keys'], true ), 'Skipped meta not reported' );
check( call_api( 'GET', '/migration/export', $read, null, array( 'kind' => 'users' ) )->get_status() === 400, 'Invalid export kind accepted' );
check( call_api( 'GET', '/migration/export', $read, null, array( 'kind' => 'posts', 'post_type' => 'revision' ) )->get_status() === 400, 'Revision export accepted' );
$site = call_api( 'GET', '/migration/export', $read, null, array( 'kind' => 'site' ) )->get_data();
$site_options = (array) $site['items'][0]['options'];
check( isset( $site_options['blogname'] ) && ! isset( $site_options['siteurl'] ) && ! isset( $site_options['active_plugins'] ) && ! isset( $site_options['admin_email'] ), 'Site export leaked protected options' );

// Attachment downloads only serve files inside uploads.
$attachment = wp_insert_attachment( array( 'post_title' => 'Tampered', 'post_mime_type' => 'text/plain', 'post_status' => 'inherit' ), ABSPATH . 'wp-config.php' );
update_post_meta( $attachment, '_wp_attached_file', '../../../wp-config.php' );
check( call_api( 'GET', '/migration/file', $read, null, array( 'id' => $attachment ) )->get_status() === 404, 'Tampered attachment path served' );
check( call_api( 'GET', '/migration/file', $read, null, array( 'id' => $src_post ) )->get_status() === 404, 'Non-attachment served as file' );
wp_delete_post( $attachment, true );
check( call_api( 'GET', '/migration/package', $read, null, array( 'type' => 'plugin', 'id' => 'ai-site-manager/ai-site-manager.php' ) )->get_status() === 403, 'Self package exported' );
check( call_api( 'GET', '/migration/package', $read, null, array( 'type' => 'plugin', 'id' => '../wp-config.php' ) )->get_status() === 404, 'Package path traversal accepted' );
check( call_api( 'GET', '/migration/package', $read, null, array( 'type' => 'theme', 'id' => 'aism-missing-theme' ) )->get_status() === 404, 'Missing theme packaged' );
$package = call_api( 'GET', '/migration/package', $read, null, array( 'type' => 'theme', 'id' => get_stylesheet() ) );
check( 200 === $package->get_status() && 'application/zip' === $package->get_headers()['Content-Type'], 'Theme package failed' );

// Import from a fake source site.
$source = wp_generate_uuid4();
$old = 'https://old.example';
$begin = call_api( 'POST', '/migration/begin', $full, array( 'source' => array( 'site_uuid' => $source, 'home' => $old, 'siteurl' => $old, 'uploads_baseurl' => $old . '/wp-content/uploads', 'name' => 'Old' ) ) );
check( 200 === $begin->get_status(), 'Begin failed: ' . wp_json_encode( $begin->get_data() ) );
$import = function ( $kind, $items, $options = array(), $authors = array() ) use ( $full, $source ) {
	return call_api( 'POST', '/migration/import', $full, array( 'source' => $source, 'kind' => $kind, 'items' => $items, 'options' => $options, 'authors' => $authors ) );
};
check( 400 === $import( 'posts', array_fill( 0, 101, array( 'id' => 1 ) ) )->get_status(), 'Oversized import accepted' );
check( 400 === $import( 'users', array() )->get_status(), 'Invalid import kind accepted' );
$slug = 'mig-' . wp_generate_password( 6, false, false );
$terms = $import( 'terms', array(
	array( 'id' => 901, 'taxonomy' => 'category', 'name' => 'Child', 'slug' => $slug . '-child', 'parent' => 900, 'description' => 'See ' . $old . '/child/', 'meta' => array( 'tone' => array( 'warm' ) ) ),
	array( 'id' => 900, 'taxonomy' => 'category', 'name' => 'Parent', 'slug' => $slug . '-parent', 'parent' => 0 ),
	array( 'id' => 902, 'taxonomy' => 'aism_missing_tax', 'name' => 'X', 'slug' => 'x' ),
	array( 'id' => 903, 'taxonomy' => 'category', 'name' => 'Uncategorized', 'slug' => 'uncategorized' ),
	array( 'id' => 904, 'taxonomy' => 'nav_menu', 'name' => 'Old menu', 'slug' => $slug . '-menu' ),
) )->get_data();
check( 4 === count( $terms['results'] ) && 'aism_taxonomy_missing' === $terms['errors'][0]['code'], 'Term import results wrong: ' . wp_json_encode( $terms ) );
check( 'matched' === $terms['results'][2]['action'] && get_term_by( 'slug', 'uncategorized', 'category' )->term_id === $terms['results'][2]['target_id'], 'Existing term not matched by slug' );
$author = array( 7 => array( 'login' => 'nobody-' . $slug, 'email' => 'nobody@example.test' ), 8 => array( 'login' => 'renamed', 'email' => 'none@example.test' ) );
$posts = $import( 'posts', array(
	array( 'id' => 50, 'type' => 'page', 'status' => 'publish', 'slug' => $slug . '-child-page', 'title' => 'Child page', 'content' => '<p>child</p>', 'parent' => 51, 'author' => 8, 'date' => '2020-01-02 03:04:05', 'date_gmt' => '2020-01-02 03:04:05', 'modified' => '2020-02-02 00:00:00', 'modified_gmt' => '2020-02-02 00:00:00' ),
	array( 'id' => 51, 'type' => 'page', 'status' => 'publish', 'slug' => $slug . '-parent-page', 'title' => 'Parent page', 'author' => 7,
		'content' => '<!-- wp:image {"id":60} --><figure><img src="' . $old . '/wp-content/uploads/2020/01/a.jpg" class="wp-image-60"></figure><!-- /wp:image --><p><a href="' . $old . '/about/">x</a> <a href="https://old.example.au/">au</a> end ' . $old . '. <a href="' . $old . '/?p=1">q</a></p><!-- wp:latest-posts {"categories":[{"id":900,"value":"Parent"}]} /--><!-- wp:query {"query":{"taxQuery":{"category":[901]},"exclude":[50]}} /-->',
		'terms' => array( 'category' => array( 901, 999 ) ),
		'meta' => array( '_thumbnail_id' => array( '60' ), 'nested' => array( array( 'u' => array( $old . '/x/' ), 'n' => 5 ) ), '_wp_attached_file' => array( '../../wp-config.php' ), '_aism_source' => array( 'spoof' ), 'escaped' => array( '{"u":"' . str_replace( '/', '\/', $old ) . '\/y\/"}' ) ) ),
	array( 'id' => 52, 'type' => 'aism_missing_type', 'title' => 'Nope' ),
	array( 'id' => 53, 'type' => 'attachment', 'title' => 'Needs a file' ),
	array( 'id' => 54, 'type' => 'post', 'status' => 'publish', 'slug' => $slug . '-script', 'title' => 'Script', 'content' => '<p>ok</p><script>alert(1)</script>', 'terms' => array( 'category' => array( 901, 999 ) ), 'meta' => array( '_wp_page_template' => array( 'x.php' ), 'free_html' => array( '<script>x</script>' ) ) ),
	array( 'id' => 55, 'type' => 'nav_menu_item', 'status' => 'publish', 'title' => 'Parent item', 'terms' => array( 'nav_menu' => array( 904 ) ), 'meta' => array( '_menu_item_type' => array( 'post_type'  	), '_menu_item_object' => array( 'page' ), '_menu_item_object_id' => array( '51' ), '_menu_item_menu_item_parent' => array( '0' ), '_menu_item_url' => array( '' ) ) ),
	array( 'id' => 56, 'type' => 'nav_menu_item', 'status' => 'publish', 'title' => 'Child item', 'terms' => array( 'nav_menu' => array( 904 ) ), 'meta' => array( '_menu_item_type' => array( 'taxonomy' ), '_menu_item_object' => array( 'category' ), '_menu_item_object_id' => array( '901' ), '_menu_item_menu_item_parent' => array( '55' ) ) ),
), array( 'author_map' => array( 'renamed' => 'aism-admin' ) ), $author )->get_data();
check( 5 === count( $posts['results'] ) && array( 53 ) === $posts['needs_file'] && 'aism_post_type_missing' === $posts['errors'][0]['code'], 'Post import results wrong: ' . wp_json_encode( $posts ) );
check( in_array( '_wp_attached_file', $posts['skipped_meta_keys'], true ) && in_array( '_aism_source', $posts['skipped_meta_keys'], true ), 'Dangerous meta not skipped' );
check( array( 'renamed' => 'aism-admin', 'nobody-' . $slug => null ) == (array) $posts['authors'], 'Author report wrong' );
$t = function ( $id, $type = 'post' ) use ( $source ) { return AISM_Migration::lookup( $source, $type, $id ); };
check( $admin->ID === (int) get_post( $t( 50 ) )->post_author && $admin->ID === (int) get_post( $t( 51 ) )->post_author, 'Author map/default author not applied' );
check( '2020-01-02 03:04:05' === get_post( $t( 50 ) )->post_date && '2020-02-02 00:00:00' === get_post( $t( 50 ) )->post_modified, 'Dates not preserved' );
check( '' === get_post_meta( $t( 51 ), '_wp_attached_file', true ) && $source . ':51' === get_post_meta( $t( 51 ), '_aism_source', true ), 'Meta guard failed' );
// Re-importing is idempotent; an interrupted write is found through its marker.
$again = $import( 'posts', array( array( 'id' => 50, 'type' => 'page', 'status' => 'publish', 'title' => 'Child page v2', 'parent' => 51, 'slug' => $slug . '-child-page' ) ) )->get_data();
check( 'updated' === $again['results'][0]['action'] && $again['results'][0]['target_id'] === $t( 50 ) && 'Child page v2' === get_the_title( $t( 50 ) ), 'Re-import duplicated or failed' );
$orphan = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Orphan', 'post_status' => 'draft', 'meta_input' => array( '_aism_source' => $source . ':57' ) ) );
$found = $import( 'posts', array( array( 'id' => 57, 'type' => 'post', 'status' => 'draft', 'title' => 'Orphan v2' ) ) )->get_data();
check( 'updated' === $found['results'][0]['action'] && $orphan === $found['results'][0]['target_id'], 'Interrupted write was duplicated' );
$skip = $import( 'posts', array( array( 'id' => 57, 'type' => 'post', 'title' => 'Ignored' ) ), array( 'on_existing' => 'skip' ) )->get_data();
check( 'skipped' === $skip['results'][0]['action'] && 'Orphan v2' === get_the_title( $orphan ), 'on_existing=skip changed content' );
$draft = $import( 'posts', array( array( 'id' => 58, 'type' => 'post', 'status' => 'publish', 'title' => 'As draft' ), array( 'id' => 59, 'type' => 'wp_block', 'status' => 'publish', 'title' => 'Pattern' ) ), array( 'status' => 'draft' ) )->get_data();
// Only viewable content becomes a draft; patterns, menus and templates keep working.
check( 'draft' === get_post_status( $t( 58 ) ) && 'publish' === get_post_status( $t( 59 ) ), 'status=draft applied incorrectly' );
// Users without unfiltered_html: content is filtered, free-form meta and site settings are refused.
$deny = function ( $caps, $cap ) { return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps; };
add_filter( 'map_meta_cap', $deny, 10, 2 );
$filtered = $import( 'posts', array( array( 'id' => 61, 'type' => 'post', 'status' => 'publish', 'title' => 'Filtered', 'content' => '<p>ok</p><script>alert(1)</script>', 'meta' => array( '_wp_page_template' => array( 'x.php' ), 'free_html' => array( '<script>x</script>' ) ) ) ) )->get_data();
$site_denied = $import( 'site', array( array( 'id' => 1, 'options' => array( 'blogname' => 'x' ) ) ) )->get_data();
remove_filter( 'map_meta_cap', $deny, 10 );
check( false === strpos( get_post( $t( 61 ) )->post_content, '<script>' ) && 'x.php' === get_post_meta( $t( 61 ), '_wp_page_template', true ) && '' === get_post_meta( $t( 61 ), 'free_html', true ), 'Untrusted import not filtered' );
check( 'aism_site_import_forbidden' === $site_denied['errors'][0]['code'], 'Site import allowed without unfiltered_html' );
check( false !== strpos( get_post( $t( 54 ) )->post_content, '<script>' ), 'Trusted import was filtered' );
// Comments need a migrated post.
$comments = $import( 'comments', array(
	array( 'id' => 70, 'post' => 51, 'author' => 'Guest', 'author_url' => $old . '/guest/', 'content' => 'Hi ' . $old . '/about/', 'approved' => '1', 'date' => '2020-03-03 00:00:00', 'date_gmt' => '2020-03-03 00:00:00' ),
	array( 'id' => 71, 'post' => 51, 'parent' => 70, 'author' => 'Reply', 'content' => 'Reply', 'approved' => '1' ),
	array( 'id' => 72, 'post' => 9999, 'content' => 'orphan' ),
) )->get_data();
check( 2 === count( $comments['results'] ) && 'aism_comment_post_missing' === $comments['errors'][0]['code'], 'Comment import wrong' );
// Page-builder shortcodes and theme image settings reference attachments by ID. Source attachment 62 is found
// through its marker, as after an interrupted write.
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
$logo_upload = wp_upload_bits( 'aism-logo-' . $slug . '.png', null, $png );
check( empty( $logo_upload['error'] ), 'Fixture upload failed' );
$logo = wp_insert_attachment( array( 'post_title' => 'Logo', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'meta_input' => array( '_aism_source' => $source . ':62' ) ), $logo_upload['file'] );
$builder = $import( 'posts', array( array( 'id' => 63, 'type' => 'page', 'status' => 'publish', 'slug' => $slug . '-builder', 'title' => 'Builder',
	'content' => '[ux_image id="62"][ux_banner bg="62" bg_color="#fff"][featured_box img=\'62\']x[/featured_box][ux_gallery ids="62, 50"][contact-form-7 id="50" title="Form"][gallery ids="62,999"][other id="62"][section bg="#123"][ux_image_box img=62][caption id="attachment_62"]c[/caption]' ) ) )->get_data();
check( 'created' === $builder['results'][0]['action'], 'Builder page import failed' );
// Site settings with ID references; a previously missing option must be deleted again on rollback.
$front_before = get_option( 'page_on_front' );
delete_option( 'widget_nav_menu' );
$site_result = $import( 'site', array( array( 'id' => 1, 'stylesheet' => get_stylesheet(), 'options' => array(
	'page_on_front' => 51, 'show_on_front' => 'page', 'default_category' => 900, 'siteurl' => 'https://evil.example', 'active_plugins' => array( 'x/x.php' ), 'aism_site_uuid' => 'x',
	'widget_nav_menu' => array( 3 => array( 'nav_menu' => 904, 'title' => 'Menu' ), '_multiwidget' => 1 ),
	'theme_mods_' . get_stylesheet() => array( 'nav_menu_locations' => array( 'primary' => 904 ), 'custom_logo' => 60, 'header_textcolor' => 'fff', 'background_image' => $old . '/wp-content/uploads/bg.jpg',
		'site_logo' => 62, 'site_logo_dark' => '60', 'logo_width' => 62, 'header_bg_img' => '51' ),
) ) ) )->get_data();
check( array( 'siteurl', 'active_plugins', 'aism_site_uuid' ) === $site_result['results'][0]['skipped_options'] && home_url() !== 'https://evil.example', 'Protected options imported: ' . wp_json_encode( $site_result ) );
// Finalize remaps IDs and rewrites URLs exactly once.
do { $final = call_api( 'POST', '/migration/finalize', $write, array( 'source' => $source, 'limit' => 5 ) )->get_data(); } while ( $final['remaining'] > 0 );
$parent_page = get_post( $t( 51 ) );
$c = $parent_page->post_content;
check( (int) get_post( $t( 50 ) )->post_parent === $t( 51 ), 'Forward parent reference not resolved' );
check( false !== strpos( $c, '<a href="' . home_url() . '/about/">' ) && false !== strpos( $c, 'https://old.example.au/' ) && false !== strpos( $c, 'end ' . home_url() . '. ' ) && false !== strpos( $c, home_url() . '/?p=1' ), 'URL rewriting boundaries wrong: ' . $c );
check( false !== strpos( $c, '{"id":' . $t( 900, 'term' ) . ',"value":"Parent"}' ) && false !== strpos( $c, '"taxQuery":{"category":[' . $t( 901, 'term' ) . ']}' ) && false !== strpos( $c, '"exclude":[' . $t( 50 ) . ']' ), 'Block term/post references not remapped: ' . $c );
check( false !== strpos( $c, '{"id":60}' ) && false !== strpos( $c, 'wp-image-60' ), 'Unresolved media reference was altered' );
check( '' === get_post_meta( $t( 51 ), '_thumbnail_id', true ), 'Unresolved featured image kept' );
check( array( $t( 901, 'term' ) ) === wp_get_post_categories( $t( 54 ) ) && array() === wp_get_object_terms( $t( 51 ), 'category' ), 'Category assignment wrong' );
$nested = get_post_meta( $t( 51 ), 'nested', true );
check( array( 'u' => array( home_url() . '/x/' ), 'n' => 5 ) === $nested, 'Nested meta wrong: ' . wp_json_encode( $nested ) );
check( '{"u":"' . str_replace( '/', '\/', home_url() ) . '\/y\/"}' === get_post_meta( $t( 51 ), 'escaped', true ), 'Escaped JSON URL wrong: ' . get_post_meta( $t( 51 ), 'escaped', true ) );
check( $t( 900, 'term' ) === get_term( $t( 901, 'term' ) )->parent && false !== strpos( get_term( $t( 901, 'term' ) )->description, home_url() . '/child/' ) && 'warm' === get_term_meta( $t( 901, 'term' ), 'tone', true ), 'Term finalize wrong' );
check( (int) get_comment( $t( 71, 'comment' ) )->comment_parent === $t( 70, 'comment' ) && home_url() . '/guest/' === get_comment( $t( 70, 'comment' ) )->comment_author_url, 'Comment finalize wrong' );
check( (int) get_post_meta( $t( 55 ), '_menu_item_object_id', true ) === $t( 51 ) && (int) get_post_meta( $t( 56 ), '_menu_item_object_id', true ) === $t( 901, 'term' ) && (int) get_post_meta( $t( 56 ), '_menu_item_menu_item_parent', true ) === $t( 55 ), 'Menu item references wrong' );
check( (int) get_option( 'page_on_front' ) === $t( 51 ) && (int) get_option( 'default_category' ) === $t( 900, 'term' ), 'ID options not remapped' );
$mods = get_option( 'theme_mods_' . get_stylesheet() );
check( $t( 904, 'term' ) === $mods['nav_menu_locations']['primary'] && 0 === $mods['custom_logo'] && 'fff' === $mods['header_textcolor'] && home_url() . '/wp-content/uploads/bg.jpg' === $mods['background_image'], 'Theme mods wrong: ' . wp_json_encode( $mods ) );
// Theme image IDs: mapped attachments are remapped, unmigrated images cleared, non-image names and non-attachments kept.
check( $logo === $mods['site_logo'] && '' === $mods['site_logo_dark'] && 62 === $mods['logo_width'] && '51' === $mods['header_bg_img'], 'Theme image mods wrong: ' . wp_json_encode( $mods ) );
$built = get_post( $t( 63 ) )->post_content;
$expect = '[ux_image id="' . $logo . '"][ux_banner bg="' . $logo . '" bg_color="#fff"][featured_box img=\'' . $logo . '\']x[/featured_box][ux_gallery ids="' . $logo . ',' . $t( 50 ) . '"][contact-form-7 id="' . $t( 50 ) . '" title="Form"][gallery ids="' . $logo . ',999"][other id="62"][section bg="#123"][ux_image_box img=' . $logo . '][caption id="attachment_' . $logo . '"]c[/caption]';
check( $expect === $built, 'Shortcode IDs not remapped exactly once: ' . $built );
check( $t( 904, 'term' ) === get_option( 'widget_nav_menu' )[3]['nav_menu'], 'Widget menu not remapped' );
check( ! empty( $final['unresolved_count'] ) || isset( $final['unresolved'] ), 'Unresolved references not reported' );
$status = call_api( 'GET', '/migration/status', $read, null, array( 'source' => $source ) )->get_data();
check( 0 === $status['counts']['post']['pending_finalize'] && $status['counts']['post']['mapped'] >= 7, 'Status wrong: ' . wp_json_encode( $status ) );
// A second finalize is a no-op: content is never rewritten twice.
$second = call_api( 'POST', '/migration/finalize', $write, array( 'source' => $source ) )->get_data();
check( 0 === $second['processed'] && $c === get_post( $t( 51 ) )->post_content, 'Finalize ran twice' );
// Rollback deletes only what the migration created and restores settings.
$matched = $t( 903, 'term' );
$created_ids = array( $t( 50 ), $t( 51 ), $t( 55 ) );
do { $rolled = call_api( 'POST', '/migration/rollback', $full, array( 'source' => $source, 'limit' => 3 ) )->get_data(); } while ( $rolled['remaining'] > 0 );
check( ! get_post( $created_ids[0] ) && ! get_post( $created_ids[1] ) && ! get_post( $created_ids[2] ) && ! get_term_by( 'slug', $slug . '-parent', 'category' ), 'Created objects survived rollback' );
check( ! get_post( $logo ) && ! file_exists( $logo_upload['file'] ), 'Marker-adopted attachment or its file survived rollback' );
// Pre-existing matched objects stay; an interrupted write carries the marker, so it was created by the migration.
check( get_term( $matched ) && ! is_wp_error( get_term( $matched ) ) && ! get_post( $orphan ), 'Matched or marker-adopted object handling wrong' );
check( $front_before == get_option( 'page_on_front' ) && false === get_option( 'widget_nav_menu' ), 'Options not restored' );
check( ! isset( get_option( 'aism_migration_sources', array() )[ $source ] ), 'Source record kept after rollback' );
check( 409 === call_api( 'POST', '/migration/import', $full, array( 'source' => $source, 'kind' => 'posts', 'items' => array() ) )->get_status(), 'Import allowed after rollback without begin' );
// Template parts match by slug and theme instead of duplicating.
$source2 = wp_generate_uuid4();
call_api( 'POST', '/migration/begin', $full, array( 'source' => array( 'site_uuid' => $source2, 'home' => 'https://old2.example' ) ) );
$part = wp_insert_post( array( 'post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_name' => $slug . '-header', 'post_title' => 'Header', 'post_content' => 'old', 'tax_input' => array( 'wp_theme' => array( get_stylesheet() ) ) ) );
wp_set_object_terms( $part, get_stylesheet(), 'wp_theme' );
$tpl = call_api( 'POST', '/migration/import', $full, array( 'source' => $source2, 'kind' => 'posts', 'items' => array( array( 'id' => 5, 'type' => 'wp_template_part', 'status' => 'publish', 'slug' => $slug . '-header', 'title' => 'Header', 'content' => 'new', 'theme' => get_stylesheet() ) ) ) )->get_data();
check( 'matched' === $tpl['results'][0]['action'] && $part === $tpl['results'][0]['target_id'] && 'new' === get_post( $part )->post_content, 'Template part not matched by slug and theme' );
do { $rolled = call_api( 'POST', '/migration/rollback', $full, array( 'source' => $source2 ) )->get_data(); } while ( $rolled['remaining'] > 0 );
check( (bool) get_post( $part ), 'Rollback deleted a matched template part' );
// An identical file already at the source path is reused (no "-1" copy) unless another attachment owns it or the bytes differ.
$reuse = new ReflectionMethod( 'AISM_Migration', 'reusable_file' );
$reuse->setAccessible( true );
$sub = '2001/01';
$existing = wp_upload_bits( 'aism-reuse-' . $slug . '.png', null, $png, $sub );
$name = wp_basename( $existing['file'] );
$tmp = wp_tempnam( 'aism-reuse' );
file_put_contents( $tmp, $png );
$found = $reuse->invoke( null, $sub, $name, $tmp );
check( is_array( $found ) && $sub . '/' . $name === $found['relative'] && wp_normalize_path( $existing['file'] ) === $found['file'] && 'image/png' === $found['type'], 'Identical existing file not reused: ' . wp_json_encode( $found ) );
file_put_contents( $tmp, $png . 'x' );
check( null === $reuse->invoke( null, $sub, $name, $tmp ), 'Different bytes reused' );
check( null === $reuse->invoke( null, $sub, '../../../wp-config.php', $tmp ) && null === $reuse->invoke( null, $sub, 'aism-missing.png', $tmp ), 'Traversal or missing file reused' );
file_put_contents( $tmp, $png );
$owner = wp_insert_attachment( array( 'post_title' => 'Owner', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $existing['file'] );
check( null === $reuse->invoke( null, $sub, $name, $tmp ), 'File owned by another attachment reused' );
// Rollback keeps an adopted original but still removes the attachment.
delete_post_meta( $owner, '_wp_attached_file' );
update_post_meta( $owner, '_wp_attached_file', $sub . '/' . $name );
update_post_meta( $owner, '_aism_adopted_file', $sub . '/' . $name );
$delete = new ReflectionMethod( 'AISM_Migration', 'delete_attachment' );
$delete->setAccessible( true );
check( $delete->invoke( null, $owner ) && ! get_post( $owner ) && file_exists( $existing['file'] ), 'Adopted file deleted on rollback' );
wp_delete_file( $tmp );
wp_delete_file( $existing['file'] );
// Cleanup.
foreach ( array( $src_post, $orphan, $part, $t( 54 ), $t( 61 ) ) as $id ) { if ( $id ) { wp_delete_post( $id, true ); } }
wp_delete_user( $editor_id );
check( get_current_user_id() === 0, 'User identity was not restored' );
echo "PASS: $aism_tests migration assertions\n";
