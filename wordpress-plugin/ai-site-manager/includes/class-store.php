<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AISM_Store {
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'aism_' . $name;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$keys = self::table( 'keys' );
		$audit = self::table( 'audit' );
		$map = self::table( 'migration_map' );
		dbDelta( "CREATE TABLE $keys (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			key_hash char(64) NOT NULL,
			key_prefix varchar(24) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			scopes longtext NOT NULL,
			option_names longtext NOT NULL,
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			last_used_at datetime DEFAULT NULL,
			revoked_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY key_hash (key_hash)
		) $charset;" );
		dbDelta( "CREATE TABLE $audit (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			key_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			method varchar(10) NOT NULL,
			route varchar(255) NOT NULL,
			status smallint unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) $charset;" );
		// Source→target object IDs for site migrations into this site.
		dbDelta( "CREATE TABLE $map (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source char(36) NOT NULL,
			object_type varchar(20) NOT NULL,
			source_id bigint(20) unsigned NOT NULL,
			target_id bigint(20) unsigned NOT NULL,
			created tinyint(1) NOT NULL DEFAULT 0,
			pending tinyint(1) NOT NULL DEFAULT 1,
			refs longtext NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_object (source,object_type,source_id),
			KEY source_pending (source,pending)
		) $charset;" );
		update_option( 'aism_version', AISM_VERSION, false );
		if ( ! wp_next_scheduled( 'aism_cleanup' ) ) { wp_schedule_event( time(), 'daily', 'aism_cleanup' ); }
	}

	public static function scopes() {
		$result = array();
		foreach ( array( 'content', 'media', 'taxonomies', 'comments', 'plugins', 'themes', 'migration' ) as $group ) {
			foreach ( array( 'read', 'write', 'delete' ) as $action ) { $result[] = "$group:$action"; }
		}
		return array_merge( $result, array( 'settings:read', 'settings:write', 'users:read', 'users:write' ) );
	}

	public static function default_options() {
		return array( 'blogname', 'blogdescription', 'timezone_string', 'date_format', 'time_format', 'start_of_week', 'posts_per_page', 'default_comment_status', 'default_ping_status' );
	}

	/** Never expose identity, code execution, credentials or this plugin's own controls. */
	public static function option_allowed( $name ) {
		global $wpdb;
		if ( ! is_string( $name ) || ! preg_match( '/^[a-zA-Z0-9_.-]{1,191}$/D', $name ) ) { return false; }
		$denied = array( 'siteurl', 'home', 'admin_email', 'new_admin_email', 'users_can_register', 'default_role', 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet', 'uninstall_plugins', 'cron', 'upload_path', 'upload_url_path', 'fileupload_url', 'site_admins', 'allowedthemes', 'recently_activated', 'rewrite_rules' );
		return ! in_array( $name, $denied, true ) && $name !== $wpdb->prefix . 'user_roles'
			&& ! preg_match( '/(^_|^aism_|user_roles|password|secret|token|api.?key|credential|salt)/i', $name );
	}

	public static function create_key( $name, $user_id, $scopes, $options, $days ) {
		global $wpdb;
		$user = get_userdata( $user_id );
		$name = sanitize_text_field( $name );
		if ( ! $user || ! user_can( $user, 'read' ) || '' === $name || strlen( $name ) > 190 || $days < 1 || $days > 365 ) {
			return new WP_Error( 'aism_invalid_key', 'Choose a name, an existing user, and an expiry of 1–365 days.' );
		}
		$permissions = self::validate_permissions( $scopes, $options );
		if ( is_wp_error( $permissions ) ) { return $permissions; }
		$token = 'aism_' . bin2hex( random_bytes( 32 ) );
		$ok = $wpdb->insert( self::table( 'keys' ), array(
			'name' => $name, 'key_hash' => hash( 'sha256', $token ), 'key_prefix' => substr( $token, 0, 17 ),
			'user_id' => $user_id, 'scopes' => wp_json_encode( $permissions['scopes'] ), 'option_names' => wp_json_encode( $permissions['option_names'] ),
			'created_at' => gmdate( 'Y-m-d H:i:s' ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ),
		) );
		return $ok ? array( 'id' => (int) $wpdb->insert_id, 'token' => $token ) : new WP_Error( 'aism_storage_error', 'Could not save the API key.' );
	}

	private static function validate_permissions( $scopes, $options ) {
		$scopes = array_values( array_intersect( self::scopes(), $scopes ) );
		if ( ! $scopes ) { return new WP_Error( 'aism_invalid_scopes', 'Select at least one permission.' ); }
		if ( count( $options ) > 100 ) { return new WP_Error( 'aism_options_limit', 'Approve at most 100 option names per key.' ); }
		foreach ( $options as $option ) {
			if ( ! self::option_allowed( $option ) ) { return new WP_Error( 'aism_forbidden_option', 'An option name is invalid or protected: ' . $option ); }
		}
		return array( 'scopes' => $scopes, 'option_names' => array_values( array_unique( $options ) ) );
	}

	public static function get_key( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'keys' ) . ' WHERE id = %d', $id ), ARRAY_A );
	}

	/** Update only permissions; never rotate the secret or reactivate an inactive key. */
	public static function update_permissions( $id, $scopes, $options ) {
		global $wpdb;
		$permissions = self::validate_permissions( $scopes, $options );
		if ( is_wp_error( $permissions ) ) { return $permissions; }
		$result = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table( 'keys' ) . ' SET scopes = %s, option_names = %s WHERE id = %d AND revoked_at IS NULL AND expires_at > %s',
			wp_json_encode( $permissions['scopes'] ), wp_json_encode( $permissions['option_names'] ), $id, gmdate( 'Y-m-d H:i:s' )
		) );
		if ( false === $result ) { return new WP_Error( 'aism_storage_error', 'Could not save the API key permissions.' ); }
		// MySQL also returns zero when the submitted permissions are unchanged.
		if ( 0 === $result ) {
			$key = self::get_key( $id );
			if ( ! $key || $key['revoked_at'] || $key['expires_at'] <= gmdate( 'Y-m-d H:i:s' ) ) {
				return new WP_Error( 'aism_inactive_key', 'Only active API keys can be edited.' );
			}
		}
		return true;
	}

	public static function authenticate( $token ) {
		global $wpdb;
		if ( ! is_string( $token ) || ! preg_match( '/^aism_[a-f0-9]{64}$/D', $token ) ) { return false; }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'keys' ) . ' WHERE key_hash = %s AND revoked_at IS NULL AND expires_at > %s', hash( 'sha256', $token ), gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
		if ( ! $row || ! hash_equals( $row['key_hash'], hash( 'sha256', $token ) ) || ! user_can( (int) $row['user_id'], 'read' ) ) { return false; }
		$row['scopes'] = (array) json_decode( $row['scopes'], true );
		$row['option_names'] = (array) json_decode( $row['option_names'], true );
		return $row;
	}

	public static function revoke( $id ) {
		global $wpdb;
		return $wpdb->update( self::table( 'keys' ), array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	public static function audit( $key, $request, $status ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( self::table( 'keys' ), array( 'last_used_at' => $now ), array( 'id' => $key['id'] ) );
		$wpdb->insert( self::table( 'audit' ), array( 'key_id' => $key['id'], 'user_id' => $key['user_id'], 'method' => $request->get_method(), 'route' => substr( $request->get_route(), 0, 255 ), 'status' => $status, 'created_at' => $now ) );
	}

	public static function cleanup() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table( 'audit' ) . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
	}
}
