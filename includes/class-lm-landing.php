<?php
/**
 * Category landings (0.16.0): the category archive IS the landing page (decision 12-nov-2026, seo/estructura-urls.md).
 *
 * Per category (term meta, editable by REST and in wp-admin):
 *   lm_intro      HTML under the H1, above the grid
 *   lm_body       HTML below the grid
 *   lm_faq        [{q, a}] shown below the body and as FAQPage JSON-LD
 *   lm_seo_title  title without " | Heavy Listing" (the SEO class adds the brand)
 *   lm_seo_desc   meta description
 * Live tokens in intro/body/faq/desc: {count} {median_price} {price_range} {dealers}
 *
 * Shortcodes: [lm_archive_crumbs] [lm_category_intro] [lm_category_content]
 * JSON-LD on category archives: BreadcrumbList + ItemList (+ FAQPage).
 */

defined( 'ABSPATH' ) || exit;

class LM_Landing {

	const FIELDS = [ 'lm_intro', 'lm_body', 'lm_seo_title', 'lm_seo_desc' ];

	private static array $stats = [];

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		foreach ( [ 'archive_crumbs', 'category_intro', 'category_content' ] as $s ) add_shortcode( 'lm_' . $s, [ __CLASS__, $s ] );
		add_action( 'wp_head', [ __CLASS__, 'json_ld' ], 32 );
		add_action( LM_Model::CATEGORY . '_edit_form_fields', [ __CLASS__, 'admin_fields' ], 20 );
		add_action( 'edited_' . LM_Model::CATEGORY, [ __CLASS__, 'admin_save' ] );
	}

	public static function register_meta() {
		foreach ( self::FIELDS as $k ) {
			register_term_meta( LM_Model::CATEGORY, $k, [
				'type' => 'string', 'single' => true, 'show_in_rest' => true,
				'sanitize_callback' => in_array( $k, [ 'lm_intro', 'lm_body' ], true ) ? 'wp_kses_post' : 'sanitize_text_field',
			] );
		}
		register_term_meta( LM_Model::CATEGORY, 'lm_faq', [
			'type' => 'array', 'single' => true,
			'show_in_rest' => [ 'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => false,
				'properties' => [ 'q' => [ 'type' => 'string' ], 'a' => [ 'type' => 'string' ] ] ] ] ],
			'sanitize_callback' => [ __CLASS__, 'sanitize_faq' ],
		] );
	}

	public static function sanitize_faq( $v ): array {
		$out = [];
		foreach ( (array) $v as $row ) {
			$row = (array) $row;
			$q = sanitize_text_field( $row['q'] ?? '' );
			$a = wp_kses( (string) ( $row['a'] ?? '' ), [ 'a' => [ 'href' => true ], 'strong' => [], 'em' => [] ] );
			if ( $q !== '' && $a !== '' ) $out[] = [ 'q' => $q, 'a' => $a ];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ data */

	private static function current(): ?WP_Term {
		$t = is_tax( LM_Model::CATEGORY ) ? get_queried_object() : null;
		return $t instanceof WP_Term ? $t : null;
	}

	/** First page and no filters: the only view that is indexable and should carry the landing copy. */
	private static function clean_view(): bool {
		if ( is_paged() ) return false;
		foreach ( array_keys( $_GET ) as $k ) {
			if ( ! preg_match( '/^(utm_|gclid|fbclid|gbraid|wbraid|msclkid|nc$)/', (string) $k ) ) return false;
		}
		return true;
	}

	/** Live numbers for a category (children included): listings not sold. */
	public static function stats( WP_Term $t ): array {
		if ( isset( self::$stats[ $t->term_id ] ) ) return self::$stats[ $t->term_id ];
		$ids = get_posts( [ 'post_type' => LM_Model::PT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
			'tax_query' => [ [ 'taxonomy' => LM_Model::CATEGORY, 'field' => 'term_id', 'terms' => $t->term_id, 'include_children' => true ] ] ] );
		update_meta_cache( 'post', $ids );
		$prices = [];
		$dealers = [];
		$n = 0;
		foreach ( $ids as $id ) {
			if ( get_post_meta( $id, 'lm_status', true ) === 'sold' ) continue;
			$n++;
			$dealers[ (int) get_post_field( 'post_author', $id ) ] = true;
			$p = (float) get_post_meta( $id, 'lm_price', true );
			if ( $p > 0 && ! get_post_meta( $id, 'lm_call_for_price', true ) ) $prices[] = $p;
		}
		sort( $prices );
		$c = count( $prices );
		$median = $c ? ( $c % 2 ? $prices[ intdiv( $c, 2 ) ] : ( $prices[ $c / 2 - 1 ] + $prices[ $c / 2 ] ) / 2 ) : 0;
		$money = fn( $v ) => '$' . number_format( round( $v / 100 ) * 100 );
		return self::$stats[ $t->term_id ] = [
			'{count}'        => number_format( $n ),
			'{median_price}' => $c ? $money( $median ) : 'call for price',
			'{price_range}'  => $c ? $money( $prices[0] ) . ' to ' . $money( $prices[ $c - 1 ] ) : 'call for price',
			'{dealers}'      => (string) count( $dealers ),
		];
	}

	public static function fill( string $s, WP_Term $t ): string {
		return strpos( $s, '{' ) === false ? $s : strtr( $s, self::stats( $t ) );
	}

	public static function meta( WP_Term $t, string $k ): string { return (string) get_term_meta( $t->term_id, $k, true ); }

	public static function faq( WP_Term $t ): array {
		$f = get_term_meta( $t->term_id, 'lm_faq', true );
		return is_array( $f ) ? $f : [];
	}

	/** Used by LM_Seo: custom title/description for a category, or null. */
	public static function seo( WP_Term $t, string $what ): ?string {
		$v = trim( self::meta( $t, $what === 'title' ? 'lm_seo_title' : 'lm_seo_desc' ) );
		if ( $v === '' ) return null;
		if ( $what === 'title' ) $v = preg_replace( '/\s*\|\s*Heavy Listing\s*$/i', '', $v );
		return self::fill( $v, $t );
	}

	/* ------------------------------------------------------------------ shortcodes */

	/** Trail for any equipment archive: Equipment / parent / category (also make and model archives). */
	public static function archive_crumbs() {
		if ( ! is_tax( [ LM_Model::CATEGORY, LM_Model::MAKE, LM_Model::MODEL ] ) ) return '';
		$parts = [];
		foreach ( self::trail( get_queried_object() ) as $i => [ $name, $url ] ) {
			$parts[] = $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>' : '<span aria-current="page">' . esc_html( $name ) . '</span>';
		}
		return '<nav class="lmf-crumbs" aria-label="Breadcrumb">' . implode( ' <span>/</span> ', $parts ) . '</nav>';
	}

	/** [[name, url|null]]: last item has no URL (current page). */
	private static function trail( WP_Term $t ): array {
		$items = [ [ 'Equipment', get_post_type_archive_link( LM_Model::PT ) ] ];
		if ( $t->taxonomy === LM_Model::CATEGORY ) {
			foreach ( array_reverse( get_ancestors( $t->term_id, LM_Model::CATEGORY, 'taxonomy' ) ) as $a ) {
				$p = get_term( $a );
				if ( $p && ! is_wp_error( $p ) ) $items[] = [ wp_specialchars_decode( $p->name, ENT_QUOTES ), get_term_link( $p ) ];
			}
		}
		$items[] = [ wp_specialchars_decode( $t->name, ENT_QUOTES ), null ];
		return $items;
	}

	public static function category_intro() {
		$t = self::current();
		if ( ! $t || ! self::clean_view() ) return '';
		$html = self::meta( $t, 'lm_intro' );
		return $html ? '<div class="lmf-cat-intro">' . self::fill( $html, $t ) . '</div>' : '';
	}

	public static function category_content() {
		$t = self::current();
		if ( ! $t || ! self::clean_view() ) return '';
		$body = self::meta( $t, 'lm_body' );
		$faq = self::faq( $t );
		if ( ! $body && ! $faq ) return '';
		$out = $body ? self::fill( $body, $t ) : '';
		if ( $faq ) {
			$out .= '<h2>Frequently asked questions</h2><div class="lmf-faq">';
			foreach ( $faq as $f ) $out .= '<h3>' . esc_html( self::fill( $f['q'], $t ) ) . '</h3><p>' . self::fill( $f['a'], $t ) . '</p>';
			$out .= '</div>';
		}
		return '<div class="lmf-prose lmf-cat-body">' . $out . '</div>';
	}

	/* ------------------------------------------------------------------ structured data */

	public static function json_ld() {
		$t = self::current();
		if ( ! $t ) return;
		$url = get_term_link( $t );
		if ( is_wp_error( $url ) ) return;
		$graph = [];

		$list = [];
		foreach ( self::trail( $t ) as $i => [ $name, $u ] ) {
			$list[] = [ '@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $u && ! is_wp_error( $u ) ? $u : $url ];
		}
		$graph[] = [ '@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => $list ];

		if ( self::clean_view() ) {
			global $wp_query;
			$items = [];
			foreach ( (array) $wp_query->posts as $i => $p ) {
				$items[] = [ '@type' => 'ListItem', 'position' => $i + 1, 'url' => get_permalink( $p ), 'name' => get_the_title( $p ) ];
			}
			if ( $items ) {
				$graph[] = [ '@type' => 'ItemList', '@id' => $url . '#list', 'name' => wp_specialchars_decode( $t->name, ENT_QUOTES ) . ' for sale',
					'numberOfItems' => (int) $wp_query->found_posts, 'itemListElement' => $items ];
			}
			$faq = self::faq( $t );
			if ( $faq ) {
				$graph[] = [ '@type' => 'FAQPage', '@id' => $url . '#faq', 'mainEntity' => array_map( fn( $f ) => [
					'@type' => 'Question', 'name' => self::fill( $f['q'], $t ),
					'acceptedAnswer' => [ '@type' => 'Answer', 'text' => wp_strip_all_tags( self::fill( $f['a'], $t ) ) ] ], $faq ) ];
			}
		}
		echo '<script type="application/ld+json">' . wp_json_encode( [ '@context' => 'https://schema.org', '@graph' => $graph ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	/* ------------------------------------------------------------------ wp-admin */

	public static function admin_fields( WP_Term $t ) {
		$faq = '';
		foreach ( self::faq( $t ) as $f ) $faq .= $f['q'] . ' | ' . $f['a'] . "\n";
		$rows = [
			'lm_seo_title' => [ 'SEO title', 'input', 'Without " | Heavy Listing". Tokens: {count} {median_price} {price_range} {dealers}' ],
			'lm_seo_desc'  => [ 'SEO description', 'textarea', '155 characters max. Same tokens.' ],
			'lm_intro'     => [ 'Landing intro (HTML)', 'textarea', 'Shown under the H1, above the listings. Same tokens.' ],
			'lm_body'      => [ 'Landing body (HTML)', 'textarea', 'Shown below the listings. Same tokens.' ],
		];
		wp_nonce_field( 'lm_landing', 'lm_landing_nonce' );
		foreach ( $rows as $k => [ $label, $type, $help ] ) {
			$v = self::meta( $t, $k );
			echo '<tr class="form-field"><th scope="row"><label for="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo $type === 'input'
				? '<input type="text" name="' . esc_attr( $k ) . '" id="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">'
				: '<textarea name="' . esc_attr( $k ) . '" id="' . esc_attr( $k ) . '" rows="' . ( $k === 'lm_body' ? 14 : 4 ) . '">' . esc_textarea( $v ) . '</textarea>';
			echo '<p class="description">' . esc_html( $help ) . '</p></td></tr>';
		}
		echo '<tr class="form-field"><th scope="row"><label for="lm_faq">FAQ</label></th><td><textarea name="lm_faq" id="lm_faq" rows="8">' . esc_textarea( $faq ) .
			'</textarea><p class="description">One per line: Question? | Answer</p></td></tr>';
	}

	public static function admin_save( int $term_id ) {
		if ( ! isset( $_POST['lm_landing_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lm_landing_nonce'] ) ), 'lm_landing' ) ) return;
		if ( ! current_user_can( 'edit_term', $term_id ) ) return;
		foreach ( self::FIELDS as $k ) {
			if ( isset( $_POST[ $k ] ) ) update_term_meta( $term_id, $k, wp_unslash( $_POST[ $k ] ) );
		}
		if ( isset( $_POST['lm_faq'] ) ) {
			$rows = [];
			foreach ( preg_split( '/\r?\n/', (string) wp_unslash( $_POST['lm_faq'] ) ) as $line ) {
				if ( strpos( $line, '|' ) === false ) continue;
				[ $q, $a ] = array_map( 'trim', explode( '|', $line, 2 ) );
				$rows[] = [ 'q' => $q, 'a' => $a ];
			}
			update_term_meta( $term_id, 'lm_faq', $rows );
		}
	}
}
