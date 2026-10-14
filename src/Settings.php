<?php
/**
 * Impostazioni della raccolta propria.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\Visit;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabella del plugin, nome costruito con $wpdb->prefix in Schema::table().

/**
 * Opzioni `wp5w_settings` (un array) e `wp5w_own_since` (data di passaggio alla raccolta propria).
 */
final class Settings {

	/**
	 * Opzione con le impostazioni (array).
	 *
	 * @var string
	 */
	public const OPTION = 'wp5w_settings';

	/**
	 * Opzione con la data di passaggio alla raccolta propria (vuota oppure Y-m-d).
	 *
	 * @var string
	 */
	public const OWN_SINCE = 'wp5w_own_since';

	/**
	 * Modalità ammesse per gli utenti collegati.
	 *
	 * @var array<int,string>
	 */
	public const USER_MODES = array( 'admins', 'logged_in', 'none' );

	/**
	 * Valori predefiniti.
	 *
	 * @return array{own_tracking:bool,exclude_users:string,excluded_ips:array<int,string>,respect_dnt:bool,retention_days:int}
	 */
	public static function defaults(): array {
		return array(
			'own_tracking'   => true,
			'exclude_users'  => 'admins',
			'excluded_ips'   => array(),
			'respect_dnt'    => true,
			'retention_days' => 30,
		);
	}

	/**
	 * Impostazioni salvate, unite ai valori predefiniti.
	 *
	 * @return array{own_tracking:bool,exclude_users:string,excluded_ips:array<int,string>,respect_dnt:bool,retention_days:int}
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return self::sanitize( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Data di passaggio alla raccolta propria: stringa vuota se manca o non è una data valida.
	 * Non si memorizza in una variabile statica: il valore può cambiare durante la richiesta.
	 *
	 * @return string
	 */
	public static function own_since(): string {
		$value = get_option( self::OWN_SINCE, '' );
		return is_string( $value ) && DateRange::is_valid( $value ) ? $value : '';
	}

	/**
	 * Primo giorno con righe della raccolta propria in site_daily, null se non ce ne sono.
	 *
	 * @return string|null
	 */
	public static function own_first_day(): ?string {
		global $wpdb;

		$day = $wpdb->get_var( 'SELECT MIN(day) FROM ' . Schema::table( 'site_daily' ) . " WHERE origin = 'own'" );
		return $day ? (string) $day : null;
	}

	/**
	 * Controlla la data di passaggio: vuota oppure valida, non futura e non precedente al primo giorno `own`.
	 *
	 * @param string $value Data proposta.
	 * @return true|string True se ammessa, altrimenti il messaggio d'errore.
	 */
	public static function check_own_since( string $value ): bool|string {
		if ( '' === $value ) {
			return true;
		}
		if ( ! DateRange::is_valid( $value ) ) {
			return __( 'La data di passaggio non è valida (formato aaaa-mm-gg).', 'wp-5w' );
		}
		if ( $value > wp_date( 'Y-m-d' ) ) {
			return __( 'La data di passaggio non può essere nel futuro.', 'wp-5w' );
		}

		// Finché non ci sono righe own l'unico valore ammesso è quello vuoto.
		$first = self::own_first_day();
		if ( null === $first ) {
			return __( 'Non ci sono ancora dati della raccolta propria: la data deve restare vuota.', 'wp-5w' );
		}
		if ( $value < $first ) {
			/* translators: %s: primo giorno con dati della raccolta propria (aaaa-mm-gg). */
			return sprintf( __( 'La raccolta propria ha dati dal %s: la data non può essere precedente.', 'wp-5w' ), $first );
		}
		return true;
	}

	/**
	 * Registra l'opzione con la sua sanificazione (la REST di wp-5w non la espone: show_in_rest è falso).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_setting' ) );
	}

	/**
	 * Chiama register_setting() per le impostazioni.
	 *
	 * @return void
	 */
	public static function register_setting(): void {
		register_setting(
			'wp5w',
			self::OPTION,
			array(
				'type'              => 'object',
				'default'           => self::defaults(),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Porta qualunque valore a un array di impostazioni valido.
	 *
	 * Gli IP non validi si scartano qui: la risposta 400 con l'elenco delle righe errate la dà la REST prima di salvare.
	 *
	 * @param mixed $value Valore ricevuto.
	 * @return array{own_tracking:bool,exclude_users:string,excluded_ips:array<int,string>,respect_dnt:bool,retention_days:int}
	 */
	public static function sanitize( mixed $value ): array {
		$defaults = self::defaults();
		$value    = is_array( $value ) ? $value + $defaults : $defaults;

		// Passo 1: elenco degli IP, uno per regola, senza spazi, vuoti, non validi e doppioni.
		$ips = array();
		foreach ( (array) $value['excluded_ips'] as $rule ) {
			$rule = trim( (string) $rule );
			if ( '' !== $rule && Visit::is_valid_ip_rule( $rule ) ) {
				$ips[] = $rule;
			}
		}

		// Passo 2: booleani, modalità ammessa e conservazione tra 7 e 365 giorni.
		return array(
			'own_tracking'   => filter_var( $value['own_tracking'], FILTER_VALIDATE_BOOLEAN ),
			'exclude_users'  => in_array( $value['exclude_users'], self::USER_MODES, true ) ? (string) $value['exclude_users'] : $defaults['exclude_users'],
			'excluded_ips'   => array_values( array_unique( $ips ) ),
			'respect_dnt'    => filter_var( $value['respect_dnt'], FILTER_VALIDATE_BOOLEAN ),
			'retention_days' => max( 7, min( 365, (int) $value['retention_days'] ) ),
		);
	}
}
