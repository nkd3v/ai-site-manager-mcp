<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only account review plus a reversible quarantine for incident response.
 *
 * Quarantine saves the account's capabilities, removes every role, signs out all sessions,
 * deletes application passwords, sets a random password and blocks every login path until
 * an administrator restores the account. Accounts are never deleted through this API.
 */
final class AISM_Users {
	const META = 'aism_quarantine';
	const MAX_PER_PAGE = 100;

	public static function init() {
		// Late priorities so the block wins over other authentication handlers.
		add_filter( 'authenticate', array( __CLASS__, 'block_login' ), 1000, 1 );
		add_filter( 'determine_current_user', array( __CLASS__, 'block_current_user' ), 1000 );
		add_filter( 'wp_authenticate_application_password_errors', array( __CLASS__, 'block_application_password' ), 10, 2 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'block_password_reset' ), 1000, 2 );
		add_filter( 'user_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_aism_lift_quarantine', array( __CLASS__, 'admin_restore' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	public static function is_quarantined( $user_id ) {
		return $user_id > 0 && is_array( get_user_meta( (int) $user_id, self::META, true ) );
	}

	public static function block_login( $user ) {
		if ( $user instanceof WP_User && self::is_quarantined( $user->ID ) ) {
			return new WP_Error( 'aism_quarantined', __( 'This account is locked. Contact the site administrator.', 'ai-site-manager' ) );
		}
		return $user;
	}

	public static function block_current_user( $user_id ) {
		return is_numeric( $user_id ) && self::is_quarantined( (int) $user_id ) ? 0 : $user_id;
	}

	public static function block_application_password( $error, $user ) {
		if ( $user instanceof WP_User && self::is_quarantined( $user->ID ) ) { $error->add( 'aism_quarantined', __( 'This account is locked.', 'ai-site-manager' ) ); }
		return $error;
	}

	public static function block_password_reset( $allow, $user_id ) {
		return self::is_quarantined( (int) $user_id ) ? false : $allow;
	}

	private static function error( $code, $message, $status = 400 ) { return new WP_Error( $code, $message, array( 'status' => $status ) ); }

	private static function utc( $timestamp ) { return $timestamp ? gmdate( 'Y-m-d H:i:s', (int) $timestamp ) : null; }

	public static function dispatch( $request, $route, $key ) {
		if ( ! current_user_can( 'list_users' ) ) { return self::error( 'aism_users_forbidden', 'The key owner needs the list_users capability.', 403 ); }
		$url = $request->get_url_params();
		if ( '/users' === $route ) { return self::collection( $request ); }
		$user = get_userdata( (int) ( $url['user_id'] ?? 0 ) );
		if ( ! $user ) { return self::error( 'aism_user_missing', 'No user has this ID.', 404 ); }
		if ( empty( $url['user_action'] ) ) { return self::describe( $user ); }
		if ( is_multisite() ) { return self::error( 'aism_multisite_users', 'Account changes through this API are supported on single-site WordPress only.', 403 ); }
		if ( ! current_user_can( 'promote_users' ) || ! current_user_can( 'edit_user', $user->ID ) ) { return self::error( 'aism_user_forbidden', 'The key owner cannot change this account\'s roles.', 403 ); }
		if ( get_current_user_id() === $user->ID ) { return self::error( 'aism_self_quarantine', 'The API key owner cannot change their own account through this API.', 403 ); }
		return 'quarantine' === $url['user_action'] ? self::quarantine( $user, $request, (int) $key['id'] ) : self::restore( $user );
	}

	private static function collection( $request ) {
		$page = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$args = array( 'number' => $per_page, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'count_total' => true );
		$role = $request->get_param( 'role' );
		if ( is_string( $role ) && '' !== $role ) {
			if ( ! wp_roles()->is_role( $role ) ) { return self::error( 'aism_invalid_role', 'Unknown role.' ); }
			$args['role'] = $role;
		}
		$search = $request->get_param( 'search' );
		if ( is_string( $search ) && '' !== trim( $search ) ) { $args['search'] = '*' . trim( $search ) . '*'; $args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' ); }
		$query = new WP_User_Query( $args );
		$total = (int) $query->get_total();
		$response = new WP_REST_Response( array_map( array( __CLASS__, 'describe' ), $query->get_results() ) );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per_page ) ) );
		return $response;
	}

	/** Account facts for review. Never includes password hashes, session tokens or application password hashes. */
	public static function describe( $user ) {
		$sessions = array();
		foreach ( (array) get_user_meta( $user->ID, 'session_tokens', true ) as $session ) {
			if ( ! is_array( $session ) ) { continue; }
			$sessions[] = array(
				'login_utc' => self::utc( $session['login'] ?? 0 ), 'expires_utc' => self::utc( $session['expiration'] ?? 0 ),
				'ip' => isset( $session['ip'] ) && is_string( $session['ip'] ) ? $session['ip'] : null,
				'user_agent' => isset( $session['ua'] ) && is_string( $session['ua'] ) ? substr( $session['ua'], 0, 300 ) : null,
			);
		}
		$app_passwords = array();
		if ( class_exists( 'WP_Application_Passwords' ) ) {
			foreach ( WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $item ) {
				$app_passwords[] = array(
					'uuid' => $item['uuid'], 'name' => $item['name'], 'created_utc' => self::utc( $item['created'] ?? 0 ),
					'last_used_utc' => self::utc( $item['last_used'] ?? 0 ), 'last_ip' => $item['last_ip'] ?? null,
				);
			}
		}
		$quarantine = get_user_meta( $user->ID, self::META, true );
		return array(
			'id' => (int) $user->ID, 'login' => $user->user_login, 'email' => $user->user_email, 'display_name' => $user->display_name,
			'url' => $user->user_url, 'registered_utc' => $user->user_registered, 'roles' => array_values( $user->roles ),
			'administrator' => user_can( $user, 'manage_options' ), 'super_admin' => is_multisite() && is_super_admin( $user->ID ),
			'posts' => (int) count_user_posts( $user->ID, array_values( get_post_types( array( 'public' => true ) ) ) ),
			'sessions' => $sessions, 'application_passwords' => $app_passwords,
			'quarantine' => is_array( $quarantine ) ? array(
				'quarantined_utc' => $quarantine['at'] ?? null, 'reason' => $quarantine['reason'] ?? '', 'by_user' => (int) ( $quarantine['by_user'] ?? 0 ),
				'by_key' => (int) ( $quarantine['by_key'] ?? 0 ), 'previous_roles' => array_values( (array) ( $quarantine['roles'] ?? array() ) ),
			) : null,
		);
	}

	private static function other_active_administrators( $user_id ) {
		$ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'exclude' => array( $user_id ), 'number' => 50 ) );
		foreach ( $ids as $id ) { if ( ! self::is_quarantined( (int) $id ) ) { return true; } }
		return false;
	}

	private static function quarantine( $user, $request, $key_id ) {
		global $wpdb;
		if ( self::is_quarantined( $user->ID ) ) { return array( 'quarantined' => true, 'changed' => false, 'user' => self::describe( $user ) ); }
		if ( is_super_admin( $user->ID ) && is_multisite() ) { return self::error( 'aism_super_admin', 'Network administrators cannot be quarantined.', 403 ); }
		if ( in_array( 'administrator', $user->roles, true ) && ! self::other_active_administrators( $user->ID ) ) { return self::error( 'aism_last_administrator', 'Refusing to quarantine the only active administrator.', 409 ); }
		$body = $request->get_json_params();
		$reason = is_array( $body ) && isset( $body['reason'] ) && is_string( $body['reason'] ) ? sanitize_text_field( substr( $body['reason'], 0, 500 ) ) : '';
		$caps_key = $wpdb->get_blog_prefix() . 'capabilities';
		$caps = get_user_meta( $user->ID, $caps_key, true );
		// Record first: if a later step fails, the login block is already active and restore has the saved roles.
		$record = array( 'at' => gmdate( 'Y-m-d H:i:s' ), 'reason' => $reason, 'by_user' => get_current_user_id(), 'by_key' => $key_id, 'roles' => array_values( $user->roles ), 'caps' => is_array( $caps ) ? $caps : array() );
		if ( ! update_user_meta( $user->ID, self::META, $record ) ) { return self::error( 'aism_quarantine_failed', 'Could not record the quarantine.', 500 ); }
		$user->remove_all_caps();
		WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		$app_passwords = 0;
		if ( class_exists( 'WP_Application_Passwords' ) ) {
			$app_passwords = count( WP_Application_Passwords::get_user_application_passwords( $user->ID ) );
			WP_Application_Passwords::delete_all_application_passwords( $user->ID );
		}
		wp_set_password( wp_generate_password( 64, true, true ), $user->ID );
		clean_user_cache( $user->ID );
		return array(
			'quarantined' => true, 'changed' => true, 'removed_roles' => $record['roles'], 'sessions_ended' => true,
			'application_passwords_deleted' => $app_passwords, 'password_reset' => true, 'user' => self::describe( get_userdata( $user->ID ) ),
		);
	}

	private static function restore( $user ) {
		$record = get_user_meta( $user->ID, self::META, true );
		if ( ! is_array( $record ) ) { return array( 'quarantined' => false, 'changed' => false, 'user' => self::describe( $user ) ); }
		self::lift( $user->ID, $record );
		return array(
			'quarantined' => false, 'changed' => true, 'restored_roles' => array_values( (array) ( $record['roles'] ?? array() ) ),
			'note' => 'The password stays randomized and deleted application passwords are not restored. The owner must reset the password.',
			'user' => self::describe( get_userdata( $user->ID ) ),
		);
	}

	private static function lift( $user_id, $record ) {
		global $wpdb;
		$caps = isset( $record['caps'] ) && is_array( $record['caps'] ) ? $record['caps'] : array();
		// Restore the saved role and capability flags exactly; ignore malformed entries.
		$caps = array_filter( $caps, function ( $grant, $cap ) { return is_string( $cap ) && is_bool( $grant ); }, ARRAY_FILTER_USE_BOTH );
		update_user_meta( $user_id, $wpdb->get_blog_prefix() . 'capabilities', $caps );
		$user = new WP_User( $user_id );
		$user->update_user_level_from_caps();
		delete_user_meta( $user_id, self::META );
		clean_user_cache( $user_id );
	}

	public static function row_actions( $actions, $user ) {
		if ( self::is_quarantined( $user->ID ) && current_user_can( 'promote_users' ) && current_user_can( 'edit_user', $user->ID ) && ! is_multisite() ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=aism_lift_quarantine&user_id=' . (int) $user->ID ), 'aism_lift_quarantine_' . (int) $user->ID );
			$actions['aism_lift_quarantine'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Lift quarantine', 'ai-site-manager' ) . '</a>';
			$actions = array( 'aism_quarantined' => '<strong>' . esc_html__( 'Quarantined', 'ai-site-manager' ) . '</strong>' ) + $actions;
		}
		return $actions;
	}

	public static function admin_restore() {
		$user_id = absint( $_GET['user_id'] ?? 0 );
		check_admin_referer( 'aism_lift_quarantine_' . $user_id );
		if ( is_multisite() || ! current_user_can( 'promote_users' ) || ! current_user_can( 'edit_user', $user_id ) ) { wp_die( esc_html__( 'You cannot change this account.', 'ai-site-manager' ), '', array( 'response' => 403 ) ); }
		$record = get_user_meta( $user_id, self::META, true );
		if ( is_array( $record ) ) { self::lift( $user_id, $record ); }
		wp_safe_redirect( add_query_arg( 'aism_lifted', $user_id, admin_url( 'users.php' ) ) );
		exit;
	}

	public static function admin_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'users' !== $screen->id || empty( $_GET['aism_lifted'] ) ) { return; }
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Quarantine lifted and previous roles restored. The account keeps its randomized password: send a password reset before the owner signs in.', 'ai-site-manager' ) . '</p></div>';
	}
}
