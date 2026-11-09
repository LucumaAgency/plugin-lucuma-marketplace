<?php
/**
 * Dealer panel page: the [lm_dealer_panel] shortcode on /dealer/ (created on upgrade), its assets,
 * and keeping dealers out of wp-admin (they land on the panel after login).
 * Logged out, /dealer/ is the login page itself: log in, forgot password and set a new password
 * all happen there (?action=lost, ?action=rp), so dealers never see wp-login.php.
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
		add_action( 'template_redirect', [ __CLASS__, 'handle_auth' ] );
		add_filter( 'lostpassword_url', [ __CLASS__, 'lost_url' ] );
		add_filter( 'retrieve_password_message', [ __CLASS__, 'reset_message_link' ] );
	}

	/** Message for the auth forms, set while handling the POST and printed by the shortcode. */
	private static $note = null;

	public static function on_panel(): bool {
		$id = (int) get_option( self::OPTION );
		return $id ? is_page( $id ) : is_page( self::SLUG );
	}

	/** Link to set a new password, on the panel instead of wp-login.php. */
	public static function reset_url( WP_User $user, string $key ): string {
		return add_query_arg( [ 'action' => 'rp', 'key' => rawurlencode( $key ), 'login' => rawurlencode( $user->user_login ) ], self::url() );
	}

	public static function lost_url() {
		return add_query_arg( 'action', 'lost', self::url() );
	}

	/** The reset email WordPress sends points to wp-login.php: send people to the panel instead. */
	public static function reset_message_link( $message ) {
		return preg_replace_callback( '#\S*wp-login\.php\?action=rp&key=([^&\s]+)&login=([^&\s]+)\S*#', function ( $m ) {
			return add_query_arg( [ 'action' => 'rp', 'key' => $m[1], 'login' => $m[2] ], self::url() );
		}, $message );
	}

	/** Handles the login, lost password and new password forms posted to /dealer/ (before any output, so cookies can be set). */
	public static function handle_auth() {
		if ( ! self::on_panel() ) return;
		nocache_headers();
		if ( is_user_logged_in() || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || empty( $_POST['lmp_auth'] ) ) return;
		$form = sanitize_key( wp_unslash( $_POST['lmp_auth'] ) );

		if ( $form === 'login' ) {
			$login = trim( (string) wp_unslash( $_POST['log'] ?? '' ) );
			$user  = wp_signon( [
				'user_login'    => $login,
				'user_password' => (string) wp_unslash( $_POST['pwd'] ?? '' ),
				'remember'      => ! empty( $_POST['rememberme'] ),
			], is_ssl() );
			if ( is_wp_error( $user ) ) {
				self::$note = [ 'bad', $login === '' ? 'Enter your email and password.' : 'The email or password is not correct.' ];
				return;
			}
			wp_safe_redirect( self::url() );
			exit;
		}

		if ( $form === 'lost' ) {
			$login = trim( (string) wp_unslash( $_POST['user_login'] ?? '' ) );
			if ( $login !== '' ) retrieve_password( $login ); // Same answer either way: does not reveal which emails have accounts.
			self::$note = [ 'ok', 'If that email has a dealer account, we sent a link to set a new password. Check your inbox (and spam).' ];
			return;
		}

		if ( $form === 'rp' ) {
			$user = check_password_reset_key( (string) wp_unslash( $_POST['key'] ?? '' ), (string) wp_unslash( $_POST['login'] ?? '' ) );
			if ( is_wp_error( $user ) ) {
				self::$note = [ 'bad', 'This link has expired or was already used. Request a new one below.' ];
				$_GET['action'] = 'lost';
				return;
			}
			$p1 = (string) wp_unslash( $_POST['pass1'] ?? '' );
			$p2 = (string) wp_unslash( $_POST['pass2'] ?? '' );
			if ( strlen( $p1 ) < 8 || $p1 !== $p2 ) {
				self::$note = [ 'bad', strlen( $p1 ) < 8 ? 'Use at least 8 characters.' : 'The two passwords do not match.' ];
				return;
			}
			reset_password( $user, $p1 );
			self::$note = [ 'ok', 'Your password is set. Log in below.' ];
			$_GET['action'] = '';
		}
	}

	/** Login, lost password or new password form, shown on /dealer/ to logged-out visitors. */
	private static function auth_view(): string {
		wp_enqueue_style( 'lm-panel', plugins_url( 'assets/', LM_DIR . '/lucuma-marketplace.php' ) . 'panel.css', [], LM_VERSION );
		$action = sanitize_key( $_GET['action'] ?? '' );
		$url    = esc_url( self::url() );
		$note   = self::$note ? '<p class="lmp-msg ' . esc_attr( self::$note[0] ) . '" role="alert">' . esc_html( self::$note[1] ) . '</p>' : '';
		$help   = '<p class="lmp-auth-foot">Not a dealer on Heavy Listing yet? <a href="' . esc_url( home_url( '/how-it-works/#dealers' ) ) . '">Request a dealer account</a></p>';

		if ( $action === 'rp' ) {
			$key   = (string) wp_unslash( $_REQUEST['key'] ?? '' );
			$login = (string) wp_unslash( $_REQUEST['login'] ?? '' );
			if ( is_wp_error( check_password_reset_key( $key, $login ) ) ) {
				$note   = '<p class="lmp-msg bad" role="alert">This link has expired or was already used. Request a new one below.</p>';
				$action = 'lost';
			} else {
				return '<div class="lmp-login lmp-auth"><h2>Set your password</h2><p class="lmp-muted">Choose a password for your dealer account.</p>' . $note .
					'<form method="post" action="' . esc_url( add_query_arg( [ 'action' => 'rp', 'key' => rawurlencode( $key ), 'login' => rawurlencode( $login ) ], self::url() ) ) . '">' .
					'<input type="hidden" name="lmp_auth" value="rp"><input type="hidden" name="key" value="' . esc_attr( $key ) . '"><input type="hidden" name="login" value="' . esc_attr( $login ) . '">' .
					'<label class="lmp-field"><span>New password</span><input type="password" name="pass1" minlength="8" autocomplete="new-password" required><small>At least 8 characters.</small></label>' .
					'<label class="lmp-field"><span>Repeat the password</span><input type="password" name="pass2" minlength="8" autocomplete="new-password" required></label>' .
					'<button class="lmp-btn" type="submit">Save password</button></form></div>';
			}
		}

		if ( $action === 'lost' ) {
			$sent = self::$note && self::$note[0] === 'ok';
			return '<div class="lmp-login lmp-auth"><h2>Forgot your password?</h2><p class="lmp-muted">Enter the email of your dealer account and we will send you a link to set a new one.</p>' . $note .
				( $sent ? '' : '<form method="post" action="' . esc_url( self::lost_url() ) . '"><input type="hidden" name="lmp_auth" value="lost">' .
					'<label class="lmp-field"><span>Email</span><input type="email" name="user_login" autocomplete="email" required></label>' .
					'<button class="lmp-btn" type="submit">Send link</button></form>' ) .
				'<p class="lmp-auth-foot"><a href="' . $url . '">Back to log in</a></p></div>';
		}

		$log = esc_attr( (string) wp_unslash( $_POST['log'] ?? '' ) );
		return '<div class="lmp-login lmp-auth"><h2>Dealer log in</h2><p class="lmp-muted">Manage your equipment, photos and auctions on Heavy Listing.</p>' . $note .
			'<form method="post" action="' . $url . '"><input type="hidden" name="lmp_auth" value="login">' .
			'<label class="lmp-field"><span>Email or username</span><input type="text" name="log" value="' . $log . '" autocomplete="username" autocapitalize="off" required></label>' .
			'<label class="lmp-field"><span>Password</span><input type="password" name="pwd" autocomplete="current-password" required></label>' .
			'<div class="lmp-auth-row"><label class="lmp-check"><input type="checkbox" name="rememberme" value="1"> Keep me logged in</label><a href="' . esc_url( self::lost_url() ) . '">Forgot your password?</a></div>' .
			'<button class="lmp-btn" type="submit">Log in</button></form>' . $help . '</div>';
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
			return self::auth_view();
		}
		if ( ! current_user_can( 'edit_lm_equipments' ) ) {
			return '<div class="lmp-login"><h2>Dealer dashboard</h2><p>This account is not a dealer account. Contact us to list your equipment.</p></div>';
		}
		$base = plugins_url( 'assets/', LM_DIR . '/lucuma-marketplace.php' );
		// Brand (colors, font) comes from Bricks: Theme Style + Global Variables (--lm-*).
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
