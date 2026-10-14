<?php
/**
 * Dati operativi: pagine non trovate e ricerche interne (scheda Articoli).
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Query;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\Referrers;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin, nomi costruiti con $wpdb->prefix in Schema::table().

/**
 * Legge `wp5w_notfound_daily` e `wp5w_search_daily`: aggregati giornalieri senza legame con il visitatore e con una sola origine
 * (la raccolta propria), quindi senza regola dell'origine.
 */
final class OperationsQuery {

	/**
	 * Righe restituite per elenco.
	 *
	 * @var int
	 */
	private const LIMIT = 100;

	/**
	 * Pagine non trovate del periodo: URI e provenienza, dalla più visitata.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array{range:array{from:string,to:string},hits:int,uris:int,rows:array<int,array<string,mixed>>}
	 */
	public function not_found( DateRange $range ): array {
		global $wpdb;

		$table = Schema::table( 'notfound_daily' );

		// Passo 1: le righe (uri e referrer) e i totali del periodo.
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT uri, referrer, SUM(hits) AS hits, MAX(last_seen) AS last_seen FROM {$table}
				WHERE day BETWEEN %s AND %s GROUP BY uri, referrer ORDER BY 3 DESC, 4 DESC LIMIT %d",
				$range->from,
				$range->to,
				self::LIMIT
			),
			ARRAY_A
		);
		$totals = $wpdb->get_row(
			$wpdb->prepare( "SELECT COALESCE(SUM(hits), 0) AS hits, COUNT(DISTINCT uri) AS uris FROM {$table} WHERE day BETWEEN %s AND %s", $range->from, $range->to ),
			ARRAY_A
		);

		// Passo 2: un referrer uguale all'host del sito è un link interno rotto.
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$site_host = str_starts_with( $site_host, 'www.' ) ? substr( $site_host, 4 ) : $site_host;

		return array(
			'range' => array(
				'from' => $range->from,
				'to'   => $range->to,
			),
			'hits'  => (int) ( $totals['hits'] ?? 0 ),
			'uris'  => (int) ( $totals['uris'] ?? 0 ),
			'rows'  => array_map(
				static fn( array $row ): array => array(
					'uri'       => (string) $row['uri'],
					'url'       => home_url( (string) $row['uri'] ),
					'referrer'  => (string) $row['referrer'],
					'label'     => '' === $row['referrer'] ? '' : Referrers::label( (string) $row['referrer'] ),
					'internal'  => '' !== $row['referrer'] && $row['referrer'] === $site_host,
					'hits'      => (int) $row['hits'],
					'last_seen' => get_date_from_gmt( (string) $row['last_seen'], 'Y-m-d H:i' ),
				),
				$rows
			),
		);
	}

	/**
	 * Ricerche interne del periodo: termini normalizzati, dal più cercato.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array{range:array{from:string,to:string},searches:int,empty:int,rows:array<int,array<string,mixed>>}
	 */
	public function searches( DateRange $range ): array {
		global $wpdb;

		$table = Schema::table( 'search_daily' );

		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term, SUM(searches) AS searches, SUM(empty) AS empty FROM {$table}
				WHERE day BETWEEN %s AND %s GROUP BY term ORDER BY 2 DESC, term LIMIT %d",
				$range->from,
				$range->to,
				self::LIMIT
			),
			ARRAY_A
		);
		$totals = $wpdb->get_row(
			$wpdb->prepare( "SELECT COALESCE(SUM(searches), 0) AS searches, COALESCE(SUM(empty), 0) AS empty FROM {$table} WHERE day BETWEEN %s AND %s", $range->from, $range->to ),
			ARRAY_A
		);

		return array(
			'range'    => array(
				'from' => $range->from,
				'to'   => $range->to,
			),
			'searches' => (int) ( $totals['searches'] ?? 0 ),
			'empty'    => (int) ( $totals['empty'] ?? 0 ),
			'rows'     => array_map(
				static fn( array $row ): array => array(
					'term'     => (string) $row['term'],
					'searches' => (int) $row['searches'],
					'empty'    => (int) $row['empty'],
				),
				$rows
			),
		);
	}
}
