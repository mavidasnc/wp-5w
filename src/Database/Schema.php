<?php
/**
 * Schema del database.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Crea e aggiorna le tabelle aggregate del plugin.
 *
 * Tutte le tabelle hanno una riga per giorno e dimensione: niente IP, hash o dati personali.
 */
final class Schema {

	/**
	 * Versione dello schema (incrementare a ogni modifica delle tabelle).
	 *
	 * @var string
	 */
	public const DB_VERSION = '3';

	/**
	 * Nomi brevi delle tabelle, nell'ordine in cui compaiono nella pagina Stato.
	 *
	 * @var array<int,string>
	 */
	public const TABLES = array( 'site_daily', 'object_daily', 'entry_daily', 'referrer_daily', 'dim_daily', 'gsc_daily' );

	/**
	 * Nome completo di una tabella del plugin.
	 *
	 * @param string $name Nome breve (vedi TABLES).
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'wp5w_' . $name;
	}

	/**
	 * Aggancia l'aggiornamento automatico dello schema.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'plugins_loaded', array( self::class, 'maybe_upgrade' ), 20 );
	}

	/**
	 * Esegue install() se la versione salvata è diversa.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'wp5w_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Crea o aggiorna le tabelle con dbDelta.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$site    = self::table( 'site_daily' );
		$object  = self::table( 'object_daily' );
		$entry   = self::table( 'entry_daily' );
		$ref     = self::table( 'referrer_daily' );
		$dim     = self::table( 'dim_daily' );
		$gsc     = self::table( 'gsc_daily' );

		// Totali del sito per giorno (fonte: WP Statistics, storico dal 2023).
		dbDelta(
			"CREATE TABLE {$site} (
			day date NOT NULL,
			visitors int(10) unsigned NOT NULL DEFAULT 0,
			views int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day)
		) {$charset};"
		);

		// Visualizzazioni e visitatori unici per contenuto e giorno (post, pagine, allegati, home, archivi...).
		dbDelta(
			"CREATE TABLE {$object} (
			day date NOT NULL,
			object_type varchar(40) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			uri varchar(190) NOT NULL DEFAULT '',
			views int(10) unsigned NOT NULL DEFAULT 0,
			visitors int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,object_type,object_id),
			KEY object (object_type,object_id,day)
		) {$charset};"
		);

		// Visitatori entrati nel sito da un contenuto, per canale di provenienza.
		dbDelta(
			"CREATE TABLE {$entry} (
			day date NOT NULL,
			object_type varchar(40) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			channel varchar(50) NOT NULL,
			visitors int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,object_type,object_id,channel),
			KEY object (object_type,object_id,day)
		) {$charset};"
		);

		// Siti di provenienza (solo host, come li salva WP Statistics) di chi entra da un contenuto.
		dbDelta(
			"CREATE TABLE {$ref} (
			day date NOT NULL,
			object_type varchar(40) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			referrer varchar(190) NOT NULL,
			channel varchar(50) NOT NULL DEFAULT '',
			source varchar(100) NOT NULL DEFAULT '',
			visitors int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,object_type,object_id,referrer),
			KEY object (object_type,object_id,day)
		) {$charset};"
		);

		// Visitatori per dimensione (country, device, browser, platform, channel, source).
		dbDelta(
			"CREATE TABLE {$dim} (
			day date NOT NULL,
			dim varchar(20) NOT NULL,
			value varchar(100) NOT NULL,
			visitors int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,dim,value)
		) {$charset};"
		);

		// Search Console per giorno, pagina e query (pos_x_impr serve per la posizione media pesata).
		// origin: "rankmath" (ETL) o "api" (backfill da file): i giorni "api" sono più completi e Rank Math non li tocca.
		dbDelta(
			"CREATE TABLE {$gsc} (
			day date NOT NULL,
			page_hash char(32) NOT NULL,
			query_hash char(32) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			page varchar(500) NOT NULL DEFAULT '',
			query varchar(1000) NOT NULL DEFAULT '',
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			impressions int(10) unsigned NOT NULL DEFAULT 0,
			pos_x_impr double NOT NULL DEFAULT 0,
			origin varchar(8) NOT NULL DEFAULT 'rankmath',
			PRIMARY KEY  (day,page_hash,query_hash),
			KEY object_day (object_id,day)
		) {$charset};"
		);

		update_option( 'wp5w_db_version', self::DB_VERSION );
	}
}
