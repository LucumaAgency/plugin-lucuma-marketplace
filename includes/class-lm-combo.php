<?php
/**
 * Make × category pages: /for-sale/<parent>/<category>/<make>/ (e.g. /for-sale/construction/skid-steers/bobcat/).
 *
 * No extra rewrite rule: the category's own hierarchical rule already sends the whole path to lm_category, and WordPress
 * only looks at its last segment. When that last segment is a make (and not a category), we split it off in `request`:
 * lm_category = the category, lm_make = the make. Pagination (/page/2/) comes through the same way.
 * The category stays the queried object (it is registered before the make), so the category template renders it.
 *
 * Indexable on leaf categories from MIN listings not sold; otherwise noindex, follow. Canonical always points to the clean combo URL.
 */
defined( 'ABSPATH' ) || exit;

class LM_Combo {
	const MIN = 3;

	private static $cur = false;

	public static function init() {
		add_filter( 'request', [ __CLASS__, 'resolve' ] );
		add_filter( 'seopress_titles_canonical', [ __CLASS__, 'canonical' ], 20 );
	}

	/** /for-sale/construction/skid-steers/bobcat/ → lm_category=construction/skid-steers + lm_make=bobcat. */
	public static function resolve( $qv ) {
		$path = isset( $qv[ LM_Model::CATEGORY ] ) ? trim( (string) $qv[ LM_Model::CATEGORY ], '/' ) : '';
		if ( $path === '' || strpos( $path, '/' ) === false || ! empty( $qv[ LM_Model::MAKE ] ) ) return $qv;
		$last = basename( $path );
		if ( get_term_by( 'slug', $last, LM_Model::CATEGORY ) ) return $qv;          // a real category: leave it alone
		if ( ! get_term_by( 'slug', $last, LM_Model::MAKE ) ) return $qv;            // neither: let WordPress 404
		$parent = dirname( $path );
		if ( ! get_term_by( 'slug', basename( $parent ), LM_Model::CATEGORY ) ) return $qv;
		$qv[ LM_Model::CATEGORY ] = $parent;
		$qv[ LM_Model::MAKE ] = $last;
		return $qv;
	}

	/** ['cat' => WP_Term, 'make' => WP_Term] on a make × category page, null elsewhere. */
	public static function current(): ?array {
		if ( self::$cur !== false ) return self::$cur;
		self::$cur = null;
		if ( ! is_tax( LM_Model::CATEGORY ) ) return null;
		$slug = (string) get_query_var( LM_Model::MAKE );
		if ( $slug === '' || strpos( $slug, ',' ) !== false || strpos( $slug, '+' ) !== false ) return null;
		$cat = get_queried_object();
		$make = get_term_by( 'slug', $slug, LM_Model::MAKE );
		if ( ! ( $cat instanceof WP_Term ) || ! $make ) return null;
		return self::$cur = [ 'cat' => $cat, 'make' => $make ];
	}

	public static function url( WP_Term $cat, WP_Term $make ): string {
		$base = get_term_link( $cat );
		return is_wp_error( $base ) ? '' : trailingslashit( $base ) . $make->slug . '/';
	}

	public static function name( WP_Term $cat, WP_Term $make ): string {
		return wp_specialchars_decode( $make->name, ENT_QUOTES ) . ' ' . wp_specialchars_decode( $cat->name, ENT_QUOTES );
	}

	/** Make term_id => listings not sold in this category (children included), most first. Cached until a listing is saved. */
	public static function makes_in( WP_Term $cat ): array {
		$key = 'lm_combo_' . $cat->term_id . '_' . (int) get_option( LM_Seo::CACHE_KEY, 1 );
		$out = get_transient( $key );
		if ( is_array( $out ) ) return $out;
		$ids = get_posts( [ 'post_type' => LM_Model::PT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
			'tax_query' => [ [ 'taxonomy' => LM_Model::CATEGORY, 'terms' => $cat->term_id, 'include_children' => true ] ],
			'meta_query' => [ 'relation' => 'OR', [ 'key' => 'lm_status', 'value' => 'sold', 'compare' => '!=' ], [ 'key' => 'lm_status', 'compare' => 'NOT EXISTS' ] ] ] );
		$out = [];
		if ( $ids ) {
			foreach ( wp_get_object_terms( $ids, LM_Model::MAKE, [ 'fields' => 'all_with_object_id' ] ) as $t ) {
				$out[ $t->term_id ] = ( $out[ $t->term_id ] ?? 0 ) + 1;
			}
		}
		arsort( $out );
		set_transient( $key, $out, DAY_IN_SECONDS );
		return $out;
	}

	public static function count( WP_Term $cat, WP_Term $make ): int {
		return (int) ( self::makes_in( $cat )[ $make->term_id ] ?? 0 );
	}

	/** Only leaf categories: on a parent ("construction/caterpillar") the page would repeat the make archive. */
	public static function leaf( WP_Term $cat ): bool {
		$kids = get_term_children( $cat->term_id, LM_Model::CATEGORY );
		return ! is_wp_error( $kids ) && ! $kids;
	}

	public static function indexable( WP_Term $cat, WP_Term $make ): bool {
		return self::leaf( $cat ) && self::count( $cat, $make ) >= self::MIN;
	}

	/** [[WP_Term make, int count, string url]] for the makes with an indexable page in this category. */
	public static function links( WP_Term $cat ): array {
		$out = [];
		if ( ! self::leaf( $cat ) ) return $out;
		foreach ( self::makes_in( $cat ) as $id => $n ) {
			if ( $n < self::MIN ) continue;
			$m = get_term( $id, LM_Model::MAKE );
			if ( $m && ! is_wp_error( $m ) ) $out[] = [ $m, $n, self::url( $cat, $m ) ];
		}
		return $out;
	}

	/** "Shop by make" chips under a category intro. */
	public static function chips( WP_Term $cat ): string {
		$links = self::links( $cat );
		if ( ! $links ) return '';
		$out = '';
		foreach ( $links as [ $m, $n, $u ] ) {
			$out .= '<a class="lmf-chip" href="' . esc_url( $u ) . '">' . esc_html( wp_specialchars_decode( $m->name, ENT_QUOTES ) ) . ' <span>' . $n . '</span></a>';
		}
		return '<div class="lmf-bymake"><span class="lmf-kicker">Shop by make</span><div class="lmf-chips">' . $out . '</div></div>';
	}

	/** Intro for a combo page, built from live numbers (no copy of the category text, so no duplicate content). */
	public static function intro(): string {
		$c = self::current();
		if ( ! $c ) return '';
		[ 'cat' => $cat, 'make' => $make ] = $c;
		$s = LM_Landing::stats( $cat, $make );
		$n = (int) str_replace( ',', '', $s['{count}'] );
		$what = strtolower( wp_specialchars_decode( $cat->name, ENT_QUOTES ) );
		$mk = wp_specialchars_decode( $make->name, ENT_QUOTES );
		$cat_url = get_term_link( $cat );
		$make_url = get_term_link( $make );
		if ( ! $n ) {
			return '<div class="lmf-cat-intro"><p>No used ' . esc_html( $mk . ' ' . $what ) . ' listed right now. See all <a href="' . esc_url( $cat_url ) . '">' . esc_html( $what ) . ' for sale</a> or every <a href="' . esc_url( $make_url ) . '">' . esc_html( $mk ) . ' machine</a>.</p></div>';
		}
		$d = (int) $s['{dealers}'];
		$p = '<p><strong>' . $n . ' used ' . esc_html( $mk . ' ' . ( $n === 1 ? LM_Panel_Api::singular( $what ) : $what ) ) . '</strong> for sale from ' . $d . ' ' . ( $d === 1 ? 'dealer' : 'dealers' );
		if ( $s['{median_price}'] !== 'call for price' ) {
			$p .= $n > 1 ? ', with a median asking price of ' . esc_html( $s['{median_price}'] ) . ' (' . esc_html( $s['{price_range}'] ) . ')' : ', asking ' . esc_html( $s['{median_price}'] );
		}
		$p .= '. Contact the dealer directly: no buyer fees.</p>';
		$p .= '<p>See all <a href="' . esc_url( $cat_url ) . '">' . esc_html( $what ) . ' for sale</a> or every <a href="' . esc_url( $make_url ) . '">' . esc_html( $mk ) . ' machine</a> on Heavy Listing.</p>';
		return '<div class="lmf-cat-intro">' . $p . '</div>';
	}

	/** SEOPress canonical: the clean combo URL (plus /page/N/ when paged, like the other archives). */
	public static function canonical( $html ) {
		$c = self::current();
		if ( ! $c ) return $html;
		$u = self::url( $c['cat'], $c['make'] );
		$p = max( 1, (int) get_query_var( 'paged' ) );
		if ( $p > 1 ) $u .= 'page/' . $p . '/';
		return '<link rel="canonical" href="' . esc_url( $u ) . '">';
	}
}
