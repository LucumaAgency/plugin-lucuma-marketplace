<?php
/**
 * SEO for the marketplace, on top of SEOPress (its general settings stay in SEOPress):
 *
 *  - Titles and meta descriptions built from listing data (equipment, categories, makes, models,
 *    the equipment archive), through the seopress_titles_title / seopress_titles_desc filters.
 *  - noindex,follow on thin or duplicate pages: filtered/search archives, makes and models with fewer
 *    than 2 listings, empty categories, the dealer panel. The same pages are left out of the sitemap.
 *  - JSON-LD: Product + Offer + BreadcrumbList on a listing; WebSite + Organization on the home page.
 *  - Stable, unique listing URLs: <title>-<post id>, set on save (old slugs redirect through WordPress).
 *    Existing listings get it the next time they are saved (a REST update with an empty body is enough).
 */
defined( 'ABSPATH' ) || exit;

class LM_Seo {
	const BRAND     = 'Heavy Listing';
	const MIN_TERM  = 2;   // makes and models need at least this many listings to be indexed
	const CACHE_KEY = 'lm_seo_cache_v';

	/** Root categories whose name is not a machine ("Construction" → "Construction Equipment"). */
	const ROOT_NOUN = [
		'construction'       => 'Construction Equipment',
		'forestry'           => 'Forestry Equipment',
		'agriculture'        => 'Farm Equipment',
		'generators-power'   => 'Generators & Power Equipment',
		'attachments'        => 'Equipment Attachments',
	];

	const STATES = [
		'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado',
		'CT' => 'Connecticut', 'DE' => 'Delaware', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
		'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
		'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
		'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
		'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina',
		'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania',
		'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
		'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
		'WI' => 'Wisconsin', 'WY' => 'Wyoming',
	];

	/** Query args that do not change the page (tracking): a URL with only these stays indexable. */
	const IGNORED_ARGS = [ 'gclid', 'fbclid', 'msclkid', 'nc' ];

	public static function init() {
		add_filter( 'seopress_titles_title', [ __CLASS__, 'title' ], 20 );
		add_filter( 'seopress_titles_desc', [ __CLASS__, 'desc' ], 20 );
		add_filter( 'seopress_titles_robots', [ __CLASS__, 'robots' ], 20 );
		add_filter( 'seopress_sitemaps_term_single_url', [ __CLASS__, 'sitemap_term' ], 20, 2 );
		add_action( 'wp_head', [ __CLASS__, 'json_ld' ], 30 );
		add_action( 'save_post_' . LM_Model::PT, [ __CLASS__, 'on_save' ], 99, 2 );
	}

	/* ------------------------------------------------------------------ data helpers */

	private static function m( int $id, string $k ) { return get_post_meta( $id, $k, true ); }

	/** A number field with a real value (0 or empty = no data). */
	private static function num( int $id, string $k ): float { return max( 0, (float) self::m( $id, $k ) ); }

	private static function term( int $id, string $tax ) {
		$t = wp_get_object_terms( $id, $tax );
		return ( ! is_wp_error( $t ) && $t ) ? $t[0] : null;
	}

	private static function name( $term ): string { return wp_specialchars_decode( $term->name, ENT_QUOTES ); }

	private static function dealer( int $id ): string {
		$u = (int) get_post_field( 'post_author', $id );
		return (string) get_user_meta( $u, 'lm_company', true ) ?: get_the_author_meta( 'display_name', $u );
	}

	/** Plural noun for a category: "Skid Steers", "Construction Equipment". */
	private static function cat_noun( WP_Term $t ): string {
		return self::ROOT_NOUN[ $t->slug ] ?? self::name( $t );
	}

	private static function make_of_model( WP_Term $model ): string {
		$make = get_term( (int) get_term_meta( $model->term_id, 'lm_make', true ) );
		return ( $make && ! is_wp_error( $make ) ) ? self::name( $make ) : '';
	}

	/** Published listing ids in a term (categories include their children). Cached until a listing is saved. */
	private static function term_posts( WP_Term $t ): array {
		$key = 'lm_seo_t' . $t->term_id . '_' . (int) get_option( self::CACHE_KEY, 1 );
		$ids = get_transient( $key );
		if ( is_array( $ids ) ) return $ids;
		$ids = get_posts( [
			'post_type' => LM_Model::PT, 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true,
			'tax_query' => [ [ 'taxonomy' => $t->taxonomy, 'terms' => $t->term_id, 'include_children' => true ] ],
		] );
		set_transient( $key, $ids, DAY_IN_SECONDS );
		return $ids;
	}

	/** "Pennsylvania", "Pennsylvania and New York", "Ohio, Pennsylvania and New York", "8 states". */
	private static function states_text( array $ids ): string {
		$s = [];
		foreach ( $ids as $id ) {
			$st = strtoupper( (string) self::m( $id, 'lm_state' ) );
			if ( isset( self::STATES[ $st ] ) ) $s[ $st ] = self::STATES[ $st ];
		}
		$s = array_values( $s );
		if ( ! $s ) return '';
		if ( count( $s ) > 3 ) return count( $s ) . ' states';
		$last = array_pop( $s );
		return $s ? implode( ', ', $s ) . ' and ' . $last : $last;
	}

	private static function page_suffix(): string {
		$p = max( 1, (int) get_query_var( 'paged' ) );
		return $p > 1 ? ' - Page ' . $p : '';
	}

	private static function has_filters(): bool {
		foreach ( array_keys( $_GET ) as $k ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! str_starts_with( $k, 'utm_' ) && ! in_array( $k, self::IGNORED_ARGS, true ) ) return true;
		}
		return false;
	}

	private static function sentence_trim( string $s, int $max = 160 ): string {
		$s = trim( preg_replace( '/\s+/', ' ', $s ) );
		if ( mb_strlen( $s ) <= $max ) return $s;
		$cut = mb_substr( $s, 0, $max - 1 );
		return rtrim( mb_substr( $cut, 0, (int) mb_strrpos( $cut, ' ' ) ), ' ,.;:' ) . '…';
	}

	/* ------------------------------------------------------------------ titles and descriptions */

	public static function title( $value ) {
		$t = self::title_for_current();
		return $t === null ? $value : $t . self::page_suffix() . ' | ' . self::BRAND;
	}

	private static function title_for_current(): ?string {
		if ( is_singular( LM_Model::PT ) ) {
			$id = get_queried_object_id();
			$loc = implode( ', ', array_filter( [ self::m( $id, 'lm_city' ), self::m( $id, 'lm_state' ) ] ) );
			$t = get_the_title( $id );
			if ( self::m( $id, 'lm_status' ) === 'sold' ) return $t . ' (Sold)';
			return $t . ' for Sale' . ( $loc ? ' in ' . $loc : '' );
		}
		if ( is_tax( LM_Model::CATEGORY ) ) return LM_Landing::seo( get_queried_object(), 'title' ) ?? 'Used ' . self::cat_noun( get_queried_object() ) . ' for Sale';
		if ( is_tax( LM_Model::MAKE ) ) return 'Used ' . self::name( get_queried_object() ) . ' Equipment for Sale';
		if ( is_tax( LM_Model::MODEL ) ) {
			$m = get_queried_object();
			return 'Used ' . trim( self::make_of_model( $m ) . ' ' . self::name( $m ) ) . ' for Sale';
		}
		if ( is_post_type_archive( LM_Model::PT ) ) return 'Used Heavy Equipment for Sale From Dealers';
		if ( is_singular( LM_Auctions::PT ) ) {
			$id = get_queried_object_id();
			$d = LM_Auctions::dt( (string) self::m( $id, 'lm_start' ) );
			$place = LM_Auctions::place( $id );
			$where = LM_Auctions::online_only( $id ) ? ' (Online)' : ( $place ? ' in ' . $place : '' );
			return get_the_title( $id ) . ( $d ? ': ' . $d->format( 'M j, Y' ) : '' ) . $where . ( LM_Auctions::ended( $id ) ? ' (Ended)' : '' );
		}
		if ( is_post_type_archive( LM_Auctions::PT ) ) {
			$where = LM_Auctions::states_label();
			$month = LM_Auctions::month();
			if ( $month ) return 'Equipment Auctions' . ( $where ? ' in ' . $where : '' ) . ': ' . LM_Auctions::month_label( $month );
			return 'Heavy Equipment Auctions' . ( $where ? ' in ' . $where : '' ) . ': Upcoming Dates';
		}
		if ( is_author() && LM_Dealers::is_dealer( get_queried_object() ) ) {
			$u = get_queried_object_id();
			$place = LM_Dealers::place( $u );
			return LM_Dealers::company( $u ) . ': Used Equipment for Sale' . ( $place ? ' in ' . $place : '' );
		}
		return null;
	}

	public static function desc( $value ) {
		$d = self::desc_for_current();
		return $d === null ? $value : self::sentence_trim( $d );
	}

	private static function desc_for_current(): ?string {
		$close = 'Compare prices, hours and specs, and contact the dealer directly. No buyer fees.';
		if ( is_singular( LM_Model::PT ) ) {
			$id = get_queried_object_id();
			$cond = ucfirst( (string) self::m( $id, 'lm_condition' ) ?: 'used' );
			$loc = implode( ', ', array_filter( [ self::m( $id, 'lm_city' ), self::m( $id, 'lm_state' ) ] ) );
			$facts = [];
			$facts[] = ( self::m( $id, 'lm_call_for_price' ) || ! self::num( $id, 'lm_price' ) ) ? 'call for price' : '$' . number_format( self::num( $id, 'lm_price' ) );
			if ( self::num( $id, 'lm_hours' ) ) $facts[] = number_format( self::num( $id, 'lm_hours' ) ) . ' hours';
			$sold = self::m( $id, 'lm_status' ) === 'sold';
			return $cond . ' ' . get_the_title( $id ) . ( $sold ? ', sold' : ' for sale' ) . ( $loc ? ' in ' . $loc : '' ) . ': ' . implode( ', ', $facts ) .
				'. Sold by ' . self::dealer( $id ) . '. See photos and specs, and contact the dealer directly.';
		}
		if ( is_tax( LM_Model::CATEGORY ) && ( $custom = LM_Landing::seo( get_queried_object(), 'desc' ) ) !== null ) return $custom;
		if ( is_tax( [ LM_Model::CATEGORY, LM_Model::MAKE, LM_Model::MODEL ] ) ) {
			$t = get_queried_object();
			$ids = self::term_posts( $t );
			$n = count( $ids );
			$where = self::states_text( $ids );
			$where = $where ? ' from dealers in ' . $where : ' from equipment dealers';
			if ( $t->taxonomy === LM_Model::CATEGORY ) {
				$noun = self::cat_noun( $t );
				$noun = $n === 1 ? LM_Panel_Api::singular( $noun ) : $noun;
				return "Browse $n used " . strtolower( $noun ) . " for sale$where. $close";
			}
			if ( $t->taxonomy === LM_Model::MAKE ) {
				$cats = [];
				foreach ( $ids as $id ) {
					$c = self::term( $id, LM_Model::CATEGORY );
					if ( $c ) $cats[ self::name( $c ) ] = ( $cats[ self::name( $c ) ] ?? 0 ) + 1;
				}
				arsort( $cats );
				$top = array_slice( array_keys( $cats ), 0, 3 );
				return "Browse $n used " . self::name( $t ) . ' ' . ( $n === 1 ? 'machine' : 'machines' ) . " for sale$where" .
					( $top ? ': ' . strtolower( implode( ', ', $top ) ) : '' ) . ". $close";
			}
			$full = trim( self::make_of_model( $t ) . ' ' . self::name( $t ) );
			return "Browse $n used $full " . ( $n === 1 ? 'unit' : 'units' ) . " for sale$where. $close";
		}
		if ( is_post_type_archive( LM_Model::PT ) ) {
			$n = (int) wp_count_posts( LM_Model::PT )->publish;
			return "Browse $n used excavators, loaders, dozers, skid steers and more, listed directly by US equipment dealers. $close";
		}
		if ( is_singular( LM_Auctions::PT ) ) {
			$id = get_queried_object_id();
			$place = LM_Auctions::place( $id );
			return LM_Auctions::type_label( $id ) . ' by ' . LM_Auctions::auctioneer( $id ) . ' on ' . LM_Auctions::when( $id ) . ( $place && ! LM_Auctions::online_only( $id ) ? ' in ' . $place : '' ) .
				'. See the details and the auction catalog.';
		}
		if ( is_post_type_archive( LM_Auctions::PT ) ) {
			$where = LM_Auctions::states_label();
			$month = LM_Auctions::month();
			if ( $month ) {
				global $wp_query;
				return (int) $wp_query->found_posts . ' construction, farm and truck auctions' . ( $where ? ' in ' . $where : '' ) . ' in ' . LM_Auctions::month_label( $month ) .
					': dates, locations, auction type and catalogs, from dealers and auctioneers.';
			}
			return 'Calendar of upcoming heavy equipment auctions' . ( $where ? ' in ' . $where : '' ) . ' from dealers and auctioneers: dates, locations, auction type and catalogs. List your auction for free.';
		}
		if ( is_author() && LM_Dealers::is_dealer( get_queried_object() ) ) {
			$u = get_queried_object_id();
			$n = LM_Dealers::count_listings( $u );
			$cats = array_keys( LM_Dealers::top_categories( $u, 3 ) );
			$place = LM_Dealers::place( $u );
			return 'Browse ' . $n . ' used ' . ( $n === 1 ? 'machine' : 'machines' ) . ' from ' . LM_Dealers::company( $u ) . ( $place ? ' in ' . $place : '' ) .
				( $cats ? ': ' . strtolower( implode( ', ', $cats ) ) : '' ) . '. Contact the dealer directly, with no buyer fees.';
		}
		return null;
	}

	/* ------------------------------------------------------------------ robots and sitemap */

	/** Why a page should not be indexed, or '' when it should. */
	private static function noindex_reason(): string {
		if ( is_page( 'dealer' ) ) return 'dealer panel';
		$ours = is_post_type_archive( [ LM_Model::PT, LM_Auctions::PT ] ) || is_tax( [ LM_Model::CATEGORY, LM_Model::MAKE, LM_Model::MODEL ] );
		if ( $ours && self::has_filters() ) return 'filtered';
		if ( is_singular( LM_Auctions::PT ) && LM_Auctions::ended( get_queried_object_id() ) ) return 'ended auction';
		if ( is_post_type_archive( LM_Auctions::PT ) && LM_Auctions::month() ) {
			global $wp_query;
			if ( ! $wp_query->found_posts ) return 'empty month';
		}
		if ( is_tax( [ LM_Model::CATEGORY, LM_Model::MAKE, LM_Model::MODEL ] ) ) {
			$t = get_queried_object();
			if ( ! self::term_indexable( $t ) ) return 'thin';
		}
		return '';
	}

	private static function term_indexable( WP_Term $t ): bool {
		$n = count( self::term_posts( $t ) );
		return $t->taxonomy === LM_Model::CATEGORY ? $n >= 1 : $n >= self::MIN_TERM;
	}

	public static function robots( $tag ) {
		return self::noindex_reason() ? '<meta name="robots" content="noindex, follow">' . "\n" : $tag;
	}

	public static function sitemap_term( $url, $term ) {
		if ( $term instanceof WP_Term && in_array( $term->taxonomy, [ LM_Model::CATEGORY, LM_Model::MAKE, LM_Model::MODEL ], true ) && ! self::term_indexable( $term ) ) {
			$url['loc'] = '';
		}
		return $url;
	}

	/* ------------------------------------------------------------------ structured data */

	public static function json_ld() {
		$graph = [];
		if ( is_singular( LM_Model::PT ) ) {
			$graph[] = self::product( get_queried_object_id() );
			$graph[] = self::breadcrumbs( get_queried_object_id() );
		} elseif ( is_author() && LM_Dealers::is_dealer( get_queried_object() ) ) {
			$graph[] = self::dealer_org( get_queried_object_id() );
		} elseif ( is_singular( LM_Auctions::PT ) ) {
			$graph[] = self::event( get_queried_object_id() );
		} elseif ( is_front_page() ) {
			$home = home_url( '/' );
			$graph[] = [ '@type' => 'Organization', '@id' => $home . '#organization', 'name' => self::BRAND, 'url' => $home,
				'email' => 'contact@heavylisting.com' ];
			$graph[] = [ '@type' => 'WebSite', '@id' => $home . '#website', 'name' => self::BRAND, 'url' => $home,
				'publisher' => [ '@id' => $home . '#organization' ],
				'potentialAction' => [ '@type' => 'SearchAction',
					'target' => [ '@type' => 'EntryPoint', 'urlTemplate' => get_post_type_archive_link( LM_Model::PT ) . '?q={search_term_string}' ],
					'query-input' => 'required name=search_term_string' ] ];
		}
		if ( ! $graph ) return;
		echo '<script type="application/ld+json">' . wp_json_encode( [ '@context' => 'https://schema.org', '@graph' => array_values( array_filter( $graph ) ) ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	private static function product( int $id ): array {
		$make  = self::term( $id, LM_Model::MAKE );
		$model = self::term( $id, LM_Model::MODEL );
		$cat   = self::term( $id, LM_Model::CATEGORY );
		$cond  = [ 'new' => 'NewCondition', 'used' => 'UsedCondition', 'refurbished' => 'RefurbishedCondition' ][ self::m( $id, 'lm_condition' ) ] ?? 'UsedCondition';
		$status = self::m( $id, 'lm_status' );
		$avail = [ 'sold' => 'SoldOut', 'pending' => 'LimitedAvailability' ][ $status ] ?? 'InStock';

		$images = [];
		$ids = array_filter( array_map( 'intval', (array) ( self::m( $id, 'lm_gallery' ) ?: [] ) ) );
		if ( has_post_thumbnail( $id ) ) array_unshift( $ids, (int) get_post_thumbnail_id( $id ) );
		foreach ( array_unique( $ids ) as $a ) {
			$u = wp_get_attachment_image_url( $a, 'large' );
			if ( $u ) $images[] = $u;
		}

		$address = array_filter( [ '@type' => 'PostalAddress', 'addressLocality' => self::m( $id, 'lm_city' ),
			'addressRegion' => self::m( $id, 'lm_state' ), 'postalCode' => self::m( $id, 'lm_zip' ), 'addressCountry' => 'US' ] );
		$seller = [ '@type' => 'Organization', 'name' => self::dealer( $id ), 'address' => $address, 'url' => LM_Dealers::url( (int) get_post_field( 'post_author', $id ) ) ];

		$p = [
			'@type' => 'Product', '@id' => get_permalink( $id ) . '#product',
			'name' => get_the_title( $id ),
			'description' => self::sentence_trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) ), 500 ) ?: get_the_title( $id ),
			'url' => get_permalink( $id ),
			'itemCondition' => 'https://schema.org/' . $cond,
		];
		if ( $images ) $p['image'] = $images;
		if ( self::m( $id, 'lm_stock_number' ) !== '' ) $p['sku'] = (string) self::m( $id, 'lm_stock_number' );
		if ( $make ) $p['brand'] = [ '@type' => 'Brand', 'name' => self::name( $make ) ];
		if ( $model ) $p['model'] = self::name( $model );
		if ( $cat ) $p['category'] = self::name( $cat );
		if ( self::num( $id, 'lm_year' ) ) $p['productionDate'] = (string) (int) self::num( $id, 'lm_year' );
		$props = [];
		if ( self::num( $id, 'lm_hours' ) ) $props[] = [ '@type' => 'PropertyValue', 'name' => 'Hours', 'value' => self::num( $id, 'lm_hours' ), 'unitText' => 'hours' ];
		if ( self::num( $id, 'lm_mileage' ) ) $props[] = [ '@type' => 'PropertyValue', 'name' => 'Mileage', 'value' => self::num( $id, 'lm_mileage' ), 'unitText' => 'miles' ];
		if ( $props ) $p['additionalProperty'] = $props;

		$price = self::num( $id, 'lm_price' );
		if ( ! self::m( $id, 'lm_call_for_price' ) && $price ) {
			$p['offers'] = [
				'@type' => 'Offer', 'url' => get_permalink( $id ), 'priceCurrency' => 'USD', 'price' => (string) $price,
				'availability' => 'https://schema.org/' . $avail, 'itemCondition' => 'https://schema.org/' . $cond,
				'seller' => $seller,
				'availableAtOrFrom' => [ '@type' => 'Place', 'address' => $address ],
			];
		}
		return $p;
	}

	/**
	 * Dealer profile = the dealer's location page. LocalBusiness when we have a street address (Google needs it),
	 * Organization otherwise. Hours only if they parse, and they are also shown on the page.
	 */
	private static function dealer_org( int $u ): array {
		$m = fn( $k ) => (string) get_user_meta( $u, $k, true );
		$url = LM_Dealers::url( $u );
		$address = array_filter( [ '@type' => 'PostalAddress', 'streetAddress' => $m( 'lm_address' ), 'addressLocality' => $m( 'lm_city' ),
			'addressRegion' => $m( 'lm_state' ), 'postalCode' => $m( 'lm_zip' ), 'addressCountry' => 'US' ] );
		$local = $m( 'lm_address' ) !== '' && $m( 'lm_city' ) !== '';
		$o = [ '@type' => $local ? 'LocalBusiness' : 'Organization', '@id' => $url . '#dealer', 'name' => LM_Dealers::company( $u ), 'url' => $url, 'address' => $address ];
		if ( $m( 'lm_phone' ) ) $o['telephone'] = $m( 'lm_phone' );
		$same = array_values( array_filter( [ $m( 'lm_website' ), $m( 'lm_gbp' ) ] ) );
		if ( $same ) $o['sameAs'] = $same;
		$logo = (int) $m( 'lm_logo' );
		if ( $logo && ( $src = wp_get_attachment_image_url( $logo, 'medium' ) ) ) { $o['logo'] = $src; $o['image'] = $src; }
		if ( $local ) {
			$hours = array_column( LM_Dealers::hours( $u ), 'schema' );
			if ( $hours ) $o['openingHours'] = count( $hours ) === 1 ? $hours[0] : $hours;
			if ( $map = LM_Dealers::map_url( $u ) ) $o['hasMap'] = $map;
			$cats = array_keys( LM_Dealers::top_categories( $u, 4 ) );
			if ( $cats ) $o['description'] = 'Used ' . strtolower( implode( ', ', $cats ) ) . ' for sale from ' . LM_Dealers::company( $u ) . ' in ' . LM_Dealers::place( $u ) . '.';
		}
		return $o;
	}

	private static function event( int $id ): ?array {
		$start = LM_Auctions::dt( (string) self::m( $id, 'lm_start' ) );
		if ( ! $start ) return null;
		$end  = LM_Auctions::dt( (string) self::m( $id, 'lm_end' ) );
		$type = (string) self::m( $id, 'lm_type' );
		$url  = (string) self::m( $id, 'lm_url' );
		$mode = [ 'live' => 'Offline', 'online' => 'Online', 'timed' => 'Online', 'live-online' => 'Mixed', 'live-online-phone' => 'Mixed' ][ $type ] ?? 'Offline';
		$address = array_filter( [ '@type' => 'PostalAddress', 'streetAddress' => self::m( $id, 'lm_address' ), 'addressLocality' => self::m( $id, 'lm_city' ),
			'addressRegion' => self::m( $id, 'lm_state' ), 'postalCode' => self::m( $id, 'lm_zip' ), 'addressCountry' => 'US' ] );
		$loc = [];
		if ( ! empty( $address['addressLocality'] ) ) $loc[] = [ '@type' => 'Place', 'name' => LM_Auctions::auctioneer( $id ), 'address' => $address ];
		if ( $mode !== 'Offline' && $url ) $loc[] = [ '@type' => 'VirtualLocation', 'url' => $url ];
		$e = [
			'@type' => 'Event', '@id' => get_permalink( $id ) . '#event', 'name' => get_the_title( $id ), 'url' => get_permalink( $id ),
			'startDate' => $start->format( 'c' ),
			'eventStatus' => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => 'https://schema.org/' . $mode . 'EventAttendanceMode',
			'location' => count( $loc ) === 1 ? $loc[0] : $loc,
			'organizer' => array_filter( [ '@type' => 'Organization', 'name' => LM_Auctions::auctioneer( $id ), 'url' => $url ] ),
			'description' => self::sentence_trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) ), 500 ) ?: LM_Auctions::type_label( $id ) . ' by ' . LM_Auctions::auctioneer( $id ),
		];
		if ( $end ) $e['endDate'] = $end->format( 'c' );
		if ( has_post_thumbnail( $id ) ) $e['image'] = [ wp_get_attachment_image_url( get_post_thumbnail_id( $id ), 'large' ) ];
		return $e;
	}

	private static function breadcrumbs( int $id ): array {
		$items = [ [ 'Equipment', get_post_type_archive_link( LM_Model::PT ) ] ];
		$cat = self::term( $id, LM_Model::CATEGORY );
		if ( $cat ) {
			foreach ( array_reverse( get_ancestors( $cat->term_id, LM_Model::CATEGORY, 'taxonomy' ) ) as $a ) {
				$t = get_term( $a );
				$items[] = [ self::name( $t ), get_term_link( $t ) ];
			}
			$items[] = [ self::name( $cat ), get_term_link( $cat ) ];
		}
		$items[] = [ get_the_title( $id ), get_permalink( $id ) ];
		$list = [];
		foreach ( $items as $i => [ $name, $url ] ) {
			$list[] = [ '@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => is_wp_error( $url ) ? null : $url ];
		}
		return [ '@type' => 'BreadcrumbList', 'itemListElement' => $list ];
	}

	/* ------------------------------------------------------------------ unique URLs */

	/** "<title>-<id>": unique and stable even when two dealers list the same model. */
	private static function wanted_slug( WP_Post $p ): string {
		return sanitize_title( $p->post_title ) . '-' . $p->ID;
	}

	public static function on_save( int $id, WP_Post $p ) {
		update_option( self::CACHE_KEY, (int) get_option( self::CACHE_KEY, 1 ) + 1, false );
		if ( wp_is_post_revision( $id ) || in_array( $p->post_status, [ 'auto-draft', 'trash' ], true ) || $p->post_title === '' ) return;
		if ( str_ends_with( $p->post_name, '-' . $id ) ) return;
		remove_action( 'save_post_' . LM_Model::PT, [ __CLASS__, 'on_save' ], 99 );
		wp_update_post( [ 'ID' => $id, 'post_name' => self::wanted_slug( $p ) ] );
		add_action( 'save_post_' . LM_Model::PT, [ __CLASS__, 'on_save' ], 99, 2 );
	}
}
