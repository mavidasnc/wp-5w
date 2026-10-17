<?php
/**
 * Pianificazione dell'ETL con WP-Cron.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Etl;

use Mavida\FiveW\Geo\CountryDatabase;
use Mavida\FiveW\Gsc\Client;
use Mavida\FiveW\Sources\OwnSource;
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
	 * Hook del passaggio orario che aggrega le visite della raccolta propria (ieri e oggi).
	 *
	 * @var string
	 */
	public const OWN_HOURLY_HOOK = 'wp5w_own_hourly';

	/**
	 * Hook del controllo giornaliero del database dei paesi (scarica il file solo a inizio mese).
	 *
	 * @var string
	 */
	public const GEO_HOOK = 'wp5w_geo_update';

	/**
	 * Secondi dopo i quali l'import notturno da Search Console non avvia un altro lotto: WP-Cron può girare
	 * dentro la richiesta di un visitatore, e un processo ucciso lascerebbe il lock fino alla sua scadenza.
	 *
	 * @var int
	 */
	public const GSC_SECONDS = 20;

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
	 * Aggancia gli eventi e pianifica quelli ricorrenti (giornaliero, orario e database dei paesi) se mancano.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::DAILY_HOOK, array( $this, 'run_daily' ) );
		add_action( self::FULL_HOOK, array( $this, 'run_full' ) );
		add_action( self::OWN_HOURLY_HOOK, static fn() => ( new OwnSource() )->run_hourly() );
		add_action(
			self::GEO_HOOK,
			static function (): void {
				CountryDatabase::update();
			}
		);

		// Alle 4:00 del sito: dopo le manutenzioni notturne di WP Statistics e Rank Math.
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			$next = new \DateTimeImmutable( 'tomorrow 04:00', wp_timezone() );
			wp_schedule_event( $next->getTimestamp(), 'daily', self::DAILY_HOOK );
		}

		// Aggregazione oraria della raccolta propria: ieri si chiude entro un'ora dalla mezzanotte, senza aspettare le 4:00.
		if ( ! wp_next_scheduled( self::OWN_HOURLY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::OWN_HOURLY_HOOK );
		}

		// Database dei paesi: un controllo al giorno, senza richieste di rete se il file è già del mese corrente.
		// Si pianifica qui e non all'attivazione, perché "wp plugin install --force" non esegue l'hook di attivazione.
		if ( ! wp_next_scheduled( self::GEO_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', self::GEO_HOOK );
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
	 * Rielabora gli ultimi DAILY_DAYS giorni e, se Search Console è collegata, scarica i giorni che mancano.
	 *
	 * @return void
	 */
	public function run_daily(): void {
		$this->importer->run( DateRange::last_days( self::DAILY_DAYS, wp_date( 'Y-m-d' ) ), 'daily' );

		// Dopo l'ETL (che ha già rilasciato il lock): l'import dell'API sostituisce le righe di Rank Math dei giorni che scarica.
		if ( Client::is_connected() ) {
			( new GscApiImport() )->run_all( self::GSC_SECONDS );
		}
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
