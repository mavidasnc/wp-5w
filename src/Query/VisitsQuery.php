<?php
/**
 * Dettaglio delle singole visite di un contenuto (modale del referrer e avvisi sulle visite anomale).
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Query;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\Referrers;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabella del plugin, nome costruito con $wpdb->prefix in Schema::table(); i frammenti WHERE hanno solo segnaposto.

/**
 * Legge solo `wp5w_hits` (raccolta propria): orario, hash dell'IP, paese, dispositivo, browser, sistema, etichetta bot e user agent.
 *
 * Lo user agent arriva da chi visita il sito ed è controllato da lui: l'interfaccia lo mostra solo come testo (React fa l'escape).
 */
final class VisitsQuery {

	/**
	 * Righe al massimo, le più recenti.
	 *
	 * @var int
	 */
	public const LIMIT = 500;

	/**
	 * Righe dei raggruppamenti per hash dell'IP e per user agent.
	 *
	 * @var int
	 */
	private const GROUPS = 20;

	/**
	 * Visite di un contenuto negli ultimi N giorni, filtrate per sito di provenienza e/o hash dell'IP.
	 *
	 * Con il referrer (anche vuoto, cioè «diretto») si leggono solo gli ingressi da quel sito, come nella modale del referrer
	 * e negli avvisi; con il solo hash dell'IP tutte le visite di quell'IP al contenuto. I raggruppamenti sono calcolati su tutte
	 * le visite del filtro, non solo sulle righe mostrate.
	 *
	 * @param string      $type     Tipo di contenuto.
	 * @param int         $id       ID del contenuto.
	 * @param int         $days     Giorni fino a oggi compreso.
	 * @param string|null $referrer Referrer (null = qualunque, '' = diretto).
	 * @param string|null $ip       Hash dell'IP (16 caratteri esadecimali) o null.
	 * @return array<string,mixed>
	 */
	public function get( string $type, int $id, int $days, ?string $referrer, ?string $ip ): array {
		global $wpdb;

		$range = DateRange::last_days( $days, wp_date( 'Y-m-d' ) );
		$hits  = Schema::table( 'hits' );

		// Passo 1: filtro comune alle quattro query.
		$where = $wpdb->prepare( 'object_type = %s AND object_id = %d AND day BETWEEN %s AND %s', $type, $id, $range->from, $range->to );
		if ( null !== $referrer ) {
			$where .= $wpdb->prepare( ' AND is_entry = 1 AND referrer_host = %s', Referrers::hits_key( $referrer ) );
		}
		if ( null !== $ip ) {
			$where .= $wpdb->prepare( ' AND ip_hash = UNHEX(%s)', $ip );
		}

		// Passo 2: totali, righe più recenti e raggruppamenti.
		$totals = $wpdb->get_row( "SELECT COUNT(*) AS visits, COUNT(DISTINCT visitor) AS visitors, COUNT(DISTINCT ip_hash) AS ips, MIN(uri) AS uri FROM {$hits} WHERE {$where}", ARRAY_A );
		// Il filtro è già preparato: i limiti sono costanti intere, così non passa una seconda volta da prepare().
		$limit  = self::LIMIT;
		$groups = self::GROUPS;
		$rows   = $wpdb->get_results( "SELECT ts, LOWER(HEX(ip_hash)) AS ip, referrer_host, is_entry, country, device, browser, os, bot, ua FROM {$hits} WHERE {$where} ORDER BY ts DESC, id DESC LIMIT {$limit}", ARRAY_A );
		$by_ip  = $wpdb->get_results( "SELECT LOWER(HEX(ip_hash)) AS ip, COUNT(*) AS visits, MIN(ts) AS first, MAX(ts) AS last FROM {$hits} WHERE {$where} GROUP BY ip_hash ORDER BY visits DESC, last DESC LIMIT {$groups}", ARRAY_A );
		$by_ua  = $wpdb->get_results( "SELECT ua, COUNT(*) AS visits, COUNT(DISTINCT ip_hash) AS ips FROM {$hits} WHERE {$where} GROUP BY ua ORDER BY visits DESC, ua LIMIT {$groups}", ARRAY_A );

		// Passo 3: orari nel fuso del sito, hash dell'IP corto (i primi 8 caratteri bastano a riconoscerlo a colpo d'occhio).
		$local = static fn( string $ts ): string => get_date_from_gmt( $ts, 'Y-m-d H:i:s' );
		$short = static fn( ?string $hash ): string => null === $hash || '' === $hash ? '' : substr( $hash, 0, 8 );

		return array(
			'range'    => array(
				'from' => $range->from,
				'to'   => $range->to,
			),
			'filter'   => array(
				'type'     => $type,
				'id'       => $id,
				'referrer' => $referrer,
				'label'    => null === $referrer ? null : ( '' === $referrer ? '' : Referrers::label( $referrer ) ),
				'ip'       => null === $ip ? null : $short( $ip ),
			),
			'content'  => OnlineQuery::describe( $type, $id, (string) ( $totals['uri'] ?? '' ) ),
			'totals'   => array(
				'visits'   => (int) ( $totals['visits'] ?? 0 ),
				'visitors' => (int) ( $totals['visitors'] ?? 0 ),
				'ips'      => (int) ( $totals['ips'] ?? 0 ),
			),
			'ip_first' => ( static function () use ( $wpdb, $hits ): ?string {
				$day = $wpdb->get_var( "SELECT MIN(day) FROM {$hits} WHERE ip_hash IS NOT NULL" );
				return is_string( $day ) && '' !== $day ? $day : null;
			} )(),
			'rows'     => array_map(
				static fn( array $row ): array => array(
					'time'     => $local( (string) $row['ts'] ),
					'ip'       => $short( $row['ip'] ),
					'referrer' => (string) $row['referrer_host'],
					'entry'    => (bool) $row['is_entry'],
					'country'  => (string) $row['country'],
					'device'   => (string) $row['device'],
					'browser'  => (string) $row['browser'],
					'os'       => (string) $row['os'],
					'bot'      => (string) $row['bot'],
					'ua'       => (string) $row['ua'],
				),
				(array) $rows
			),
			'by_ip'    => array_map(
				static fn( array $row ): array => array(
					'ip'     => $short( $row['ip'] ),
					'visits' => (int) $row['visits'],
					'first'  => $local( (string) $row['first'] ),
					'last'   => $local( (string) $row['last'] ),
				),
				(array) $by_ip
			),
			'by_ua'    => array_map(
				static fn( array $row ): array => array(
					'ua'     => (string) $row['ua'],
					'visits' => (int) $row['visits'],
					'ips'    => (int) $row['ips'],
				),
				(array) $by_ua
			),
		);
	}
}
