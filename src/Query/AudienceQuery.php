<?php
/**
 * Dati della scheda Visitatori.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Query;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Settings;
use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\Months;
use Mavida\FiveW\Support\Origin;
use Mavida\FiveW\Support\Series;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin, nomi costruiti con $wpdb->prefix in Schema::table().

/**
 * Ultimi 7 giorni, ultimi 6 mesi, referrer e keyword recenti.
 */
final class AudienceQuery {

	/**
	 * Mesi dell'analisi "ultimi 6 mesi", compreso quello in corso.
	 *
	 * @var int
	 */
	private const MONTHS = 6;

	/**
	 * Righe delle tabelle referrer e keyword.
	 *
	 * @var int
	 */
	private const LIMIT = 50;

	/**
	 * Costruttore.
	 *
	 * @param SearchQuery $search Query di Search Console (riuso della tabella query con variazione).
	 */
	public function __construct( private readonly SearchQuery $search ) {}

	/**
	 * Tutti i dati della scheda.
	 *
	 * @return array<string,mixed>
	 */
	public function get(): array {
		global $wpdb;

		$today = wp_date( 'Y-m-d' );
		$week  = DateRange::last_days( 7, $today );
		$half  = new DateRange( Months::first_day( $today, self::MONTHS ), $today );
		$site  = Schema::table( 'site_daily' );

		// Regola dell'origine: solo righe wps, o own dalla data di passaggio.
		$origin = Origin::where( Settings::own_since() );

		$week_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT day, visitors, views FROM {$site} WHERE day BETWEEN %s AND %s AND {$origin}", $week->from, $week->to ),
			OBJECT_K
		);
		$half_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT day, visitors, views FROM {$site} WHERE day BETWEEN %s AND %s AND {$origin}", $half->from, $half->to ),
			ARRAY_A
		);

		// Keyword: gli ultimi 7 giorni presenti in Search Console (i dati arrivano con 2-3 giorni di ritardo).
		$last_gsc = $wpdb->get_var( 'SELECT MAX(day) FROM ' . Schema::table( 'gsc_daily' ) );
		$gsc_week = $last_gsc ? DateRange::last_days( 7, (string) $last_gsc ) : null;

		// Variazione rispetto ai 7 giorni precedenti solo se coperti dai dati (come in SearchQuery::get()).
		$comparable = $gsc_week && $this->search->first_day() <= $gsc_week->previous()->from;

		return array(
			'week'      => array(
				'range' => array(
					'from' => $week->from,
					'to'   => $week->to,
				),
				'days'  => Series::fill( $week, $week_rows, array( 'visitors', 'views' ) ),
			),
			'half_year' => array(
				'range'  => array(
					'from' => $half->from,
					'to'   => $half->to,
				),
				'months' => Months::group( $half_rows, $half->from, self::MONTHS ),
			),
			'referrers' => array(
				'range' => array(
					'from' => $week->from,
					'to'   => $week->to,
				),
				'rows'  => $this->referrers( $week ),
			),
			'keywords'  => array(
				'range'      => $gsc_week ? array(
					'from' => $gsc_week->from,
					'to'   => $gsc_week->to,
				) : null,
				'comparable' => $comparable,
				'rows'       => $gsc_week ? $this->search->queries( $gsc_week, $gsc_week->previous(), self::LIMIT ) : array(),
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

		// Regola dell'origine: solo righe wps, o own dalla data di passaggio.
		$origin = Origin::where( Settings::own_since() );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT referrer, MAX(source) AS source, MAX(channel) AS channel, SUM(visitors) AS visitors,
					COUNT(DISTINCT object_type, object_id) AS contents
				FROM ' . Schema::table( 'referrer_daily' ) . ' WHERE day BETWEEN %s AND %s AND ' . $origin . '
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
}
