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
		if ( '/plugins/update' === $route ) {
			$params = $request->get_body_params();
			$file = self::installed_plugin( $params['plugin'] ?? null );
			if ( is_wp_error( $file ) ) { return $file; }
			return self::upload( $request, $file );
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['action'], $body['plugin'] ) || ! is_string( $body['action'] ) || ! is_string( $body['plugin'] ) || ! in_array( $body['action'], array( 'activate', 'deactivate', 'update', 'delete' ), true ) ) {
			return self::error( 'aism_invalid_action', 'Send JSON with plugin and action (activate, deactivate, update or delete).' );
		}
		$file = self::installed_plugin( $body['plugin'] );
		if ( is_wp_error( $file ) ) { return $file; }
		$action = $body['action'];
		if ( 'update' === $action ) { return self::update( $file ); }
		$inner = new WP_REST_Request( 'delete' === $action ? 'DELETE' : 'PUT', '/wp/v2/plugins/' . substr( $file, 0, -4 ) );
		if ( 'delete' !== $action ) { $inner->set_body_params( array( 'status' => 'activate' === $action ? 'active' : 'inactive' ) ); }
		return rest_do_request( $inner );
	}

	private static function installed_plugin( $file ) {
		$plugins = get_plugins();
		if ( ! is_string( $file ) || '' === $file || 0 !== validate_file( $file ) ) { return self::error( 'aism_plugin_missing', 'Use an installed plugin identifier from GET /plugins.', 404 ); }
		if ( ! isset( $plugins[ $file ] ) && isset( $plugins[ $file . '.php' ] ) ) { $file .= '.php'; }
		if ( ! isset( $plugins[ $file ] ) ) { return self::error( 'aism_plugin_missing', 'Use an installed plugin identifier from GET /plugins.', 404 ); }
		if ( plugin_basename( AISM_DIR . 'ai-site-manager.php' ) === $file ) { return self::error( 'aism_self_management', 'Manage AI Site Manager itself from WordPress administration.', 403 ); }
		return $file;
	}

	private static function filesystem() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		// Do not prompt for credentials or read credentials from an API payload.
		$credentials = request_filesystem_credentials( '', '', false, false, null );
		if ( false === $credentials || ! WP_Filesystem( $credentials ) ) { return self::error( 'aism_filesystem_unavailable', 'WordPress needs filesystem access. Configure it through your host before retrying.', 409 ); }
		return true;
	}

	private static function upgrader_result( $result, $skin ) {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( ! is_array( $data ) || ! isset( $data['status'] ) ) { $result->add_data( array( 'status' => 500 ) ); }
			return $result;
		}
		$errors = $skin->get_errors();
		if ( is_wp_error( $errors ) && $errors->has_errors() ) { return self::upgrader_result( $errors, $skin ); }
		if ( ! $result ) { return self::error( 'aism_plugin_operation_failed', 'WordPress could not complete the plugin operation.', 500 ); }
		return true;
	}

	private static function upload( $request, $target = null ) {
		if ( ! current_user_can( 'upload_plugins' ) || ! current_user_can( $target ? 'update_plugins' : 'install_plugins' ) ) { return self::error( 'aism_plugin_upload_forbidden', 'The key owner cannot upload or update plugins.', 403 ); }
		// Replacing the plugins root would endanger unrelated plugins. Only dedicated folders are supported.
		if ( $target && ( '.' === dirname( $target ) || false !== strpos( dirname( $target ), '/' ) ) ) { return self::error( 'aism_plugin_zip_target', 'ZIP updates require a plugin in its own top-level directory.' ); }
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
			// Core validates the package first; this filter runs before any installed files are removed.
			$validate = function ( $source, $remote_source, $instance ) use ( $target, $upgrader ) {
				if ( $instance !== $upgrader || ! $target || is_wp_error( $source ) ) { return $source; }
				global $wp_filesystem;
				$local = str_replace( $wp_filesystem->wp_content_dir(), trailingslashit( WP_CONTENT_DIR ), $source );
				$main = trailingslashit( $local ) . basename( $target );
				if ( basename( untrailingslashit( $source ) ) !== dirname( $target ) || ! is_file( $main ) ) { return self::error( 'aism_plugin_zip_mismatch', 'The ZIP folder and main plugin file must match the selected installed plugin.' ); }
				$data = get_plugin_data( $main, false, false );
				$installed = get_plugins();
				if ( empty( $data['Name'] ) || empty( $data['Version'] ) || ! version_compare( $data['Version'], $installed[ $target ]['Version'], '>' ) ) { return self::error( 'aism_plugin_zip_version', 'The ZIP must contain a valid plugin with a newer version.', 409 ); }
				if ( ! is_php_version_compatible( $data['RequiresPHP'] ) || ! is_wp_version_compatible( $data['RequiresWP'] ) ) { return self::error( 'aism_plugin_zip_incompatible', 'The uploaded plugin requires a newer PHP or WordPress version.' ); }
				return $source;
			};
			add_filter( 'upgrader_source_selection', $validate, 20, 3 );
			try { $raw = $upgrader->install( $temp, array( 'overwrite_package' => null !== $target ) ); }
			finally { remove_filter( 'upgrader_source_selection', $validate, 20 ); }
			$result = self::upgrader_result( $raw, $skin );
			if ( is_wp_error( $result ) ) { return $result; }
			if ( $target ) {
				wp_clean_plugins_cache();
				$plugins = get_plugins();
				return new WP_REST_Response( array( 'updated' => true, 'plugin' => $target, 'version' => $plugins[ $target ]['Version'] ?? null, 'active' => is_plugin_active( $target ) ), 200 );
			}
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
