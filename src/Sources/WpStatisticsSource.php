<?php
/**
 * Sorgente WP Statistics (traffico).
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Sources;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Support\DateRange;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabelle di WP Statistics e del plugin, nomi costruiti con $wpdb->prefix.

/**
 * Legge le tabelle statistics_* di WP Statistics 14.x.
 *
 * - statistics_summary_totals: totali del sito per giorno (storico lungo);
 * - statistics_pages: visualizzazioni per URI, giorno e contenuto (id + type);
 * - statistics_visitor: un visitatore per giorno, con sorgente, paese, device e pagina d'ingresso.
 *
 * Gli IP (già anonimizzati con hash da WP Statistics) non vengono mai copiati.
 */
final class WpStatisticsSource extends Source {

	/**
	 * Dimensioni riversate in wp5w_dim_daily: nome => colonna di statistics_visitor.
	 *
	 * @var array<string,string>
	 */
	private const DIMENSIONS = array(
		'country'  => 'location',
		'device'   => 'device',
		'browser'  => 'agent',
		'platform' => 'platform',
		'channel'  => 'source_channel',
		'source'   => 'source_name',
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'wp_statistics';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'WP Statistics';
	}

	/**
	 * {@inheritDoc}
	 */
	public function version(): ?string {
		return defined( 'WP_STATISTICS_VERSION' ) ? (string) constant( 'WP_STATISTICS_VERSION' ) : null;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function required(): array {
		return array(
			'statistics_summary_totals' => array( 'date', 'visitors', 'views' ),
			'statistics_pages'          => array( 'page_id', 'uri', 'date', 'count', 'id', 'type' ),
			'statistics_visitor'        => array_merge( array( 'last_counter', 'first_page' ), array_values( self::DIMENSIONS ) ),
		);
	}

	/**
	 * Riversa i dati dell'intervallo nelle tabelle wp5w_*.
	 *
	 * @param DateRange $range Intervallo richiesto.
	 * @return array<string,int> Tabella wp5w => righe scritte.
	 */
	public function import( DateRange $range ): array {
		return array(
			'site_daily'   => $this->import_site( $range ),
			'object_daily' => $this->import_objects( $range ),
			'entry_daily'  => $this->import_entries( $range ),
			'dim_daily'    => $this->import_dimensions( $range ),
		);
	}

	/**
	 * Totali del sito per giorno.
	 *
	 * Prima si calcolano dai dati di dettaglio (servono per i giorni recenti, non ancora riassunti),
	 * poi si sovrascrivono con statistics_summary_totals, che è il dato ufficiale di WP Statistics
	 * e copre anche gli anni in cui il dettaglio è stato aggregato.
	 *
	 * @param DateRange $range Intervallo richiesto.
	 * @return int Righe scritte o aggiornate.
	 */
	private function import_site( DateRange $range ): int {
		global $wpdb;

		$target  = Schema::table( 'site_daily' );
		$pages   = $this->table( 'statistics_pages' );
		$visitor = $this->table( 'statistics_visitor' );
		$summary = $this->table( 'statistics_summary_totals' );
		$rows    = 0;

		$detail = $this->data_range( 'statistics_pages', 'date' );
		$scope  = $detail ? $range->intersect( $detail ) : null;
		if ( $scope ) {
			$rows += (int) $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$target} (day, views)
					SELECT date, SUM(count) FROM {$pages} WHERE date BETWEEN %s AND %s GROUP BY date
					ON DUPLICATE KEY UPDATE views = VALUES(views)",
					$scope->from,
					$scope->to
				)
			);
			$rows += (int) $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$target} (day, visitors)
					SELECT last_counter, COUNT(*) FROM {$visitor} WHERE last_counter BETWEEN %s AND %s GROUP BY last_counter
					ON DUPLICATE KEY UPDATE visitors = VALUES(visitors)",
					$scope->from,
					$scope->to
				)
			);
		}

		// I giorni a zero (es. 2017-2022 sul blog) sono buchi di raccolta, non traffico nullo: si saltano.
		$rows += (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$target} (day, visitors, views)
				SELECT date, visitors, views FROM {$summary}
				WHERE date BETWEEN %s AND %s AND ( visitors > 0 OR views > 0 )
				ON DUPLICATE KEY UPDATE visitors = VALUES(visitors), views = VALUES(views)",
				$range->from,
				$range->to
			)
		);

		return $rows;
	}

	/**
	 * Visualizzazioni per contenuto e giorno.
	 *
	 * @param DateRange $range Intervallo richiesto.
	 * @return int
	 */
	private function import_objects( DateRange $range ): int {
		$pages = $this->table( 'statistics_pages' );

		return $this->replace_by_month(
			'object_daily',
			$range,
			$this->data_range( 'statistics_pages', 'date' ),
			"SELECT date, LEFT(type, 40), id, MIN(uri), SUM(count)
			FROM {$pages} WHERE date BETWEEN %s AND %s
			GROUP BY date, type, id",
			'day, object_type, object_id, uri, views'
		);
	}

	/**
	 * Visitatori entrati da un contenuto, per canale.
	 *
	 * La colonna statistics_visitor.first_page punta a statistics_pages.page_id (verificato sul blog: 10.614 su 10.616).
	 *
	 * @param DateRange $range Intervallo richiesto.
	 * @return int
	 */
	private function import_entries( DateRange $range ): int {
		$pages   = $this->table( 'statistics_pages' );
		$visitor = $this->table( 'statistics_visitor' );

		return $this->replace_by_month(
			'entry_daily',
			$range,
			$this->data_range( 'statistics_visitor', 'last_counter' ),
			"SELECT v.last_counter, LEFT(p.type, 40), p.id, COALESCE(NULLIF(v.source_channel, ''), 'unassigned'), COUNT(*)
			FROM {$visitor} v JOIN {$pages} p ON p.page_id = v.first_page
			WHERE v.last_counter BETWEEN %s AND %s
			GROUP BY v.last_counter, p.type, p.id, 4",
			'day, object_type, object_id, channel, visitors'
		);
	}

	/**
	 * Visitatori per dimensione (paese, device, browser, sistema, canale, sorgente).
	 *
	 * @param DateRange $range Intervallo richiesto.
	 * @return int
	 */
	private function import_dimensions( DateRange $range ): int {
		$visitor = $this->table( 'statistics_visitor' );

		// Una SELECT per dimensione, unite: così ogni mese si sostituisce in un'unica transazione.
		$parts = array();
		foreach ( self::DIMENSIONS as $dim => $column ) {
			$parts[] = "SELECT last_counter AS day, '{$dim}' AS dim, LEFT(COALESCE(NULLIF({$column}, ''), '(n/d)'), 100) AS value, COUNT(*) AS visitors
				FROM {$visitor} WHERE last_counter BETWEEN %s AND %s GROUP BY last_counter, 3";
		}

		return $this->replace_by_month(
			'dim_daily',
			$range,
			$this->data_range( 'statistics_visitor', 'last_counter' ),
			implode( ' UNION ALL ', $parts ),
			'day, dim, value, visitors'
		);
	}
}
