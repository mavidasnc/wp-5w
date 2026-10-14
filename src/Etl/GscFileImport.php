<?php
/**
 * Import in wp5w_gsc_daily di un file JSONL scaricato dall'API di Search Console.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Etl;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Sources\RankMathSource;
use Mavida\FiveW\Support\DateRange;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabella del plugin; i VALUES sono preparati riga per riga.

/**
 * Serve a recuperare lo storico che Rank Math non ha (scripts/gsc-backfill.py produce il file).
 *
 * Ogni riga: {"day":"Y-m-d","page":"/percorso/","query":"...","clicks":1,"impressions":10,"position":3.2}.
 * Ogni giorno presente nel file sostituisce per intero le righe di quel giorno e diventa di origine "api":
 * l'API restituisce più query di Rank Math, quindi da lì in poi l'ETL di Rank Math non tocca quel giorno.
 * Rilanciare l'import con lo stesso file dà lo stesso risultato.
 */
final class GscFileImport {

	/**
	 * Righe per INSERT.
	 *
	 * @var int
	 */
	private const BATCH = 500;

	/**
	 * Importa il file.
	 *
	 * @param string $file Percorso del file JSONL.
	 * @return array{rows:int,days:int,skipped:int,from:string|null,to:string|null}
	 * @throws \RuntimeException Se il file non è leggibile o una query fallisce.
	 */
	public function run( string $file ): array {
		$lines = is_readable( $file ) ? file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : false;
		if ( false === $lines ) {
			throw new \RuntimeException( "File non leggibile: {$file}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messaggio per la CLI.
		}

		// Righe raggruppate per giorno (il file di 180 giorni sta in poche decine di MB).
		$days    = array();
		$skipped = 0;
		foreach ( $lines as $line ) {
			$item = json_decode( $line, true );
			if ( ! is_array( $item ) || ! isset( $item['day'], $item['page'], $item['query'] ) || ! DateRange::is_valid( (string) $item['day'] ) ) {
				++$skipped;
				continue;
			}
			$days[ $item['day'] ][] = $item;
		}
		ksort( $days );

		$rows = 0;
		foreach ( $days as $day => $items ) {
			$rows += $this->replace_day( (string) $day, $items );
		}

		$from = array_key_first( $days );
		$to   = array_key_last( $days );

		// Collega le pagine ai contenuti, come per le righe di Rank Math.
		if ( null !== $from && null !== $to ) {
			( new RankMathSource() )->map_unknown_pages( new DateRange( (string) $from, (string) $to ) );
		}

		return array(
			'rows'    => $rows,
			'days'    => count( $days ),
			'skipped' => $skipped,
			'from'    => null === $from ? null : (string) $from,
			'to'      => null === $to ? null : (string) $to,
		);
	}

	/**
	 * Sostituisce le righe di un giorno in una transazione.
	 *
	 * @param string                         $day   Giorno (Y-m-d).
	 * @param array<int,array<string,mixed>> $items Righe del file per quel giorno.
	 * @return int Righe scritte.
	 * @throws \RuntimeException Se una query fallisce.
	 */
	private function replace_day( string $day, array $items ): int {
		global $wpdb;

		$table = Schema::table( 'gsc_daily' );
		$wpdb->query( 'START TRANSACTION' );
		$ok = false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day = %s", $day ) );

		foreach ( array_chunk( $items, self::BATCH ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $item ) {
				$page        = mb_substr( (string) $item['page'], 0, 500 );
				$query       = mb_substr( (string) $item['query'], 0, 1000 );
				$impressions = (int) ( $item['impressions'] ?? 0 );
				$values[]    = $wpdb->prepare(
					"(%s, %s, %s, 0, %s, %s, %d, %d, %f, 'api')",
					$day,
					md5( $page ),
					md5( $query ),
					$page,
					$query,
					(int) ( $item['clicks'] ?? 0 ),
					$impressions,
					(float) ( $item['position'] ?? 0 ) * $impressions
				);
			}

			// La chiave è (giorno, md5 pagina, md5 query). Più URL dello stesso percorso (es. con #frammento)
			// diventano la stessa riga: i valori si sommano (il giorno è stato svuotato sopra).
			$ok = $ok && false !== $wpdb->query(
				"INSERT INTO {$table} (day, page_hash, query_hash, object_id, page, query, clicks, impressions, pos_x_impr, origin) VALUES "
				. implode( ',', $values )
				. ' ON DUPLICATE KEY UPDATE clicks = clicks + VALUES(clicks), impressions = impressions + VALUES(impressions), pos_x_impr = pos_x_impr + VALUES(pos_x_impr)'
			);
		}

		if ( ! $ok ) {
			$wpdb->query( 'ROLLBACK' );
			throw new \RuntimeException( "{$day}: {$wpdb->last_error}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messaggio per la CLI.
		}
		$wpdb->query( 'COMMIT' );

		return count( $items );
	}
}
