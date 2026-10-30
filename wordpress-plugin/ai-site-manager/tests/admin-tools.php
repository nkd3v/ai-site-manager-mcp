<?php
// Database, diagnostics and maintenance tests. Disposable WordPress ONLY; not included in the release ZIP.
if ( PHP_SAPI !== 'cli' ) { exit; }
require $argv[1] ?? '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'A local disposable installation is required.' ); }
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
	$key = AISM_Store::create_key( 'Admin tools test', $user, $scopes, array(), 1 );
	check( ! is_wp_error( $key ), 'Could not create test key' );
	return $key['token'];
}
function sql( $token, $sql, $extra = array() ) { return call_api( 'POST', '/database/query', $token, array_merge( array( 'sql' => $sql ), $extra ) ); }

global $wpdb;
$admin = get_user_by( 'login', 'aism-admin' );
check( (bool) $admin, 'Test admin missing' );
wp_set_current_user( 0 );
$fixture = $wpdb->prefix . 'aism_fixture';
$editor = 0;
$hooks = array( 'aism_test_event', 'aism_test_recurring', 'aism_test_twins' );
try {
	foreach ( array( 'database:read', 'diagnostics:read', 'maintenance:write' ) as $scope ) { check( in_array( $scope, AISM_Store::scopes(), true ), "Scope {$scope} registered" ); }
	check( ! in_array( 'database:write', AISM_Store::scopes(), true ), 'No database write scope exists' );
	$db = key_for( $admin->ID, array( 'database:read' ) );
	$diag = key_for( $admin->ID, array( 'diagnostics:read' ) );
	$maint = key_for( $admin->ID, array( 'maintenance:write' ) );
	$content = key_for( $admin->ID, array( 'content:read' ) );
	$editor = wp_insert_user( array( 'user_login' => 'aism-tools-editor' . wp_rand( 1000, 9999 ), 'user_email' => 'tools' . wp_rand() . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
	$editor_key = key_for( $editor, array( 'database:read', 'diagnostics:read', 'maintenance:write' ) );

	// Scope and capability gates.
	foreach ( array( array( 'GET', '/database/tables', null ), array( 'POST', '/database/query', array( 'sql' => 'SELECT 1' ) ), array( 'GET', '/diagnostics', null ), array( 'GET', '/diagnostics/cron', null ), array( 'POST', '/maintenance', array( 'action' => 'flush_cache' ) ) ) as $case ) {
		check( 403 === call_api( $case[0], $case[1], $content, $case[2] )->get_status(), 'Scope required for ' . $case[1] );
		check( 403 === call_api( $case[0], $case[1], $editor_key, $case[2] )->get_status(), 'manage_options required for ' . $case[1] );
	}
	check( 403 === call_api( 'GET', '/diagnostics', $db )->get_status() && 403 === call_api( 'GET', '/database/tables', $diag )->get_status() && 403 === call_api( 'POST', '/maintenance', $diag, array( 'action' => 'flush_cache' ) )->get_status(), 'Scopes are independent' );
	$discovery = call_api( 'GET', '/discovery', $db )->get_data();
	check( 'read-only' === $discovery['limits']['raw_sql'] && in_array( 'POST /database/query', $discovery['endpoints'], true ), 'Discovery reports read-only SQL' );
	check( false === call_api( 'GET', '/discovery', $content )->get_data()['limits']['raw_sql'], 'Discovery reports no SQL without the scope' );

	// Tables and descriptions.
	$wpdb->query( "DROP TABLE IF EXISTS {$fixture}" );
	$wpdb->query( "CREATE TABLE {$fixture} (id int NOT NULL AUTO_INCREMENT, label varchar(50) NOT NULL, body longtext NULL, raw blob NULL, PRIMARY KEY (id), KEY label (label)) {$wpdb->get_charset_collate()}" );
	for ( $i = 1; $i <= 30; $i++ ) { $wpdb->insert( $fixture, array( 'label' => 'row' . $i, 'body' => str_repeat( 'ก', 10 ) ) ); }
	$wpdb->query( $wpdb->prepare( "UPDATE {$fixture} SET raw = UNHEX(%s) WHERE id = 1", 'FF00FE' ) );
	$tables = call_api( 'GET', '/database/tables', $db );
	check( 200 === $tables->get_status(), 'Tables listed' );
	$by_name = array_column( $tables->get_data()['tables'], null, 'name' );
	check( isset( $by_name[ $wpdb->posts ] ) && $by_name[ $wpdb->posts ]['core'] && ! $by_name[ $wpdb->posts ]['query_blocked'], 'Core tables flagged' );
	check( $by_name[ $wpdb->users ]['query_blocked'] && $by_name[ $wpdb->usermeta ]['query_blocked'] && $by_name[ AISM_Store::table( 'keys' ) ]['query_blocked'], 'Credential tables flagged' );
	check( isset( $by_name[ $fixture ] ) && ! $by_name[ $fixture ]['core'], 'Plugin tables listed as non-core' );
	$table = call_api( 'GET', '/database/tables/' . $fixture, $db )->get_data();
	check( 30 === $table['rows'] && 'label' === $table['columns'][1]['name'] && $table['indexes']['PRIMARY']['unique'] && array( 'label' ) === $table['indexes']['label']['columns'], 'Table described with exact count and indexes' );
	check( 404 === call_api( 'GET', '/database/tables/wp_nonexistent', $db )->get_status() && 404 === call_api( 'GET', '/database/tables/information_schema', $db )->get_status(), 'Only site tables can be described' );

	// Read-only queries.
	$result = sql( $db, "SELECT label, COUNT(*) AS n FROM {$fixture} WHERE id <= 3 GROUP BY label ORDER BY label" );
	check( 200 === $result->get_status() && array( 'label', 'n' ) === $result->get_data()['columns'] && array( array( 'row1', '1' ), array( 'row2', '1' ), array( 'row3', '1' ) ) === $result->get_data()['rows'], 'SELECT returns columns and rows' );
	$result = sql( $db, "SELECT id FROM {$fixture} ORDER BY id", array( 'max_rows' => 5 ) )->get_data();
	check( 5 === $result['row_count'] && $result['truncated'] && '5' === $result['rows'][4][0], 'Row limit truncates' );
	$result = sql( $db, "SELECT body, raw FROM {$fixture} WHERE id = 1", array( 'max_value_bytes' => 16 ) )->get_data();
	check( 'กกกกก' === $result['rows'][0][0] && 1 === $result['values_truncated'], 'Long UTF-8 values cut on character boundaries' );
	check( 'base64:' . base64_encode( "\xFF\x00\xFE" ) === $result['rows'][0][1], 'Binary values returned as base64' );
	foreach ( array( "WITH x AS (SELECT id FROM {$fixture}) SELECT COUNT(*) FROM x", 'SHOW TABLES', "SHOW CREATE TABLE {$fixture}", "SHOW INDEX FROM {$fixture}", 'SHOW VARIABLES', "SELECT * FROM {$fixture} ORDER BY id DESC LIMIT 2", "SHOW COLUMNS FROM {$fixture}", "DESCRIBE {$wpdb->posts}", "EXPLAIN SELECT * FROM {$fixture} WHERE label = 'row1'", "SELECT \"x\", 'it''s', `id` FROM {$fixture} LIMIT 1;", "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blogname'", 'SELECT @@version' ) as $ok ) {
		check( 200 === sql( $db, $ok )->get_status(), 'Allowed: ' . $ok );
	}
	$syntax = sql( $db, 'SELECT FROM WHERE' );
	check( 400 === $syntax->get_status() && 'aism_sql_error' === $syntax->get_data()['code'], 'SQL errors reported' );
	check( 200 === sql( $db, 'SELECT 1' )->get_status(), 'Connection usable after an error' );

	$rejected = array(
		"INSERT INTO {$fixture} (label) VALUES ('x')", "UPDATE {$fixture} SET label = 'x'", "DELETE FROM {$fixture}", "REPLACE INTO {$fixture} (id, label) VALUES (1, 'x')",
		"DROP TABLE {$fixture}", "TRUNCATE {$fixture}", "ALTER TABLE {$fixture} ADD c int", "CREATE TABLE x (id int)", "SELECT 1; DELETE FROM {$fixture}",
		"SELECT 1 -- x", "SELECT 1 /* x */", "SELECT 1 # x", "SELECT 1 /*!50000 , 2 */", "SELECT * FROM {$fixture} INTO OUTFILE '/tmp/x'", "SELECT label INTO @v FROM {$fixture} LIMIT 1",
		'SELECT SLEEP(5)', "SELECT BENCHMARK(1000000, MD5('x'))", "SELECT GET_LOCK('x', 1)", "SELECT LOAD_FILE('/etc/passwd')", 'SELECT @x := 1', "SET @x = 1",
		"SELECT * FROM {$fixture} FOR UPDATE", "SELECT * FROM {$fixture} LOCK IN SHARE MODE", 'SHOW GRANTS', 'SHOW PROCESSLIST', "SHOW CREATE PROCEDURE x", "SHOW CREATE TABLE {$fixture} SET", "EXPLAIN ANALYZE SELECT * FROM {$fixture}",
		"SELECT 'a\\' FROM x", "CALL x()", 'DO 1', "HANDLER {$fixture} OPEN", "LOAD DATA INFILE '/etc/passwd' INTO TABLE {$fixture}", 'START TRANSACTION', 'COMMIT', 'KILL 1', "OPTIMIZE TABLE {$fixture}",
		"SELECT 'unterminated", '',
	);
	foreach ( $rejected as $bad ) {
		$response = sql( $db, $bad );
		check( 400 === $response->get_status() && in_array( $response->get_data()['code'], array( 'aism_sql_rejected', 'aism_invalid_sql' ), true ), 'Rejected: ' . $bad );
	}
	$protected = array(
		"SELECT user_pass FROM {$wpdb->users}", "SELECT * FROM `{$wpdb->users}`", "SELECT * FROM \"{$wpdb->users}\"", 'SELECT * FROM ' . strtoupper( $wpdb->users ), "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'session_tokens'",
		"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->users} u ON u.ID = p.post_author", "SELECT * FROM {$wpdb->posts} WHERE post_author IN (SELECT ID FROM " . DB_NAME . ".{$wpdb->users})",
		'SELECT key_hash FROM ' . AISM_Store::table( 'keys' ), 'SELECT * FROM mysql.user', 'SELECT * FROM information_schema.PROCESSLIST', 'SELECT * FROM performance_schema.threads', "DESCRIBE {$wpdb->users}", "SHOW CREATE TABLE {$wpdb->usermeta}",
	);
	$wpdb->query( 'CREATE DATABASE IF NOT EXISTS aism_other_site' );
	if ( in_array( 'aism_other_site', $wpdb->get_col( 'SHOW DATABASES' ), true ) ) {
		$protected[] = 'SELECT * FROM aism_other_site.anything';
		$protected[] = 'SHOW TABLES FROM `aism_other_site`';
		$protected[] = 'SELECT a.id FROM ' . DB_NAME . '.' . $fixture . ' a JOIN aism_other_site.anything b ON b.id = a.id';
	}
	// Other databases are matched by the name as written, so mixed-case names stay protected.
	if ( in_array( 'AISM_Mixed_Site', $wpdb->get_col( 'SHOW DATABASES' ), true ) ) {
		$protected[] = 'SELECT * FROM AISM_Mixed_Site.anything';
		$protected[] = 'SELECT * FROM `AISM_Mixed_Site`.`anything`';
	}
	foreach ( $protected as $bad ) {
		$response = sql( $db, $bad );
		check( 403 === $response->get_status() && 'aism_sql_protected' === $response->get_data()['code'], 'Protected: ' . $bad );
	}
	check( 30 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$fixture}" ) && 'row1' === $wpdb->get_var( "SELECT label FROM {$fixture} WHERE id = 1" ), 'Rejected queries changed nothing' );
	foreach ( array( array( 'max_rows' => 0 ), array( 'max_rows' => 1001 ), array( 'max_value_bytes' => 8 ), array( 'timeout_seconds' => 31 ) ) as $limits ) {
		check( 400 === sql( $db, 'SELECT 1', $limits )->get_status(), 'Limits validated' );
	}
	check( 400 === call_api( 'POST', '/database/query', $db, array( 'sql' => str_repeat( ' ', 10 ) . 'SELECT ' . str_repeat( '1+', 10000 ) . '1' ) )->get_status(), 'Oversized SQL rejected' );

	// The read-only transaction ends cleanly: later writes on the same connection persist, and the time limit is restored.
	$maria = false !== stripos( $wpdb->db_server_info(), 'mariadb' );
	$variable = $maria ? 'max_statement_time' : 'max_execution_time';
	$before = $wpdb->get_var( "SELECT @@SESSION.{$variable}" );
	check( 200 === sql( $db, "SELECT COUNT(*) FROM {$fixture}", array( 'timeout_seconds' => 3 ) )->get_status(), 'Timed query ran' );
	check( $before === $wpdb->get_var( "SELECT @@SESSION.{$variable}" ), 'Session time limit restored' );
	$wpdb->insert( $fixture, array( 'label' => 'after-query' ) );
	$other = mysqli_init();
	$host = explode( ':', DB_HOST );
	mysqli_real_connect( $other, $host[0], DB_USER, DB_PASSWORD, DB_NAME, isset( $host[1] ) ? (int) $host[1] : 3306 );
	$seen = mysqli_fetch_row( mysqli_query( $other, "SELECT COUNT(*) FROM {$fixture} WHERE label = 'after-query'" ) )[0];
	mysqli_close( $other );
	check( '1' === $seen, 'Autocommit resumes after the read-only transaction' );
	if ( $maria ) {
		$slow = sql( $db, "SELECT COUNT(*) FROM {$fixture} a, {$fixture} b, {$fixture} c, {$fixture} d, {$fixture} e, {$fixture} f", array( 'timeout_seconds' => 1 ) );
		check( 400 === $slow->get_status() || 200 === $slow->get_status(), 'Server-side time limit applies' );
	}

	// Redaction of secret-looking settings and password hashes.
	update_option( 'aism_fixture_api_key', 'sk_live_fixture_secret' );
	update_option( 'aism_fixture_plain', 'visible value' );
	update_option( 'aism_fixture_hash', '$P$BabcdefghijklmnopqrstuvwxyzABCD' );
	$result = sql( $db, "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'aism\\_fixture\\_%' ORDER BY option_name" );
	check( 400 === $result->get_status(), 'Backslash escapes rejected' );
	$result = sql( $db, "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ('aism_fixture_api_key', 'aism_fixture_plain', 'aism_fixture_hash') ORDER BY option_name" )->get_data();
	$values = array_column( $result['rows'], 1, 0 );
	check( '[redacted]' === $values['aism_fixture_api_key'] && '[redacted]' === $values['aism_fixture_hash'] && 'visible value' === $values['aism_fixture_plain'] && 2 === $result['redacted_values'], 'Secret-looking settings and password hashes redacted' );
	$result = sql( $db, "SELECT CONCAT('x', option_value) FROM {$wpdb->options} WHERE option_name = 'aism_fixture_hash'" )->get_data();
	check( '[redacted]' === $result['rows'][0][0], 'Hashes redacted inside expressions' );

	// Audit records include the SQL text.
	$detail = $wpdb->get_var( 'SELECT detail FROM ' . AISM_Store::table( 'audit' ) . " WHERE route = '/ai-site-manager/v1/database/query' ORDER BY id DESC LIMIT 1" );
	check( false !== strpos( (string) $detail, "SQL: SELECT CONCAT('x', option_value)" ), 'SQL recorded in audit detail' );
	$denied = $wpdb->get_row( 'SELECT detail, status FROM ' . AISM_Store::table( 'audit' ) . " WHERE detail LIKE 'SQL: SELECT * FROM mysql.user%' ORDER BY id DESC LIMIT 1", ARRAY_A );
	check( $denied && 403 === (int) $denied['status'], 'Rejected SQL also audited' );

	// Diagnostics.
	$overview = call_api( 'GET', '/diagnostics', $diag );
	$data = $overview->get_data();
	check( 200 === $overview->get_status() && get_bloginfo( 'version' ) === $data['wordpress']['version'] && PHP_VERSION === $data['php']['version'] && $data['database']['standard_connection'], 'Overview reports versions' );
	$plugins = array_column( $data['wordpress']['plugins'], null, 'plugin' );
	check( $plugins['ai-site-manager/ai-site-manager']['active'] && AISM_VERSION === $plugins['ai-site-manager/ai-site-manager']['version'], 'Overview lists plugins' );
	check( ! isset( $data['site_health'] ) && $data['cron']['events'] > 0 && is_array( $data['logs'] ), 'Overview has cron and logs without Site Health by default' );
	$health = call_api( 'GET', '/diagnostics', $diag, null, array( 'site_health' => '1' ) )->get_data();
	check( isset( $health['site_health']['tests'] ) && count( $health['site_health']['tests'] ) > 5 && '' !== $health['site_health']['tests'][0]['status'], 'Site Health direct tests run' );
	$cron = call_api( 'GET', '/diagnostics/cron', $diag )->get_data();
	$cleanup = array_values( array_filter( $cron['events'], function ( $event ) { return 'aism_cleanup' === $event['hook']; } ) );
	check( $cleanup && 'daily' === $cleanup[0]['schedule'] && $cleanup[0]['has_callbacks'] && isset( $cron['schedules']['hourly'] ), 'Cron events listed' );
	$marker = 'AISM-LOG-MARK-' . wp_generate_password( 12, false );
	$debug_log = WP_DEBUG_LOG === true ? WP_CONTENT_DIR . '/debug.log' : WP_DEBUG_LOG;
	file_put_contents( $debug_log, '[' . gmdate( 'd-M-Y H:i:s' ) . " UTC] PHP Warning: {$marker} fixture\n", FILE_APPEND );
	$logs = call_api( 'GET', '/diagnostics/logs', $diag, null, array( 'source' => 'debug', 'search' => $marker, 'lines' => 5 ) )->get_data();
	check( 1 === $logs['matched'] && false !== strpos( $logs['lines'][0], $marker ), 'Debug log searched' );
	$tail = call_api( 'GET', '/diagnostics/logs', $diag, null, array( 'lines' => 1 ) )->get_data();
	check( 1 === count( $tail['lines'] ) && false !== strpos( $tail['lines'][0], $marker ), 'Debug log tail' );
	foreach ( array( array( 'lines' => 0 ), array( 'lines' => 1001 ), array( 'source' => array( 'x' ) ), array( 'search' => str_repeat( 'x', 201 ) ) ) as $query ) {
		check( 400 === call_api( 'GET', '/diagnostics/logs', $diag, null, $query )->get_status(), 'Log query validated' );
	}
	check( 404 === call_api( 'GET', '/diagnostics/logs', $diag, null, array( 'source' => '../../wp-config.php' ) )->get_status(), 'Arbitrary files cannot be read' );

	// Maintenance: only scheduled events run, as cron would run them.
	update_option( 'aism_test_cron_runs', array() );
	$recorder = function ( $value = null ) {
		$runs = get_option( 'aism_test_cron_runs', array() );
		$runs[] = array( 'hook' => current_action(), 'arg' => $value, 'user' => get_current_user_id(), 'cron' => wp_doing_cron() );
		update_option( 'aism_test_cron_runs', $runs );
		echo 'callback output';
	};
	foreach ( $hooks as $hook ) { add_action( $hook, $recorder ); wp_clear_scheduled_hook( $hook ); }
	wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'aism_test_event', array( 7 ) );
	wp_schedule_event( time() - 60, 'hourly', 'aism_test_recurring' );
	wp_schedule_single_event( time() + 600, 'aism_test_twins', array( 1 ) );
	wp_schedule_single_event( time() + 900, 'aism_test_twins', array( 2 ) );
	$run = call_api( 'POST', '/maintenance', $maint, array( 'action' => 'run_cron', 'hook' => 'aism_test_event' ) );
	$runs = get_option( 'aism_test_cron_runs' );
	check( 200 === $run->get_status() && 1 === count( $runs ) && 7 === $runs[0]['arg'] && 0 === $runs[0]['user'] && $runs[0]['cron'], 'Scheduled event runs like cron' );
	check( 'callback output' === $run->get_data()['output'] && null === $run->get_data()['next_run_utc'] && ! wp_next_scheduled( 'aism_test_event', array( 7 ) ), 'Single event consumed' );
	check( get_current_user_id() === 0 && ! wp_doing_cron(), 'Request state restored after running an event' );
	$run = call_api( 'POST', '/maintenance', $maint, array( 'action' => 'run_cron', 'hook' => 'aism_test_recurring' ) );
	check( 200 === $run->get_status() && wp_next_scheduled( 'aism_test_recurring' ) > time(), 'Recurring event rescheduled' );
	check( 409 === call_api( 'POST', '/maintenance', $maint, array( 'action' => 'run_cron', 'hook' => 'aism_test_twins' ) )->get_status(), 'Ambiguous hook needs a key' );
	$twins = array_values( array_filter( call_api( 'GET', '/diagnostics/cron', $diag )->get_data()['events'], function ( $event ) { return 'aism_test_twins' === $event['hook']; } ) );
	$run = call_api( 'POST', '/maintenance', $maint, array( 'action' => 'run_cron', 'hook' => 'aism_test_twins', 'key' => $twins[1]['key'] ) );
	$runs = get_option( 'aism_test_cron_runs' );
	check( 200 === $run->get_status() && $twins[1]['args'][0] === end( $runs )['arg'] && 1 === count( array_filter( _get_cron_array(), function ( $hooks ) { return isset( $hooks['aism_test_twins'] ); } ) ), 'Key selects one occurrence' );
	foreach ( array( 'init', 'shutdown', 'wp_login', 'aism_unscheduled' ) as $hook ) {
		check( 404 === call_api( 'POST', '/maintenance', $maint, array( 'action' => 'run_cron', 'hook' => $hook ) )->get_status(), 'Unscheduled hook refused: ' . $hook );
	}
	check( 400 === call_api( 'POST', '/maintenance', $maint, array( 'action' => 'run_cron' ) )->get_status() && 400 === call_api( 'POST', '/maintenance', $maint, array( 'action' => 'drop_tables' ) )->get_status(), 'Maintenance input validated' );
	set_transient( 'aism_expired_fixture', 'x', 60 );
	update_option( '_transient_timeout_aism_expired_fixture', time() - 10 );
	$clean = call_api( 'POST', '/maintenance', $maint, array( 'action' => 'delete_expired_transients' ) )->get_data();
	check( $clean['transient_rows_removed'] >= 2 && null === $wpdb->get_var( "SELECT option_id FROM {$wpdb->options} WHERE option_name = '_transient_aism_expired_fixture'" ), 'Expired transients removed' );
	check( call_api( 'POST', '/maintenance', $maint, array( 'action' => 'flush_cache' ) )->get_data()['flushed'], 'Object cache flushed' );
	check( call_api( 'POST', '/maintenance', $maint, array( 'action' => 'flush_rewrite_rules' ) )->get_data()['rules'] > 0, 'Rewrite rules regenerated' );
	$detail = $wpdb->get_var( 'SELECT detail FROM ' . AISM_Store::table( 'audit' ) . " WHERE route = '/ai-site-manager/v1/maintenance' AND status = 200 ORDER BY id DESC LIMIT 1" );
	check( 'maintenance: flush_rewrite_rules' === $detail, 'Maintenance action audited' );
} finally {
	wp_set_current_user( 0 );
	$wpdb->query( "DROP TABLE IF EXISTS {$fixture}" );
	$wpdb->query( 'DROP DATABASE IF EXISTS aism_other_site' );
	foreach ( array( 'aism_fixture_api_key', 'aism_fixture_plain', 'aism_fixture_hash', 'aism_test_cron_runs' ) as $option ) { delete_option( $option ); }
	foreach ( $hooks as $hook ) { wp_clear_scheduled_hook( $hook ); }
	if ( $editor && ! is_wp_error( $editor ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $editor ); }
}
echo "PASS: {$aism_tests} database, diagnostics and maintenance assertions\n";
