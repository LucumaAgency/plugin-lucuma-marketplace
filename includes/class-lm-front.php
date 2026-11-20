<?php
/**
 * Public pieces placed in Bricks with the Shortcode element (layout stays in Bricks, logic here):
 *
 *   [lm_search]                 hero search: keyword + category → /equipment/?q=…&eqcat=… (never ?cat=: WordPress reserves it)
 *   [lm_categories]             root categories as tiles with counts
 *   [lm_makes]                  makes with listings, as chips
 *   [lm_grid count="8"]         latest listings as cards; main="1" = the current archive (with filters
 *                               ?q= ?eqcat= ?make= ?dealer= and pagination)
 *   [lm_filters]                archive sidebar (one GET form): keyword, category tree, price, year, hours, make,
 *                               state/condition when listings differ; ?min_price ?max_price ?year_min ?year_max
 *                               ?hours_max ?state ?condition ?sort (all noindex via LM_Seo::has_filters)
 *   [lm_archive_title]          heading for the current archive / filters
 *   [lm_count cities="" category=""]  number of listings (regional pages)
 *   [lm_grid cities="A, B"]     grid limited to those cities
 *   single listing: [lm_price] [lm_gallery] [lm_facts] [lm_specs] [lm_dealer] [lm_breadcrumbs]
 */
defined( 'ABSPATH' ) || exit;

class LM_Front {
	public static function init() {
		foreach ( [ 'search', 'categories', 'makes', 'grid', 'count', 'filters', 'archive_title', 'price', 'gallery', 'facts', 'specs', 'dealer', 'breadcrumbs' ] as $s ) {
			add_shortcode( 'lm_' . $s, [ __CLASS__, $s ] );
		}
		add_action( 'wp_enqueue_scripts', function () {
			wp_enqueue_style( 'lm-front', plugins_url( 'assets/front.css', LM_DIR . '/lucuma-marketplace.php' ), [], LM_VERSION );
		} );
		add_action( 'pre_get_posts', [ __CLASS__, 'archive_query' ] );
		add_action( 'wp_footer', [ __CLASS__, 'plan_prefill' ] );
		add_action( 'wp_footer', [ __CLASS__, 'table_hint' ] );
	}

	/**
	 * Wide tables in articles and category texts scroll sideways on phones. When one does not fit, add a short
	 * "swipe" line above it and a fade on the right edge (gone once scrolled to the end), so readers know there is more.
	 */
	public static function table_hint() {
		if ( is_admin() || ! ( is_singular() || is_tax() ) ) return;
		?>
<script>(function(){function go(){document.querySelectorAll('.lmf-prose table,.lmf-cat-body table').forEach(function(t){
if(t.closest('.lmf-tscroll')||t.scrollWidth<=t.clientWidth+2)return;var w=document.createElement('div');w.className='lmf-tscroll';t.parentNode.insertBefore(w,t);w.appendChild(t);
var h=document.createElement('p');h.className='lmf-thint';h.textContent='Swipe sideways to see all columns →';w.parentNode.insertBefore(h,w);
function u(){w.classList.toggle('at-end',t.scrollLeft+t.clientWidth>=t.scrollWidth-4);}t.addEventListener('scroll',u,{passive:true});u();});}
if(document.readyState==='complete'){go();}else{window.addEventListener('load',go);}})();</script>
		<?php
	}

	/**
	 * ?plan=medium (or auction-plan, promoted…) preselects that plan in any form on the page: the plan buttons on
	 * /pricing/ link to the dealer and auction forms with it. Matches the start of the option text ("Medium (up to 100)").
	 */
	public static function plan_prefill() {
		if ( empty( $_GET['plan'] ) ) return;
		$plan = str_replace( '-', ' ', sanitize_key( wp_unslash( $_GET['plan'] ) ) );
		?>
<script>(function(){var p=<?php echo wp_json_encode( $plan ); ?>;function go(){document.querySelectorAll('form select').forEach(function(s){
for(var i=0;i<s.options.length;i++){if(s.options[i].text.toLowerCase().indexOf(p)===0){s.selectedIndex=i;s.dispatchEvent(new Event('change',{bubbles:true}));return;}}});}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',go);}else{go();}})();</script>
		<?php
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

	/** Numeric and choice filters read from the URL (shared by the query, the sidebar and the active chips). */
	const SORTS = [ '' => 'Newest first', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'year_desc' => 'Year: newest', 'hours_asc' => 'Hours: lowest' ];

	public static function params(): array {
		$g = fn( $k ) => isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$n = fn( $k ) => ( $v = preg_replace( '/[^0-9]/', '', $g( $k ) ) ) === '' ? '' : (int) $v;
		$state = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $g( 'state' ) ), 0, 2 ) );
		$cond  = in_array( $g( 'condition' ), LM_Model::CONDITIONS, true ) ? $g( 'condition' ) : '';
		$sort  = isset( self::SORTS[ $g( 'sort' ) ] ) ? $g( 'sort' ) : '';
		return [ 'min_price' => $n( 'min_price' ), 'max_price' => $n( 'max_price' ), 'year_min' => $n( 'year_min' ), 'year_max' => $n( 'year_max' ),
			'hours_max' => $n( 'hours_max' ), 'state' => strlen( $state ) === 2 ? $state : '', 'condition' => $cond, 'sort' => $sort ];
	}

	/** Filters on the equipment archive: ?q= keyword, ?eqcat= category slug, ?make= make slug, ?dealer= user id,
	 *  ?min_price= ?max_price= ?year_min= ?year_max= ?hours_max= ?state= ?condition= and ?sort=. */
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

		$p = self::params();
		// Sold listings stay reachable but go last. Named clauses (st, pr, yr, hr) let us sort by them without dropping
		// listings that lack the field; range filters, on the other hand, only keep listings that have it.
		$mq = [ 'relation' => 'AND',
			[ 'relation' => 'OR', 'st' => [ 'key' => 'lm_status', 'compare' => 'EXISTS' ], [ 'key' => 'lm_status', 'compare' => 'NOT EXISTS' ] ] ];
		$sort_key = [ 'price_asc' => 'pr', 'price_desc' => 'pr', 'year_desc' => 'yr', 'hours_asc' => 'hr' ][ $p['sort'] ] ?? '';
		$meta_of  = [ 'pr' => 'lm_price', 'yr' => 'lm_year', 'hr' => 'lm_hours' ];
		if ( $sort_key ) $mq[] = [ 'relation' => 'OR', $sort_key => [ 'key' => $meta_of[ $sort_key ], 'compare' => 'EXISTS', 'type' => 'NUMERIC' ], [ 'key' => $meta_of[ $sort_key ], 'compare' => 'NOT EXISTS' ] ];
		$range = function ( $key, $min, $max ) use ( &$mq ) {
			if ( $min !== '' && $max !== '' ) $mq[] = [ 'key' => $key, 'value' => [ min( $min, $max ), max( $min, $max ) ], 'compare' => 'BETWEEN', 'type' => 'NUMERIC' ];
			elseif ( $min !== '' ) $mq[] = [ 'key' => $key, 'value' => $min, 'compare' => '>=', 'type' => 'NUMERIC' ];
			elseif ( $max !== '' ) $mq[] = [ 'key' => $key, 'value' => $max, 'compare' => '<=', 'type' => 'NUMERIC' ];
		};
		$range( 'lm_price', $p['min_price'], $p['max_price'] );
		$range( 'lm_year', $p['year_min'], $p['year_max'] );
		$range( 'lm_hours', '', $p['hours_max'] );
		if ( $p['state'] ) $mq[] = [ 'key' => 'lm_state', 'value' => $p['state'] ];
		if ( $p['condition'] ) $mq[] = [ 'key' => 'lm_condition', 'value' => $p['condition'] ];
		$q->set( 'meta_query', $mq );
		$order = [ 'st' => 'ASC' ];
		if ( $sort_key ) $order[ $sort_key ] = in_array( $p['sort'], [ 'price_desc', 'year_desc' ], true ) ? 'DESC' : 'ASC';
		$order['date'] = 'DESC';
		$q->set( 'orderby', $order );
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

	/** Above the results: count, active filters (each removable) and sort. */
	private static function toolbar( int $found ): string {
		$p = self::params();
		$chips = '';
		$active = is_author() ? [] : self::active_filters();
		foreach ( $active as [ $label, $url ] ) $chips .= '<a class="lmf-achip" href="' . $url . '" aria-label="Remove ' . esc_attr( $label ) . '">' . esc_html( $label ) . ' <span aria-hidden="true">×</span></a>';
		if ( count( $active ) > 1 ) $chips .= '<a class="lmf-aclear" href="' . esc_url( get_post_type_archive_link( LM_Model::PT ) ) . '">Clear all</a>';
		$keep = '';
		foreach ( $_GET as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( $k !== 'sort' && is_string( $v ) && $v !== '' ) $keep .= '<input type="hidden" name="' . esc_attr( sanitize_key( $k ) ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( $v ) ) ) . '">';
		}
		$opts = '';
		foreach ( self::SORTS as $v => $l ) $opts .= '<option value="' . esc_attr( $v ) . '"' . selected( $p['sort'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		$sort = is_author() ? '' : '<form class="lmf-sort" method="get" action="' . esc_url( self::base_url() ) . '">' . $keep . '<label><span>Sort</span><select name="sort" onchange="if(!this.value)this.disabled=true;this.form.submit()">' . $opts . '</select></label><noscript><button type="submit">Sort</button></noscript></form>';
		return '<div class="lmf-bar"><p class="lmf-found">' . $found . ' ' . ( $found === 1 ? 'listing' : 'listings' ) . '</p>' . $sort . '</div>' .
			( $chips ? '<div class="lmf-achips">' . $chips . '</div>' : '' );
	}

	public static function grid( $atts ) {
		$a = shortcode_atts( [ 'count' => 8, 'main' => '0', 'category' => '', 'make' => '', 'cities' => '' ], $atts );
		if ( $a['main'] === '1' ) {
			global $wp_query;
			if ( ! $wp_query->have_posts() ) {
				return self::toolbar( 0 ) . '<div class="lmf-empty"><p><strong>No equipment matches these filters.</strong></p><p>Try removing a filter above, or <a href="' . self::archive_url() . '">see all equipment</a>.</p></div>';
			}
			$ids = wp_list_pluck( $wp_query->posts, 'ID' );
			$pages = paginate_links( [ 'total' => $wp_query->max_num_pages, 'current' => max( 1, get_query_var( 'paged' ) ), 'type' => 'list', 'prev_text' => '←', 'next_text' => '→' ] );
			return self::toolbar( (int) $wp_query->found_posts ) .
				'<div class="lmf-grid">' . implode( '', array_map( [ __CLASS__, 'card' ], $ids ) ) . '</div>' . ( $pages ? '<nav class="lmf-pages">' . $pages . '</nav>' : '' );
		}
		$args = [ 'post_type' => LM_Model::PT, 'post_status' => 'publish', 'posts_per_page' => max( 1, min( 48, (int) $a['count'] ) ), 'fields' => 'ids', 'no_found_rows' => true ];
		$tax = [];
		if ( $a['category'] ) $tax[] = [ 'taxonomy' => LM_Model::CATEGORY, 'field' => 'slug', 'terms' => sanitize_title( $a['category'] ) ];
		if ( $a['make'] ) $tax[] = [ 'taxonomy' => LM_Model::MAKE, 'field' => 'slug', 'terms' => sanitize_title( $a['make'] ) ];
		if ( $tax ) $args['tax_query'] = $tax;
		if ( $c = self::cities_query( $a['cities'] ) ) $args['meta_query'] = [ $c ];
		$ids = get_posts( $args );
		return $ids ? '<div class="lmf-grid">' . implode( '', array_map( [ __CLASS__, 'card' ], $ids ) ) . '</div>' : '';
	}

	/** meta_query for a comma-separated list of cities (regional landing pages): "Littlestown, Hanover". */
	public static function cities_query( string $cities ): array {
		$list = array_values( array_filter( array_map( 'trim', explode( ',', $cities ) ) ) );
		return $list ? [ 'key' => 'lm_city', 'value' => array_map( 'sanitize_text_field', $list ), 'compare' => 'IN' ] : [];
	}

	/** [lm_count cities="..." category="..."]: number of listings, for copy like "98 machines near Harrisburg". */
	public static function count( $atts ) {
		$a = shortcode_atts( [ 'cities' => '', 'category' => '' ], $atts );
		$args = [ 'post_type' => LM_Model::PT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ];
		if ( $a['category'] ) $args['tax_query'] = [ [ 'taxonomy' => LM_Model::CATEGORY, 'field' => 'slug', 'terms' => sanitize_title( $a['category'] ) ] ];
		if ( $c = self::cities_query( $a['cities'] ) ) $args['meta_query'] = [ $c ];
		return (string) count( get_posts( $args ) );
	}

	/** Current archive URL without page number or query string: the filter form submits here. */
	private static function base_url(): string {
		if ( $c = LM_Combo::current() ) return LM_Combo::url( $c['cat'], $c['make'] );
		if ( is_tax() && ( $t = get_queried_object() ) instanceof WP_Term ) {
			$l = get_term_link( $t );
			if ( ! is_wp_error( $l ) ) return $l;
		}
		return get_post_type_archive_link( LM_Model::PT );
	}

	/** Query args to carry over when switching category or make (keyword, ranges, sort), so a price filter survives. */
	private static function keep_args(): array {
		$keep = array_filter( self::params(), fn( $v ) => $v !== '' );
		if ( ! empty( $_GET['q'] ) ) $keep['q'] = sanitize_text_field( wp_unslash( $_GET['q'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		return $keep;
	}

	private static function link( string $url, array $args = [] ): string {
		return esc_url( $args ? add_query_arg( array_map( 'rawurlencode', $args ), $url ) : $url );
	}

	/** Distinct values of a listing field among published listings (to show state / condition only when there is a choice). */
	private static function distinct( string $key ): array {
		$ck = 'lm_distinct_' . $key . '_' . (int) get_option( LM_Seo::CACHE_KEY, 1 );
		$v = get_transient( $ck );
		if ( is_array( $v ) ) return $v;
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_value v, COUNT(*) n FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND pm.meta_value <> '' AND p.post_type = %s AND p.post_status = 'publish' GROUP BY pm.meta_value ORDER BY n DESC", $key, LM_Model::PT ) );
		$v = [];
		foreach ( $rows as $r ) $v[ $r->v ] = (int) $r->n;
		set_transient( $ck, $v, DAY_IN_SECONDS );
		return $v;
	}

	/** Labels of the active filters, each with the URL that removes it. */
	public static function active_filters(): array {
		$base = self::base_url();
		$args = self::keep_args();
		$out  = [];
		$money = fn( $v ) => '$' . ( $v >= 1000 && $v % 1000 === 0 ? number_format( $v / 1000 ) . 'k' : number_format( $v ) );
		$p = self::params();
		$without = function ( array $keys ) use ( $base, $args ) { return self::link( $base, array_diff_key( $args, array_flip( $keys ) ) ); };
		if ( $c = LM_Combo::current() ) {
			$out[] = [ wp_specialchars_decode( $c['make']->name ), self::link( get_term_link( $c['cat'] ), $args ) ];
			$out[] = [ wp_specialchars_decode( $c['cat']->name ), self::link( get_term_link( $c['make'] ), $args ) ];
		} elseif ( is_tax( [ LM_Model::CATEGORY, LM_Model::MAKE ] ) && ( $t = get_queried_object() ) instanceof WP_Term ) {
			$up = $t->taxonomy === LM_Model::CATEGORY && $t->parent ? get_term_link( get_term( $t->parent ) ) : get_post_type_archive_link( LM_Model::PT );
			$out[] = [ wp_specialchars_decode( $t->name ), self::link( $up, $args ) ];
		}
		if ( ! empty( $args['q'] ) ) $out[] = [ '“' . $args['q'] . '”', $without( [ 'q' ] ) ];
		if ( $p['min_price'] !== '' || $p['max_price'] !== '' ) {
			$l = $p['min_price'] !== '' && $p['max_price'] !== '' ? $money( $p['min_price'] ) . '–' . $money( $p['max_price'] ) : ( $p['min_price'] !== '' ? $money( $p['min_price'] ) . '+' : 'Under ' . $money( $p['max_price'] ) );
			$out[] = [ $l, $without( [ 'min_price', 'max_price' ] ) ];
		}
		if ( $p['year_min'] !== '' || $p['year_max'] !== '' ) {
			$l = $p['year_min'] !== '' && $p['year_max'] !== '' ? $p['year_min'] . '–' . $p['year_max'] : ( $p['year_min'] !== '' ? $p['year_min'] . ' or newer' : $p['year_max'] . ' or older' );
			$out[] = [ $l, $without( [ 'year_min', 'year_max' ] ) ];
		}
		if ( $p['hours_max'] !== '' ) $out[] = [ 'Under ' . number_format( $p['hours_max'] ) . ' hrs', $without( [ 'hours_max' ] ) ];
		if ( $p['state'] ) $out[] = [ $p['state'], $without( [ 'state' ] ) ];
		if ( $p['condition'] ) $out[] = [ ucfirst( $p['condition'] ), $without( [ 'condition' ] ) ];
		return $out;
	}

	private static function section( string $title, string $body, bool $open, string $summary = '' ): string {
		return '<details class="lmf-fsec"' . ( $open ? ' open' : '' ) . '><summary><span>' . esc_html( $title ) . '</span>' .
			( $summary !== '' ? '<em>' . esc_html( $summary ) . '</em>' : '' ) . '</summary><div class="lmf-fbody">' . $body . '</div></details>';
	}

	public static function filters() {
		$cur_cat  = is_tax( LM_Model::CATEGORY ) ? get_queried_object()->slug : ( isset( $_GET['eqcat'] ) ? sanitize_title( wp_unslash( $_GET['eqcat'] ) ) : '' );
		$cur_make = is_tax( LM_Model::MAKE ) ? get_queried_object()->slug : ( isset( $_GET['make'] ) ? sanitize_title( wp_unslash( $_GET['make'] ) ) : '' );
		$combo = LM_Combo::current();
		if ( $combo ) $cur_make = $combo['make']->slug;
		$p    = self::params();
		$args = self::keep_args();
		$kw   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$active = self::active_filters();

		// Mobile: a "Filters" button opens the panel, so the machines come first.
		$toggle = '<button type="button" class="lmf-ftoggle" aria-expanded="false" aria-controls="lmf-fpanel"><span>Filters' . ( $active ? ' (' . count( $active ) . ')' : '' ) . '</span>' .
			'<em>' . esc_html( $active ? implode( ' · ', wp_list_pluck( $active, 0 ) ) : 'Category, make, price, year' ) . '</em></button>';

		$h = '<div class="lmf-fhead"><strong>Filters</strong><button type="button" class="lmf-fclose" aria-label="Close filters">×</button></div>';
		$h .= '<div class="lmf-kw"><input type="search" name="q" value="' . esc_attr( $kw ) . '" placeholder="Make, model or keyword" aria-label="Keyword"><button type="submit">Go</button></div>';

		// Category: groups collapsed, except the one holding the current category.
		$cats = '';
		$cur_name = '';
		foreach ( get_terms( [ 'taxonomy' => LM_Model::CATEGORY, 'parent' => 0, 'hide_empty' => false, 'orderby' => 'name' ] ) as $c ) {
			$kids = get_terms( [ 'taxonomy' => LM_Model::CATEGORY, 'parent' => $c->term_id, 'hide_empty' => true, 'orderby' => 'name' ] );
			$total = (int) $c->count + array_sum( wp_list_pluck( $kids, 'count' ) );
			if ( ! $total ) continue;
			$in = $cur_cat === $c->slug || in_array( $cur_cat, wp_list_pluck( $kids, 'slug' ), true );
			$sub = '<li><a class="lmf-all' . ( $cur_cat === $c->slug ? ' on' : '' ) . '" href="' . self::link( get_term_link( $c ), $args ) . '">All ' . esc_html( wp_specialchars_decode( $c->name ) ) . ' <span>' . $total . '</span></a></li>';
			foreach ( $kids as $k ) {
				if ( $cur_cat === $k->slug ) $cur_name = wp_specialchars_decode( $k->name );
				$sub .= '<li><a' . ( $cur_cat === $k->slug ? ' class="on"' : '' ) . ' href="' . self::link( get_term_link( $k ), $args ) . '">' . esc_html( wp_specialchars_decode( $k->name ) ) . ' <span>' . (int) $k->count . '</span></a></li>';
			}
			if ( $cur_cat === $c->slug ) $cur_name = wp_specialchars_decode( $c->name );
			$cats .= '<li><details' . ( $in ? ' open' : '' ) . '><summary>' . esc_html( wp_specialchars_decode( $c->name ) ) . ' <span>' . $total . '</span></summary><ul>' . $sub . '</ul></details></li>';
		}
		$all_eq = '<li><a class="lmf-all' . ( $cur_cat === '' ? ' on' : '' ) . '" href="' . self::link( get_post_type_archive_link( LM_Model::PT ), $args ) . '">All equipment</a></li>';
		$h .= self::section( 'Category', '<ul class="lmf-list lmf-tree">' . $all_eq . $cats . '</ul>', true, $cur_name );

		// Price: presets + custom range.
		$money = fn( $v ) => $v === '' ? '' : (string) $v;
		$presets = [ [ '', 25000, 'Under $25k' ], [ 25000, 50000, '$25k–$50k' ], [ 50000, 100000, '$50k–$100k' ], [ 100000, 250000, '$100k–$250k' ], [ 250000, '', '$250k+' ] ];
		$chips = '';
		$price_label = '';
		foreach ( $presets as [ $lo, $hi, $label ] ) {
			$on = $p['min_price'] === $lo && $p['max_price'] === $hi;
			if ( $on ) $price_label = $label;
			$a = array_merge( array_diff_key( $args, [ 'min_price' => 1, 'max_price' => 1 ] ), array_filter( [ 'min_price' => $lo, 'max_price' => $hi ], fn( $v ) => $v !== '' ) );
			$chips .= '<a class="lmf-pchip' . ( $on ? ' on' : '' ) . '" href="' . self::link( self::base_url(), $on ? array_diff_key( $args, [ 'min_price' => 1, 'max_price' => 1 ] ) : $a ) . '">' . esc_html( $label ) . '</a>';
		}
		if ( ! $price_label && ( $p['min_price'] !== '' || $p['max_price'] !== '' ) ) $price_label = 'Custom';
		$h .= self::section( 'Price', '<div class="lmf-pchips">' . $chips . '</div>' .
			'<div class="lmf-range"><label><span>Min $</span><input type="number" name="min_price" inputmode="numeric" min="0" step="1000" placeholder="No min" value="' . esc_attr( $money( $p['min_price'] ) ) . '"></label>' .
			'<label><span>Max $</span><input type="number" name="max_price" inputmode="numeric" min="0" step="1000" placeholder="No max" value="' . esc_attr( $money( $p['max_price'] ) ) . '"></label></div>', true, $price_label );

		// Year and hours.
		$years = range( (int) gmdate( 'Y' ) + 1, 2000 );
		$sel = function ( string $name, array $opts, $cur, string $any ) {
			$o = '<option value="">' . esc_html( $any ) . '</option>';
			foreach ( $opts as $v => $l ) $o .= '<option value="' . esc_attr( (string) $v ) . '"' . selected( (string) $cur, (string) $v, false ) . '>' . esc_html( $l ) . '</option>';
			return '<select name="' . esc_attr( $name ) . '">' . $o . '</select>';
		};
		$yopts = array_combine( $years, $years );
		$year_on = $p['year_min'] !== '' || $p['year_max'] !== '';
		$h .= self::section( 'Year', '<div class="lmf-range"><label><span>From</span>' . $sel( 'year_min', $yopts, $p['year_min'], 'Any' ) . '</label><label><span>To</span>' . $sel( 'year_max', $yopts, $p['year_max'], 'Any' ) . '</label></div>',
			$year_on, $year_on ? trim( $p['year_min'] . '–' . $p['year_max'], '–' ) : '' );
		$hopts = [ 500 => 'Under 500 hrs', 1000 => 'Under 1,000 hrs', 2500 => 'Under 2,500 hrs', 5000 => 'Under 5,000 hrs', 10000 => 'Under 10,000 hrs' ];
		$h .= self::section( 'Hours', $sel( 'hours_max', $hopts, $p['hours_max'], 'Any hours' ), $p['hours_max'] !== '', $p['hours_max'] !== '' ? ( $hopts[ $p['hours_max'] ] ?? 'Under ' . number_format( $p['hours_max'] ) ) : '' );

		// Make: the 8 with most listings, then "Show all" with a search box. On a category, only the makes in it,
		// linking to the make × category page.
		$in_cat = is_tax( LM_Model::CATEGORY ) && get_queried_object() instanceof WP_Term ? get_queried_object() : null;
		$list = [];
		if ( $in_cat ) {
			$counts = LM_Combo::makes_in( $in_cat );
			$terms = $counts ? get_terms( [ 'taxonomy' => LM_Model::MAKE, 'include' => array_keys( $counts ), 'hide_empty' => false ] ) : [];
			foreach ( $terms as $m ) {
				$on = $combo && $combo['make']->term_id === $m->term_id;
				$list[] = [ $m, (int) $counts[ $m->term_id ], $on, $on ? get_term_link( $in_cat ) : LM_Combo::url( $in_cat, $m ) ];
			}
		} else {
			foreach ( get_terms( [ 'taxonomy' => LM_Model::MAKE, 'hide_empty' => true ] ) as $m ) {
				$on = $cur_make === $m->slug;
				$list[] = [ $m, (int) $m->count, $on, $on ? get_post_type_archive_link( LM_Model::PT ) : get_term_link( $m ) ];
			}
		}
		usort( $list, fn( $a, $b ) => $b[1] <=> $a[1] ?: strcasecmp( $a[0]->name, $b[0]->name ) );
		$top = 8;
		$mk = '';
		$make_label = '';
		foreach ( $list as $i => [ $m, $n, $on, $url ] ) {
			if ( $on ) $make_label = wp_specialchars_decode( $m->name );
			$mk .= '<li' . ( $i >= $top && ! $on ? ' class="lmf-more"' : '' ) . ' data-name="' . esc_attr( strtolower( $m->name ) ) . '"><a' . ( $on ? ' class="on"' : '' ) . ' href="' . self::link( $url, $args ) . '">' .
				esc_html( wp_specialchars_decode( $m->name ) ) . ' <span>' . $n . '</span></a></li>';
		}
		if ( $mk ) {
			$extra = count( $list ) > $top;
			$h .= self::section( 'Make', ( $extra ? '<input type="search" class="lmf-msearch" placeholder="Search ' . count( $list ) . ' makes" aria-label="Search makes">' : '' ) .
				'<ul class="lmf-list lmf-makes">' . $mk . '</ul>' . ( $extra ? '<button type="button" class="lmf-showall">Show all ' . count( $list ) . ' makes</button>' : '' ), true, $make_label );
		}

		// State and condition only when listings differ (today all are used and in PA).
		foreach ( [ 'state' => [ 'lm_state', 'State' ], 'condition' => [ 'lm_condition', 'Condition' ] ] as $name => [ $key, $title ] ) {
			$vals = self::distinct( $key );
			if ( count( $vals ) < 2 && $p[ $name ] === '' ) continue;
			$opts = [];
			foreach ( $vals as $v => $n ) $opts[ $v ] = ( $name === 'condition' ? ucfirst( $v ) : $v ) . ' (' . $n . ')';
			$h .= self::section( $title, $sel( $name, $opts, $p[ $name ], 'Any' ), $p[ $name ] !== '', $p[ $name ] !== '' ? ( $name === 'condition' ? ucfirst( $p[ $name ] ) : $p[ $name ] ) : '' );
		}

		if ( $p['sort'] ) $h .= '<input type="hidden" name="sort" value="' . esc_attr( $p['sort'] ) . '">';
		if ( isset( $_GET['dealer'] ) ) $h .= '<input type="hidden" name="dealer" value="' . absint( $_GET['dealer'] ) . '">';
		$h .= '<div class="lmf-fapply"><button type="submit" class="lmf-btn">Show results</button>' .
			( $active ? '<a href="' . esc_url( get_post_type_archive_link( LM_Model::PT ) ) . '">Clear all</a>' : '' ) . '</div>';
		return self::filters_wrap( $toggle, $h );
	}

	private static function filters_wrap( string $toggle, string $panel ): string {
		return '<aside class="lmf-filters">' . $toggle . '<form class="lmf-fpanel" id="lmf-fpanel" action="' . esc_url( self::base_url() ) . '" method="get">' . $panel . '</form><div class="lmf-fshade" hidden></div></aside>' .
			'<script>(function(){var a=document.currentScript.previousElementSibling,t=a.querySelector(".lmf-ftoggle"),s=a.querySelector(".lmf-fshade"),f=a.querySelector("form");' .
			'function set(o){a.classList.toggle("is-open",o);t.setAttribute("aria-expanded",o?"true":"false");s.hidden=!o;document.documentElement.classList.toggle("lmf-noscroll",o);}' .
			't.addEventListener("click",function(){set(true);});a.querySelector(".lmf-fclose").addEventListener("click",function(){set(false);});s.addEventListener("click",function(){set(false);});' .
			'document.addEventListener("keydown",function(e){if(e.key==="Escape")set(false);});' .
			// Empty fields stay out of the URL; selects apply on change (desktop), the button applies on phones.
			'f.addEventListener("submit",function(){f.querySelectorAll("input,select").forEach(function(i){if(!i.value)i.disabled=true;});});' .
			'f.querySelectorAll("select").forEach(function(x){x.addEventListener("change",function(){if(!a.classList.contains("is-open"))f.requestSubmit();});});' .
			'var mk=a.querySelector(".lmf-makes"),b=a.querySelector(".lmf-showall"),q=a.querySelector(".lmf-msearch");' .
			'if(b)b.addEventListener("click",function(){var o=mk.classList.toggle("show-all");b.textContent=o?"Show fewer makes":b.dataset.l;});if(b)b.dataset.l=b.textContent;' .
			'if(q){q.addEventListener("keydown",function(e){if(e.key==="Enter")e.preventDefault();});q.addEventListener("input",function(){var v=q.value.trim().toLowerCase();mk.classList.toggle("is-search",!!v);' .
			'mk.querySelectorAll("li").forEach(function(li){li.hidden=!!v&&li.dataset.name.indexOf(v)<0;});if(b)b.hidden=!!v;});q.removeAttribute("name");}' .
			'})();</script>';
	}


	public static function archive_title() {
		$title = 'Heavy equipment for sale';
		if ( is_tax() ) $title = wp_specialchars_decode( single_term_title( '', false ) ) . ( is_tax( LM_Model::MAKE ) ? ' equipment' : '' ) . ' for sale';
		if ( $c = LM_Combo::current() ) $title = LM_Combo::name( $c['cat'], $c['make'] ) . ' for sale';
		// 0.31.0: a category can set its own H1 (term meta lm_h1), only on the clean category view.
		if ( ! $c && is_tax( LM_Model::CATEGORY ) && ( $o = get_queried_object() ) instanceof WP_Term && ( $h = get_term_meta( $o->term_id, 'lm_h1', true ) ) ) $title = $h;
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
		$tel = $phone ? 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) : '';
		$mailto = 'mailto:' . $email . '?subject=' . $subject;
		$form = LM_Inquiry::form( $p, $company, $email );
		return '<div class="lmf-dealer">' . ( $logo ? wp_get_attachment_image( $logo, 'medium', false, [ 'class' => 'lmf-logo' ] ) : '' ) .
			'<span class="lmf-kicker">Sold by</span><strong class="lmf-company">' . esc_html( $company ) . '</strong>' .
			( $place ? '<span class="lmf-meta">' . esc_html( $place ) . '</span>' : '' ) .
			( $phone ? '<a class="lmf-btn" href="' . esc_attr( $tel ) . '" data-lm-event="call">Call ' . esc_html( $phone ) . '</a>' : '' ) .
			// Yard units: the inquiry form (E3) replaces the mailto button; it keeps a mailto link inside.
			( $form ?: '<a class="lmf-btn ghost" href="' . esc_attr( $mailto ) . '" data-lm-event="email">Email dealer</a>' ) .
			'<a class="lmf-more" href="' . esc_url( LM_Dealers::url( $u ) ) . '">More equipment from this dealer →</a>' . self::source( $p->ID, $company ) . '</div>' .
			self::contact_bar( $p->ID, $tel, $form ? '' : $mailto );
	}

	/**
	 * Up to 991px: a bar fixed at the bottom with Call and Message/Email, shown once the dealer box has scrolled out of view.
	 * Same data-lm-event values as the box, so GTM and the dealer stats count both.
	 */
	private static function contact_bar( int $id, string $tel, string $mailto ): string {
		$second = $mailto
			? '<a class="lmf-btn ghost" href="' . esc_attr( $mailto ) . '" data-lm-event="email">Email</a>'
			: '<a class="lmf-btn ghost" href="#lmf-inq-' . $id . '" data-lm-event="inquiry_open" data-lmf-open>Message</a>';
		return '<div class="lmf-cbar" aria-hidden="true"><div class="lmf-cbar-in"><span class="lmf-cbar-price">' . esc_html( self::price_text( $id ) ) . '</span>' .
			( $tel ? '<a class="lmf-btn" href="' . esc_attr( $tel ) . '" data-lm-event="call">Call</a>' : '' ) . $second . '</div></div>' .
			'<script>(function(){var b=document.currentScript.previousElementSibling,box=b.previousElementSibling;if(!("IntersectionObserver" in window)||!box)return;' .
			'new IntersectionObserver(function(e){var on=!e[0].isIntersecting&&e[0].boundingClientRect.top<0;b.classList.toggle("is-on",on);b.setAttribute("aria-hidden",on?"false":"true");document.documentElement.classList.toggle("lmf-has-cbar",on);}).observe(box);' .
			'var o=b.querySelector("[data-lmf-open]");if(o)o.addEventListener("click",function(e){var d=document.getElementById(o.getAttribute("href").slice(1));if(!d)return;e.preventDefault();d.open=true;' .
			'd.scrollIntoView({behavior:"smooth",block:"start"});setTimeout(function(){var i=d.querySelector("input[name=name]");if(i)i.focus({preventScroll:true});},450);});})();</script>';
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
