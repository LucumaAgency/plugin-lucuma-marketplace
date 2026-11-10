<?php
/**
 * Per-dealer results: listing views, auction views, profile views and contact clicks (call, email, website, catalog).
 *
 * - Counted in the browser (a small beacon), so bots that do not run JavaScript are left out.
 * - One view per visitor, item and day (localStorage), never for logged-in dealers or admins (the script is not printed for them).
 * - No personal data: the table only keeps day, item, dealer, kind and a count.
 * - Read by the dealer panel (#/results) and, for admins, across all dealers.
 */
defined( 'ABSPATH' ) || exit;

class LM_Stats {
	const DB_VERSION = '1';
	const KINDS = [ 'view', 'call', 'email', 'website', 'catalog' ];
	// data-lm-event values already used for GTM → kind stored here.
	const EVENTS = [ 'call' => 'call', 'email' => 'email', 'dealer_website' => 'website', 'source' => 'website', 'auction_catalog' => 'catalog' ];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lm_stats';
	}

	public static function init() {
		add_action( 'plugins_loaded', [ __CLASS__, 'install' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'wp_footer', [ __CLASS__, 'script' ], 50 );
	}

	public static function install() {
		if ( get_option( 'lm_stats_db' ) === self::DB_VERSION ) return;
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . ' (
			day date NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			dealer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(12) NOT NULL,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,object_id,dealer_id,kind),
			KEY dealer_day (dealer_id,day)
		) ' . $wpdb->get_charset_collate() . ';' );
		update_option( 'lm_stats_db', self::DB_VERSION, false );
	}

	/* ------------------------------------------------------------------ tracking */

	/** Page context for the beacon: what is being viewed and whose it is. */
	private static function context(): array {
		if ( is_singular( LM_Model::PT ) ) {
			$id = get_queried_object_id();
			return [ 'o' => $id, 'd' => (int) get_post_field( 'post_author', $id ) ];
		}
		if ( is_singular( LM_Auctions::PT ) ) {
			$id = get_queried_object_id();
			return [ 'o' => $id, 'd' => (int) get_post_field( 'post_author', $id ) ];
		}
		if ( is_author() && LM_Dealers::is_dealer( get_queried_object() ) ) {
			return [ 'o' => 0, 'd' => (int) get_queried_object_id() ];
		}
		return [];
	}

	public static function script() {
		if ( is_admin() || current_user_can( 'edit_lm_equipments' ) || current_user_can( 'manage_options' ) ) return;
		$ctx = self::context();
		$cfg = [ 'url' => esc_url_raw( rest_url( 'lucuma-mkt/v1/hit' ) ), 'ctx' => $ctx ?: null, 'ev' => self::EVENTS ];
		?>
<script>(function(c){var day=new Date().toISOString().slice(0,10);function send(o,d,k){var b=JSON.stringify({o:o||0,d:d||0,k:k});if(navigator.sendBeacon){navigator.sendBeacon(c.url,new Blob([b],{type:'application/json'}));}else{fetch(c.url,{method:'POST',headers:{'Content-Type':'application/json'},body:b,keepalive:true});}}
if(c.ctx){var key='lmv:'+c.ctx.o+':'+c.ctx.d+':'+day,seen=false;try{seen=localStorage.getItem(key);localStorage.setItem(key,'1');}catch(e){}if(!seen)send(c.ctx.o,c.ctx.d,'view');}
document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('[data-lm-event]');if(!a)return;var k=c.ev[a.getAttribute('data-lm-event')];if(!k)return;
var o=parseInt(a.getAttribute('data-lm-auction-id')||a.getAttribute('data-lm-object-id')||0,10)||(c.ctx?c.ctx.o:0);var d=parseInt(a.getAttribute('data-lm-dealer-id')||0,10)||(c.ctx&&(!a.getAttribute('data-lm-auction-id')||+a.getAttribute('data-lm-auction-id')===c.ctx.o)?c.ctx.d:0);send(o,d,k);},true);
})(<?php echo wp_json_encode( $cfg ); ?>);</script>
		<?php
	}

	public static function routes() {
		register_rest_route( 'lucuma-mkt/v1', '/hit', [
			'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => [ __CLASS__, 'hit' ],
		] );
		register_rest_route( 'lucuma-mkt/v1', '/my/stats', [
			'methods' => 'GET', 'permission_callback' => fn() => current_user_can( 'edit_lm_equipments' ), 'callback' => [ __CLASS__, 'my_stats' ],
		] );
		register_rest_route( 'lucuma-mkt/v1', '/stats', [
			'methods' => 'GET', 'permission_callback' => fn() => current_user_can( 'edit_others_lm_equipments' ), 'callback' => [ __CLASS__, 'all_stats' ],
		] );
	}

	private static function is_bot(): bool {
		$ua = strtolower( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		return $ua === '' || (bool) preg_match( '/bot|crawl|spider|slurp|preview|headless|lighthouse|pingdom|monitor/', $ua );
	}

	public static function hit( WP_REST_Request $req ) {
		$b = json_decode( $req->get_body(), true ) ?: [];
		$o = absint( $b['o'] ?? 0 );
		$d = absint( $b['d'] ?? 0 );
		$k = sanitize_key( $b['k'] ?? '' );
		if ( ! in_array( $k, self::KINDS, true ) || self::is_bot() ) return new WP_REST_Response( null, 204 );
		if ( $o ) {
			$p = get_post( $o );
			if ( ! $p || $p->post_status !== 'publish' || ! in_array( $p->post_type, [ LM_Model::PT, LM_Auctions::PT ], true ) ) return new WP_REST_Response( null, 204 );
			$d = (int) $p->post_author; // the owner always comes from the item, never from the browser
		} elseif ( ! $d || ! LM_Dealers::is_dealer( get_userdata( $d ) ) ) {
			return new WP_REST_Response( null, 204 );
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::table() . ' (day,object_id,dealer_id,kind,hits) VALUES (%s,%d,%d,%s,1) ON DUPLICATE KEY UPDATE hits=hits+1',
			wp_date( 'Y-m-d' ), $o, $d, $k ) );
		return new WP_REST_Response( null, 204 );
	}

	/* ------------------------------------------------------------------ reports */

	private static function since( WP_REST_Request $req ): string {
		$days = max( 1, min( 365, (int) ( $req['days'] ?: 30 ) ) );
		return wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
	}

	private static function totals( array $rows ): array {
		$t = array_fill_keys( self::KINDS, 0 );
		foreach ( $rows as $r ) $t[ $r->kind ] += (int) $r->hits;
		return $t;
	}

	/** One dealer: totals, top items and first day with data. */
	public static function report( int $dealer, string $since ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_id, kind, SUM(hits) hits FROM ' . self::table() . ' WHERE dealer_id=%d AND day>=%s GROUP BY object_id, kind', $dealer, $since ) );
		$items = [];
		foreach ( $rows as $r ) {
			if ( ! $r->object_id ) continue;
			$items[ $r->object_id ] ??= array_fill_keys( self::KINDS, 0 );
			$items[ $r->object_id ][ $r->kind ] += (int) $r->hits;
		}
		uasort( $items, fn( $a, $b ) => ( $b['view'] <=> $a['view'] ) ?: ( ( $b['call'] + $b['email'] + $b['website'] + $b['catalog'] ) <=> ( $a['call'] + $a['email'] + $a['website'] + $a['catalog'] ) ) );
		$top = [];
		foreach ( array_slice( $items, 0, 10, true ) as $id => $k ) {
			$top[] = [ 'id' => $id, 'title' => html_entity_decode( get_the_title( $id ) ), 'link' => get_permalink( $id ), 'type' => get_post_type( $id ) === LM_Auctions::PT ? 'auction' : 'equipment' ] + $k;
		}
		$profile = 0;
		foreach ( $rows as $r ) if ( ! $r->object_id && $r->kind === 'view' ) $profile += (int) $r->hits;
		return [ 'since' => $since, 'totals' => self::totals( $rows ), 'profile_views' => $profile, 'top' => $top,
			'first_day' => (string) $wpdb->get_var( 'SELECT MIN(day) FROM ' . self::table() ) ];
	}

	public static function my_stats( WP_REST_Request $req ) {
		return self::report( get_current_user_id(), self::since( $req ) );
	}

	/** Admin: one row per dealer (auctions created by admins show up under that admin). */
	public static function all_stats( WP_REST_Request $req ) {
		global $wpdb;
		$since = self::since( $req );
		if ( $req['dealer'] ) return self::report( absint( $req['dealer'] ), $since );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT dealer_id, kind, SUM(hits) hits FROM ' . self::table() . ' WHERE day>=%s GROUP BY dealer_id, kind', $since ) );
		$by = [];
		foreach ( $rows as $r ) {
			$by[ $r->dealer_id ] ??= array_fill_keys( self::KINDS, 0 );
			$by[ $r->dealer_id ][ $r->kind ] += (int) $r->hits;
		}
		$out = [];
		foreach ( $by as $id => $k ) {
			$out[] = [ 'id' => (int) $id, 'company' => get_user_meta( $id, 'lm_company', true ) ?: ( get_userdata( $id )->display_name ?? '#' . $id ) ] + $k;
		}
		usort( $out, fn( $a, $b ) => $b['view'] <=> $a['view'] );
		return [ 'since' => $since, 'dealers' => $out, 'first_day' => (string) $wpdb->get_var( 'SELECT MIN(day) FROM ' . self::table() ) ];
	}
}
