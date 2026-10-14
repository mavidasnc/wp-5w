<?php
/**
 * Dati della Panoramica.
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
 * Interroga solo le tabelle wp5w_* (mai quelle delle sorgenti).
 */
final class OverviewQuery {

	/**
	 * Numero di contenuti nella classifica.
	 *
	 * @var int
	 */
	private const TOP_LIMIT = 20;

	/**
	 * Tutti i dati della Panoramica per l'intervallo.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array<string,mixed>
	 */
	public function get( DateRange $range ): array {
		$previous = $range->previous();
		$current  = $this->totals( $range );
		$before   = $this->totals( $previous );

		$kpis = array();
		foreach ( $current as $key => $value ) {
			$kpis[ $key ] = array(
				'value'    => $value,
				'previous' => $before[ $key ],
				'change'   => null === $value || null === $before[ $key ] ? null : Metrics::change( $value, $before[ $key ] ),
			);
		}

		return array(
			'range'    => array(
				'from' => $range->from,
				'to'   => $range->to,
			),
			'previous' => array(
				'from' => $previous->from,
				'to'   => $previous->to,
			),
			'kpis'     => $kpis,
			'series'   => $this->series( $range ),
			'channels' => $this->channels( $range ),
			'top'      => $this->top( $range ),
			'coverage' => $this->coverage(),
		);
	}

	/**
	 * Totali del periodo: traffico da site_daily, Search Console da gsc_daily.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array{visitors:int,views:int,clicks:int,impressions:int,ctr:float|null,position:float|null}
	 */
	private function totals( DateRange $range ): array {
		global $wpdb;

		$site = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(visitors), 0) AS visitors, COALESCE(SUM(views), 0) AS views FROM ' . Schema::table( 'site_daily' ) . ' WHERE day BETWEEN %s AND %s',
				$range->from,
				$range->to
			)
		);
		$gsc  = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(pos_x_impr), 0) AS pos_x_impr FROM ' . Schema::table( 'gsc_daily' ) . ' WHERE day BETWEEN %s AND %s',
				$range->from,
				$range->to
			)
		);

		$clicks      = (int) ( $gsc->clicks ?? 0 );
		$impressions = (int) ( $gsc->impressions ?? 0 );

		return array(
			'visitors'    => (int) ( $site->visitors ?? 0 ),
			'views'       => (int) ( $site->views ?? 0 ),
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'ctr'         => Metrics::ctr( $clicks, $impressions ),
			'position'    => Metrics::weighted_position( (float) ( $gsc->pos_x_impr ?? 0 ), $impressions ),
		);
	}

	/**
	 * Serie giornaliera: visitatori, visualizzazioni, click e impression.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array<int,array{day:string,visitors:int,views:int,clicks:int,impressions:int}>
	 */
	private function series( DateRange $range ): array {
		global $wpdb;

		$site = $wpdb->get_results(
			$wpdb->prepare( 'SELECT day, visitors, views FROM ' . Schema::table( 'site_daily' ) . ' WHERE day BETWEEN %s AND %s', $range->from, $range->to ),
			OBJECT_K
		);
		$gsc  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT day, SUM(clicks) AS clicks, SUM(impressions) AS impressions FROM ' . Schema::table( 'gsc_daily' ) . ' WHERE day BETWEEN %s AND %s GROUP BY day', $range->from, $range->to ),
			OBJECT_K
		);

		// Traffico e Search Console sulla stessa riga del giorno.
		$rows = array();
		foreach ( $site as $day => $row ) {
			$rows[ $day ] = (array) $row;
		}
		foreach ( $gsc as $day => $row ) {
			$rows[ $day ] = ( $rows[ $day ] ?? array() ) + (array) $row;
		}

		return Series::fill( $range, $rows, array( 'visitors', 'views', 'clicks', 'impressions' ) );
	}

	/**
	 * Visitatori per canale (search, direct, social...).
	 *
	 * @param DateRange $range Intervallo.
	 * @return array<int,array{channel:string,visitors:int}>
	 */
	private function channels( DateRange $range ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT value AS channel, SUM(visitors) AS visitors FROM ' . Schema::table( 'dim_daily' ) . " WHERE dim = 'channel' AND day BETWEEN %s AND %s GROUP BY value ORDER BY visitors DESC",
				$range->from,
				$range->to
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'channel'  => (string) $row['channel'],
				'visitors' => (int) $row['visitors'],
			),
			$rows
		);
	}

	/**
	 * Contenuti più visti, con ingressi da ricerca e dati Search Console.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array<int,array<string,mixed>>
	 */
	private function top( DateRange $range ): array {
		global $wpdb;

		$object = Schema::table( 'object_daily' );
		$entry  = Schema::table( 'entry_daily' );
		$gsc    = Schema::table( 'gsc_daily' );

		// Solo contenuti veri (id > 0): home, archivi e 404 restano nei totali del sito.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.object_type, o.object_id, MIN(o.uri) AS uri, SUM(o.views) AS views,
					( SELECT COALESCE(SUM(e.visitors), 0) FROM {$entry} e WHERE e.object_type = o.object_type AND e.object_id = o.object_id AND e.channel = 'search' AND e.day BETWEEN %s AND %s ) AS search_entries,
					( SELECT COALESCE(SUM(e.visitors), 0) FROM {$entry} e WHERE e.object_type = o.object_type AND e.object_id = o.object_id AND e.day BETWEEN %s AND %s ) AS entries
				FROM {$object} o
				WHERE o.day BETWEEN %s AND %s AND o.object_id > 0 AND o.object_type NOT IN ('home', '404', 'search', 'archive', 'author', 'category', 'post_tag', 'loginpage')
				GROUP BY o.object_type, o.object_id
				ORDER BY views DESC
				LIMIT %d",
				$range->from,
				$range->to,
				$range->from,
				$range->to,
				$range->from,
				$range->to,
				self::TOP_LIMIT
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		// Search Console per gli stessi contenuti, in una sola query.
		$ids          = array_map( 'intval', wp_list_pluck( $rows, 'object_id' ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$gsc_rows     = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Segnaposto %d generati per ogni id.
				"SELECT object_id, SUM(clicks) AS clicks, SUM(impressions) AS impressions, SUM(pos_x_impr) AS pos_x_impr
				FROM {$gsc} WHERE day BETWEEN %s AND %s AND object_id IN ({$placeholders}) GROUP BY object_id",
				array_merge( array( $range->from, $range->to ), $ids )
			),
			OBJECT_K
		);

		return array_map(
			static function ( array $row ) use ( $gsc_rows ): array {
				$id          = (int) $row['object_id'];
				$clicks      = isset( $gsc_rows[ $id ] ) ? (int) $gsc_rows[ $id ]->clicks : 0;
				$impressions = isset( $gsc_rows[ $id ] ) ? (int) $gsc_rows[ $id ]->impressions : 0;
				$title       = get_the_title( $id );

				return array(
					'id'             => $id,
					'type'           => (string) $row['object_type'],
					'title'          => '' !== $title ? html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) : (string) $row['uri'],
					'url'            => (string) ( get_permalink( $id ) ? get_permalink( $id ) : home_url( (string) $row['uri'] ) ),
					'edit_url'       => (string) get_edit_post_link( $id, 'raw' ),
					'views'          => (int) $row['views'],
					'entries'        => (int) $row['entries'],
					'search_entries' => (int) $row['search_entries'],
					'clicks'         => $clicks,
					'impressions'    => $impressions,
					'ctr'            => Metrics::ctr( $clicks, $impressions ),
					'position'       => isset( $gsc_rows[ $id ] ) ? Metrics::weighted_position( (float) $gsc_rows[ $id ]->pos_x_impr, $impressions ) : null,
				);
			},
			$rows
		);
	}

	/**
	 * Periodo coperto da ciascuna tabella, per spiegare i buchi nei grafici.
	 *
	 * @return array<string,array{from:string|null,to:string|null,rows:int}>
	 */
	public function coverage(): array {
		global $wpdb;

		$coverage = array();
		foreach ( Schema::TABLES as $name ) {
			$row               = $wpdb->get_row( 'SELECT MIN(day) AS d_from, MAX(day) AS d_to, COUNT(*) AS n FROM ' . Schema::table( $name ) );
			$coverage[ $name ] = array(
				'from' => $row->d_from ?? null,
				'to'   => $row->d_to ?? null,
				'rows' => (int) ( $row->n ?? 0 ),
			);
		}
		return $coverage;
	}
}
