<?php
/**
 * Sincronización desde Heavy Listing Yard (yard.heavylisting.com).
 *
 * Yard es la fuente de verdad del inventario de los dealers que lo usan; este sitio publica lo
 * que Yard le manda. Rutas (todas firmadas, sin usuario de WordPress):
 *   POST lucuma-mkt/v1/sync/equipment   crear o actualizar una ficha (o pasarla a borrador)
 *   POST lucuma-mkt/v1/sync/dealer      perfil público del dealer (user meta)
 *   GET  lucuma-mkt/v1/sync/ping        comprobar firma y versión
 * y una de configuración, con usuario administrador:
 *   POST lucuma-mkt/v1/sync/secret      guardar el secreto compartido (opción lm_yard_secret)
 *
 * Firma: cabeceras X-Yard-Timestamp (unix) y X-Yard-Signature = hex(HMAC-SHA256(secreto,
 * "<timestamp>.<cuerpo crudo>")). Se rechaza con más de 5 minutos de desfase (repetición).
 *
 * Fotos: se descargan a la Media Library desde Yard (igual que el importador CSV), una sola vez
 * por foto de Yard (meta _lm_yard_photo). Si la foto de Yard vino de este mismo sitio, se adopta
 * el adjunto existente en vez de bajarla de nuevo. Así las plantillas de Bricks, el SEO y el
 * Open Graph siguen funcionando sin cambios (lm_gallery + imagen destacada).
 *
 * Un dealer sincronizado queda marcado (user meta lm_yard_managed): su panel /dealer/ pasa a
 * solo lectura para que nadie edite la misma ficha en dos lados.
 */
defined( 'ABSPATH' ) || exit;

class LM_Sync {
	const NS     = 'lucuma-mkt/v1';
	const OPTION = 'lm_yard_secret';
	const SKEW   = 300;

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_filter( 'rest_pre_dispatch', [ __CLASS__, 'guard_panel' ], 10, 3 );
	}

	public static function routes() {
		$signed = [ __CLASS__, 'verify' ];
		register_rest_route( self::NS, '/sync/ping', [ 'methods' => 'GET', 'permission_callback' => $signed, 'callback' => fn() => [ 'ok' => true, 'version' => LM_VERSION ] ] );
		register_rest_route( self::NS, '/sync/equipment', [ 'methods' => 'POST', 'permission_callback' => $signed, 'callback' => [ __CLASS__, 'equipment' ] ] );
		register_rest_route( self::NS, '/sync/dealer', [ 'methods' => 'POST', 'permission_callback' => $signed, 'callback' => [ __CLASS__, 'dealer' ] ] );
		register_rest_route( self::NS, '/sync/secret', [
			'methods' => 'POST', 'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'callback' => function ( WP_REST_Request $r ) {
				$s = (string) $r->get_param( 'secret' );
				if ( strlen( $s ) < 32 ) return new WP_Error( 'secret', 'The secret must have at least 32 characters.', [ 'status' => 400 ] );
				update_option( self::OPTION, $s, false );
				return [ 'ok' => true ];
			},
		] );
	}

	/* ------------------------------------------------------------------ firma */

	public static function verify( WP_REST_Request $req ) {
		$secret = (string) get_option( self::OPTION, '' );
		if ( $secret === '' ) return new WP_Error( 'yard_disabled', 'Yard sync is not configured on this site.', [ 'status' => 503 ] );
		$ts  = (string) $req->get_header( 'x_yard_timestamp' );
		$sig = (string) $req->get_header( 'x_yard_signature' );
		if ( ! ctype_digit( $ts ) || abs( time() - (int) $ts ) > self::SKEW ) return new WP_Error( 'yard_time', 'Stale or missing timestamp.', [ 'status' => 401 ] );
		$expected = hash_hmac( 'sha256', $ts . '.' . $req->get_body(), $secret );
		if ( ! hash_equals( $expected, $sig ) ) return new WP_Error( 'yard_signature', 'Bad signature.', [ 'status' => 401 ] );
		return true;
	}

	/* ------------------------------------------------------------------ fichas */

	/**
	 * Crea o actualiza una ficha. Busca la existente por: wp_post_id (si Yard ya la conoce),
	 * meta lm_yard_id, o autor + número de stock (migración de las fichas que ya estaban).
	 * El cuerpo trae términos por id de WordPress (Yard copia el catálogo de aquí).
	 */
	public static function equipment( WP_REST_Request $req ) {
		$b = (array) $req->get_json_params();
		$yard_id = sanitize_text_field( (string) ( $b['yard_id'] ?? '' ) );
		$author  = (int) ( $b['wp_user_id'] ?? 0 );
		if ( $yard_id === '' ) return new WP_Error( 'yard_id', 'Missing yard_id.', [ 'status' => 400 ] );
		if ( ! $author || ! LM_Dealers::is_dealer( $author ) ) return new WP_Error( 'dealer', 'wp_user_id is not a dealer on this site.', [ 'status' => 400 ] );

		$id = self::find( (int) ( $b['wp_post_id'] ?? 0 ), $yard_id, $author, (string) ( $b['stock'] ?? '' ) );

		// Borrada en Yard: la ficha pasa a borrador (no se borra, para no romper URLs ni redirecciones).
		if ( ! empty( $b['deleted'] ) ) {
			if ( $id ) wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
			return [ 'id' => $id ?: null, 'status' => 'draft', 'link' => null ];
		}

		$cat  = get_term( (int) ( $b['category_term'] ?? 0 ), LM_Model::CATEGORY );
		$make = get_term( (int) ( $b['make_term'] ?? 0 ), LM_Model::MAKE );
		$draft = ( $b['status'] ?? '' ) === 'draft';
		if ( ! $draft && ( ! $cat || is_wp_error( $cat ) || ! $make || is_wp_error( $make ) ) ) {
			return new WP_Error( 'terms', 'Category or make not found on this site.', [ 'status' => 400 ] );
		}
		if ( ! $draft && trim( (string) ( $b['model'] ?? '' ) ) === '' ) {
			return new WP_Error( 'model', 'A published listing needs a model.', [ 'status' => 400 ] );
		}

		// Se guarda como el dealer: misma lógica, permisos y autoría que su panel.
		$prev_user = get_current_user_id();
		wp_set_current_user( $author );
		try {
			$fields = [
				'category' => $cat && ! is_wp_error( $cat ) ? (string) $cat->term_id : '',
				'make' => $make && ! is_wp_error( $make ) ? (string) $make->term_id : '',
				'model' => (string) ( $b['model'] ?? '' ),
				'title' => (string) ( $b['title'] ?? '' ), 'description' => (string) ( $b['description'] ?? '' ),
				'year' => $b['year'] ?? '', 'hours' => $b['hours'] ?? '', 'mileage' => $b['mileage'] ?? '', 'price' => $b['price'] ?? '',
				'call' => ! empty( $b['call_for_price'] ), 'condition' => (string) ( $b['condition'] ?? 'used' ),
				'status' => $draft ? 'available' : (string) ( $b['status'] ?? 'available' ), 'draft' => $draft,
				'stock' => (string) ( $b['stock'] ?? '' ), 'serial' => (string) ( $b['serial'] ?? '' ),
				'city' => (string) ( $b['city'] ?? '' ), 'state' => (string) ( $b['state'] ?? '' ), 'zip' => (string) ( $b['zip'] ?? '' ),
				'video_url' => (string) ( $b['video_url'] ?? '' ), 'source_url' => (string) ( $b['source_url'] ?? '' ),
				'specs' => is_array( $b['specs'] ?? null ) ? $b['specs'] : [],
			];
			if ( ! $cat || is_wp_error( $cat ) || ! $make || is_wp_error( $make ) || trim( $fields['model'] ) === '' ) {
				// Borrador incompleto: el guardado del panel exige categoría, marca y modelo, así que solo se toca el estado.
				if ( $id ) wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
				return [ 'id' => $id ?: null, 'status' => 'draft', 'link' => null, 'note' => 'Kept as draft: missing category, make or model.' ];
			}
			$saved = LM_Panel_Api::save_for( $id, $fields, $author );
			if ( is_wp_error( $saved ) ) return $saved;
			$is_new = ! $id;
			$id = (int) ( is_array( $saved ) ? $saved['id'] : $saved->get_data()['id'] );
			update_post_meta( $id, 'lm_yard_id', $yard_id );
			// Solo una ficha CREADA por Yard toma la fecha de Yard. Una que ya existía conserva la suya
			// (y Yard adopta esa fecha con la respuesta: 'published_at').
			if ( $is_new && ! empty( $b['published_at'] ) && get_post_status( $id ) === 'publish' && ! get_post_meta( $id, '_lm_yard_dated', true ) ) {
				$t = strtotime( (string) $b['published_at'] );
				if ( $t && $t < time() ) {
					wp_update_post( [ 'ID' => $id, 'post_date' => wp_date( 'Y-m-d H:i:s', $t ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $t ) ] );
				}
				update_post_meta( $id, '_lm_yard_dated', 1 );
			}
			$photos = self::photos( $id, $author, is_array( $b['photos'] ?? null ) ? $b['photos'] : [] );
		} finally {
			wp_set_current_user( $prev_user );
		}
		update_user_meta( $author, 'lm_yard_managed', 1 );
		return [ 'id' => $id, 'status' => get_post_status( $id ), 'link' => get_permalink( $id ), 'photos' => $photos,
			'published_at' => get_post_status( $id ) === 'publish' ? get_post_time( 'c', true, $id ) : null ];
	}

	private static function find( int $wp_post_id, string $yard_id, int $author, string $stock ): int {
		if ( $wp_post_id && get_post_type( $wp_post_id ) === LM_Model::PT && (int) get_post_field( 'post_author', $wp_post_id ) === $author ) return $wp_post_id;
		$q = [ 'post_type' => LM_Model::PT, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'author' => $author, 'no_found_rows' => true ];
		$found = get_posts( $q + [ 'meta_key' => 'lm_yard_id', 'meta_value' => $yard_id ] );
		if ( $found ) return (int) $found[0];
		$stock = trim( $stock );
		if ( $stock !== '' ) {
			$found = get_posts( $q + [ 'meta_key' => 'lm_stock_number', 'meta_value' => $stock ] );
			// Solo se adopta una ficha que todavía no pertenece a otra unidad de Yard.
			if ( $found && ! get_post_meta( (int) $found[0], 'lm_yard_id', true ) ) return (int) $found[0];
		}
		return 0;
	}

	/**
	 * Galería en el orden de Yard. Cada foto: { id, url, source_url? }. Las fotos de Yard que ya no
	 * están se borran; los adjuntos anteriores que no vinieron de Yard solo salen de la galería.
	 */
	private static function photos( int $post_id, int $author, array $photos ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		@set_time_limit( 120 );
		$ids = [];
		$failed = 0;
		foreach ( array_slice( $photos, 0, 60 ) as $p ) {
			$yid = sanitize_text_field( (string) ( $p['id'] ?? '' ) );
			$url = esc_url_raw( (string) ( $p['url'] ?? '' ) );
			if ( $yid === '' || $url === '' ) continue;
			$att = self::attachment_for( $yid, (string) ( $p['source_url'] ?? '' ) );
			if ( ! $att ) $att = self::download( $url, $post_id, $author );
			if ( ! $att ) { $failed++; continue; }
			update_post_meta( $att, '_lm_yard_photo', $yid );
			if ( (int) wp_get_post_parent_id( $att ) !== $post_id ) wp_update_post( [ 'ID' => $att, 'post_parent' => $post_id ] );
			$ids[] = $att;
		}
		$before = array_map( 'intval', (array) ( get_post_meta( $post_id, 'lm_gallery', true ) ?: [] ) );
		update_post_meta( $post_id, 'lm_gallery', $ids );
		if ( $ids ) set_post_thumbnail( $post_id, $ids[0] ); else delete_post_thumbnail( $post_id );
		foreach ( array_diff( $before, $ids ) as $old ) {
			if ( get_post_meta( $old, '_lm_yard_photo', true ) ) wp_delete_attachment( $old, true );
		}
		return [ 'count' => count( $ids ), 'failed' => $failed ];
	}

	/** Adjunto ya bajado para esta foto de Yard, o el de este sitio del que salió la foto. */
	private static function attachment_for( string $yard_photo, string $source_url ): int {
		$found = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => '_lm_yard_photo', 'meta_value' => $yard_photo ] );
		if ( $found ) return (int) $found[0];
		if ( $source_url !== '' && str_starts_with( $source_url, home_url( '/' ) ) ) {
			$att = attachment_url_to_postid( $source_url );
			if ( $att && ! get_post_meta( $att, '_lm_yard_photo', true ) ) return (int) $att;
		}
		return 0;
	}

	private static function download( string $url, int $post_id, int $author ): int {
		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) return 0;
		$type = wp_get_image_mime( $tmp );
		if ( ! $type || ! str_starts_with( $type, 'image/' ) ) { @unlink( $tmp ); return 0; }
		$ext = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif' ][ $type ] ?? 'jpg';
		$name = sanitize_file_name( get_the_title( $post_id ) ?: 'equipment' ) . '.' . $ext;
		$id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $post_id, get_the_title( $post_id ) );
		if ( is_wp_error( $id ) ) { @unlink( $tmp ); return 0; }
		wp_update_post( [ 'ID' => $id, 'post_author' => $author ] );
		update_post_meta( $id, '_wp_attachment_image_alt', get_the_title( $post_id ) );
		return (int) $id;
	}

	/* ------------------------------------------------------------------ dealer */

	public static function dealer( WP_REST_Request $req ) {
		$b = (array) $req->get_json_params();
		$u = (int) ( $b['wp_user_id'] ?? 0 );
		if ( ! $u || ! LM_Dealers::is_dealer( $u ) ) return new WP_Error( 'dealer', 'wp_user_id is not a dealer on this site.', [ 'status' => 400 ] );
		$map = [ 'name' => 'lm_company', 'phone' => 'lm_phone', 'website' => 'lm_website', 'address' => 'lm_address', 'city' => 'lm_city',
			'state' => 'lm_state', 'zip' => 'lm_zip', 'hours' => 'lm_opening_hours', 'gbp' => 'lm_gbp' ];
		foreach ( $map as $in => $key ) {
			if ( ! array_key_exists( $in, $b ) ) continue;
			$v = (string) ( $b[ $in ] ?? '' );
			$v = in_array( $in, [ 'website', 'gbp' ], true ) ? esc_url_raw( $v ) : ( $in === 'hours' ? sanitize_textarea_field( $v ) : sanitize_text_field( $v ) );
			if ( $v === '' ) delete_user_meta( $u, $key ); else update_user_meta( $u, $key, $v );
		}
		update_user_meta( $u, 'lm_yard_managed', 1 );
		return [ 'ok' => true, 'link' => LM_Dealers::url( $u ) ];
	}

	/* ------------------------------------------------------------------ panel en solo lectura */

	/** Un dealer que ya usa Yard no puede editar sus fichas desde /dealer/: lo hace en Yard. */
	public static function guard_panel( $result, $server, WP_REST_Request $req ) {
		if ( $result !== null || $req->get_method() === 'GET' ) return $result;
		$route = $req->get_route();
		if ( ! preg_match( '#^/' . preg_quote( self::NS, '#' ) . '/(my/equipment|import)#', $route ) ) return $result;
		$u = get_current_user_id();
		if ( $u && get_user_meta( $u, 'lm_yard_managed', true ) && ! current_user_can( 'edit_others_lm_equipments' ) ) {
			return new WP_Error( 'yard_managed', 'Your inventory is managed in Heavy Listing Yard: https://yard.heavylisting.com', [ 'status' => 403 ] );
		}
		return $result;
	}
}

LM_Sync::init();
