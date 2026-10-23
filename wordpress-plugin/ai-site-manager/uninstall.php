<?php
/** Remove plugin credentials and audit records; leave site content and settings intact. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
function aism_uninstall_site() {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}aism_keys`" );
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}aism_audit`" );
	delete_option( 'aism_version' );
	wp_clear_scheduled_hook( 'aism_cleanup' );
}
if ( is_multisite() ) {
	// Network activation is not supported; remove tables on sites where it was enabled individually.
	$aism_offset = 0;
	do {
		$aism_sites = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $aism_offset ) );
		foreach ( $aism_sites as $aism_site ) { switch_to_blog( $aism_site ); aism_uninstall_site(); restore_current_blog(); }
		$aism_offset += 100;
	} while ( count( $aism_sites ) === 100 );
} else { aism_uninstall_site(); }
