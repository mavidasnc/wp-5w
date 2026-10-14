<?php
/**
 * Attivazione del plugin.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Etl\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Controlli e setup eseguiti all'attivazione.
 */
final class Activator {

	/**
	 * Versione minima di PHP.
	 *
	 * @var string
	 */
	private const MIN_PHP = '8.3';

	/**
	 * Versione minima di WordPress.
	 *
	 * @var string
	 */
	private const MIN_WP = '6.5';

	/**
	 * Verifica i requisiti, crea le tabelle e pianifica l'import iniziale.
	 *
	 * @return void
	 */
	public static function activate(): void {
		global $wp_version;

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) || version_compare( $wp_version, self::MIN_WP, '<' ) ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: PHP minimo, 2: WordPress minimo. */
						__( 'wp-5w richiede PHP %1$s e WordPress %2$s o superiori.', 'wp-5w' ),
						self::MIN_PHP,
						self::MIN_WP
					)
				)
			);
		}

		Schema::install();

		// L'import completo può durare: non si fa durante l'attivazione ma subito dopo, in un evento cron.
		Scheduler::schedule_full_import();
	}
}
