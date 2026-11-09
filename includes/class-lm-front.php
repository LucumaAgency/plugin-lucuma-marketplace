<?php
/**
 * Public pieces placed in Bricks with the Shortcode element (layout stays in Bricks, logic here):
 *
 *   [lm_search]                 hero search: keyword + category → /equipment/?q=…&eqcat=… (never ?cat=: WordPress reserves it)
 *   [lm_categories]             root categories as tiles with counts
 *   [lm_makes]                  makes with listings, as chips
 *   [lm_grid count="8"]         latest listings as cards; main="1" = the current archive (with filters
 *                               ?q= ?eqcat= ?make= ?dealer= and pagination)
 *   [lm_filters]                archive sidebar: categories and makes
 *   [lm_archive_title]          heading for the current archive / filters
 *   single listing: [lm_price] [lm_gallery] [lm_facts] [lm_specs] [lm_dealer] [lm_breadcrumbs]
 */
defined( 'ABSPATH' ) || exit;

class LM_Front {
	public static function init() {
		foreach ( [ 'search', 'categories', 'makes', 'grid', 'filters', 'archive_title', 'price', 'gallery', 'facts', 'specs', 'dealer', 'breadcrumbs' ] as $s ) {
			add_shortcode( 'lm_' . $s, [ __CLASS__, $s ] );
		}
		add_action( 'wp_enqueue_scripts', function () {
			wp_enqueue_style( 'lm-front', plugins_url( 'assets/front.css', LM_DIR . '/lucuma-marketplace.php' ), [], LM_VERSION );
		} );
		add_action( 'pre_get_posts', [ __CLASS__, 'archive_query' ] );
	}

	/* ------------------------------------------------------------------ helpers */

	private static function m( int $id, string $k ) { return get_post_meta( $id, $k, true ); }

	public static function price_text( int $id ): string {
		if ( self::m( $id, 'lm_call_for_price' ) ) return 'Call for price';
		$p = self::m( $id, 'lm_price' );
		return $p === '' ? 'Call for price' : '$' . number_format( (float) $p );
	}

	private static function location( int $id ): string {
		return implode( ', ', array_filter( [ self::m( $id, 'lm_city' ), self::m( $id, 'lm_state' ) ] ) );
	}

	private static function status_badge( int $id ): string {
		$s = self::m( $id, 'lm_status' );
		return in_array( $s, [ 'pending', 'sold' ], true ) ? '<span class="lmf-badge ' . esc_attr( $s ) . '">' . esc_html( $s === 'sold' ? 'Sold' : 'Sale pending' ) . '</span>' : '';
	}

	private static function term( int $id, string $tax ) {
		$t = wp_get_object_terms( $id, $tax );
		return ( ! is_wp_error( $t ) && $t ) ? $t[0] : null;
	}

	private static function archive_url( array $args = [] ): string {
		return esc_url( add_query_arg( array_filter( $args ), get_post_type_archive_link( LM_Model::PT ) ) );
	}

	/* ------------------------------------------------------------------ archive query */

	/** Filters on the equipment archive: ?q= keyword, ?eqcat= category slug, ?make= make slug, ?dealer= user id. */
	public static function archive_query( WP_Query $q ) {
		if ( is_admin() || ! $q->is_main_query() ) return;
		$is_ours = $q->is_post_type_archive( LM_Model::PT ) || $q->is_tax( [ LM_Model::CATEGORY, LM_Model::MAKE, LM_Model::MODEL ] );
		if ( ! $is_ours ) return;
		$q->set( 'posts_per_page', 24 );
		$tax = [];
		$cat = isset( $_GET['eqcat'] ) ? sanitize_title( wp_unslash( $_GET['eqcat'] ) ) : '';
		if ( $cat ) $tax[] = [ 'taxonomy' => LM_Model::CATEGORY, 'field' => 'slug', 'terms' => $cat, 'include_children' => true ];
		$make = isset( $_GET['make'] ) ? sanitize_title( wp_unslash( $_GET['make'] ) ) : '';
		if ( $make ) $tax[] = [ 'taxonomy' => LM_Model::MAKE, 'field' => 'slug', 'terms' => $make ];
		if ( $tax ) $q->set( 'tax_query', $tax );
		$kw = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		if ( $kw !== '' ) $q->set( 's', $kw );
		$dealer = isset( $_GET['dealer'] ) ? absint( $_GET['dealer'] ) : 0;
		if ( $dealer ) $q->set( 'author', $dealer );
		// Sold listings stay reachable but go last.
		$q->set( 'meta_query', [ 'relation' => 'OR', 'st' => [ 'key' => 'lm_status', 'compare' => 'EXISTS' ], [ 'key' => 'lm_status', 'compare' => 'NOT EXISTS' ] ] );
		$q->set( 'orderby', [ 'st' => 'ASC', 'date' => 'DESC' ] );
	}

	/* ------------------------------------------------------------------ home & archive pieces */

	public static function search() {
		$cats = get_terms( [ 'taxonomy' => LM_Model::CATEGORY, 'parent' => 0, 'hide_empty' => false, 'orderby' => 'name' ] );
		$opts = '<option value="">All categories</option>';
		foreach ( $cats as $c ) {
			$opts .= '<option value="' . esc_attr( $c->slug ) . '">' . esc_html( wp_specialchars_decode( $c->name ) ) . '</option>';
			foreach ( get_terms( [ 'taxonomy' => LM_Model::CATEGORY, 'parent' => $c->term_id, 'hide_empty' => false, 'orderby' => 'name' ] ) as $k ) {
				$opts .= '<option value="' . esc_attr( $k->slug ) . '">&nbsp;&nbsp;' . esc_html( wp_specialchars_decode( $k->name ) ) . '</option>';
			}
		}
		return '<form class="lmf-search" action="' . esc_url( get_post_type_archive_link( LM_Model::PT ) ) . '" method="get" role="search">' .
			'<input type="search" name="q" placeholder="Make, model or keyword — e.g. Bobcat T770" aria-label="Keyword">' .
			'<select name="eqcat" aria-label="Category">' . $opts . '</select>' .
			'<button type="submit">Search equipment</button></form>';
	}

	public static function categories( $atts ) {
		$a = shortcode_atts( [ 'empty' => '1' ], $atts );
		$out = '';
		foreach ( get_terms( [ 'taxonomy' => LM_Model::CATEGORY, 'parent' => 0, 'hide_empty' => false, 'orderby' => 'name' ] ) as $c ) {
			$n = (int) ( new WP_Query( [ 'post_type' => LM_Model::PT, 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 1,
				'tax_query' => [ [ 'taxonomy' => LM_Model::CATEGORY, 'terms' => $c->term_id, 'include_children' => true ] ] ] ) )->found_posts;
			if ( ! $n && $a['empty'] !== '1' ) continue;
			$kids = wp_list_pluck( get_terms( [ 'taxonomy' => LM_Model::CATEGORY, 'parent' => $c->term_id, 'hide_empty' => false, 'number' => 4, 'orderby' => 'count', 'order' => 'DESC' ] ), 'name' );
			$out .= '<a class="lmf-cat" href="' . esc_url( get_term_link( $c ) ) . '"><strong>' . esc_html( wp_specialchars_decode( $c->name ) ) . '</strong>' .
				'<span class="lmf-count">' . $n . ' ' . ( $n === 1 ? 'listing' : 'listings' ) . '</span>' .
				( $kids ? '<span class="lmf-sub">' . esc_html( wp_specialchars_decode( implode( ' · ', $kids ) ) ) . '</span>' : '' ) . '</a>';
		}
		return '<div class="lmf-cats">' . $out . '</div>';
	}

	public static function makes() {
		$out = '';
		foreach ( get_terms( [ 'taxonomy' => LM_Model::MAKE, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 24 ] ) as $m ) {
			$out .= '<a class="lmf-chip" href="' . esc_url( get_term_link( $m ) ) . '">' . esc_html( wp_specialchars_decode( $m->name ) ) . ' <span>' . (int) $m->count . '</span></a>';
		}
		return $out ? '<div class="lmf-chips">' . $out . '</div>' : '';
	}

	private static function card( int $id ): string {
		$img  = get_the_post_thumbnail( $id, 'medium_large', [ 'loading' => 'lazy', 'alt' => esc_attr( get_the_title( $id ) ) ] );
		$meta = array_filter( [
			self::m( $id, 'lm_year' ),
			self::m( $id, 'lm_hours' ) !== '' ? number_format( (float) self::m( $id, 'lm_hours' ) ) . ' hrs' : '',
			self::m( $id, 'lm_mileage' ) !== '' ? number_format( (float) self::m( $id, 'lm_mileage' ) ) . ' mi' : '',
		] );
		$cat = self::term( $id, LM_Model::CATEGORY );
		return '<article class="lmf-card"><a href="' . esc_url( get_permalink( $id ) ) . '" class="lmf-card-img">' . ( $img ?: '<span class="lmf-noimg">No photo</span>' ) . self::status_badge( $id ) . '</a>' .
			'<div class="lmf-card-body">' . ( $cat ? '<span class="lmf-kicker">' . esc_html( wp_specialchars_decode( $cat->name ) ) . '</span>' : '' ) .
			'<h3><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></h3>' .
			'<p class="lmf-meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>' .
			'<div class="lmf-card-foot"><span class="lmf-price">' . esc_html( self::price_text( $id ) ) . '</span><span class="lmf-loc">' . esc_html( self::location( $id ) ) . '</span></div></div></article>';
	}

	public static function grid( $atts ) {
		$a = shortcode_atts( [ 'count' => 8, 'main' => '0', 'category' => '', 'make' => '' ], $atts );
		if ( $a['main'] === '1' ) {
			global $wp_query;
			if ( ! $wp_query->have_posts() ) {
				return '<div class="lmf-empty"><p><strong>No equipment matches these filters.</strong></p><p><a href="' . self::archive_url() . '">See all equipment</a></p></div>';
			}
			$ids = wp_list_pluck( $wp_query->posts, 'ID' );
			$pages = paginate_links( [ 'total' => $wp_query->max_num_pages, 'current' => max( 1, get_query_var( 'paged' ) ), 'type' => 'list', 'prev_text' => '←', 'next_text' => '→' ] );
			return '<p class="lmf-found">' . (int) $wp_query->found_posts . ' ' . ( $wp_query->found_posts == 1 ? 'listing' : 'listings' ) . '</p>' .
				'<div class="lmf-grid">' . implode( '', array_map( [ __CLASS__, 'card' ], $ids ) ) . '</div>' . ( $pages ? '<nav class="lmf-pages">' . $pages . '</nav>' : '' );
		}
		$args = [ 'post_type' => LM_Model::PT, 'post_status' => 'publish', 'posts_per_page' => max( 1, min( 48, (int) $a['count'] ) ), 'fields' => 'ids', 'no_found_rows' => true ];
		$tax = [];
		if ( $a['category'] ) $tax[] = [ 'taxonomy' => LM_Model::CATEGORY, 'field' => 'slug', 'terms' => sanitize_title( $a['category'] ) ];
		if ( $a['make'] ) $tax[] = [ 'taxonomy' => LM_Model::MAKE, 'field' => 'slug', 'terms' => sanitize_title( $a['make'] ) ];
		if ( $tax ) $args['tax_query'] = $tax;
		$ids = get_posts( $args );
		return $ids ? '<div class="lmf-grid">' . implode( '', array_map( [ __CLASS__, 'card' ], $ids ) ) . '</div>' : '';
	}

	public static function filters() {
		$cur_cat = is_tax( LM_Model::CATEGORY ) ? get_queried_object()->slug : ( isset( $_GET['eqcat'] ) ? sanitize_title( wp_unslash( $_GET['eqcat'] ) ) : '' );
		$cur_make = is_tax( LM_Model::MAKE ) ? get_queried_object()->slug : ( isset( $_GET['make'] ) ? sanitize_title( wp_unslash( $_GET['make'] ) ) : '' );
		$html = '<form class="lmf-kw" action="' . self::archive_url() . '" method="get"><input type="search" name="q" value="' . esc_attr( isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '' ) . '" placeholder="Keyword"><button type="submit">Go</button></form>';
		$html .= '<h4>Category</h4><ul class="lmf-list">';
		foreach ( get_terms( [ 'taxonomy' => LM_Model::CATEGORY, 'parent' => 0, 'hide_empty' => true, 'orderby' => 'name' ] ) as $c ) {
			$html .= '<li><a' . ( $cur_cat === $c->slug ? ' class="on"' : '' ) . ' href="' . esc_url( get_term_link( $c ) ) . '">' . esc_html( wp_specialchars_decode( $c->name ) ) . '</a><ul>';
			foreach ( get_terms( [ 'taxonomy' => LM_Model::CATEGORY, 'parent' => $c->term_id, 'hide_empty' => true, 'orderby' => 'name' ] ) as $k ) {
				$html .= '<li><a' . ( $cur_cat === $k->slug ? ' class="on"' : '' ) . ' href="' . esc_url( get_term_link( $k ) ) . '">' . esc_html( wp_specialchars_decode( $k->name ) ) . ' <span>' . (int) $k->count . '</span></a></li>';
			}
			$html .= '</ul></li>';
		}
		$html .= '</ul><h4>Make</h4><ul class="lmf-list">';
		foreach ( get_terms( [ 'taxonomy' => LM_Model::MAKE, 'hide_empty' => true, 'orderby' => 'name' ] ) as $m ) {
			$html .= '<li><a' . ( $cur_make === $m->slug ? ' class="on"' : '' ) . ' href="' . esc_url( get_term_link( $m ) ) . '">' . esc_html( wp_specialchars_decode( $m->name ) ) . ' <span>' . (int) $m->count . '</span></a></li>';
		}
		return '<aside class="lmf-filters">' . $html . '</ul></aside>';
	}

	public static function archive_title() {
		$title = 'Heavy equipment for sale';
		if ( is_tax() ) $title = wp_specialchars_decode( single_term_title( '', false ) ) . ( is_tax( LM_Model::MAKE ) ? ' equipment' : '' ) . ' for sale';
		if ( is_author() ) {
			global $wp_query;
			return '<h2 class="lmf-h2">Equipment for sale (' . (int) $wp_query->found_posts . ')</h2>';
		}
		if ( ! empty( $_GET['q'] ) ) $title = 'Results for “' . sanitize_text_field( wp_unslash( $_GET['q'] ) ) . '”';
		return '<h1 class="lmf-h1">' . esc_html( $title ) . '</h1>';
	}

	/* ------------------------------------------------------------------ single listing */

	public static function price() {
		$id = get_the_ID();
		return '<div class="lmf-bigprice">' . esc_html( self::price_text( $id ) ) . self::status_badge( $id ) . '</div>';
	}

	public static function gallery() {
		$id  = get_the_ID();
		$ids = array_map( 'intval', (array) ( self::m( $id, 'lm_gallery' ) ?: [] ) );
		if ( ! $ids && has_post_thumbnail( $id ) ) $ids = [ (int) get_post_thumbnail_id( $id ) ];
		if ( ! $ids ) return '<div class="lmf-gallery"><span class="lmf-noimg">No photos yet</span></div>';
		$main  = wp_get_attachment_image( $ids[0], 'large', false, [ 'class' => 'lmf-main', 'alt' => esc_attr( get_the_title( $id ) ) ] );
		$thumbs = count( $ids ) > 1 ? '<div class="lmf-thumbs">' . implode( '', array_map( fn( $a ) => '<a href="' . esc_url( wp_get_attachment_image_url( $a, 'large' ) ) . '">' . wp_get_attachment_image( $a, 'thumbnail', false, [ 'loading' => 'lazy' ] ) . '</a>', $ids ) ) . '</div>' : '';
		return '<div class="lmf-gallery">' . $main . $thumbs . '</div>' . ( $thumbs ? '<script>document.querySelectorAll(".lmf-thumbs a").forEach(function(a){a.addEventListener("click",function(e){e.preventDefault();var m=a.closest(".lmf-gallery").querySelector(".lmf-main");m.removeAttribute("srcset");m.src=a.href;});});</script>' : '' );
	}

	public static function facts() {
		$id = get_the_ID();
		$make = self::term( $id, LM_Model::MAKE );
		$model = self::term( $id, LM_Model::MODEL );
		$rows = [
			'Year'      => self::m( $id, 'lm_year' ),
			'Make'      => $make ? wp_specialchars_decode( $make->name ) : '',
			'Model'     => $model ? wp_specialchars_decode( $model->name ) : '',
			'Hours'     => self::m( $id, 'lm_hours' ) !== '' ? number_format( (float) self::m( $id, 'lm_hours' ) ) : '',
			'Mileage'   => self::m( $id, 'lm_mileage' ) !== '' ? number_format( (float) self::m( $id, 'lm_mileage' ) ) . ' mi' : '',
			'Condition' => ucfirst( (string) self::m( $id, 'lm_condition' ) ),
			'Stock #'   => self::m( $id, 'lm_stock_number' ),
			'Location'  => self::location( $id ),
		];
		$out = '';
		foreach ( array_filter( $rows, fn( $v ) => $v !== '' && $v !== null ) as $k => $v ) $out .= '<div><dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( (string) $v ) . '</dd></div>';
		return '<dl class="lmf-facts">' . $out . '</dl>';
	}

	public static function specs() {
		$id = get_the_ID();
		$vals = (array) ( self::m( $id, 'lm_specs' ) ?: [] );
		$out = '';
		foreach ( LM_Specs::for_post( $id ) as $k => $d ) {
			if ( ! array_key_exists( $k, $vals ) ) continue;
			$v = $vals[ $k ];
			if ( $d['type'] === 'boolean' ) $v = $v ? 'Yes' : 'No';
			elseif ( $d['type'] === 'number' ) $v = ( floor( (float) $v ) == $v ? number_format( (float) $v ) : (string) $v ) . ( ! empty( $d['unit'] ) ? ' ' . $d['unit'] : '' );
			$out .= '<tr><th>' . esc_html( $d['label'] ) . '</th><td>' . esc_html( (string) $v ) . '</td></tr>';
		}
		return $out ? '<table class="lmf-specs"><tbody>' . $out . '</tbody></table>' : '';
	}

	public static function dealer() {
		$p  = get_post();
		$u  = (int) $p->post_author;
		$m  = fn( $k ) => (string) get_user_meta( $u, $k, true );
		$company = $m( 'lm_company' ) ?: get_the_author_meta( 'display_name', $u );
		$phone = $m( 'lm_phone' );
		$logo  = (int) get_user_meta( $u, 'lm_logo', true );
		$place = implode( ', ', array_filter( [ $m( 'lm_city' ), $m( 'lm_state' ) ] ) );
		$subject = rawurlencode( 'Inquiry: ' . get_the_title( $p ) . ( self::m( $p->ID, 'lm_stock_number' ) ? ' (Stock #' . self::m( $p->ID, 'lm_stock_number' ) . ')' : '' ) );
		$email = get_the_author_meta( 'user_email', $u );
		return '<div class="lmf-dealer">' . ( $logo ? wp_get_attachment_image( $logo, 'medium', false, [ 'class' => 'lmf-logo' ] ) : '' ) .
			'<span class="lmf-kicker">Sold by</span><strong class="lmf-company">' . esc_html( $company ) . '</strong>' .
			( $place ? '<span class="lmf-meta">' . esc_html( $place ) . '</span>' : '' ) .
			( $phone ? '<a class="lmf-btn" href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ) . '" data-lm-event="call">Call ' . esc_html( $phone ) . '</a>' : '' ) .
			'<a class="lmf-btn ghost" href="mailto:' . esc_attr( $email ) . '?subject=' . $subject . '" data-lm-event="email">Email dealer</a>' .
			'<a class="lmf-more" href="' . esc_url( LM_Dealers::url( $u ) ) . '">More equipment from this dealer →</a>' . self::source( $p->ID, $company ) . '</div>';
	}

	/** Aggregated units: say where the listing comes from and link to the original. */
	private static function source( int $id, string $company ): string {
		$url = self::m( $id, 'lm_source_url' );
		if ( ! $url ) return '';
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return '<p class="lmf-source">Listing from ' . esc_html( $company ) . '\'s website (' . esc_html( $host ) . '). Details may have changed. ' .
			'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" data-lm-event="source">View the original listing ↗</a></p>';
	}

	public static function breadcrumbs() {
		$id = get_the_ID();
		$parts = [ '<a href="' . self::archive_url() . '">Equipment</a>' ];
		$cat = self::term( $id, LM_Model::CATEGORY );
		if ( $cat ) {
			foreach ( array_reverse( get_ancestors( $cat->term_id, LM_Model::CATEGORY, 'taxonomy' ) ) as $a ) {
				$t = get_term( $a );
				$parts[] = '<a href="' . esc_url( get_term_link( $t ) ) . '">' . esc_html( wp_specialchars_decode( $t->name ) ) . '</a>';
			}
			$parts[] = '<a href="' . esc_url( get_term_link( $cat ) ) . '">' . esc_html( wp_specialchars_decode( $cat->name ) ) . '</a>';
		}
		return '<nav class="lmf-crumbs" aria-label="Breadcrumb">' . implode( ' <span>/</span> ', $parts ) . '</nav>';
	}
}
