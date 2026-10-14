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
use Mavida\FiveW\Support\Dimensions;
use Mavida\FiveW\Support\Months;
use Mavida\FiveW\Support\Origin;
use Mavida\FiveW\Support\Referrers;
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
	 * Dimensioni UTM in `dim_daily` (solo righe own, fuori dalla regola «stessa somma per ogni dimensione»).
	 *
	 * @var array<int,string>
	 */
	private const UTM_DIMS = array( 'utm_campaign', 'utm_source', 'utm_medium' );

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
	 * Paesi, dispositivi, browser e sistemi operativi del periodo (box «Pubblico»).
	 *
	 * Una persona conta una volta per ogni giorno di visita (come in `dim_daily`), quindi i visitatori
	 * sommati su più giorni non sono persone uniche.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array{range:array{from:string,to:string},first_day:?string,dims:array<string,array<string,mixed>>}
	 */
	public function dimensions( DateRange $range ): array {
		global $wpdb;

		$table = Schema::table( 'dim_daily' );
		$dims  = "'" . implode( "','", Dimensions::DIMS ) . "'";

		// Regola dell'origine: solo righe wps, o own dalla data di passaggio (anche per il primo giorno con dati).
		$origin = Origin::where( Settings::own_since() );

		// Una sola SELECT per le quattro dimensioni.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT dim, value, SUM(visitors) AS visitors FROM {$table} WHERE day BETWEEN %s AND %s AND dim IN ({$dims}) AND {$origin} GROUP BY dim, value",
				$range->from,
				$range->to
			),
			ARRAY_A
		);
		$first = $wpdb->get_var( "SELECT MIN(day) FROM {$table} WHERE dim IN ({$dims}) AND {$origin}" );

		// Valori per dimensione (le quattro chiavi ci sono sempre, anche vuote).
		$values = array_fill_keys( Dimensions::DIMS, array() );
		foreach ( $rows as $row ) {
			$values[ $row['dim'] ][ (string) $row['value'] ] = (int) $row['visitors'];
		}

		return array(
			'range'     => array(
				'from' => $range->from,
				'to'   => $range->to,
			),
			'first_day' => $first ? (string) $first : null,
			'dims'      => array_map( static fn( array $items ): array => Dimensions::top( $items ), $values ),
		);
	}

	/**
	 * Campagne, sorgenti e mezzi UTM del periodo (box «Campagne»).
	 *
	 * ECCEZIONE ALLA REGOLA DELL'ORIGINE (come CompareQuery): WP Statistics non salva i parametri UTM, quindi le
	 * dimensioni utm_* esistono solo nelle righe own e si leggono sempre, qualunque sia wp5w_own_since.
	 * Per questo il primo giorno di dati è il primo giorno own di `dim_daily` (dimensione `channel`), non il primo
	 * giorno con UTM: un box vuoto vuol dire «nessuna campagna», non «nessun dato».
	 * Un visitatore conta una volta per ogni valore e per ogni giorno; chi arriva senza UTM non compare.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array{range:array{from:string,to:string},first_day:?string,dims:array<string,array<string,mixed>>}
	 */
	public function campaigns( DateRange $range ): array {
		global $wpdb;

		$table = Schema::table( 'dim_daily' );
		$dims  = "'" . implode( "','", self::UTM_DIMS ) . "'";

		// Una sola SELECT per le tre dimensioni, solo righe own.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT dim, value, SUM(visitors) AS visitors FROM {$table} WHERE origin = 'own' AND dim IN ({$dims}) AND day BETWEEN %s AND %s GROUP BY dim, value",
				$range->from,
				$range->to
			),
			ARRAY_A
		);
		$first = $wpdb->get_var( "SELECT MIN(day) FROM {$table} WHERE origin = 'own' AND dim = 'channel'" );

		// Valori per dimensione (le tre chiavi ci sono sempre, anche vuote).
		$values = array_fill_keys( self::UTM_DIMS, array() );
		foreach ( $rows as $row ) {
			$values[ $row['dim'] ][ (string) $row['value'] ] = (int) $row['visitors'];
		}

		return array(
			'range'     => array(
				'from' => $range->from,
				'to'   => $range->to,
			),
			'first_day' => $first ? (string) $first : null,
			'dims'      => array_map( static fn( array $items ): array => Dimensions::top( $items ), $values ),
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
				GROUP BY referrer ORDER BY visitors DESC, referrer LIMIT %d',
				$range->from,
				$range->to,
				self::LIMIT
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'referrer' => (string) $row['referrer'],
				'label'    => Referrers::label( (string) $row['referrer'] ),
				'source'   => (string) $row['source'],
				'channel'  => (string) $row['channel'],
				'visitors' => (int) $row['visitors'],
				'contents' => (int) $row['contents'],
			),
			$rows
		);
	}
}
