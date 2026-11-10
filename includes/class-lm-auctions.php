<?php
/**
 * Auction calendar (phase A of the auctions business): dealers' and auctioneers' upcoming auctions.
 * No bidding here: each auction links to its own catalog. Paid placement = "featured" (lm_featured): first inside its
 * week in the calendar (max. 3 per week, enforced on save), and featured auctions with close dates rotate on every visit.
 *
 *   /auctions/                 upcoming auctions by date (?state=PA filter)
 *   /auctions/<slug>/          one auction
 *   [lm_auctions count="6"]    upcoming auctions as cards; main="1" = the archive list
 *   [lm_auctions_featured count="3" min="6" title="Featured auctions"]  featured upcoming auctions, full width,
 *                              shuffled on every visit (min = only with that many upcoming; title="" = no heading).
 *                              Lists placed after it on the same page skip the auctions it showed.
 *   [lm_auction_facts]         single auction: date, type, place, auctioneer, catalog button
 *   /auctions/YYYY-MM/         auctions of one month, past ones included (query var lm_month)
 *   [lm_auctions_title]        h1 + intro for the calendar or the month page
 *   [lm_auction_months]        month chips: this month and the next ones with auctions
 *
 * Times are the auction's local time (site timezone, America/New_York), stored as "YYYY-MM-DDTHH:MM".
 */
defined( 'ABSPATH' ) || exit;

class LM_Auctions {
	const PT    = 'lm_auction';
	const TYPES = [ 'live' => 'Live auction', 'online' => 'Online auction', 'timed' => 'Timed online auction', 'live-online' => 'Live + online bidding',
		'live-online-phone' => 'Onsite, online & phone bidding' ];
	const META  = [
		'lm_start'      => [ 'type' => 'string' ],
		'lm_end'        => [ 'type' => 'string' ],
		'lm_type'       => [ 'type' => 'string', 'enum' => [ 'live', 'online', 'timed', 'live-online', 'live-online-phone' ] ],
		'lm_auctioneer' => [ 'type' => 'string' ], // name when the auctioneer has no dealer account (the author otherwise)
		'lm_address'    => [ 'type' => 'string' ],
		'lm_city'       => [ 'type' => 'string' ],
		'lm_state'      => [ 'type' => 'string' ],
		'lm_zip'        => [ 'type' => 'string' ],
		'lm_url'        => [ 'type' => 'string', 'format' => 'uri' ], // catalog / bidding page on the auctioneer's site
		'lm_featured'   => [ 'type' => 'boolean' ],
	];
	const FEATURED_DAYS = 21; // featured block: auctions in the next 3 weeks
	const MAX_FEATURED_PER_WEEK = 3; // paid spots per calendar week (Mon–Sun): keeps featured worth paying for

	/** Auctions already shown in a featured block on this page: the lists below skip them. */
	private static array $shown = [];

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'archive_query' ] );
		add_action( 'add_meta_boxes_' . self::PT, function () {
			add_meta_box( 'lm_auction', 'Auction details', [ __CLASS__, 'box' ], self::PT, 'normal', 'high' );
		} );
		add_action( 'save_post_' . self::PT, [ __CLASS__, 'save_box' ], 10, 2 );
		add_action( 'admin_notices', [ __CLASS__, 'featured_full_notice' ] );
		foreach ( [ 'auctions', 'auctions_featured', 'auction_facts', 'auctions_title', 'auction_months' ] as $s ) add_shortcode( 'lm_' . $s, [ __CLASS__, $s ] );
		add_filter( 'query_vars', fn( $v ) => array_merge( $v, [ 'lm_month' ] ) );
		add_action( 'init', function () {
			add_rewrite_rule( '^auctions/(\\d{4}-\\d{2})/?$', 'index.php?post_type=' . self::PT . '&lm_month=$matches[1]', 'top' );
		}, 15 ); // before LM_Model::maybe_upgrade (20) flushes the rules
		add_action( 'wp_footer', [ __CLASS__, 'datalayer' ], 5 ); // after the consent defaults in <head>
	}

	public static function register() {
		register_post_type( self::PT, [
			'labels'       => [ 'name' => 'Auctions', 'singular_name' => 'Auction', 'add_new_item' => 'Add auction', 'edit_item' => 'Edit auction',
				'all_items' => 'All auctions', 'not_found' => 'No auctions found', 'menu_name' => 'Auctions' ],
			'public'       => true,
			'has_archive'  => 'auctions',
			'rewrite'      => [ 'slug' => 'auctions', 'with_front' => false ],
			'menu_icon'    => 'dashicons-megaphone',
			'menu_position' => 6,
			'supports'     => [ 'title', 'editor', 'thumbnail', 'author', 'custom-fields' ],
			'show_in_rest' => true,
			'rest_base'    => 'auctions',
		] );
		$auth = fn( $allowed, $key, $post_id ) => current_user_can( 'edit_post', $post_id );
		foreach ( self::META as $key => $schema ) {
			register_post_meta( self::PT, $key, [ 'type' => $schema['type'], 'single' => true, 'show_in_rest' => [ 'schema' => $schema ], 'auth_callback' => $auth ] );
		}
	}

	/* ------------------------------------------------------------------ helpers */

	private static function m( int $id, string $k ) { return get_post_meta( $id, $k, true ); }

	public static function now(): string { return wp_date( 'Y-m-d\TH:i' ); }

	/** DateTimeImmutable in the site timezone, or null. */
	public static function dt( string $v ): ?DateTimeImmutable {
		if ( $v === '' ) return null;
		$d = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', substr( $v, 0, 16 ), wp_timezone() );
		return $d ?: null;
	}

	public static function auctioneer( int $id ): string {
		$name = (string) self::m( $id, 'lm_auctioneer' );
		if ( $name !== '' ) return $name;
		$u = (int) get_post_field( 'post_author', $id );
		return (string) get_user_meta( $u, 'lm_company', true ) ?: get_the_author_meta( 'display_name', $u );
	}

	/** Online-only auction with no physical place given. */
	public static function online_only( int $id ): bool {
		return in_array( self::m( $id, 'lm_type' ), [ 'online', 'timed' ], true ) && self::m( $id, 'lm_city' ) === '';
	}

	/** "Littlestown, PA", or "Online" for an online auction without a city. */
	public static function place( int $id ): string {
		if ( self::online_only( $id ) ) return 'Online';
		return implode( ', ', array_filter( [ self::m( $id, 'lm_city' ), self::m( $id, 'lm_state' ) ] ) );
	}

	public static function type_label( int $id ): string { return self::TYPES[ self::m( $id, 'lm_type' ) ] ?? 'Auction'; }

	/** Ended = its end (or start) is in the past. */
	public static function ended( int $id ): bool {
		$end = self::m( $id, 'lm_end' ) ?: self::m( $id, 'lm_start' );
		return $end !== '' && substr( $end, 0, 16 ) < self::now();
	}

	public static function when( int $id ): string {
		$s = self::dt( (string) self::m( $id, 'lm_start' ) );
		if ( ! $s ) return '';
		$e = self::dt( (string) self::m( $id, 'lm_end' ) );
		$txt = $s->format( 'l, F j, Y \a\t g:i A' );
		if ( $e && $e->format( 'Y-m-d' ) !== $s->format( 'Y-m-d' ) ) $txt = $s->format( 'F j' ) . ' – ' . $e->format( 'F j, Y' ) . ' (starts ' . $s->format( 'g:i A' ) . ')';
		return $txt . ' ET';
	}

	/** Card time: "9:00 AM ET", or "Closes Nov 17, 9:00 AM ET" for a timed auction that runs several days. */
	private static function time_text( int $id ): string {
		$s = self::dt( (string) self::m( $id, 'lm_start' ) );
		$e = self::dt( (string) self::m( $id, 'lm_end' ) );
		if ( $e && $s && $e->format( 'Y-m-d' ) !== $s->format( 'Y-m-d' ) ) return 'Closes ' . $e->format( 'M j, g:i A' ) . ' ET';
		return $s ? $s->format( 'g:i A' ) . ' ET' : '';
	}

	/** The month page being viewed ("2026-12"), or ''. */
	public static function month(): string {
		$m = (string) get_query_var( 'lm_month' );
		return preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $m ) ? $m : '';
	}

	public static function month_label( string $m ): string {
		$d = DateTimeImmutable::createFromFormat( 'Y-m-d', $m . '-01', wp_timezone() );
		return $d ? $d->format( 'F Y' ) : $m;
	}

	public static function month_url( string $m ): string { return home_url( '/auctions/' . $m . '/' ); }

	/** Upcoming auction ids by date. */
	private static function upcoming( array $extra = [] ): array {
		return get_posts( array_merge( [
			'post_type' => self::PT, 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 50, 'no_found_rows' => true,
			'meta_key' => 'lm_start', 'orderby' => 'meta_value', 'order' => 'ASC',
			'meta_query' => [ [ 'key' => 'lm_start', 'value' => self::now(), 'compare' => '>=' ] ],
		], $extra ) );
	}

	/** ISO week of the auction start ("2026-W50"): featured auctions go first inside their own week. */
	private static function week( int $id ): string {
		$d = self::dt( (string) self::m( $id, 'lm_start' ) );
		return $d ? $d->format( 'o-\\WW' ) : '';
	}

	/** Keeps date order between weeks; inside each week, featured auctions first (each group still by date). */
	public static function by_week( array $ids ): array {
		$pos = array_flip( $ids );
		usort( $ids, function ( $a, $b ) use ( $pos ) {
			$wa = self::week( $a ); $wb = self::week( $b );
			if ( $wa !== $wb ) return $pos[ $a ] <=> $pos[ $b ];
			$fa = (bool) self::m( $a, 'lm_featured' ); $fb = (bool) self::m( $b, 'lm_featured' );
			return $fa === $fb ? $pos[ $a ] <=> $pos[ $b ] : ( $fa ? -1 : 1 );
		} );
		return $ids;
	}

	/** Other featured auctions in the same week as $id. */
	public static function featured_in_week( int $id, string $start ): array {
		$d = self::dt( $start );
		if ( ! $d ) return [];
		$mon = $d->modify( 'monday this week' )->format( 'Y-m-d' ) . 'T00:00';
		$next = $d->modify( 'monday next week' )->format( 'Y-m-d' ) . 'T00:00';
		return get_posts( [ 'post_type' => self::PT, 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 20, 'no_found_rows' => true,
			'post__not_in' => [ $id ], 'meta_query' => [ 'relation' => 'AND',
				[ 'key' => 'lm_start', 'value' => [ $mon, $next ], 'compare' => 'BETWEEN' ],
				[ 'key' => 'lm_featured', 'value' => '1' ] ] ] );
	}

	/* ------------------------------------------------------------------ archive */

	public static function archive_query( WP_Query $q ) {
		if ( is_admin() || ! $q->is_main_query() || ! $q->is_post_type_archive( self::PT ) ) return;
		$q->set( 'posts_per_page', 30 );
		$q->set( 'meta_key', 'lm_start' );
		$q->set( 'orderby', 'meta_value' );
		$q->set( 'order', 'ASC' );
		$mq = [ [ 'key' => 'lm_start', 'value' => self::now(), 'compare' => '>=' ] ];
		$month = self::month();
		if ( $month ) {
			$next = ( new DateTimeImmutable( $month . '-01' ) )->modify( '+1 month' )->format( 'Y-m' );
			$mq = [ [ 'key' => 'lm_start', 'value' => [ $month . '-01T00:00', $next . '-01T00:00' ], 'compare' => 'BETWEEN' ] ];
		}
		$st = isset( $_GET['state'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['state'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $st ) $mq[] = [ 'key' => 'lm_state', 'value' => $st ];
		$q->set( 'meta_query', $mq );
	}

	/* ------------------------------------------------------------------ public pieces */

	private static function card( int $id, bool $badge = true ): string {
		$s = self::dt( (string) self::m( $id, 'lm_start' ) );
		$img = get_the_post_thumbnail( $id, 'medium_large', [ 'loading' => 'lazy', 'alt' => esc_attr( get_the_title( $id ) ) ] );
		$feat = $badge && self::m( $id, 'lm_featured' );
		return '<article class="lmf-auction' . ( $feat ? ' is-featured' : '' ) . '">' .
			'<a class="lmf-auction-date" href="' . esc_url( get_permalink( $id ) ) . '"' . self::track( $id, 'auction_open', 'list' ) . '>' . ( $s ? '<span>' . esc_html( $s->format( 'M' ) ) . '</span><strong>' . esc_html( $s->format( 'j' ) ) . '</strong><em>' . esc_html( $s->format( 'D' ) ) . '</em>' : '' ) . '</a>' .
			( $img ? '<a class="lmf-auction-img" href="' . esc_url( get_permalink( $id ) ) . '"' . self::track( $id, 'auction_open', 'list' ) . '>' . $img . '</a>' : '' ) .
			'<div class="lmf-auction-body">' .
			'<span class="lmf-kicker">' . esc_html( self::type_label( $id ) ) . ( $feat ? ' · Featured' : '' ) . '</span>' .
			'<h3><a href="' . esc_url( get_permalink( $id ) ) . '"' . self::track( $id, 'auction_open', 'list' ) . '>' . esc_html( get_the_title( $id ) ) . '</a></h3>' .
			'<p class="lmf-meta">' . esc_html( implode( ' · ', array_filter( [ self::auctioneer( $id ), self::place( $id ), self::time_text( $id ) ] ) ) ) . '</p>' .
			'</div></article>';
	}

	/** Data attributes read by GTM on auction links (see medicion/README: select_auction, click_auction_catalog). */
	private static function track( int $id, string $event, string $placement ): string {
		$s = self::dt( (string) self::m( $id, 'lm_start' ) );
		return ' data-lm-event="' . esc_attr( $event ) . '" data-lm-placement="' . esc_attr( $placement ) . '"' .
			' data-lm-auction-id="' . $id . '" data-lm-auction="' . esc_attr( get_the_title( $id ) ) . '"' .
			' data-lm-auctioneer="' . esc_attr( self::auctioneer( $id ) ) . '" data-lm-auction-date="' . esc_attr( $s ? $s->format( 'Y-m-d' ) : '' ) . '"' .
			' data-lm-auction-type="' . esc_attr( (string) self::m( $id, 'lm_type' ) ) . '" data-lm-featured="' . ( self::m( $id, 'lm_featured' ) ? 'yes' : 'no' ) . '"';
	}

	/** Featured auction: full width, date in an accent block, short description and both buttons. */
	private static function featured_card( int $id ): string {
		$s = self::dt( (string) self::m( $id, 'lm_start' ) );
		$url = (string) self::m( $id, 'lm_url' );
		$link = get_permalink( $id );
		$about = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) ), 28, '…' );
		return '<article class="lmf-fauction">' .
			'<a class="lmf-fauction-date" href="' . esc_url( $link ) . '"' . self::track( $id, 'auction_open', 'featured' ) . '>' . ( $s ? '<span>' . esc_html( $s->format( 'M' ) ) . '</span><strong>' . esc_html( $s->format( 'j' ) ) . '</strong><em>' . esc_html( $s->format( 'l' ) ) . '</em>' : '' ) . '</a>' .
			'<div class="lmf-fauction-body">' .
			'<span class="lmf-fauction-tag">Featured auction</span>' .
			'<span class="lmf-kicker">' . esc_html( self::type_label( $id ) ) . '</span>' .
			'<h3><a href="' . esc_url( $link ) . '"' . self::track( $id, 'auction_open', 'featured' ) . '>' . esc_html( get_the_title( $id ) ) . '</a></h3>' .
			'<p class="lmf-meta">' . esc_html( implode( ' · ', array_filter( [ self::auctioneer( $id ), self::place( $id ), self::time_text( $id ) ] ) ) ) . '</p>' .
			( $about ? '<p class="lmf-fauction-about">' . esc_html( $about ) . '</p>' : '' ) .
			'</div><div class="lmf-fauction-actions">' .
			( $url ? '<a class="lmf-btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener"' . self::track( $id, 'auction_catalog', 'featured' ) . '>View catalog &amp; bidding ↗</a>' : '' ) .
			'<a class="lmf-btn ghost" href="' . esc_url( $link ) . '"' . self::track( $id, 'auction_open', 'featured' ) . '>Auction details</a>' .
			'</div></article>';
	}

	/** List of auction cards for the given ids (dealer profiles). */
	public static function cards( array $ids ): string {
		return $ids ? '<div class="lmf-auctions">' . implode( '', array_map( fn( $id ) => self::card( (int) $id ), $ids ) ) . '</div>' : '';
	}

	/** States of the upcoming auctions: "Pennsylvania" while all are in PA. */
	public static function states_label(): string {
		$st = [];
		foreach ( self::upcoming() as $id ) { $s = strtoupper( (string) self::m( $id, 'lm_state' ) ); if ( $s ) $st[ $s ] = 1; }
		return count( $st ) === 1 && isset( $st['PA'] ) ? 'Pennsylvania' : '';
	}

	public static function auctions_title() {
		$where = self::states_label();
		$month = self::month();
		if ( $month ) {
			$h = 'Equipment auctions' . ( $where ? ' in ' . $where : '' ) . ': ' . self::month_label( $month );
			$p = 'Construction, farm and truck auctions scheduled for ' . self::month_label( $month ) . ', with dates, locations, auction type and a link to each catalog.';
		} else {
			$h = 'Heavy equipment auctions' . ( $where ? ' in ' . $where : '' );
			$p = 'Upcoming construction, farm and truck auctions from dealers and auctioneers: dates, locations, auction type and catalogs. Bidding happens on each auctioneer\'s own site.';
		}
		return '<h1 class="lmf-h1">' . esc_html( $h ) . '</h1><p class="lmf-lead">' . esc_html( $p ) . '</p>';
	}

	/** Chips: All upcoming · this month · the next 3 months that have auctions. */
	public static function auction_months() {
		$cur = self::month();
		$months = [];
		foreach ( self::upcoming() as $id ) { $m = substr( (string) self::m( $id, 'lm_start' ), 0, 7 ); if ( $m ) $months[ $m ] = ( $months[ $m ] ?? 0 ) + 1; }
		ksort( $months );
		$months = array_slice( $months, 0, 4, true );
		if ( $cur && ! isset( $months[ $cur ] ) ) $months = [ $cur => 0 ] + $months;
		$out = '<a class="lmf-chip' . ( $cur ? '' : ' on' ) . '" href="' . esc_url( get_post_type_archive_link( self::PT ) ) . '">All upcoming</a>';
		foreach ( $months as $m => $n ) {
			$out .= '<a class="lmf-chip' . ( $cur === $m ? ' on' : '' ) . '" href="' . esc_url( self::month_url( $m ) ) . '">' . esc_html( self::month_label( $m ) ) . ( $n ? ' <span>' . $n . '</span>' : '' ) . '</a>';
		}
		return '<nav class="lmf-chips lmf-months" aria-label="Auctions by month">' . $out . '</nav>';
	}

	public static function auctions( $atts ) {
		$a = shortcode_atts( [ 'count' => 6, 'main' => '0', 'cities' => '' ], $atts );
		if ( $a['main'] === '1' ) {
			global $wp_query;
			if ( ! $wp_query->have_posts() && self::month() ) return '<div class="lmf-empty"><p><strong>No auctions listed for ' . esc_html( self::month_label( self::month() ) ) . ' yet.</strong></p><p><a href="' . esc_url( get_post_type_archive_link( self::PT ) ) . '">See all upcoming auctions</a></p></div>';
			if ( ! $wp_query->have_posts() ) return '<div class="lmf-empty"><p><strong>No upcoming auctions listed yet.</strong></p><p>Running an equipment auction? <a href="mailto:contact@heavylisting.com">List it for free</a>.</p></div>';
			$ids = self::by_week( array_values( array_diff( wp_list_pluck( $wp_query->posts, 'ID' ), self::$shown ) ) );
			$pages = paginate_links( [ 'total' => $wp_query->max_num_pages, 'current' => max( 1, get_query_var( 'paged' ) ), 'type' => 'list', 'prev_text' => '←', 'next_text' => '→' ] );
			return '<div class="lmf-auctions">' . implode( '', array_map( [ __CLASS__, 'card' ], $ids ) ) . '</div>' . ( $pages ? '<nav class="lmf-pages">' . $pages . '</nav>' : '' );
		}
		$extra = [];
		if ( $c = LM_Front::cities_query( $a['cities'] ) ) $extra['meta_query'] = [ 'relation' => 'AND', [ 'key' => 'lm_start', 'value' => self::now(), 'compare' => '>=' ], $c ];
		$ids = self::by_week( array_slice( array_values( array_diff( self::upcoming( $extra ), self::$shown ) ), 0, max( 1, min( 24, (int) $a['count'] ) ) ) );
		if ( ! $ids && $a['cities'] ) return '<div class="lmf-empty"><p>No auctions listed in this area right now. <a href="' . esc_url( get_post_type_archive_link( self::PT ) ) . '">See all upcoming auctions in Pennsylvania</a>.</p></div>';
		return $ids ? '<div class="lmf-auctions">' . implode( '', array_map( [ __CLASS__, 'card' ], $ids ) ) . '</div>' : '';
	}

	/** Featured auctions in the next weeks, in a new random order on every visit (paid placement rotates). */
	public static function auctions_featured( $atts ) {
		$a = shortcode_atts( [ 'count' => 3, 'min' => 0, 'title' => 'Featured auctions' ], $atts );
		// With few auctions the featured row would just repeat the list below: show it only past "min" upcoming.
		if ( (int) $a['min'] && count( self::upcoming() ) < (int) $a['min'] ) return '';
		// Month page: that month's featured auctions; elsewhere, the next 3 weeks.
		$month = self::month();
		$from = self::now();
		$limit = wp_date( 'Y-m-d\TH:i', time() + self::FEATURED_DAYS * DAY_IN_SECONDS );
		if ( $month ) {
			$from = max( $from, $month . '-01T00:00' );
			$limit = ( new DateTimeImmutable( $month . '-01' ) )->modify( '+1 month' )->format( 'Y-m' ) . '-01T00:00';
		}
		$ids = self::upcoming( [ 'meta_query' => [ 'relation' => 'AND',
			[ 'key' => 'lm_start', 'value' => $from, 'compare' => '>=' ],
			[ 'key' => 'lm_start', 'value' => $limit, 'compare' => '<' ],
			[ 'key' => 'lm_featured', 'value' => '1' ] ] ] );
		if ( ! $ids ) return '';
		shuffle( $ids );
		$ids = array_slice( $ids, 0, max( 1, min( 12, (int) $a['count'] ) ) );
		self::$shown = array_merge( self::$shown, $ids );
		return ( $a['title'] !== '' ? '<h2 class="lmf-h2">' . esc_html( $a['title'] ) . '</h2>' : '' ) .
			'<div class="lmf-auctions is-featured-row">' . implode( '', array_map( [ __CLASS__, 'featured_card' ], $ids ) ) . '</div>';
	}

	public static function auction_facts() {
		$id = get_the_ID();
		if ( get_post_type( $id ) !== self::PT ) return '';
		$addr = self::online_only( $id ) ? 'Online' : implode( ', ', array_filter( [ self::m( $id, 'lm_address' ), trim( self::place( $id ) . ' ' . self::m( $id, 'lm_zip' ) ) ] ) );
		$rows = array_filter( [ 'When' => self::when( $id ), 'Type' => self::type_label( $id ), 'Where' => $addr, 'Auctioneer' => self::auctioneer( $id ) ] );
		$out = '';
		foreach ( $rows as $k => $v ) $out .= '<div><dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd></div>';
		$url = (string) self::m( $id, 'lm_url' );
		return ( self::ended( $id ) ? '<p class="lmf-ended">This auction has ended. <a href="' . esc_url( get_post_type_archive_link( self::PT ) ) . '">See upcoming auctions</a></p>' : '' ) .
			'<dl class="lmf-facts">' . $out . '</dl>' .
			( $url ? '<a class="lmf-btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener"' . self::track( $id, 'auction_catalog', 'auction_page' ) . '>View catalog &amp; bidding ↗</a>' : '' );
	}

	/** On an auction page, tell GTM which auction it is (event hl_auction_view → GA4 view_auction). */
	public static function datalayer() {
		if ( ! is_singular( self::PT ) ) return;
		$id = get_queried_object_id();
		$s = self::dt( (string) self::m( $id, 'lm_start' ) );
		$d = [ 'event' => 'hl_auction_view', 'auction_id' => (string) $id, 'auction_name' => get_the_title( $id ), 'auctioneer' => self::auctioneer( $id ),
			'auction_date' => $s ? $s->format( 'Y-m-d' ) : '', 'auction_type' => (string) self::m( $id, 'lm_type' ),
			'featured' => self::m( $id, 'lm_featured' ) ? 'yes' : 'no', 'auction_state' => (string) self::m( $id, 'lm_state' ) ];
		echo '<script>window.dataLayer=window.dataLayer||[];dataLayer.push(' . wp_json_encode( $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . ');</script>' . "\n";
	}

	/* ------------------------------------------------------------------ admin box */

	public static function box( WP_Post $post ) {
		wp_nonce_field( 'lm_auction', 'lm_auction_nonce' );
		$v = fn( $k ) => esc_attr( (string) get_post_meta( $post->ID, $k, true ) );
		$type = (string) get_post_meta( $post->ID, 'lm_type', true );
		$opts = '';
		foreach ( self::TYPES as $k => $label ) $opts .= '<option value="' . esc_attr( $k ) . '"' . selected( $type, $k, false ) . '>' . esc_html( $label ) . '</option>';
		$f = fn( $k, $label, $type = 'text', $extra = '' ) => '<p><label><strong>' . esc_html( $label ) . '</strong><br><input type="' . $type . '" name="' . $k . '" value="' . $v( $k ) . '" class="widefat" ' . $extra . '></label></p>';
		echo $f( 'lm_start', 'Start (local time, ET)', 'datetime-local' ) . $f( 'lm_end', 'End (optional)', 'datetime-local' ) . // phpcs:ignore WordPress.Security.EscapeOutput
			'<p><label><strong>Type</strong><br><select name="lm_type" class="widefat">' . $opts . '</select></label></p>' .
			$f( 'lm_auctioneer', 'Auctioneer name (leave empty to use the author dealer)' ) .
			$f( 'lm_address', 'Street address' ) . $f( 'lm_city', 'City' ) . $f( 'lm_state', 'State (2 letters)', 'text', 'maxlength="2"' ) . $f( 'lm_zip', 'ZIP' ) .
			$f( 'lm_url', 'Catalog / bidding URL', 'url' ) .
			'<p><label><input type="checkbox" name="lm_featured" value="1"' . checked( (bool) get_post_meta( $post->ID, 'lm_featured', true ), true, false ) . '> <strong>Featured</strong> (paid: Auction plan, max. ' . (int) self::MAX_FEATURED_PER_WEEK . ' per week)</label></p>';
	}

	public static function save_box( int $id, WP_Post $post ) {
		if ( ! isset( $_POST['lm_auction_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['lm_auction_nonce'] ), 'lm_auction' ) || ! current_user_can( 'edit_post', $id ) ) return;
		foreach ( [ 'lm_start', 'lm_end', 'lm_type', 'lm_auctioneer', 'lm_address', 'lm_city', 'lm_zip' ] as $k ) {
			update_post_meta( $id, $k, sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ) );
		}
		update_post_meta( $id, 'lm_state', strtoupper( substr( sanitize_text_field( wp_unslash( $_POST['lm_state'] ?? '' ) ), 0, 2 ) ) );
		update_post_meta( $id, 'lm_url', esc_url_raw( wp_unslash( $_POST['lm_url'] ?? '' ) ) );
		$feat = ! empty( $_POST['lm_featured'] );
		if ( $feat && count( self::featured_in_week( $id, (string) get_post_meta( $id, 'lm_start', true ) ) ) >= self::MAX_FEATURED_PER_WEEK ) {
			$feat = false; // week is full: saved as not featured, with a notice
			set_transient( 'lm_featured_full_' . get_current_user_id(), get_the_title( $id ), 60 );
		}
		update_post_meta( $id, 'lm_featured', $feat );
	}

	public static function featured_full_notice() {
		$t = get_transient( 'lm_featured_full_' . get_current_user_id() );
		if ( ! $t ) return;
		delete_transient( 'lm_featured_full_' . get_current_user_id() );
		echo '<div class="notice notice-error"><p><strong>' . esc_html( $t ) . '</strong> was saved as not featured: that week already has ' .
			(int) self::MAX_FEATURED_PER_WEEK . ' featured auctions.</p></div>';
	}
}
