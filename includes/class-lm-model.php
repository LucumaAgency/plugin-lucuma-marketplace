<?php
/**
 * Data model: equipment post type, taxonomies, meta and the dealer role.
 *
 *   lm_equipment  one listing (author = the dealer user who owns it)
 *   lm_category   hierarchical (Construction > Excavators); top terms carry the spec definitions
 *   lm_make       Caterpillar, John Deere…
 *   lm_model      "320" with term meta lm_make = make term id (slug "caterpillar-320", unique)
 */
defined( 'ABSPATH' ) || exit;

class LM_Model {
	const PT       = 'lm_equipment';
	const CATEGORY = 'lm_category';
	const MAKE     = 'lm_make';
	const MODEL    = 'lm_model';
	const ROLE     = 'lm_dealer';

	const CONDITIONS = [ 'new', 'used', 'refurbished' ];
	const STATUSES   = [ 'available', 'pending', 'sold' ];

	/** Meta shown in REST (public). The serial number is private: see LM_Rest::private_fields(). */
	const META = [
		'lm_year'           => [ 'type' => 'integer' ],
		'lm_hours'          => [ 'type' => 'integer' ],
		'lm_mileage'        => [ 'type' => 'integer' ],
		'lm_price'          => [ 'type' => 'number' ],
		'lm_call_for_price' => [ 'type' => 'boolean' ],
		'lm_condition'      => [ 'type' => 'string', 'enum' => self::CONDITIONS ],
		'lm_status'         => [ 'type' => 'string', 'enum' => self::STATUSES ],
		'lm_stock_number'   => [ 'type' => 'string' ],
		'lm_city'           => [ 'type' => 'string' ],
		'lm_state'          => [ 'type' => 'string' ],
		'lm_zip'            => [ 'type' => 'string' ],
		'lm_country'        => [ 'type' => 'string' ],
		'lm_video_url'      => [ 'type' => 'string', 'format' => 'uri' ],
		'lm_source_url'     => [ 'type' => 'string', 'format' => 'uri' ], // original listing on the dealer's own site (aggregated units)
	];

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'init', [ __CLASS__, 'maybe_upgrade' ], 20 );
	}

	/** After an update by Deployer for Git there is no activation hook: refresh roles and rewrites once per version. */
	public static function maybe_upgrade() {
		if ( get_option( 'lm_version' ) === LM_VERSION ) return;
		self::add_roles();
		LM_Panel::ensure_page();
		flush_rewrite_rules();
		update_option( 'lm_version', LM_VERSION );
	}

	public static function register() {
		register_post_type( self::PT, [
			'labels'          => [
				'name'          => 'Equipment',
				'singular_name' => 'Equipment',
				'add_new_item'  => 'Add equipment',
				'edit_item'     => 'Edit equipment',
				'all_items'     => 'All equipment',
				'search_items'  => 'Search equipment',
				'not_found'     => 'No equipment found',
				'menu_name'     => 'Equipment',
			],
			'public'          => true,
			'has_archive'     => 'equipment',
			'rewrite'         => [ 'slug' => 'equipment', 'with_front' => false ],
			'menu_icon'       => 'dashicons-hammer',
			'menu_position'   => 5,
			'supports'        => [ 'title', 'editor', 'thumbnail', 'author', 'custom-fields' ],
			'show_in_rest'    => true,
			'rest_base'       => 'equipment',
			'capability_type' => [ 'lm_equipment', 'lm_equipments' ],
			'map_meta_cap'    => true,
		] );

		$caps = [
			'manage_terms' => 'manage_options',
			'edit_terms'   => 'manage_options',
			'delete_terms' => 'manage_options',
			'assign_terms' => 'edit_lm_equipments',
		];
		register_taxonomy( self::CATEGORY, self::PT, [
			'labels'            => [ 'name' => 'Categories', 'singular_name' => 'Category', 'menu_name' => 'Categories' ],
			'hierarchical'      => true,
			'public'            => true,
			'show_in_rest'      => true,
			'rest_base'         => 'equipment-categories',
			'show_admin_column' => true,
			'rewrite'           => [ 'slug' => 'for-sale', 'with_front' => false, 'hierarchical' => true ],
			'capabilities'      => $caps,
		] );
		register_taxonomy( self::MAKE, self::PT, [
			'labels'            => [ 'name' => 'Makes', 'singular_name' => 'Make' ],
			'hierarchical'      => false,
			'public'            => true,
			'show_in_rest'      => true,
			'rest_base'         => 'equipment-makes',
			'show_admin_column' => true,
			'rewrite'           => [ 'slug' => 'make', 'with_front' => false ],
			'capabilities'      => $caps,
		] );
		register_taxonomy( self::MODEL, self::PT, [
			'labels'            => [ 'name' => 'Models', 'singular_name' => 'Model' ],
			'hierarchical'      => false,
			'public'            => true,
			'show_in_rest'      => true,
			'rest_base'         => 'equipment-models',
			'show_admin_column' => true,
			'rewrite'           => [ 'slug' => 'model', 'with_front' => false ],
			// Dealers may create a model that is missing (via LM_Rest::model), never edit the catalog by hand.
			'capabilities'      => $caps,
		] );

		$auth = function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};
		foreach ( self::META as $key => $schema ) {
			register_post_meta( self::PT, $key, [
				'type'          => $schema['type'],
				'single'        => true,
				'show_in_rest'  => [ 'schema' => $schema ],
				'auth_callback' => $auth,
			] );
		}
		register_post_meta( self::PT, 'lm_gallery', [
			'type'          => 'array',
			'single'        => true,
			'show_in_rest'  => [ 'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ] ],
			'auth_callback' => $auth,
		] );
		register_post_meta( self::PT, 'lm_specs', [
			'type'          => 'object',
			'single'        => true,
			'show_in_rest'  => [ 'schema' => [ 'type' => 'object', 'additionalProperties' => [ 'type' => [ 'string', 'number', 'boolean' ] ] ] ],
			'auth_callback' => $auth,
		] );
		// Private: never in REST through register_post_meta (it would show to everyone on GET).
		register_post_meta( self::PT, 'lm_serial', [ 'type' => 'string', 'single' => true, 'show_in_rest' => false, 'auth_callback' => $auth ] );
		register_term_meta( self::MODEL, 'lm_make', [ 'type' => 'integer', 'single' => true, 'show_in_rest' => true ] );
		register_term_meta( self::CATEGORY, 'lm_specs', [ 'type' => 'array', 'single' => true, 'show_in_rest' => false ] );
	}

	/** Dealer role: own listings only (map_meta_cap does the "own" part). */
	public static function add_roles() {
		$own = [
			'read'                          => true,
			'upload_files'                  => true,
			'edit_lm_equipments'            => true,
			'edit_published_lm_equipments'  => true,
			'publish_lm_equipments'         => true,
			'delete_lm_equipments'          => true,
			'delete_published_lm_equipments' => true,
		];
		remove_role( self::ROLE );
		add_role( self::ROLE, 'Dealer', $own );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_merge( array_keys( $own ), [
				'edit_others_lm_equipments', 'delete_others_lm_equipments', 'read_private_lm_equipments',
				'edit_private_lm_equipments', 'delete_private_lm_equipments',
			] ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}
}
