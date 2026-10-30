<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-to-site migration between two AI Site Manager installations.
 *
 * The source exports portable JSON pages; the target imports them, records a source→target ID map,
 * and a later finalize pass remaps IDs and rewrites source URLs exactly once per imported object.
 */
final class AISM_Migration {
	const MAX_PER_PAGE = 100;
	const MAX_BODY = 16777216;
	const MAX_PACKAGE = 268435456;
	const EXCLUDED_TYPES = array( 'revision', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_font_face', 'wp_font_family' );
	/** Matched by slug (and theme) on the target, because WordPress keeps one per theme. */
	const TEMPLATE_TYPES = array( 'wp_template', 'wp_template_part', 'wp_global_styles' );
	/** Never exported: rebuilt on the target, editor state, or credential-like. */
	const SKIP_EXPORT_META = '/^(_aism_|_edit_lock$|_edit_last$|_wp_old_slug$|_wp_old_date$|_wp_trash_meta_|_wp_desired_post_slug$|_encloseme$|_pingme$|_wp_attached_file$|_wp_attachment_metadata$|_wp_attachment_backup_sizes$)|password|passwd|secret|token|api.?key|credential|salt|nonce|session/i';
	/** Never imported: these keys control file paths or migration state. */
	const SKIP_IMPORT_META = '/^(_aism_|_wp_attached_file$|_wp_attachment_metadata$|_wp_attachment_backup_sizes$|_edit_lock$|_edit_last$|_wp_trash_meta_|_encloseme$|_pingme$)/';
	/** Imported even when the key owner lacks unfiltered_html. */
	const STRUCTURAL_META = '/^(_thumbnail_id|_wp_page_template|_wp_attachment_image_alt|_menu_item_[a-z_]+)$/D';
	const PORTABLE_OPTIONS = array(
		'blogname', 'blogdescription', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week', 'WPLANG',
		'permalink_structure', 'category_base', 'tag_base', 'show_on_front', 'page_on_front', 'page_for_posts', 'wp_page_for_privacy_policy',
		'posts_per_page', 'posts_per_rss', 'rss_use_excerpt', 'blog_public', 'default_category', 'default_post_format',
		'default_comment_status', 'default_ping_status', 'default_pingback_flag', 'comment_registration', 'comment_moderation', 'comment_previously_approved',
		'comment_max_links', 'moderation_keys', 'disallowed_keys', 'close_comments_for_old_posts', 'close_comments_days_old', 'thread_comments',
		'thread_comments_depth', 'page_comments', 'comments_per_page', 'default_comments_page', 'comment_order', 'require_name_email',
		'show_avatars', 'avatar_rating', 'avatar_default', 'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop', 'medium_size_w', 'medium_size_h',
		'medium_large_size_w', 'medium_large_size_h', 'large_size_w', 'large_size_h', 'uploads_use_yearmonth_folders', 'image_default_link_type',
		'image_default_size', 'image_default_align', 'site_icon', 'use_smilies', 'nav_menu_options', 'sidebars_widgets',
	);
	/** Shortcode attributes that hold post or attachment IDs in any shortcode (core media and page-builder images). Filter: aism_migration_shortcode_id_attributes. */
	const SHORTCODE_ID_ATTRS = array( 'ids', 'image', 'images', 'img', 'bg', 'bg_image', 'background_image', 'image_id', 'attachment_id', 'attach_id', 'thumbnail_id', 'icon_img', 'photo', 'poster' );
	/** Shortcodes whose numeric id attribute is a post ID: forms, reusable blocks, products and page-builder images. Filter: aism_migration_shortcode_id_tags. */
	const SHORTCODE_ID_TAGS = array( 'contact-form-7', 'contact-form', 'wpforms', 'block', 'ux_image', 'product', 'product_page', 'add_to_cart', 'add_to_cart_url', 'elementor-template', 'fl_builder_insert_layout', 'templatera' );
	/** Theme mod names that hold an attachment ID, for example Flatsome's site_logo and site_logo_dark. */
	const THEME_MOD_ATTACHMENT_KEY = '/(?:^|_)(?:logo|image|img|icon|favicon|bg|background|banner|photo)(?:_(?:id|dark|light|mobile|sticky|retina|white|alt|\d+))*$/D';
	const BLOCK_REGEX = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	private static $map_cache = array();
	private static $replacers = array();

	private static function error( $code, $message, $status = 400 ) { return new WP_Error( $code, $message, array( 'status' => $status ) ); }

	public static function dispatch( $request, $route, $key ) {
		@set_time_limit( 300 );
		wp_raise_memory_limit( 'admin' );
		$read = 'GET' === $request->get_method();
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( $read ? 'export' : 'import' ) ) {
			return self::error( 'aism_migration_forbidden', 'The key owner needs manage_options and the WordPress ' . ( $read ? 'export' : 'import' ) . ' capability.', 403 );
		}
		if ( ! $read && is_multisite() ) { return self::error( 'aism_multisite_migration', 'Importing through this API is supported on single-site WordPress only.', 403 ); }
		switch ( $route ) {
			case '/migration/manifest': return self::manifest();
			case '/migration/export': return self::export( $request, $key );
			case '/migration/file': return self::file( $request );
			case '/migration/package': return self::package( $request );
			case '/migration/status': return self::status( $request );
			case '/migration/begin': return self::begin( $request );
			case '/migration/import': return self::import( $request );
			case '/migration/import-file': return self::import_file( $request );
			case '/migration/finalize': return self::finalize( $request );
			case '/migration/rollback': return self::rollback( $request );
		}
		return self::error( 'aism_route_not_allowed', 'Unknown migration endpoint.', 404 );
	}

	/* ---------- Shared helpers ---------- */

	public static function site_uuid() {
		$uuid = get_option( 'aism_site_uuid' );
		if ( ! is_string( $uuid ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $uuid ) ) {
			$uuid = wp_generate_uuid4();
			update_option( 'aism_site_uuid', $uuid, false );
		}
		return $uuid;
	}

	private static function post_types() {
		$types = array();
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( ! in_array( $type->name, self::EXCLUDED_TYPES, true ) && false !== $type->can_export ) { $types[] = $type->name; }
		}
		return $types;
	}

	private static function statuses( $include_trash = false ) {
		return array_values( array_diff( get_post_stati(), $include_trash ? array( 'auto-draft' ) : array( 'auto-draft', 'trash' ) ) );
	}

	private static function taxonomies() { return array_values( get_taxonomies() ); }

	private static function page_args( $request, $default ) {
		$per_page = (int) ( $request->get_param( 'per_page' ) ?? $default );
		$page = (int) ( $request->get_param( 'page' ) ?? 1 );
		return array( max( 1, min( self::MAX_PER_PAGE, $per_page ) ), max( 1, $page ) );
	}

	private static function ids_param( $value ) {
		if ( null === $value || '' === $value ) { return null; }
		$list = is_array( $value ) ? $value : explode( ',', (string) $value );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $list ) ) ) );
		return array_slice( $ids, 0, self::MAX_PER_PAGE );
	}

	/** Make a stored PHP value JSON-safe. Only plain arrays/stdClass/scalars with valid UTF-8 survive. */
	private static function portable( $value, &$ok ) {
		if ( $value instanceof stdClass ) { $value = get_object_vars( $value ); }
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::portable( $v, $ok );
				if ( ! $ok ) { return null; }
			}
			return $value;
		}
		if ( is_object( $value ) || is_resource( $value ) || ( is_float( $value ) && ! is_finite( $value ) ) || ( is_string( $value ) && ! preg_match( '//u', $value ) ) ) { $ok = false; return null; }
		return $value;
	}

	/** Decode a raw database value without instantiating classes. */
	private static function decode_stored( $raw, &$ok ) {
		$ok = true;
		if ( is_string( $raw ) && is_serialized( $raw ) ) {
			$value = @unserialize( trim( $raw ), array( 'allowed_classes' => array( 'stdClass' ) ) );
			if ( false === $value && 'b:0;' !== trim( $raw ) ) { $ok = false; return null; }
			return self::portable( $value, $ok );
		}
		return self::portable( $raw, $ok );
	}

	private static function export_meta( $type, $id, &$skipped ) {
		$result = array();
		foreach ( (array) get_metadata( $type, $id ) as $key => $values ) {
			$key = (string) $key;
			if ( preg_match( self::SKIP_EXPORT_META, $key ) ) { $skipped[ $key ] = true; continue; }
			$out = array();
			foreach ( (array) $values as $raw ) {
				$value = self::decode_stored( $raw, $ok );
				if ( ! $ok ) { $skipped[ $key ] = true; continue 2; }
				$out[] = $value;
			}
			$result[ $key ] = $out;
		}
		return (object) $result;
	}

	private static function import_meta( $type, $id, $meta, &$skipped ) {
		$keys = array();
		if ( ! is_array( $meta ) ) { return $keys; }
		$trusted = current_user_can( 'unfiltered_html' );
		foreach ( $meta as $key => $values ) {
			$key = (string) $key;
			if ( '' === $key || strlen( $key ) > 255 || ! is_array( $values ) || preg_match( self::SKIP_IMPORT_META, $key ) || ( ! $trusted && ! preg_match( self::STRUCTURAL_META, $key ) ) ) { $skipped[ $key ] = true; continue; }
			delete_metadata( $type, $id, wp_slash( $key ) );
			foreach ( $values as $value ) { add_metadata( $type, $id, wp_slash( $key ), wp_slash( $value ) ); }
			$keys[] = $key;
		}
		return $keys;
	}

	private static function author_info( $user_id, &$authors ) {
		$user_id = (int) $user_id;
		if ( $user_id && ! isset( $authors[ $user_id ] ) ) {
			$user = get_userdata( $user_id );
			$authors[ $user_id ] = $user ? array( 'login' => $user->user_login, 'email' => $user->user_email, 'display_name' => $user->display_name ) : null;
		}
		return $user_id;
	}

	/* ---------- Source: manifest, export and downloads ---------- */

	private static function manifest() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$post_types = array();
		foreach ( self::post_types() as $name ) {
			$counts = array_intersect_key( (array) wp_count_posts( $name ), array_flip( self::statuses() ) );
			$post_types[ $name ] = array( 'label' => get_post_type_object( $name )->label, 'counts' => (object) array_map( 'intval', array_filter( $counts ) ), 'total' => (int) array_sum( $counts ) );
		}
		$taxonomies = array();
		foreach ( self::taxonomies() as $name ) {
			$count = wp_count_terms( array( 'taxonomy' => $name, 'hide_empty' => false ) );
			$taxonomies[ $name ] = is_wp_error( $count ) ? 0 : (int) $count;
		}
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array( 'plugin' => $file, 'name' => $data['Name'], 'version' => $data['Version'], 'slug' => false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file ), 'active' => is_plugin_active( $file ), 'self' => plugin_basename( AISM_DIR . 'ai-site-manager.php' ) === $file );
		}
		$themes = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$themes[] = array( 'theme' => $stylesheet, 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ), 'template' => $theme->get_template(), 'active' => get_stylesheet() === $stylesheet );
		}
		$uploads = wp_get_upload_dir();
		$comments = get_comments( array( 'status' => 'all', 'count' => true, 'post_type' => self::post_types() ) );
		return array(
			'site_uuid' => self::site_uuid(), 'name' => get_bloginfo( 'name' ), 'home' => home_url(), 'siteurl' => site_url(), 'uploads_baseurl' => $uploads['baseurl'],
			'plugin_version' => AISM_VERSION, 'wordpress_version' => get_bloginfo( 'version' ), 'php_version' => PHP_VERSION, 'multisite' => is_multisite(), 'locale' => get_locale(),
			'permalink_structure' => get_option( 'permalink_structure' ), 'theme' => array( 'stylesheet' => get_stylesheet(), 'template' => get_template() ),
			'themes' => $themes, 'plugins' => $plugins, 'mu_plugins' => array_values( wp_list_pluck( get_mu_plugins(), 'Name' ) ), 'dropins' => array_keys( get_dropins() ),
			'post_types' => $post_types, 'taxonomies' => $taxonomies, 'comments' => (int) $comments,
			'imported_sources' => array_values( self::sources() ),
			'capabilities' => array( 'export' => current_user_can( 'export' ), 'import' => current_user_can( 'import' ) && ! is_multisite(), 'unfiltered_html' => current_user_can( 'unfiltered_html' ), 'upload_files' => current_user_can( 'upload_files' ) ),
			'limits' => array( 'max_per_page' => self::MAX_PER_PAGE, 'max_upload_bytes' => (int) wp_max_upload_size(), 'zip_packages' => class_exists( 'ZipArchive' ) || file_exists( ABSPATH . 'wp-admin/includes/class-pclzip.php' ) ),
			'not_migrated' => array( 'User accounts and passwords (authors are matched by login or email)', 'Must-use plugins and drop-ins', 'Custom database tables and files outside uploads, plugins and themes', 'Revisions, spam/trashed comments and credential-like metadata or options' ),
		);
	}

	private static function export( $request, $key ) {
		$kind = $request->get_param( 'kind' );
		if ( 'terms' === $kind ) { return self::export_terms( $request ); }
		if ( 'posts' === $kind ) { return self::export_posts( $request ); }
		if ( 'comments' === $kind ) { return self::export_comments( $request ); }
		if ( 'site' === $kind ) { return self::export_site( $key ); }
		return self::error( 'aism_invalid_kind', 'kind must be terms, posts, comments or site.' );
	}

	private static function modified_after( $request ) {
		$value = $request->get_param( 'modified_after' );
		if ( null === $value || '' === $value ) { return null; }
		$time = is_string( $value ) ? strtotime( $value ) : false;
		return false === $time ? false : gmdate( 'Y-m-d H:i:s', $time );
	}

	private static function export_terms( $request ) {
		list( $per_page, $page ) = self::page_args( $request, 100 );
		$taxonomies = self::taxonomies();
		$args = array( 'taxonomy' => $taxonomies, 'hide_empty' => false, 'orderby' => 'term_id', 'order' => 'ASC', 'update_term_meta_cache' => true );
		$ids = self::ids_param( $request->get_param( 'ids' ) );
		if ( null !== $ids ) {
			// Include ancestors so a selective copy keeps its hierarchy.
			foreach ( $ids as $id ) {
				$term = get_term( $id );
				if ( $term && ! is_wp_error( $term ) ) { $ids = array_merge( $ids, get_ancestors( $id, $term->taxonomy, 'taxonomy' ) ); }
			}
			$args['include'] = $ids ? array_unique( $ids ) : array( 0 );
		}
		$total = (int) get_terms( array_merge( $args, array( 'fields' => 'count' ) ) );
		$terms = get_terms( array_merge( $args, array( 'number' => $per_page, 'offset' => ( $page - 1 ) * $per_page ) ) );
		if ( is_wp_error( $terms ) ) { return $terms; }
		$skipped = array();
		$items = array();
		foreach ( $terms as $term ) {
			$items[] = array( 'id' => $term->term_id, 'taxonomy' => $term->taxonomy, 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'parent' => $term->parent, 'meta' => self::export_meta( 'term', $term->term_id, $skipped ) );
		}
		return self::page( 'terms', $page, $per_page, $total, $items, array( 'skipped_meta_keys' => array_keys( $skipped ) ) );
	}

	private static function page( $kind, $page, $per_page, $total, $items, $extra = array() ) {
		return array_merge( array( 'kind' => $kind, 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => (int) ceil( $total / $per_page ), 'items' => $items ), $extra );
	}

	private static function export_posts( $request ) {
		list( $per_page, $page ) = self::page_args( $request, 50 );
		$types = self::post_types();
		$type = $request->get_param( 'post_type' );
		if ( null !== $type && '' !== $type ) {
			if ( ! is_string( $type ) || ! in_array( $type, $types, true ) ) { return self::error( 'aism_invalid_post_type', 'Use an exportable post type from /migration/manifest.' ); }
			$types = array( $type );
		}
		$after = self::modified_after( $request );
		if ( false === $after ) { return self::error( 'aism_invalid_date', 'modified_after must be a date/time string.' ); }
		$args = array( 'post_type' => $types, 'post_status' => self::statuses( rest_sanitize_boolean( $request->get_param( 'include_trash' ) ) ), 'posts_per_page' => $per_page, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'ignore_sticky_posts' => true, 'suppress_filters' => true );
		$ids = self::ids_param( $request->get_param( 'ids' ) );
		if ( null !== $ids ) { $args['post__in'] = $ids ? $ids : array( 0 ); }
		if ( $after ) { $args['date_query'] = array( array( 'column' => 'post_modified_gmt', 'after' => $after, 'inclusive' => true ) ); }
		$query = new WP_Query( $args );
		$authors = array();
		$skipped = array();
		$items = array();
		$uploads = wp_get_upload_dir();
		foreach ( $query->posts as $post ) {
			$terms = array();
			$assigned = get_object_taxonomies( $post->post_type ) ? wp_get_object_terms( $post->ID, get_object_taxonomies( $post->post_type ) ) : array();
			foreach ( is_wp_error( $assigned ) ? array() : $assigned as $term ) { $terms[ $term->taxonomy ][] = $term->term_id; }
			$deps = array( 'post' => array(), 'term' => array() );
			self::content_refs( $post->post_content, function ( $kind, $id ) use ( &$deps ) { $deps[ $kind ][ $id ] = true; return null; } );
			$thumbnail = (int) get_post_meta( $post->ID, '_thumbnail_id', true );
			if ( $thumbnail ) { $deps['post'][ $thumbnail ] = true; }
			$item = array(
				'id' => $post->ID, 'type' => $post->post_type, 'status' => $post->post_status, 'slug' => $post->post_name, 'title' => $post->post_title,
				'content' => $post->post_content, 'excerpt' => $post->post_excerpt, 'date' => $post->post_date, 'date_gmt' => $post->post_date_gmt,
				'modified' => $post->post_modified, 'modified_gmt' => $post->post_modified_gmt, 'parent' => $post->post_parent, 'menu_order' => $post->menu_order,
				'comment_status' => $post->comment_status, 'ping_status' => $post->ping_status, 'password' => $post->post_password, 'mime_type' => $post->post_mime_type,
				'author' => self::author_info( $post->post_author, $authors ), 'sticky' => 'post' === $post->post_type && is_sticky( $post->ID ),
				'terms' => (object) $terms, 'meta' => self::export_meta( 'post', $post->ID, $skipped ),
				'dependencies' => array( 'posts' => array_keys( $deps['post'] ), 'terms' => array_keys( $deps['term'] ) ),
			);
			if ( in_array( $post->post_type, self::TEMPLATE_TYPES, true ) ) {
				$theme = wp_get_object_terms( $post->ID, 'wp_theme', array( 'fields' => 'names' ) );
				$item['theme'] = is_array( $theme ) && $theme ? $theme[0] : null;
			}
			if ( 'attachment' === $post->post_type ) {
				$path = self::attachment_path( $post->ID );
				$relative = $path && 0 === strpos( $path, trailingslashit( $uploads['basedir'] ) ) ? substr( $path, strlen( trailingslashit( $uploads['basedir'] ) ) ) : null;
				$item['attachment'] = array( 'file' => $relative, 'bytes' => $path && is_file( $path ) ? filesize( $path ) : null, 'url' => wp_get_attachment_url( $post->ID ), 'urls' => self::attachment_urls( $post->ID ) );
			}
			$items[] = $item;
		}
		return self::page( 'posts', $page, $per_page, (int) $query->found_posts, $items, array( 'authors' => (object) $authors, 'skipped_meta_keys' => array_keys( $skipped ) ) );
	}

	private static function export_comments( $request ) {
		list( $per_page, $page ) = self::page_args( $request, 100 );
		$after = self::modified_after( $request );
		if ( false === $after ) { return self::error( 'aism_invalid_date', 'modified_after must be a date/time string.' ); }
		$args = array( 'status' => 'all', 'post_type' => self::post_types(), 'orderby' => 'comment_ID', 'order' => 'ASC', 'update_comment_meta_cache' => true );
		if ( $after ) { $args['date_query'] = array( array( 'column' => 'comment_date_gmt', 'after' => $after, 'inclusive' => true ) ); }
		$total = (int) get_comments( array_merge( $args, array( 'count' => true ) ) );
		$comments = get_comments( array_merge( $args, array( 'number' => $per_page, 'offset' => ( $page - 1 ) * $per_page ) ) );
		$authors = array();
		$skipped = array();
		$items = array();
		foreach ( $comments as $comment ) {
			$items[] = array(
				'id' => (int) $comment->comment_ID, 'post' => (int) $comment->comment_post_ID, 'parent' => (int) $comment->comment_parent, 'user' => self::author_info( $comment->user_id, $authors ),
				'author' => $comment->comment_author, 'author_email' => $comment->comment_author_email, 'author_url' => $comment->comment_author_url, 'author_ip' => $comment->comment_author_IP,
				'date' => $comment->comment_date, 'date_gmt' => $comment->comment_date_gmt, 'content' => $comment->comment_content, 'karma' => (int) $comment->comment_karma,
				'approved' => $comment->comment_approved, 'agent' => $comment->comment_agent, 'type' => $comment->comment_type, 'meta' => self::export_meta( 'comment', $comment->comment_ID, $skipped ),
			);
		}
		return self::page( 'comments', $page, $per_page, $total, $items, array( 'authors' => (object) $authors, 'skipped_meta_keys' => array_keys( $skipped ) ) );
	}

	private static function site_option_names( $key ) {
		global $wpdb;
		$names = array_merge( self::PORTABLE_OPTIONS, array( 'theme_mods_' . get_stylesheet() ), (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'widget\\_%'" ), $key ? (array) $key['option_names'] : array() );
		return array_values( array_filter( array_unique( $names ), array( 'AISM_Store', 'option_allowed' ) ) );
	}

	private static function export_site( $key ) {
		$options = array();
		$skipped = array();
		foreach ( self::site_option_names( $key ) as $name ) {
			$value = get_option( $name, null );
			if ( null === $value ) { continue; }
			$ok = true;
			$value = self::portable( $value, $ok );
			if ( $ok ) { $options[ $name ] = $value; } else { $skipped[] = $name; }
		}
		return self::page( 'site', 1, 1, 1, array( array( 'id' => 1, 'stylesheet' => get_stylesheet(), 'options' => (object) $options ) ), array( 'skipped_options' => $skipped ) );
	}

	private static function attachment_path( $id ) {
		$path = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $id ) : false;
		if ( ! $path || ! is_file( $path ) ) { $path = get_attached_file( $id ); }
		return $path ? wp_normalize_path( $path ) : null;
	}

	private static function attachment_urls( $id ) {
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) { return (object) array(); }
		$dir = trailingslashit( dirname( $url ) );
		$meta = wp_get_attachment_metadata( $id );
		$urls = array( 'full' => $url );
		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) { $urls['original'] = $dir . $meta['original_image']; }
		foreach ( is_array( $meta ) && isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array() as $size => $info ) {
			if ( ! empty( $info['file'] ) ) { $urls[ $size ] = $dir . $info['file']; }
		}
		return $urls;
	}

	/** Send raw bytes after the REST server has sent headers. */
	private static function file_response( $path, $mime, $filename, $delete = false ) {
		$response = new WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', $mime );
		$response->header( 'Content-Disposition', 'attachment; filename="' . str_replace( array( '"', "\r", "\n", '\\' ), '_', $filename ) . '"' );
		$response->header( 'Content-Length', (string) filesize( $path ) );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$serve = function ( $served, $result ) use ( &$serve, $response, $path, $delete ) {
			if ( $served || $result !== $response ) { return $served; }
			remove_filter( 'rest_pre_serve_request', $serve, 999 );
			readfile( $path );
			if ( $delete ) { wp_delete_file( $path ); }
			return true;
		};
		add_filter( 'rest_pre_serve_request', $serve, 999, 2 );
		return $response;
	}

	private static function file( $request ) {
		$id = absint( $request->get_param( 'id' ) );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'attachment' !== $post->post_type ) { return self::error( 'aism_file_missing', 'Use an attachment ID from the posts export.', 404 ); }
		$path = self::attachment_path( $id );
		$real = $path ? realpath( $path ) : false;
		$base = realpath( wp_get_upload_dir()['basedir'] );
		// Guard against tampered attachment paths: serve only regular files inside uploads.
		if ( ! $real || ! $base || 0 !== strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $base ) ) ) || ! is_file( $real ) || ! is_readable( $real ) ) {
			return self::error( 'aism_file_missing', 'This attachment has no local file in the uploads directory. It may be stored offsite.', 404 );
		}
		return self::file_response( $real, $post->post_mime_type ? $post->post_mime_type : 'application/octet-stream', wp_basename( $real ) );
	}

	private static function package( $request ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$type = $request->get_param( 'type' );
		$id = $request->get_param( 'id' );
		if ( ! is_string( $id ) || '' === $id || 0 !== validate_file( $id ) ) { return self::error( 'aism_package_missing', 'Provide an installed plugin or theme identifier.', 404 ); }
		if ( 'plugin' === $type ) {
			if ( ! current_user_can( 'activate_plugins' ) ) { return self::error( 'aism_package_forbidden', 'The key owner cannot manage plugins.', 403 ); }
			$plugins = get_plugins();
			if ( ! isset( $plugins[ $id ] ) && isset( $plugins[ $id . '.php' ] ) ) { $id .= '.php'; }
			if ( ! isset( $plugins[ $id ] ) ) { return self::error( 'aism_package_missing', 'Use an installed plugin identifier from /migration/manifest.', 404 ); }
			if ( plugin_basename( AISM_DIR . 'ai-site-manager.php' ) === $id ) { return self::error( 'aism_self_management', 'Install AI Site Manager on the target from its release ZIP.', 403 ); }
			if ( false === strpos( $id, '/' ) ) { return self::error( 'aism_package_single_file', 'Single-file plugins in the plugins root cannot be packaged. Copy the file manually.', 409 ); }
			$root = WP_PLUGIN_DIR . '/' . dirname( $id );
		} elseif ( 'theme' === $type ) {
			if ( ! current_user_can( 'switch_themes' ) ) { return self::error( 'aism_package_forbidden', 'The key owner cannot manage themes.', 403 ); }
			$theme = wp_get_theme( $id );
			if ( ! $theme->exists() ) { return self::error( 'aism_package_missing', 'Use an installed theme stylesheet from /migration/manifest.', 404 ); }
			$root = $theme->get_stylesheet_directory();
		} else {
			return self::error( 'aism_invalid_package', 'type must be plugin or theme.' );
		}
		$zip = self::zip_directory( $root );
		if ( is_wp_error( $zip ) ) { return $zip; }
		return self::file_response( $zip, 'application/zip', basename( $root ) . '.zip', true );
	}

	private static function zip_directory( $root ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$root = realpath( $root );
		if ( ! $root || ! is_dir( $root ) ) { return self::error( 'aism_package_missing', 'The package directory is missing.', 404 ); }
		$prefix = trailingslashit( wp_normalize_path( $root ) );
		$folder = basename( $root );
		$files = array();
		$bytes = 0;
		$iterator = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			function ( $file ) { return ! $file->isLink() && ! in_array( $file->getFilename(), array( '.git', '.svn', '.hg', '.DS_Store' ), true ); }
		) );
		foreach ( $iterator as $file ) {
			$real = realpath( $file->getPathname() );
			// Symlinks are skipped; every file must resolve inside the package directory.
			if ( ! $file->isFile() || ! $real || 0 !== strpos( wp_normalize_path( $real ), $prefix ) ) { continue; }
			$bytes += $file->getSize();
			if ( $bytes > self::MAX_PACKAGE ) { return self::error( 'aism_package_too_large', 'The package exceeds 256 MiB.', 413 ); }
			$files[ $real ] = $folder . '/' . substr( wp_normalize_path( $real ), strlen( $prefix ) );
		}
		$temp = wp_tempnam( 'aism-package.zip' );
		if ( ! $temp ) { return self::error( 'aism_package_failed', 'Could not create a temporary file.', 500 ); }
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $temp, ZipArchive::OVERWRITE ) ) { wp_delete_file( $temp ); return self::error( 'aism_package_failed', 'Could not create the ZIP.', 500 ); }
			$zip->addEmptyDir( $folder );
			foreach ( $files as $path => $name ) { $zip->addFile( $path, $name ); }
			if ( ! $zip->close() ) { wp_delete_file( $temp ); return self::error( 'aism_package_failed', 'Could not write the ZIP.', 500 ); }
		} else {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			wp_delete_file( $temp );
			$archive = new PclZip( $temp );
			if ( ! $archive->create( array_keys( $files ), PCLZIP_OPT_REMOVE_PATH, wp_normalize_path( dirname( $root ) ) ) ) { return self::error( 'aism_package_failed', 'Could not create the ZIP.', 500 ); }
		}
		return $temp;
	}

	/* ---------- Target: source records and ID map ---------- */

	private static function sources() {
		$sources = get_option( 'aism_migration_sources', array() );
		return is_array( $sources ) ? $sources : array();
	}

	private static function source_param( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $value ) ) { return self::error( 'aism_invalid_source', 'source must be the source site_uuid from its manifest.' ); }
		$sources = self::sources();
		if ( ! isset( $sources[ $value ] ) ) { return self::error( 'aism_migration_not_started', 'Call POST /migration/begin with the source manifest first.', 409 ); }
		return $value;
	}

	private static function begin( $request ) {
		$body = $request->get_json_params();
		$manifest = is_array( $body ) && isset( $body['source'] ) && is_array( $body['source'] ) ? $body['source'] : null;
		if ( ! $manifest || ! isset( $manifest['site_uuid'], $manifest['home'] ) || ! is_string( $manifest['site_uuid'] ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $manifest['site_uuid'] ) ) { return self::error( 'aism_invalid_source', 'Send {"source": <source manifest>} including site_uuid and home.' ); }
		$record = array( 'site_uuid' => $manifest['site_uuid'], 'name' => '', 'began_at' => gmdate( 'c' ) );
		foreach ( array( 'home', 'siteurl', 'uploads_baseurl' ) as $field ) {
			$url = isset( $manifest[ $field ] ) && is_string( $manifest[ $field ] ) ? untrailingslashit( $manifest[ $field ] ) : '';
			if ( '' !== $url && ! preg_match( '#^https?://[^\s/?\#]+(?:/[^\s?\#]*)?$#iD', $url ) ) { return self::error( 'aism_invalid_source', "Invalid source $field." ); }
			$record[ $field ] = $url;
		}
		if ( isset( $manifest['name'] ) && is_string( $manifest['name'] ) ) { $record['name'] = sanitize_text_field( $manifest['name'] ); }
		if ( $record['site_uuid'] === self::site_uuid() && $record['home'] === untrailingslashit( home_url() ) ) { return self::error( 'aism_same_site', 'The source and target are the same site.', 409 ); }
		$sources = self::sources();
		if ( isset( $sources[ $record['site_uuid'] ] ) ) { $record['began_at'] = $sources[ $record['site_uuid'] ]['began_at']; }
		$sources[ $record['site_uuid'] ] = $record;
		update_option( 'aism_migration_sources', $sources, false );
		unset( self::$replacers[ $record['site_uuid'] ] );
		return array( 'source' => $record, 'target' => array( 'site_uuid' => self::site_uuid(), 'home' => home_url() ), 'status' => self::counts( $record['site_uuid'] ) );
	}

	private static function row( $source, $type, $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . AISM_Store::table( 'migration_map' ) . ' WHERE source = %s AND object_type = %s AND source_id = %d', $source, $type, $id ), ARRAY_A );
	}

	private static function exists( $type, $id ) {
		if ( 'post' === $type ) { return (bool) get_post( $id ); }
		if ( 'term' === $type ) { $term = get_term( (int) $id ); return $term && ! is_wp_error( $term ); }
		if ( 'comment' === $type ) { return (bool) get_comment( $id ); }
		return true;
	}

	/** Target ID for a source object, or 0. Falls back to the _aism_source marker after an interrupted write. */
	public static function lookup( $source, $type, $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) { return 0; }
		$cache = $source . ':' . $type . ':' . $id;
		if ( isset( self::$map_cache[ $cache ] ) ) { return self::$map_cache[ $cache ]; }
		$row = self::row( $source, $type, $id );
		$target = $row ? (int) $row['target_id'] : 0;
		if ( $target && ! self::exists( $type, $target ) ) {
			$wpdb->delete( AISM_Store::table( 'migration_map' ), array( 'id' => $row['id'] ) );
			$target = 0;
		}
		if ( ! $target && in_array( $type, array( 'post', 'term', 'comment' ), true ) ) {
			$table = _get_meta_table( $type );
			$column = $type . '_id';
			$target = (int) $wpdb->get_var( $wpdb->prepare( "SELECT $column FROM $table WHERE meta_key = '_aism_source' AND meta_value = %s LIMIT 1", $source . ':' . $id ) );
			if ( $target && ! self::exists( $type, $target ) ) { $target = 0; }
			// Already written objects are not finalized again; a retried import marks them pending.
			if ( $target ) { self::remember( $source, $type, $id, $target, true, array(), false ); }
		}
		return self::$map_cache[ $cache ] = $target;
	}

	private static function remember( $source, $type, $source_id, $target_id, $created, $refs, $pending = true ) {
		global $wpdb;
		$table = AISM_Store::table( 'migration_map' );
		$row = self::row( $source, $type, $source_id );
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( $row ) {
			$old = json_decode( (string) $row['refs'], true );
			foreach ( array( 'urls', 'previous' ) as $keep ) { if ( is_array( $old ) && isset( $old[ $keep ] ) && ! isset( $refs[ $keep ] ) ) { $refs[ $keep ] = $old[ $keep ]; } }
			$created = (int) $row['target_id'] === (int) $target_id ? (bool) $row['created'] : $created;
			$wpdb->update( $table, array( 'target_id' => $target_id, 'created' => $created ? 1 : 0, 'pending' => $pending ? 1 : 0, 'refs' => wp_json_encode( $refs ), 'updated_at' => $now ), array( 'id' => $row['id'] ) );
		} else {
			$wpdb->insert( $table, array( 'source' => $source, 'object_type' => $type, 'source_id' => $source_id, 'target_id' => $target_id, 'created' => $created ? 1 : 0, 'pending' => $pending ? 1 : 0, 'refs' => wp_json_encode( $refs ), 'updated_at' => $now ) );
		}
		self::$map_cache[ $source . ':' . $type . ':' . $source_id ] = (int) $target_id;
	}

	private static function counts( $source ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_type, COUNT(*) AS total, SUM(created) AS created, SUM(pending) AS pending FROM ' . AISM_Store::table( 'migration_map' ) . ' WHERE source = %s GROUP BY object_type', $source ), ARRAY_A );
		$result = array();
		foreach ( $rows as $row ) { $result[ $row['object_type'] ] = array( 'mapped' => (int) $row['total'], 'created' => (int) $row['created'], 'pending_finalize' => (int) $row['pending'] ); }
		$by_type = $wpdb->get_results( $wpdb->prepare( 'SELECT p.post_type, COUNT(*) AS total FROM ' . AISM_Store::table( 'migration_map' ) . " m JOIN {$wpdb->posts} p ON p.ID = m.target_id WHERE m.source = %s AND m.object_type = 'post' GROUP BY p.post_type", $source ), ARRAY_A );
		$result['post_types'] = (object) array_map( 'intval', wp_list_pluck( $by_type, 'total', 'post_type' ) );
		return $result;
	}

	private static function status( $request ) {
		$source = $request->get_param( 'source' );
		if ( null === $source ) { return array( 'site_uuid' => self::site_uuid(), 'sources' => array_values( self::sources() ) ); }
		$source = self::source_param( $source );
		if ( is_wp_error( $source ) ) { return $source; }
		return array( 'source' => self::sources()[ $source ], 'counts' => self::counts( $source ) );
	}

	/* ---------- Target: import ---------- */

	private static function options( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$map = array();
		foreach ( isset( $raw['author_map'] ) && is_array( $raw['author_map'] ) ? $raw['author_map'] : array() as $from => $to ) {
			if ( is_string( $to ) ) { $map[ (string) $from ] = $to; }
		}
		$default = isset( $raw['default_author'] ) && is_string( $raw['default_author'] ) ? get_user_by( 'login', $raw['default_author'] ) : false;
		return array(
			'status' => isset( $raw['status'] ) && 'draft' === $raw['status'] ? 'draft' : 'preserve',
			'on_existing' => isset( $raw['on_existing'] ) && 'skip' === $raw['on_existing'] ? 'skip' : 'update',
			'match_slugs' => ! empty( $raw['match_slugs'] ),
			'author_map' => $map,
			'default_author' => $default ? $default->ID : get_current_user_id(),
		);
	}

	private static function author( $source_user, &$ctx, $fallback = true ) {
		$source_user = (int) $source_user;
		if ( ! $source_user ) { return $fallback ? $ctx['options']['default_author'] : 0; }
		if ( ! isset( $ctx['resolved'][ $source_user ] ) ) {
			$info = isset( $ctx['authors'][ $source_user ] ) && is_array( $ctx['authors'][ $source_user ] ) ? $ctx['authors'][ $source_user ] : array();
			$login = isset( $info['login'] ) && is_string( $info['login'] ) ? $info['login'] : '';
			$user = false;
			if ( '' !== $login && isset( $ctx['options']['author_map'][ $login ] ) ) { $user = get_user_by( 'login', $ctx['options']['author_map'][ $login ] ); }
			if ( ! $user && '' !== $login ) { $user = get_user_by( 'login', $login ); }
			if ( ! $user && ! empty( $info['email'] ) && is_string( $info['email'] ) ) { $user = get_user_by( 'email', $info['email'] ); }
			$ctx['resolved'][ $source_user ] = $user ? $user->ID : 0;
			$ctx['author_report'][ '' !== $login ? $login : '#' . $source_user ] = $user ? $user->user_login : null;
		}
		$id = $ctx['resolved'][ $source_user ];
		return $id ? $id : ( $fallback ? $ctx['options']['default_author'] : 0 );
	}

	private static function import( $request ) {
		if ( strlen( $request->get_body() ) > self::MAX_BODY ) { return self::error( 'aism_body_too_large', 'Import bodies may not exceed 16 MiB; send fewer items.', 413 ); }
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) { return self::error( 'aism_invalid_import', 'Send JSON with source, kind and items.' ); }
		$source = self::source_param( $body['source'] ?? null );
		if ( is_wp_error( $source ) ) { return $source; }
		$kind = $body['kind'] ?? null;
		$items = $body['items'] ?? null;
		if ( ! in_array( $kind, array( 'terms', 'posts', 'comments', 'site' ), true ) || ! is_array( $items ) || count( $items ) > self::MAX_PER_PAGE ) { return self::error( 'aism_invalid_import', 'kind must be terms, posts, comments or site, with at most 100 items.' ); }
		$ctx = self::context( $body['authors'] ?? array(), $body['options'] ?? array() );
		return self::run_import( $source, $kind, $items, $ctx );
	}

	private static function context( $authors, $options ) {
		$list = array();
		foreach ( is_array( $authors ) ? $authors : array() as $id => $info ) { $list[ (int) $id ] = $info; }
		return array( 'authors' => $list, 'options' => self::options( $options ), 'resolved' => array(), 'author_report' => array(), 'results' => array(), 'errors' => array(), 'needs_file' => array(), 'skipped_meta' => array() );
	}

	private static function run_import( $source, $kind, $items, &$ctx, $upload = null ) {
		if ( ! defined( 'WP_IMPORTING' ) ) { define( 'WP_IMPORTING', true ); }
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		// Imports hold raw source IDs/URLs until finalize; never keep them as revisions.
		add_filter( 'wp_revisions_to_keep', '__return_zero', 999 );
		try {
			foreach ( $items as $item ) {
				$id = is_array( $item ) && isset( $item['id'] ) ? (int) $item['id'] : 0;
				if ( $id <= 0 ) { $ctx['errors'][] = array( 'source_id' => null, 'code' => 'aism_invalid_item', 'message' => 'Each item needs a positive id.' ); continue; }
				if ( 'terms' === $kind ) { $result = self::import_term( $source, $item, $ctx ); }
				elseif ( 'posts' === $kind ) { $result = self::import_post( $source, $item, $ctx, $upload ); }
				elseif ( 'comments' === $kind ) { $result = self::import_comment( $source, $item, $ctx ); }
				else { $result = self::import_site( $source, $item ); }
				if ( is_wp_error( $result ) ) { $ctx['errors'][] = array( 'source_id' => $id, 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ); }
				elseif ( $result ) { $ctx['results'][] = array_merge( array( 'source_id' => $id ), $result ); }
			}
		} finally {
			remove_filter( 'wp_revisions_to_keep', '__return_zero', 999 );
			wp_defer_term_counting( false );
			wp_defer_comment_counting( false );
		}
		return array( 'kind' => $kind, 'results' => $ctx['results'], 'errors' => $ctx['errors'], 'needs_file' => $ctx['needs_file'], 'authors' => (object) $ctx['author_report'], 'skipped_meta_keys' => array_keys( $ctx['skipped_meta'] ) );
	}

	private static function text( $item, $key, $default = '' ) { return isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) ? (string) $item[ $key ] : $default; }

	private static function date( $item, $key ) {
		$value = self::text( $item, $key );
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ? $value : null;
	}

	private static function import_term( $source, $item, &$ctx ) {
		$taxonomy = self::text( $item, 'taxonomy' );
		if ( ! taxonomy_exists( $taxonomy ) ) { return self::error( 'aism_taxonomy_missing', "Taxonomy $taxonomy is not registered on the target. Install and activate the plugin or theme that provides it." ); }
		$name = self::text( $item, 'name' );
		$slug = self::text( $item, 'slug' );
		$existing = self::lookup( $source, 'term', $item['id'] );
		$action = $existing ? 'updated' : 'created';
		if ( $existing && 'skip' === $ctx['options']['on_existing'] ) { return array( 'target_id' => $existing, 'action' => 'skipped' ); }
		if ( ! $existing && '' !== $slug ) {
			$match = get_term_by( 'slug', $slug, $taxonomy );
			if ( $match ) { $existing = $match->term_id; $action = 'matched'; }
		}
		$fields = array( 'description' => self::text( $item, 'description' ) );
		if ( '' !== $slug ) { $fields['slug'] = $slug; }
		if ( $existing ) {
			$result = wp_update_term( $existing, $taxonomy, wp_slash( array_merge( $fields, array( 'name' => $name ) ) ) );
		} else {
			$result = wp_insert_term( wp_slash( $name ), $taxonomy, wp_slash( $fields ) );
			if ( is_wp_error( $result ) && 'term_exists' === $result->get_error_code() && $result->get_error_data( 'term_exists' ) ) { $result = array( 'term_id' => (int) $result->get_error_data( 'term_exists' ) ); $action = 'matched'; }
		}
		if ( is_wp_error( $result ) ) { return $result; }
		$target = (int) $result['term_id'];
		if ( 'created' === $action ) { update_term_meta( $target, '_aism_source', $source . ':' . (int) $item['id'] ); }
		$keys = self::import_meta( 'term', $target, $item['meta'] ?? array(), $ctx['skipped_meta'] );
		self::remember( $source, 'term', (int) $item['id'], $target, 'created' === $action, array( 'parent' => (int) ( $item['parent'] ?? 0 ), 'taxonomy' => $taxonomy, 'meta_keys' => $keys ) );
		return array( 'target_id' => $target, 'action' => $action );
	}

	private static function match_post( $item, $type, &$ctx ) {
		$slug = self::text( $item, 'slug' );
		$template = in_array( $type, self::TEMPLATE_TYPES, true );
		if ( '' === $slug || 'attachment' === $type || ( ! $template && ! $ctx['options']['match_slugs'] ) ) { return 0; }
		$args = array( 'post_type' => $type, 'name' => $slug, 'post_status' => self::statuses( true ), 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true, 'orderby' => 'ID', 'order' => 'ASC' );
		if ( $template && ! empty( $item['theme'] ) && is_string( $item['theme'] ) ) { $args['tax_query'] = array( array( 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => $item['theme'] ) ); }
		$found = get_posts( $args );
		return $found ? (int) $found[0] : 0;
	}

	private static function post_fields( $source, $item, $type, &$ctx ) {
		$status = self::text( $item, 'status', 'draft' );
		if ( 'draft' === $ctx['options']['status'] && is_post_type_viewable( $type ) && ! in_array( $status, array( 'inherit', 'trash' ), true ) ) { $status = 'draft'; }
		$fields = array(
			'post_type' => $type, 'post_status' => $status, 'post_title' => self::text( $item, 'title' ), 'post_content' => self::text( $item, 'content' ),
			'post_excerpt' => self::text( $item, 'excerpt' ), 'post_name' => self::text( $item, 'slug' ), 'menu_order' => (int) ( $item['menu_order'] ?? 0 ),
			'comment_status' => 'open' === self::text( $item, 'comment_status' ) ? 'open' : 'closed', 'ping_status' => 'open' === self::text( $item, 'ping_status' ) ? 'open' : 'closed',
			'post_password' => self::text( $item, 'password' ), 'post_author' => self::author( $item['author'] ?? 0, $ctx ),
			'post_parent' => self::lookup( $source, 'post', $item['parent'] ?? 0 ),
		);
		if ( 'attachment' !== $type && '' !== self::text( $item, 'mime_type' ) ) { $fields['post_mime_type'] = self::text( $item, 'mime_type' ); }
		foreach ( array( 'post_date' => 'date', 'post_date_gmt' => 'date_gmt' ) as $field => $key ) {
			$date = self::date( $item, $key );
			if ( $date ) { $fields[ $field ] = $date; }
		}
		return $fields;
	}

	private static function after_post_write( $source, $target, $item, $type, $action, &$ctx, $extra_refs = array() ) {
		global $wpdb;
		$modified = self::date( $item, 'modified' );
		$modified_gmt = self::date( $item, 'modified_gmt' );
		if ( $modified && $modified_gmt ) { $wpdb->update( $wpdb->posts, array( 'post_modified' => $modified, 'post_modified_gmt' => $modified_gmt ), array( 'ID' => $target ) ); }
		$keys = self::import_meta( 'post', $target, $item['meta'] ?? array(), $ctx['skipped_meta'] );
		$pending_terms = array();
		foreach ( isset( $item['terms'] ) && is_array( $item['terms'] ) ? $item['terms'] : array() as $taxonomy => $ids ) {
			if ( ! is_array( $ids ) || ! is_object_in_taxonomy( $type, $taxonomy ) ) { continue; }
			$mapped = array();
			foreach ( $ids as $term ) {
				$found = self::lookup( $source, 'term', $term );
				if ( $found ) { $mapped[] = $found; } else { $pending_terms[ $taxonomy ][] = (int) $term; }
			}
			wp_set_object_terms( $target, $mapped, $taxonomy );
		}
		if ( 'post' === $type ) { ! empty( $item['sticky'] ) ? stick_post( $target ) : unstick_post( $target ); }
		clean_post_cache( $target );
		$refs = array_merge( array( 'parent' => (int) ( $item['parent'] ?? 0 ), 'meta_keys' => $keys ), $extra_refs );
		if ( $pending_terms ) { $refs['terms'] = $pending_terms; }
		self::remember( $source, 'post', (int) $item['id'], $target, 'created' === $action, $refs );
		return array( 'target_id' => $target, 'action' => $action );
	}

	private static function import_post( $source, $item, &$ctx, $upload = null ) {
		$type = self::text( $item, 'type' );
		if ( ! post_type_exists( $type ) || in_array( $type, self::EXCLUDED_TYPES, true ) ) { return self::error( 'aism_post_type_missing', "Post type $type is not available on the target. Install and activate the plugin or theme that provides it." ); }
		$existing = self::lookup( $source, 'post', $item['id'] );
		$action = $existing ? 'updated' : 'created';
		if ( $existing && 'skip' === $ctx['options']['on_existing'] ) { return array( 'target_id' => $existing, 'action' => 'skipped' ); }
		if ( ! $existing ) {
			$existing = self::match_post( $item, $type, $ctx );
			if ( $existing ) { $action = 'matched'; }
		}
		if ( 'attachment' === $type && ! $existing ) {
			if ( ! $upload ) { $ctx['needs_file'][] = (int) $item['id']; return null; }
			return self::create_attachment( $source, $item, $ctx, $upload );
		}
		$fields = self::post_fields( $source, $item, $type, $ctx );
		if ( 'attachment' === $type ) { unset( $fields['post_name'] ); $fields['post_status'] = 'inherit'; }
		if ( $existing ) {
			$fields['ID'] = $existing;
			$result = wp_update_post( wp_slash( $fields ), true );
		} else {
			$fields['meta_input'] = array( '_aism_source' => $source . ':' . (int) $item['id'] );
			$result = wp_insert_post( wp_slash( $fields ), true );
		}
		if ( is_wp_error( $result ) ) { return $result; }
		return self::after_post_write( $source, (int) $result, $item, $type, $action, $ctx );
	}

	private static function import_file( $request ) {
		if ( ! current_user_can( 'upload_files' ) ) { return self::error( 'aism_upload_forbidden', 'The key owner cannot upload files.', 403 ); }
		$params = $request->get_body_params();
		$source = self::source_param( $params['source'] ?? null );
		if ( is_wp_error( $source ) ) { return $source; }
		$item = json_decode( is_string( $params['item'] ?? null ) ? $params['item'] : '', true );
		if ( ! is_array( $item ) || 'attachment' !== ( $item['type'] ?? null ) || empty( $item['id'] ) ) { return self::error( 'aism_invalid_import', 'Send multipart fields source, item (an attachment export item as JSON) and file.' ); }
		$files = $request->get_file_params();
		$file = $files['file'] ?? null;
		if ( ! is_array( $file ) || ! isset( $file['error'], $file['tmp_name'], $file['size'] ) || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) { return self::error( 'aism_invalid_upload', 'Upload the original file using multipart field file.' ); }
		if ( $file['size'] > wp_max_upload_size() ) { return self::error( 'aism_upload_too_large', 'The file exceeds the WordPress upload limit on the target.', 413 ); }
		$ctx = self::context( json_decode( is_string( $params['authors'] ?? null ) ? $params['authors'] : '{}', true ), json_decode( is_string( $params['options'] ?? null ) ? $params['options'] : '{}', true ) );
		return self::run_import( $source, 'posts', array( $item ), $ctx, $file );
	}

	private static function create_attachment( $source, $item, &$ctx, $file ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$relative = isset( $item['attachment']['file'] ) && is_string( $item['attachment']['file'] ) ? $item['attachment']['file'] : '';
		$subdir = trim( dirname( $relative ), './' );
		if ( ! preg_match( '#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#D', $subdir ) ) { $subdir = ''; }
		$name = sanitize_file_name( wp_basename( $relative ) );
		if ( '' === $name ) { $name = sanitize_file_name( (string) $file['name'] ); }
		// Keep the source's year/month folder so URLs keep the same relative path.
		$dirs = function ( $uploads ) use ( $subdir ) {
			$uploads['subdir'] = '' === $subdir ? '' : '/' . $subdir;
			$uploads['path'] = $uploads['basedir'] . $uploads['subdir'];
			$uploads['url'] = $uploads['baseurl'] . $uploads['subdir'];
			return $uploads;
		};
		$adopted = self::reusable_file( $subdir, $name, $file['tmp_name'] );
		if ( $adopted ) {
			$upload = $adopted;
		} else {
			add_filter( 'upload_dir', $dirs );
			try { $upload = wp_handle_upload( array_merge( $file, array( 'name' => $name ) ), array( 'test_form' => false ) ); }
			finally { remove_filter( 'upload_dir', $dirs ); }
			if ( isset( $upload['error'] ) ) { return self::error( 'aism_upload_rejected', $upload['error'] ); }
		}
		$fields = self::post_fields( $source, $item, 'attachment', $ctx );
		unset( $fields['post_name'] );
		$fields = array_merge( $fields, array( 'post_status' => 'inherit', 'post_mime_type' => $upload['type'], 'guid' => $upload['url'], 'meta_input' => array( '_aism_source' => $source . ':' . (int) $item['id'] ) ) );
		$id = wp_insert_attachment( wp_slash( $fields ), $upload['file'], $fields['post_parent'], true );
		if ( is_wp_error( $id ) ) { if ( ! $adopted ) { wp_delete_file( $upload['file'] ); } return $id; }
		// Rollback keeps an adopted file: it was on the target before the migration.
		if ( $adopted ) { update_post_meta( $id, '_aism_adopted_file', $adopted['relative'] ); }
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		$urls = self::url_pairs( $source, isset( $item['attachment']['urls'] ) && is_array( $item['attachment']['urls'] ) ? $item['attachment']['urls'] : array(), self::attachment_urls( $id ) );
		return self::after_post_write( $source, $id, $item, 'attachment', 'created', $ctx, array( 'urls' => $urls ) );
	}

	/**
	 * An identical file already at the source's path (for example uploads copied to the server before migrating) is
	 * reused instead of saved again under a "-1" name, so file URLs stay unchanged. Files owned by another attachment,
	 * different bytes or a disallowed type fall back to a normal upload.
	 */
	private static function reusable_file( $subdir, $name, $tmp ) {
		global $wpdb;
		$uploads = wp_get_upload_dir();
		$relative = ltrim( ( '' === $subdir ? '' : $subdir . '/' ) . $name, '/' );
		$path = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . $relative );
		$real = realpath( $path );
		$base = realpath( $uploads['basedir'] );
		if ( '' === $name || ! $real || ! $base || 0 !== strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $base ) ) ) || is_link( $path ) || ! is_file( $real ) ) { return null; }
		if ( ! is_readable( $tmp ) || filesize( $real ) !== filesize( $tmp ) || ! hash_equals( hash_file( 'sha256', $real ), hash_file( 'sha256', $tmp ) ) ) { return null; }
		$check = wp_check_filetype_and_ext( $real, $name );
		if ( empty( $check['type'] ) ) { return null; }
		$owner = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $relative ) );
		if ( $owner ) { return null; }
		return array( 'file' => $path, 'url' => trailingslashit( $uploads['baseurl'] ) . $relative, 'type' => $check['type'], 'relative' => $relative );
	}

	/** Explicit source→target file URL pairs where the relative upload path changed. */
	private static function url_pairs( $source, $source_urls, $target_urls ) {
		$record = self::sources()[ $source ];
		$source_base = trailingslashit( $record['uploads_baseurl'] );
		$target_base = trailingslashit( wp_get_upload_dir()['baseurl'] );
		$target_urls = (array) $target_urls;
		$pairs = array();
		foreach ( $source_urls as $size => $url ) {
			if ( ! is_string( $url ) || '' === $url || empty( $target_urls ) ) { continue; }
			$target = $target_urls[ $size ] ?? $target_urls['full'];
			$from = 0 === strpos( $url, $source_base ) ? substr( $url, strlen( $source_base ) ) : $url;
			$to = 0 === strpos( $target, $target_base ) ? substr( $target, strlen( $target_base ) ) : $target;
			if ( $from !== $to ) { $pairs[ $url ] = $target; }
		}
		return $pairs;
	}

	private static function import_comment( $source, $item, &$ctx ) {
		$post = self::lookup( $source, 'post', $item['post'] ?? 0 );
		if ( ! $post ) { return self::error( 'aism_comment_post_missing', 'The comment\'s post has not been migrated.' ); }
		$existing = self::lookup( $source, 'comment', $item['id'] );
		if ( $existing && 'skip' === $ctx['options']['on_existing'] ) { return array( 'target_id' => $existing, 'action' => 'skipped' ); }
		$approved = self::text( $item, 'approved', '0' );
		$data = array(
			'comment_post_ID' => $post, 'comment_author' => self::text( $item, 'author' ), 'comment_author_email' => self::text( $item, 'author_email' ), 'comment_author_url' => self::text( $item, 'author_url' ),
			'comment_author_IP' => self::text( $item, 'author_ip' ), 'comment_content' => self::text( $item, 'content' ), 'comment_karma' => (int) ( $item['karma'] ?? 0 ),
			'comment_approved' => in_array( $approved, array( '0', '1', 'spam', 'trash', 'post-trashed' ), true ) ? $approved : '0', 'comment_agent' => self::text( $item, 'agent' ),
			'comment_type' => self::text( $item, 'type', 'comment' ), 'user_id' => self::author( $item['user'] ?? 0, $ctx, false ),
		);
		foreach ( array( 'comment_date' => 'date', 'comment_date_gmt' => 'date_gmt' ) as $field => $key ) {
			$date = self::date( $item, $key );
			if ( $date ) { $data[ $field ] = $date; }
		}
		$data = wp_slash( $data );
		if ( $existing ) {
			$data['comment_ID'] = $existing;
			$result = wp_update_comment( $data, true );
			if ( is_wp_error( $result ) ) { return $result; }
			$target = $existing;
			$action = 'updated';
		} else {
			// Apply the target's comment filters, including kses for users without unfiltered_html. Updates filter internally.
			$target = wp_insert_comment( wp_filter_comment( $data ) );
			if ( ! $target ) { return self::error( 'aism_comment_failed', 'WordPress could not save the comment.', 500 ); }
			update_comment_meta( $target, '_aism_source', $source . ':' . (int) $item['id'] );
			$action = 'created';
		}
		$keys = self::import_meta( 'comment', $target, $item['meta'] ?? array(), $ctx['skipped_meta'] );
		self::remember( $source, 'comment', (int) $item['id'], $target, 'created' === $action, array( 'parent' => (int) ( $item['parent'] ?? 0 ), 'meta_keys' => $keys ) );
		return array( 'target_id' => (int) $target, 'action' => $action );
	}

	private static function import_site( $source, $item ) {
		if ( ! current_user_can( 'unfiltered_html' ) ) { return self::error( 'aism_site_import_forbidden', 'Importing site settings requires the unfiltered_html capability.', 403 ); }
		$options = isset( $item['options'] ) && is_array( $item['options'] ) ? $item['options'] : array();
		$allowed = array_flip( self::PORTABLE_OPTIONS );
		$row = self::row( $source, 'options', 0 );
		$refs = $row ? json_decode( (string) $row['refs'], true ) : array();
		$previous = is_array( $refs ) && isset( $refs['previous'] ) ? $refs['previous'] : array();
		$names = array();
		$skipped = array();
		foreach ( $options as $name => $value ) {
			$name = (string) $name;
			$portable = isset( $allowed[ $name ] ) || preg_match( '/^(widget_[a-z0-9_-]+|theme_mods_[a-zA-Z0-9_.-]+)$/D', $name );
			if ( ! $portable || ! AISM_Store::option_allowed( $name ) || null === $value ) { $skipped[] = $name; continue; }
			// Keep the value from before the first import so rollback can restore it.
			if ( ! array_key_exists( $name, $previous ) ) { $previous[ $name ] = get_option( $name, null ); }
			update_option( $name, $value );
			$names[] = $name;
		}
		self::remember( $source, 'options', 0, 0, false, array( 'names' => array_values( array_unique( array_merge( $names, is_array( $refs ) && isset( $refs['names'] ) ? $refs['names'] : array() ) ) ), 'previous' => $previous, 'stylesheet' => self::text( $item, 'stylesheet' ) ) );
		return array( 'target_id' => 0, 'action' => 'updated', 'options' => $names, 'skipped_options' => $skipped );
	}

	/* ---------- Target: finalize (ID remapping and URL rewriting) ---------- */

	/** Visit object IDs referenced in block markup, image classes and media shortcodes. $map returns a replacement or null. */
	public static function content_refs( $content, $map ) {
		if ( ! is_string( $content ) || '' === $content ) { return $content; }
		if ( false !== strpos( $content, '<!--' ) ) {
			$content = preg_replace_callback( self::BLOCK_REGEX, function ( $m ) use ( $map ) {
				if ( empty( $m['attrs'] ) || ! empty( $m['closer'] ) ) { return $m[0]; }
				$json = trim( $m['attrs'] );
				$attrs = json_decode( $json, true );
				if ( ! is_array( $attrs ) ) { return $m[0]; }
				$name = ( $m['namespace'] ? $m['namespace'] : 'core/' ) . $m['name'];
				$changed = self::block_refs( $name, $attrs, $map );
				return $changed ? str_replace( $json, serialize_block_attributes( $attrs ), $m[0] ) : $m[0];
			}, $content );
		}
		$one = function ( $id, $kind = 'post' ) use ( $map ) { $new = $map( $kind, (int) $id ); return $new ? (string) $new : (string) $id; };
		$content = preg_replace_callback( '/\bwp-image-(\d+)\b/', function ( $m ) use ( $one ) { return 'wp-image-' . $one( $m[1] ); }, $content );
		if ( false !== strpos( $content, '[' ) ) { $content = self::shortcode_refs( $content, $one ); }
		return preg_replace_callback( '/(\[caption\b[^\]]*?\bid=["\']?attachment_)(\d+)/', function ( $m ) use ( $one ) { return $m[1] . $one( $m[2] ); }, $content );
	}

	/**
	 * Remap numeric post/attachment IDs in shortcode attributes: core [gallery]/[playlist] ids, page-builder images
	 * (for example Flatsome [ux_image id], [ux_banner bg], [featured_box img], [ux_gallery ids]) and form or block
	 * embeds such as [contact-form-7 id]. Each attribute is mapped once; unmapped IDs are left unchanged.
	 */
	private static function shortcode_refs( $content, $one ) {
		$attrs = (array) apply_filters( 'aism_migration_shortcode_id_attributes', self::SHORTCODE_ID_ATTRS );
		$id_tags = (array) apply_filters( 'aism_migration_shortcode_id_tags', self::SHORTCODE_ID_TAGS );
		return preg_replace_callback( '/\[(?!\/)([A-Za-z][A-Za-z0-9_-]*)(\s[^\[\]]*)?\]/', function ( $tag ) use ( $one, $attrs, $id_tags ) {
			if ( empty( $tag[2] ) ) { return $tag[0]; }
			$name = strtolower( $tag[1] );
			$fields = in_array( $name, $id_tags, true ) ? array_merge( $attrs, array( 'id' ) ) : $attrs;
			$body = preg_replace_callback( '/(\s)([A-Za-z0-9_-]+)(\s*=\s*)(?:"(\d[\d,\s]*)"|\'(\d[\d,\s]*)\'|(\d+(?:,\d+)*)(?=[\s\/]|$))/', function ( $m ) use ( $one, $fields ) {
				if ( ! in_array( strtolower( $m[2] ), $fields, true ) ) { return $m[0]; }
				$quote = isset( $m[4] ) && '' !== $m[4] ? '"' : ( isset( $m[5] ) && '' !== $m[5] ? "'" : '' );
				$value = '"' === $quote ? $m[4] : ( "'" === $quote ? $m[5] : $m[6] );
				$ids = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
				$mapped = array_map( $one, $ids );
				// Keep the original spelling (spaces, quotes) when nothing was remapped.
				return $mapped === $ids ? $m[0] : $m[1] . $m[2] . $m[3] . $quote . implode( ',', $mapped ) . $quote;
			}, $tag[2] );
			return '[' . $tag[1] . $body . ']';
		}, $content );
	}

	private static function block_refs( $name, &$attrs, $map ) {
		$changed = false;
		$one = function ( &$value, $kind ) use ( $map, &$changed ) {
			if ( ! ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) || (int) $value <= 0 ) { return; }
			$new = $map( $kind, (int) $value );
			if ( $new && (int) $new !== (int) $value ) { $value = is_string( $value ) ? (string) $new : (int) $new; $changed = true; }
		};
		$many = function ( &$list, $kind ) use ( $one ) { if ( is_array( $list ) ) { foreach ( $list as &$item ) { $one( $item, $kind ); } } };
		switch ( $name ) {
			case 'core/image': case 'core/cover': case 'core/video': case 'core/audio': case 'core/file':
				if ( isset( $attrs['id'] ) ) { $one( $attrs['id'], 'post' ); }
				break;
			case 'core/media-text':
				if ( isset( $attrs['mediaId'] ) ) { $one( $attrs['mediaId'], 'post' ); }
				break;
			case 'core/gallery':
				if ( isset( $attrs['ids'] ) ) { $many( $attrs['ids'], 'post' ); }
				break;
			case 'core/block': case 'core/navigation':
				if ( isset( $attrs['ref'] ) ) { $one( $attrs['ref'], 'post' ); }
				break;
			case 'core/navigation-link': case 'core/navigation-submenu':
				if ( isset( $attrs['id'], $attrs['kind'] ) && in_array( $attrs['kind'], array( 'post-type', 'taxonomy' ), true ) ) { $one( $attrs['id'], 'taxonomy' === $attrs['kind'] ? 'term' : 'post' ); }
				break;
			case 'core/page-list':
				if ( isset( $attrs['parentPageID'] ) ) { $one( $attrs['parentPageID'], 'post' ); }
				break;
			case 'core/latest-posts':
				if ( isset( $attrs['categories'] ) && is_array( $attrs['categories'] ) ) { foreach ( $attrs['categories'] as &$category ) { if ( is_array( $category ) && isset( $category['id'] ) ) { $one( $category['id'], 'term' ); } } unset( $category ); }
				break;
			case 'core/query':
				if ( isset( $attrs['query'] ) && is_array( $attrs['query'] ) ) {
					foreach ( array( 'parents', 'exclude', 'include' ) as $field ) { if ( isset( $attrs['query'][ $field ] ) ) { $many( $attrs['query'][ $field ], 'post' ); } }
					foreach ( array( 'categoryIds', 'tagIds' ) as $field ) { if ( isset( $attrs['query'][ $field ] ) ) { $many( $attrs['query'][ $field ], 'term' ); } }
					if ( isset( $attrs['query']['taxQuery'] ) && is_array( $attrs['query']['taxQuery'] ) ) { foreach ( $attrs['query']['taxQuery'] as &$terms ) { $many( $terms, 'term' ); } unset( $terms ); }
				}
				break;
		}
		return $changed;
	}

	/** One-pass URL rewriter: explicit file renames first, then uploads, WordPress and home URLs. */
	private static function replacer( $source ) {
		global $wpdb;
		if ( isset( self::$replacers[ $source ] ) ) { return self::$replacers[ $source ]; }
		$record = self::sources()[ $source ];
		$pairs = array();
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT refs FROM ' . AISM_Store::table( 'migration_map' ) . " WHERE source = %s AND object_type = 'post' AND refs LIKE %s", $source, '%"urls":{%' ) );
		foreach ( $rows as $refs ) {
			$refs = json_decode( $refs, true );
			foreach ( isset( $refs['urls'] ) && is_array( $refs['urls'] ) ? $refs['urls'] : array() as $from => $to ) { if ( is_string( $to ) ) { $pairs[ $from ] = $to; } }
		}
		$target = array( 'uploads_baseurl' => wp_get_upload_dir()['baseurl'], 'siteurl' => site_url(), 'home' => home_url() );
		foreach ( $target as $field => $url ) { if ( ! empty( $record[ $field ] ) ) { $pairs[ untrailingslashit( $record[ $field ] ) ] = untrailingslashit( $url ); } }
		$all = array();
		foreach ( $pairs as $from => $to ) {
			$from_rel = preg_replace( '#^https?:#i', '', $from );
			$to_rel = preg_replace( '#^https?:#i', '', $to );
			foreach ( array( 'https:' . $from_rel => $to, 'http:' . $from_rel => $to, $from_rel => $to_rel ) as $f => $t ) {
				if ( $f === $t || '' === $f ) { continue; }
				$all[ $f ] = $t;
				$all[ str_replace( '/', '\\/', $f ) ] = str_replace( '/', '\\/', $t );
			}
		}
		uksort( $all, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		$patterns = array();
		foreach ( array_chunk( array_keys( $all ), 200 ) as $chunk ) {
			$patterns[] = '#(?:' . implode( '|', array_map( function ( $k ) { return preg_quote( $k, '#' ); }, $chunk ) ) . ')(?![A-Za-z0-9_:-]|\\.[A-Za-z0-9])#';
		}
		return self::$replacers[ $source ] = function ( $text ) use ( $patterns, $all ) {
			if ( ! is_string( $text ) || '' === $text || ! $patterns ) { return $text; }
			// Placeholders keep replaced URLs from matching later chunks.
			$tokens = array();
			foreach ( $patterns as $pattern ) {
				$text = preg_replace_callback( $pattern, function ( $m ) use ( $all, &$tokens ) { $token = "\x00AISM" . count( $tokens ) . "\x00"; $tokens[ $token ] = $all[ $m[0] ]; return $token; }, $text );
			}
			return $tokens ? strtr( $text, $tokens ) : $text;
		};
	}

	private static function deep( $value, $fn ) {
		if ( is_array( $value ) ) { foreach ( $value as $k => $v ) { $value[ $k ] = self::deep( $v, $fn ); } return $value; }
		return is_string( $value ) ? $fn( $value ) : $value;
	}

	private static function finalize( $request ) {
		global $wpdb;
		$body = $request->get_json_params();
		$source = self::source_param( is_array( $body ) ? ( $body['source'] ?? null ) : null );
		if ( is_wp_error( $source ) ) { return $source; }
		$limit = max( 1, min( 500, (int) ( $body['limit'] ?? 100 ) ) );
		$table = AISM_Store::table( 'migration_map' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE source = %s AND pending = 1 ORDER BY id ASC LIMIT %d", $source, $limit ), ARRAY_A );
		$unresolved = array();
		$errors = array();
		$replace = self::replacer( $source );
		foreach ( $rows as $row ) {
			$missing = array();
			$map = function ( $kind, $id ) use ( $source, &$missing ) {
				// Empty references (for example an empty _thumbnail_id) are not unresolved objects.
				if ( (int) $id <= 0 ) { return null; }
				$target = self::lookup( $source, 'term' === $kind ? 'term' : 'post', $id );
				if ( ! $target ) { $missing[] = $kind . ':' . $id; }
				return $target ? $target : null;
			};
			$refs = json_decode( (string) $row['refs'], true );
			$refs = is_array( $refs ) ? $refs : array();
			try {
				if ( 'post' === $row['object_type'] ) { self::finalize_post( (int) $row['target_id'], $refs, $map, $replace ); }
				elseif ( 'term' === $row['object_type'] ) { self::finalize_term( (int) $row['target_id'], $refs, $map, $replace ); }
				elseif ( 'comment' === $row['object_type'] ) { self::finalize_comment( $source, (int) $row['target_id'], $refs, $replace ); }
				elseif ( 'options' === $row['object_type'] ) { self::finalize_options( $refs, $map, $replace ); }
			} catch ( Throwable $e ) {
				$errors[] = array( 'object_type' => $row['object_type'], 'source_id' => (int) $row['source_id'], 'message' => $e->getMessage() );
			}
			$wpdb->update( $table, array( 'pending' => 0 ), array( 'id' => $row['id'] ) );
			if ( $missing ) { $unresolved[] = array( 'object_type' => $row['object_type'], 'source_id' => (int) $row['source_id'], 'target_id' => (int) $row['target_id'], 'missing' => array_values( array_unique( $missing ) ) ); }
		}
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE source = %s AND pending = 1", $source ) );
		if ( 0 === $remaining && $rows ) {
			global $wp_rewrite;
			$wp_rewrite->set_permalink_structure( get_option( 'permalink_structure' ) );
			flush_rewrite_rules( false );
			$sources = self::sources();
			$sources[ $source ]['finalized_at'] = gmdate( 'c' );
			update_option( 'aism_migration_sources', $sources, false );
		}
		return array( 'processed' => count( $rows ), 'remaining' => $remaining, 'unresolved' => array_slice( $unresolved, 0, 50 ), 'unresolved_count' => count( $unresolved ), 'errors' => $errors );
	}

	private static function finalize_post( $id, $refs, $map, $replace ) {
		global $wpdb;
		$post = get_post( $id );
		if ( ! $post ) { return; }
		$parent = ! empty( $refs['parent'] ) ? (int) $map( 'post', $refs['parent'] ) : 0;
		$wpdb->update( $wpdb->posts, array(
			'post_content' => $replace( self::content_refs( $post->post_content, $map ) ), 'post_excerpt' => $replace( $post->post_excerpt ), 'post_parent' => $parent,
		), array( 'ID' => $id ) );
		foreach ( isset( $refs['terms'] ) && is_array( $refs['terms'] ) ? $refs['terms'] : array() as $taxonomy => $ids ) {
			$found = array_filter( array_map( function ( $term ) use ( $map ) { return (int) $map( 'term', $term ); }, (array) $ids ) );
			if ( $found && taxonomy_exists( $taxonomy ) ) { wp_set_object_terms( $id, array_values( $found ), $taxonomy, true ); }
		}
		$keys = isset( $refs['meta_keys'] ) && is_array( $refs['meta_keys'] ) ? $refs['meta_keys'] : array();
		$item_type = get_post_meta( $id, '_menu_item_type', true );
		foreach ( $keys ? $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')', array_merge( array( $id ), $keys ) ), ARRAY_A ) : array() as $meta ) {
			$value = maybe_unserialize( $meta['meta_value'] );
			if ( '_thumbnail_id' === $meta['meta_key'] ) {
				$new = (int) $map( 'post', $value );
				if ( ! $new ) { delete_metadata_by_mid( 'post', $meta['meta_id'] ); continue; }
			} elseif ( '_menu_item_menu_item_parent' === $meta['meta_key'] ) {
				$new = (int) $value ? (string) (int) $map( 'post', $value ) : '0';
			} elseif ( '_menu_item_object_id' === $meta['meta_key'] && in_array( $item_type, array( 'post_type', 'taxonomy' ), true ) ) {
				$new = $map( 'taxonomy' === $item_type ? 'term' : 'post', $value );
				$new = $new ? (string) $new : $value;
			} else {
				$new = self::deep( $value, $replace );
			}
			// update_metadata_by_mid() does not unslash, unlike update_metadata().
			if ( $new !== $value ) { update_metadata_by_mid( 'post', $meta['meta_id'], $new ); }
		}
		clean_post_cache( $id );
	}

	private static function finalize_term( $id, $refs, $map, $replace ) {
		$term = get_term( $id );
		if ( ! $term || is_wp_error( $term ) ) { return; }
		$fields = array( 'description' => wp_slash( $replace( $term->description ) ) );
		if ( is_taxonomy_hierarchical( $term->taxonomy ) ) { $fields['parent'] = ! empty( $refs['parent'] ) ? (int) $map( 'term', $refs['parent'] ) : 0; }
		wp_update_term( $id, $term->taxonomy, $fields );
		foreach ( isset( $refs['meta_keys'] ) && is_array( $refs['meta_keys'] ) ? $refs['meta_keys'] : array() as $key ) {
			foreach ( get_term_meta( $id, $key ) as $value ) {
				$new = self::deep( $value, $replace );
				if ( $new !== $value ) { update_term_meta( $id, wp_slash( $key ), wp_slash( $new ), wp_slash( $value ) ); }
			}
		}
	}

	private static function finalize_comment( $source, $id, $refs, $replace ) {
		global $wpdb;
		$comment = get_comment( $id );
		if ( ! $comment ) { return; }
		$parent = ! empty( $refs['parent'] ) ? self::lookup( $source, 'comment', $refs['parent'] ) : 0;
		$wpdb->update( $wpdb->comments, array( 'comment_parent' => $parent, 'comment_content' => $replace( $comment->comment_content ), 'comment_author_url' => $replace( $comment->comment_author_url ) ), array( 'comment_ID' => $id ) );
		clean_comment_cache( $id );
	}

	private static function finalize_options( $refs, $map, $replace ) {
		$post = function ( $value ) use ( $map ) { return (int) $value > 0 ? (int) $map( 'post', $value ) : 0; };
		$term = function ( $value ) use ( $map ) { return (int) $value > 0 ? (int) $map( 'term', $value ) : 0; };
		foreach ( isset( $refs['names'] ) && is_array( $refs['names'] ) ? $refs['names'] : array() as $name ) {
			$value = get_option( $name, null );
			if ( null === $value ) { continue; }
			if ( in_array( $name, array( 'page_on_front', 'page_for_posts', 'site_icon', 'wp_page_for_privacy_policy' ), true ) ) { $value = $post( $value ); }
			elseif ( 'default_category' === $name ) { $value = $term( $value ); }
			elseif ( 'nav_menu_options' === $name && is_array( $value ) && isset( $value['auto_add'] ) && is_array( $value['auto_add'] ) ) { $value['auto_add'] = array_values( array_filter( array_map( $term, $value['auto_add'] ) ) ); }
			elseif ( 0 === strpos( $name, 'theme_mods_' ) && is_array( $value ) ) {
				foreach ( array( 'custom_logo', 'custom_css_post_id' ) as $field ) { if ( isset( $value[ $field ] ) ) { $value[ $field ] = $post( $value[ $field ] ); } }
				if ( isset( $value['nav_menu_locations'] ) && is_array( $value['nav_menu_locations'] ) ) { $value['nav_menu_locations'] = array_map( $term, $value['nav_menu_locations'] ); }
				if ( isset( $value['header_image_data'] ) && is_array( $value['header_image_data'] ) && isset( $value['header_image_data']['attachment_id'] ) ) { $value['header_image_data']['attachment_id'] = $post( $value['header_image_data']['attachment_id'] ); }
				$value = self::theme_mod_attachments( $value, $map );
			} elseif ( 0 === strpos( $name, 'widget_' ) && is_array( $value ) ) {
				foreach ( $value as $k => $instance ) {
					if ( ! is_array( $instance ) ) { continue; }
					if ( isset( $instance['nav_menu'] ) ) { $instance['nav_menu'] = $term( $instance['nav_menu'] ); }
					if ( isset( $instance['attachment_id'] ) ) { $instance['attachment_id'] = $post( $instance['attachment_id'] ); }
					if ( isset( $instance['ids'] ) ) { $ids = is_array( $instance['ids'] ) ? $instance['ids'] : explode( ',', (string) $instance['ids'] ); $mapped = array_values( array_filter( array_map( $post, $ids ) ) ); $instance['ids'] = is_array( $instance['ids'] ) ? $mapped : implode( ',', $mapped ); }
					if ( isset( $instance['content'] ) && is_string( $instance['content'] ) ) { $instance['content'] = self::content_refs( $instance['content'], $map ); }
					$value[ $k ] = $instance;
				}
			}
			update_option( $name, self::deep( $value, $replace ) );
		}
	}

	/**
	 * Remap theme-specific image settings that store an attachment ID (Flatsome site_logo, site_logo_dark and similar).
	 * Only numeric values under image-like names are touched; an ID that maps to a non-attachment is left alone, and an
	 * unmigrated image is cleared rather than left pointing at an unrelated target attachment.
	 */
	private static function theme_mod_attachments( $mods, $map ) {
		foreach ( $mods as $field => $mod ) {
			if ( ! is_string( $field ) || in_array( $field, array( 'custom_logo', 'custom_css_post_id' ), true ) || ! preg_match( self::THEME_MOD_ATTACHMENT_KEY, $field ) ) { continue; }
			if ( ! ( is_int( $mod ) || ( is_string( $mod ) && ctype_digit( $mod ) ) ) || (int) $mod <= 0 ) { continue; }
			$new = (int) $map( 'post', (int) $mod );
			if ( $new && 'attachment' !== get_post_type( $new ) ) { continue; }
			$mods[ $field ] = is_int( $mod ) ? $new : ( $new ? (string) $new : '' );
		}
		return $mods;
	}

	/* ---------- Target: rollback ---------- */

	/** Delete a migrated attachment and its generated sizes, keeping an original file that existed before the import. */
	private static function delete_attachment( $id ) {
		$adopted = (string) get_post_meta( $id, '_aism_adopted_file', true );
		$keep = '' === $adopted ? false : realpath( trailingslashit( wp_get_upload_dir()['basedir'] ) . $adopted );
		$guard = function ( $file ) use ( $keep ) { return $keep && realpath( $file ) === $keep ? '' : $file; };
		if ( $keep ) { add_filter( 'wp_delete_file', $guard ); }
		try { return wp_delete_attachment( $id, true ); }
		finally { if ( $keep ) { remove_filter( 'wp_delete_file', $guard ); } }
	}

	private static function rollback( $request ) {
		global $wpdb;
		$body = $request->get_json_params();
		$source = self::source_param( is_array( $body ) ? ( $body['source'] ?? null ) : null );
		if ( is_wp_error( $source ) ) { return $source; }
		$limit = max( 1, min( 200, (int) ( $body['limit'] ?? 50 ) ) );
		$table = AISM_Store::table( 'migration_map' );
		// Newest first: comments and menu items go before the posts and terms they reference.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE source = %s ORDER BY id DESC LIMIT %d", $source, $limit ), ARRAY_A );
		$deleted = 0;
		$restored = array();
		$errors = array();
		foreach ( $rows as $row ) {
			$target = (int) $row['target_id'];
			$marker = $source . ':' . (int) $row['source_id'];
			if ( 'options' === $row['object_type'] ) {
				$refs = json_decode( (string) $row['refs'], true );
				foreach ( is_array( $refs ) && isset( $refs['previous'] ) && is_array( $refs['previous'] ) ? $refs['previous'] : array() as $name => $value ) {
					if ( ! AISM_Store::option_allowed( $name ) ) { continue; }
					null === $value ? delete_option( $name ) : update_option( $name, $value );
					$restored[] = $name;
				}
			} elseif ( $row['created'] ) {
				// Delete only objects this migration created, confirmed by their source marker.
				if ( 'post' === $row['object_type'] && get_post_meta( $target, '_aism_source', true ) === $marker ) {
					$ok = 'attachment' === get_post_type( $target ) ? self::delete_attachment( $target ) : wp_delete_post( $target, true );
					if ( $ok ) { ++$deleted; } else { $errors[] = array( 'object_type' => 'post', 'target_id' => $target ); }
				} elseif ( 'term' === $row['object_type'] && get_term_meta( $target, '_aism_source', true ) === $marker ) {
					$term = get_term( $target );
					$ok = $term && ! is_wp_error( $term ) ? wp_delete_term( $target, $term->taxonomy ) : false;
					if ( true === $ok ) { ++$deleted; } else { $errors[] = array( 'object_type' => 'term', 'target_id' => $target ); }
				} elseif ( 'comment' === $row['object_type'] && get_comment_meta( $target, '_aism_source', true ) === $marker ) {
					if ( wp_delete_comment( $target, true ) ) { ++$deleted; } else { $errors[] = array( 'object_type' => 'comment', 'target_id' => $target ); }
				}
			}
			$wpdb->delete( $table, array( 'id' => $row['id'] ) );
		}
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE source = %s", $source ) );
		if ( 0 === $remaining ) {
			$sources = self::sources();
			unset( $sources[ $source ] );
			update_option( 'aism_migration_sources', $sources, false );
		}
		self::$map_cache = array();
		return array( 'processed' => count( $rows ), 'deleted' => $deleted, 'restored_options' => $restored, 'remaining' => $remaining, 'errors' => $errors );
	}
}
