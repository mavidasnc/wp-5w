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
		$zone        = wp_timezone();
		$start       = $now - 32 * DAY_IN_SECONDS;
		$transitions = $zone->getTransitions( $start, $now );
		// Un fuso a scostamento fisso (UTC o «+02:00») non ha transizioni e getTransitions() restituisce false: basta lo scostamento di oggi.
		if ( ! is_array( $transitions ) ) {
			$transitions = array(
				array(
					'ts'     => $start,
					'offset' => $zone->getOffset( new \DateTimeImmutable( '@' . $now ) ),
				),
			);
		}
		$local = LocalTime::shift_sql( 'ts', $transitions );
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
			'pages'     => $this->pages( $today, $full['visitors'][ $today ] ?? 0 ),
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

		$hits = Schema::table( 'hits' );
		$ai   = Channels::ai_sql( 'f.referrer_host', 'f.utm_source' );

		// Passo 1: canale di ogni visitatore dalla sua prima visita di oggi, poi le visite di oggi per contenuto.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.object_type, h.object_id, MIN(h.uri) AS uri, COUNT(*) AS views, COUNT(DISTINCT h.visitor) AS visitors,
					COUNT(IF( v.ch = 'direct', 1, NULL )) AS direct,
					COUNT(IF( v.ch = 'search', 1, NULL )) AS search,
					COUNT(IF( v.ch = 'bot', 1, NULL )) AS bot
				FROM {$hits} h
				JOIN (
					SELECT f.visitor, IF( f.bot <> '', 'bot', IF( {$ai} <> '', 'ai', f.channel ) ) AS ch
					FROM {$hits} f
					JOIN ( SELECT MIN(id) AS id FROM {$hits} WHERE day = %s GROUP BY visitor ) m ON m.id = f.id
				) v ON v.visitor = h.visitor
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
}
