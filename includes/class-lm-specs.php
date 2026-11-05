<?php
/**
 * Specs per category. Each category term may carry, in term meta `lm_specs`, a list of fields:
 *   [ { "key": "bucket_capacity", "label": "Bucket capacity", "type": "number", "unit": "yd³" },
 *     { "key": "track_type", "label": "Track type", "type": "select", "options": ["Rubber","Steel"] } ]
 * Types: number, text, select, boolean. A listing gets the fields of its categories AND their
 * ancestors (Construction's general fields + Excavators' own). Values live in post meta `lm_specs`
 * as one object; on save, unknown keys are dropped and values are cast to their type.
 */
defined( 'ABSPATH' ) || exit;

class LM_Specs {
	const TYPES = [ 'number', 'text', 'select', 'boolean' ];

	public static function init() {}

	/** Field definitions of one category term (validated shape). */
	public static function of_term( int $term_id ): array {
		$defs = get_term_meta( $term_id, 'lm_specs', true );
		return is_array( $defs ) ? array_values( array_filter( $defs, [ __CLASS__, 'valid_def' ] ) ) : [];
	}

	public static function valid_def( $d ): bool {
		return is_array( $d ) && ! empty( $d['key'] ) && preg_match( '/^[a-z0-9_]{1,40}$/', $d['key'] )
			&& ! empty( $d['label'] ) && in_array( $d['type'] ?? '', self::TYPES, true )
			&& ( $d['type'] !== 'select' || ( ! empty( $d['options'] ) && is_array( $d['options'] ) ) );
	}

	/** Fields that apply to a listing: its categories and their ancestors, top level first. Key → definition. */
	public static function for_post( int $post_id ): array {
		$terms = wp_get_object_terms( $post_id, LM_Model::CATEGORY, [ 'fields' => 'ids' ] );
		return is_wp_error( $terms ) ? [] : self::for_terms( $terms );
	}

	public static function for_terms( array $term_ids ): array {
		$chain = [];
		foreach ( $term_ids as $id ) {
			$ids = array_reverse( get_ancestors( (int) $id, LM_Model::CATEGORY, 'taxonomy' ) );
			$ids[] = (int) $id;
			foreach ( $ids as $t ) $chain[ $t ] = true;
		}
		$fields = [];
		foreach ( array_keys( $chain ) as $t ) {
			foreach ( self::of_term( $t ) as $d ) $fields[ $d['key'] ] = $d;
		}
		return $fields;
	}

	/** Cast one value to its definition, or null when it does not fit. */
	public static function cast( array $def, $value ) {
		if ( $value === '' || $value === null ) return null;
		switch ( $def['type'] ) {
			case 'number':
				if ( ! is_numeric( $value ) ) return null;
				return $value + 0;
			case 'boolean':
				return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			case 'select':
				foreach ( $def['options'] as $opt ) {
					if ( strcasecmp( (string) $opt, (string) $value ) === 0 ) return $opt;
				}
				return null;
			default:
				return mb_substr( sanitize_text_field( (string) $value ), 0, 200 );
		}
	}

	/** Keep only the fields the listing's categories define, each cast to its type. */
	public static function clean_post( int $post_id ): void {
		$raw = get_post_meta( $post_id, 'lm_specs', true );
		if ( ! is_array( $raw ) || ! $raw ) return;
		$fields = self::for_post( $post_id );
		$clean  = [];
		foreach ( $raw as $k => $v ) {
			if ( ! isset( $fields[ $k ] ) ) continue;
			$c = self::cast( $fields[ $k ], $v );
			if ( $c !== null ) $clean[ $k ] = $c;
		}
		if ( $clean !== $raw ) update_post_meta( $post_id, 'lm_specs', $clean );
	}

	/** Core fields: defaults and formats (state as 2 letters, ZIP as 5 digits). */
	public static function clean_core( int $post_id ): void {
		$set = function ( $k, $v ) use ( $post_id ) { update_post_meta( $post_id, $k, $v ); };
		$status = get_post_meta( $post_id, 'lm_status', true );
		if ( ! in_array( $status, LM_Model::STATUSES, true ) ) $set( 'lm_status', 'available' );
		$cond = get_post_meta( $post_id, 'lm_condition', true );
		if ( $cond !== '' && ! in_array( $cond, LM_Model::CONDITIONS, true ) ) $set( 'lm_condition', 'used' );
		if ( get_post_meta( $post_id, 'lm_country', true ) === '' ) $set( 'lm_country', 'US' );
		$state = strtoupper( trim( (string) get_post_meta( $post_id, 'lm_state', true ) ) );
		if ( $state !== '' ) $set( 'lm_state', preg_match( '/^[A-Z]{2}$/', $state ) ? $state : '' );
		$zip = trim( (string) get_post_meta( $post_id, 'lm_zip', true ) );
		if ( $zip !== '' ) $set( 'lm_zip', preg_match( '/^\d{5}$/', substr( $zip, 0, 5 ) ) ? substr( $zip, 0, 5 ) : '' );
		$year = (int) get_post_meta( $post_id, 'lm_year', true );
		if ( $year && ( $year < 1900 || $year > (int) gmdate( 'Y' ) + 1 ) ) delete_post_meta( $post_id, 'lm_year' );
		foreach ( [ 'lm_hours', 'lm_mileage', 'lm_price' ] as $k ) {
			$v = get_post_meta( $post_id, $k, true );
			if ( $v !== '' && ( ! is_numeric( $v ) || $v < 0 ) ) delete_post_meta( $post_id, $k );
		}
	}

	/** Model must belong to the listing's make: keep the listing's make in sync with its model. */
	public static function sync_make( int $post_id ): void {
		$models = wp_get_object_terms( $post_id, LM_Model::MODEL, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $models ) || ! $models ) return;
		$make = (int) get_term_meta( (int) $models[0], 'lm_make', true );
		if ( $make ) wp_set_object_terms( $post_id, [ $make ], LM_Model::MAKE );
	}

	public static function after_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || get_post_type( $post_id ) !== LM_Model::PT ) return;
		self::sync_make( $post_id );
		self::clean_core( $post_id );
		self::clean_post( $post_id );
	}
}

// Classic save (admin) and REST save (taxonomies and meta are set after the post insert there).
add_action( 'save_post_' . LM_Model::PT, function ( $post_id ) { LM_Specs::after_save( (int) $post_id ); }, 30 );
add_action( 'rest_after_insert_' . LM_Model::PT, function ( $post ) { LM_Specs::after_save( (int) $post->ID ); }, 30 );
