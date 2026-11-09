<?php
/**
 * Dealer directory and dealer profiles (SEO: "used heavy equipment dealers in pa", "<dealer> used equipment").
 *
 *   /dealers/              WordPress page with [lm_dealers] (directory)
 *   /dealers/<slug>/       the dealer's author archive (author base = "dealers"): profile + their equipment
 *   [lm_dealers]           dealers with listings or upcoming auctions, as cards
 *   [lm_dealer_profile]    on the profile: logo, contact, website button, upcoming auctions
 *
 * The profile URL slug comes from the company name (user_nicename), kept in sync when lm_company changes.
 * Only dealers have a profile: other authors' archives return 404.
 */
defined( 'ABSPATH' ) || exit;

class LM_Dealers {
	const BASE = 'dealers';

	public static function init() {
		add_action( 'init', function () {
			global $wp_rewrite;
			$wp_rewrite->author_base = self::BASE;
		}, 1 );
		add_action( 'init', [ __CLASS__, 'sync_all_slugs' ], 30 );
		add_action( 'added_user_meta', [ __CLASS__, 'on_meta' ], 10, 4 );
		add_action( 'updated_user_meta', [ __CLASS__, 'on_meta' ], 10, 4 );
		add_action( 'pre_get_posts', [ __CLASS__, 'profile_query' ] );
		add_action( 'template_redirect', [ __CLASS__, 'only_dealers' ] );
		foreach ( [ 'dealers', 'dealer_profile' ] as $s ) add_shortcode( 'lm_' . $s, [ __CLASS__, $s ] );
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
	}

	/** Public business profile fields in REST (/wp/v2/users), editable by admins (set up dealers for them). */
	public static function register_meta() {
		foreach ( [ 'lm_company', 'lm_phone', 'lm_website', 'lm_address', 'lm_city', 'lm_state', 'lm_zip' ] as $k ) {
			register_meta( 'user', $k, [ 'type' => 'string', 'single' => true, 'show_in_rest' => true,
				'auth_callback' => fn() => current_user_can( 'edit_users' ) ] );
		}
	}

	/* ------------------------------------------------------------------ helpers */

	public static function is_dealer( $user ): bool {
		$u = $user instanceof WP_User ? $user : get_user_by( 'id', (int) $user );
		return $u && in_array( LM_Model::ROLE, (array) $u->roles, true );
	}

	public static function company( int $u ): string {
		return (string) get_user_meta( $u, 'lm_company', true ) ?: (string) get_the_author_meta( 'display_name', $u );
	}

	public static function url( int $u ): string { return get_author_posts_url( $u ); }

	public static function place( int $u ): string {
		return implode( ', ', array_filter( [ get_user_meta( $u, 'lm_city', true ), get_user_meta( $u, 'lm_state', true ) ] ) );
	}

	public static function count_listings( int $u ): int {
		return (int) ( new WP_Query( [ 'post_type' => LM_Model::PT, 'post_status' => 'publish', 'author' => $u, 'fields' => 'ids', 'posts_per_page' => 1 ] ) )->found_posts;
	}

	private static function upcoming_auctions( int $u ): array {
		return get_posts( [
			'post_type' => LM_Auctions::PT, 'post_status' => 'publish', 'author' => $u, 'fields' => 'ids', 'posts_per_page' => 6, 'no_found_rows' => true,
			'meta_key' => 'lm_start', 'orderby' => 'meta_value', 'order' => 'ASC',
			'meta_query' => [ [ 'key' => 'lm_start', 'value' => LM_Auctions::now(), 'compare' => '>=' ] ],
		] );
	}

	/** Top categories of a dealer's stock: [name => count]. */
	public static function top_categories( int $u, int $n = 3 ): array {
		$ids = get_posts( [ 'post_type' => LM_Model::PT, 'post_status' => 'publish', 'author' => $u, 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true ] );
		$cats = [];
		foreach ( $ids as $id ) {
			$t = wp_get_object_terms( $id, LM_Model::CATEGORY );
			if ( $t && ! is_wp_error( $t ) ) {
				$k = wp_specialchars_decode( $t[0]->name );
				$cats[ $k ] = ( $cats[ $k ] ?? 0 ) + 1;
			}
		}
		arsort( $cats );
		return array_slice( $cats, 0, $n, true );
	}

	/* ------------------------------------------------------------------ slugs */

	/** Profile slug = company name ("old-forge-power-equipment"), unique among users. */
	public static function sync_slug( int $u ) {
		if ( ! self::is_dealer( $u ) ) return;
		$want = sanitize_title( self::company( $u ) );
		if ( $want === '' || get_userdata( $u )->user_nicename === $want ) return;
		$slug = $want;
		$i = 2;
		while ( ( $o = get_user_by( 'slug', $slug ) ) && $o->ID !== $u ) $slug = $want . '-' . $i++;
		wp_update_user( [ 'ID' => $u, 'user_nicename' => $slug ] );
	}

	public static function on_meta( $meta_id, $user_id, $key, $value ) {
		if ( $key === 'lm_company' ) self::sync_slug( (int) $user_id );
	}

	/** Once per plugin version: existing dealers get their company slug. */
	public static function sync_all_slugs() {
		if ( get_option( 'lm_dealer_slugs' ) === LM_VERSION ) return;
		foreach ( get_users( [ 'role' => LM_Model::ROLE, 'fields' => 'ID' ] ) as $u ) self::sync_slug( (int) $u );
		update_option( 'lm_dealer_slugs', LM_VERSION, false );
	}

	/* ------------------------------------------------------------------ profile archive */

	public static function profile_query( WP_Query $q ) {
		if ( is_admin() || ! $q->is_main_query() || ! $q->is_author() ) return;
		$q->set( 'post_type', LM_Model::PT );
		$q->set( 'posts_per_page', 24 );
		$q->set( 'meta_query', [ 'relation' => 'OR', 'st' => [ 'key' => 'lm_status', 'compare' => 'EXISTS' ], [ 'key' => 'lm_status', 'compare' => 'NOT EXISTS' ] ] );
		$q->set( 'orderby', [ 'st' => 'ASC', 'date' => 'DESC' ] );
	}

	public static function only_dealers() {
		if ( ! is_author() ) return;
		$u = get_queried_object();
		if ( ! ( $u instanceof WP_User ) || ! self::is_dealer( $u ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/* ------------------------------------------------------------------ public pieces */

	private static function logo( int $u, string $size = 'medium' ): string {
		$logo = (int) get_user_meta( $u, 'lm_logo', true );
		return $logo ? wp_get_attachment_image( $logo, $size, false, [ 'class' => 'lmf-dlogo', 'alt' => esc_attr( self::company( $u ) ) ] ) : '';
	}

	private static function initials( int $u ): string {
		$w = preg_split( '/\s+/', trim( self::company( $u ) ) );
		return mb_strtoupper( mb_substr( $w[0] ?? '', 0, 1 ) . mb_substr( $w[1] ?? '', 0, 1 ) );
	}

	/** Directory: dealers with listings or upcoming auctions, most listings first. */
	public static function dealers() {
		$rows = [];
		foreach ( get_users( [ 'role' => LM_Model::ROLE ] ) as $user ) {
			$u = $user->ID;
			$n = self::count_listings( $u );
			$a = count( self::upcoming_auctions( $u ) );
			if ( ! $n && ! $a ) continue;
			$rows[] = [ $u, $n, $a ];
		}
		usort( $rows, fn( $x, $y ) => $y[1] <=> $x[1] );
		if ( ! $rows ) return '<div class="lmf-empty"><p>No dealers listed yet.</p></div>';
		$out = '';
		foreach ( $rows as [ $u, $n, $a ] ) {
			$cats = array_keys( self::top_categories( $u, 3 ) );
			$out .= '<a class="lmf-dcard" href="' . esc_url( self::url( $u ) ) . '">' .
				'<span class="lmf-dcard-logo">' . ( self::logo( $u, 'thumbnail' ) ?: '<span class="lmf-dinit">' . esc_html( self::initials( $u ) ) . '</span>' ) . '</span>' .
				'<span class="lmf-dcard-body"><strong>' . esc_html( self::company( $u ) ) . '</strong>' .
				'<span class="lmf-meta">' . esc_html( self::place( $u ) ) . '</span>' .
				( $cats ? '<span class="lmf-sub">' . esc_html( implode( ' · ', $cats ) ) . '</span>' : '' ) . '</span>' .
				'<span class="lmf-dcard-stats"><b>' . $n . '</b> ' . ( $n === 1 ? 'listing' : 'listings' ) .
				( $a ? '<br><b>' . $a . '</b> upcoming ' . ( $a === 1 ? 'auction' : 'auctions' ) : '' ) . '</span></a>';
		}
		return '<div class="lmf-dealers">' . $out . '</div>';
	}

	/** Profile header (on /dealers/<slug>/): who they are, how to reach them, their upcoming auctions. */
	public static function dealer_profile() {
		$user = get_queried_object();
		if ( ! ( $user instanceof WP_User ) || ! self::is_dealer( $user ) ) return '';
		$u = $user->ID;
		$m = fn( $k ) => (string) get_user_meta( $u, $k, true );
		$company = self::company( $u );
		$addr = implode( ', ', array_filter( [ $m( 'lm_address' ), trim( self::place( $u ) . ' ' . $m( 'lm_zip' ) ) ] ) );
		$phone = $m( 'lm_phone' );
		$web = $m( 'lm_website' );
		$n = self::count_listings( $u );
		$cats = self::top_categories( $u, 4 );
		$track = ' data-lm-dealer="' . esc_attr( $company ) . '"';
		$html = '<div class="lmf-dprofile">' .
			'<div class="lmf-dprofile-logo">' . ( self::logo( $u ) ?: '<span class="lmf-dinit">' . esc_html( self::initials( $u ) ) . '</span>' ) . '</div>' .
			'<div class="lmf-dprofile-body"><span class="lmf-kicker">Equipment dealer</span>' .
			'<h1 class="lmf-h1">' . esc_html( $company ) . '</h1>' .
			'<p class="lmf-meta">' . esc_html( implode( ' · ', array_filter( [ $addr, $n . ' ' . ( $n === 1 ? 'listing' : 'listings' ) ] ) ) ) . '</p>' .
			( $cats ? '<p class="lmf-sub">' . esc_html( implode( ' · ', array_map( fn( $k, $v ) => "$k ($v)", array_keys( $cats ), $cats ) ) ) . '</p>' : '' ) .
			'</div><div class="lmf-dprofile-actions">' .
			( $phone ? '<a class="lmf-btn" href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ) . '" data-lm-event="call"' . $track . '>Call ' . esc_html( $phone ) . '</a>' : '' ) .
			( $web ? '<a class="lmf-btn ghost" href="' . esc_url( $web ) . '" target="_blank" rel="noopener" data-lm-event="dealer_website"' . $track . '>Visit website ↗</a>' : '' ) .
			'</div></div>';
		$auctions = self::upcoming_auctions( $u );
		if ( $auctions ) {
			$html .= '<h2 class="lmf-h2 lmf-dprofile-h2">Upcoming auctions</h2>' . LM_Auctions::cards( $auctions );
		}
		return $html;
	}
}
