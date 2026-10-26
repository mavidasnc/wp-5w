<?php
/**
 * Comandi WP-CLI.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Cli;

use Mavida\FiveW\Etl\GscFileImport;
use Mavida\FiveW\Etl\Importer;
use Mavida\FiveW\Query\OverviewQuery;
use Mavida\FiveW\Sources\AccessLogSource;
use Mavida\FiveW\Support\DateRange;

defined( 'ABSPATH' ) || exit;

/**
 * Gestisce l'ETL di wp-5w.
 *
 * ## EXAMPLES
 *
 *     wp 5w status
 *     wp 5w etl --full
 *     wp 5w etl --from=2026-04-01 --to=2026-04-30
 */
final class Command {

	/**
	 * Costruttore.
	 *
	 * @param Importer $importer Importer.
	 */
	public function __construct( private readonly Importer $importer ) {}

	/**
	 * Mostra sorgenti, righe per tabella e ultimo ETL.
	 *
	 * @param array<int,string>    $args       Argomenti posizionali.
	 * @param array<string,string> $assoc_args Opzioni.
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$sources = array_map(
			static fn( $source ) => array(
				'source'  => $source['label'],
				'version' => $source['version'] ?? '-',
				'schema'  => $source['ok'] ? 'ok' : 'manca: ' . implode( ', ', $source['missing'] ),
			),
			array_map( static fn( $source ) => $source->detect(), $this->importer->sources() )
		);
		\WP_CLI\Utils\format_items( 'table', $sources, array( 'source', 'version', 'schema' ) );

		$tables = array();
		foreach ( ( new OverviewQuery() )->coverage() as $name => $info ) {
			$tables[] = array( 'table' => $name ) + $info;
		}
		\WP_CLI\Utils\format_items( 'table', $tables, array( 'table', 'from', 'to', 'rows' ) );

		$last = Importer::last_state();
		if ( $last ) {
			\WP_CLI::log( sprintf( 'Ultimo ETL: %s, %s → %s, %s s, %s', $last['mode'], $last['from'], $last['to'], $last['duration'], $last['ok'] ? 'ok' : 'con errori' ) );
		}
	}

	/**
	 * Esegue l'ETL.
	 *
	 * ## OPTIONS
	 *
	 * [--full]
	 * : Importa tutto lo storico disponibile.
	 *
	 * [--from=<date>]
	 * : Primo giorno (Y-m-d). Predefinito: 7 giorni fa.
	 *
	 * [--to=<date>]
	 * : Ultimo giorno (Y-m-d). Predefinito: oggi.
	 *
	 * @param array<int,string>    $args       Argomenti posizionali.
	 * @param array<string,string> $assoc_args Opzioni.
	 * @return void
	 */
	public function etl( array $args, array $assoc_args ): void {
		unset( $args );

		$today = wp_date( 'Y-m-d' );
		try {
			$range = isset( $assoc_args['full'] )
				? Importer::full_range()
				: new DateRange(
					$assoc_args['from'] ?? DateRange::last_days( 7, $today )->from,
					$assoc_args['to'] ?? $today
				);
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		\WP_CLI::log( "Import {$range->from} → {$range->to}..." );
		$state = $this->importer->run( $range, isset( $assoc_args['full'] ) ? 'full' : 'manual' );

		if ( isset( $state['error'] ) ) {
			\WP_CLI::error( (string) $state['error'] );
		}
		foreach ( $state['sources'] as $id => $source ) {
			$detail = 'error' === $source['status'] ? ': ' . $source['error'] : ( 'skipped' === $source['status'] ? ': manca ' . implode( ', ', $source['missing'] ) : '' );
			\WP_CLI::log( "{$id}: {$source['status']}{$detail}" );
		}
		foreach ( $state['rows'] as $table => $rows ) {
			\WP_CLI::log( "  {$table}: {$rows} righe" );
		}

		if ( $state['ok'] ) {
			\WP_CLI::success( "ETL completato in {$state['duration']} s." );
		} else {
			\WP_CLI::error( 'ETL completato con errori.' );
		}
	}

	/**
	 * Importa righe di Search Console da un file JSONL (prodotto da scripts/gsc-backfill.py).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Percorso del file JSONL.
	 *
	 * ## EXAMPLES
	 *
	 *     wp 5w gsc-import ~/backups/gsc-backfill.jsonl
	 *
	 * @subcommand gsc-import
	 *
	 * @param array<int,string>    $args       Argomenti posizionali.
	 * @param array<string,string> $assoc_args Opzioni.
	 * @return void
	 */
	public function gsc_import( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		try {
			$result = ( new GscFileImport() )->run( $args[0] );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		\WP_CLI::success( sprintf( '%d righe in %d giorni (%s → %s), %d scartate.', $result['rows'], $result['days'], $result['from'] ?? '-', $result['to'] ?? '-', $result['skipped'] ) );
	}

	/**
	 * Rilegge tutti gli archivi dei log di accesso (Opzioni, «Log di accesso») e riscrive i giorni che coprono per intero.
	 *
	 * Non tocca l'esito dell'ETL generale (wp5w_etl_state): usa un'opzione sua.
	 *
	 * ## EXAMPLES
	 *
	 *     wp 5w log-import
	 *
	 * @subcommand log-import
	 *
	 * @param array<int,string>    $args       Argomenti posizionali.
	 * @param array<string,string> $assoc_args Opzioni.
	 * @return void
	 */
	public function log_import( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$state = ( new Importer( array( new AccessLogSource() ), 'wp5w_log_state' ) )->run( Importer::full_range(), 'full' );
		if ( isset( $state['error'] ) ) {
			\WP_CLI::error( (string) $state['error'] );
		}
		foreach ( $state['rows'] as $table => $rows ) {
			\WP_CLI::log( "  {$table}: {$rows} righe" );
		}

		if ( $state['ok'] ) {
			\WP_CLI::success( "Log importati in {$state['duration']} s." );
		} else {
			\WP_CLI::error( 'Import dei log con errori: ' . (string) ( $state['sources']['log']['error'] ?? 'sorgente non disponibile' ) );
		}
	}
}
