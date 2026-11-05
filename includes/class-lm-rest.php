<?php
/**
 * REST (namespace lucuma-mkt/v1) and the private serial field on /wp/v2/equipment.
 *
 *   GET  /catalog   public: category tree with its specs, and makes
 *   POST /seed      admin: load data/catalogo.json (idempotent: by slug, specs are overwritten)
 *   POST /models    dealers and admins: find or create a model under an existing make
 */
defined( 'ABSPATH' ) || exit;

class LM_Rest {
	const NS = 'lucuma-mkt/v1';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'private_fields' ] );
	}

	public static function routes() {
		register_rest_route( self::NS, '/catalog', [
			'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => [ __CLASS__, 'catalog' ],
		] );
		register_rest_route( self::NS, '/seed', [
			'methods' => 'POST', 'permission_callback' => fn() => current_user_can( 'manage_options' ), 'callback' => [ __CLASS__, 'seed' ],
		] );
		register_rest_route( self::NS, '/models', [
			'methods' => 'POST', 'permission_callback' => fn() => current_user_can( 'edit_lm_equipments' ), 'callback' => [ __CLASS__, 'model' ],
		] );
	}

	/** Serial / VIN: only whoever can edit the listing reads or writes it. */
	public static function private_fields() {
		register_rest_field( LM_Model::PT, 'lm_serial', [
			'get_callback'    => function ( $p ) {
				return current_user_can( 'edit_post', $p['id'] ) ? (string) get_post_meta( $p['id'], 'lm_serial', true ) : null;
			},
			'update_callback' => function ( $value, $post ) {
				if ( ! current_user_can( 'edit_post', $post->ID ) ) return new WP_Error( 'forbidden', 'Not allowed.', [ 'status' => 403 ] );
				update_post_meta( $post->ID, 'lm_serial', mb_substr( sanitize_text_field( (string) $value ), 0, 64 ) );
				return true;
			},
			'schema'          => [ 'type' => [ 'string', 'null' ], 'context' => [ 'edit' ] ],
		] );
	}

	private static function tree( int $parent = 0 ): array {
		$out = [];
		foreach ( get_terms( [ 'taxonomy' => LM_Model::CATEGORY, 'parent' => $parent, 'hide_empty' => false, 'orderby' => 'name' ] ) as $t ) {
			$out[] = [
				'id'       => $t->term_id,
				'name'     => $t->name,
				'slug'     => $t->slug,
				'count'    => $t->count,
				'specs'    => LM_Specs::of_term( $t->term_id ),
				'children' => self::tree( $t->term_id ),
			];
		}
		return $out;
	}

	public static function catalog() {
		$makes = array_map(
			fn( $t ) => [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count ],
			get_terms( [ 'taxonomy' => LM_Model::MAKE, 'hide_empty' => false, 'orderby' => 'name' ] )
		);
		return [ 'categories' => self::tree(), 'makes' => $makes ];
	}

	/** Find a term by slug under a parent, or create it. Returns [term_id, created?]. */
	private static function upsert( string $tax, string $name, string $slug, int $parent = 0 ): array {
		$found = get_terms( [ 'taxonomy' => $tax, 'slug' => $slug, 'hide_empty' => false, 'parent' => $parent ] );
		if ( ! is_wp_error( $found ) && $found ) {
			if ( $found[0]->name !== $name ) wp_update_term( $found[0]->term_id, $tax, [ 'name' => $name ] );
			return [ (int) $found[0]->term_id, false ];
		}
		$r = wp_insert_term( $name, $tax, [ 'slug' => $slug, 'parent' => $parent ] );
		if ( is_wp_error( $r ) ) {
			// Slug taken elsewhere in the tree: reuse that term only if it has the same parent.
			throw new RuntimeException( "$tax «$name»: " . $r->get_error_message() );
		}
		return [ (int) $r['term_id'], true ];
	}

	private static function seed_categories( array $items, int $parent, array &$n ) {
		foreach ( $items as $c ) {
			if ( empty( $c['name'] ) ) continue;
			[ $id, $new ] = self::upsert( LM_Model::CATEGORY, $c['name'], $c['slug'] ?? sanitize_title( $c['name'] ), $parent );
			$n[ $new ? 'categories_created' : 'categories_kept' ]++;
			$specs = array_values( array_filter( $c['specs'] ?? [], [ 'LM_Specs', 'valid_def' ] ) );
			update_term_meta( $id, 'lm_specs', $specs );
			$n['spec_fields'] += count( $specs );
			if ( ! empty( $c['children'] ) ) self::seed_categories( $c['children'], $id, $n );
		}
	}

	public static function seed() {
		$file = LM_DIR . '/data/catalogo.json';
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) ) return new WP_Error( 'bad_catalog', 'data/catalogo.json is not valid JSON.', [ 'status' => 500 ] );
		$n = [ 'categories_created' => 0, 'categories_kept' => 0, 'spec_fields' => 0, 'makes_created' => 0, 'makes_kept' => 0 ];
		try {
			self::seed_categories( $data['categories'] ?? [], 0, $n );
			foreach ( $data['makes'] ?? [] as $m ) {
				[ , $new ] = self::upsert( LM_Model::MAKE, $m, sanitize_title( $m ) );
				$n[ $new ? 'makes_created' : 'makes_kept' ]++;
			}
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'seed_failed', $e->getMessage(), [ 'status' => 409, 'progress' => $n ] );
		}
		return $n;
	}

	/** { make: "Caterpillar" | id, model: "320" } → the model term (created if missing). Makes are not created here. */
	public static function model( WP_REST_Request $req ) {
		$make  = $req->get_param( 'make' );
		$model = trim( sanitize_text_field( (string) $req->get_param( 'model' ) ) );
		if ( ! $make || $model === '' ) return new WP_Error( 'missing', 'make and model are required.', [ 'status' => 400 ] );
		$term = is_numeric( $make ) ? get_term( (int) $make, LM_Model::MAKE ) : ( get_term_by( 'slug', sanitize_title( $make ), LM_Model::MAKE ) ?: get_term_by( 'name', $make, LM_Model::MAKE ) );
		if ( ! $term || is_wp_error( $term ) ) return new WP_Error( 'unknown_make', "Make «{$make}» is not in the catalog.", [ 'status' => 404 ] );
		$slug = sanitize_title( $term->slug . '-' . $model );
		try {
			[ $id, $new ] = self::upsert( LM_Model::MODEL, $model, $slug );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'model_failed', $e->getMessage(), [ 'status' => 409 ] );
		}
		update_term_meta( $id, 'lm_make', $term->term_id );
		return [ 'id' => $id, 'name' => $model, 'slug' => $slug, 'make' => [ 'id' => $term->term_id, 'name' => $term->name ], 'created' => $new ];
	}
}
