<?php
/**
 * Privacy della raccolta propria: testo suggerito per l'informativa e conservazione di hash dell'IP e user agent.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Admin;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Settings;
use Mavida\FiveW\Support\IpHash;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabella del plugin, nome costruito con $wpdb->prefix in Schema::table().

/**
 * Le visite della raccolta propria salvano (schema v7) l'hash dell'IP e lo user agent: si azzerano dopo `ip_retention_days` giorni.
 */
final class Privacy {

	/**
	 * Aggancia il testo dell'informativa (Impostazioni > Privacy > Guida).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'policy_content' ) );
	}

	/**
	 * Testo suggerito per l'informativa: cosa si salva, per quanto e cosa resta.
	 *
	 * Le altre colonne della visita (hash del giorno, pagina, referrer, paese, dispositivo) non scadono: `retention_days`
	 * è salvata ma nessun codice la applica ancora, quindi il testo non promette una cancellazione delle visite.
	 *
	 * @return void
	 */
	public function policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$days = Settings::get()['ip_retention_days'];
		wp_add_privacy_policy_content(
			'wp-5w',
			wp_kses_post(
				wpautop(
					sprintf(
						/* translators: %d: giorni di conservazione di hash dell'IP e user agent. */
						__(
							"Le statistiche del sito registrano le pagine visitate senza cookie. Per ogni visita si salvano la pagina, il sito di provenienza, gli eventuali parametri UTM, il paese (ricavato dall'indirizzo IP e poi scartato), il tipo di dispositivo, il browser, il sistema operativo e un identificativo del visitatore che cambia ogni giorno, quindi non permette di collegare le visite di giorni diversi.\n\nPer riconoscere il traffico automatico e le visite anomale si salvano anche lo user agent del browser e un'impronta dell'indirizzo IP (un hash calcolato con una chiave segreta del sito, non reversibile senza quella chiave). L'indirizzo IP in chiaro non viene mai salvato. Impronta dell'IP e user agent vengono cancellati dopo %d giorni.",
							'wp-5w'
						),
						$days
					)
				)
			)
		);
	}

	/**
	 * Azzera hash dell'IP e user agent delle visite più vecchie di `ip_retention_days` giorni (le righe restano per gli aggregati).
	 *
	 * Gira nel passaggio orario: dopo un abbassamento dell'opzione pulisce tutti i giorni più vecchi, poi solo quello appena uscito.
	 *
	 * @param string|null $today Oggi (Y-m-d), solo per i test: predefinito il giorno del sito.
	 * @return int Righe azzerate.
	 */
	public static function purge( ?string $today = null ): int {
		global $wpdb;

		// Schema non ancora migrato alla v7: le colonne non ci sono.
		if ( get_option( 'wp5w_db_version' ) !== Schema::DB_VERSION ) {
			return 0;
		}

		$hits = Schema::table( 'hits' );
		$keep = IpHash::first_kept_day( $today ?? wp_date( 'Y-m-d' ), Settings::get()['ip_retention_days'] );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$hits} SET ip_hash = NULL, ua = '' WHERE day < %s AND ( ip_hash IS NOT NULL OR ua <> '' )",
				$keep
			)
		);
	}
}
