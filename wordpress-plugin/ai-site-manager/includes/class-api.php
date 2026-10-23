<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AISM_API {
	const NS = 'ai-site-manager/v1';
	private $contexts = array();

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'no_cache' ), 10, 3 );
		add_filter( 'rest_allowed_cors_headers', function ( $headers ) { $headers[] = 'X-AISM-Key'; return $headers; } );
	}

	public function routes() {
		foreach ( array(
			'/discovery' => 'GET', '/guide' => 'GET', '/schema' => 'GET',
			'/plugins' => 'GET', '/plugins/install' => 'POST', '/plugins/upload' => 'POST', '/plugins/action' => 'POST',
			'/themes' => 'GET', '/themes/install' => 'POST', '/themes/upload' => 'POST', '/themes/action' => 'POST',
			'/settings' => 'GET', '/settings/(?P<option>[a-zA-Z0-9_.-]+)' => 'GET,PUT',
			'/wp/(?P<resource>[a-zA-Z0-9_-]+)(?:/(?P<object_id>[1-9][0-9]*|[a-zA-Z_-]+))?(?:/(?P<subresource>revisions)(?:/(?P<revision_id>[1-9][0-9]*))?)?' => 'GET,POST,PUT,PATCH,DELETE',
		) as $route => $methods ) {
			register_rest_route( self::NS, $route, array( 'methods' => $methods, 'callback' => array( $this, 'dispatch' ), 'permission_callback' => array( $this, 'permission' ) ) );
		}
	}

	public function no_cache( $response, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/' . self::NS . '/' ) ) {
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Vary', 'Authorization, X-AISM-Key' );
			$response->header( 'X-Robots-Tag', 'noindex' );
		}
		return $response;
	}

	private function error( $code, $message, $status ) { return new WP_Error( $code, $message, array( 'status' => $status ) ); }

	/** Keys authenticate only inside this namespace, never as a general WordPress login. */
	public function permission( $request ) {
		if ( ! is_ssl() && ! ( defined( 'AISM_ALLOW_INSECURE_LOCAL_HTTP' ) && AISM_ALLOW_INSECURE_LOCAL_HTTP && 'local' === wp_get_environment_type() ) ) {
			return $this->error( 'aism_https_required', 'HTTPS is required for API keys.', 403 );
		}
		$token = $request->get_header( 'x-aism-key' );
		$authorization = $request->get_header( 'authorization' );
		if ( ! $token && preg_match( '/^Bearer\s+(\S+)$/iD', $authorization, $matches ) ) { $token = $matches[1]; }
		$key = AISM_Store::authenticate( $token );
		if ( ! $key ) { return $this->error( 'aism_invalid_key', 'A valid, unexpired API key is required in Authorization: Bearer or X-AISM-Key.', 401 ); }
		$scope = $this->required_scope( $request );
		if ( is_wp_error( $scope ) ) { AISM_Store::audit( $key, $request, $scope->get_error_data()['status'] ); return $scope; }
		if ( $scope && ! in_array( $scope, $key['scopes'], true ) ) {
			AISM_Store::audit( $key, $request, 403 );
			return $this->error( 'aism_scope_required', 'This key requires the ' . $scope . ' permission.', 403 );
		}
		$this->contexts[ spl_object_hash( $request ) ] = $key;
		return true;
	}

	/** Only exact collection, item and revision paths for WordPress content are forwarded. */
	private function resources() {
		$result = array( 'comments' => array( 'group' => 'comments', 'kind' => 'comments' ) );
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $type ) {
			if ( ! empty( $type->rest_namespace ) && 'wp/v2' !== $type->rest_namespace ) { continue; }
			$base = $type->rest_base ? $type->rest_base : $type->name;
			if ( preg_match( '/^[a-zA-Z0-9_-]+$/D', $base ) ) {
				$result[ $base ] = array( 'group' => 'attachment' === $type->name ? 'media' : 'content', 'kind' => 'post_type', 'name' => $type->name );
			}
		}
		foreach ( get_taxonomies( array( 'show_in_rest' => true ), 'objects' ) as $taxonomy ) {
			if ( ! empty( $taxonomy->rest_namespace ) && 'wp/v2' !== $taxonomy->rest_namespace ) { continue; }
			$base = $taxonomy->rest_base ? $taxonomy->rest_base : $taxonomy->name;
			if ( preg_match( '/^[a-zA-Z0-9_-]+$/D', $base ) ) { $result[ $base ] = array( 'group' => 'taxonomies', 'kind' => 'taxonomy', 'name' => $taxonomy->name ); }
		}
		foreach ( array( 'types', 'taxonomies', 'statuses' ) as $base ) { $result[ $base ] = array( 'group' => 'content', 'kind' => 'discovery' ); }
		// Never forward user, plugin, theme, settings, batch, or application-password controllers.
		foreach ( array( 'users', 'plugins', 'themes', 'settings', 'batch', 'application-passwords' ) as $denied ) { unset( $result[ $denied ] ); }
		return $result;
	}

	private function required_scope( $request ) {
		$route = $request->get_route();
		$method = $request->get_method();
		if ( preg_match( '#^/' . self::NS . '/(plugins|themes)(?:/|$)#', $route, $matches ) ) {
			$body = $request->get_json_params();
			$delete = is_array( $body ) && isset( $body['action'] ) && 'delete' === $body['action'] && substr( $route, -7 ) === '/action';
			return $matches[1] . ( 'GET' === $method ? ':read' : ( $delete ? ':delete' : ':write' ) );
		}
		if ( preg_match( '#^/' . self::NS . '/settings(?:/|$)#', $route ) ) { return 'GET' === $method ? 'settings:read' : 'settings:write'; }
		if ( 0 !== strpos( $route, '/' . self::NS . '/wp/' ) ) { return ''; }
		$url = $request->get_url_params();
		$resources = $this->resources();
		if ( ! isset( $resources[ $url['resource'] ] ) ) { return $this->error( 'aism_route_not_allowed', 'This resource is not exposed. Read /discovery.', 404 ); }
		$resource = $resources[ $url['resource'] ];
		if ( 'discovery' === $resource['kind'] && 'GET' !== $method ) { return $this->error( 'aism_method_not_allowed', 'This resource is read-only.', 405 ); }
		if ( ! empty( $url['object_id'] ) && 'discovery' !== $resource['kind'] && ! ctype_digit( (string) $url['object_id'] ) ) { return $this->error( 'aism_invalid_id', 'Content IDs must be positive integers.', 400 ); }
		if ( ! empty( $url['subresource'] ) && ( 'post_type' !== $resource['kind'] || 'GET' !== $method ) ) { return $this->error( 'aism_route_not_allowed', 'Revisions support read requests on post types only.', 405 ); }
		$action = 'GET' === $method ? 'read' : ( 'DELETE' === $method ? 'delete' : 'write' );
		return $resource['group'] . ':' . $action;
	}

	public function dispatch( $request ) {
		$id = spl_object_hash( $request );
		if ( ! isset( $this->contexts[ $id ] ) ) { return $this->error( 'aism_invalid_key', 'Authentication required.', 401 ); }
		$key = $this->contexts[ $id ];
		unset( $this->contexts[ $id ] );
		$previous_user = get_current_user_id();
		wp_set_current_user( (int) $key['user_id'] );
		try {
			$route = substr( $request->get_route(), strlen( '/' . self::NS ) );
			if ( '/discovery' === $route ) { $result = $this->discovery( $key ); }
			elseif ( '/guide' === $route ) { $result = array( 'format' => 'markdown', 'guide' => file_get_contents( AISM_DIR . 'docs/AI-GUIDE.md' ) ); }
			elseif ( '/schema' === $route ) { $result = $this->schema( $request ); }
			elseif ( '/plugins' === $route || 0 === strpos( $route, '/plugins/' ) ) { $result = AISM_Plugins::dispatch( $request, $route ); }
			elseif ( '/themes' === $route || 0 === strpos( $route, '/themes/' ) ) { $result = AISM_Themes::dispatch( $request, $route ); }
			elseif ( '/settings' === $route || 0 === strpos( $route, '/settings/' ) ) { $result = $this->settings( $request, $key ); }
			else { $result = $this->forward( $request ); }
			$response = is_wp_error( $result ) ? rest_convert_error_to_response( $result ) : rest_ensure_response( $result );
			AISM_Store::audit( $key, $request, $response->get_status() );
			return $response;
		} finally { wp_set_current_user( $previous_user ); }
	}

	private function forward( $request ) {
		$url = $request->get_url_params();
		$path = '/wp/v2/' . $url['resource'];
		foreach ( array( 'object_id', 'subresource', 'revision_id' ) as $part ) { if ( ! empty( $url[ $part ] ) ) { $path .= '/' . $url[ $part ]; } }
		$inner = new WP_REST_Request( $request->get_method(), $path );
		$query = $request->get_query_params();
		$body_params = $request->get_body_params();
		$json = $request->get_json_params();
		$route_params = array();
		if ( ! empty( $url['object_id'] ) ) { $route_params[] = 'id'; }
		if ( ! empty( $url['subresource'] ) ) { $route_params[] = 'parent'; }
		// WP gives JSON/body/query values precedence over URL captures. Keep IDs tied to the path.
		foreach ( $route_params as $name ) {
			unset( $query[ $name ], $body_params[ $name ] );
			if ( is_array( $json ) ) { unset( $json[ $name ] ); }
		}
		// _fields is supported; method/path/authentication overrides are never forwarded.
		foreach ( array( '_method', '_jsonp', '_embed', '_envelope', 'rest_route', '_wpnonce' ) as $name ) { unset( $query[ $name ] ); }
		$inner->set_query_params( $query );
		$inner->set_body( is_array( $json ) ? wp_json_encode( $json ) : $request->get_body() );
		$inner->set_body_params( $body_params );
		$inner->set_file_params( $request->get_file_params() );
		foreach ( array( 'content-type', 'content-disposition', 'content-md5' ) as $header ) {
			if ( $request->get_header( $header ) ) { $inner->set_header( $header, $request->get_header( $header ) ); }
		}
		return rest_do_request( $inner );
	}

	private function discovery( $key ) {
		return array(
			'plugin_version' => AISM_VERSION, 'wordpress_version' => get_bloginfo( 'version' ), 'base_url' => rest_url( self::NS ),
			'key' => array( 'id' => (int) $key['id'], 'name' => $key['name'], 'user_id' => (int) $key['user_id'], 'scopes' => $key['scopes'], 'expires_at_utc' => $key['expires_at'] ),
			'resources' => $this->resources(), 'approved_options' => array_values( array_filter( $key['option_names'], array( 'AISM_Store', 'option_allowed' ) ) ),
			'endpoints' => array( 'GET /guide', 'GET /schema?resource=posts', 'GET /settings', 'GET|PUT /settings/{option}', 'GET|POST /wp/{resource}', 'GET|POST|PUT|PATCH|DELETE /wp/{resource}/{id}', 'GET /wp/{resource}/{id}/revisions', 'GET /plugins', 'POST /plugins/install', 'POST /plugins/upload', 'POST /plugins/action', 'GET /themes', 'POST /themes/install', 'POST /themes/upload', 'POST /themes/action' ),
			'stores' => array( 'posts, postmeta' => '/wp/posts, /wp/pages, /wp/media and other discovered post types; REST-registered meta in the meta field', 'terms, term_taxonomy, term_relationships, termmeta' => 'Discovered taxonomy resources; assign terms through post fields', 'comments, commentmeta' => '/wp/comments', 'options' => '/settings/{option}; explicit per-key allowlist' ),
			'limits' => array( 'max_per_page' => 100, 'settings_body_bytes' => 262144, 'raw_sql' => false, 'native_wordpress_permissions' => true, 'key_authentication_outside_this_namespace' => false ),
		);
	}

	private function schema( $request ) {
		$resource = $request->get_param( 'resource' );
		$resources = $this->resources();
		if ( ! is_string( $resource ) || ( ! isset( $resources[ $resource ] ) && ! in_array( $resource, array( 'plugins', 'themes' ), true ) ) ) { return $this->error( 'aism_invalid_resource', 'Provide a resource from /discovery, for example ?resource=posts.', 400 ); }
		return rest_do_request( new WP_REST_Request( 'OPTIONS', '/wp/v2/' . $resource ) );
	}

	private function option_schema( $name, $value ) {
		$builtins = array(
			'blogname' => array( 'type' => 'string' ), 'blogdescription' => array( 'type' => 'string' ),
			'timezone_string' => array( 'type' => 'string', 'enum' => array_merge( array( '' ), timezone_identifiers_list( DateTimeZone::ALL_WITH_BC ) ) ),
			'date_format' => array( 'type' => 'string', 'minLength' => 1 ), 'time_format' => array( 'type' => 'string', 'minLength' => 1 ),
			'start_of_week' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 6 ),
			'posts_per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
			'default_comment_status' => array( 'type' => 'string', 'enum' => array( 'open', 'closed' ) ),
			'default_ping_status' => array( 'type' => 'string', 'enum' => array( 'open', 'closed' ) ),
		);
		if ( isset( $builtins[ $name ] ) ) { return $builtins[ $name ]; }
		$registered = get_registered_settings();
		if ( isset( $registered[ $name ] ) ) {
			$setting = $registered[ $name ];
			$schema = array( 'type' => $setting['type'] );
			if ( is_array( $setting['show_in_rest'] ) && isset( $setting['show_in_rest']['schema'] ) ) { $schema = array_merge( $schema, $setting['show_in_rest']['schema'] ); }
			return $schema;
		}
		$type = gettype( $value );
		if ( 'array' === $type ) { return array( 'type' => array_keys( $value ) === range( 0, count( $value ) - 1 ) || array() === $value ? 'array' : 'object' ); }
		return array( 'type' => array( 'boolean' => 'boolean', 'integer' => 'integer', 'double' => 'number', 'string' => 'string' )[ $type ] ?? 'object' );
	}

	private function settings( $request, $key ) {
		if ( ! current_user_can( 'manage_options' ) ) { return $this->error( 'aism_settings_forbidden', 'The key owner needs manage_options.', 403 ); }
		$url = $request->get_url_params();
		if ( empty( $url['option'] ) ) {
			$options = array();
			foreach ( $key['option_names'] as $name ) {
				if ( ! AISM_Store::option_allowed( $name ) ) { continue; }
				$value = get_option( $name, null );
				$options[] = array( 'name' => $name, 'exists' => null !== $value, 'schema' => $this->option_schema( $name, $value ) );
			}
			return array( 'options' => $options );
		}
		$name = $url['option'];
		if ( ! AISM_Store::option_allowed( $name ) || ! in_array( $name, $key['option_names'], true ) ) { return $this->error( 'aism_option_not_allowed', 'This option is not approved for this key.', 403 ); }
		$current = get_option( $name, null );
		if ( 'GET' === $request->get_method() ) {
			if ( null === $current ) { return $this->error( 'aism_option_missing', 'This option does not exist.', 404 ); }
			return array( 'name' => $name, 'value' => $current, 'schema' => $this->option_schema( $name, $current ) );
		}
		if ( strlen( $request->get_body() ) > 262144 ) { return $this->error( 'aism_body_too_large', 'Settings bodies may not exceed 256 KiB.', 413 ); }
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! array_key_exists( 'value', $body ) || count( $body ) !== 1 || null === $body['value'] ) { return $this->error( 'aism_invalid_setting', 'Send a JSON object containing only a non-null value field.', 400 ); }
		// New options require a registered schema; existing options use their registered or inferred type.
		if ( null === $current && ! isset( get_registered_settings()[ $name ] ) ) { return $this->error( 'aism_option_missing', 'Create and register this option in WordPress first.', 404 ); }
		$valid = rest_validate_value_from_schema( $body['value'], $this->option_schema( $name, $current ), 'value' );
		if ( is_wp_error( $valid ) ) { return $this->error( 'aism_invalid_setting', $valid->get_error_message(), 400 ); }
		// update_option invokes sanitize_option and registered sanitize callbacks and maintains WP caches.
		global $wpdb;
		$wpdb->last_error = '';
		$changed = update_option( $name, $body['value'] );
		if ( $wpdb->last_error ) { return $this->error( 'aism_setting_failed', 'The option could not be saved.', 500 ); }
		$saved = get_option( $name, null );
		return array( 'name' => $name, 'value' => $saved, 'changed' => $changed );
	}
}
