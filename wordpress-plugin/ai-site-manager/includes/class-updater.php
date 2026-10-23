<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Offers new GitHub releases through WordPress's normal plugin update system.
 *
 * The release must have a vX.Y.Z tag with ai-site-manager.zip and SHA256SUMS attached.
 * The ZIP is checked against SHA256SUMS before WordPress unpacks it.
 * Define AISM_DISABLE_GITHUB_UPDATES as true to turn this off.
 */
final class AISM_Updater {
	const REPO = 'nkd3v/ai-site-manager-mcp';
	const ASSET = 'ai-site-manager.zip';
	const CACHE = 'aism_github_release';

	public static function init() {
		if ( defined( 'AISM_DISABLE_GITHUB_UPDATES' ) && AISM_DISABLE_GITHUB_UPDATES ) { return; }
		// WordPress calls this for plugins whose "Update URI" header points to github.com.
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'offer' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'source' ), 10, 4 );
		add_action( 'load-update-core.php', function () { if ( isset( $_GET['force-check'] ) ) { self::clear(); } } );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_aism_check_updates', array( __CLASS__, 'check_now' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function plugin_file() { return plugin_basename( AISM_DIR . 'ai-site-manager.php' ); }

	public static function clear() { delete_site_transient( self::CACHE ); }

	/** Latest release as an array, or a WP_Error. Results are cached for 6 hours (errors for 1 hour). */
	public static function release( $force = false ) {
		$cached = $force ? false : get_site_transient( self::CACHE );
		if ( is_array( $cached ) && isset( $cached['version'] ) ) { return $cached; }
		if ( is_array( $cached ) && isset( $cached['error'] ) ) { return new WP_Error( 'aism_update_check_failed', $cached['error'] ); }
		$release = self::fetch();
		set_site_transient( self::CACHE, is_wp_error( $release ) ? array( 'error' => $release->get_error_message() ) : $release, is_wp_error( $release ) ? HOUR_IN_SECONDS : 6 * HOUR_IN_SECONDS );
		return $release;
	}

	private static function fetch() {
		$response = wp_safe_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/vnd.github+json' ) ) );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'aism_update_check_failed', 'Could not reach GitHub: ' . $response->get_error_message() ); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) { return new WP_Error( 'aism_update_check_failed', 'GitHub returned HTTP ' . $code . ' for the latest release.' ); }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['tag_name'] ) || ! is_string( $data['tag_name'] ) || ! preg_match( '/^v?(\d+\.\d+\.\d+)$/D', $data['tag_name'], $match ) ) { return new WP_Error( 'aism_update_check_failed', 'The latest GitHub release has no vX.Y.Z version tag.' ); }
		$prefix = 'https://github.com/' . self::REPO . '/releases/download/';
		$urls = array();
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( is_array( $asset ) && isset( $asset['name'], $asset['browser_download_url'] ) && is_string( $asset['browser_download_url'] ) && 0 === strpos( $asset['browser_download_url'], $prefix ) ) { $urls[ $asset['name'] ] = $asset['browser_download_url']; }
		}
		if ( ! isset( $urls[ self::ASSET ], $urls['SHA256SUMS'] ) ) { return new WP_Error( 'aism_update_check_failed', 'The latest GitHub release is missing ' . self::ASSET . ' or SHA256SUMS.' ); }
		return array(
			'version' => $match[1],
			'package' => $urls[ self::ASSET ],
			'url' => is_string( $data['html_url'] ?? null ) ? $data['html_url'] : 'https://github.com/' . self::REPO . '/releases',
			'notes' => is_string( $data['body'] ?? null ) ? $data['body'] : '',
			'published' => is_string( $data['published_at'] ?? null ) ? $data['published_at'] : '',
		);
	}

	public static function offer( $update, $plugin_data, $plugin_file ) {
		if ( self::plugin_file() !== $plugin_file ) { return $update; }
		$release = self::release();
		if ( is_wp_error( $release ) ) { return $update; }
		return array(
			'slug' => 'ai-site-manager',
			'version' => $release['version'],
			'url' => $release['url'],
			'package' => $release['package'],
		);
	}

	/** Content for the "View details" dialog. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || 'ai-site-manager' !== ( $args->slug ?? '' ) ) { return $result; }
		$release = self::release();
		if ( is_wp_error( $release ) ) { return $result; }
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$installed = get_plugin_data( AISM_DIR . 'ai-site-manager.php', false, false );
		return (object) array(
			'name' => 'AI Site Manager',
			'slug' => 'ai-site-manager',
			'version' => $release['version'],
			'homepage' => 'https://github.com/' . self::REPO,
			'download_link' => $release['package'],
			'requires' => $installed['RequiresWP'],
			'requires_php' => $installed['RequiresPHP'],
			'last_updated' => $release['published'],
			'sections' => array( 'changelog' => wpautop( esc_html( $release['notes'] ) ) . '<p><a href="' . esc_url( $release['url'] ) . '">View this release on GitHub</a></p>' ),
		);
	}

	/** Downloads the release ZIP and rejects it unless it matches the release's SHA256SUMS. */
	public static function download( $reply, $package, $upgrader, $hook_extra = array() ) {
		$prefix = 'https://github.com/' . self::REPO . '/releases/download/';
		if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, $prefix ) || self::ASSET !== basename( $package ) ) { return $reply; }
		if ( isset( $upgrader->skin ) && isset( $upgrader->strings['downloading_package'] ) ) { $upgrader->skin->feedback( 'downloading_package', $package ); }
		$response = wp_safe_remote_get( dirname( $package ) . '/SHA256SUMS', array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'aism_update_checksum', 'Could not download SHA256SUMS for this release.' ); }
		if ( ! preg_match( '/^([a-f0-9]{64})[ \t]+\*?' . preg_quote( self::ASSET, '/' ) . '[ \t]*$/mi', wp_remote_retrieve_body( $response ), $match ) ) { return new WP_Error( 'aism_update_checksum', 'SHA256SUMS does not list ' . self::ASSET . '.' ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) { return $file; }
		if ( ! hash_equals( strtolower( $match[1] ), (string) hash_file( 'sha256', $file ) ) ) {
			wp_delete_file( $file );
			return new WP_Error( 'aism_update_checksum', 'The downloaded update does not match its published SHA-256 checksum. Nothing was changed.' );
		}
		return $file;
	}

	/** Keeps the installed folder name and refuses packages that are not a newer AI Site Manager. */
	public static function source( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		$file = self::plugin_file();
		if ( is_wp_error( $source ) || ! is_array( $hook_extra ) || ( $hook_extra['plugin'] ?? '' ) !== $file || '.' === dirname( $file ) ) { return $source; }
		global $wp_filesystem;
		$main = trailingslashit( $source ) . basename( $file );
		if ( ! $wp_filesystem->exists( $main ) ) { return new WP_Error( 'aism_update_package', 'The update package does not contain ' . basename( $file ) . '.' ); }
		$local = str_replace( $wp_filesystem->wp_content_dir(), trailingslashit( WP_CONTENT_DIR ), $main );
		if ( is_file( $local ) ) {
			$data = get_file_data( $local, array( 'Name' => 'Plugin Name', 'Version' => 'Version' ) );
			if ( 'AI Site Manager' !== $data['Name'] || ! version_compare( $data['Version'], AISM_VERSION, '>' ) ) { return new WP_Error( 'aism_update_package', 'The update package is not a newer version of AI Site Manager.' ); }
		}
		$wanted = trailingslashit( $remote_source ) . trailingslashit( dirname( $file ) );
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) { return $source; }
		if ( ! $wp_filesystem->move( $source, $wanted, true ) ) { return new WP_Error( 'aism_update_package', 'Could not prepare the update package.' ); }
		return $wanted;
	}

	public static function row_meta( $links, $file ) {
		if ( self::plugin_file() !== $file || ! current_user_can( 'update_plugins' ) ) { return $links; }
		$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aism_check_updates' ), 'aism_check_updates' ) ) . '">Check for updates</a>';
		return $links;
	}

	public static function check_now() {
		if ( ! current_user_can( 'update_plugins' ) ) { wp_die( esc_html__( 'You cannot update plugins.', 'ai-site-manager' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'aism_check_updates' );
		$release = self::release( true );
		// Refresh WordPress's own update list so the result appears everywhere right away.
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$status = is_wp_error( $release ) ? 'error' : ( version_compare( $release['version'], AISM_VERSION, '>' ) ? 'available' : 'current' );
		$back = wp_get_referer() ?: self_admin_url( 'plugins.php' );
		wp_safe_redirect( add_query_arg( 'aism_update_check', $status, remove_query_arg( 'aism_update_check', $back ) ) );
		exit;
	}

	public static function notice() {
		if ( ! isset( $_GET['aism_update_check'] ) || ! current_user_can( 'update_plugins' ) ) { return; }
		$status = sanitize_key( $_GET['aism_update_check'] );
		$release = get_site_transient( self::CACHE );
		if ( 'available' === $status && is_array( $release ) && isset( $release['version'] ) ) {
			$class = 'notice-warning';
			$message = 'AI Site Manager ' . $release['version'] . ' is available. Use "update now" in the plugin list or Dashboard → Updates.';
		} elseif ( 'current' === $status ) {
			$class = 'notice-success';
			$message = 'AI Site Manager ' . AISM_VERSION . ' is the latest version.';
		} elseif ( 'error' === $status ) {
			$class = 'notice-error';
			$message = 'Could not check GitHub for AI Site Manager updates' . ( is_array( $release ) && isset( $release['error'] ) ? ': ' . $release['error'] : '.' );
		} else { return; }
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}
