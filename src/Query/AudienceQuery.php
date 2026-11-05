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
use Mavida\FiveW\Support\Channels;
use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\Dimensions;
use Mavida\FiveW\Support\Months;
use Mavida\FiveW\Support\Origin;
use Mavida\FiveW\Support\Referrers;
use Mavida\FiveW\Support\Series;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin, nomi costruiti con $wpdb->prefix in Schema::table().

/**
 * Ultimi 7 giorni, ultimi 6 mesi, keyword recenti, siti di provenienza e contenuti raggiunti da ciascuno.
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
	 * Righe del dettaglio di un sito di provenienza (contenuti raggiunti).
	 *
	 * @var int
	 */
	private const PAGES_LIMIT = 100;

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
	 * Siti di provenienza degli ultimi N giorni (box «Provenienza»).
	 *
	 * Con 7, 30 e 90 giorni legge `referrer_daily` con la regola dell'origine: oggi è parziale (le righe own arrivano con
	 * l'aggregazione oraria) o assente (le righe wps arrivano con l'ETL notturno di domani).
	 * Con 1 giorno («Oggi») legge `wp5w_hits`, come TodayQuery: sempre raccolta propria e in tempo reale, quindi nessuna regola
	 * dell'origine. La SELECT interna è quella con cui OwnSource scrive `referrer_daily` (ingressi con un referrer esterno,
	 * visitatori distinti per contenuto, canale «ai» con Channels::ai_sql()), così i numeri coincidono con quelli che
	 * l'aggregazione oraria scriverà per oggi.
	 * Un visitatore conta una volta per contenuto e per giorno (come in `referrer_daily`).
	 *
	 * @param int $days Giorni fino a oggi compreso (1 = solo oggi).
	 * @return array{range:array{from:string,to:string},live:bool,rows:array<int,array<string,mixed>>}
	 */
	public function referrers( int $days ): array {
		global $wpdb;

		$range = DateRange::last_days( $days, wp_date( 'Y-m-d' ) );
		$live  = 1 === $days;

		if ( $live ) {
			$ai  = Channels::ai_sql( 'referrer_host', 'utm_source' );
			$sql = $wpdb->prepare(
				"SELECT referrer, MAX(source) AS source, MAX(channel) AS channel, SUM(visitors) AS visitors, COUNT(*) AS contents
				FROM (
					SELECT referrer_host AS referrer, MAX(IF( {$ai} <> '', 'ai', channel )) AS channel, MAX(IF( {$ai} <> '', {$ai}, source )) AS source,
						COUNT(DISTINCT visitor) AS visitors
					FROM " . Schema::table( 'hits' ) . " WHERE is_entry = 1 AND referrer_host <> '' AND day = %s
					GROUP BY object_type, object_id, referrer_host
				) t
				GROUP BY referrer ORDER BY visitors DESC, referrer LIMIT %d",
				$range->to,
				self::LIMIT
			);
		} else {
			// Regola dell'origine: solo righe wps, o own dalla data di passaggio.
			$origin = Origin::where( Settings::own_since() );
			$sql    = $wpdb->prepare(
				'SELECT referrer, MAX(source) AS source, MAX(channel) AS channel, SUM(visitors) AS visitors,
					COUNT(DISTINCT object_type, object_id) AS contents
				FROM ' . Schema::table( 'referrer_daily' ) . ' WHERE day BETWEEN %s AND %s AND ' . $origin . '
				GROUP BY referrer ORDER BY visitors DESC, referrer LIMIT %d',
				$range->from,
				$range->to,
				self::LIMIT
			);
		}

		return array(
			'range' => array(
				'from' => $range->from,
				'to'   => $range->to,
			),
			'live'  => $live,
			'rows'  => array_map(
				static fn( array $row ): array => array(
					'referrer' => (string) $row['referrer'],
					'label'    => Referrers::label( (string) $row['referrer'] ),
					'source'   => (string) $row['source'],
					'channel'  => (string) $row['channel'],
					'visitors' => (int) $row['visitors'],
					'contents' => (int) $row['contents'],
				),
				(array) $wpdb->get_results( $sql, ARRAY_A )
			),
		);
	}

	/**
	 * Contenuti raggiunti da un sito di provenienza negli ultimi N giorni, dal più visitato (modale del box «Provenienza»).
	 *
	 * Stesse tabelle e stesse regole di referrers(): `referrer_daily` per 7, 30 e 90 giorni, `wp5w_hits` per «Oggi».
	 * Il conteggio sono i visitatori entrati da quel sito (un visitatore per contenuto e per giorno): `referrer_daily` non ha
	 * le visualizzazioni, quindi la somma delle righe è il numero della tabella dei siti.
	 * `referrer_daily` non ha l'URI: per titolo e link dei contenuti che non sono post (home, archivi) viene da `object_daily`
	 * dello stesso giorno e della stessa origine.
	 *
	 * @param string $referrer Referrer come salvato (host o `android-app://...`), non l'etichetta.
	 * @param int    $days     Giorni fino a oggi compreso (1 = solo oggi).
	 * @return array{range:array{from:string,to:string},live:bool,referrer:string,label:string,rows:array<int,array<string,mixed>>}
	 */
	public function referrer_pages( string $referrer, int $days ): array {
		global $wpdb;

		$range = DateRange::last_days( $days, wp_date( 'Y-m-d' ) );
		$live  = 1 === $days;

		if ( $live ) {
			$sql = $wpdb->prepare(
				'SELECT object_type, object_id, MIN(uri) AS uri, COUNT(DISTINCT visitor) AS visitors FROM ' . Schema::table( 'hits' ) . '
				WHERE is_entry = 1 AND referrer_host = %s AND day = %s
				GROUP BY object_type, object_id ORDER BY visitors DESC, object_type, object_id LIMIT %d',
				$referrer,
				$range->to,
				self::PAGES_LIMIT
			);
		} else {
			// Regola dell'origine su entrambe le tabelle (la riga di object_daily ha già la stessa origine di quella di referrer_daily).
			$own_since = Settings::own_since();
			$sql       = $wpdb->prepare(
				'SELECT r.object_type, r.object_id, MIN(o.uri) AS uri, SUM(r.visitors) AS visitors
				FROM ' . Schema::table( 'referrer_daily' ) . ' r
				LEFT JOIN ' . Schema::table( 'object_daily' ) . ' o
					ON o.day = r.day AND o.object_type = r.object_type AND o.object_id = r.object_id AND o.origin = r.origin AND ' . Origin::where( $own_since, 'o' ) . '
				WHERE r.referrer = %s AND r.day BETWEEN %s AND %s AND ' . Origin::where( $own_since, 'r' ) . '
				GROUP BY r.object_type, r.object_id ORDER BY visitors DESC, r.object_type, r.object_id LIMIT %d',
				$referrer,
				$range->from,
				$range->to,
				self::PAGES_LIMIT
			);
		}

		$rows = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) {
			$type   = (string) $row['object_type'];
			$id     = (int) $row['object_id'];
			$rows[] = array(
				'type'     => $type,
				'id'       => $id,
				'visitors' => (int) $row['visitors'],
			) + OnlineQuery::describe( $type, $id, (string) $row['uri'] );
		}

		return array(
			'range'    => array(
				'from' => $range->from,
				'to'   => $range->to,
			),
			'live'     => $live,
			'referrer' => $referrer,
			'label'    => Referrers::label( $referrer ),
			'rows'     => $rows,
		);
	}
}
