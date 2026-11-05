<?php
/**
 * wp-admin for Lucuma admins: a details box on each listing (core fields + specs of its
 * categories) and useful list columns. Dealers get their own front-end panel later (phase 1, step 3).
 */
defined( 'ABSPATH' ) || exit;

class LM_Admin {
	const NONCE = 'lm_details';

	const CORE = [
		'lm_year'         => [ 'Year', 'number' ],
		'lm_hours'        => [ 'Hours', 'number' ],
		'lm_mileage'      => [ 'Mileage (mi)', 'number' ],
		'lm_price'        => [ 'Price (USD)', 'number' ],
		'lm_condition'    => [ 'Condition', 'select', LM_Model::CONDITIONS ],
		'lm_status'       => [ 'Status', 'select', LM_Model::STATUSES ],
		'lm_stock_number' => [ 'Stock number', 'text' ],
		'lm_serial'       => [ 'Serial / VIN (private)', 'text' ],
		'lm_city'         => [ 'City', 'text' ],
		'lm_state'        => [ 'State (2 letters)', 'text' ],
		'lm_zip'          => [ 'ZIP', 'text' ],
		'lm_video_url'    => [ 'Video URL', 'url' ],
	];

	public static function init() {
		add_action( 'add_meta_boxes_' . LM_Model::PT, function () {
			add_meta_box( 'lm_details', 'Equipment details', [ __CLASS__, 'box' ], LM_Model::PT, 'normal', 'high' );
		} );
		add_action( 'save_post_' . LM_Model::PT, [ __CLASS__, 'save' ], 10, 2 );
		add_filter( 'manage_' . LM_Model::PT . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . LM_Model::PT . '_posts_custom_column', [ __CLASS__, 'column' ], 10, 2 );
	}

	private static function field( string $key, string $label, string $type, $value, array $options = [] ) {
		$id = esc_attr( $key );
		echo '<p><label for="' . $id . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
		if ( $type === 'select' ) {
			echo '<select name="' . $id . '" id="' . $id . '"><option value="">—</option>';
			foreach ( $options as $o ) echo '<option value="' . esc_attr( $o ) . '"' . selected( (string) $value, (string) $o, false ) . '>' . esc_html( ucfirst( $o ) ) . '</option>';
			echo '</select>';
		} elseif ( $type === 'boolean' ) {
			echo '<input type="checkbox" name="' . $id . '" id="' . $id . '" value="1"' . checked( (bool) $value, true, false ) . '>';
		} else {
			$t = $type === 'number' ? 'number" step="any' : ( $type === 'url' ? 'url' : 'text' );
			echo '<input type="' . $t . '" class="widefat" name="' . $id . '" id="' . $id . '" value="' . esc_attr( (string) $value ) . '">';
		}
		echo '</p>';
	}

	public static function box( WP_Post $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );
		echo '<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0 16px">';
		foreach ( self::CORE as $k => $f ) self::field( $k, $f[0], $f[1], get_post_meta( $post->ID, $k, true ), $f[2] ?? [] );
		self::field( 'lm_call_for_price', 'Call for price', 'boolean', get_post_meta( $post->ID, 'lm_call_for_price', true ) );
		echo '</div>';

		$fields = LM_Specs::for_post( $post->ID );
		$specs  = (array) get_post_meta( $post->ID, 'lm_specs', true );
		echo '<h4 style="margin:12px 0 4px">Specs</h4>';
		if ( ! $fields ) {
			echo '<p class="description">Pick a category and save: its specs will show here.</p>';
			return;
		}
		echo '<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0 16px">';
		foreach ( $fields as $k => $d ) {
			$label = $d['label'] . ( ! empty( $d['unit'] ) ? ' (' . $d['unit'] . ')' : '' );
			self::field( 'lm_spec_' . $k, $label, $d['type'] === 'number' ? 'number' : $d['type'], $specs[ $k ] ?? '', $d['options'] ?? [] );
		}
		echo '</div><p class="description">Changed the category? Save to refresh this list.</p>';
	}

	public static function save( int $post_id, WP_Post $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) return;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		foreach ( self::CORE as $k => $f ) {
			$v = isset( $_POST[ $k ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ) : '';
			if ( $f[1] === 'url' ) $v = esc_url_raw( $v );
			if ( $v === '' ) delete_post_meta( $post_id, $k );
			else update_post_meta( $post_id, $k, $f[1] === 'number' ? $v + 0 : $v );
		}
		update_post_meta( $post_id, 'lm_call_for_price', ! empty( $_POST['lm_call_for_price'] ) );

		$specs = [];
		foreach ( LM_Specs::for_post( $post_id ) as $k => $d ) {
			$name = 'lm_spec_' . $k;
			if ( $d['type'] === 'boolean' ) { $specs[ $k ] = ! empty( $_POST[ $name ] ); continue; }
			if ( isset( $_POST[ $name ] ) && $_POST[ $name ] !== '' ) $specs[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $name ] ) );
		}
		update_post_meta( $post_id, 'lm_specs', $specs );
		// LM_Specs::after_save (priority 30) casts and validates everything.
	}

	public static function columns( array $cols ): array {
		$new = [];
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( $k === 'title' ) {
				$new['lm_year']   = 'Year';
				$new['lm_price']  = 'Price';
				$new['lm_status'] = 'Status';
			}
		}
		return $new;
	}

	public static function column( string $col, int $post_id ) {
		if ( $col === 'lm_price' ) {
			$p = get_post_meta( $post_id, 'lm_price', true );
			echo get_post_meta( $post_id, 'lm_call_for_price', true ) ? 'Call' : ( $p !== '' ? '$' . esc_html( number_format( (float) $p ) ) : '—' );
		} elseif ( in_array( $col, [ 'lm_year', 'lm_status' ], true ) ) {
			echo esc_html( (string) get_post_meta( $post_id, $col, true ) ?: '—' );
		}
	}
}
