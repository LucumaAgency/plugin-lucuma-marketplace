<?php
/**
 * Inventory importer (phase 1, step 4). CSV → preview → import in small batches.
 *
 *   GET  /import/template           CSV with the columns (and one example row)
 *   POST /import                    multipart "file" (CSV) or JSON { rows: [...] }; optional "dealer" (admins)
 *                                   → job id + preview: each row ready / with errors
 *   POST /import/{job}/run          { limit } → imports the next rows (photos included) and reports progress
 *
 * Rows are matched to existing listings by stock number (per dealer): re-importing updates them.
 * Photos are downloaded from their URLs once; the source URL is kept on the attachment so a second
 * import reuses it. Categories and makes are resolved with LM_Rest::find_term (names, slugs, aliases).
 */
defined( 'ABSPATH' ) || exit;

class LM_Import {
	const NS       = 'lucuma-mkt/v1';
	const MAX_ROWS = 2000;
	const COLUMNS  = [ 'stock_number', 'category', 'make', 'model', 'year', 'hours', 'mileage', 'price', 'call_for_price',
		'condition', 'status', 'serial', 'city', 'state', 'zip', 'title', 'description', 'video_url', 'photos' ];
	const ALIASES  = [ 'stock' => 'stock_number', 'stock_#' => 'stock_number', 'stock_no' => 'stock_number', 'manufacturer' => 'make',
		'type' => 'category', 'hrs' => 'hours', 'miles' => 'mileage', 'vin' => 'serial', 'serial_number' => 'serial', 'images' => 'photos',
		'photo_urls' => 'photos', 'image_urls' => 'photos', 'notes' => 'description', 'postal_code' => 'zip' ];

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes() {
		$dealer = fn() => current_user_can( 'edit_lm_equipments' );
		register_rest_route( self::NS, '/import/template', [ 'methods' => 'GET', 'permission_callback' => $dealer, 'callback' => [ __CLASS__, 'template' ] ] );
		register_rest_route( self::NS, '/import', [ 'methods' => 'POST', 'permission_callback' => $dealer, 'callback' => [ __CLASS__, 'start' ] ] );
		register_rest_route( self::NS, '/import/(?P<job>[a-z0-9]{12})/run', [ 'methods' => 'POST', 'permission_callback' => $dealer, 'callback' => [ __CLASS__, 'run' ] ] );
	}

	/* ------------------------------------------------------------------ template */

	public static function template() {
		$h = fopen( 'php://temp', 'r+' );
		fputcsv( $h, array_merge( self::COLUMNS, [ 'spec:operating_weight', 'spec:engine_hp' ] ) );
		fputcsv( $h, [ 'A1024', 'Compact Track Loaders', 'Bobcat', 'T770', '2021', '1450', '', '68500', 'no', 'used', 'available', 'B4CF12345',
			'Littlestown', 'PA', '17340', '', 'Enclosed cab with heat and AC. High flow. Two-speed.', '', 'https://example.com/photo1.jpg|https://example.com/photo2.jpg', '10175', '92' ] );
		rewind( $h );
		$csv = stream_get_contents( $h );
		fclose( $h );
		$r = new WP_REST_Response( $csv );
		$r->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$r->header( 'Content-Disposition', 'attachment; filename="inventory-template.csv"' );
		return $r;
	}

	/* ------------------------------------------------------------------ parse + preview */

	private static function key( string $h ): string {
		$h = strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', $h ) ) );
		if ( str_starts_with( $h, 'spec:' ) ) return 'spec:' . preg_replace( '/[^a-z0-9_]/', '_', substr( $h, 5 ) );
		$h = preg_replace( '/[^a-z0-9#]+/', '_', $h );
		$h = trim( $h, '_' );
		return self::ALIASES[ $h ] ?? $h;
	}

	private static function parse_csv( string $text ): array {
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		$first = strtok( $text, "\n" );
		$sep = substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ? ';' : ',';
		$h = fopen( 'php://temp', 'r+' );
		fwrite( $h, $text );
		rewind( $h );
		$head = null; $rows = [];
		while ( ( $r = fgetcsv( $h, 0, $sep ) ) !== false ) {
			if ( $r === [ null ] ) continue;
			if ( $head === null ) { $head = array_map( [ __CLASS__, 'key' ], $r ); continue; }
			$row = [];
			foreach ( $head as $i => $k ) if ( $k !== '' ) $row[ $k ] = isset( $r[ $i ] ) ? trim( (string) $r[ $i ] ) : '';
			if ( array_filter( $row, fn( $v ) => $v !== '' ) ) $rows[] = $row;
			if ( count( $rows ) > self::MAX_ROWS ) break;
		}
		fclose( $h );
		return $rows;
	}

	private static function num( $v ) {
		$v = preg_replace( '/[^0-9.\-]/', '', (string) $v );
		return $v === '' || ! is_numeric( $v ) ? '' : $v + 0;
	}

	private static function yes( $v ): bool {
		return in_array( strtolower( trim( (string) $v ) ), [ '1', 'yes', 'y', 'true', 'si', 'sí', 'x', 'call', 'call for price' ], true );
	}

	/** One row → panel payload, or the list of what is wrong. */
	private static function check( array $r ): array {
		$err = [];
		$cat = LM_Rest::find_term( LM_Model::CATEGORY, (string) ( $r['category'] ?? '' ) );
		if ( ! $cat ) $err[] = 'Unknown category «' . ( $r['category'] ?? '' ) . '»';
		$make = LM_Rest::find_term( LM_Model::MAKE, (string) ( $r['make'] ?? '' ) );
		if ( ! $make ) $err[] = 'Unknown make «' . ( $r['make'] ?? '' ) . '»';
		if ( trim( (string) ( $r['model'] ?? '' ) ) === '' ) $err[] = 'Model is empty';
		$price = self::num( $r['price'] ?? '' );
		$call  = self::yes( $r['call_for_price'] ?? '' ) || ( $price === '' && preg_match( '/call/i', (string) ( $r['price'] ?? '' ) ) );
		$photos = array_values( array_filter( array_map( 'trim', preg_split( '/[|\n]+/', (string) ( $r['photos'] ?? '' ) ) ), fn( $u ) => (bool) wp_http_validate_url( $u ) ) );
		$specs = [];
		foreach ( $r as $k => $v ) if ( str_starts_with( $k, 'spec:' ) && $v !== '' ) $specs[ substr( $k, 5 ) ] = $v;
		$cond = strtolower( (string) ( $r['condition'] ?? '' ) );
		$status = strtolower( (string) ( $r['status'] ?? '' ) );
		$payload = [
			'category' => $cat ? (string) $cat->term_id : '', 'make' => $make ? (string) $make->term_id : '',
			'model' => trim( (string) ( $r['model'] ?? '' ) ), 'title' => (string) ( $r['title'] ?? '' ),
			'year' => self::num( $r['year'] ?? '' ), 'hours' => self::num( $r['hours'] ?? '' ), 'mileage' => self::num( $r['mileage'] ?? '' ),
			'price' => $call ? '' : $price, 'call' => $call,
			'condition' => in_array( $cond, LM_Model::CONDITIONS, true ) ? $cond : 'used',
			'status' => in_array( $status, LM_Model::STATUSES, true ) ? $status : 'available',
			'stock' => (string) ( $r['stock_number'] ?? '' ), 'serial' => (string) ( $r['serial'] ?? '' ),
			'city' => (string) ( $r['city'] ?? '' ), 'state' => (string) ( $r['state'] ?? '' ), 'zip' => (string) ( $r['zip'] ?? '' ),
			'description' => (string) ( $r['description'] ?? '' ), 'video_url' => (string) ( $r['video_url'] ?? '' ),
			'specs' => $specs,
		];
		$label = trim( implode( ' ', array_filter( [ $payload['year'], $make ? wp_specialchars_decode( $make->name ) : ( $r['make'] ?? '' ), $payload['model'] ] ) ) );
		return [ 'payload' => $payload, 'photos' => array_slice( $photos, 0, 60 ), 'errors' => $err, 'label' => $label ?: '(no name)',
			'category' => $cat ? wp_specialchars_decode( $cat->name ) : null ];
	}

	private static function dealer_for( WP_REST_Request $req ): int {
		$d = (int) $req->get_param( 'dealer' );
		return ( $d && current_user_can( 'edit_others_lm_equipments' ) && get_user_by( 'id', $d ) ) ? $d : get_current_user_id();
	}

	private static function existing( int $author, string $stock ): int {
		if ( $stock === '' ) return 0;
		$ids = get_posts( [ 'post_type' => LM_Model::PT, 'post_status' => [ 'publish', 'draft', 'pending', 'private' ], 'author' => $author,
			'meta_key' => 'lm_stock_number', 'meta_value' => $stock, 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true ] );
		return $ids ? (int) $ids[0] : 0;
	}

	public static function start( WP_REST_Request $req ) {
		$files = $req->get_file_params();
		if ( ! empty( $files['file']['tmp_name'] ) ) {
			if ( (int) $files['file']['size'] > 5 * 1048576 ) return new WP_Error( 'too_big', 'The CSV is larger than 5 MB.', [ 'status' => 400 ] );
			$rows = self::parse_csv( (string) file_get_contents( $files['file']['tmp_name'] ) );
		} else {
			$rows = array_values( array_filter( (array) $req->get_param( 'rows' ), 'is_array' ) );
			$rows = array_map( fn( $r ) => array_combine( array_map( [ __CLASS__, 'key' ], array_keys( $r ) ), array_map( fn( $v ) => is_scalar( $v ) ? trim( (string) $v ) : ( is_array( $v ) ? implode( '|', $v ) : '' ), $r ) ), $rows );
		}
		if ( ! $rows ) return new WP_Error( 'empty', 'No rows found. Check the file has a header row and data.', [ 'status' => 400 ] );
		if ( count( $rows ) > self::MAX_ROWS ) return new WP_Error( 'too_many', 'Up to ' . self::MAX_ROWS . ' rows per import.', [ 'status' => 400 ] );

		$author = self::dealer_for( $req );
		$items = []; $seen = [];
		foreach ( $rows as $i => $r ) {
			$c = self::check( $r );
			$stock = $c['payload']['stock'];
			if ( $stock !== '' && isset( $seen[ $stock ] ) ) $c['errors'][] = 'Stock number repeated (row ' . ( $seen[ $stock ] + 2 ) . ')';
			if ( $stock !== '' ) $seen[ $stock ] = $i;
			$c['row'] = $i + 2; // spreadsheet row (header is 1)
			$c['update'] = self::existing( $author, $stock );
			$items[] = $c;
		}
		$job = strtolower( wp_generate_password( 12, false, false ) );
		set_transient( 'lm_import_' . $job, [ 'user' => get_current_user_id(), 'author' => $author, 'items' => $items, 'next' => 0, 'done' => [] ], 6 * HOUR_IN_SECONDS );
		$ok = count( array_filter( $items, fn( $x ) => ! $x['errors'] ) );
		return [
			'job' => $job, 'total' => count( $items ), 'ready' => $ok, 'with_errors' => count( $items ) - $ok,
			'updates' => count( array_filter( $items, fn( $x ) => ! $x['errors'] && $x['update'] ) ),
			'photos' => array_sum( array_map( fn( $x ) => $x['errors'] ? 0 : count( $x['photos'] ), $items ) ),
			'rows' => array_map( fn( $x ) => [ 'row' => $x['row'], 'label' => $x['label'], 'category' => $x['category'], 'stock' => $x['payload']['stock'],
				'photos' => count( $x['photos'] ), 'update' => (bool) $x['update'], 'errors' => $x['errors'] ], $items ),
		];
	}

	/* ------------------------------------------------------------------ run */

	/** Download a photo once (by source URL) and give it to the dealer. */
	private static function photo( string $url, int $author, int $post_id ): int {
		$found = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_lm_source_url', 'meta_value' => $url, 'fields' => 'ids', 'posts_per_page' => 1 ] );
		if ( $found ) return (int) $found[0];
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) return 0;
		$type = wp_get_image_mime( $tmp );
		if ( ! $type || ! str_starts_with( $type, 'image/' ) ) { @unlink( $tmp ); return 0; }
		$ext = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif' ][ $type ] ?? 'jpg';
		$name = sanitize_file_name( get_the_title( $post_id ) ?: 'equipment' ) . '.' . $ext;
		$id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $post_id, get_the_title( $post_id ) );
		if ( is_wp_error( $id ) ) { @unlink( $tmp ); return 0; }
		wp_update_post( [ 'ID' => $id, 'post_author' => $author ] );
		update_post_meta( $id, '_lm_source_url', esc_url_raw( $url ) );
		update_post_meta( $id, '_wp_attachment_image_alt', get_the_title( $post_id ) );
		return (int) $id;
	}

	public static function run( WP_REST_Request $req ) {
		$key = 'lm_import_' . $req['job'];
		$job = get_transient( $key );
		if ( ! $job || (int) $job['user'] !== get_current_user_id() ) return new WP_Error( 'not_found', 'Import not found or expired. Upload the file again.', [ 'status' => 404 ] );
		@set_time_limit( 120 );
		$limit = max( 1, min( 10, (int) ( $req->get_param( 'limit' ) ?: 3 ) ) );
		$author = (int) $job['author'];
		$results = [];
		while ( $limit-- > 0 && $job['next'] < count( $job['items'] ) ) {
			$it = $job['items'][ $job['next'] ];
			$job['next']++;
			if ( $it['errors'] ) { $results[] = [ 'row' => $it['row'], 'skipped' => true ]; continue; }
			$p = $it['payload'];
			$p['author'] = $author;
			$id = self::existing( $author, $p['stock'] ) ?: (int) $it['update'];
			$saved = LM_Panel_Api::save_for( $id, $p, $author );
			if ( is_wp_error( $saved ) ) { $results[] = [ 'row' => $it['row'], 'error' => $saved->get_error_message() ]; continue; }
			$gallery = [];
			foreach ( $it['photos'] as $u ) { $a = self::photo( $u, $author, $saved['id'] ); if ( $a ) $gallery[] = $a; }
			if ( $gallery ) {
				update_post_meta( $saved['id'], 'lm_gallery', $gallery );
				set_post_thumbnail( $saved['id'], $gallery[0] );
			}
			$results[] = [ 'row' => $it['row'], 'id' => $saved['id'], 'title' => $saved['title'], 'photos' => count( $gallery ) . '/' . count( $it['photos'] ), 'updated' => (bool) $id ];
		}
		$job['done'] = array_merge( $job['done'], $results );
		set_transient( $key, $job, 6 * HOUR_IN_SECONDS );
		$total = count( $job['items'] );
		return [ 'next' => $job['next'], 'total' => $total, 'finished' => $job['next'] >= $total, 'results' => $results,
			'imported' => count( array_filter( $job['done'], fn( $r ) => ! empty( $r['id'] ) ) ),
			'failed' => count( array_filter( $job['done'], fn( $r ) => ! empty( $r['error'] ) ) ) ];
	}
}
