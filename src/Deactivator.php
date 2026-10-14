<?php
/**
 * Disattivazione del plugin.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW;

use Mavida\FiveW\Etl\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Toglie gli eventi cron e la chiave del giorno: i dati restano (si cancellano solo alla disinstallazione, se richiesto).
 */
final class Deactivator {

	/**
	 * Rimuove gli eventi dell'ETL.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Scheduler::DAILY_HOOK );
		wp_clear_scheduled_hook( Scheduler::FULL_HOOK );
		wp_clear_scheduled_hook( Scheduler::OWN_HOURLY_HOOK );

		// La chiave del giorno non deve restare nel database con la raccolta ferma.
		delete_option( 'wp5w_daily_salt' );
	}
}
