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
	 * Per ogni contenuto: visitatori di oggi, di ieri e del periodo, e IP diversi. La sorgente si sceglie per giorno:
	 * - dal primo giorno di `wp5w_hits` in poi le visite grezze (raccolta propria, aggiornate a ogni visita);
	 * - prima, se il periodo ci arriva, `referrer_daily` con la regola dell'origine.
	 * Oggi e ieri vengono quindi sempre dalla stessa sorgente del totale, che non può essere più basso della loro somma.
	 * Un visitatore conta una volta per contenuto e per giorno, come in `referrer_daily` (il primo giorno di raccolta è parziale
	 * ma viene dalle visite grezze, proprio per non rompere questa regola). Il referrer delle visite grezze è nella forma
	 * di Referrers::hits_key() (WP Statistics salva le app come `android-app://pacchetto/`), quindi `referrer_daily` si legge
	 * con la stessa normalizzazione (Channels::host_sql()).
	 * Gli IP diversi si contano solo sulle visite con l'hash dell'IP (schema v7, entro `ip_retention_days`): null se non ce ne sono.
	 *
	 * @param string $referrer Referrer come salvato (host o `android-app://...`), non l'etichetta.
	 * @param int    $days     Giorni fino a oggi compreso (1 = solo oggi).
	 * @return array<string,mixed>
	 */
	public function referrer_pages( string $referrer, int $days ): array {
		global $wpdb;

		// Passo 1: periodo, giorni di oggi e ieri e divisione tra aggregato e visite grezze.
		$today     = wp_date( 'Y-m-d' );
		$yesterday = DateRange::last_days( 2, $today )->from;
		$range     = DateRange::last_days( $days, $today );
		$key       = Referrers::hits_key( $referrer );
		$hits      = Schema::table( 'hits' );
		$first     = $wpdb->get_var( "SELECT MIN(day) FROM {$hits}" );
		$first     = is_string( $first ) && '' !== $first ? $first : null;

		[ $aggregate, $raw ] = $range->split_at( $first );

		/**
		 * Contenuti per chiave tipo|id.
		 *
		 * @var array<string,array{type:string,id:int,uri:string,today:int,yesterday:int,total:int,ips:?int}> $items
		 */
		$items = array();
		$add   = static function ( array $row, int $visitors, ?string $day ) use ( &$items, $today, $yesterday ): void {
			$k = $row['object_type'] . '|' . $row['object_id'];
			if ( ! isset( $items[ $k ] ) ) {
				$items[ $k ] = array(
					'type'      => (string) $row['object_type'],
					'id'        => (int) $row['object_id'],
					'uri'       => (string) $row['uri'],
					'today'     => 0,
					'yesterday' => 0,
					'total'     => 0,
					'ips'       => null,
				);
			}
			$items[ $k ]['total'] += $visitors;
			if ( $today === $day ) {
				$items[ $k ]['today'] += $visitors;
			} elseif ( $yesterday === $day ) {
				$items[ $k ]['yesterday'] += $visitors;
			}
			if ( '' === $items[ $k ]['uri'] ) {
				$items[ $k ]['uri'] = (string) $row['uri'];
			}
		};

		// Passo 2: giorni delle visite grezze, un visitatore per contenuto e per giorno.
		if ( $raw ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT object_type, object_id, day, MIN(uri) AS uri, COUNT(DISTINCT visitor) AS visitors FROM {$hits}
					WHERE is_entry = 1 AND referrer_host = %s AND day BETWEEN %s AND %s GROUP BY object_type, object_id, day",
					$key,
					$raw->from,
					$raw->to
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$add( $row, (int) $row['visitors'], (string) $row['day'] );
			}
		}

		// Passo 3: giorni precedenti alla raccolta propria, dall'aggregato con la regola dell'origine su entrambe le tabelle
		// (la riga di object_daily ha la stessa origine di quella di referrer_daily e dà l'URI dei contenuti che non sono post).
		if ( $aggregate ) {
			$own_since = Settings::own_since();
			$rows      = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT r.object_type, r.object_id, MIN(o.uri) AS uri, SUM(r.visitors) AS visitors
					FROM ' . Schema::table( 'referrer_daily' ) . ' r
					LEFT JOIN ' . Schema::table( 'object_daily' ) . ' o
						ON o.day = r.day AND o.object_type = r.object_type AND o.object_id = r.object_id AND o.origin = r.origin AND ' . Origin::where( $own_since, 'o' ) . '
					WHERE ' . Channels::host_sql( 'r.referrer' ) . ' = %s AND r.day BETWEEN %s AND %s AND ' . Origin::where( $own_since, 'r' ) . '
					GROUP BY r.object_type, r.object_id',
					$key,
					$aggregate->from,
					$aggregate->to
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$add( $row, (int) $row['visitors'], null );
			}
		}

		// Passo 4: IP diversi del periodo per contenuto e primo giorno con l'hash dell'IP.
		$ip_first = $wpdb->get_var( "SELECT MIN(day) FROM {$hits} WHERE ip_hash IS NOT NULL" );
		$ips      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_type, object_id, COUNT(DISTINCT ip_hash) AS ips FROM {$hits}
				WHERE is_entry = 1 AND referrer_host = %s AND day BETWEEN %s AND %s AND ip_hash IS NOT NULL GROUP BY object_type, object_id",
				$key,
				$range->from,
				$range->to
			),
			ARRAY_A
		);
		foreach ( (array) $ips as $row ) {
			$k = $row['object_type'] . '|' . $row['object_id'];
			if ( isset( $items[ $k ] ) ) {
				$items[ $k ]['ips'] = (int) $row['ips'];
			}
		}

		// Passo 5: dal più visitato (a parità per tipo e id), totali prima del taglio, titolo e link.
		uasort( $items, static fn( array $a, array $b ): int => array( $b['total'], $a['type'], $a['id'] ) <=> array( $a['total'], $b['type'], $b['id'] ) );
		$out = array();
		foreach ( array_slice( $items, 0, self::PAGES_LIMIT ) as $item ) {
			$out[] = array(
				'type'      => $item['type'],
				'id'        => $item['id'],
				'today'     => $raw && $raw->to >= $today ? $item['today'] : null,
				'yesterday' => $days > 1 && $raw && $raw->from <= $yesterday ? $item['yesterday'] : null,
				'visitors'  => $item['total'],
				'ips'       => $item['ips'],
			) + OnlineQuery::describe( $item['type'], $item['id'], $item['uri'] );
		}

		return array(
			'range'    => array(
				'from' => $range->from,
				'to'   => $range->to,
			),
			'live'     => 1 === $days,
			'referrer' => $referrer,
			'label'    => Referrers::label( $referrer ),
			'sources'  => array(
				'aggregate' => $aggregate ? array(
					'from' => $aggregate->from,
					'to'   => $aggregate->to,
				) : null,
				'raw'       => $raw ? array(
					'from' => $raw->from,
					'to'   => $raw->to,
				) : null,
			),
			'ip_from'  => is_string( $ip_first ) && '' !== $ip_first ? max( $range->from, $ip_first ) : null,
			'totals'   => array(
				'visitors' => array_sum( array_column( $items, 'total' ) ),
				'contents' => count( $items ),
			),
			'rows'     => $out,
		);
	}
}
