<?php
/**
 * Esecuzione dell'ETL.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Etl;

use Mavida\FiveW\Sources\Source;
use Mavida\FiveW\Support\DateRange;

defined( 'ABSPATH' ) || exit;

/**
 * Riversa le sorgenti nelle tabelle wp5w_* e salva l'esito in wp5w_etl_state.
 */
final class Importer {

	/**
	 * Opzione con l'esito dell'ultima esecuzione.
	 *
	 * @var string
	 */
	public const STATE_OPTION = 'wp5w_etl_state';

	/**
	 * Primo giorno considerato da un import completo (prima non ci sono dati utili sul blog).
	 *
	 * @var string
	 */
	public const FULL_FROM = '2016-01-01';

	/**
	 * Lock per evitare due esecuzioni contemporanee (cron e pulsante, per esempio).
	 *
	 * @var string
	 */
	private const LOCK = 'wp5w_etl_lock';

	/**
	 * Costruttore.
	 *
	 * @param array<int,Source> $sources Sorgenti da importare.
	 */
	public function __construct( private readonly array $sources ) {}

	/**
	 * Sorgenti configurate.
	 *
	 * @return array<int,Source>
	 */
	public function sources(): array {
		return $this->sources;
	}

	/**
	 * Intervallo dell'import completo: da FULL_FROM a oggi.
	 *
	 * @return DateRange
	 */
	public static function full_range(): DateRange {
		return new DateRange( self::FULL_FROM, wp_date( 'Y-m-d' ) );
	}

	/**
	 * Importa l'intervallo da tutte le sorgenti con schema valido.
	 *
	 * @param DateRange $range Intervallo.
	 * @param string    $mode  Etichetta per lo stato: full, daily, manual.
	 * @return array<string,mixed> Esito (lo stesso salvato nell'opzione).
	 */
	public function run( DateRange $range, string $mode ): array {
		if ( get_transient( self::LOCK ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Un import è già in corso.', 'wp-5w' ),
			);
		}
		set_transient( self::LOCK, 1, 30 * MINUTE_IN_SECONDS );

		$started = microtime( true );
		$state   = array(
			'mode'     => $mode,
			'from'     => $range->from,
			'to'       => $range->to,
			'started'  => time(),
			'ok'       => true,
			'sources'  => array(),
			'rows'     => array(),
			'duration' => 0.0,
		);

		foreach ( $this->sources as $source ) {
			$info = $source->detect();

			// Schema inatteso: la sorgente si salta senza toccare i dati già importati.
			if ( ! $info['ok'] ) {
				$state['sources'][ $source->id() ] = array( 'status' => 'skipped' ) + $info;
				continue;
			}

			try {
				$rows                              = $source->import( $range );
				$state['rows']                     = array_merge( $state['rows'], $rows );
				$state['sources'][ $source->id() ] = array( 'status' => 'ok' ) + $info;
			} catch ( \Throwable $e ) {
				$state['ok']                       = false;
				$state['sources'][ $source->id() ] = array(
					'status' => 'error',
					'error'  => $e->getMessage(),
				) + $info;
			}
		}

		$state['duration'] = round( microtime( true ) - $started, 2 );
		update_option( self::STATE_OPTION, $state, false );
		delete_transient( self::LOCK );

		return $state;
	}

	/**
	 * Esito dell'ultima esecuzione.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function last_state(): ?array {
		$state = get_option( self::STATE_OPTION );
		return is_array( $state ) ? $state : null;
	}
}
