<?php
/**
 * Seller leads (0.27.0): owners who fill in the form on /sell-your-equipment/ (Bricks form lhwfrm).
 *
 * The Bricks form keeps its own actions (save-submission + email to contact@ as a backup) and adds
 * "custom": Bricks then fires bricks/form/custom_action and this class stores the lead and sends it to Yard
 * at once (POST <yard>/api/v1/hooks/wp/seller-lead, same HMAC as inquiries). If that fails, Yard picks it up
 * in its nightly pass:
 *
 *   GET  lucuma-mkt/v1/sync/seller-leads        signed by Yard: leads Yard has not confirmed yet
 *   POST lucuma-mkt/v1/sync/seller-leads/ack    signed by Yard: confirm { ids }
 *
 * In Yard the Heavy Listing team sends each lead to dealers on a plan (paying clients only) or to an
 * auctioneer by email. The copy here is deleted 30 days after Yard has it.
 */
defined( 'ABSPATH' ) || exit;

class LM_Seller {
	const DB_VERSION = '1';
	const FORM_ID    = 'lhwfrm';
	const KEEP_DAYS  = 30;
	const PER_HOUR   = 5;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lm_seller_leads';
	}

	public static function init() {
		add_action( 'plugins_loaded', [ __CLASS__, 'install' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'bricks/form/custom_action', [ __CLASS__, 'bricks' ], 10, 1 );
		add_action( 'lm_seller_leads_purge', [ __CLASS__, 'purge' ] );
		add_action( 'init', function () {
			if ( ! wp_next_scheduled( 'lm_seller_leads_purge' ) ) wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'lm_seller_leads_purge' );
		} );
	}

	public static function install() {
		if ( get_option( 'lm_seller_leads_db' ) === self::DB_VERSION ) return;
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . ' (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created datetime NOT NULL,
			name varchar(120) NOT NULL,
			email varchar(190) NOT NULL,
			phone varchar(40) NOT NULL DEFAULT \'\',
			zip varchar(10) NOT NULL DEFAULT \'\',
			type varchar(60) NOT NULL DEFAULT \'\',
			make_model varchar(160) NOT NULL DEFAULT \'\',
			year varchar(10) NOT NULL DEFAULT \'\',
			hours varchar(20) NOT NULL DEFAULT \'\',
			how_sell varchar(60) NOT NULL DEFAULT \'\',
			notes text NOT NULL,
			url varchar(500) NOT NULL DEFAULT \'\',
			ip_hash char(16) NOT NULL DEFAULT \'\',
			forwarded datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY forwarded (forwarded),
			KEY ip_created (ip_hash,created)
		) ' . $wpdb->get_charset_collate() . ';' );
		update_option( 'lm_seller_leads_db', self::DB_VERSION, false );
	}

	/** One field of the Bricks form ("form-field-<id>"); checkboxes come as arrays. */
	private static function field( array $f, string $id, int $max ): string {
		$v = $f[ 'form-field-' . $id ] ?? '';
		if ( is_array( $v ) ) $v = implode( ', ', $v );
		return mb_substr( trim( sanitize_text_field( (string) $v ) ), 0, $max );
	}

	/**
	 * Bricks custom action. Never sets an error: Bricks already validated the fields and the email to contact@
	 * goes out anyway, so the owner always sees the success message.
	 */
	public static function bricks( $form ) {
		if ( ! is_object( $form ) || ! method_exists( $form, 'get_fields' ) ) return;
		$f = (array) $form->get_fields();
		if ( (string) ( $f['formId'] ?? '' ) !== self::FORM_ID ) return;

		$email = sanitize_email( (string) ( $f['form-field-email'] ?? '' ) );
		$name = self::field( $f, 'name', 120 );
		if ( $name === '' || ! is_email( $email ) ) return;

		global $wpdb;
		$ip = substr( hash_hmac( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), wp_salt( 'auth' ) ), 0, 16 );
		$recent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ip_hash=%s AND created>%s', $ip, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		if ( $recent >= self::PER_HOUR ) return;

		$post_id = absint( $f['postId'] ?? 0 );
		$notes = $f['form-field-notes'] ?? '';
		$wpdb->insert( self::table(), [
			'created'    => gmdate( 'Y-m-d H:i:s' ),
			'name'       => $name,
			'email'      => mb_substr( $email, 0, 190 ),
			'phone'      => mb_substr( trim( preg_replace( '/[^0-9+()\-.\s]/', '', (string) ( $f['form-field-phone'] ?? '' ) ) ), 0, 40 ),
			'zip'        => self::field( $f, 'zip', 10 ),
			'type'       => self::field( $f, 'type', 60 ),
			'make_model' => self::field( $f, 'makemodel', 160 ),
			'year'       => self::field( $f, 'year', 10 ),
			'hours'      => self::field( $f, 'hours', 20 ),
			'how_sell'   => self::field( $f, 'howsell', 60 ),
			'notes'      => mb_substr( sanitize_textarea_field( is_array( $notes ) ? implode( ', ', $notes ) : (string) $notes ), 0, 5000 ),
			'url'        => $post_id ? (string) get_permalink( $post_id ) : '',
			'ip_hash'    => $ip,
		] );
		$id = (int) $wpdb->insert_id;
		if ( $id ) self::forward( $id );
	}

	/* ------------------------------------------------------------------ REST */

	public static function routes() {
		$signed = [ 'LM_Sync', 'verify' ];
		register_rest_route( LM_Sync::NS, '/sync/seller-leads', [ 'methods' => 'GET', 'permission_callback' => $signed, 'callback' => [ __CLASS__, 'pending' ] ] );
		register_rest_route( LM_Sync::NS, '/sync/seller-leads/ack', [ 'methods' => 'POST', 'permission_callback' => $signed, 'callback' => [ __CLASS__, 'ack' ] ] );
	}

	private static function payload( object $r ): array {
		return [
			'id' => (int) $r->id, 'name' => $r->name, 'email' => $r->email, 'phone' => $r->phone, 'zip' => $r->zip, 'type' => $r->type,
			'make_model' => $r->make_model, 'year' => $r->year, 'hours' => $r->hours, 'how_sell' => $r->how_sell, 'notes' => $r->notes,
			'url' => $r->url, 'created' => gmdate( 'c', strtotime( $r->created . ' UTC' ) ),
		];
	}

	/** Send one lead to Yard now. On failure it stays pending for Yard's nightly pass. */
	private static function forward( int $id ): void {
		global $wpdb;
		$secret = (string) get_option( LM_Sync::OPTION, '' );
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d', $id ) );
		if ( ! $r || $secret === '' ) return;
		$body = wp_json_encode( self::payload( $r ) );
		$ts = (string) time();
		$url = untrailingslashit( (string) get_option( LM_Inquiry::YARD_URL, 'https://yard.heavylisting.com' ) ) . '/api/v1/hooks/wp/seller-lead';
		$res = wp_remote_post( $url, [
			'timeout' => 8,
			'headers' => [ 'Content-Type' => 'application/json', 'X-WP-Timestamp' => $ts, 'X-WP-Signature' => hash_hmac( 'sha256', 'wp-hook.' . $ts . '.' . $body, $secret ) ],
			'body'    => $body,
		] );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			$wpdb->update( self::table(), [ 'forwarded' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => $id ] );
		} else {
			error_log( '[lm_seller] Yard did not take seller lead ' . $id . ': ' . ( is_wp_error( $res ) ? $res->get_error_message() : $code ) );
		}
	}

	public static function pending() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE forwarded IS NULL ORDER BY id ASC LIMIT 200' );
		return [ 'leads' => array_map( [ __CLASS__, 'payload' ], $rows ) ];
	}

	public static function ack( WP_REST_Request $req ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', (array) ( json_decode( $req->get_body(), true )['ids'] ?? [] ) ) ) );
		if ( ! $ids ) return [ 'ok' => true, 'updated' => 0 ];
		$in = implode( ',', $ids );
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET forwarded=%s WHERE forwarded IS NULL AND id IN ($in)", gmdate( 'Y-m-d H:i:s' ) ) );
		return [ 'ok' => true, 'updated' => (int) $n ];
	}

	/** Personal data stays here only as a backup until Yard has it. */
	public static function purge() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE forwarded IS NOT NULL AND forwarded<%s', gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
	}
}
