<?php
/**
 * Pennsylvania dealer directory (0.22.0). SEO: "heavy equipment dealers in pa", "<category> dealers in pa".
 *
 * Dealers that are not on Heavy Listing live in the hidden post type lm_dir_dealer, written from their own public
 * website (seo/dealers/cargar.py). They have no page of their own: they show as cards
 *
 *   [lm_dealer_directory]     on /dealers/, grouped by region, under the member dealers ([lm_dealers])
 *   LM_Directory::for_category on each category landing (LM_Landing::category_content): "<Category> dealers in Pennsylvania",
 *                              member dealers with that stock first, then directory dealers that sell that type
 *
 * Every card says how to claim it. When a dealer joins, set lm_user to their account and the card stops showing
 * (the member card takes its place).
 *
 * Meta (REST /wp/v2/directory-dealers, editors): lm_website, lm_phone, lm_address, lm_city, lm_zip, lm_towns (other PA
 * towns), lm_region, lm_brands, lm_line (one neutral sentence), lm_inventory (used inventory URL), lm_checked (Y-m-d),
 * lm_types [category slugs], lm_used / lm_rent / lm_parts / lm_service (bool), lm_user (int).
 */
defined( 'ABSPATH' ) || exit;

class LM_Directory {
	const PT   = 'lm_dir_dealer';
	const TEXT = [ 'lm_website', 'lm_phone', 'lm_address', 'lm_city', 'lm_zip', 'lm_towns', 'lm_region', 'lm_brands', 'lm_line', 'lm_inventory', 'lm_checked' ];
	const BOOL = [ 'lm_used' => 'Used equipment', 'lm_rent' => 'Rentals', 'lm_parts' => 'Parts', 'lm_service' => 'Service' ];
	/** Region order on the page: multi-region dealers first, then east to west. */
	const REGIONS = [ 'Statewide', 'Southeast', 'Lehigh Valley', 'Northeast', 'Southcentral', 'Central', 'Northwest', 'Southwest' ];
	const CLAIM = '/how-it-works/#dealers';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_shortcode( 'lm_dealer_directory', [ __CLASS__, 'shortcode' ] );
		add_action( 'wp_head', [ __CLASS__, 'json_ld' ], 31 );
	}

	public static function register() {
		register_post_type( self::PT, [
			'labels'       => [ 'name' => 'PA dealer directory', 'singular_name' => 'Directory dealer', 'add_new_item' => 'Add directory dealer', 'edit_item' => 'Edit directory dealer' ],
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => 'edit.php?post_type=' . LM_Model::PT,
			'show_in_rest' => true,
			'rest_base'    => 'directory-dealers',
			'supports'     => [ 'title', 'page-attributes', 'custom-fields' ],
			'rewrite'      => false,
			'query_var'    => false,
		] );
		$auth = fn() => current_user_can( 'edit_posts' );
		foreach ( self::TEXT as $k ) {
			register_post_meta( self::PT, $k, [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'auth_callback' => $auth,
				'sanitize_callback' => in_array( $k, [ 'lm_website', 'lm_inventory' ], true ) ? 'esc_url_raw' : 'sanitize_text_field' ] );
		}
		foreach ( array_keys( self::BOOL ) as $k ) {
			register_post_meta( self::PT, $k, [ 'type' => 'boolean', 'single' => true, 'show_in_rest' => true, 'auth_callback' => $auth ] );
		}
		register_post_meta( self::PT, 'lm_user', [ 'type' => 'integer', 'single' => true, 'show_in_rest' => true, 'auth_callback' => $auth ] );
		register_post_meta( self::PT, 'lm_types', [ 'type' => 'array', 'single' => true, 'auth_callback' => $auth,
			'show_in_rest' => [ 'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ],
			'sanitize_callback' => fn( $v ) => array_values( array_filter( array_map( 'sanitize_title', (array) $v ) ) ) ] );
	}

	/* ------------------------------------------------------------------ data */

	private static function m( int $id, string $k ): string { return (string) get_post_meta( $id, $k, true ); }

	/** Published directory dealers that are not (yet) members. */
	public static function entries(): array {
		static $ids = null;
		if ( $ids !== null ) return $ids;
		$all = get_posts( [ 'post_type' => self::PT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
			'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ] ] );
		update_meta_cache( 'post', $all );
		return $ids = array_values( array_filter( $all, fn( $id ) => ! (int) get_post_meta( $id, 'lm_user', true ) ) );
	}

	private static function types( int $id ): array {
		$t = get_post_meta( $id, 'lm_types', true );
		return is_array( $t ) ? $t : [];
	}

	private static function region( int $id ): string {
		$r = self::m( $id, 'lm_region' );
		return in_array( $r, self::REGIONS, true ) ? $r : 'Other';
	}

	/** Member dealers (users) with stock in a category, children included: [user id => listings]. */
	private static function members_for( WP_Term $t ): array {
		$ids = get_posts( [ 'post_type' => LM_Model::PT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
			'tax_query' => [ [ 'taxonomy' => LM_Model::CATEGORY, 'field' => 'term_id', 'terms' => $t->term_id, 'include_children' => true ] ] ] );
		$out = [];
		foreach ( $ids as $id ) {
			if ( get_post_meta( $id, 'lm_status', true ) === 'sold' ) continue;
			$u = (int) get_post_field( 'post_author', $id );
			if ( LM_Dealers::is_dealer( $u ) ) $out[ $u ] = ( $out[ $u ] ?? 0 ) + 1;
		}
		arsort( $out );
		return $out;
	}

	/** Directory dealers that sell this category or one of its subcategories. */
	private static function entries_for( WP_Term $t ): array {
		$slugs = [ $t->slug ];
		foreach ( get_term_children( $t->term_id, LM_Model::CATEGORY ) as $c ) {
			$ct = get_term( $c );
			if ( $ct && ! is_wp_error( $ct ) ) $slugs[] = $ct->slug;
		}
		return array_values( array_filter( self::entries(), fn( $id ) => array_intersect( $slugs, self::types( $id ) ) ) );
	}

	/* ------------------------------------------------------------------ html */

	private static function card( int $id ): string {
		$name = get_the_title( $id );
		$web = self::m( $id, 'lm_website' );
		$phone = self::m( $id, 'lm_phone' );
		$inv = self::m( $id, 'lm_inventory' );
		$towns = self::m( $id, 'lm_towns' );
		$place = implode( ', ', array_filter( [ self::m( $id, 'lm_city' ), 'PA' ] ) );
		$does = [];
		foreach ( self::BOOL as $k => $label ) if ( get_post_meta( $id, $k, true ) ) $does[] = $label;
		$track = ' data-lm-dealer="' . esc_attr( $name ) . '"';
		return '<div class="lmf-ddir" id="' . esc_attr( get_post_field( 'post_name', $id ) ) . '">' .
			'<strong class="lmf-ddir-name">' . esc_html( $name ) . '</strong>' .
			'<span class="lmf-meta">' . esc_html( $place . ( $towns ? ' · also ' . $towns : '' ) ) . '</span>' .
			( ( $line = self::m( $id, 'lm_line' ) ) ? '<p class="lmf-ddir-line">' . esc_html( $line ) . '</p>' : '' ) .
			( $does ? '<span class="lmf-sub">' . esc_html( implode( ' · ', $does ) ) . '</span>' : '' ) .
			( ( $b = self::m( $id, 'lm_brands' ) ) ? '<span class="lmf-sub">Brands: ' . esc_html( $b ) . '</span>' : '' ) .
			'<span class="lmf-ddir-actions">' .
			( $web ? '<a href="' . esc_url( $web ) . '" target="_blank" rel="nofollow noopener" data-lm-event="directory_website"' . $track . '>Website ↗</a>' : '' ) .
			( $inv ? '<a href="' . esc_url( $inv ) . '" target="_blank" rel="nofollow noopener" data-lm-event="directory_inventory"' . $track . '>Used inventory ↗</a>' : '' ) .
			( $phone ? '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ) . '" data-lm-event="directory_call"' . $track . '>' . esc_html( $phone ) . '</a>' : '' ) .
			'</span>' .
			'<a class="lmf-ddir-claim" href="' . esc_url( home_url( self::CLAIM ) ) . '" data-lm-event="directory_claim"' . $track . '>Is this your business? Claim this listing</a>' .
			'</div>';
	}

	/** On /dealers/: directory dealers grouped by region, with a jump list. */
	public static function shortcode() {
		$ids = self::entries();
		if ( ! $ids ) return '';
		$groups = [];
		foreach ( $ids as $id ) $groups[ self::region( $id ) ][] = $id;
		$order = array_values( array_filter( array_merge( self::REGIONS, [ 'Other' ] ), fn( $r ) => isset( $groups[ $r ] ) ) );
		$nav = '';
		$body = '';
		foreach ( $order as $r ) {
			$a = 'region-' . sanitize_title( $r );
			$label = [ 'Other' => 'Other areas', 'Statewide' => 'Statewide' ][ $r ] ?? $r . ' PA';
			$nav .= '<a class="lmf-chip" href="#' . $a . '">' . esc_html( $label ) . ' <span>' . count( $groups[ $r ] ) . '</span></a>';
			$body .= '<h3 class="lmf-ddir-region" id="' . $a . '">' . esc_html( [ 'Other' => 'Other areas', 'Statewide' => 'Dealers with locations across the state' ][ $r ] ?? $r . ' Pennsylvania' ) . '</h3>' .
				'<div class="lmf-ddirs">' . implode( '', array_map( [ __CLASS__, 'card' ], $groups[ $r ] ) ) . '</div>';
		}
		$dates = array_filter( array_map( fn( $id ) => self::m( $id, 'lm_checked' ), $ids ) );
		$checked = $dates ? max( $dates ) : '';
		return '<div class="lmf-ddir-wrap"><nav class="lmf-chips lmf-ddir-nav" aria-label="Regions">' . $nav . '</nav>' . $body .
			'<p class="lmf-ddir-note">These dealers are not on Heavy Listing and are not affiliated with us. Details come from each dealer\'s own website' .
			( $checked ? ', checked ' . esc_html( wp_date( 'F Y', strtotime( $checked ) ) ) : '' ) . '. Call ahead to confirm stock and hours.</p></div>';
	}

	/** On a category landing: who sells this in Pennsylvania. Empty when nobody does. */
	public static function for_category( WP_Term $t ): string {
		$members = self::members_for( $t );
		$dir = array_slice( self::entries_for( $t ), 0, 6 );
		if ( ! $members && ! $dir ) return '';
		$name = wp_specialchars_decode( $t->name, ENT_QUOTES );
		// "Skid Steers" → "Skid Steer dealers", "Compactors & Rollers" → "Compactor & Roller dealers"
		$single = preg_replace( '/(?<!s)s\b/', '', $name );
		$out = '<h2>' . esc_html( $single ) . ' dealers in Pennsylvania</h2>';
		if ( $members ) {
			$out .= '<ul class="lmf-catdealers">';
			foreach ( $members as $u => $n ) {
				$out .= '<li><a href="' . esc_url( LM_Dealers::url( $u ) ) . '">' . esc_html( LM_Dealers::company( $u ) ) . '</a>' .
					( ( $p = LM_Dealers::place( $u ) ) ? ', ' . esc_html( $p ) : '' ) . ': ' . $n . ' ' . ( $n === 1 ? 'listing' : 'listings' ) . ' on Heavy Listing</li>';
			}
			$out .= '</ul>';
		}
		if ( $dir ) {
			$out .= '<p>' . ( $members ? 'Other dealers in the state that sell ' : 'Dealers in the state that sell ' ) . esc_html( strtolower( $name ) ) . ' (not on Heavy Listing; check their own sites for stock):</p><ul class="lmf-catdealers">';
			foreach ( $dir as $id ) {
				$web = self::m( $id, 'lm_website' );
				$nm = esc_html( get_the_title( $id ) );
				$out .= '<li>' . ( $web ? '<a href="' . esc_url( $web ) . '" target="_blank" rel="nofollow noopener">' . $nm . '</a>' : $nm ) .
					', ' . esc_html( self::m( $id, 'lm_city' ) ?: self::region( $id ) ) . '</li>';
			}
			$out .= '</ul>';
		}
		return $out . '<p><a href="' . esc_url( home_url( '/' . LM_Dealers::BASE . '/' ) ) . '">All equipment dealers in Pennsylvania →</a></p>';
	}

	/* ------------------------------------------------------------------ structured data */

	/** /dealers/: ItemList of member profiles and directory dealers (their own sites). */
	public static function json_ld() {
		if ( ! is_page( LM_Dealers::BASE ) ) return;
		$items = [];
		foreach ( get_users( [ 'role' => LM_Model::ROLE, 'fields' => 'ID' ] ) as $u ) {
			if ( LM_Dealers::count_listings( (int) $u ) ) $items[] = [ 'name' => LM_Dealers::company( (int) $u ), 'url' => LM_Dealers::url( (int) $u ) ];
		}
		foreach ( self::entries() as $id ) {
			if ( $w = self::m( $id, 'lm_website' ) ) $items[] = [ 'name' => get_the_title( $id ), 'url' => $w ];
		}
		if ( ! $items ) return;
		$url = home_url( '/' . LM_Dealers::BASE . '/' );
		$list = [];
		foreach ( $items as $i => $it ) $list[] = [ '@type' => 'ListItem', 'position' => $i + 1, 'name' => wp_specialchars_decode( $it['name'], ENT_QUOTES ), 'url' => $it['url'] ];
		echo '<script type="application/ld+json">' . wp_json_encode( [ '@context' => 'https://schema.org', '@type' => 'ItemList', '@id' => $url . '#dealers',
			'name' => 'Heavy equipment dealers in Pennsylvania', 'numberOfItems' => count( $list ), 'itemListElement' => $list ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}
}
