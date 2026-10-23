<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AISM_Plugins {
	private static function error( $code, $message, $status = 400 ) { return new WP_Error( $code, $message, array( 'status' => $status ) ); }

	public static function dispatch( $request, $route ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( '/plugins' === $route ) {
			$inner = new WP_REST_Request( 'GET', '/wp/v2/plugins' );
			return rest_do_request( $inner );
		}
		if ( is_multisite() ) { return self::error( 'aism_multisite_plugins', 'Plugin changes through this API are supported on single-site WordPress only.', 403 ); }
		if ( '/plugins/install' === $route ) {
			$body = $request->get_json_params();
			if ( ! is_array( $body ) || empty( $body['slug'] ) || ! is_string( $body['slug'] ) || ! preg_match( '/^[a-z0-9-]+$/D', $body['slug'] ) ) { return self::error( 'aism_invalid_slug', 'Provide a WordPress.org plugin slug in JSON.' ); }
			// Install inactive; activation is a separate action with its own core permission checks.
			$inner = new WP_REST_Request( 'POST', '/wp/v2/plugins' );
			$inner->set_body_params( array( 'slug' => $body['slug'], 'status' => 'inactive' ) );
			return rest_do_request( $inner );
		}
		if ( '/plugins/upload' === $route ) { return self::upload( $request ); }
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['action'], $body['plugin'] ) || ! is_string( $body['action'] ) || ! is_string( $body['plugin'] ) || ! in_array( $body['action'], array( 'activate', 'deactivate', 'update', 'delete' ), true ) ) {
			return self::error( 'aism_invalid_action', 'Send JSON with plugin and action (activate, deactivate, update or delete).' );
		}
		$plugins = get_plugins();
		$file = $body['plugin'];
		if ( ! isset( $plugins[ $file ] ) && isset( $plugins[ $file . '.php' ] ) ) { $file .= '.php'; }
		if ( ! isset( $plugins[ $file ] ) || 0 !== validate_file( $file ) ) { return self::error( 'aism_plugin_missing', 'Use an installed plugin identifier from GET /plugins.', 404 ); }
		if ( plugin_basename( AISM_DIR . 'ai-site-manager.php' ) === $file ) { return self::error( 'aism_self_management', 'Manage AI Site Manager itself from WordPress administration.', 403 ); }
		$action = $body['action'];
		if ( 'update' === $action ) { return self::update( $file ); }
		$inner = new WP_REST_Request( 'delete' === $action ? 'DELETE' : 'PUT', '/wp/v2/plugins/' . substr( $file, 0, -4 ) );
		if ( 'delete' !== $action ) { $inner->set_body_params( array( 'status' => 'activate' === $action ? 'active' : 'inactive' ) ); }
		return rest_do_request( $inner );
	}

	private static function filesystem() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		// Do not prompt for credentials or read credentials from an API payload.
		$credentials = request_filesystem_credentials( '', '', false, false, null );
		if ( false === $credentials || ! WP_Filesystem( $credentials ) ) { return self::error( 'aism_filesystem_unavailable', 'WordPress needs filesystem access. Configure it through your host before retrying.', 409 ); }
		return true;
	}

	private static function upgrader_result( $result, $skin ) {
		if ( is_wp_error( $result ) ) { $result->add_data( array( 'status' => 500 ) ); return $result; }
		$errors = $skin->get_errors();
		if ( is_wp_error( $errors ) && $errors->has_errors() ) { $errors->add_data( array( 'status' => 500 ) ); return $errors; }
		if ( ! $result ) { return self::error( 'aism_plugin_operation_failed', 'WordPress could not complete the plugin operation.', 500 ); }
		return true;
	}

	private static function upload( $request ) {
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'upload_plugins' ) ) { return self::error( 'aism_plugin_upload_forbidden', 'The key owner cannot upload plugins.', 403 ); }
		$files = $request->get_file_params();
		$file = $files['pluginzip'] ?? null;
		if ( ! is_array( $file ) || ! isset( $file['error'], $file['name'], $file['size'], $file['tmp_name'] ) || UPLOAD_ERR_OK !== $file['error'] || ! is_string( $file['name'] ) || 'zip' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) || ! is_uploaded_file( $file['tmp_name'] ) ) { return self::error( 'aism_invalid_plugin_upload', 'Upload a ZIP file using multipart field pluginzip.' ); }
		if ( $file['size'] > wp_max_upload_size() ) { return self::error( 'aism_upload_too_large', 'The plugin ZIP exceeds the WordPress upload limit.', 413 ); }
		// request_filesystem_credentials can print a form; never include that form in JSON.
		ob_start();
		try { $fs = self::filesystem(); } finally { ob_end_clean(); }
		if ( is_wp_error( $fs ) ) { return $fs; }
		$temp = wp_tempnam( 'aism-plugin.zip' );
		if ( ! $temp || ! move_uploaded_file( $file['tmp_name'], $temp ) ) { if ( $temp ) { wp_delete_file( $temp ); } return self::error( 'aism_upload_failed', 'Unable to stage the uploaded ZIP.', 500 ); }
		try {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			$skin = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result = self::upgrader_result( $upgrader->install( $temp ), $skin );
			if ( is_wp_error( $result ) ) { return $result; }
			$plugin = $upgrader->plugin_info();
			return new WP_REST_Response( array( 'installed' => true, 'plugin' => $plugin, 'status' => 'inactive' ), 201 );
		} finally { wp_delete_file( $temp ); }
	}

	private static function update( $file ) {
		if ( ! current_user_can( 'update_plugins' ) ) { return self::error( 'aism_plugin_update_forbidden', 'The key owner cannot update plugins.', 403 ); }
		ob_start();
		try { $fs = self::filesystem(); } finally { ob_end_clean(); }
		if ( is_wp_error( $fs ) ) { return $fs; }
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		if ( ! isset( $updates->response[ $file ] ) ) { return array( 'plugin' => $file, 'updated' => false, 'message' => 'No update is currently advertised by WordPress.' ); }
		$skin = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result = self::upgrader_result( $upgrader->upgrade( $file ), $skin );
		if ( is_wp_error( $result ) ) { return $result; }
		wp_clean_plugins_cache();
		$plugins = get_plugins();
		return array( 'plugin' => $file, 'updated' => true, 'version' => $plugins[ $file ]['Version'] ?? null, 'active' => is_plugin_active( $file ) );
	}
}
