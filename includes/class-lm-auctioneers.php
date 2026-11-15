<?php
/**
 * Auctioneer pages (SEO: "<auctioneer> auction", "<auctioneer> auction schedule").
 *
 *   /auctioneers/            WordPress page "auctioneers" with [lm_auctioneers]: every auctioneer in the calendar
 *   /auctioneers/<slug>/     the same page, one auctioneer: next sales, past sales, terms, FAQ (query var lm_auctioneer_slug)
 *
 * Each auctioneer is a term of the hidden taxonomy lm_auctioneer_org, assigned on its own from the auction's
 * auctioneer name (LM_Auctions::auctioneer: lm_auctioneer, or the author dealer's company), so nothing changes in the
 * auction import or in Yard. An auctioneer that is a dealer account (lm_dealer = user id) has no page of its own:
 * /auctioneers/<slug>/ redirects to the dealer profile.
 *
 * Term meta (REST /wp/v2/auctioneers, admins): lm_website, lm_phone, lm_address, lm_city, lm_state, lm_zip,
 * lm_about (html), lm_terms (html, buyer terms), lm_faq [{q, a}], lm_seo_title, lm_seo_desc, lm_dealer.
 * Indexable from MIN auctions listed or with an lm_about text; otherwise noindex, follow (thin page).
 */
defined( 'ABSPATH' ) || exit;

class LM_Auctioneers {
	const TAX     = 'lm_auctioneer_org';
	const BASE    = 'auctioneers';
	const QV      = 'lm_auctioneer_slug';
	const MIN     = 2;
	const SITEMAP = 'auctioneer-sitemap.xml';
	const BACKFILL = 'lm_auctioneers_backfill';
	const TEXT    = [ 'lm_website', 'lm_phone', 'lm_address', 'lm_city', 'lm_state', 'lm_zip', 'lm_seo_title', 'lm_seo_desc' ];

	private static $cur = false;

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'init', function () {
			add_rewrite_rule( '^' . self::BASE . '/([^/]+)/?$', 'index.php?pagename=' . self::BASE . '&' . self::QV . '=$matches[1]', 'top' );
		}, 15 ); // before LM_Model::maybe_upgrade (20) flushes the rules
		add_action( 'init', [ __CLASS__, 'backfill' ], 30 );
		add_filter( 'query_vars', fn( $v ) => array_merge( $v, [ self::QV ] ) );
		add_action( 'save_post_' . LM_Auctions::PT, fn( $id ) => self::assign( (int) $id ), 20 );        // admin box saves meta at 10
		add_action( 'rest_after_insert_' . LM_Auctions::PT, fn( $p ) => self::assign( (int) $p->ID ) ); // REST: meta is saved by now
		add_action( 'template_redirect', [ __CLASS__, 'route' ] );
		add_shortcode( 'lm_auctioneers', [ __CLASS__, 'shortcode' ] );
		add_filter( 'seopress_titles_title', [ __CLASS__, 'seo_title' ], 25 );
		add_filter( 'seopress_titles_desc', [ __CLASS__, 'seo_desc' ], 25 );
		add_filter( 'seopress_titles_robots', [ __CLASS__, 'robots' ], 25 );
		add_filter( 'seopress_titles_canonical', [ __CLASS__, 'canonical' ], 25 );
		add_filter( 'seopress_sitemaps_external_link', [ __CLASS__, 'sitemap_index' ] );
		add_action( 'parse_request', [ __CLASS__, 'sitemap' ] );
		add_action( 'wp_head', [ __CLASS__, 'json_ld' ], 30 );
	}

	public static function register() {
		register_taxonomy( self::TAX, LM_Auctions::PT, [
			'labels'            => [ 'name' => 'Auctioneers', 'singular_name' => 'Auctioneer', 'edit_item' => 'Edit auctioneer', 'menu_name' => 'Auctioneers' ],
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rest_base'         => 'auctioneers',
			'hierarchical'      => false,
			'rewrite'           => false,
		] );
		foreach ( self::TEXT as $k ) {
			register_term_meta( self::TAX, $k, [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'sanitize_text_field' ] );
		}
		foreach ( [ 'lm_about', 'lm_terms' ] as $k ) {
			register_term_meta( self::TAX, $k, [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'wp_kses_post' ] );
		}
		register_term_meta( self::TAX, 'lm_dealer', [ 'type' => 'integer', 'single' => true, 'show_in_rest' => true ] );
		register_term_meta( self::TAX, 'lm_faq', [
			'type' => 'array', 'single' => true,
			'show_in_rest' => [ 'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => false,
				'properties' => [ 'q' => [ 'type' => 'string' ], 'a' => [ 'type' => 'string' ] ] ] ] ],
			'sanitize_callback' => [ 'LM_Landing', 'sanitize_faq' ],
		] );
	}

	/* ------------------------------------------------------------------ data */

	private static function meta( WP_Term $t, string $k ): string { return (string) get_term_meta( $t->term_id, $k, true ); }

	public static function name( WP_Term $t ): string { return wp_specialchars_decode( $t->name, ENT_QUOTES ); }

	public static function url( WP_Term $t ): string {
		$u = (int) get_term_meta( $t->term_id, 'lm_dealer', true );
		return $u ? LM_Dealers::url( $u ) : home_url( '/' . self::BASE . '/' . $t->slug . '/' );
	}

	/** The auction's auctioneer term (creates it the first time a name shows up). */
	public static function assign( int $id ) {
		if ( get_post_type( $id ) !== LM_Auctions::PT || wp_is_post_revision( $id ) ) return;
		$name = trim( LM_Auctions::auctioneer( $id ) );
		if ( $name === '' ) return;
		$t = get_term_by( 'name', $name, self::TAX );
		if ( ! $t ) {
			$r = wp_insert_term( $name, self::TAX );
			if ( is_wp_error( $r ) ) return;
			$t = get_term( $r['term_id'], self::TAX );
		}
		if ( ! $t || is_wp_error( $t ) ) return;
		// No lm_auctioneer name: the author dealer runs it, so the "page" is the dealer profile.
		if ( (string) get_post_meta( $id, 'lm_auctioneer', true ) === '' ) {
			$u = (int) get_post_field( 'post_author', $id );
			if ( $u && LM_Dealers::is_dealer( get_userdata( $u ) ) ) update_term_meta( $t->term_id, 'lm_dealer', $u );
		}
		wp_set_object_terms( $id, (int) $t->term_id, self::TAX );
	}

	/** Once per plugin version: every auction gets its auctioneer term. */
	public static function backfill() {
		if ( get_option( self::BACKFILL ) === LM_VERSION ) return;
		foreach ( get_posts( [ 'post_type' => LM_Auctions::PT, 'post_status' => [ 'publish', 'draft', 'future' ], 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ] ) as $id ) {
			self::assign( (int) $id );
		}
		update_option( self::BACKFILL, LM_VERSION );
	}

	/** Auction ids of an auctioneer: upcoming (by date) or past (newest first). */
	public static function auctions( WP_Term $t, bool $upcoming, int $limit = 50 ): array {
		return get_posts( [
			'post_type' => LM_Auctions::PT, 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => $limit, 'no_found_rows' => true,
			'tax_query' => [ [ 'taxonomy' => self::TAX, 'terms' => $t->term_id ] ],
			'meta_key' => 'lm_start', 'orderby' => 'meta_value', 'order' => $upcoming ? 'ASC' : 'DESC',
			'meta_query' => [ [ 'key' => 'lm_start', 'value' => LM_Auctions::now(), 'compare' => $upcoming ? '>=' : '<' ] ],
		] );
	}

	public static function total( WP_Term $t ): int { return (int) $t->count; }

	public static function indexable( WP_Term $t ): bool {
		return self::total( $t ) >= self::MIN || trim( wp_strip_all_tags( self::meta( $t, 'lm_about' ) ) ) !== '';
	}

	/** Every auctioneer with a page (dealer accounts excluded unless $dealers), with at least one auction. */
	public static function all( bool $dealers = false ): array {
		$terms = get_terms( [ 'taxonomy' => self::TAX, 'hide_empty' => true ] );
		if ( is_wp_error( $terms ) ) return [];
		return array_values( array_filter( $terms, fn( $t ) => $dealers || ! (int) get_term_meta( $t->term_id, 'lm_dealer', true ) ) );
	}

	/** The auctioneer on /auctioneers/<slug>/, null elsewhere (and on the index). */
	public static function current(): ?WP_Term {
		if ( self::$cur !== false ) return self::$cur;
		self::$cur = null;
		$slug = sanitize_title( (string) get_query_var( self::QV ) );
		if ( $slug === '' || ! is_page( self::BASE ) ) return null;
		$t = get_term_by( 'slug', $slug, self::TAX );
		return self::$cur = ( $t instanceof WP_Term ) ? $t : null;
	}

	private static function is_index(): bool { return is_page( self::BASE ) && (string) get_query_var( self::QV ) === ''; }

	/** Unknown slug → 404; dealer auctioneer → its dealer profile. */
	public static function route() {
		if ( ! is_page( self::BASE ) || (string) get_query_var( self::QV ) === '' ) return;
		$t = self::current();
		if ( ! $t ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		if ( (int) get_term_meta( $t->term_id, 'lm_dealer', true ) ) {
			wp_safe_redirect( self::url( $t ), 301 );
			exit;
		}
	}

	/* ------------------------------------------------------------------ text helpers */

	private static function place( WP_Term $t ): string {
		$city = self::meta( $t, 'lm_city' );
		$st = self::meta( $t, 'lm_state' );
		if ( $city !== '' ) return trim( $city . ( $st ? ', ' . $st : '' ) );
		// From its auctions: the most common city.
		$c = [];
		foreach ( array_merge( self::auctions( $t, true ), self::auctions( $t, false, 20 ) ) as $id ) {
			if ( LM_Auctions::online_only( $id ) ) continue;
			$p = LM_Auctions::place( $id );
			if ( $p ) $c[ $p ] = ( $c[ $p ] ?? 0 ) + 1;
		}
		arsort( $c );
		return (string) array_key_first( $c );
	}

	/** Distinct auction places ("Shippensburg, PA"), most used first. */
	private static function places( array $ids ): array {
		$c = [];
		foreach ( $ids as $id ) { $p = LM_Auctions::place( $id ); if ( $p && $p !== 'Online' ) $c[ $p ] = ( $c[ $p ] ?? 0 ) + 1; }
		arsort( $c );
		return array_keys( $c );
	}

	private static function online( array $ids ): bool {
		foreach ( $ids as $id ) if ( in_array( get_post_meta( $id, 'lm_type', true ), [ 'online', 'timed', 'live-online', 'live-online-phone' ], true ) ) return true;
		return false;
	}

	private static function date( int $id, string $fmt = 'F j, Y' ): string {
		$d = LM_Auctions::dt( (string) get_post_meta( $id, 'lm_start', true ) );
		return $d ? $d->format( $fmt ) : '';
	}

	/** FAQ: answers built from the calendar, then the ones written for this auctioneer. */
	private static function faq( WP_Term $t, array $up, array $past ): array {
		$n = self::name( $t );
		$out = [];
		if ( $up ) {
			$next = $up[0];
			$out[] = [ 'q' => "When is the next $n auction?",
				'a' => 'The next one listed is <a href="' . esc_url( get_permalink( $next ) ) . '">' . esc_html( get_the_title( $next ) ) . '</a> on ' . esc_html( LM_Auctions::when( $next ) ) .
					( count( $up ) > 1 ? '. ' . count( $up ) . ' upcoming ' . esc_html( $n ) . ' auctions are listed on this page.' : '.' ) ];
		} else {
			$out[] = [ 'q' => "When is the next $n auction?",
				'a' => 'No upcoming ' . esc_html( $n ) . ' auction is listed right now. Check the auctioneer\'s own website, or the <a href="' . esc_url( get_post_type_archive_link( LM_Auctions::PT ) ) . '">equipment auction calendar</a> for other sales.' ];
		}
		$places = self::places( array_merge( $up, $past ) );
		if ( $places ) {
			$out[] = [ 'q' => "Where are $n auctions held?", 'a' => esc_html( 'Auctions listed on Heavy Listing were held in ' . self::join( array_slice( $places, 0, 4 ) ) . '. The address of each sale is on its page.' ) ];
		}
		if ( self::online( array_merge( $up, $past ) ) ) {
			$out[] = [ 'q' => "Can I bid online at $n auctions?", 'a' => esc_html( 'Some of their sales take online bids. Each auction page says whether it is live, online or timed, and links to the catalog and bidding page on the auctioneer\'s site.' ) ];
		}
		$f = get_term_meta( $t->term_id, 'lm_faq', true );
		return array_merge( $out, is_array( $f ) ? $f : [] );
	}

	private static function join( array $a ): string {
		if ( count( $a ) < 2 ) return (string) ( $a[0] ?? '' );
		$last = array_pop( $a );
		return implode( '; ', $a ) . ' and ' . $last;
	}

	/* ------------------------------------------------------------------ public pieces */

	public static function shortcode() {
		if ( $t = self::current() ) return self::profile( $t );
		if ( (string) get_query_var( self::QV ) !== '' ) return ''; // unknown slug: the 404 shows nothing of the index
		return self::index();
	}

	private static function index(): string {
		$rows = [];
		foreach ( self::all( true ) as $t ) {
			$up = self::auctions( $t, true );
			$rows[] = [ $t, $up, $up ? (string) get_post_meta( $up[0], 'lm_start', true ) : '9999' ];
		}
		usort( $rows, fn( $a, $b ) => [ $a[2], self::name( $a[0] ) ] <=> [ $b[2], self::name( $b[0] ) ] );
		$where = LM_Auctions::states_label();
		$h = '<h1 class="lmf-h1">Equipment auctioneers' . ( $where ? ' in ' . esc_html( $where ) : '' ) . '</h1>' .
			'<p class="lmf-lead">The auction companies behind the construction, farm and truck sales in our <a href="' . esc_url( get_post_type_archive_link( LM_Auctions::PT ) ) . '">auction calendar</a>: ' .
			'where they are, their next sale and every auction they have listed. Bidding happens on each auctioneer\'s own site.</p>';
		if ( ! $rows ) return $h . '<div class="lmf-empty"><p>No auctioneers listed yet.</p></div>';
		$out = '';
		foreach ( $rows as [ $t, $up ] ) {
			$n = count( $up );
			$out .= '<a class="lmf-dcard" href="' . esc_url( self::url( $t ) ) . '">' .
				'<span class="lmf-dcard-logo"><span class="lmf-dinit">' . esc_html( self::initials( $t ) ) . '</span></span>' .
				'<span class="lmf-dcard-body"><strong>' . esc_html( self::name( $t ) ) . '</strong>' .
				'<span class="lmf-meta">' . esc_html( self::place( $t ) ) . '</span>' .
				( $up ? '<span class="lmf-sub">Next: ' . esc_html( self::date( $up[0], 'M j, Y' ) ) . '</span>' : '<span class="lmf-sub">No upcoming auction listed</span>' ) . '</span>' .
				'<span class="lmf-dcard-stats"><b>' . $n . '</b> upcoming<br><b>' . self::total( $t ) . '</b> listed</span></a>';
		}
		return $h . '<div class="lmf-dealers">' . $out . '</div>' .
			'<p class="lmf-sub" style="margin-top:24px">Run equipment auctions? <a href="/list-your-auction/">List your auction for free</a>.</p>';
	}

	private static function initials( WP_Term $t ): string {
		$w = preg_split( '/\s+/', preg_replace( '/[^A-Za-z0-9 ]/', '', self::name( $t ) ) );
		return strtoupper( substr( $w[0] ?? '', 0, 1 ) . substr( $w[1] ?? '', 0, 1 ) );
	}

	private static function profile( WP_Term $t ): string {
		$n = self::name( $t );
		$up = self::auctions( $t, true );
		$past = self::auctions( $t, false, 12 );
		$place = self::place( $t );
		$web = self::meta( $t, 'lm_website' );
		$phone = self::meta( $t, 'lm_phone' );
		$addr = implode( ', ', array_filter( [ self::meta( $t, 'lm_address' ), trim( $place . ' ' . self::meta( $t, 'lm_zip' ) ) ] ) );
		$track = ' data-lm-auctioneer="' . esc_attr( $n ) . '"';

		$html = '<nav class="lmf-crumbs" aria-label="Breadcrumb"><a href="' . esc_url( home_url( '/' ) ) . '">Home</a> › <a href="' . esc_url( get_post_type_archive_link( LM_Auctions::PT ) ) . '">Auctions</a> › ' .
			'<a href="' . esc_url( home_url( '/' . self::BASE . '/' ) ) . '">Auctioneers</a> › <span>' . esc_html( $n ) . '</span></nav>';
		$html .= '<div class="lmf-dprofile">' .
			'<div class="lmf-dprofile-logo"><span class="lmf-dinit">' . esc_html( self::initials( $t ) ) . '</span></div>' .
			'<div class="lmf-dprofile-body"><span class="lmf-kicker">Equipment auctioneer</span>' .
			'<h1 class="lmf-h1">' . esc_html( $n ) . ' auctions</h1>' .
			'<p class="lmf-meta">' . esc_html( implode( ' · ', array_filter( [ $addr, count( $up ) . ' upcoming ' . ( count( $up ) === 1 ? 'auction' : 'auctions' ) ] ) ) ) . '</p>' .
			( $up ? '<p class="lmf-sub">Next auction: <a href="' . esc_url( get_permalink( $up[0] ) ) . '">' . esc_html( self::date( $up[0], 'l, F j, Y' ) ) . '</a></p>' : '' ) .
			'</div><div class="lmf-dprofile-actions">' .
			( $phone ? '<a class="lmf-btn" href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ) . '" data-lm-event="call"' . $track . '>Call ' . esc_html( $phone ) . '</a>' : '' ) .
			( $web ? '<a class="lmf-btn ghost" href="' . esc_url( $web ) . '" target="_blank" rel="noopener" data-lm-event="auctioneer_website"' . $track . '>Auctioneer website ↗</a>' : '' ) .
			'</div></div>';

		$about = self::meta( $t, 'lm_about' );
		$html .= '<div class="lmf-prose">' . ( $about ? wp_kses_post( $about ) :
			'<p>' . esc_html( $n ) . ' runs equipment auctions' . ( $place ? ' in ' . esc_html( $place ) : '' ) . '. Below are its upcoming sales listed on Heavy Listing, with dates, auction type and a link to each catalog, plus its past auctions.</p>' ) . '</div>';

		$html .= '<h2 class="lmf-h2 lmf-dprofile-h2">Upcoming ' . esc_html( $n ) . ' auctions</h2>';
		$html .= $up ? LM_Auctions::cards( $up ) : '<div class="lmf-empty"><p>No upcoming auction listed right now. See the <a href="' . esc_url( get_post_type_archive_link( LM_Auctions::PT ) ) . '">auction calendar</a> for other sales in the area.</p></div>';

		$terms = self::meta( $t, 'lm_terms' );
		$prose = '';
		if ( $terms ) $prose .= '<h2>Buyer terms</h2>' . wp_kses_post( $terms );
		if ( $past ) {
			$prose .= '<h2>Past auctions</h2><ul>';
			foreach ( $past as $id ) $prose .= '<li><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a> · ' . esc_html( self::date( $id, 'M j, Y' ) ) . '</li>';
			$prose .= '</ul>';
		}
		$faq = self::faq( $t, $up, $past );
		if ( $faq ) {
			$prose .= '<h2>Frequently asked questions</h2><div class="lmf-faq">';
			foreach ( $faq as $f ) $prose .= '<h3>' . esc_html( $f['q'] ) . '</h3><p>' . wp_kses( $f['a'], [ 'a' => [ 'href' => true ], 'strong' => [], 'em' => [] ] ) . '</p>';
			$prose .= '</div>';
		}
		$prose .= '<p class="lmf-sub">Is this your company? <a href="mailto:contact@heavylisting.com?subject=' . rawurlencode( 'Auctioneer page: ' . $n ) . '">Update this page or list your next auction for free</a>. ' .
			'Heavy Listing is an independent calendar and is not affiliated with ' . esc_html( $n ) . '.</p>';
		return $html . '<div class="lmf-prose">' . $prose . '</div>';
	}

	/* ------------------------------------------------------------------ SEO */

	public static function seo_title( $v ) {
		if ( $t = self::current() ) {
			$custom = self::meta( $t, 'lm_seo_title' );
			return ( $custom !== '' ? $custom : preg_replace( '/\s+Auctions?$/i', '', self::name( $t ) ) . ' Auction Schedule: Upcoming Equipment Auctions' ) . ' | Heavy Listing';
		}
		if ( self::is_index() ) {
			$where = LM_Auctions::states_label();
			return 'Equipment Auctioneers' . ( $where ? ' in ' . $where : '' ) . ': Schedules and Upcoming Sales | Heavy Listing';
		}
		return $v;
	}

	public static function seo_desc( $v ) {
		if ( $t = self::current() ) {
			$custom = self::meta( $t, 'lm_seo_desc' );
			if ( $custom !== '' ) return $custom;
			$n = self::name( $t );
			$up = self::auctions( $t, true );
			$place = self::place( $t );
			$d = 'Upcoming ' . $n . ' equipment auctions' . ( $place ? ' in ' . $place : '' ) . ': ';
			$d .= $up ? 'next sale ' . self::date( $up[0], 'M j, Y' ) . ', ' : '';
			$d .= 'auction types, catalogs and past sales.';
			return mb_strlen( $d ) > 160 ? rtrim( mb_substr( $d, 0, 157 ) ) . '…' : $d;
		}
		if ( self::is_index() ) return 'Equipment auction companies in our calendar: where they are, their next construction, farm and truck sale and every auction listed, with links to each catalog.';
		return $v;
	}

	public static function robots( $tag ) {
		$t = self::current();
		return $t && ! self::indexable( $t ) ? '<meta name="robots" content="noindex, follow">' . "\n" : $tag;
	}

	public static function canonical( $html ) {
		$t = self::current();
		return $t ? '<link rel="canonical" href="' . esc_url( self::url( $t ) ) . '">' : $html;
	}

	public static function sitemap_index( $list ) {
		$list = is_array( $list ) ? $list : [];
		$list[] = [ 'sitemap_url' => home_url( '/' . self::SITEMAP ) ];
		return $list;
	}

	/** /auctioneer-sitemap.xml: indexable auctioneer pages, dated by their newest auction change. */
	public static function sitemap( WP $wp ) {
		if ( $wp->request !== self::SITEMAP ) return;
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( self::all() as $t ) {
			if ( ! self::indexable( $t ) ) continue;
			$last = get_posts( [ 'post_type' => LM_Auctions::PT, 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'modified', 'order' => 'DESC',
				'no_found_rows' => true, 'tax_query' => [ [ 'taxonomy' => self::TAX, 'terms' => $t->term_id ] ] ] );
			$xml .= '<url><loc>' . esc_url( self::url( $t ) ) . '</loc>' . ( $last ? '<lastmod>' . get_post_modified_time( 'c', true, $last[0] ) . '</lastmod>' : '' ) . "</url>\n";
		}
		$xml .= '</urlset>';
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow', true );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above with esc_url.
		exit;
	}

	/** Organization + BreadcrumbList (+ FAQPage) on a profile; the events carry their own Event schema on each auction page. */
	public static function json_ld() {
		$t = self::current();
		if ( ! $t ) return;
		$url = self::url( $t );
		$n = self::name( $t );
		$address = array_filter( [ '@type' => 'PostalAddress', 'streetAddress' => self::meta( $t, 'lm_address' ), 'addressLocality' => self::meta( $t, 'lm_city' ),
			'addressRegion' => self::meta( $t, 'lm_state' ), 'postalCode' => self::meta( $t, 'lm_zip' ), 'addressCountry' => 'US' ] );
		$org = [ '@type' => 'Organization', '@id' => $url . '#auctioneer', 'name' => $n, 'url' => $url ];
		if ( count( $address ) > 2 ) $org['address'] = $address;
		if ( $w = self::meta( $t, 'lm_website' ) ) $org['sameAs'] = [ $w ];
		if ( $p = self::meta( $t, 'lm_phone' ) ) $org['telephone'] = $p;
		$crumbs = [ [ 'Home', home_url( '/' ) ], [ 'Auctions', get_post_type_archive_link( LM_Auctions::PT ) ], [ 'Auctioneers', home_url( '/' . self::BASE . '/' ) ], [ $n, $url ] ];
		$graph = [ $org, [ '@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => array_map(
			fn( $c, $i ) => [ '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1] ], $crumbs, array_keys( $crumbs ) ) ] ];
		$faq = self::faq( $t, self::auctions( $t, true ), self::auctions( $t, false, 12 ) );
		if ( $faq ) {
			$graph[] = [ '@type' => 'FAQPage', '@id' => $url . '#faq', 'mainEntity' => array_map( fn( $f ) => [ '@type' => 'Question', 'name' => $f['q'],
				'acceptedAnswer' => [ '@type' => 'Answer', 'text' => wp_strip_all_tags( $f['a'] ) ] ], $faq ) ];
		}
		echo '<script type="application/ld+json">' . wp_json_encode( [ '@context' => 'https://schema.org', '@graph' => $graph ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}
}
