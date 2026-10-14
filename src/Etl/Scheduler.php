<?php
/**
 * Pianificazione dell'ETL con WP-Cron.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Etl;

use Mavida\FiveW\Support\DateRange;

defined( 'ABSPATH' ) || exit;

/**
 * Evento giornaliero (ultimi giorni) ed evento singolo per l'import completo dopo l'attivazione.
 */
final class Scheduler {

	/**
	 * Hook dell'evento giornaliero.
	 *
	 * @var string
	 */
	public const DAILY_HOOK = 'wp5w_etl_daily';

	/**
	 * Hook dell'import completo.
	 *
	 * @var string
	 */
	public const FULL_HOOK = 'wp5w_etl_full';

	/**
	 * Giorni rielaborati ogni notte: coprono il ritardo di 2-3 giorni di Search Console.
	 *
	 * @var int
	 */
	public const DAILY_DAYS = 7;

	/**
	 * Costruttore.
	 *
	 * @param Importer $importer Importer.
	 */
	public function __construct( private readonly Importer $importer ) {}

	/**
	 * Aggancia gli eventi e pianifica quello giornaliero se manca.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::DAILY_HOOK, array( $this, 'run_daily' ) );
		add_action( self::FULL_HOOK, array( $this, 'run_full' ) );

		// Alle 4:00 del sito: dopo le manutenzioni notturne di WP Statistics e Rank Math.
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			$next = new \DateTimeImmutable( 'tomorrow 04:00', wp_timezone() );
			wp_schedule_event( $next->getTimestamp(), 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Pianifica l'import completo tra un minuto (usato all'attivazione).
	 *
	 * @return void
	 */
	public static function schedule_full_import(): void {
		if ( ! wp_next_scheduled( self::FULL_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::FULL_HOOK );
		}
	}

	/**
	 * Rielabora gli ultimi DAILY_DAYS giorni.
	 *
	 * @return void
	 */
	public function run_daily(): void {
		$this->importer->run( DateRange::last_days( self::DAILY_DAYS, wp_date( 'Y-m-d' ) ), 'daily' );
	}

	/**
	 * Importa tutto lo storico disponibile.
	 *
	 * @return void
	 */
	public function run_full(): void {
		$this->importer->run( Importer::full_range(), 'full' );
	}
}
