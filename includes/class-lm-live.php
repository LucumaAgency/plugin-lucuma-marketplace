<?php
/**
 * Heavy Listing Live (remates en vivo) dentro de heavylisting.com.
 *
 * Un remate armado en Yard llega como ficha lm_auction (LM_Sync::auction) con lm_live = slug del remate en Live.
 * En esa ficha se ve y se puja ahí mismo: Live se muestra en un iframe (mismo sitio, live.heavylisting.com),
 * que avisa su altura y los eventos de medición por postMessage.
 *
 *   [lm_live]                 el remate en vivo de la ficha actual (lo agrega solo [lm_auction_facts])
 *   [lm_live slug="…"]        un remate puntual
 *
 * "Live now": las tarjetas de remates con data-lm-live se marcan con un script que lee la lista pública de Live.
 * Medición (GTM): live_view, bidder_register, bid_placed (ver medicion/README).
 */
defined( 'ABSPATH' ) || exit;

class LM_Live {
	/** Hubo tarjetas de remates en vivo en esta página: se imprime el script de "Live now". */
	private static bool $badges = false;
	private static bool $bridge = false;

	public static function init() {
		add_shortcode( 'lm_live', [ __CLASS__, 'shortcode' ] );
		add_action( 'wp_footer', [ __CLASS__, 'footer' ], 30 );
	}

	public static function url(): string {
		$u = defined( 'LM_LIVE_URL' ) ? LM_LIVE_URL : 'https://live.heavylisting.com';
		return untrailingslashit( (string) apply_filters( 'lm_live_url', $u ) );
	}

	/** Marca la página para el script de "Live now". */
	public static function want_badges() { self::$badges = true; }

	public static function shortcode( $atts = [] ) {
		$a = shortcode_atts( [ 'slug' => '' ], $atts, 'lm_live' );
		$id = get_the_ID();
		$slug = $a['slug'] !== '' ? $a['slug'] : (string) get_post_meta( $id, 'lm_live', true );
		$slug = preg_replace( '/[^a-z0-9-]/', '', strtolower( $slug ) );
		if ( $slug === '' ) return '';
		self::$bridge = true;
		$src = self::url() . '/a/' . rawurlencode( $slug ) . '?embed=1';
		$title = $id ? get_the_title( $id ) : 'Live auction';
		return '<div class="lmf-live" id="live" data-lm-auction-id="' . (int) $id . '" data-lm-auction="' . esc_attr( $title ) . '">' .
			'<iframe class="lmf-live-frame" src="' . esc_url( $src ) . '" title="' . esc_attr( 'Live auction: ' . $title ) . '" loading="eager" ' .
			'allow="autoplay; encrypted-media; picture-in-picture; clipboard-write" allowfullscreen style="width:100%;min-height:720px;border:0;display:block"></iframe>' .
			'<noscript><a class="lmf-btn" href="' . esc_url( self::url() . '/a/' . rawurlencode( $slug ) ) . '">Open the live auction</a></noscript></div>';
	}

	public static function footer() {
		$origin = esc_js( self::url() );
		if ( self::$bridge ) {
			// Altura del iframe y eventos para GTM (solo mensajes que vienen de Live).
			echo "<script>(function(){var O='{$origin}';window.addEventListener('message',function(e){if(e.origin!==O||!e.data||e.data.source!=='hl-live')return;" .
				"var f=document.querySelectorAll('.lmf-live-frame');for(var i=0;i<f.length;i++){if(f[i].contentWindow!==e.source)continue;var box=f[i].parentNode;" .
				"if(e.data.type==='height'&&e.data.height>200){f[i].style.height=Math.min(e.data.height+4,6000)+'px';f[i].style.minHeight='0';}" .
				"if(e.data.type==='event'&&/^(live_view|bidder_register|bid_placed)$/.test(e.data.name)){window.dataLayer=window.dataLayer||[];var d=e.data.data||{};" .
				"dataLayer.push({event:e.data.name,auction_id:box.getAttribute('data-lm-auction-id'),auction_name:box.getAttribute('data-lm-auction'),live_auction:d.auction||'',lot:d.lot||'',value:d.amount||undefined,currency:d.amount?'USD':undefined});}}});})();</script>\n";
		}
		if ( self::$badges ) {
			// "Live now" en las tarjetas: lista pública de Live (sin cookies), una vez por página.
			echo "<script>(function(){var c=document.querySelectorAll('[data-lm-live]');if(!c.length||!window.fetch)return;" .
				"fetch('{$origin}/api/auctions').then(function(r){return r.ok?r.json():[]}).then(function(list){var st={};list.forEach(function(a){st[a.slug]=a.status});" .
				"c.forEach(function(el){var s=st[el.getAttribute('data-lm-live')];if(s!=='live'&&s!=='paused')return;el.classList.add('is-live');" .
				"var k=el.querySelector('.lmf-kicker');if(k&&!el.querySelector('.lmf-live-badge')){var b=document.createElement('span');b.className='lmf-live-badge';b.textContent=s==='live'?'Live now':'Paused';k.parentNode.insertBefore(b,k);}});}).catch(function(){});})();</script>\n";
		}
	}
}
