<?php
/**
 * Inquiry form on the listing (Yard E3, 0.23.0).
 *
 * Only on units that come from Yard (meta lm_yard_id) and only when Yard sync is set up (option lm_yard_secret):
 * the dealer box shows "Message the dealer" instead of the plain mailto button.
 *
 *   POST lucuma-mkt/v1/inquiry               public: the form (honeypot + signed time token + rate limit per IP)
 *   GET  lucuma-mkt/v1/sync/inquiries        signed by Yard: inquiries Yard has not confirmed yet
 *   POST lucuma-mkt/v1/sync/inquiries/ack    signed by Yard: confirm { ids }
 *   GET  lucuma-mkt/v1/sync/stats?since=     signed by Yard: lm_stats rows of Yard units, by day and kind
 *
 * Each inquiry is stored here, counted in lm_stats (kind "inquiry") and sent to Yard at once
 * (POST <yard>/api/v1/hooks/wp/inquiry, HMAC of "wp-hook.<ts>.<body>" with the same secret). If that fails, Yard
 * picks it up in its nightly pass. Yard emails the dealer. The copy here is deleted 30 days after Yard has it.
 */
defined( 'ABSPATH' ) || exit;

class LM_Inquiry {
	const DB_VERSION = '1';
	const YARD_URL   = 'lm_yard_url';
	const KEEP_DAYS  = 30;
	const PER_HOUR   = 5;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lm_inquiries';
	}

	public static function init() {
		add_action( 'plugins_loaded', [ __CLASS__, 'install' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'lm_inquiries_purge', [ __CLASS__, 'purge' ] );
		add_action( 'init', function () {
			if ( ! wp_next_scheduled( 'lm_inquiries_purge' ) ) wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'lm_inquiries_purge' );
		} );
	}

	public static function install() {
		if ( get_option( 'lm_inquiries_db' ) === self::DB_VERSION ) return;
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . ' (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created datetime NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			yard_id varchar(40) NOT NULL DEFAULT \'\',
			dealer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(120) NOT NULL,
			email varchar(190) NOT NULL,
			phone varchar(40) NOT NULL DEFAULT \'\',
			message text NOT NULL,
			url varchar(500) NOT NULL DEFAULT \'\',
			ip_hash char(16) NOT NULL DEFAULT \'\',
			forwarded datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY forwarded (forwarded),
			KEY ip_created (ip_hash,created)
		) ' . $wpdb->get_charset_collate() . ';' );
		update_option( 'lm_inquiries_db', self::DB_VERSION, false );
	}

	public static function enabled_for( int $post_id ): bool {
		return (string) get_option( LM_Sync::OPTION, '' ) !== '' && (string) get_post_meta( $post_id, 'lm_yard_id', true ) !== '';
	}

	private static function yard_url(): string {
		return untrailingslashit( (string) get_option( self::YARD_URL, 'https://yard.heavylisting.com' ) );
	}

	/* ------------------------------------------------------------------ form */

	/** Time token: the form must be at least 3 s old and at most 6 h (bots post at once or replay old tokens). */
	private static function token(): string {
		$t = (string) time();
		return $t . '.' . substr( hash_hmac( 'sha256', 'lm-inq.' . $t, wp_salt( 'nonce' ) ), 0, 20 );
	}

	private static function token_ok( string $tok ): bool {
		if ( ! preg_match( '/^(\d{9,11})\.([a-f0-9]{20})$/', $tok, $m ) ) return false;
		$age = time() - (int) $m[1];
		return $age >= 3 && $age <= 6 * HOUR_IN_SECONDS && hash_equals( substr( hash_hmac( 'sha256', 'lm-inq.' . $m[1], wp_salt( 'nonce' ) ), 0, 20 ), $m[2] );
	}

	/** The "Message the dealer" button and form, for the dealer box. Empty when the unit is not from Yard. */
	public static function form( WP_Post $p, string $company, string $email ): string {
		if ( ! self::enabled_for( $p->ID ) ) return '';
		$title = html_entity_decode( get_the_title( $p ), ENT_QUOTES );
		$privacy = get_privacy_policy_url();
		$id = 'lmf-inq-' . $p->ID;
		$cfg = [ 'url' => esc_url_raw( rest_url( 'lucuma-mkt/v1/inquiry' ) ), 'post' => $p->ID, 'dealer' => $company, 'title' => $title ];
		return '<details class="lmf-inq" id="' . esc_attr( $id ) . '"><summary class="lmf-btn ghost" data-lm-event="inquiry_open">Message the dealer</summary>' .
			'<form class="lmf-inq-form" novalidate>' .
			'<label>Name<input name="name" autocomplete="name" required maxlength="120"></label>' .
			'<label>Email<input name="email" type="email" autocomplete="email" required maxlength="190"></label>' .
			'<label>Phone <span>(optional)</span><input name="phone" type="tel" autocomplete="tel" maxlength="40"></label>' .
			'<label>Message<textarea name="message" rows="4" required maxlength="3000">' . esc_textarea( 'Hi, is the ' . $title . ' still available?' ) . '</textarea></label>' .
			'<label class="lmf-inq-hp" aria-hidden="true">Company website<input name="company_website" tabindex="-1" autocomplete="off"></label>' .
			'<input type="hidden" name="t" value="' . esc_attr( self::token() ) . '">' .
			'<button type="submit" class="lmf-btn">Send message</button>' .
			'<p class="lmf-inq-note">Your name and contact details go to ' . esc_html( $company ) . ' so they can answer you.' .
			( $privacy ? ' <a href="' . esc_url( $privacy ) . '">Privacy policy</a>.' : '' ) .
			( $email ? ' Prefer email? <a href="mailto:' . esc_attr( $email ) . '?subject=' . rawurlencode( 'Inquiry: ' . $title ) . '" data-lm-event="email">Write to the dealer</a>.' : '' ) . '</p>' .
			'<p class="lmf-inq-msg" role="status" aria-live="polite"></p></form></details>' .
			'<script>(function(c){var d=document.getElementById(' . wp_json_encode( $id ) . ');if(!d)return;var f=d.querySelector("form"),m=d.querySelector(".lmf-inq-msg"),b=f.querySelector("button");' .
			'f.addEventListener("submit",function(e){e.preventDefault();var o={post:c.post};new FormData(f).forEach(function(v,k){o[k]=v;});' .
			'if(!o.name||!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(o.email||"")||!o.message){m.textContent="Please add your name, a valid email and a message.";return;}' .
			'b.disabled=true;m.textContent="Sending…";fetch(c.url,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(o)}).then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j};});}).then(function(r){' .
			'if(r.ok){f.innerHTML="<p class=\"lmf-inq-done\"><strong>Message sent.</strong> "+c.dealer.replace(/[<>&]/g,"")+" will answer you by email or phone.</p>";window.dataLayer=window.dataLayer||[];window.dataLayer.push({event:"inquiry_submit",lm_dealer:c.dealer,lm_item:c.title});}' .
			'else{m.textContent=(r.j&&r.j.message)||"Could not send. Please try again or call the dealer.";b.disabled=false;}}).catch(function(){m.textContent="Could not send. Please try again or call the dealer.";b.disabled=false;});});' .
			'})(' . wp_json_encode( $cfg ) . ');</script>';
	}

	/* ------------------------------------------------------------------ REST */

	public static function routes() {
		register_rest_route( LM_Sync::NS, '/inquiry', [ 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => [ __CLASS__, 'submit' ] ] );
		$signed = [ 'LM_Sync', 'verify' ];
		register_rest_route( LM_Sync::NS, '/sync/inquiries', [ 'methods' => 'GET', 'permission_callback' => $signed, 'callback' => [ __CLASS__, 'pending' ] ] );
		register_rest_route( LM_Sync::NS, '/sync/inquiries/ack', [ 'methods' => 'POST', 'permission_callback' => $signed, 'callback' => [ __CLASS__, 'ack' ] ] );
		register_rest_route( LM_Sync::NS, '/sync/stats', [ 'methods' => 'GET', 'permission_callback' => $signed, 'callback' => [ __CLASS__, 'stats' ] ] );
	}

	private static function fail( string $msg, int $status = 400 ) {
		return new WP_Error( 'lm_inquiry', $msg, [ 'status' => $status ] );
	}

	public static function submit( WP_REST_Request $req ) {
		$b = json_decode( $req->get_body(), true ) ?: [];
		$post = get_post( absint( $b['post'] ?? 0 ) );
		if ( ! $post || $post->post_type !== LM_Model::PT || $post->post_status !== 'publish' || ! self::enabled_for( $post->ID ) ) return self::fail( 'This listing does not take messages. Please call the dealer.' );
		// Bots: hidden field filled, or posted too fast / with an old or forged token. Answer "ok" so they don't retry.
		if ( ! empty( $b['company_website'] ) || ! self::token_ok( (string) ( $b['t'] ?? '' ) ) ) return [ 'ok' => true ];

		$name = sanitize_text_field( (string) ( $b['name'] ?? '' ) );
		$email = sanitize_email( (string) ( $b['email'] ?? '' ) );
		$phone = preg_replace( '/[^0-9+()\-.\s]/', '', (string) ( $b['phone'] ?? '' ) );
		$message = sanitize_textarea_field( (string) ( $b['message'] ?? '' ) );
		if ( $name === '' || ! is_email( $email ) || $message === '' ) return self::fail( 'Please add your name, a valid email and a message.' );
		if ( mb_strlen( $message ) > 3000 ) return self::fail( 'The message is too long.' );
		if ( preg_match_all( '#https?://#i', $message ) > 2 ) return self::fail( 'Please remove the links from your message.' );

		global $wpdb;
		$ip = substr( hash_hmac( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), wp_salt( 'auth' ) ), 0, 16 );
		$recent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ip_hash=%s AND created>%s', $ip, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		if ( $recent >= self::PER_HOUR ) return self::fail( 'Too many messages from this connection. Please call the dealer.', 429 );

		$row = [
			'created' => gmdate( 'Y-m-d H:i:s' ), 'post_id' => $post->ID, 'yard_id' => (string) get_post_meta( $post->ID, 'lm_yard_id', true ),
			'dealer_id' => (int) $post->post_author, 'name' => mb_substr( $name, 0, 120 ), 'email' => mb_substr( $email, 0, 190 ),
			'phone' => mb_substr( trim( $phone ), 0, 40 ), 'message' => $message, 'url' => get_permalink( $post ), 'ip_hash' => $ip,
		];
		$wpdb->insert( self::table(), $row );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) return self::fail( 'Could not send. Please try again or call the dealer.', 500 );

		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . LM_Stats::table() . ' (day,object_id,dealer_id,kind,hits) VALUES (%s,%d,%d,%s,1) ON DUPLICATE KEY UPDATE hits=hits+1',
			wp_date( 'Y-m-d' ), $post->ID, (int) $post->post_author, 'inquiry' ) );

		self::forward( $id );
		return [ 'ok' => true ];
	}

	private static function payload( object $r ): array {
		return [ 'id' => (int) $r->id, 'yard_id' => $r->yard_id, 'wp_dealer_id' => (int) $r->dealer_id, 'name' => $r->name, 'email' => $r->email,
			'phone' => $r->phone, 'message' => $r->message, 'url' => $r->url, 'created' => gmdate( 'c', strtotime( $r->created . ' UTC' ) ) ];
	}

	/** Send one inquiry to Yard now. On failure it stays pending for Yard's nightly pass. */
	private static function forward( int $id ): void {
		global $wpdb;
		$secret = (string) get_option( LM_Sync::OPTION, '' );
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d', $id ) );
		if ( ! $r || $secret === '' ) return;
		$body = wp_json_encode( self::payload( $r ) );
		$ts = (string) time();
		$res = wp_remote_post( self::yard_url() . '/api/v1/hooks/wp/inquiry', [
			'timeout' => 8,
			'headers' => [ 'Content-Type' => 'application/json', 'X-WP-Timestamp' => $ts, 'X-WP-Signature' => hash_hmac( 'sha256', 'wp-hook.' . $ts . '.' . $body, $secret ) ],
			'body'    => $body,
		] );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			$wpdb->update( self::table(), [ 'forwarded' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => $id ] );
		} else {
			error_log( '[lm_inquiry] Yard did not take inquiry ' . $id . ': ' . ( is_wp_error( $res ) ? $res->get_error_message() : $code ) );
		}
	}

	public static function pending() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE forwarded IS NULL ORDER BY id ASC LIMIT 200' );
		return [ 'inquiries' => array_map( [ __CLASS__, 'payload' ], $rows ) ];
	}

	public static function ack( WP_REST_Request $req ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', (array) ( json_decode( $req->get_body(), true )['ids'] ?? [] ) ) ) );
		if ( ! $ids ) return [ 'ok' => true, 'updated' => 0 ];
		$in = implode( ',', $ids );
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET forwarded=%s WHERE forwarded IS NULL AND id IN ($in)", gmdate( 'Y-m-d H:i:s' ) ) );
		return [ 'ok' => true, 'updated' => (int) $n ];
	}

	/** Daily counts of Yard units (views, contact clicks, inquiries) since a date. */
	public static function stats( WP_REST_Request $req ) {
		global $wpdb;
		$since = (string) $req->get_param( 'since' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) $since = wp_date( 'Y-m-d', time() - 3 * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT pm.meta_value yard_id, s.day, s.kind, SUM(s.hits) hits FROM ' . LM_Stats::table() . " s JOIN {$wpdb->postmeta} pm ON pm.post_id=s.object_id AND pm.meta_key='lm_yard_id' " .
			'WHERE s.day>=%s AND s.object_id>0 GROUP BY pm.meta_value, s.day, s.kind', $since ) );
		return [ 'since' => $since, 'rows' => array_map( fn( $r ) => [ 'yard_id' => $r->yard_id, 'day' => $r->day, 'kind' => $r->kind, 'hits' => (int) $r->hits ], $rows ) ];
	}

	/** Personal data stays here only as a backup until Yard has it. */
	public static function purge() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE forwarded IS NOT NULL AND forwarded<%s', gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
	}
}
