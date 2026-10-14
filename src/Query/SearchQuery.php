<?php
/**
 * Dati della scheda Search Console.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Query;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\Metrics;
use Mavida\FiveW\Support\Series;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin, nomi costruiti con $wpdb->prefix in Schema::table().

/**
 * KPI, andamento, query, pagine e opportunità da wp5w_gsc_daily.
 *
 * I totali sono somme delle righe pagina+query: Search Console esclude le query anonimizzate,
 * quindi sono più bassi dei totali mostrati nell'interfaccia di Google.
 */
final class SearchQuery {

	/**
	 * Righe della tabella query.
	 *
	 * @var int
	 */
	private const QUERIES_LIMIT = 200;

	/**
	 * Righe della tabella pagine.
	 *
	 * @var int
	 */
	private const PAGES_LIMIT = 100;

	/**
	 * Righe di ciascuna lista di opportunità.
	 *
	 * @var int
	 */
	private const OPPORTUNITY_LIMIT = 30;

	/**
	 * Impression minime perché una query conti come opportunità.
	 *
	 * @var int
	 */
	private const MIN_IMPRESSIONS = 20;

	/**
	 * Tutti i dati della scheda per l'intervallo.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array<string,mixed>
	 */
	public function get( DateRange $range ): array {
		global $wpdb;

		$gsc      = Schema::table( 'gsc_daily' );
		$coverage = $wpdb->get_row( "SELECT MIN(day) AS d_from, MAX(day) AS d_to, COUNT(DISTINCT day) AS days FROM {$gsc}" );

		// Prima del primo giorno con dati non c'è nulla: si accorcia il periodo ("tutto lo storico").
		$first = (string) ( $coverage->d_from ?? '' );
		if ( '' !== $first && $first > $range->from && $first <= $range->to ) {
			$range = new DateRange( $first, $range->to );
		}
		$previous   = $range->previous();
		$comparable = '' !== $first && $first <= $previous->from;

		$daily = $wpdb->get_results(
			$wpdb->prepare( "SELECT day, SUM(clicks) AS clicks, SUM(impressions) AS impressions FROM {$gsc} WHERE day BETWEEN %s AND %s GROUP BY day", $range->from, $range->to ),
			OBJECT_K
		);

		return array(
			'range'         => array(
				'from' => $range->from,
				'to'   => $range->to,
			),
			'previous'      => array(
				'from' => $previous->from,
				'to'   => $previous->to,
			),
			// Il confronto ha senso solo se il periodo precedente è coperto dai dati (altrimenti +9000%).
			'comparable'    => $comparable,
			'kpis'          => Metrics::compare( $this->totals( $range ), $comparable ? $this->totals( $previous ) : array() ),
			'series'        => Series::fill( $range, $daily, array( 'clicks', 'impressions' ) ),
			'queries'       => $this->queries( $range, $previous ),
			'pages'         => $this->pages( $range ),
			'opportunities' => array(
				// Quasi in prima pagina: un piccolo miglioramento porta in prima pagina.
				'near_first' => $this->opportunities( $range, 'position BETWEEN 8 AND 20' ),
				// In alto ma poco cliccate: titolo o descrizione da rivedere.
				'low_ctr'    => $this->opportunities( $range, 'position <= 5 AND clicks / impressions < 0.02' ),
			),
			'coverage'      => array(
				'from' => $coverage->d_from ?? null,
				'to'   => $coverage->d_to ?? null,
				'days' => (int) ( $coverage->days ?? 0 ),
			),
		);
	}

	/**
	 * Totali di Search Console nell'intervallo.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array{clicks:int,impressions:int,ctr:float|null,position:float|null}
	 */
	private function totals( DateRange $range ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(pos_x_impr), 0) AS pos_x_impr
				FROM ' . Schema::table( 'gsc_daily' ) . ' WHERE day BETWEEN %s AND %s',
				$range->from,
				$range->to
			)
		);

		$clicks      = (int) ( $row->clicks ?? 0 );
		$impressions = (int) ( $row->impressions ?? 0 );

		return array(
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'ctr'         => Metrics::ctr( $clicks, $impressions ),
			'position'    => Metrics::weighted_position( (float) ( $row->pos_x_impr ?? 0 ), $impressions ),
		);
	}

	/**
	 * Query più cliccate, con variazione dei click rispetto al periodo precedente.
	 *
	 * @param DateRange $range    Intervallo.
	 * @param DateRange $previous Periodo precedente.
	 * @return array<int,array<string,mixed>>
	 */
	private function queries( DateRange $range, DateRange $previous ): array {
		global $wpdb;

		$gsc  = Schema::table( 'gsc_daily' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_hash, MIN(query) AS query, SUM(clicks) AS clicks, SUM(impressions) AS impressions, SUM(pos_x_impr) AS pos_x_impr,
					COUNT(DISTINCT page_hash) AS pages
				FROM {$gsc} WHERE day BETWEEN %s AND %s
				GROUP BY query_hash ORDER BY clicks DESC, impressions DESC LIMIT %d",
				$range->from,
				$range->to,
				self::QUERIES_LIMIT
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return array();
		}

		// Click delle stesse query nel periodo precedente.
		$hashes       = wp_list_pluck( $rows, 'query_hash' );
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$before       = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Segnaposto %s generati per ogni hash.
				"SELECT query_hash, SUM(clicks) AS clicks FROM {$gsc} WHERE day BETWEEN %s AND %s AND query_hash IN ({$placeholders}) GROUP BY query_hash",
				array_merge( array( $previous->from, $previous->to ), $hashes )
			),
			OBJECT_K
		);

		return array_map(
			static function ( array $row ) use ( $before ): array {
				$clicks      = (int) $row['clicks'];
				$impressions = (int) $row['impressions'];
				$prev        = isset( $before[ $row['query_hash'] ] ) ? (int) $before[ $row['query_hash'] ]->clicks : 0;
				return array(
					'query'           => (string) $row['query'],
					'clicks'          => $clicks,
					'impressions'     => $impressions,
					'ctr'             => Metrics::ctr( $clicks, $impressions ),
					'position'        => Metrics::weighted_position( (float) $row['pos_x_impr'], $impressions ),
					'previous_clicks' => $prev,
					'clicks_change'   => $clicks - $prev,
					'pages'           => (int) $row['pages'],
				);
			},
			$rows
		);
	}

	/**
	 * Pagine con più click, con titolo del contenuto quando è collegato.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array<int,array<string,mixed>>
	 */
	private function pages( DateRange $range ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT MIN(page) AS page, MAX(object_id) AS object_id, SUM(clicks) AS clicks, SUM(impressions) AS impressions, SUM(pos_x_impr) AS pos_x_impr,
					COUNT(DISTINCT query_hash) AS queries
				FROM ' . Schema::table( 'gsc_daily' ) . ' WHERE day BETWEEN %s AND %s
				GROUP BY page_hash ORDER BY clicks DESC, impressions DESC LIMIT %d',
				$range->from,
				$range->to,
				self::PAGES_LIMIT
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$id          = (int) $row['object_id'];
				$clicks      = (int) $row['clicks'];
				$impressions = (int) $row['impressions'];
				$title       = $id > 0 ? html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) : '';
				return array(
					'id'          => $id,
					'page'        => (string) $row['page'],
					'title'       => '' !== $title ? $title : (string) $row['page'],
					'url'         => home_url( (string) $row['page'] ),
					'clicks'      => $clicks,
					'impressions' => $impressions,
					'ctr'         => Metrics::ctr( $clicks, $impressions ),
					'position'    => Metrics::weighted_position( (float) $row['pos_x_impr'], $impressions ),
					'queries'     => (int) $row['queries'],
				);
			},
			$rows
		);
	}

	/**
	 * Query che soddisfano una condizione su posizione pesata e CTR, ordinate per impression.
	 *
	 * @param DateRange $range     Intervallo.
	 * @param string    $condition Condizione SQL su position, clicks e impressions (costante interna).
	 * @return array<int,array<string,mixed>>
	 */
	private function opportunities( DateRange $range, string $condition ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM (
					SELECT MIN(query) AS query, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
						SUM(pos_x_impr) / SUM(impressions) AS position, COUNT(DISTINCT page_hash) AS pages
					FROM ' . Schema::table( 'gsc_daily' ) . " WHERE day BETWEEN %s AND %s
					GROUP BY query_hash HAVING SUM(impressions) >= %d
				) q WHERE {$condition}
				ORDER BY impressions DESC LIMIT %d",
				$range->from,
				$range->to,
				self::MIN_IMPRESSIONS,
				self::OPPORTUNITY_LIMIT
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'query'       => (string) $row['query'],
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'ctr'         => Metrics::ctr( (int) $row['clicks'], (int) $row['impressions'] ),
				'position'    => round( (float) $row['position'], 1 ),
				'pages'       => (int) $row['pages'],
			),
			$rows
		);
	}
}
