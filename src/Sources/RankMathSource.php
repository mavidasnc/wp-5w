<?php
/**
 * Sorgente Rank Math Analytics (Search Console).
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Sources;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Support\DateRange;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabelle di Rank Math e del plugin, nomi costruiti con $wpdb->prefix.

/**
 * Legge rank_math_analytics_gsc (una riga per giorno, pagina e query).
 *
 * - Le righe possono essere duplicate se l'import di Rank Math viene rilanciato: si raggruppa per
 *   (giorno, pagina, query) e si prende il MAX, che su copie identiche restituisce il valore giusto.
 * - La colonna created è ON UPDATE current_timestamp(): la tabella si legge e basta.
 * - created è un TIMESTAMP: DATE(created) dipende dal fuso della sessione MySQL. Rank Math scrive e
 *   wp-5w legge sullo stesso server, quindi i giorni coincidono; un dump caricato su un server con
 *   un altro fuso sposta invece i giorni (es. produzione +02:00, wp-env UTC).
 * - Rank Math cancella le righe più vecchie di 2 x console_caching_control giorni: le copie in
 *   wp5w_gsc_daily restano, perché si sostituiscono solo i giorni ancora presenti nella sorgente.
 */
final class RankMathSource extends Source {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'rank_math';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Rank Math Analytics (Search Console)';
	}

	/**
	 * {@inheritDoc}
	 */
	public function version(): ?string {
		return defined( 'RANK_MATH_VERSION' ) ? (string) constant( 'RANK_MATH_VERSION' ) : null;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function required(): array {
		return array(
			'rank_math_analytics_gsc'     => array( 'created', 'query', 'page', 'clicks', 'impressions', 'position' ),
			'rank_math_analytics_objects' => array( 'page', 'object_id' ),
		);
	}

	/**
	 * Riversa i dati dell'intervallo nelle tabelle wp5w_*.
	 *
	 * @param DateRange $range Intervallo richiesto.
	 * @return array<string,int> Tabella wp5w => righe scritte.
	 */
	public function import( DateRange $range ): array {
		$gsc     = $this->table( 'rank_math_analytics_gsc' );
		$objects = $this->table( 'rank_math_analytics_objects' );

		// object_id dalla tabella ponte di Rank Math (una pagina può comparire più volte: si prende il MAX).
		$rows = $this->replace_by_month(
			'gsc_daily',
			$range,
			$this->data_range( 'rank_math_analytics_gsc', 'DATE(created)' ),
			"SELECT DATE(g.created), MD5(g.page), MD5(g.query), COALESCE(o.object_id, 0), g.page, g.query,
				MAX(g.clicks), MAX(g.impressions), MAX(g.position) * MAX(g.impressions)
			FROM {$gsc} g
			LEFT JOIN ( SELECT page, MAX(object_id) AS object_id FROM {$objects} GROUP BY page ) o ON o.page = g.page
			WHERE g.created >= %s AND g.created < DATE_ADD(%s, INTERVAL 1 DAY)
			GROUP BY DATE(g.created), g.page, g.query",
			'day, page_hash, query_hash, object_id, page, query, clicks, impressions, pos_x_impr'
		);

		$this->map_unknown_pages( $range );

		return array( 'gsc_daily' => $rows );
	}

	/**
	 * Associa a un contenuto le pagine che Rank Math non ha in rank_math_analytics_objects.
	 *
	 * @param DateRange $range Intervallo appena importato.
	 * @return void
	 */
	private function map_unknown_pages( DateRange $range ): void {
		global $wpdb;

		$table = Schema::table( 'gsc_daily' );
		$pages = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT page FROM {$table} WHERE object_id = 0 AND day BETWEEN %s AND %s", $range->from, $range->to )
		);

		foreach ( $pages as $page ) {
			$post_id = url_to_postid( home_url( (string) $page ) );
			if ( $post_id > 0 ) {
				$wpdb->query(
					$wpdb->prepare( "UPDATE {$table} SET object_id = %d WHERE object_id = 0 AND page_hash = MD5(%s)", $post_id, $page )
				);
			}
		}
	}
}
