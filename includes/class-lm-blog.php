<?php
/**
 * Blog: listing, article header, related posts, reading styles and BlogPosting JSON-LD.
 * Editorial look on purpose (not the equipment cards): category pill, big title, excerpt, date and reading time.
 *
 *   [lm_blog count="12"]       posts; on the /blog/ page the newest is a large lead card. main="1" = category archive
 *   [lm_blog_cats]             category chips (All + each category with posts)
 *   [lm_blog_title]            h1 + description on a category archive
 *   [lm_post_meta]             article header: category pill · date · reading time · author
 *   [lm_related_posts count="3"]
 *
 * Posts can embed live inventory and auctions: [lm_grid count="4" category="skid-steers"], [lm_auctions count="4"].
 */
defined( 'ABSPATH' ) || exit;

class LM_Blog {
	const AUTHOR = 'Heavy Listing Team';

	public static function init() {
		foreach ( [ 'blog', 'blog_cats', 'blog_title', 'post_meta', 'related_posts' ] as $s ) add_shortcode( 'lm_' . $s, [ __CLASS__, $s ] );
		add_action( 'wp_head', [ __CLASS__, 'json_ld' ], 31 );
		add_action( 'pre_get_posts', function ( WP_Query $q ) {
			if ( ! is_admin() && $q->is_main_query() && $q->is_category() ) $q->set( 'posts_per_page', 12 );
		} );
	}

	/* ------------------------------------------------------------------ helpers */

	public static function minutes( int $id ): int {
		return max( 1, (int) round( str_word_count( wp_strip_all_tags( strip_shortcodes( (string) get_post_field( 'post_content', $id ) ) ) ) / 230 ) );
	}

	private static function cat( int $id ): ?WP_Term {
		$c = get_the_category( $id );
		foreach ( $c as $t ) if ( $t->slug !== 'uncategorized' ) return $t;
		return null;
	}

	private static function pill( ?WP_Term $c, bool $link = true ): string {
		if ( ! $c ) return '';
		$n = esc_html( wp_specialchars_decode( $c->name ) );
		$cls = 'lmf-pill c-' . sanitize_html_class( $c->slug );
		return $link ? '<a class="' . $cls . '" href="' . esc_url( get_category_link( $c ) ) . '">' . $n . '</a>' : '<span class="' . $cls . '">' . $n . '</span>';
	}

	private static function excerpt( int $id, int $words = 26 ): string {
		$e = has_excerpt( $id ) ? get_the_excerpt( $id ) : wp_strip_all_tags( strip_shortcodes( (string) get_post_field( 'post_content', $id ) ) );
		return wp_trim_words( $e, $words, '…' );
	}

	private static function meta_line( int $id ): string {
		return '<span class="lmf-post-date">' . esc_html( get_the_date( 'M j, Y', $id ) ) . '</span><span class="lmf-post-dot">·</span><span>' . self::minutes( $id ) . ' min read</span>';
	}

	private static function card( int $id, bool $lead = false ): string {
		$link = esc_url( get_permalink( $id ) );
		$img = get_the_post_thumbnail( $id, $lead ? 'large' : 'medium_large', [ 'loading' => $lead ? 'eager' : 'lazy', 'alt' => esc_attr( get_the_title( $id ) ) ] );
		return '<article class="lmf-post' . ( $lead ? ' is-lead' : '' ) . '">' .
			'<a class="lmf-post-img" href="' . $link . '" tabindex="-1" aria-hidden="true">' . ( $img ?: '<span class="lmf-post-noimg"></span>' ) . '</a>' .
			'<div class="lmf-post-body">' . self::pill( self::cat( $id ) ) .
			'<h3 class="lmf-post-title"><a href="' . $link . '">' . esc_html( get_the_title( $id ) ) . '</a></h3>' .
			'<p class="lmf-post-excerpt">' . esc_html( self::excerpt( $id, $lead ? 40 : 24 ) ) . '</p>' .
			'<p class="lmf-post-meta">' . self::meta_line( $id ) . '</p></div></article>';
	}

	/* ------------------------------------------------------------------ shortcodes */

	public static function blog( $atts ) {
		$a = shortcode_atts( [ 'count' => 12, 'main' => '0' ], $atts );
		if ( $a['main'] === '1' ) {
			global $wp_query;
			$ids = wp_list_pluck( $wp_query->posts, 'ID' );
			$total = $wp_query->max_num_pages;
			$cur = max( 1, (int) get_query_var( 'paged' ) );
			$lead = false;
		} else {
			$cur = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
			$q = new WP_Query( [ 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => max( 1, (int) $a['count'] ), 'paged' => $cur, 'fields' => 'ids' ] );
			$ids = $q->posts;
			$total = $q->max_num_pages;
			$lead = $cur === 1;
		}
		if ( ! $ids ) return '<div class="lmf-empty"><p><strong>No articles yet.</strong></p></div>';
		$html = '';
		if ( $lead ) $html .= '<div class="lmf-posts-lead">' . self::card( (int) array_shift( $ids ), true ) . '</div>';
		$html .= $ids ? '<div class="lmf-posts">' . implode( '', array_map( fn( $id ) => self::card( (int) $id ), $ids ) ) . '</div>' : '';
		$pages = paginate_links( [ 'total' => $total, 'current' => $cur, 'type' => 'list', 'prev_text' => '←', 'next_text' => '→' ] );
		return $html . ( $pages ? '<nav class="lmf-pages">' . $pages . '</nav>' : '' );
	}

	public static function blog_cats() {
		$cur = is_category() ? get_queried_object_id() : 0;
		$page = get_page_by_path( 'blog' );
		$out = '<a class="lmf-chip' . ( $cur ? '' : ' on' ) . '" href="' . esc_url( $page ? get_permalink( $page ) : home_url( '/blog/' ) ) . '">All articles</a>';
		foreach ( get_categories( [ 'hide_empty' => true, 'exclude' => [ (int) get_option( 'default_category' ) ] ] ) as $c ) {
			$out .= '<a class="lmf-chip' . ( $cur === $c->term_id ? ' on' : '' ) . '" href="' . esc_url( get_category_link( $c ) ) . '">' . esc_html( wp_specialchars_decode( $c->name ) ) . ' <span>' . (int) $c->count . '</span></a>';
		}
		return '<nav class="lmf-chips lmf-blog-cats" aria-label="Blog categories">' . $out . '</nav>';
	}

	public static function blog_title() {
		if ( ! is_category() ) return '';
		$c = get_queried_object();
		$d = trim( wp_strip_all_tags( category_description( $c ) ) );
		return '<span class="lmf-kicker">Heavy Listing blog</span><h1 class="lmf-h1">' . esc_html( wp_specialchars_decode( $c->name ) ) . '</h1>' . ( $d ? '<p class="lmf-lead">' . esc_html( $d ) . '</p>' : '' );
	}

	public static function post_meta() {
		$id = get_the_ID();
		if ( get_post_type( $id ) !== 'post' ) return '';
		return '<p class="lmf-post-head">' . self::pill( self::cat( $id ) ) . '<span class="lmf-post-meta">' . self::meta_line( $id ) .
			'<span class="lmf-post-dot">·</span><span>By ' . esc_html( self::AUTHOR ) . '</span></span></p>';
	}

	public static function related_posts( $atts ) {
		$a = shortcode_atts( [ 'count' => 3 ], $atts );
		$id = get_the_ID();
		$c = self::cat( $id );
		$args = [ 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => (int) $a['count'], 'post__not_in' => [ $id ], 'fields' => 'ids', 'no_found_rows' => true ];
		$ids = $c ? get_posts( $args + [ 'cat' => $c->term_id ] ) : [];
		if ( count( $ids ) < (int) $a['count'] ) $ids = array_unique( array_merge( $ids, get_posts( array_merge( $args, [ 'post__not_in' => array_merge( [ $id ], $ids ) ] ) ) ) );
		$ids = array_slice( $ids, 0, (int) $a['count'] );
		return $ids ? '<div class="lmf-posts">' . implode( '', array_map( fn( $i ) => self::card( (int) $i ), $ids ) ) . '</div>' : '';
	}

	/* ------------------------------------------------------------------ structured data */

	public static function json_ld() {
		if ( ! is_singular( 'post' ) ) return;
		$id = get_queried_object_id();
		$home = home_url( '/' );
		$d = [
			'@context' => 'https://schema.org', '@type' => 'BlogPosting', '@id' => get_permalink( $id ) . '#article',
			'headline' => get_the_title( $id ), 'description' => self::excerpt( $id, 40 ),
			'datePublished' => get_the_date( 'c', $id ), 'dateModified' => get_the_modified_date( 'c', $id ),
			'mainEntityOfPage' => get_permalink( $id ),
			'author' => [ '@type' => 'Organization', 'name' => self::AUTHOR, 'url' => $home ],
			'publisher' => [ '@type' => 'Organization', 'name' => 'Heavy Listing', 'url' => $home ],
			'wordCount' => str_word_count( wp_strip_all_tags( strip_shortcodes( (string) get_post_field( 'post_content', $id ) ) ) ),
		];
		if ( has_post_thumbnail( $id ) ) $d['image'] = [ wp_get_attachment_image_url( get_post_thumbnail_id( $id ), 'full' ) ];
		$c = self::cat( $id );
		if ( $c ) $d['articleSection'] = wp_specialchars_decode( $c->name );
		echo '<script type="application/ld+json">' . wp_json_encode( $d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}
}
