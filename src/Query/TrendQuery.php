<?php
/**
 * Dati dell'indicatore di trend per il sito e per i singoli contenuti.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Query;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Settings;
use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\Metrics;
use Mavida\FiveW\Support\Origin;
use Mavida\FiveW\Support\Trend;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin, nomi costruiti con $wpdb->prefix in Schema::table().

/**
 * Ultime 4 settimane complete contro le 4 precedenti (vedi Support\Trend).
 *
 * Il traffico usa le settimane chiuse fino a ieri, Search Console quelle chiuse fino all'ultimo giorno
 * disponibile (arriva con 2-3 giorni di ritardo). Visibilità: per il sito le pagine con almeno
 * un'impression, per un contenuto le query con almeno un'impression.
 */
final class TrendQuery {

	/**
	 * Condizione sui tipi di WP Statistics che corrispondono a un post (esclude home, archivi e tassonomie).
	 *
	 * @var string
	 */
	private const POST_TYPES = "(object_type IN ('post', 'page') OR object_type LIKE 'post\\_type\\_%')";

	/**
	 * Trend del sito.
	 *
	 * @return array<string,mixed>
	 */
	public function site(): array {
		global $wpdb;

		[ $traffic, $gsc ] = $this->windows();
		$site              = Schema::table( 'site_daily' );
		$origin            = Origin::where( Settings::own_since() ); // Regola dell'origine.
		$values            = array();
		foreach ( array( 'current', 'previous' ) as $period ) {
			$values[ $period ] = array(
				'visitors' => (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COALESCE(SUM(visitors), 0) FROM {$site} WHERE day BETWEEN %s AND %s AND {$origin}", $traffic[ $period ]->from, $traffic[ $period ]->to )
				),
			) + $this->site_gsc( $gsc[ $period ] ?? null );
		}

		return Trend::score( $values['current'], $values['previous'] ) + array( 'windows' => self::export( $traffic, $gsc ) );
	}

	/**
	 * Trend di più contenuti in poche query (una per tabella e periodo).
	 *
	 * @param array<int,int> $ids ID dei post.
	 * @return array<int,array<string,mixed>> ID => trend.
	 */
	public function objects( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}

		[ $traffic, $gsc ] = $this->windows();
		$placeholders      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$origin            = Origin::where( Settings::own_since() ); // Regola dell'origine (solo su object_daily).
		$data              = array();
		foreach ( array( 'current', 'previous' ) as $period ) {
			$data[ $period ]['visitors'] = $wpdb->get_results(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Segnaposto %d generati per ogni id.
					'SELECT object_id, SUM(visitors) AS visitors FROM ' . Schema::table( 'object_daily' ) . '
					WHERE day BETWEEN %s AND %s AND ' . self::POST_TYPES . " AND {$origin} AND object_id IN ({$placeholders}) GROUP BY object_id",
					array_merge( array( $traffic[ $period ]->from, $traffic[ $period ]->to ), $ids )
				),
				OBJECT_K
			);
			$data[ $period ]['gsc']      = isset( $gsc[ $period ] ) ? $wpdb->get_results(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Segnaposto %d generati per ogni id.
					'SELECT object_id, COUNT(DISTINCT CASE WHEN impressions > 0 THEN query_hash END) AS visibility,
						SUM(impressions) AS impressions, SUM(pos_x_impr) AS pos_x_impr
					FROM ' . Schema::table( 'gsc_daily' ) . " WHERE day BETWEEN %s AND %s AND object_id IN ({$placeholders}) GROUP BY object_id",
					array_merge( array( $gsc[ $period ]->from, $gsc[ $period ]->to ), $ids )
				),
				OBJECT_K
			) : array();
		}

		$trends = array();
		foreach ( $ids as $id ) {
			$values = array();
			foreach ( array( 'current', 'previous' ) as $period ) {
				$row               = $data[ $period ]['gsc'][ $id ] ?? null;
				$values[ $period ] = array(
					'visitors'   => (int) ( $data[ $period ]['visitors'][ $id ]->visitors ?? 0 ),
					'visibility' => (int) ( $row->visibility ?? 0 ),
					'position'   => $row ? Metrics::weighted_position( (float) $row->pos_x_impr, (int) $row->impressions ) : null,
				);
			}
			$trends[ $id ] = Trend::score( $values['current'], $values['previous'] );

			// Pubblicato dopo l'inizio del periodo precedente: il confronto misurerebbe solo la pubblicazione.
			if ( (string) get_the_date( 'Y-m-d', $id ) > $traffic['previous']->from ) {
				$trends[ $id ]['score']  = null;
				$trends[ $id ]['status'] = 'new';
			}
		}
		return $trends;
	}

	/**
	 * Aggiunge 'trend' a righe che hanno la chiave 'id'.
	 *
	 * @param array<int,array<string,mixed>> $rows Righe.
	 * @return array<int,array<string,mixed>>
	 */
	public function attach( array $rows ): array {
		$trends = $this->objects( array_column( $rows, 'id' ) );
		return array_map(
			static fn( array $row ): array => $row + array( 'trend' => $trends[ (int) $row['id'] ] ?? null ),
			$rows
		);
	}

	/**
	 * Visibilità e posizione del sito nel periodo di Search Console.
	 *
	 * @param DateRange|null $range Periodo, null se Search Console non ha dati.
	 * @return array{visibility:int,position:float|null}
	 */
	private function site_gsc( ?DateRange $range ): array {
		global $wpdb;

		if ( null === $range ) {
			return array(
				'visibility' => 0,
				'position'   => null,
			);
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT CASE WHEN impressions > 0 THEN page_hash END) AS visibility, COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(pos_x_impr), 0) AS pos_x_impr
				FROM ' . Schema::table( 'gsc_daily' ) . ' WHERE day BETWEEN %s AND %s',
				$range->from,
				$range->to
			)
		);
		return array(
			'visibility' => (int) ( $row->visibility ?? 0 ),
			'position'   => Metrics::weighted_position( (float) ( $row->pos_x_impr ?? 0 ), (int) ( $row->impressions ?? 0 ) ),
		);
	}

	/**
	 * Finestre del traffico (settimane chiuse fino a ieri) e di Search Console (fino all'ultimo giorno disponibile).
	 *
	 * @return array{0:array{current:DateRange,previous:DateRange},1:array{current?:DateRange,previous?:DateRange}}
	 */
	private function windows(): array {
		global $wpdb;

		$yesterday = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
		$last_gsc  = (string) $wpdb->get_var( 'SELECT MAX(day) FROM ' . Schema::table( 'gsc_daily' ) );

		return array( Trend::windows( (string) $yesterday ), '' !== $last_gsc ? Trend::windows( $last_gsc ) : array() );
	}

	/**
	 * Periodi in formato REST.
	 *
	 * @param array<string,DateRange> $traffic Finestre del traffico.
	 * @param array<string,DateRange> $gsc     Finestre di Search Console.
	 * @return array<string,array<string,array{from:string,to:string}>>
	 */
	private static function export( array $traffic, array $gsc ): array {
		$out     = array();
		$sources = array(
			'traffic' => $traffic,
			'gsc'     => $gsc,
		);
		foreach ( $sources as $source => $windows ) {
			foreach ( $windows as $period => $range ) {
				$out[ $source ][ $period ] = array(
					'from' => $range->from,
					'to'   => $range->to,
				);
			}
		}
		return $out;
	}
}
