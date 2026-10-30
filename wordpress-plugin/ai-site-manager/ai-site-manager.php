<?php
/**
 * Plugin Name: AI Site Manager
 * Description: Scoped API keys for AI clients to manage and migrate WordPress content and approved settings, review and quarantine user accounts, with live API documentation.
 * Version: 1.6.0
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: ai-site-manager
 * Update URI: https://github.com/nkd3v/ai-site-manager-mcp
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'AISM_VERSION', '1.6.0' );
define( 'AISM_DIR', plugin_dir_path( __FILE__ ) );
require_once AISM_DIR . 'includes/class-store.php';
require_once AISM_DIR . 'includes/class-plugins.php';
require_once AISM_DIR . 'includes/class-themes.php';
require_once AISM_DIR . 'includes/class-migration.php';
require_once AISM_DIR . 'includes/class-users.php';
require_once AISM_DIR . 'includes/class-database.php';
require_once AISM_DIR . 'includes/class-diagnostics.php';
require_once AISM_DIR . 'includes/class-api.php';
require_once AISM_DIR . 'includes/class-admin.php';
require_once AISM_DIR . 'includes/class-updater.php';
AISM_Updater::init();
AISM_Users::init();
register_activation_hook( __FILE__, array( 'AISM_Store', 'install' ) );
register_deactivation_hook( __FILE__, function () { wp_clear_scheduled_hook( 'aism_cleanup' ); } );
add_action( 'plugins_loaded', function () {
	if ( get_option( 'aism_version' ) !== AISM_VERSION ) { AISM_Store::install(); }
	new AISM_API();
	if ( is_admin() ) { new AISM_Admin(); }
} );
add_action( 'aism_cleanup', array( 'AISM_Store', 'cleanup' ) );
