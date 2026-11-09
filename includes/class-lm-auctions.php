<?php
/**
 * Auction calendar (phase A of the auctions business): dealers' and auctioneers' upcoming auctions.
 * No bidding here: each auction links to its own catalog. Paid placement = "featured" (lm_featured),
 * and featured auctions with close dates rotate on every visit so nobody hides anybody.
 *
 *   /auctions/                 upcoming auctions by date (?state=PA filter)
 *   /auctions/<slug>/          one auction
 *   [lm_auctions count="6"]    upcoming auctions as cards; main="1" = the archive list
 *   [lm_auctions_featured count="3" min="6"]  featured upcoming auctions, shuffled on every visit
 *                              (min = only when at least that many auctions are upcoming)
 *   [lm_auction_facts]         single auction: date, type, place, auctioneer, catalog button
 *
 * Times are the auction's local time (site timezone, America/New_York), stored as "YYYY-MM-DDTHH:MM".
 */
defined( 'ABSPATH' ) || exit;

class LM_Auctions {
	const PT    = 'lm_auction';
	const TYPES = [ 'live' => 'Live auction', 'online' => 'Online auction', 'timed' => 'Timed online auction', 'live-online' => 'Live + online bidding' ];
	const META  = [
		'lm_start'      => [ 'type' => 'string' ],
		'lm_end'        => [ 'type' => 'string' ],
		'lm_type'       => [ 'type' => 'string', 'enum' => [ 'live', 'online', 'timed', 'live-online' ] ],
		'lm_auctioneer' => [ 'type' => 'string' ], // name when the auctioneer has no dealer account (the author otherwise)
		'lm_address'    => [ 'type' => 'string' ],
		'lm_city'       => [ 'type' => 'string' ],
		'lm_state'      => [ 'type' => 'string' ],
		'lm_zip'        => [ 'type' => 'string' ],
		'lm_url'        => [ 'type' => 'string', 'format' => 'uri' ], // catalog / bidding page on the auctioneer's site
		'lm_featured'   => [ 'type' => 'boolean' ],
	];
	const FEATURED_DAYS = 21; // featured block: auctions in the next 3 weeks

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'archive_query' ] );
		add_action( 'add_meta_boxes_' . self::PT, function () {
			add_meta_box( 'lm_auction', 'Auction details', [ __CLASS__, 'box' ], self::PT, 'normal', 'high' );
		} );
		add_action( 'save_post_' . self::PT, [ __CLASS__, 'save_box' ], 10, 2 );
		foreach ( [ 'auctions', 'auctions_featured', 'auction_facts' ] as $s ) add_shortcode( 'lm_' . $s, [ __CLASS__, $s ] );
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

	/** Upcoming auction ids by date. */
	private static function upcoming( array $extra = [] ): array {
		return get_posts( array_merge( [
			'post_type' => self::PT, 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 50, 'no_found_rows' => true,
			'meta_key' => 'lm_start', 'orderby' => 'meta_value', 'order' => 'ASC',
			'meta_query' => [ [ 'key' => 'lm_start', 'value' => self::now(), 'compare' => '>=' ] ],
		], $extra ) );
	}

	/* ------------------------------------------------------------------ archive */

	public static function archive_query( WP_Query $q ) {
		if ( is_admin() || ! $q->is_main_query() || ! $q->is_post_type_archive( self::PT ) ) return;
		$q->set( 'posts_per_page', 30 );
		$q->set( 'meta_key', 'lm_start' );
		$q->set( 'orderby', 'meta_value' );
		$q->set( 'order', 'ASC' );
		$mq = [ [ 'key' => 'lm_start', 'value' => self::now(), 'compare' => '>=' ] ];
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
			'<a class="lmf-auction-date" href="' . esc_url( get_permalink( $id ) ) . '">' . ( $s ? '<span>' . esc_html( $s->format( 'M' ) ) . '</span><strong>' . esc_html( $s->format( 'j' ) ) . '</strong><em>' . esc_html( $s->format( 'D' ) ) . '</em>' : '' ) . '</a>' .
			( $img ? '<a class="lmf-auction-img" href="' . esc_url( get_permalink( $id ) ) . '">' . $img . '</a>' : '' ) .
			'<div class="lmf-auction-body">' .
			'<span class="lmf-kicker">' . esc_html( self::type_label( $id ) ) . ( $feat ? ' · Featured' : '' ) . '</span>' .
			'<h3><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></h3>' .
			'<p class="lmf-meta">' . esc_html( implode( ' · ', array_filter( [ self::auctioneer( $id ), self::place( $id ), $s ? $s->format( 'g:i A' ) . ' ET' : '' ] ) ) ) . '</p>' .
			'</div></article>';
	}

	public static function auctions( $atts ) {
		$a = shortcode_atts( [ 'count' => 6, 'main' => '0' ], $atts );
		if ( $a['main'] === '1' ) {
			global $wp_query;
			if ( ! $wp_query->have_posts() ) return '<div class="lmf-empty"><p><strong>No upcoming auctions listed yet.</strong></p><p>Running an equipment auction? <a href="mailto:contact@heavylisting.com">List it for free</a>.</p></div>';
			$ids = wp_list_pluck( $wp_query->posts, 'ID' );
			$pages = paginate_links( [ 'total' => $wp_query->max_num_pages, 'current' => max( 1, get_query_var( 'paged' ) ), 'type' => 'list', 'prev_text' => '←', 'next_text' => '→' ] );
			return '<div class="lmf-auctions">' . implode( '', array_map( [ __CLASS__, 'card' ], $ids ) ) . '</div>' . ( $pages ? '<nav class="lmf-pages">' . $pages . '</nav>' : '' );
		}
		$ids = array_slice( self::upcoming(), 0, max( 1, min( 24, (int) $a['count'] ) ) );
		return $ids ? '<div class="lmf-auctions">' . implode( '', array_map( [ __CLASS__, 'card' ], $ids ) ) . '</div>' : '';
	}

	/** Featured auctions in the next weeks, in a new random order on every visit (paid placement rotates). */
	public static function auctions_featured( $atts ) {
		$a = shortcode_atts( [ 'count' => 3, 'min' => 0 ], $atts );
		// With few auctions the featured row would just repeat the list below: show it only past "min" upcoming.
		if ( (int) $a['min'] && count( self::upcoming() ) < (int) $a['min'] ) return '';
		$limit = wp_date( 'Y-m-d\TH:i', time() + self::FEATURED_DAYS * DAY_IN_SECONDS );
		$ids = self::upcoming( [ 'meta_query' => [ 'relation' => 'AND',
			[ 'key' => 'lm_start', 'value' => self::now(), 'compare' => '>=' ],
			[ 'key' => 'lm_start', 'value' => $limit, 'compare' => '<=' ],
			[ 'key' => 'lm_featured', 'value' => '1' ] ] ] );
		if ( ! $ids ) return '';
		shuffle( $ids );
		$ids = array_slice( $ids, 0, max( 1, min( 12, (int) $a['count'] ) ) );
		return '<h2 class="lmf-h2">Featured auctions</h2><div class="lmf-auctions is-featured-row">' . implode( '', array_map( fn( $id ) => self::card( $id, true ), $ids ) ) . '</div>';
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
			( $url ? '<a class="lmf-btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener" data-lm-event="auction_catalog">View catalog &amp; bidding ↗</a>' : '' );
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
			'<p><label><input type="checkbox" name="lm_featured" value="1"' . checked( (bool) get_post_meta( $post->ID, 'lm_featured', true ), true, false ) . '> <strong>Featured</strong> (paid: Auction plan)</label></p>';
	}

	public static function save_box( int $id, WP_Post $post ) {
		if ( ! isset( $_POST['lm_auction_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['lm_auction_nonce'] ), 'lm_auction' ) || ! current_user_can( 'edit_post', $id ) ) return;
		foreach ( [ 'lm_start', 'lm_end', 'lm_type', 'lm_auctioneer', 'lm_address', 'lm_city', 'lm_zip' ] as $k ) {
			update_post_meta( $id, $k, sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ) );
		}
		update_post_meta( $id, 'lm_state', strtoupper( substr( sanitize_text_field( wp_unslash( $_POST['lm_state'] ?? '' ) ), 0, 2 ) ) );
		update_post_meta( $id, 'lm_url', esc_url_raw( wp_unslash( $_POST['lm_url'] ?? '' ) ) );
		update_post_meta( $id, 'lm_featured', empty( $_POST['lm_featured'] ) ? false : true );
	}
}
