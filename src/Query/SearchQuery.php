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

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin, nomi costruiti con $wpdb->prefix in Schema::table().

/**
 * Query, pagine e opportunità da wp5w_gsc_daily.
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
	 * Periodi selezionabili (giorni) per pagine e opportunità.
	 *
	 * @var int[]
	 */
	public const DAYS = array( 7, 28, 90, 180 );

	/**
	 * Ultimi $days giorni disponibili in Search Console (i dati arrivano con 2-3 giorni di ritardo).
	 *
	 * @param int $days Numero di giorni.
	 * @return DateRange|null Null se la tabella è vuota.
	 */
	public function latest_range( int $days ): ?DateRange {
		global $wpdb;
		$last = (string) $wpdb->get_var( 'SELECT MAX(day) FROM ' . Schema::table( 'gsc_daily' ) );
		return '' !== $last ? DateRange::last_days( $days, $last ) : null;
	}

	/**
	 * Primo giorno con dati di Search Console, stringa vuota se la tabella è vuota.
	 *
	 * @return string
	 */
	public function first_day(): string {
		global $wpdb;
		return (string) $wpdb->get_var( 'SELECT MIN(day) FROM ' . Schema::table( 'gsc_daily' ) );
	}

	/**
	 * Query più cliccate, con variazione dei click rispetto al periodo precedente.
	 *
	 * @param DateRange $range    Intervallo.
	 * @param DateRange $previous Periodo precedente.
	 * @param int       $limit    Numero massimo di query.
	 * @return array<int,array<string,mixed>>
	 */
	public function queries( DateRange $range, DateRange $previous, int $limit = self::QUERIES_LIMIT ): array {
		global $wpdb;

		$gsc  = Schema::table( 'gsc_daily' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_hash, MIN(query) AS query, SUM(clicks) AS clicks, SUM(impressions) AS impressions, SUM(pos_x_impr) AS pos_x_impr,
					COUNT(DISTINCT page_hash) AS pages
				FROM {$gsc} WHERE day BETWEEN %s AND %s
				GROUP BY query_hash ORDER BY clicks DESC, impressions DESC, query LIMIT %d",
				$range->from,
				$range->to,
				$limit
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
	public function pages( DateRange $range ): array {
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
	 * Opportunità in un'unica lista ordinata per impression, con il tipo di ciascuna riga:
	 * near_first = quasi in prima pagina (posizione 8-20), low_ctr = in alto ma poco cliccata (posizione fino a 5, CTR sotto il 2%).
	 * Le due fasce di posizione non si sovrappongono, quindi una query compare una volta sola.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array<int,array<string,mixed>>
	 */
	public function opportunities( DateRange $range ): array {
		$rows = array_merge(
			$this->opportunities_where( $range, 'position BETWEEN 8 AND 20', 'near_first' ),
			$this->opportunities_where( $range, 'position <= 5 AND clicks / impressions < 0.02', 'low_ctr' )
		);
		usort( $rows, static fn( array $a, array $b ): int => $b['impressions'] <=> $a['impressions'] );
		return $rows;
	}

	/**
	 * Query che soddisfano una condizione su posizione pesata e CTR, ordinate per impression.
	 *
	 * @param DateRange $range     Intervallo.
	 * @param string    $condition Condizione SQL su position, clicks e impressions (costante interna).
	 * @param string    $kind      Tipo di opportunità.
	 * @return array<int,array<string,mixed>>
	 */
	private function opportunities_where( DateRange $range, string $condition, string $kind ): array {
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
				'kind'        => $kind,
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
