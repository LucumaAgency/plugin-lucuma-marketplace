<?php
/**
 * REST for the dealer panel (lucuma-mkt/v1). Cookie auth + X-WP-Nonce from the panel page; the
 * same routes work with an application password (admins can act on any listing).
 *
 *   GET    /my/equipment               own listings (admins: all), compact
 *   POST   /my/equipment               create (publishes unless draft:true — decision 2a)
 *   GET    /my/equipment/{id}          one listing, full, for the form
 *   POST   /my/equipment/{id}          update
 *   POST   /my/equipment/{id}/status   { status: available|pending|sold }
 *   DELETE /my/equipment/{id}          to the trash
 *   GET    /models?make=ID&q=…         models of a make (autocomplete)
 *   GET    /my/profile | POST /my/profile   dealer company card
 *   POST   /dealers                    admin: invite a dealer → set-password link (decision 1a)
 */
defined( 'ABSPATH' ) || exit;

class LM_Panel_Api {
	const NS = 'lucuma-mkt/v1';

	const PROFILE = [
		'lm_company' => 'company', 'lm_phone' => 'phone', 'lm_website' => 'website', 'lm_address' => 'address',
		'lm_city' => 'city', 'lm_state' => 'state', 'lm_zip' => 'zip', 'lm_opening_hours' => 'hours', 'lm_gbp' => 'gbp',
	];

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes() {
		$dealer = fn() => current_user_can( 'edit_lm_equipments' );
		$admin  = fn() => current_user_can( 'manage_options' );
		register_rest_route( self::NS, '/my/equipment', [
			[ 'methods' => 'GET', 'permission_callback' => $dealer, 'callback' => [ __CLASS__, 'list' ] ],
			[ 'methods' => 'POST', 'permission_callback' => $dealer, 'callback' => [ __CLASS__, 'create' ] ],
		] );
		register_rest_route( self::NS, '/my/equipment/(?P<id>\d+)', [
			[ 'methods' => 'GET', 'permission_callback' => $dealer, 'callback' => [ __CLASS__, 'get' ] ],
			[ 'methods' => 'POST', 'permission_callback' => $dealer, 'callback' => [ __CLASS__, 'update' ] ],
			[ 'methods' => 'DELETE', 'permission_callback' => $dealer, 'callback' => [ __CLASS__, 'trash' ] ],
		] );
		register_rest_route( self::NS, '/my/equipment/(?P<id>\d+)/status', [
			'methods' => 'POST', 'permission_callback' => $dealer, 'callback' => [ __CLASS__, 'status' ],
		] );
		register_rest_route( self::NS, '/models', [
			'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => [ __CLASS__, 'models' ],
		] );
		register_rest_route( self::NS, '/my/profile', [
			[ 'methods' => 'GET', 'permission_callback' => $dealer, 'callback' => [ __CLASS__, 'profile' ] ],
			[ 'methods' => 'POST', 'permission_callback' => $dealer, 'callback' => [ __CLASS__, 'save_profile' ] ],
		] );
		register_rest_route( self::NS, '/dealers', [
			[ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'invite' ] ],
			[ 'methods' => 'GET', 'permission_callback' => fn() => current_user_can( 'edit_others_lm_equipments' ), 'callback' => [ __CLASS__, 'dealers' ] ],
		] );
	}

	/* ------------------------------------------------------------------ helpers */

	private static function own( int $id ) {
		$p = get_post( $id );
		if ( ! $p || $p->post_type !== LM_Model::PT || $p->post_status === 'trash' ) return new WP_Error( 'not_found', 'Not found.', [ 'status' => 404 ] );
		if ( ! current_user_can( 'edit_post', $id ) ) return new WP_Error( 'not_found', 'Not found.', [ 'status' => 404 ] );
		return $p;
	}

	private static function term_of( int $id, string $tax ) {
		$t = wp_get_object_terms( $id, $tax );
		return ( ! is_wp_error( $t ) && $t ) ? [ 'id' => $t[0]->term_id, 'name' => wp_specialchars_decode( $t[0]->name ) ] : null;
	}

	private static function photo( int $att ) {
		$src = wp_get_attachment_image_src( $att, 'medium' );
		return $src ? [ 'id' => $att, 'url' => $src[0] ] : null;
	}

	private static function compact( WP_Post $p ): array {
		$m = fn( $k ) => get_post_meta( $p->ID, $k, true );
		$thumb = get_post_thumbnail_id( $p->ID );
		return [
			'id'          => $p->ID,
			'title'       => get_the_title( $p ),
			'published'   => $p->post_status === 'publish',
			'post_status' => $p->post_status,
			'status'      => $m( 'lm_status' ) ?: 'available',
			'price'       => $m( 'lm_price' ) === '' ? null : (float) $m( 'lm_price' ),
			'call'        => (bool) $m( 'lm_call_for_price' ),
			'year'        => $m( 'lm_year' ) ?: null,
			'stock'       => $m( 'lm_stock_number' ),
			'category'    => self::term_of( $p->ID, LM_Model::CATEGORY ),
			'thumb'       => $thumb ? ( wp_get_attachment_image_src( $thumb, 'thumbnail' )[0] ?? null ) : null,
			'link'        => get_permalink( $p ),
			'updated'     => get_post_modified_time( 'c', true, $p ),
			'dealer'      => current_user_can( 'edit_others_lm_equipments' ) ? ( get_the_author_meta( 'lm_company', $p->post_author ) ?: get_the_author_meta( 'display_name', $p->post_author ) ) : null,
		];
	}

	/* ------------------------------------------------------------------ listings */

	public static function list( WP_REST_Request $req ) {
		$args = [
			'post_type'      => LM_Model::PT,
			'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page' => 200,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];
		if ( ! current_user_can( 'edit_others_lm_equipments' ) ) $args['author'] = get_current_user_id();
		$q = (string) $req->get_param( 'q' );
		if ( $q !== '' ) $args['s'] = $q;
		return array_map( [ __CLASS__, 'compact' ], get_posts( $args ) );
	}

	public static function get( WP_REST_Request $req ) {
		$p = self::own( (int) $req['id'] );
		if ( is_wp_error( $p ) ) return $p;
		$m = fn( $k ) => get_post_meta( $p->ID, $k, true );
		$gallery = array_values( array_filter( array_map( [ __CLASS__, 'photo' ], array_map( 'intval', (array) ( $m( 'lm_gallery' ) ?: [] ) ) ) ) );
		return self::compact( $p ) + [
			'description' => $p->post_content,
			'make'        => self::term_of( $p->ID, LM_Model::MAKE ),
			'model'       => self::term_of( $p->ID, LM_Model::MODEL ),
			'hours'       => $m( 'lm_hours' ) ?: null,
			'mileage'     => $m( 'lm_mileage' ) ?: null,
			'condition'   => $m( 'lm_condition' ),
			'serial'      => $m( 'lm_serial' ),
			'city'        => $m( 'lm_city' ),
			'state'       => $m( 'lm_state' ),
			'zip'         => $m( 'lm_zip' ),
			'video_url'   => $m( 'lm_video_url' ),
			'specs'       => (object) ( $m( 'lm_specs' ) ?: [] ),
			'gallery'     => $gallery,
		];
	}

	public static function create( WP_REST_Request $req ) {
		return self::save( 0, (array) $req->get_json_params() );
	}

	public static function update( WP_REST_Request $req ) {
		$p = self::own( (int) $req['id'] );
		if ( is_wp_error( $p ) ) return $p;
		return self::save( $p->ID, (array) $req->get_json_params() );
	}

	/** "Wheel Loaders" → "Wheel Loader" for auto titles ("Compactors & Rollers" stays as it is). */
	public static function singular( string $name ): string {
		if ( str_contains( $name, '&' ) ) return $name;
		if ( preg_match( '/ies$/', $name ) ) return substr( $name, 0, -3 ) . 'y';
		if ( preg_match( '/(ch|sh|x)es$/', $name ) ) return substr( $name, 0, -2 );
		if ( preg_match( '/[^s]s$/', $name ) ) return substr( $name, 0, -1 );
		return $name;
	}

	/** Same as the panel save, for the importer: new listings belong to $author (admins import for a dealer). */
	public static function save_for( int $id, array $b, int $author ) {
		if ( $id && ! current_user_can( 'edit_post', $id ) ) return new WP_Error( 'not_found', 'Not found.', [ 'status' => 404 ] );
		return self::save( $id, $b, $author );
	}

	/** Create or update from the panel form. Validation of specs and formats: LM_Specs::after_save. */
	private static function save( int $id, array $b, int $author = 0 ) {
		$cat = LM_Rest::find_term( LM_Model::CATEGORY, (string) ( $b['category'] ?? '' ) );
		if ( ! $cat ) return new WP_Error( 'category', 'Pick a category.', [ 'status' => 400 ] );
		$make = LM_Rest::find_term( LM_Model::MAKE, (string) ( $b['make'] ?? '' ) );
		if ( ! $make ) return new WP_Error( 'make', 'Pick a make from the list.', [ 'status' => 400 ] );
		$model_name = trim( sanitize_text_field( (string) ( $b['model'] ?? '' ) ) );
		if ( $model_name === '' ) return new WP_Error( 'model', 'Type the model.', [ 'status' => 400 ] );

		$year  = isset( $b['year'] ) && $b['year'] !== '' ? (int) $b['year'] : null;
		$title = trim( sanitize_text_field( (string) ( $b['title'] ?? '' ) ) );
		if ( $title === '' ) $title = trim( ( $year ?: '' ) . ' ' . wp_specialchars_decode( $make->name ) . ' ' . $model_name . ' ' . self::singular( wp_specialchars_decode( $cat->name ) ) );

		$data = [
			'post_type'    => LM_Model::PT,
			'post_title'   => mb_substr( $title, 0, 200 ),
			'post_content' => wp_kses_post( (string) ( $b['description'] ?? '' ) ),
			'post_status'  => ! empty( $b['draft'] ) ? 'draft' : 'publish',
		];
		if ( $id ) {
			$data['ID'] = $id;
		} else {
			$data['post_author'] = ( $author && current_user_can( 'edit_others_lm_equipments' ) ) ? $author : get_current_user_id();
			if ( ! current_user_can( 'publish_lm_equipments' ) ) $data['post_status'] = 'draft';
		}
		// Update with wp_update_post: it keeps the author and dates (wp_insert_post would make the
		// current user the author when an admin or the importer edits a dealer's listing).
		$id = $id ? wp_update_post( wp_slash( $data ), true ) : wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $id ) ) return $id;

		// Model under its make (created if missing), then the terms.
		$r = new WP_REST_Request( 'POST' );
		$r->set_param( 'make', (string) $make->term_id );
		$r->set_param( 'model', $model_name );
		$model = LM_Rest::model( $r );
		if ( is_wp_error( $model ) ) return $model;
		wp_set_object_terms( $id, [ $cat->term_id ], LM_Model::CATEGORY );
		wp_set_object_terms( $id, [ (int) $model['id'] ], LM_Model::MODEL );
		wp_set_object_terms( $id, [ $make->term_id ], LM_Model::MAKE );

		$map = [
			'year' => 'lm_year', 'hours' => 'lm_hours', 'mileage' => 'lm_mileage', 'price' => 'lm_price',
			'condition' => 'lm_condition', 'status' => 'lm_status', 'stock' => 'lm_stock_number', 'serial' => 'lm_serial',
			'city' => 'lm_city', 'state' => 'lm_state', 'zip' => 'lm_zip', 'video_url' => 'lm_video_url', 'source_url' => 'lm_source_url',
		];
		foreach ( $map as $in => $key ) {
			if ( ! array_key_exists( $in, $b ) ) continue;
			$v = is_string( $b[ $in ] ) ? trim( sanitize_text_field( $b[ $in ] ) ) : $b[ $in ];
			if ( $in === 'video_url' || $in === 'source_url' ) $v = esc_url_raw( (string) $v );
			if ( $v === '' || $v === null ) delete_post_meta( $id, $key );
			else update_post_meta( $id, $key, in_array( $in, [ 'year', 'hours', 'mileage', 'price' ], true ) ? ( is_numeric( $v ) ? $v + 0 : '' ) : $v );
		}
		update_post_meta( $id, 'lm_call_for_price', ! empty( $b['call'] ) );
		if ( isset( $b['specs'] ) && is_array( $b['specs'] ) ) update_post_meta( $id, 'lm_specs', $b['specs'] );

		// Photos: only attachments this user may edit; first = cover; attach them to the listing.
		if ( isset( $b['gallery'] ) && is_array( $b['gallery'] ) ) {
			$ids = [];
			foreach ( array_map( 'intval', $b['gallery'] ) as $att ) {
				if ( get_post_type( $att ) === 'attachment' && current_user_can( 'edit_post', $att ) && ! in_array( $att, $ids, true ) ) $ids[] = $att;
			}
			update_post_meta( $id, 'lm_gallery', array_slice( $ids, 0, 60 ) );
			if ( $ids ) set_post_thumbnail( $id, $ids[0] ); else delete_post_thumbnail( $id );
			foreach ( $ids as $att ) {
				if ( ! wp_get_post_parent_id( $att ) ) wp_update_post( [ 'ID' => $att, 'post_parent' => $id ] );
			}
		}

		LM_Specs::after_save( $id );
		$req = new WP_REST_Request( 'GET' );
		$req->set_param( 'id', $id );
		return self::get( $req );
	}

	public static function status( WP_REST_Request $req ) {
		$p = self::own( (int) $req['id'] );
		if ( is_wp_error( $p ) ) return $p;
		$s = (string) $req->get_param( 'status' );
		if ( ! in_array( $s, LM_Model::STATUSES, true ) ) return new WP_Error( 'status', 'Unknown status.', [ 'status' => 400 ] );
		update_post_meta( $p->ID, 'lm_status', $s );
		return self::compact( get_post( $p->ID ) );
	}

	public static function trash( WP_REST_Request $req ) {
		$p = self::own( (int) $req['id'] );
		if ( is_wp_error( $p ) ) return $p;
		if ( ! current_user_can( 'delete_post', $p->ID ) ) return new WP_Error( 'forbidden', 'Not allowed.', [ 'status' => 403 ] );
		wp_trash_post( $p->ID );
		return [ 'trashed' => true, 'id' => $p->ID ];
	}

	public static function models( WP_REST_Request $req ) {
		$make = LM_Rest::find_term( LM_Model::MAKE, (string) $req->get_param( 'make' ) );
		if ( ! $make ) return [];
		$args = [
			'taxonomy'   => LM_Model::MODEL,
			'hide_empty' => false,
			'number'     => 50,
			'orderby'    => 'name',
			'meta_query' => [ [ 'key' => 'lm_make', 'value' => $make->term_id ] ],
		];
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( $q !== '' ) $args['name__like'] = $q;
		return array_map( fn( $t ) => [ 'id' => $t->term_id, 'name' => wp_specialchars_decode( $t->name ) ], get_terms( $args ) );
	}

	/* ------------------------------------------------------------------ dealers */

	public static function profile() {
		$u = wp_get_current_user();
		$out = [ 'name' => $u->display_name, 'email' => $u->user_email ];
		foreach ( self::PROFILE as $meta => $k ) $out[ $k ] = (string) get_user_meta( $u->ID, $meta, true );
		$logo = (int) get_user_meta( $u->ID, 'lm_logo', true );
		$out['logo'] = $logo ? self::photo( $logo ) : null;
		return $out;
	}

	public static function save_profile( WP_REST_Request $req ) {
		$b  = (array) $req->get_json_params();
		$id = get_current_user_id();
		foreach ( self::PROFILE as $meta => $k ) {
			if ( ! array_key_exists( $k, $b ) ) continue;
			$v = trim( sanitize_text_field( (string) $b[ $k ] ) );
			if ( $k === 'website' || $k === 'gbp' ) $v = esc_url_raw( $v );
			if ( $k === 'state' ) $v = preg_match( '/^[A-Za-z]{2}$/', $v ) ? strtoupper( $v ) : '';
			update_user_meta( $id, $meta, mb_substr( $v, 0, 200 ) );
		}
		if ( array_key_exists( 'logo', $b ) ) {
			$att = (int) $b['logo'];
			if ( $att && get_post_type( $att ) === 'attachment' && current_user_can( 'edit_post', $att ) ) update_user_meta( $id, 'lm_logo', $att );
			elseif ( ! $att ) delete_user_meta( $id, 'lm_logo' );
		}
		if ( ! empty( $b['name'] ) ) wp_update_user( [ 'ID' => $id, 'display_name' => sanitize_text_field( (string) $b['name'] ) ] );
		return self::profile();
	}

	/** Admins: dealers to import for. */
	public static function dealers() {
		return array_map( fn( $u ) => [ 'id' => $u->ID, 'company' => get_user_meta( $u->ID, 'lm_company', true ) ?: $u->display_name ],
			get_users( [ 'role' => LM_Model::ROLE, 'orderby' => 'display_name' ] ) );
	}

	/** Decision 1a: Lucuma invites. Returns the set-password link (email delivery is not assumed). */
	public static function invite( WP_REST_Request $req ) {
		$email   = sanitize_email( (string) $req->get_param( 'email' ) );
		$company = trim( sanitize_text_field( (string) $req->get_param( 'company' ) ) );
		$name    = trim( sanitize_text_field( (string) $req->get_param( 'name' ) ) );
		if ( ! is_email( $email ) || $company === '' ) return new WP_Error( 'missing', 'email and company are required.', [ 'status' => 400 ] );
		$user = get_user_by( 'email', $email );
		$new  = false;
		if ( ! $user ) {
			$login = sanitize_user( strtolower( strtok( $email, '@' ) ), true );
			while ( username_exists( $login ) ) $login .= wp_rand( 1, 9 );
			$uid = wp_insert_user( [
				'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password( 32 ),
				'display_name' => $name ?: $company, 'role' => LM_Model::ROLE,
			] );
			if ( is_wp_error( $uid ) ) return $uid;
			$user = get_user_by( 'id', $uid );
			$new  = true;
		} elseif ( ! in_array( LM_Model::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'manage_options' ) ) {
			$user->add_role( LM_Model::ROLE );
		}
		update_user_meta( $user->ID, 'lm_company', $company );
		foreach ( [ 'phone' => 'lm_phone', 'city' => 'lm_city', 'state' => 'lm_state' ] as $p => $meta ) {
			if ( $req->get_param( $p ) ) update_user_meta( $user->ID, $meta, sanitize_text_field( (string) $req->get_param( $p ) ) );
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) return $key;
		return [
			'id'      => $user->ID,
			'login'   => $user->user_login,
			'created' => $new,
			'link'    => LM_Panel::reset_url( $user, $key ),
			'panel'   => LM_Panel::url(),
		];
	}
}
