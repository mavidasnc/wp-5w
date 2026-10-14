<?php
/**
 * Schema del database.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Database;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin e migrazione dello schema, nomi costruiti con $wpdb->prefix.

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
	public const DB_VERSION = '4';

	/**
	 * Nomi brevi delle tabelle, nell'ordine in cui compaiono nella pagina Stato.
	 *
	 * @var array<int,string>
	 */
	public const TABLES = array( 'site_daily', 'object_daily', 'entry_daily', 'referrer_daily', 'dim_daily', 'gsc_daily' );

	/**
	 * Tabelle delle visite grezze e degli scarti (raccolta propria), fuori dall'elenco della pagina Stato.
	 *
	 * @var array<int,string>
	 */
	public const RAW_TABLES = array( 'hits', 'discards' );

	/**
	 * Chiavi primarie della versione 4 delle tabelle aggregate: origin è l'ultima colonna.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const PRIMARY_KEYS = array(
		'site_daily'     => array( 'day', 'origin' ),
		'object_daily'   => array( 'day', 'object_type', 'object_id', 'origin' ),
		'entry_daily'    => array( 'day', 'object_type', 'object_id', 'channel', 'origin' ),
		'referrer_daily' => array( 'day', 'object_type', 'object_id', 'referrer', 'origin' ),
		'dim_daily'      => array( 'day', 'dim', 'value', 'origin' ),
	);

	/**
	 * Lock MySQL contro due migrazioni dello schema contemporanee.
	 *
	 * @var string
	 */
	private const UPGRADE_LOCK = 'wp5w_schema_upgrade';

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
	 * Porta lo schema alla versione corrente: migrazione delle chiavi primarie, poi dbDelta.
	 *
	 * Si esegue sotto un lock MySQL: se un'altra richiesta sta già migrando si esce senza toccare nulla
	 * (nemmeno wp5w_db_version) e si riprova alla richiesta successiva.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		// Passo 1: lock senza attesa. Solo chi lo ottiene (1) può migrare e solo lui lo rilascia.
		$locked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', self::UPGRADE_LOCK ) );
		if ( 1 !== $locked ) {
			return;
		}

		try {
			// Passo 2: la versione si rilegge dal database, perché alloptions può essere vecchio.
			$saved = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wp5w_db_version' ) ); // Serve il valore reale, non quello in cache.
			if ( self::DB_VERSION === $saved ) {
				return;
			}

			// Passo 3: chiavi primarie e colonna origin (dbDelta non le cambia). Se fallisce non si aggiorna la versione.
			if ( ! self::migrate_primary_keys() ) {
				return;
			}

			// Passo 4: creazione delle tabelle mancanti con le definizioni finali.
			self::create_tables();

			// Passo 5: la versione si salva solo a migrazione riuscita.
			update_option( 'wp5w_db_version', self::DB_VERSION );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::UPGRADE_LOCK ) );
		}
	}

	/**
	 * Aggiunge origin e porta le chiavi primarie delle cinque tabelle aggregate alla versione 4.
	 *
	 * Per ogni tabella esistente si esegue al massimo un solo ALTER TABLE (atomico). Rieseguirla su uno
	 * schema già migrato non esegue nessun ALTER.
	 *
	 * @return bool Falso se un ALTER è fallito.
	 */
	private static function migrate_primary_keys(): bool {
		global $wpdb;

		foreach ( self::PRIMARY_KEYS as $name => $primary ) {
			$table = self::table( $name );

			// Su un'installazione nuova la tabella non esiste: ci pensa dbDelta().
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				continue;
			}

			$clauses = array();

			// Colonna origin mancante (schema v3).
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
			if ( ! in_array( 'origin', $columns, true ) ) {
				$clauses[] = "ADD COLUMN origin varchar(8) NOT NULL DEFAULT 'wps'";
			}

			// Chiave primaria diversa da quella della v4: colonne in ordine di Seq_in_index.
			$keys = $wpdb->get_results( "SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'", ARRAY_A );
			usort( $keys, static fn( array $a, array $b ): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
			if ( array_column( $keys, 'Column_name' ) !== $primary ) {
				$clauses[] = 'DROP PRIMARY KEY, ADD PRIMARY KEY (' . implode( ',', $primary ) . ')';
			}

			if ( ! $clauses ) {
				continue;
			}

			// Un solo ALTER: o si applicano tutte le clausole o nessuna.
			if ( false === $wpdb->query( "ALTER TABLE {$table} " . implode( ', ', $clauses ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Migrazione dello schema del plugin.
				error_log( "wp5w: migrazione di {$table} fallita: " . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Il fallimento della migrazione deve restare nel log di PHP.
				return false;
			}
		}

		return true;
	}

	/**
	 * Crea le tabelle mancanti e aggiorna le definizioni con dbDelta.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$site     = self::table( 'site_daily' );
		$object   = self::table( 'object_daily' );
		$entry    = self::table( 'entry_daily' );
		$ref      = self::table( 'referrer_daily' );
		$dim      = self::table( 'dim_daily' );
		$gsc      = self::table( 'gsc_daily' );
		$hits     = self::table( 'hits' );
		$discards = self::table( 'discards' );

		// Le cinque tabelle aggregate hanno la colonna origin ("wps" importata da WP Statistics, "own" raccolta propria),
		// ultima colonna della chiave primaria: le righe delle due raccolte convivono nello stesso giorno.

		// Totali del sito per giorno (fonte: WP Statistics, storico dal 2023).
		dbDelta(
			"CREATE TABLE {$site} (
			day date NOT NULL,
			visitors int(10) unsigned NOT NULL DEFAULT 0,
			views int(10) unsigned NOT NULL DEFAULT 0,
			origin varchar(8) NOT NULL DEFAULT 'wps',
			PRIMARY KEY  (day,origin)
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
			origin varchar(8) NOT NULL DEFAULT 'wps',
			PRIMARY KEY  (day,object_type,object_id,origin),
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
			origin varchar(8) NOT NULL DEFAULT 'wps',
			PRIMARY KEY  (day,object_type,object_id,channel,origin),
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
			origin varchar(8) NOT NULL DEFAULT 'wps',
			PRIMARY KEY  (day,object_type,object_id,referrer,origin),
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
			origin varchar(8) NOT NULL DEFAULT 'wps',
			PRIMARY KEY  (day,dim,value,origin)
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

		// Visite grezze della raccolta propria: nessun IP e nessuno user agent, solo l'hash del giorno (md5 grezzo, 16 byte).
		// ts è in UTC, day è il giorno del sito. country (ISO a 2 lettere), device, browser e os (nomi di WP Statistics) sono vuoti se non riconosciuti o se il database dei paesi manca.
		dbDelta(
			"CREATE TABLE {$hits} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ts datetime NOT NULL,
			day date NOT NULL,
			visitor binary(16) NOT NULL,
			object_type varchar(40) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			uri varchar(190) NOT NULL DEFAULT '',
			referrer_host varchar(190) NOT NULL DEFAULT '',
			channel varchar(50) NOT NULL DEFAULT '',
			source varchar(100) NOT NULL DEFAULT '',
			utm_source varchar(100) NOT NULL DEFAULT '',
			utm_medium varchar(100) NOT NULL DEFAULT '',
			utm_campaign varchar(100) NOT NULL DEFAULT '',
			is_entry tinyint(1) unsigned NOT NULL DEFAULT 0,
			country char(2) NOT NULL DEFAULT '',
			device varchar(20) NOT NULL DEFAULT '',
			browser varchar(40) NOT NULL DEFAULT '',
			os varchar(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY visitor_ts (visitor,ts),
			KEY day (day)
		) {$charset};"
		);

		// Visite scartate per giorno e motivo: bot, user, dnt, rate, signature, ip.
		dbDelta(
			"CREATE TABLE {$discards} (
			day date NOT NULL,
			reason varchar(20) NOT NULL,
			n int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,reason)
		) {$charset};"
		);
	}
}
