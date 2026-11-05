<?php
/**
 * Dealer panel page: the [lm_dealer_panel] shortcode on /dealer/ (created on upgrade), its assets,
 * and keeping dealers out of wp-admin (they land on the panel after login).
 */
defined( 'ABSPATH' ) || exit;

class LM_Panel {
	const OPTION = 'lm_panel_page';
	const SLUG   = 'dealer';

	public static function init() {
		add_shortcode( 'lm_dealer_panel', [ __CLASS__, 'shortcode' ] );
		add_action( 'admin_init', [ __CLASS__, 'keep_out_of_admin' ] );
		add_filter( 'show_admin_bar', [ __CLASS__, 'admin_bar' ] );
		add_filter( 'login_redirect', [ __CLASS__, 'login_redirect' ], 10, 3 );
		add_filter( 'ajax_query_attachments_args', [ __CLASS__, 'own_media' ] );
		add_filter( 'rest_attachment_query', [ __CLASS__, 'own_media' ] );
	}

	public static function is_dealer( $user = null ): bool {
		$user = $user ?: wp_get_current_user();
		return $user && $user->exists() && in_array( LM_Model::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'manage_options' );
	}

	public static function url(): string {
		$id = (int) get_option( self::OPTION );
		return $id && get_post_status( $id ) ? get_permalink( $id ) : home_url( '/' . self::SLUG . '/' );
	}

	/** The panel page, created once (and recreated if it was deleted). */
	public static function ensure_page() {
		$id = (int) get_option( self::OPTION );
		if ( $id && get_post_status( $id ) && get_post_status( $id ) !== 'trash' ) return;
		$found = get_page_by_path( self::SLUG );
		if ( $found ) {
			$id = $found->ID;
		} else {
			$id = wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Dealer Dashboard',
				'post_name'    => self::SLUG,
				'post_content' => '[lm_dealer_panel]',
			] );
		}
		if ( $id && ! is_wp_error( $id ) ) update_option( self::OPTION, (int) $id );
	}

	public static function keep_out_of_admin() {
		if ( wp_doing_ajax() || ! self::is_dealer() ) return;
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function admin_bar( $show ) {
		return self::is_dealer() ? false : $show;
	}

	public static function login_redirect( $to, $requested, $user ) {
		return ( $user instanceof WP_User && self::is_dealer( $user ) ) ? self::url() : $to;
	}

	/** Dealers only see their own uploads. */
	public static function own_media( $args ) {
		if ( self::is_dealer() ) $args['author'] = get_current_user_id();
		return $args;
	}

	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<div class="lmp-login"><h2>Dealer dashboard</h2><p>Log in to manage your equipment.</p><p><a class="lmp-btn" href="' . esc_url( wp_login_url( self::url() ) ) . '">Log in</a></p></div>';
		}
		if ( ! current_user_can( 'edit_lm_equipments' ) ) {
			return '<div class="lmp-login"><h2>Dealer dashboard</h2><p>This account is not a dealer account. Contact us to list your equipment.</p></div>';
		}
		$base = plugins_url( 'assets/', LM_DIR . '/lucuma-marketplace.php' );
		wp_enqueue_style( 'lm-panel', $base . 'panel.css', [], LM_VERSION );
		wp_enqueue_script( 'lm-panel', $base . 'panel.js', [], LM_VERSION, true );
		wp_localize_script( 'lm-panel', 'LMP', [
			'api'     => esc_url_raw( rest_url( 'lucuma-mkt/v1/' ) ),
			'wpapi'   => esc_url_raw( rest_url( 'wp/v2/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'logout'  => wp_logout_url( home_url( '/' ) ),
			'admin'   => current_user_can( 'edit_others_lm_equipments' ),
			'maxMb'   => (int) floor( wp_max_upload_size() / 1048576 ),
		] );
		return '<div id="lm-panel" class="lmp"><p class="lmp-muted">Loading…</p></div>';
	}
}
