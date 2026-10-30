<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only database access for administrators' AI keys (database:read).
 *
 * Queries must be a single SELECT, WITH, TABLE, SHOW, DESCRIBE or EXPLAIN statement. A token
 * scan rejects comments, write/locking keywords, side-effect functions and references to the
 * user, user meta and API key tables (account credentials) or other schemas. Accepted queries
 * run inside a READ ONLY transaction with a server-side time limit, and only the requested
 * number of rows is read from the server. Password hashes and values of secret-looking
 * option/meta names are redacted on a best-effort basis.
 */
final class AISM_Database {
	const MAX_ROWS = 1000;
	const MAX_VALUE_BYTES = 65536;
	const MAX_SECONDS = 30;
	const SECRET_NAME = '/(pass(word|wd)?$|password|secret|token|api.?key|private.?key|credential|salt|auth.?key|nonce.?key|logged.?in.?key|session|license.?key)/i';
	const HASH_VALUE = '/\$(?:P|H)\$[.\/0-9A-Za-z]{31}|\$wp\$2y\$|\$2[aby]\$\d\d\$[.\/0-9A-Za-z]{53}|\$argon2(?:i|id)\$/';

	private static function error( $code, $message, $status = 400 ) { return new WP_Error( $code, $message, array( 'status' => $status ) ); }

	public static function dispatch( $request, $route ) {
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'aism_database_forbidden', 'The key owner needs manage_options.', 403 ); }
		if ( '/database/tables' === $route ) { return self::tables(); }
		if ( '/database/query' === $route ) { return self::query( $request ); }
		return self::describe( (string) $request->get_param( 'table' ) );
	}

	/** Tables whose rows hold credentials: passwords, sessions, application passwords and API key hashes. */
	public static function blocked_tables() {
		global $wpdb;
		$tables = array( $wpdb->prefix . 'users', $wpdb->prefix . 'usermeta', $wpdb->base_prefix . 'users', $wpdb->base_prefix . 'usermeta', AISM_Store::table( 'keys' ) );
		return array_values( array_unique( array_map( 'strtolower', $tables ) ) );
	}

	private static function site_tables() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A );
		$names = array();
		foreach ( (array) $rows as $row ) { $names[ $row['Name'] ] = $row; }
		// Multisite users tables live under the network prefix.
		if ( $wpdb->base_prefix !== $wpdb->prefix ) {
			foreach ( array( 'users', 'usermeta' ) as $global ) {
				$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->base_prefix . $global ), ARRAY_A );
				if ( $row ) { $names[ $row['Name'] ] = $row; }
			}
		}
		return $names;
	}

	private static function tables() {
		global $wpdb;
		$core = array_map( 'strtolower', array_values( $wpdb->tables( 'all', true ) ) );
		$blocked = self::blocked_tables();
		$result = array();
		foreach ( self::site_tables() as $name => $row ) {
			$lower = strtolower( $name );
			$result[] = array(
				'name' => $name, 'core' => in_array( $lower, $core, true ), 'query_blocked' => in_array( $lower, $blocked, true ),
				'engine' => $row['Engine'], 'approx_rows' => null === $row['Rows'] ? null : (int) $row['Rows'],
				'data_bytes' => (int) $row['Data_length'], 'index_bytes' => (int) $row['Index_length'], 'auto_increment' => null === $row['Auto_increment'] ? null : (int) $row['Auto_increment'],
				'collation' => $row['Collation'], 'updated_at' => $row['Update_time'],
			);
		}
		return array( 'prefix' => $wpdb->prefix, 'server' => $wpdb->db_server_info(), 'tables' => $result,
			'note' => 'approx_rows comes from table statistics; use aism_db_table for an exact count. Tables with query_blocked hold credentials: use the users API instead.' );
	}

	private static function describe( $table ) {
		global $wpdb;
		$tables = self::site_tables();
		if ( ! isset( $tables[ $table ] ) ) { return self::error( 'aism_table_missing', 'Unknown table. Use a name from /database/tables.', 404 ); }
		$quoted = '`' . str_replace( '`', '``', $table ) . '`';
		$columns = array();
		foreach ( (array) $wpdb->get_results( "SHOW FULL COLUMNS FROM {$quoted}", ARRAY_A ) as $column ) {
			$columns[] = array( 'name' => $column['Field'], 'type' => $column['Type'], 'nullable' => 'YES' === $column['Null'], 'key' => $column['Key'], 'default' => $column['Default'], 'extra' => $column['Extra'], 'comment' => $column['Comment'] );
		}
		$indexes = array();
		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$quoted}", ARRAY_A ) as $index ) {
			$indexes[ $index['Key_name'] ]['unique'] = '0' === (string) $index['Non_unique'];
			$indexes[ $index['Key_name'] ]['columns'][] = $index['Column_name'];
		}
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$quoted}" );
		return array( 'name' => $table, 'query_blocked' => in_array( strtolower( $table ), self::blocked_tables(), true ), 'rows' => null === $count ? null : (int) $count, 'columns' => $columns, 'indexes' => $indexes );
	}

	/**
	 * Tokenize enough SQL to validate it: words and identifiers outside string literals.
	 * Returns array( words => upper-case bare words, idents => lower-case names ) or WP_Error.
	 */
	public static function scan( $sql ) {
		$words = array();
		$idents = array();
		$len = strlen( $sql );
		if ( false !== strpos( $sql, '\\' ) ) { return self::error( 'aism_sql_rejected', 'Backslashes are not allowed; use LIKE ... ESCAPE with another character.' ); }
		for ( $i = 0; $i < $len; ) {
			$c = $sql[ $i ];
			$next = $i + 1 < $len ? $sql[ $i + 1 ] : '';
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$value = '';
				for ( $j = $i + 1; $j < $len; $j++ ) {
					if ( $sql[ $j ] === $c ) {
						if ( $j + 1 < $len && $sql[ $j + 1 ] === $c ) { $value .= $c; $j++; continue; }
						break;
					}
					$value .= $sql[ $j ];
				}
				if ( $j >= $len ) { return self::error( 'aism_sql_rejected', 'Unterminated quoted string or identifier.' ); }
				// Double quotes are identifiers under ANSI_QUOTES, so check them as names too.
				if ( "'" !== $c ) { $idents[] = strtolower( $value ); }
				$i = $j + 1;
				continue;
			}
			if ( '#' === $c || ( '-' === $c && '-' === $next ) || ( '/' === $c && '*' === $next ) ) { return self::error( 'aism_sql_rejected', 'SQL comments are not allowed.' ); }
			if ( ';' === $c ) {
				if ( '' !== trim( substr( $sql, $i + 1 ) ) ) { return self::error( 'aism_sql_rejected', 'Send exactly one statement.' ); }
				break;
			}
			if ( ':' === $c && '=' === $next ) { return self::error( 'aism_sql_rejected', 'Variable assignment is not allowed.' ); }
			if ( preg_match( '/[A-Za-z0-9_$@]/', $c ) ) {
				preg_match( '/[A-Za-z0-9_$@]+/A', $sql, $match, 0, $i );
				$word = $match[0];
				if ( ! ctype_digit( $word[0] ) ) { $words[] = strtoupper( $word ); }
				$idents[] = strtolower( $word );
				$i += strlen( $word );
				continue;
			}
			$i++;
		}
		return array( 'words' => $words, 'idents' => $idents );
	}

	/** Validate a read-only query; returns true or WP_Error explaining the rejection. */
	public static function validate( $sql ) {
		$scan = self::scan( $sql );
		if ( is_wp_error( $scan ) ) { return $scan; }
		$words = $scan['words'];
		if ( ! $words ) { return self::error( 'aism_sql_rejected', 'Empty query.' ); }
		if ( ! in_array( $words[0], array( 'SELECT', 'WITH', 'TABLE', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN' ), true ) ) {
			return self::error( 'aism_sql_rejected', 'Only SELECT, WITH, TABLE, SHOW, DESCRIBE and EXPLAIN queries are allowed.' );
		}
		if ( 'SHOW' === $words[0] ) {
			$show = implode( ' ', array_slice( $words, 1, 2 ) );
			if ( ! preg_match( '/^(?:FULL (?:TABLES|COLUMNS|FIELDS)|TABLES|COLUMNS|FIELDS|INDEX|INDEXES|KEYS|CREATE TABLE|CREATE VIEW|TABLE STATUS|VARIABLES|(?:GLOBAL|SESSION) (?:VARIABLES|STATUS)|STATUS|WARNINGS|ERRORS|ENGINES|COLLATION)\b/', $show ) ) {
				return self::error( 'aism_sql_rejected', 'This SHOW statement is not allowed. Allowed: TABLES, COLUMNS, INDEX, CREATE TABLE/VIEW, TABLE STATUS, VARIABLES, STATUS, WARNINGS, ERRORS, ENGINES, COLLATION.' );
			}
		}
		$forbidden = array( 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'UPSERT', 'MERGE', 'CREATE', 'DROP', 'ALTER', 'TRUNCATE', 'RENAME', 'GRANT', 'REVOKE', 'LOCK', 'UNLOCK', 'CALL', 'DO', 'HANDLER', 'LOAD', 'SET', 'INTO', 'OUTFILE', 'DUMPFILE', 'KILL', 'FLUSH', 'PURGE', 'RESET', 'SHUTDOWN', 'RESTART', 'INSTALL', 'UNINSTALL', 'PREPARE', 'EXECUTE', 'DEALLOCATE', 'BEGIN', 'COMMIT', 'ROLLBACK', 'START', 'SAVEPOINT', 'RELEASE', 'XA', 'OPTIMIZE', 'REPAIR', 'ANALYZE', 'CHECKSUM', 'CACHE', 'IMPORT', 'CLONE', 'SIGNAL', 'RESIGNAL', 'BINLOG',
			'SLEEP', 'BENCHMARK', 'GET_LOCK', 'RELEASE_LOCK', 'RELEASE_ALL_LOCKS', 'IS_FREE_LOCK', 'IS_USED_LOCK', 'LOAD_FILE', 'MASTER_POS_WAIT', 'SOURCE_POS_WAIT', 'MASTER_GTID_WAIT', 'WAIT_FOR_EXECUTED_GTID_SET', 'WAIT_UNTIL_SQL_THREAD_AFTER_GTIDS', 'SYS_EXEC', 'SYS_EVAL', 'NEXTVAL', 'SETVAL', 'LASTVAL', 'NEXT', 'PROCEDURE' );
		// SHOW CREATE TABLE/VIEW only reads a definition; check the words after it.
		$checked = 'SHOW' === $words[0] && 'CREATE' === ( $words[1] ?? '' ) ? array_slice( $words, 3 ) : $words;
		$bad = array_values( array_intersect( $checked, $forbidden ) );
		if ( $bad ) { return self::error( 'aism_sql_rejected', 'Read-only queries cannot use ' . $bad[0] . '.' ); }
		global $wpdb;
		// Other databases on the same server belong to other sites or applications.
		$schemas = array_diff( array_map( 'strtolower', (array) $wpdb->get_col( 'SHOW DATABASES' ) ), array( strtolower( DB_NAME ), 'information_schema' ) );
		$denied = array_merge( self::blocked_tables(), array( 'mysql', 'performance_schema', 'sys', 'processlist' ), $schemas );
		$hit = array_values( array_intersect( $scan['idents'], $denied ) );
		if ( $hit ) {
			return self::error( 'aism_sql_protected', 'Queries cannot reference ' . $hit[0] . '. Only this site\'s database can be read, and user accounts, sessions and API keys are protected; review accounts with the users API.', 403 );
		}
		return true;
	}

	private static function query( $request ) {
		global $wpdb;
		$body = $request->get_json_params();
		$sql = is_array( $body ) && isset( $body['sql'] ) && is_string( $body['sql'] ) ? trim( $body['sql'] ) : '';
		if ( '' === $sql || strlen( $sql ) > 20000 ) { return self::error( 'aism_invalid_sql', 'Send {"sql": "SELECT ..."} with at most 20000 characters.' ); }
		$max_rows = isset( $body['max_rows'] ) ? (int) $body['max_rows'] : 100;
		$max_bytes = isset( $body['max_value_bytes'] ) ? (int) $body['max_value_bytes'] : 4096;
		$seconds = isset( $body['timeout_seconds'] ) ? (int) $body['timeout_seconds'] : 10;
		if ( $max_rows < 1 || $max_rows > self::MAX_ROWS || $max_bytes < 16 || $max_bytes > self::MAX_VALUE_BYTES || $seconds < 1 || $seconds > self::MAX_SECONDS ) {
			return self::error( 'aism_invalid_limits', 'max_rows must be 1–1000, max_value_bytes 16–65536 and timeout_seconds 1–30.' );
		}
		AISM_Store::note( 'SQL: ' . $sql );
		$valid = self::validate( $sql );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$dbh = $wpdb->dbh;
		if ( ! $dbh instanceof mysqli ) { return self::error( 'aism_database_unsupported', 'Read-only queries need the standard MySQL/MariaDB connection; a database drop-in replaces it on this site.', 501 ); }

		$maria = false !== stripos( (string) $wpdb->db_server_info(), 'mariadb' );
		$variable = $maria ? 'max_statement_time' : 'max_execution_time';
		$previous = $wpdb->get_var( "SELECT @@SESSION.{$variable}" );
		if ( null !== $previous ) { $wpdb->query( $wpdb->prepare( "SET SESSION {$variable} = %d", $maria ? $seconds : $seconds * 1000 ) ); }
		$started = false;
		$start = microtime( true );
		try {
			$started = false !== $wpdb->query( 'START TRANSACTION READ ONLY' );
			if ( ! $started ) { return self::error( 'aism_database_unsupported', 'The database refused a read-only transaction.', 501 ); }
			try {
				$result = mysqli_query( $dbh, $sql, MYSQLI_USE_RESULT );
			} catch ( mysqli_sql_exception $exception ) {
				return self::error( 'aism_sql_error', $exception->getMessage(), 400 );
			}
			if ( false === $result ) { return self::error( 'aism_sql_error', mysqli_error( $dbh ), 400 ); }
			if ( true === $result ) { return array( 'columns' => array(), 'rows' => array(), 'row_count' => 0, 'truncated' => false ); }
			$columns = array();
			foreach ( mysqli_fetch_fields( $result ) as $field ) { $columns[] = $field->name; }
			$rows = array();
			$more = false;
			try {
				while ( $row = mysqli_fetch_row( $result ) ) {
					if ( count( $rows ) >= $max_rows ) { $more = true; break; }
					$rows[] = $row;
				}
			} finally { mysqli_free_result( $result ); }
			$stats = array( 'values_truncated' => 0, 'redacted' => 0 );
			$rows = self::clean( $columns, $rows, $max_bytes, $stats );
			return array(
				'columns' => $columns, 'rows' => $rows, 'row_count' => count( $rows ), 'truncated' => $more,
				'values_truncated' => $stats['values_truncated'], 'redacted_values' => $stats['redacted'], 'elapsed_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
			);
		} finally {
			if ( $started ) { $wpdb->query( 'ROLLBACK' ); }
			if ( null !== $previous ) { $wpdb->query( $maria ? $wpdb->prepare( "SET SESSION {$variable} = %f", (float) $previous ) : $wpdb->prepare( "SET SESSION {$variable} = %d", (int) $previous ) ); }
		}
	}

	/** Cut to at most $bytes without splitting a UTF-8 character. */
	private static function cut( $value, $bytes, $utf8 ) {
		$value = substr( $value, 0, $bytes );
		for ( $i = 0; $utf8 && $i < 3 && '' !== $value && '' === wp_check_invalid_utf8( $value ); $i++ ) { $value = substr( $value, 0, -1 ); }
		return $value;
	}

	/** Bound value sizes, keep JSON valid for binary data and redact obvious secrets. */
	private static function clean( $columns, $rows, $max_bytes, &$stats ) {
		$lower = array_map( 'strtolower', $columns );
		$name_columns = array_keys( array_intersect( $lower, array( 'option_name', 'meta_key', 'name', 'setting', 'key' ) ) );
		$value_columns = array_keys( array_intersect( $lower, array( 'option_value', 'meta_value', 'value' ) ) );
		$secret_columns = array_keys( array_intersect( $lower, array( 'user_pass', 'user_activation_key', 'key_hash', 'session_tokens' ) ) );
		foreach ( $rows as &$row ) {
			$secret_row = false;
			foreach ( $name_columns as $index ) {
				if ( is_string( $row[ $index ] ) && preg_match( self::SECRET_NAME, $row[ $index ] ) ) { $secret_row = true; }
			}
			foreach ( $row as $index => &$value ) {
				if ( null === $value ) { continue; }
				if ( in_array( $index, $secret_columns, true ) || ( $secret_row && in_array( $index, $value_columns, true ) ) || preg_match( self::HASH_VALUE, $value ) ) {
					$value = '[redacted]';
					$stats['redacted']++;
					continue;
				}
				$utf8 = '' === $value || '' !== wp_check_invalid_utf8( $value );
				if ( strlen( $value ) > $max_bytes ) {
					$value = self::cut( $value, $max_bytes, $utf8 );
					$stats['values_truncated']++;
				}
				if ( ! $utf8 ) { $value = 'base64:' . base64_encode( $value ); }
			}
			unset( $value );
		}
		unset( $row );
		return $rows;
	}
}
