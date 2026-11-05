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
	 * Righe cancellate per ogni DELETE della pulizia manuale.
	 *
	 * @var int
	 */
	private const DELETE_BATCH = 5000;

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
							"Le statistiche del sito registrano le pagine visitate senza cookie. Per ogni visita si salvano la pagina, il sito di provenienza, gli eventuali parametri UTM, il paese (ricavato dall'indirizzo IP e poi scartato), il tipo di dispositivo, il browser, il sistema operativo e un identificativo del visitatore che cambia ogni giorno.\n\nPer riconoscere il traffico automatico e le visite anomale si salvano anche lo user agent del browser e un'impronta dell'indirizzo IP (un hash calcolato con una chiave segreta del sito, non reversibile senza quella chiave). L'impronta è sempre la stessa per lo stesso indirizzo IP, quindi finché viene conservata permette di riconoscere le visite dello stesso indirizzo anche in giorni diversi. L'indirizzo IP in chiaro non viene mai salvato. Impronta dell'IP e user agent vengono cancellati dopo %d giorni; le altre informazioni della visita restano.",
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

	/**
	 * Anteprima della pulizia manuale delle visite grezze: quante righe di `wp5w_hits` sono più vecchie di `retention_days` giorni.
	 *
	 * La pulizia delle visite grezze non è automatica (scelta di Maurizio del 5/11/2026): la avvia solo il pulsante della scheda Opzioni.
	 *
	 * @param string|null $today Oggi (Y-m-d), solo per i test: predefinito il giorno del sito.
	 * @return array{retention_days:int,before:string,rows:int,first_day:?string}
	 */
	public static function old_hits( ?string $today = null ): array {
		global $wpdb;

		$hits   = Schema::table( 'hits' );
		$days   = Settings::get()['retention_days'];
		$before = IpHash::first_kept_day( $today ?? wp_date( 'Y-m-d' ), $days );
		$first  = $wpdb->get_var( "SELECT MIN(day) FROM {$hits}" );

		return array(
			'retention_days' => $days,
			'before'         => $before,
			'rows'           => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$hits} WHERE day < %s", $before ) ),
			'first_day'      => is_string( $first ) && '' !== $first ? $first : null,
		);
	}

	/**
	 * Cancella le visite grezze con `day` precedente a $before, a blocchi (niente lock lunghi sulla tabella che riceve le visite).
	 *
	 * Gli aggregati restano: OwnSource sostituisce solo i giorni presenti in `wp5w_hits` (replace_by_month), quindi le righe own
	 * dei giorni cancellati non cambiano più.
	 *
	 * @param string $before Primo giorno conservato (Y-m-d), quello dell'anteprima.
	 * @return int Righe cancellate.
	 */
	public static function delete_old_hits( string $before ): int {
		global $wpdb;

		$hits    = Schema::table( 'hits' );
		$deleted = 0;
		do {
			$batch    = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$hits} WHERE day < %s ORDER BY id LIMIT %d", $before, self::DELETE_BATCH ) );
			$deleted += $batch;
		} while ( self::DELETE_BATCH === $batch );

		return $deleted;
	}
}
