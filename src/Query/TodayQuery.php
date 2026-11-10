<?php
/**
 * Dati della scheda «Oggi».
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Query;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Settings;
use Mavida\FiveW\Support\Channels;
use Mavida\FiveW\Support\LocalTime;
use Mavida\FiveW\Support\Metrics;
use Mavida\FiveW\Support\Referrers;
use Mavida\FiveW\Support\TodayWindow;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin, nomi costruiti con $wpdb->prefix in Schema::table().

/**
 * Visitatori e visualizzazioni di oggi con il confronto con ieri e con le medie degli ultimi 7 e 30 giorni, più le pagine viste oggi.
 *
 * Legge solo `wp5w_hits`, che è sempre raccolta propria (nessuna regola dell'origine, come OnlineQuery): conteggi identici
 * a quelli di OwnSource (visitatori distinti e visite, bot compresi): non sono i numeri di WP Statistics e, finché l'aggregazione oraria
 * non ha scritto oggi, nemmeno quelli della Panoramica.
 * Il confronto è alla stessa ora del giorno (la giornata in corso non si confronta con giornate intere) e porta con sé,
 * come riferimento, anche il totale della giornata intera.
 */
final class TodayQuery {

	/**
	 * Finestre di confronto: chiave => giorni interi precedenti a oggi.
	 *
	 * @var array<string,int>
	 */
	private const WINDOWS = array(
		'yesterday' => 1,
		'avg7'      => 7,
		'avg30'     => 30,
	);

	/**
	 * Dati per la rotta /admin/today.
	 *
	 * @param int|null $now Istante di riferimento (timestamp UNIX), solo per i test: predefinito l'ora attuale.
	 * @return array<string,mixed>
	 */
	public function get( ?int $now = null ): array {
		global $wpdb;

		$now ??= time();
		$hits = Schema::table( 'hits' );

		// Passo 1: oggi e ora locale del sito, primo giorno di raccolta e giorni della finestra più lunga.
		$today = wp_date( 'Y-m-d', $now );
		$time  = wp_date( 'H:i:s', $now );
		$first = $wpdb->get_var( "SELECT MIN(day) FROM {$hits}" );
		$first = is_string( $first ) && '' !== $first ? $first : null;
		$from  = TodayWindow::days( $today, $first, max( self::WINDOWS ) )[0] ?? $today;

		// Passo 2: una sola query per giorno. L'ora locale si ricava da `ts` (UTC) con le transizioni del fuso del sito,
		// perché la finestra mescola giorni con ora legale e solare (niente scostamento fisso).
		$local = LocalTime::shift_sql( 'ts', LocalTime::transitions( wp_timezone(), $now - 32 * DAY_IN_SECONDS, $now ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, COUNT(DISTINCT visitor) AS visitors, COUNT(*) AS views,
					COUNT(DISTINCT IF( TIME( {$local} ) <= TIME(%s), visitor, NULL )) AS visitors_at,
					SUM( TIME( {$local} ) <= TIME(%s) ) AS views_at
				FROM {$hits} WHERE day BETWEEN %s AND %s GROUP BY day",
				$time,
				$time,
				$from,
				$today
			),
			ARRAY_A
		);

		// Passo 3: valori per giorno, alla stessa ora (`at`) e sulla giornata intera (`full`).
		$at   = array(
			'visitors' => array(),
			'views'    => array(),
		);
		$full = $at;
		foreach ( (array) $rows as $row ) {
			$day                      = (string) $row['day'];
			$at['visitors'][ $day ]   = (int) $row['visitors_at'];
			$at['views'][ $day ]      = (int) $row['views_at'];
			$full['visitors'][ $day ] = (int) $row['visitors'];
			$full['views'][ $day ]    = (int) $row['views'];
		}

		// Passo 4: KPI. Il valore di oggi è quello alla stessa ora (in produzione è tutto ciò che c'è fino a adesso).
		$kpis = array();
		foreach ( array( 'visitors', 'views' ) as $metric ) {
			$value   = $at[ $metric ][ $today ] ?? 0;
			$compare = array();
			foreach ( self::WINDOWS as $key => $size ) {
				$days            = TodayWindow::days( $today, $first, $size );
				$mean_at         = TodayWindow::mean( $at[ $metric ], $days );
				$mean_full       = TodayWindow::mean( $full[ $metric ], $days );
				$compare[ $key ] = array(
					'days'   => count( $days ),
					'at'     => null === $mean_at ? null : round( $mean_at, 1 ),
					'full'   => null === $mean_full ? null : round( $mean_full, 1 ),
					'change' => null === $mean_at ? null : Metrics::change( $value, $mean_at ),
				);
			}
			$kpis[ $metric ] = array(
				'value'   => $value,
				'full'    => $full[ $metric ][ $today ] ?? 0,
				'compare' => $compare,
			);
		}

		return array(
			'day'       => $today,
			'at'        => $time,
			'tracking'  => Settings::get()['own_tracking'],
			'first_day' => $first,
			'kpis'      => $kpis,
			'hourly'    => $this->hourly( $today, $local, (int) substr( $time, 0, 2 ), TodayWindow::days( $today, $first, self::WINDOWS['avg7'] ) ),
			'pages'     => $this->pages( $today, $full['visitors'][ $today ] ?? 0 ),
		);
	}

	/**
	 * Andamento orario cumulato di oggi, di ieri e della media degli ultimi 7 giorni (visitatori e visualizzazioni), per il grafico.
	 *
	 * Un visitatore conta nell'ora della sua prima visita del giorno, così a ogni ora il valore cumulato è il numero di visitatori
	 * distinti fino a quel momento (come il KPI). L'ora è quella locale del sito, ricavata da `ts` con la stessa espressione di get().
	 * Oggi si ferma all'ora in corso. La media usa gli stessi giorni del KPI `avg7` (un giorno senza visite conta zero), quindi
	 * il suo valore delle 23 è la media della giornata intera; null se non c'è ancora nessun giorno intero.
	 * I valori di ogni singola ora (le barre del grafico) sono le differenze tra due ore consecutive e li calcola il frontend.
	 *
	 * @param string            $today        Oggi (Y-m-d).
	 * @param string            $local        Espressione SQL dell'ora locale di `ts` (LocalTime::shift_sql()).
	 * @param int               $current_hour Ora locale in corso (0-23).
	 * @param array<int,string> $avg_days     Giorni della media di 7 giorni (TodayWindow::days()).
	 * @return array<string,array{visitors:array<int,int|float|null>,views:array<int,int|float|null>}|null>
	 */
	private function hourly( string $today, string $local, int $current_hour, array $avg_days ): array {
		global $wpdb;

		$hits = Schema::table( 'hits' );
		// Ieri con date su stringhe in UTC: 24 ore prima non è sempre il giorno prima nei giorni di cambio dell'ora.
		$yesterday = gmdate( 'Y-m-d', (int) strtotime( $today . ' UTC' ) - DAY_IN_SECONDS );

		// Passo 1: visualizzazioni per giorno e ora, poi visitatori per giorno e ora della prima visita, su oggi, ieri e i giorni della media.
		$days         = array_values( array_unique( array_merge( array( $yesterday, $today ), $avg_days ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $days ), '%s' ) );
		$views        = $wpdb->get_results(
			$wpdb->prepare( "SELECT day, HOUR( {$local} ) AS hour, COUNT(*) AS n FROM {$hits} WHERE day IN ({$placeholders}) GROUP BY day, hour", $days ), // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Segnaposto generati per ogni giorno.
			ARRAY_A
		);
		$visitors     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, hour, COUNT(*) AS n FROM (
					SELECT day, visitor, HOUR( MIN( {$local} ) ) AS hour FROM {$hits} WHERE day IN ({$placeholders}) GROUP BY day, visitor
				) first_hits GROUP BY day, hour", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Segnaposto generati per ogni giorno.
				$days
			),
			ARRAY_A
		);

		// Passo 2: valori per giorno e ora.
		$by = array(
			'visitors' => array(),
			'views'    => array(),
		);
		foreach ( (array) $visitors as $row ) {
			$by['visitors'][ (string) $row['day'] ][ (int) $row['hour'] ] = (int) $row['n'];
		}
		foreach ( (array) $views as $row ) {
			$by['views'][ (string) $row['day'] ][ (int) $row['hour'] ] = (int) $row['n'];
		}

		// Passo 3: media ora per ora dei cumulati dei giorni della media, con due decimali (il frontend ne ricava i valori orari:
		// con un decimale le differenze tra due ore sbaglierebbero di 0,1).
		$avg7 = null;
		if ( array() !== $avg_days ) {
			$avg7 = array();
			foreach ( array( 'visitors', 'views' ) as $metric ) {
				$sum = array_fill( 0, 24, 0 );
				foreach ( $avg_days as $day ) {
					foreach ( TodayWindow::cumulate( $by[ $metric ][ $day ] ?? array() ) as $hour => $value ) {
						$sum[ $hour ] += (int) $value;
					}
				}
				$avg7[ $metric ] = array_map( static fn( int $total ): float => round( $total / count( $avg_days ), 2 ), $sum );
			}
		}

		// Passo 4: cumulati; oggi si ferma all'ora in corso, ieri è completo.
		return array(
			'today'     => array(
				'visitors' => TodayWindow::cumulate( $by['visitors'][ $today ] ?? array(), $current_hour ),
				'views'    => TodayWindow::cumulate( $by['views'][ $today ] ?? array(), $current_hour ),
			),
			'yesterday' => array(
				'visitors' => TodayWindow::cumulate( $by['visitors'][ $yesterday ] ?? array() ),
				'views'    => TodayWindow::cumulate( $by['views'][ $yesterday ] ?? array() ),
			),
			'avg7'      => $avg7,
		);
	}

	/**
	 * Pagine viste oggi con visualizzazioni, visitatori e visualizzazioni per canale di arrivo.
	 *
	 * Ogni visualizzazione prende il canale con cui il visitatore è arrivato oggi (la sua prima visita del giorno), con la stessa
	 * regola della dimensione `channel` di OwnSource: `bot` ha la precedenza su `ai`, e `ai` sul canale di WP Statistics
	 * (senza `ai_sql()` chatgpt.com finirebbe in «Motori di ricerca»). Quindi totale = diretti + ricerca + bot + altri.
	 *
	 * @param string $today    Oggi (Y-m-d).
	 * @param int    $visitors Visitatori distinti di oggi (non si sommano dalle pagine: uno può vederne più d'una).
	 * @return array{rows:array<int,array<string,mixed>>,totals:array<string,int>}
	 */
	private function pages( string $today, int $visitors ): array {
		global $wpdb;

		$hits     = Schema::table( 'hits' );
		$arrivals = $this->arrivals_sql();

		// Passo 1: canale di ogni visitatore dalla sua prima visita di oggi, poi le visite di oggi per contenuto.
		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Il primo %s sta in arrivals_sql().
				"SELECT h.object_type, h.object_id, MIN(h.uri) AS uri, COUNT(*) AS views, COUNT(DISTINCT h.visitor) AS visitors,
					COUNT(IF( v.ch = 'direct', 1, NULL )) AS direct,
					COUNT(IF( v.ch = 'search', 1, NULL )) AS search,
					COUNT(IF( v.ch = 'bot', 1, NULL )) AS bot
				FROM {$hits} h
				JOIN ( {$arrivals} ) v ON v.visitor = h.visitor
				WHERE h.day = %s
				GROUP BY h.object_type, h.object_id
				ORDER BY views DESC, h.object_type, h.object_id",
				$today,
				$today
			),
			ARRAY_A
		);

		// Passo 2: titolo e link, «altri» per differenza e totali.
		$totals = array(
			'views'    => 0,
			'visitors' => $visitors,
			'direct'   => 0,
			'search'   => 0,
			'bot'      => 0,
			'other'    => 0,
		);
		$pages  = array();
		foreach ( (array) $rows as $row ) {
			$type   = (string) $row['object_type'];
			$id     = (int) $row['object_id'];
			$views  = (int) $row['views'];
			$direct = (int) $row['direct'];
			$search = (int) $row['search'];
			$bot    = (int) $row['bot'];
			$other  = $views - $direct - $search - $bot;

			$pages[] = array(
				'type'     => $type,
				'id'       => $id,
				'views'    => $views,
				'visitors' => (int) $row['visitors'],
				'direct'   => $direct,
				'search'   => $search,
				'bot'      => $bot,
				'other'    => $other,
			) + OnlineQuery::describe( $type, $id, (string) $row['uri'] );

			$totals['views']  += $views;
			$totals['direct'] += $direct;
			$totals['search'] += $search;
			$totals['bot']    += $bot;
			$totals['other']  += $other;
		}

		return array(
			'rows'   => $pages,
			'totals' => $totals,
		);
	}

	/**
	 * Da dove sono arrivati i visitatori di una pagina vista oggi (modale della scheda Oggi).
	 *
	 * Stessa regola della tabella «Pagine viste oggi» (arrivals_sql()): ogni visualizzazione prende la provenienza della prima visita
	 * del giorno del suo visitatore, anche se quella visita era su un'altra pagina. Quindi chi entra da Google sulla home e poi apre
	 * l'articolo compare sotto Google, e le righe tornano con la tabella: la somma delle visualizzazioni è `views` della pagina e quella
	 * dei visitatori è `visitors` (a ogni visitatore corrisponde una sola riga, perché la prima visita del giorno è una sola).
	 * Le righe sono per canale, sito di provenienza ed etichetta del bot (i bot hanno il canale `bot` con la loro firma).
	 * Legge solo `wp5w_hits`. Gli IP diversi sono quelli delle visite alla pagina con l'hash dell'IP (null se nessuna lo ha).
	 *
	 * @param string   $type Tipo di contenuto.
	 * @param int      $id   ID del contenuto.
	 * @param int|null $now  Istante di riferimento (timestamp UNIX), solo per i test: predefinito l'ora attuale.
	 * @return array<string,mixed>
	 */
	public function page_sources( string $type, int $id, ?int $now = null ): array {
		global $wpdb;

		$today    = wp_date( 'Y-m-d', $now ?? time() );
		$hits     = Schema::table( 'hits' );
		$arrivals = $this->arrivals_sql();

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Il primo %s sta in arrivals_sql().
				"SELECT v.ch, v.host, v.bot, MAX(v.src) AS src, MIN(h.uri) AS uri, COUNT(*) AS views, COUNT(DISTINCT h.visitor) AS visitors,
					COUNT(DISTINCT h.ip_hash) AS ips
				FROM {$hits} h
				JOIN ( {$arrivals} ) v ON v.visitor = h.visitor
				WHERE h.day = %s AND h.object_type = %s AND h.object_id = %d
				GROUP BY v.ch, v.host, v.bot
				ORDER BY visitors DESC, views DESC, v.ch, v.host, v.bot",
				$today,
				$today,
				$type,
				$id
			),
			ARRAY_A
		);

		$items  = array();
		$totals = array(
			'views'    => 0,
			'visitors' => 0,
		);
		$uri    = '';
		foreach ( (array) $rows as $row ) {
			$host                = (string) $row['host'];
			$ips                 = (int) $row['ips'];
			$uri                 = '' !== $uri ? $uri : (string) $row['uri'];
			$items[]             = array(
				'channel'  => (string) $row['ch'],
				'referrer' => $host,
				'label'    => '' === $host ? '' : Referrers::label( $host ),
				'bot'      => (string) $row['bot'],
				'source'   => (string) $row['src'],
				'visitors' => (int) $row['visitors'],
				'views'    => (int) $row['views'],
				'ips'      => $ips > 0 ? $ips : null,
			);
			$totals['views']    += (int) $row['views'];
			$totals['visitors'] += (int) $row['visitors'];
		}

		return array(
			'day'     => $today,
			'content' => array(
				'type' => $type,
				'id'   => $id,
			) + OnlineQuery::describe( $type, $id, $uri ),
			'totals'  => $totals,
			'rows'    => $items,
		);
	}

	/**
	 * Tabella derivata con la provenienza di ogni visitatore di oggi, dalla sua prima visita del giorno.
	 *
	 * Colonne: `visitor`, `host` (referrer), `bot` (etichetta, vuota per le persone), `ch` (canale: `bot` ha la precedenza su `ai`, e `ai` sul canale
	 * di WP Statistics, come la dimensione `channel` di OwnSource; senza `ai_sql()` chatgpt.com finirebbe in «Motori di ricerca») e `src`
	 * (nome della sorgente, quello dell'assistente AI se lo è). Contiene un solo segnaposto `%s` per il giorno: lo passa il chiamante,
	 * perché annidare due `prepare()` lascerebbe i `%` dei `LIKE` dell'elenco AI senza protezione.
	 * Usata dalla tabella delle pagine e dalla modale, così le due non possono divergere.
	 *
	 * @return string
	 */
	private function arrivals_sql(): string {
		$hits = Schema::table( 'hits' );
		$ai   = Channels::ai_sql( 'f.referrer_host', 'f.utm_source' );

		return "SELECT f.visitor, f.referrer_host AS host, f.bot, IF( f.bot <> '', 'bot', IF( {$ai} <> '', 'ai', f.channel ) ) AS ch,
				IF( {$ai} <> '', {$ai}, f.source ) AS src
			FROM {$hits} f
			JOIN ( SELECT MIN(id) AS id FROM {$hits} WHERE day = %s GROUP BY visitor ) m ON m.id = f.id";
	}
}
