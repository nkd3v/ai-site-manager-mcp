<?php
// Run last, on a disposable local installation: this intentionally deletes all API keys.
if ( PHP_SAPI !== 'cli' ) { exit; }
require $argv[1] ?? '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Disposable local WordPress required.' ); }
$checks = 0;
function lifecycle_check( $condition, $message ) {
	global $checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$checks;
}
global $wpdb;
$table = AISM_Store::table( 'audit' );
$wpdb->insert( $table, array( 'key_id' => 0, 'user_id' => 0, 'method' => 'GET', 'route' => '/retention-fixture', 'status' => 200, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ) );
$old_id = $wpdb->insert_id;
AISM_Store::cleanup();
lifecycle_check( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id = %d", $old_id ) ), 'Audit retention cleanup failed' );
AISM_Migration::site_uuid();
lifecycle_check( (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( AISM_Store::table( 'migration_map' ) ) ) ), 'Migration map table missing' );
$title = get_option( 'blogname' );
$post_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
lifecycle_check( (bool) wp_next_scheduled( 'aism_cleanup' ), 'Cleanup was not scheduled' );
define( 'WP_UNINSTALL_PLUGIN', 'ai-site-manager/ai-site-manager.php' );
require AISM_DIR . 'uninstall.php';
lifecycle_check( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( AISM_Store::table( 'keys' ) ) ) ), 'Uninstall left keys behind' );
lifecycle_check( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( AISM_Store::table( 'audit' ) ) ) ), 'Uninstall left audit records behind' );
lifecycle_check( false === get_option( 'aism_version' ) && ! wp_next_scheduled( 'aism_cleanup' ), 'Uninstall left configuration or cron behind' );
lifecycle_check( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( AISM_Store::table( 'migration_map' ) ) ) ) && false === get_option( 'aism_site_uuid' ) && false === get_option( 'aism_migration_sources' ), 'Uninstall left migration bookkeeping behind' );
lifecycle_check( get_option( 'blogname' ) === $title && $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) === $post_count, 'Uninstall changed site content' );
AISM_Store::install();
lifecycle_check( get_option( 'aism_version' ) === AISM_VERSION && (bool) wp_next_scheduled( 'aism_cleanup' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( AISM_Store::table( 'migration_map' ) ) ) ), 'Reinstallation failed' );
echo "PASS: $checks retention/uninstall/reinstallation assertions\n";
