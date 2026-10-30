<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site diagnostics (diagnostics:read) and routine maintenance actions (maintenance:write).
 *
 * Diagnostics report configuration, environment, scheduled events, optional Site Health
 * results and the end of the configured PHP/debug error logs. Only those two known log
 * files can be read. Maintenance runs events that are already scheduled (never arbitrary
 * hooks), flushes caches and rewrite rules, and removes expired transients.
 */
final class AISM_Diagnostics {
	const MAX_LOG_LINES = 1000;
	const MAX_LOG_BYTES = 4194304;

	private static function error( $code, $message, $status = 400 ) { return new WP_Error( $code, $message, array( 'status' => $status ) ); }

	public static function dispatch( $request, $route ) {
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'aism_diagnostics_forbidden', 'The key owner needs manage_options.', 403 ); }
		if ( '/diagnostics' === $route ) { return self::overview( rest_sanitize_boolean( $request->get_param( 'site_health' ) ) ); }
		if ( '/diagnostics/cron' === $route ) { return self::cron(); }
		if ( '/diagnostics/logs' === $route ) { return self::logs( $request ); }
		return self::maintenance( $request );
	}

	private static function bytes( $value ) { return function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) $value ) : (int) $value; }

	private static function constant( $name ) { return defined( $name ) ? constant( $name ) : null; }

	private static function overview( $site_health ) {
		global $wpdb, $wp_rewrite;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = array();
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array( 'plugin' => preg_replace( '/\.php$/', '', $file ), 'name' => $data['Name'], 'version' => $data['Version'], 'active' => in_array( $file, $active, true ) || is_plugin_active_for_network( $file ) );
		}
		$mu = array();
		foreach ( get_mu_plugins() as $file => $data ) { $mu[] = array( 'file' => $file, 'name' => $data['Name'], 'version' => $data['Version'] ); }
		$dropins = array_keys( get_dropins() );
		$theme = wp_get_theme();
		$sizes = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS tables, SUM(data_length + index_length) AS bytes FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s', DB_NAME, $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A );
		$cron = self::cron_events();
		$overdue = array_filter( $cron, function ( $event ) { return $event['overdue_seconds'] > 0; } );
		$result = array(
			'generated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			'wordpress' => array(
				'version' => get_bloginfo( 'version' ), 'home' => home_url(), 'siteurl' => site_url(), 'multisite' => is_multisite(), 'environment' => wp_get_environment_type(),
				'locale' => get_locale(), 'timezone' => wp_timezone_string(), 'permalink_structure' => $wp_rewrite ? $wp_rewrite->permalink_structure : get_option( 'permalink_structure' ),
				'https' => is_ssl(), 'object_cache' => wp_using_ext_object_cache() ? 'external' : 'none', 'dropins' => $dropins,
				'constants' => array(
					'WP_DEBUG' => self::constant( 'WP_DEBUG' ), 'WP_DEBUG_LOG' => self::constant( 'WP_DEBUG_LOG' ), 'WP_DEBUG_DISPLAY' => self::constant( 'WP_DEBUG_DISPLAY' ), 'SCRIPT_DEBUG' => self::constant( 'SCRIPT_DEBUG' ),
					'WP_CACHE' => self::constant( 'WP_CACHE' ), 'DISABLE_WP_CRON' => self::constant( 'DISABLE_WP_CRON' ), 'ALTERNATE_WP_CRON' => self::constant( 'ALTERNATE_WP_CRON' ), 'DISALLOW_FILE_EDIT' => self::constant( 'DISALLOW_FILE_EDIT' ),
					'DISALLOW_FILE_MODS' => self::constant( 'DISALLOW_FILE_MODS' ), 'AUTOMATIC_UPDATER_DISABLED' => self::constant( 'AUTOMATIC_UPDATER_DISABLED' ), 'WP_AUTO_UPDATE_CORE' => self::constant( 'WP_AUTO_UPDATE_CORE' ),
					'WP_MEMORY_LIMIT' => self::constant( 'WP_MEMORY_LIMIT' ), 'WP_MAX_MEMORY_LIMIT' => self::constant( 'WP_MAX_MEMORY_LIMIT' ), 'WP_ENVIRONMENT_TYPE' => self::constant( 'WP_ENVIRONMENT_TYPE' ), 'WP_HTTP_BLOCK_EXTERNAL' => self::constant( 'WP_HTTP_BLOCK_EXTERNAL' ),
				),
				'theme' => array( 'stylesheet' => $theme->get_stylesheet(), 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ), 'parent' => $theme->parent() ? $theme->get_template() : null ),
				'plugins' => $plugins, 'mu_plugins' => $mu,
			),
			'php' => array(
				'version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'os' => PHP_OS_FAMILY, 'memory_limit' => ini_get( 'memory_limit' ), 'max_execution_time' => (int) ini_get( 'max_execution_time' ),
				'upload_max_bytes' => self::bytes( ini_get( 'upload_max_filesize' ) ), 'post_max_bytes' => self::bytes( ini_get( 'post_max_size' ) ), 'wp_max_upload_bytes' => wp_max_upload_size(),
				'opcache' => function_exists( 'opcache_get_status' ) && ini_get( 'opcache.enable' ), 'extensions' => array_values( get_loaded_extensions() ),
			),
			'server' => array( 'software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '', 'time_utc' => gmdate( 'c' ) ),
			'database' => array(
				'server' => $wpdb->db_server_info(), 'version' => $wpdb->db_version(), 'charset' => $wpdb->charset, 'collate' => $wpdb->collate, 'prefix' => $wpdb->prefix,
				'tables' => isset( $sizes['tables'] ) ? (int) $sizes['tables'] : null, 'bytes' => isset( $sizes['bytes'] ) ? (int) $sizes['bytes'] : null,
				'standard_connection' => $wpdb->dbh instanceof mysqli,
			),
			'cron' => array( 'events' => count( $cron ), 'overdue' => count( $overdue ), 'disabled' => (bool) self::constant( 'DISABLE_WP_CRON' ), 'doing_cron_lock' => get_transient( 'doing_cron' ) ?: null ),
			'logs' => self::log_sources(),
		);
		if ( $site_health ) { $result['site_health'] = self::site_health(); }
		return $result;
	}

	private static function site_health() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		if ( ! class_exists( 'WP_Site_Health' ) ) { require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php'; }
		$health = WP_Site_Health::get_instance();
		$results = array();
		foreach ( WP_Site_Health::get_tests()['direct'] as $name => $test ) {
			$callback = is_string( $test['test'] ) ? array( $health, 'get_test_' . $test['test'] ) : $test['test'];
			if ( ! is_callable( $callback ) ) { continue; }
			ob_start();
			try { $outcome = call_user_func( $callback ); }
			catch ( Throwable $error ) { $outcome = array( 'status' => 'error', 'label' => $test['label'] ?? $name, 'description' => $error->getMessage() ); }
			finally { ob_end_clean(); }
			if ( ! is_array( $outcome ) ) { continue; }
			$results[] = array(
				'test' => $name, 'status' => $outcome['status'] ?? '', 'label' => wp_strip_all_tags( (string) ( $outcome['label'] ?? '' ) ),
				'badge' => isset( $outcome['badge']['label'] ) ? (string) $outcome['badge']['label'] : '',
				'description' => trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $outcome['description'] ?? '' ) . ' ' . (string) ( $outcome['actions'] ?? '' ) ) ) ),
			);
		}
		return array( 'note' => 'Direct Site Health tests only; asynchronous loopback tests are not run.', 'tests' => $results );
	}

	private static function cron_events() {
		$events = array();
		$now = time();
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $instances ) {
				foreach ( (array) $instances as $key => $event ) {
					$args = wp_json_encode( $event['args'] ?? array() );
					$events[] = array(
						'hook' => $hook, 'key' => $key, 'next_run_utc' => gmdate( 'Y-m-d H:i:s', (int) $timestamp ), 'timestamp' => (int) $timestamp,
						'overdue_seconds' => max( 0, $now - (int) $timestamp ), 'schedule' => $event['schedule'] ?: null, 'interval' => isset( $event['interval'] ) ? (int) $event['interval'] : null,
						'args' => false === $args ? null : ( strlen( $args ) > 1000 ? substr( $args, 0, 1000 ) . '…' : json_decode( $args, true ) ),
						'has_callbacks' => has_action( $hook ) !== false,
					);
				}
			}
		}
		return $events;
	}

	private static function cron() {
		$schedules = array();
		foreach ( wp_get_schedules() as $name => $schedule ) { $schedules[ $name ] = array( 'interval' => (int) $schedule['interval'], 'display' => $schedule['display'] ); }
		return array( 'now_utc' => gmdate( 'Y-m-d H:i:s' ), 'disabled' => (bool) self::constant( 'DISABLE_WP_CRON' ), 'events' => self::cron_events(), 'schedules' => $schedules,
			'note' => 'has_callbacks=false means no code is listening for the hook (often left behind by a removed plugin). Run a due event now with maintenance action run_cron.' );
	}

	/** Only the WordPress debug log and PHP's configured error log file; never arbitrary paths. */
	private static function log_paths() {
		$paths = array();
		$debug = self::constant( 'WP_DEBUG_LOG' );
		if ( true === $debug || in_array( $debug, array( 1, '1', 'true' ), true ) ) { $paths['debug'] = WP_CONTENT_DIR . '/debug.log'; }
		elseif ( is_string( $debug ) && '' !== $debug && ! in_array( strtolower( $debug ), array( 'false', '0' ), true ) ) { $paths['debug'] = $debug; }
		$php = (string) ini_get( 'error_log' );
		if ( '' !== $php && ! in_array( strtolower( $php ), array( 'syslog', 'stderr', '/dev/stderr', 'php://stderr' ), true ) && ( ! isset( $paths['debug'] ) || realpath( $php ) !== realpath( $paths['debug'] ) ) ) { $paths['php'] = $php; }
		return $paths;
	}

	private static function log_sources() {
		$sources = array();
		foreach ( self::log_paths() as $name => $path ) {
			$readable = is_file( $path ) && is_readable( $path );
			$sources[ $name ] = array( 'path' => $path, 'exists' => $readable, 'bytes' => $readable ? (int) filesize( $path ) : null, 'modified_utc' => $readable ? gmdate( 'Y-m-d H:i:s', (int) filemtime( $path ) ) : null );
		}
		if ( ! $sources ) { $sources['note'] = 'No log file is configured. Set WP_DEBUG and WP_DEBUG_LOG in wp-config.php, or PHP error_log, to record errors.'; }
		return $sources;
	}

	private static function logs( $request ) {
		$source = $request->get_param( 'source' ) ?? 'debug';
		$lines = $request->get_param( 'lines' ) ?? 200;
		$search = $request->get_param( 'search' );
		if ( ! is_string( $source ) || ! is_numeric( $lines ) || (int) $lines != $lines ) { return self::error( 'aism_invalid_log_request', 'Use source=debug|php and a whole number of lines.' ); }
		$lines = (int) $lines;
		if ( $lines < 1 || $lines > self::MAX_LOG_LINES || ( null !== $search && ( ! is_string( $search ) || strlen( $search ) > 200 ) ) ) {
			return self::error( 'aism_invalid_log_request', 'Use source=debug|php, lines 1–1000 and an optional search of up to 200 characters.' );
		}
		$paths = self::log_paths();
		if ( ! isset( $paths[ $source ] ) ) { return self::error( 'aism_log_missing', 'This log is not configured. Read /diagnostics for configured log sources.', 404 ); }
		$path = $paths[ $source ];
		if ( ! is_file( $path ) || ! is_readable( $path ) ) { return self::error( 'aism_log_missing', 'The configured log file does not exist yet or cannot be read.', 404 ); }
		$size = (int) filesize( $path );
		$read = min( $size, '' !== (string) $search ? self::MAX_LOG_BYTES : min( self::MAX_LOG_BYTES, $lines * 2048 ) );
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) { return self::error( 'aism_log_unreadable', 'The log file could not be opened.', 500 ); }
		fseek( $handle, $size - $read );
		$text = (string) fread( $handle, $read );
		fclose( $handle );
		$all = preg_split( '/\r\n|\n|\r/', $text );
		if ( $read < $size ) { array_shift( $all ); } // First line is probably partial.
		$all = array_values( array_filter( $all, 'strlen' ) );
		if ( '' !== (string) $search ) { $all = array_values( array_filter( $all, function ( $line ) use ( $search ) { return false !== stripos( $line, $search ); } ) ); }
		$tail = array_slice( $all, -$lines );
		foreach ( $tail as &$line ) {
			if ( strlen( $line ) > 4000 ) { $line = substr( $line, 0, 4000 ) . '…'; }
			if ( '' === wp_check_invalid_utf8( $line ) ) { $line = wp_check_invalid_utf8( $line, true ); }
		}
		unset( $line );
		return array( 'source' => $source, 'path' => $path, 'bytes' => $size, 'scanned_bytes' => $read, 'modified_utc' => gmdate( 'Y-m-d H:i:s', (int) filemtime( $path ) ), 'lines' => $tail, 'matched' => count( $all ) );
	}

	private static function maintenance( $request ) {
		$body = $request->get_json_params();
		$action = is_array( $body ) && isset( $body['action'] ) ? $body['action'] : '';
		AISM_Store::note( 'maintenance: ' . ( is_string( $action ) ? $action : '' ) . ( isset( $body['hook'] ) && is_string( $body['hook'] ) ? ' ' . $body['hook'] : '' ) );
		switch ( $action ) {
			case 'run_cron': return self::run_cron( $body );
			case 'flush_cache': return array( 'action' => $action, 'flushed' => (bool) wp_cache_flush() );
			case 'flush_rewrite_rules': flush_rewrite_rules( false ); return array( 'action' => $action, 'flushed' => true, 'rules' => count( (array) get_option( 'rewrite_rules' ) ) );
			case 'delete_expired_transients':
				global $wpdb;
				$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );
				delete_expired_transients( true );
				$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );
				return array( 'action' => $action, 'transient_rows_removed' => max( 0, $before - $after ) );
		}
		return self::error( 'aism_invalid_action', 'Use action run_cron, flush_cache, flush_rewrite_rules or delete_expired_transients.' );
	}

	/** Run one already-scheduled event the way wp-cron.php does, inside this request. */
	private static function run_cron( $body ) {
		$hook = isset( $body['hook'] ) && is_string( $body['hook'] ) ? $body['hook'] : '';
		$key = isset( $body['key'] ) && is_string( $body['key'] ) ? $body['key'] : '';
		$timestamp = isset( $body['timestamp'] ) ? (int) $body['timestamp'] : 0;
		if ( '' === $hook ) { return self::error( 'aism_invalid_cron', 'Provide the hook of a scheduled event from /diagnostics/cron.' ); }
		$matches = array();
		foreach ( self::cron_events() as $event ) {
			if ( $event['hook'] === $hook && ( '' === $key || $event['key'] === $key ) && ( ! $timestamp || $event['timestamp'] === $timestamp ) ) { $matches[] = $event; }
		}
		if ( ! $matches ) { return self::error( 'aism_cron_missing', 'No scheduled event matches. Only events listed by /diagnostics/cron can be run.', 404 ); }
		if ( count( array_unique( array_column( $matches, 'key' ) ) ) > 1 ) { return self::error( 'aism_cron_ambiguous', 'Several events use this hook with different arguments; pass key from /diagnostics/cron.', 409 ); }
		$event = $matches[0];
		$crons = _get_cron_array();
		$stored = $crons[ $event['timestamp'] ][ $hook ][ $event['key'] ];
		$args = $stored['args'] ?? array();
		if ( ! empty( $stored['schedule'] ) ) { wp_reschedule_event( $event['timestamp'], $stored['schedule'], $hook, $args ); }
		wp_unschedule_event( $event['timestamp'], $hook, $args );
		$user = get_current_user_id();
		wp_set_current_user( 0 ); // Cron callbacks normally run without a signed-in user.
		$doing = function () { return true; };
		add_filter( 'wp_doing_cron', $doing );
		$start = microtime( true );
		$error = null;
		ob_start();
		try { do_action_ref_array( $hook, $args ); }
		catch ( Throwable $exception ) { $error = get_class( $exception ) . ': ' . $exception->getMessage(); }
		finally {
			$output = (string) ob_get_clean();
			remove_filter( 'wp_doing_cron', $doing );
			wp_set_current_user( $user );
		}
		$next = wp_next_scheduled( $hook, $args );
		return array(
			'action' => 'run_cron', 'hook' => $hook, 'key' => $event['key'], 'scheduled_for_utc' => $event['next_run_utc'], 'had_callbacks' => $event['has_callbacks'],
			'elapsed_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ), 'error' => $error, 'output' => strlen( $output ) > 4000 ? substr( $output, 0, 4000 ) . '…' : $output,
			'next_run_utc' => $next ? gmdate( 'Y-m-d H:i:s', $next ) : null,
		);
	}
}
