<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AISM_Themes {
	private static function error( $code, $message, $status = 400 ) { return new WP_Error( $code, $message, array( 'status' => $status ) ); }

	public static function dispatch( $request, $route ) {
		if ( '/themes' === $route ) { return rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/themes' ) ); }
		if ( is_multisite() ) { return self::error( 'aism_multisite_themes', 'Theme changes through this API are supported on single-site WordPress only.', 403 ); }
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		if ( '/themes/install' === $route ) {
			if ( ! current_user_can( 'install_themes' ) ) { return self::error( 'aism_theme_install_forbidden', 'The key owner cannot install themes.', 403 ); }
			$body = $request->get_json_params();
			if ( ! is_array( $body ) || empty( $body['slug'] ) || ! is_string( $body['slug'] ) || ! preg_match( '/^[a-z0-9-]+$/D', $body['slug'] ) ) { return self::error( 'aism_invalid_slug', 'Provide a WordPress.org theme slug in JSON.' ); }
			$info = themes_api( 'theme_information', array( 'slug' => $body['slug'], 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $info ) ) { $info->add_data( array( 'status' => 502 ) ); return $info; }
			if ( empty( $info->download_link ) ) { return self::error( 'aism_theme_package_missing', 'WordPress.org did not provide a theme package.', 502 ); }
			return self::install( $info->download_link );
		}
		if ( '/themes/upload' === $route ) { return self::upload( $request ); }
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['action'], $body['theme'] ) || ! is_string( $body['action'] ) || ! is_string( $body['theme'] ) || ! in_array( $body['action'], array( 'activate', 'update', 'delete' ), true ) ) {
			return self::error( 'aism_invalid_action', 'Send JSON with theme and action (activate, update or delete).' );
		}
		$stylesheet = $body['theme'];
		$themes = wp_get_themes( array( 'errors' => null ) );
		if ( 0 !== validate_file( $stylesheet ) || ! isset( $themes[ $stylesheet ] ) ) { return self::error( 'aism_theme_missing', 'Use an installed stylesheet identifier from GET /themes.', 404 ); }
		$theme = $themes[ $stylesheet ];
		if ( 'update' === $body['action'] ) { return self::update( $stylesheet ); }
		if ( 'delete' === $body['action'] ) {
			if ( ! current_user_can( 'delete_themes' ) ) { return self::error( 'aism_theme_delete_forbidden', 'The key owner cannot delete themes.', 403 ); }
			if ( in_array( $stylesheet, array( get_stylesheet(), get_template() ), true ) ) { return self::error( 'aism_active_theme', 'Switch to another theme before deleting the active theme or its parent.', 409 ); }
			$fs = self::filesystem();
			if ( is_wp_error( $fs ) ) { return $fs; }
			$result = delete_theme( $stylesheet );
			if ( is_wp_error( $result ) ) { $result->add_data( array( 'status' => 500 ) ); return $result; }
			if ( true !== $result ) { return self::error( 'aism_theme_operation_failed', 'WordPress could not delete the theme.', 500 ); }
			wp_clean_themes_cache();
			return array( 'theme' => $stylesheet, 'deleted' => true );
		}
		if ( ! current_user_can( 'switch_themes' ) || ! $theme->is_allowed() ) { return self::error( 'aism_theme_activate_forbidden', 'The key owner cannot activate this theme.', 403 ); }
		$valid = $theme->errors();
		if ( ! is_wp_error( $valid ) ) { $valid = validate_theme_requirements( $stylesheet ); }
		// Validate before switch_theme(), which can otherwise terminate the request with HTML.
		if ( is_wp_error( $valid ) ) { $valid->add_data( array( 'status' => 400 ) ); return $valid; }
		switch_theme( $stylesheet );
		if ( get_stylesheet() !== $stylesheet ) { return self::error( 'aism_theme_operation_failed', 'WordPress could not activate the theme.', 500 ); }
		return self::summary( wp_get_theme( $stylesheet ) );
	}

	private static function summary( $theme ) {
		return array( 'theme' => $theme->get_stylesheet(), 'template' => $theme->get_template(), 'version' => $theme->get( 'Version' ), 'status' => get_stylesheet() === $theme->get_stylesheet() ? 'active' : 'inactive' );
	}

	private static function filesystem() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		// Never print a credentials form or accept filesystem credentials in an API payload.
		ob_start();
		try {
			$credentials = request_filesystem_credentials( '', '', false, false, null );
			if ( false === $credentials || ! WP_Filesystem( $credentials ) ) { return self::error( 'aism_filesystem_unavailable', 'WordPress needs filesystem access. Configure it through your host before retrying.', 409 ); }
		} finally { ob_end_clean(); }
		return true;
	}

	private static function upgrader_result( $result, $skin ) {
		if ( is_wp_error( $result ) ) { $result->add_data( array( 'status' => 500 ) ); return $result; }
		$errors = $skin->get_errors();
		if ( is_wp_error( $errors ) && $errors->has_errors() ) { $errors->add_data( array( 'status' => 500 ) ); return $errors; }
		if ( ! $result ) { return self::error( 'aism_theme_operation_failed', 'WordPress could not complete the theme operation.', 500 ); }
		return true;
	}

	private static function install( $package ) {
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) { return $fs; }
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$skin = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		// Core validates the package and installs missing parents; never replace or activate.
		$result = self::upgrader_result( $upgrader->install( $package ), $skin );
		if ( is_wp_error( $result ) ) { return $result; }
		$theme = $upgrader->theme_info();
		if ( ! $theme ) { return self::error( 'aism_theme_operation_failed', 'WordPress could not identify the installed theme.', 500 ); }
		return new WP_REST_Response( array_merge( array( 'installed' => true ), self::summary( $theme ) ), 201 );
	}

	private static function upload( $request ) {
		if ( ! current_user_can( 'install_themes' ) || ! current_user_can( 'upload_themes' ) ) { return self::error( 'aism_theme_upload_forbidden', 'The key owner cannot upload themes.', 403 ); }
		$files = $request->get_file_params();
		$file = $files['themezip'] ?? null;
		if ( ! is_array( $file ) || ! isset( $file['error'], $file['name'], $file['size'], $file['tmp_name'] ) || UPLOAD_ERR_OK !== $file['error'] || ! is_string( $file['name'] ) || 'zip' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) || ! is_uploaded_file( $file['tmp_name'] ) ) { return self::error( 'aism_invalid_theme_upload', 'Upload a ZIP file using multipart field themezip.' ); }
		if ( $file['size'] > wp_max_upload_size() ) { return self::error( 'aism_upload_too_large', 'The theme ZIP exceeds the WordPress upload limit.', 413 ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$temp = wp_tempnam( 'aism-theme.zip' );
		if ( ! $temp || ! move_uploaded_file( $file['tmp_name'], $temp ) ) { if ( $temp ) { wp_delete_file( $temp ); } return self::error( 'aism_upload_failed', 'Unable to stage the uploaded ZIP.', 500 ); }
		try { return self::install( $temp ); } finally { wp_delete_file( $temp ); }
	}

	private static function update( $stylesheet ) {
		if ( ! current_user_can( 'update_themes' ) ) { return self::error( 'aism_theme_update_forbidden', 'The key owner cannot update themes.', 403 ); }
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) { return $fs; }
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		wp_update_themes();
		$updates = get_site_transient( 'update_themes' );
		if ( ! isset( $updates->response[ $stylesheet ] ) ) { return array( 'theme' => $stylesheet, 'updated' => false, 'message' => 'No update is currently advertised by WordPress.' ); }
		$skin = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result = self::upgrader_result( $upgrader->upgrade( $stylesheet ), $skin );
		if ( is_wp_error( $result ) ) { return $result; }
		wp_clean_themes_cache();
		return array_merge( array( 'updated' => true ), self::summary( wp_get_theme( $stylesheet ) ) );
	}
}
