<?php
/**
 * Avvisi sulle visite anomale: calcolo nel passaggio orario e risultato salvato in un'opzione per la Panoramica.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Etl;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Query\OnlineQuery;
use Mavida\FiveW\Settings;
use Mavida\FiveW\Support\Anomaly;
use Mavida\FiveW\Support\Referrers;
use Mavida\FiveW\Support\TodayWindow;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabella del plugin, nome costruito con $wpdb->prefix in Schema::table().

/**
 * Legge solo `wp5w_hits` (raccolta propria). Coppie analizzate: (host del referrer, contenuto) sugli ingressi, con il referrer
 * vuoto come «(diretto)»; in più un avviso per ogni hash dell'IP con almeno M visite allo stesso contenuto nella finestra.
 * La regola è in Support\Anomaly. Le query filtrano anche su `day` (indice) oltre che su `ts` (senza indice).
 */
final class Anomalies {

	/**
	 * Opzione con l'ultimo calcolo (non caricata in automatico).
	 *
	 * @var string
	 */
	public const OPTION = 'wp5w_alerts';

	/**
	 * Avvisi e picchi al massimo per tipo, i più grandi.
	 *
	 * @var int
	 */
	private const LIMIT = 20;

	/**
	 * Calcola gli avvisi e li salva (passaggio orario e salvataggio delle Opzioni).
	 *
	 * Con la raccolta propria spenta l'opzione si svuota: gli avvisi di ieri non devono restare a vista.
	 * La data del primo avviso di una coppia (`since`) si conserva tra un calcolo e l'altro finché l'avviso resta attivo.
	 *
	 * @param int|null $now Istante di riferimento (timestamp UNIX), solo per i test.
	 * @return array<string,mixed>
	 */
	public static function run( ?int $now = null ): array {
		$result = Settings::get()['own_tracking'] ? self::compute( $now ) : array(
			'computed_at' => $now ?? time(),
			'alerts'      => array(),
			'peaks'       => array(),
		);

		// La data del primo avviso: quella salvata se la stessa chiave c'era già.
		$previous = get_option( self::OPTION, array() );
		$since    = array();
		foreach ( (array) ( $previous['alerts'] ?? array() ) as $alert ) {
			if ( is_array( $alert ) && isset( $alert['key'], $alert['since'] ) ) {
				$since[ (string) $alert['key'] ] = (int) $alert['since'];
			}
		}
		foreach ( $result['alerts'] as &$alert ) {
			$alert['since'] = $since[ $alert['key'] ] ?? $result['computed_at'];
		}
		unset( $alert );

		update_option( self::OPTION, $result, false );
		return $result;
	}

	/**
	 * Calcola avvisi e picchi senza salvarli (usato anche dalla calibrazione sui dati reali).
	 *
	 * @param int|null $now Istante di riferimento (timestamp UNIX): predefinito l'ora attuale.
	 * @return array{computed_at:int,alerts:array<int,array<string,mixed>>,peaks:array<int,array<string,mixed>>}
	 */
	public static function compute( ?int $now = null ): array {
		global $wpdb;

		$now     ??= time();
		$settings  = Settings::get();
		$hits      = Schema::table( 'hits' );
		$start     = $now - $settings['alert_hours'] * HOUR_IN_SECONDS;
		$start_utc = gmdate( 'Y-m-d H:i:s', $start );
		$now_utc   = gmdate( 'Y-m-d H:i:s', $now );
		$start_day = wp_date( 'Y-m-d', $start );
		$end_day   = wp_date( 'Y-m-d', $now );
		$window    = $wpdb->prepare( 'day BETWEEN %s AND %s AND ts >= %s AND ts < %s', $start_day, $end_day, $start_utc, $now_utc );
		$limits    = array(
			'countries' => $settings['alert_countries'],
			'share'     => $settings['alert_share'],
			'ip_visits' => $settings['alert_ip_visits'],
		);
		$result    = array(
			'computed_at' => $now,
			'alerts'      => array(),
			'peaks'       => array(),
		);

		// Passo 1: coppie della finestra con almeno MIN visitatori (di solito poche: le altre query si limitano a loro).
		$pairs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_host, object_type, object_id, MIN(uri) AS uri, COUNT(DISTINCT visitor) AS visitors, COUNT(DISTINCT NULLIF(country, '')) AS countries
				FROM {$hits} WHERE is_entry = 1 AND {$window}
				GROUP BY referrer_host, object_type, object_id HAVING visitors >= %d",
				$settings['alert_min_visitors']
			),
			ARRAY_A
		);

		if ( $pairs ) {
			// Passo 2: mediana dei visitatori al giorno nei B giorni prima della finestra (senza il primo giorno di raccolta, parziale).
			$first = $wpdb->get_var( "SELECT MIN(day) FROM {$hits}" );
			$days  = TodayWindow::days( $start_day, is_string( $first ) ? $first : null, $settings['alert_baseline_days'] );
			$base  = array();
			if ( $days ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT referrer_host, object_type, object_id, day, COUNT(DISTINCT visitor) AS visitors FROM {$hits}
						WHERE is_entry = 1 AND day BETWEEN %s AND %s GROUP BY referrer_host, object_type, object_id, day",
						$days[0],
						$days[ count( $days ) - 1 ]
					),
					ARRAY_A
				);
				foreach ( (array) $rows as $row ) {
					$base[ self::pair_key( $row ) ][ (string) $row['day'] ] = (int) $row['visitors'];
				}
			}

			// Passo 3: visitatori per browser + sistema e visite del singolo IP più attivo, per coppia.
			$combos = array();
			foreach ( (array) $wpdb->get_results( "SELECT referrer_host, object_type, object_id, CONCAT(browser, '|', os) AS combo, COUNT(DISTINCT visitor) AS visitors FROM {$hits} WHERE is_entry = 1 AND {$window} GROUP BY referrer_host, object_type, object_id, combo", ARRAY_A ) as $row ) {
				$combos[ self::pair_key( $row ) ][ (string) $row['combo'] ] = (int) $row['visitors'];
			}
			$ip_max = array();
			foreach ( (array) $wpdb->get_results( "SELECT referrer_host, object_type, object_id, MAX(n) AS n FROM ( SELECT referrer_host, object_type, object_id, COUNT(*) AS n FROM {$hits} WHERE is_entry = 1 AND ip_hash IS NOT NULL AND {$window} GROUP BY referrer_host, object_type, object_id, ip_hash ) t GROUP BY referrer_host, object_type, object_id", ARRAY_A ) as $row ) {
				$ip_max[ self::pair_key( $row ) ] = (int) $row['n'];
			}

			// Passo 4: regola per ogni coppia.
			foreach ( $pairs as $row ) {
				$key      = self::pair_key( $row );
				$visitors = (int) $row['visitors'];
				$median   = Anomaly::median( $base[ $key ] ?? array(), $days );
				if ( ! Anomaly::is_peak( $visitors, $median, $settings['alert_min_visitors'], $settings['alert_factor'] ) ) {
					continue;
				}
				$dominant = Anomaly::dominant( $combos[ $key ] ?? array() );
				$reasons  = Anomaly::signature( (int) $row['countries'], $dominant['share'] ?? null, $ip_max[ $key ] ?? null, $limits );
				$referrer = (string) $row['referrer_host'];
				$item     = array(
					'key'       => 'pair:' . $key,
					'kind'      => 'pair',
					'referrer'  => $referrer,
					'label'     => '' === $referrer ? '' : Referrers::label( $referrer ),
					'type'      => (string) $row['object_type'],
					'id'        => (int) $row['object_id'],
					'visitors'  => $visitors,
					'median'    => $median,
					'base_days' => count( $days ),
					'countries' => (int) $row['countries'],
					'combo'     => $dominant,
					'ip_max'    => $ip_max[ $key ] ?? null,
					'reasons'   => $reasons,
				) + OnlineQuery::describe( (string) $row['object_type'], (int) $row['object_id'], (string) $row['uri'] );

				if ( $reasons ) {
					$result['alerts'][] = $item;
				} else {
					$result['peaks'][] = $item;
				}
			}
		}

		// Passo 5: avvisi per IP, su tutte le visite (non solo gli ingressi) allo stesso contenuto. Solo visite con l'hash (schema v7).
		$ips = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT LOWER(HEX(ip_hash)) AS ip, object_type, object_id, MIN(uri) AS uri, COUNT(*) AS visits, COUNT(DISTINCT ua) AS agents
				FROM {$hits} WHERE ip_hash IS NOT NULL AND {$window}
				GROUP BY ip_hash, object_type, object_id HAVING visits >= %d ORDER BY visits DESC LIMIT %d",
				$settings['alert_ip_visits'],
				self::LIMIT
			),
			ARRAY_A
		);
		foreach ( (array) $ips as $row ) {
			$result['alerts'][] = array(
				'key'    => 'ip:' . $row['ip'] . '|' . $row['object_type'] . '|' . $row['object_id'],
				'kind'   => 'ip',
				'ip'     => (string) $row['ip'],
				'type'   => (string) $row['object_type'],
				'id'     => (int) $row['object_id'],
				'visits' => (int) $row['visits'],
				'agents' => (int) $row['agents'],
			) + OnlineQuery::describe( (string) $row['object_type'], (int) $row['object_id'], (string) $row['uri'] );
		}

		// Passo 6: i più grandi per primi, al massimo LIMIT per elenco.
		$size = static fn( array $item ): int => (int) ( $item['visitors'] ?? $item['visits'] ?? 0 );
		usort( $result['alerts'], static fn( array $a, array $b ): int => $size( $b ) <=> $size( $a ) );
		usort( $result['peaks'], static fn( array $a, array $b ): int => $size( $b ) <=> $size( $a ) );
		$result['alerts'] = array_slice( $result['alerts'], 0, self::LIMIT );
		$result['peaks']  = array_slice( $result['peaks'], 0, self::LIMIT );

		return $result;
	}

	/**
	 * Chiave di una coppia: host del referrer, tipo e ID del contenuto.
	 *
	 * @param array<string,mixed> $row Riga con referrer_host, object_type e object_id.
	 * @return string
	 */
	private static function pair_key( array $row ): string {
		return $row['referrer_host'] . '|' . $row['object_type'] . '|' . $row['object_id'];
	}
}
