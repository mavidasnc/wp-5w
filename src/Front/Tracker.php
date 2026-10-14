<?php
/**
 * Tracker nel frontend: script e dati della pagina.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Front;

use Mavida\FiveW\Settings;
use Mavida\FiveW\Support\SearchTerm;
use Mavida\FiveW\Support\Visit;

defined( 'ABSPATH' ) || exit;

/**
 * Carica `build/tracker/tracker.js` e stampa il blocco JSON `#wp5w-hit` con i dati della pagina.
 *
 * Il blocco JSON non viene eseguito, quindi le ottimizzazioni JavaScript di LiteSpeed (defer, minify)
 * non lo toccano e l'ordine di esecuzione non conta. La pagina è identica per tutti i visitatori
 * (nessun nonce, nessun dato dell'utente) e può stare in cache.
 */
final class Tracker {

	/**
	 * Aggancia lo script e il blocco dei dati.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'print_data' ) );
	}

	/**
	 * Vero se la pagina corrente va tracciata.
	 *
	 * @return bool
	 */
	public function should_track(): bool {
		// Raccolta spenta dalle Opzioni.
		if ( ! Settings::get()['own_tracking'] ) {
			return false;
		}

		// Anteprime, Customizer, feed, robots.txt, trackback ed embed non sono visite.
		return ! ( is_preview() || is_customize_preview() || is_feed() || is_robots() || is_trackback() || is_embed() );
	}

	/**
	 * Mette in coda lo script compilato, in defer e nel footer.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->should_track() ) {
			return;
		}

		wp_enqueue_script(
			'wp5w-tracker',
			WP5W_PLUGIN_URL . 'build/tracker/tracker.js',
			array(),
			WP5W_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}

	/**
	 * Stampa il blocco JSON con endpoint, contenuto, firma e opzione DNT (e, in una ricerca, termine e risultati).
	 *
	 * @return void
	 */
	public function print_data(): void {
		if ( ! $this->should_track() ) {
			return;
		}

		$object = self::current_object();
		$data   = array(
			'url'  => esc_url_raw( rest_url( 'wp5w/v1/hit' ) ),
			'type' => $object['type'],
			'id'   => $object['id'],
			'sig'  => self::signature( $object['type'], $object['id'] ),
			'dnt'  => Settings::get()['respect_dnt'],
		);

		// Ricerca interna (solo prima pagina dei risultati, così le pagine successive non gonfiano i totali): termine normalizzato
		// e numero di risultati. Non sono firmati: l'endpoint li filtra e decide (vedi SearchTerm).
		if ( is_search() && ! is_paged() ) {
			$term = SearchTerm::normalize( get_search_query( false ) );
			if ( '' !== $term ) {
				$data['q'] = $term;
				$data['n'] = (int) $GLOBALS['wp_query']->found_posts;
			}
		}

		// Si stampa a mano (non con wp_print_inline_script_tag): con temi senza supporto html5 per gli script
		// WordPress avvolge il contenuto in un commento CDATA e il JSON non sarebbe più valido.
		// I flag JSON_HEX_* impediscono che il contenuto chiuda il tag o apra entità HTML.
		printf(
			'<script type="application/json" id="wp5w-hit">%s</script>' . "\n",
			wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON con i flag JSON_HEX_*, sicuro dentro un tag script.
		);
	}

	/**
	 * Tipo e ID del contenuto corrente, con gli stessi valori di WP Statistics (WP_Statistics\Pages::get_page_type).
	 *
	 * @return array{type:string,id:int}
	 */
	public static function current_object(): array {
		if ( is_front_page() || is_home() ) {
			$type = 'home';
		} elseif ( is_singular() ) {
			$post_type = (string) get_post_type();
			$type      = in_array( $post_type, array( 'post', 'page' ), true ) ? $post_type : 'post_type_' . $post_type;
		} elseif ( is_category() ) {
			$type = 'category';
		} elseif ( is_tag() ) {
			$type = 'post_tag';
		} elseif ( is_tax() ) {
			$type = 'tax_' . (string) get_queried_object()?->taxonomy;
		} elseif ( is_author() ) {
			$type = 'author';
		} elseif ( is_archive() ) {
			$type = 'archive';
		} elseif ( is_search() ) {
			$type = 'search';
		} else {
			$type = '404';
		}

		// L'endpoint accetta solo lettere minuscole, numeri e trattino basso (massimo 40 caratteri).
		$type = substr( (string) preg_replace( '/[^a-z0-9_]/', '_', strtolower( $type ) ), 0, 40 );

		// Le ricerche non hanno un contenuto: ID 0.
		return array(
			'type' => $type,
			'id'   => is_search() ? 0 : (int) get_queried_object_id(),
		);
	}

	/**
	 * Firma di tipo e ID del contenuto, con la chiave di autenticazione del sito.
	 * Se cambiano le salt di WordPress le pagine in cache vanno svuotate.
	 *
	 * @param string $type Tipo di contenuto.
	 * @param int    $id   ID del contenuto.
	 * @return string
	 */
	public static function signature( string $type, int $id ): string {
		return Visit::signature( $type, $id, wp_salt( 'auth' ) );
	}
}
