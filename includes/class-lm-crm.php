<?php
/**
 * CRM leads (0.28.0): the Bricks forms for dealers (lhcfrm, /how-it-works/) and auctioneers
 * (lhrfrm, /list-your-auction/) are also sent to Lucuma CRM (organization "heavylisting").
 *
 * The forms keep their own actions (save-submission + email to contact@) and add "custom": Bricks
 * then fires bricks/form/custom_action and this class posts the lead server to server to
 * POST <crm>/api/v1/public/forms/<id>/submissions with the CRM site's public key. The same
 * idempotency key travels in every retry, so the CRM never duplicates a lead. If the CRM is down
 * the lead is still in Bricks submissions and in the email; retries run at 5 min, 1 h and 6 h.
 *
 * Config (option lm_crm), set by an administrator through REST:
 *   POST lucuma-mkt/v1/crm/config  { url, key, forms: { lhcfrm: "<crm form id>", lhrfrm: "<crm form id>" } }
 *   GET  lucuma-mkt/v1/crm/config  config without the key + last 20 results
 *
 * The seller form (lhwfrm) is not sent here: it goes to Yard (class-lm-seller.php).
 */
defined( 'ABSPATH' ) || exit;

class LM_Crm {
	const OPTION  = 'lm_crm';
	const LOG     = 'lm_crm_log';
	const RETRY   = 'lm_crm_retry';
	const DELAYS  = [ 300, 3600, 21600 ];

	/** Bricks field id => CRM field key, per form. "name" is split into fname/lname. */
	const MAP = [
		'lhcfrm' => [ 'company' => 'company', 'email' => 'email', 'units' => 'units', 'state' => 'state', 'plan' => 'plan', 'auctions' => 'auctions', 'notes' => 'notes' ],
		'lhrfrm' => [ 'company' => 'company', 'email' => 'email', 'auction' => 'auction', 'date' => 'date', 'location' => 'location', 'bidding' => 'bidding', 'plan' => 'plan', 'catalog' => 'catalog', 'more' => 'notes' ],
	];

	public static function init() {
		add_action( 'bricks/form/custom_action', [ __CLASS__, 'bricks' ], 10, 1 );
		add_action( self::RETRY, [ __CLASS__, 'retry' ], 10, 3 );
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	private static function config(): array {
		$c = get_option( self::OPTION, [] );
		return is_array( $c ) ? $c : [];
	}

	/** US numbers to E.164 (+1…); the CRM's own normalizer assumes Peru. */
	public static function phone( string $raw ): string {
		$d = preg_replace( '/\D/', '', $raw );
		if ( strlen( $d ) === 10 ) return '+1' . $d;
		if ( strlen( $d ) === 11 && $d[0] === '1' ) return '+' . $d;
		return $d !== '' ? ( str_starts_with( trim( $raw ), '+' ) ? '+' . $d : $d ) : '';
	}

	private static function text( $v, int $max ): string {
		if ( is_array( $v ) ) $v = implode( ', ', $v );
		return mb_substr( trim( sanitize_textarea_field( (string) $v ) ), 0, $max );
	}

	/** Bricks custom action. Never sets an error: the email to contact@ and the saved submission are the backup. */
	public static function bricks( $form ) {
		if ( ! is_object( $form ) || ! method_exists( $form, 'get_fields' ) ) return;
		$f = (array) $form->get_fields();
		$bricks_id = (string) ( $f['formId'] ?? '' );
		$c = self::config();
		if ( ! isset( self::MAP[ $bricks_id ] ) || empty( $c['forms'][ $bricks_id ] ) || empty( $c['url'] ) || empty( $c['key'] ) ) return;

		$name = self::text( $f['form-field-name'] ?? '', 120 );
		$parts = preg_split( '/\s+/', $name, 2 );
		$values = [ 'fname' => $parts[0] ?? '', 'lname' => $parts[1] ?? '', 'phone' => self::phone( (string) ( $f['form-field-phone'] ?? '' ) ) ];
		foreach ( self::MAP[ $bricks_id ] as $field => $key ) {
			$values[ $key ] = self::text( $f[ 'form-field-' . $field ] ?? '', $key === 'notes' ? 5000 : 300 );
		}
		$values['email'] = sanitize_email( $values['email'] );
		if ( $values['fname'] === '' || ! is_email( $values['email'] ) ) return;

		$post_id = absint( $f['postId'] ?? 0 );
		$body = [
			'idempotencyKey' => wp_generate_uuid4(),
			'values'         => $values,
			'attribution'    => array_filter( [
				'pageUrl'  => $post_id ? (string) get_permalink( $post_id ) : '',
				'referrer' => mb_substr( (string) ( $_SERVER['HTTP_REFERER'] ?? '' ), 0, 500 ),
			] ),
			// Bricks already ran its own checks; this is a server-to-server post.
			'antispam'       => [ 'elapsedSeconds' => 60 ],
		];
		self::send( $bricks_id, $body, 0 );
	}

	public static function retry( $bricks_id, $body, $attempt ) {
		self::send( (string) $bricks_id, (array) $body, (int) $attempt );
	}

	private static function send( string $bricks_id, array $body, int $attempt ): void {
		$c = self::config();
		$form = (string) ( $c['forms'][ $bricks_id ] ?? '' );
		if ( $form === '' || empty( $c['url'] ) || empty( $c['key'] ) ) return;
		$url = untrailingslashit( (string) $c['url'] ) . '/api/v1/public/forms/' . rawurlencode( $form ) . '/submissions';
		$res = wp_remote_post( $url, [
			'timeout' => 8,
			'headers' => [ 'Content-Type' => 'application/json', 'X-LCRM-Key' => (string) $c['key'] ],
			'body'    => wp_json_encode( $body ),
		] );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$ok = $code >= 200 && $code < 300;
		$detail = is_wp_error( $res ) ? $res->get_error_message() : mb_substr( (string) wp_remote_retrieve_body( $res ), 0, 300 );
		self::log( $bricks_id, $code, $ok ? '' : $detail, $attempt );
		// 4xx other than 429 will not get better by retrying.
		$retry = ! $ok && ( $code === 0 || $code === 429 || $code >= 500 );
		if ( $retry && isset( self::DELAYS[ $attempt ] ) ) {
			wp_schedule_single_event( time() + self::DELAYS[ $attempt ], self::RETRY, [ $bricks_id, $body, $attempt + 1 ] );
		}
	}

	private static function log( string $form, int $code, string $error, int $attempt ): void {
		$log = get_option( self::LOG, [] );
		if ( ! is_array( $log ) ) $log = [];
		array_unshift( $log, [ 'at' => gmdate( 'c' ), 'form' => $form, 'code' => $code, 'attempt' => $attempt, 'error' => $error ] );
		update_option( self::LOG, array_slice( $log, 0, 20 ), false );
	}

	/* ------------------------------------------------------------------ REST */

	public static function routes() {
		$admin = function () { return current_user_can( 'manage_options' ); };
		register_rest_route( 'lucuma-mkt/v1', '/crm/config', [
			[ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'get_config' ] ],
			[ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'set_config' ] ],
		] );
	}

	public static function get_config() {
		$c = self::config();
		return [
			'url'    => $c['url'] ?? '',
			'hasKey' => ! empty( $c['key'] ),
			'forms'  => $c['forms'] ?? (object) [],
			'log'    => get_option( self::LOG, [] ),
		];
	}

	public static function set_config( WP_REST_Request $req ) {
		$c = self::config();
		$url = esc_url_raw( (string) $req->get_param( 'url' ) );
		if ( $url !== '' ) $c['url'] = $url;
		$key = sanitize_text_field( (string) $req->get_param( 'key' ) );
		if ( $key !== '' ) $c['key'] = $key;
		$forms = $req->get_param( 'forms' );
		if ( is_array( $forms ) ) {
			foreach ( array_keys( self::MAP ) as $id ) {
				if ( array_key_exists( $id, $forms ) ) $c['forms'][ $id ] = sanitize_text_field( (string) $forms[ $id ] );
			}
		}
		update_option( self::OPTION, $c, false );
		return self::get_config();
	}
}
