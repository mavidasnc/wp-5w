<?php
/**
 * Dati della scheda Visitatori.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Query;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\Metrics;
use Mavida\FiveW\Support\Series;
use Mavida\FiveW\Support\Weekdays;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin, nomi costruiti con $wpdb->prefix in Schema::table().

/**
 * Giorni della settimana, referrer e keyword recenti.
 */
final class AudienceQuery {

	/**
	 * Giorni dell'analisi "ultimi 6 mesi".
	 *
	 * @var int
	 */
	private const HALF_YEAR_DAYS = 183;

	/**
	 * Righe delle tabelle referrer e keyword.
	 *
	 * @var int
	 */
	private const LIMIT = 50;

	/**
	 * Tutti i dati della scheda.
	 *
	 * @return array<string,mixed>
	 */
	public function get(): array {
		global $wpdb;

		$today = wp_date( 'Y-m-d' );
		$week  = DateRange::last_days( 7, $today );
		$half  = DateRange::last_days( self::HALF_YEAR_DAYS, $today );
		$site  = Schema::table( 'site_daily' );

		$week_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT day, visitors, views FROM {$site} WHERE day BETWEEN %s AND %s", $week->from, $week->to ),
			OBJECT_K
		);
		$half_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT day, visitors, views FROM {$site} WHERE day BETWEEN %s AND %s", $half->from, $half->to ),
			ARRAY_A
		);

		// Keyword: gli ultimi 7 giorni presenti in Search Console (i dati arrivano con 2-3 giorni di ritardo).
		$last_gsc = $wpdb->get_var( 'SELECT MAX(day) FROM ' . Schema::table( 'gsc_daily' ) );
		$gsc_week = $last_gsc ? DateRange::last_days( 7, (string) $last_gsc ) : null;

		return array(
			'week'      => array(
				'range' => array(
					'from' => $week->from,
					'to'   => $week->to,
				),
				'days'  => Series::fill( $week, $week_rows, array( 'visitors', 'views' ) ),
			),
			'half_year' => array(
				'range'    => array(
					'from' => $half->from,
					'to'   => $half->to,
				),
				'weekdays' => Weekdays::group( $half_rows ),
			),
			'referrers' => array(
				'range' => array(
					'from' => $week->from,
					'to'   => $week->to,
				),
				'rows'  => $this->referrers( $week ),
			),
			'keywords'  => array(
				'range' => $gsc_week ? array(
					'from' => $gsc_week->from,
					'to'   => $gsc_week->to,
				) : null,
				'rows'  => $gsc_week ? $this->keywords( $gsc_week ) : array(),
			),
		);
	}

	/**
	 * Siti di provenienza nel periodo.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array<int,array<string,mixed>>
	 */
	private function referrers( DateRange $range ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT referrer, MAX(source) AS source, MAX(channel) AS channel, SUM(visitors) AS visitors,
					COUNT(DISTINCT object_type, object_id) AS contents
				FROM ' . Schema::table( 'referrer_daily' ) . ' WHERE day BETWEEN %s AND %s
				GROUP BY referrer ORDER BY visitors DESC LIMIT %d',
				$range->from,
				$range->to,
				self::LIMIT
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'referrer' => (string) $row['referrer'],
				'source'   => (string) $row['source'],
				'channel'  => (string) $row['channel'],
				'visitors' => (int) $row['visitors'],
				'contents' => (int) $row['contents'],
			),
			$rows
		);
	}

	/**
	 * Query di ricerca nel periodo.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array<int,array<string,mixed>>
	 */
	private function keywords( DateRange $range ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT MIN(query) AS query, SUM(clicks) AS clicks, SUM(impressions) AS impressions, SUM(pos_x_impr) AS pos_x_impr
				FROM ' . Schema::table( 'gsc_daily' ) . ' WHERE day BETWEEN %s AND %s
				GROUP BY query_hash ORDER BY clicks DESC, impressions DESC LIMIT %d',
				$range->from,
				$range->to,
				self::LIMIT
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'query'       => (string) $row['query'],
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'ctr'         => Metrics::ctr( (int) $row['clicks'], (int) $row['impressions'] ),
				'position'    => Metrics::weighted_position( (float) $row['pos_x_impr'], (int) $row['impressions'] ),
			),
			$rows
		);
	}
}
