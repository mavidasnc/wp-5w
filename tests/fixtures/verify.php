<?php
/**
 * Verifica l'ETL e la raccolta propria sui dati di seed.php (oppure su un dump reale).
 *
 * Uso: npx wp-env run cli wp eval-file wp-content/plugins/wp-5w/tests/fixtures/verify.php
 *
 * Controlla lo schema v6 e le migrazioni da v3, da v4 e da v5, confronta le tabelle wp5w_* con le sorgenti, simula le visite
 * all'endpoint /hit, verifica l'aggregazione, la regola dell'origine, le rotte delle opzioni, il confronto con WP Statistics
 * e, dalla fase 3, il box Pubblico, il database dei paesi (download reale e guasti simulati) e paese e user agent delle visite.
 * La fase 4 aggiunge il canale «ai» sulle due origini, le dimensioni UTM, «Online adesso», le pagine 404, le ricerche interne,
 * le visite nell'elenco dei contenuti e nella barra di amministrazione e il modale aperto dall'URL (sezione 12).
 * L'iterazione 0.11.0 segnala bot e browser automatici nella colonna `bot` senza scartarli (sezioni 5 e 6),
 * aggiunge la migrazione alla v6 (sezione 2) e controlla le rotte degli aggiornamenti e i link nell'admin (sezione 13).
 *
 * @package Mavida\FiveW
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Etl\GscApiImport;
use Mavida\FiveW\Etl\Importer;
use Mavida\FiveW\Etl\Scheduler;
use Mavida\FiveW\Front\Tracker;
use Mavida\FiveW\Geo\CountryDatabase;
use Mavida\FiveW\Gsc\Client as GscClient;
use Mavida\FiveW\Gsc\OAuthHandler;
use Mavida\FiveW\Plugin;
use Mavida\FiveW\Admin\AdminMenu;
use Mavida\FiveW\Admin\PostViews;
use Mavida\FiveW\Query\AudienceQuery;
use Mavida\FiveW\Query\OnlineQuery;
use Mavida\FiveW\Query\OperationsQuery;
use Mavida\FiveW\Query\OverviewQuery;
use Mavida\FiveW\Query\PostsQuery;
use Mavida\FiveW\Query\SearchQuery;
use Mavida\FiveW\Query\TrendQuery;
use Mavida\FiveW\REST\GscController;
use Mavida\FiveW\Settings;
use Mavida\FiveW\Sources\OwnSource;
use Mavida\FiveW\Support\DateRange;

global $wpdb;

$p        = $wpdb->prefix;
$failures = 0;

/**
 * Confronta due valori e stampa l'esito.
 *
 * @param string $label    Descrizione.
 * @param mixed  $expected Atteso.
 * @param mixed  $actual   Ottenuto.
 * @return void
 */
$check = static function ( string $label, $expected, $actual ) use ( &$failures ): void {
	if ( (string) $expected === (string) $actual ) {
		WP_CLI::log( "OK   {$label}: {$actual}" );
		return;
	}
	++$failures;
	WP_CLI::log( "FAIL {$label}: atteso {$expected}, ottenuto {$actual}" );
};

// Stato iniziale pulito: nessun blocco dell'ETL rimasto da prove precedenti.
delete_transient( 'wp5w_etl_lock' );

/**
 * Colonne della chiave primaria di una tabella del plugin, in ordine.
 *
 * @param string $name Nome breve della tabella.
 * @return string
 */
$primary_key = static function ( string $name ) use ( $wpdb ): string {
	return (string) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT GROUP_CONCAT( column_name ORDER BY seq_in_index ) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'PRIMARY'",
			Schema::table( $name )
		)
	);
};

// Chiavi primarie attese delle versioni 4 e 5 (la 5 aggiunge solo due tabelle, vedi $v5_keys).
$v4_keys = array(
	'site_daily'     => 'day,origin',
	'object_daily'   => 'day,object_type,object_id,origin',
	'entry_daily'    => 'day,object_type,object_id,channel,origin',
	'referrer_daily' => 'day,object_type,object_id,referrer,origin',
	'dim_daily'      => 'day,dim,value,origin',
);

// Tabelle nuove della versione 5 (fuori da Schema::TABLES e da RAW_TABLES) e loro chiavi primarie.
$v5_keys = array(
	'notfound_daily' => 'day,uri,referrer',
	'search_daily'   => 'day,term',
);

// ---------------------------------------------------------------------------
// 1. Schema v6.
// ---------------------------------------------------------------------------
$check( 'schema: wp5w_db_version = Schema::DB_VERSION', Schema::DB_VERSION, get_option( 'wp5w_db_version' ) );
$check( 'schema: versione 6', '6', Schema::DB_VERSION );
foreach ( $v5_keys as $wp5w_name => $wp5w_expected ) {
	$check( "schema: esiste wp5w_{$wp5w_name}", Schema::table( $wp5w_name ), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( $wp5w_name ) ) ) );
	$check( "schema: chiave primaria di {$wp5w_name}", $wp5w_expected, $primary_key( $wp5w_name ) );
}
$check( 'schema: le due tabelle nuove non sono in TABLES né in RAW_TABLES', 0, count( array_intersect( array_keys( $v5_keys ), array_merge( Schema::TABLES, Schema::RAW_TABLES ) ) ) );
foreach ( $v4_keys as $wp5w_name => $wp5w_expected ) {
	$check( "schema: chiave primaria di {$wp5w_name}", $wp5w_expected, $primary_key( $wp5w_name ) );
}
foreach ( Schema::RAW_TABLES as $wp5w_name ) {
	$check( "schema: esiste wp5w_{$wp5w_name}", Schema::table( $wp5w_name ), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( $wp5w_name ) ) ) );
}
// Colonna bot (0.11.0): varchar(20) NOT NULL con default vuoto, subito dopo os.
$bot_column  = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT column_type, is_nullable, column_default, ordinal_position FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'bot'",
		Schema::table( 'hits' )
	),
	ARRAY_A
);
$os_position = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT ordinal_position FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'os'",
		Schema::table( 'hits' )
	)
);
$check( 'schema: wp5w_hits.bot è varchar(20)', 'varchar(20)', $bot_column['column_type'] ?? 'assente' );
$check( 'schema: wp5w_hits.bot è NOT NULL', 'NO', $bot_column['is_nullable'] ?? 'assente' );
$check( 'schema: wp5w_hits.bot ha il default vuoto', '', trim( (string) ( $bot_column['column_default'] ?? 'assente' ), "'" ) );
$check( 'schema: wp5w_hits.bot viene subito dopo os', $os_position + 1, $bot_column['ordinal_position'] ?? 'assente' );
$check(
	'schema: wp5w_hits senza colonne ip o agent',
	0,
	$wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND ( column_name LIKE '%%ip%%' OR column_name LIKE '%%agent%%' )",
			Schema::table( 'hits' )
		)
	)
);

// ---------------------------------------------------------------------------
// 2. Migrazione da v3: tabelle ricreate con le definizioni della versione 3 e una riga ciascuna.
// ---------------------------------------------------------------------------
$charset = $wpdb->get_charset_collate();
$v3_defs = array(
	'site_daily'     => 'day date NOT NULL, visitors int(10) unsigned NOT NULL DEFAULT 0, views int(10) unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (day)',
	'object_daily'   => 'day date NOT NULL, object_type varchar(40) NOT NULL, object_id bigint(20) unsigned NOT NULL DEFAULT 0, uri varchar(190) NOT NULL DEFAULT \'\', views int(10) unsigned NOT NULL DEFAULT 0, visitors int(10) unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (day,object_type,object_id), KEY object (object_type,object_id,day)',
	'entry_daily'    => 'day date NOT NULL, object_type varchar(40) NOT NULL, object_id bigint(20) unsigned NOT NULL DEFAULT 0, channel varchar(50) NOT NULL, visitors int(10) unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (day,object_type,object_id,channel), KEY object (object_type,object_id,day)',
	'referrer_daily' => 'day date NOT NULL, object_type varchar(40) NOT NULL, object_id bigint(20) unsigned NOT NULL DEFAULT 0, referrer varchar(190) NOT NULL, channel varchar(50) NOT NULL DEFAULT \'\', source varchar(100) NOT NULL DEFAULT \'\', visitors int(10) unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (day,object_type,object_id,referrer), KEY object (object_type,object_id,day)',
	'dim_daily'      => 'day date NOT NULL, dim varchar(20) NOT NULL, value varchar(100) NOT NULL, visitors int(10) unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (day,dim,value)',
);
$v3_rows = array(
	'site_daily'     => array(
		'day'      => '2026-01-01',
		'visitors' => 1,
		'views'    => 2,
	),
	'object_daily'   => array(
		'day'         => '2026-01-01',
		'object_type' => 'post',
		'object_id'   => 1,
		'uri'         => '/x/',
		'views'       => 2,
		'visitors'    => 1,
	),
	'entry_daily'    => array(
		'day'         => '2026-01-01',
		'object_type' => 'post',
		'object_id'   => 1,
		'channel'     => 'search',
		'visitors'    => 1,
	),
	'referrer_daily' => array(
		'day'         => '2026-01-01',
		'object_type' => 'post',
		'object_id'   => 1,
		'referrer'    => 'google.com',
		'channel'     => 'search',
		'source'      => 'Google',
		'visitors'    => 1,
	),
	'dim_daily'      => array(
		'day'      => '2026-01-01',
		'dim'      => 'channel',
		'value'    => 'search',
		'visitors' => 1,
	),
);
foreach ( $v3_defs as $wp5w_name => $wp5w_def ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::table( $wp5w_name ) );
	$wpdb->query( 'CREATE TABLE ' . Schema::table( $wp5w_name ) . " ( {$wp5w_def} ) {$charset}" );
	$wpdb->insert( Schema::table( $wp5w_name ), $v3_rows[ $wp5w_name ] );
}
update_option( 'wp5w_db_version', '3' );

// Concorrenza: una seconda connessione tiene il lock della migrazione, quindi maybe_upgrade() non deve toccare nulla.
$wp5w_host = DB_HOST;
$wp5w_port = 3306;
if ( str_contains( $wp5w_host, ':' ) ) {
	[ $wp5w_host, $wp5w_port ] = explode( ':', $wp5w_host, 2 );
}
$other = new mysqli( $wp5w_host, DB_USER, DB_PASSWORD, DB_NAME, (int) $wp5w_port );
$other->query( "SELECT GET_LOCK( 'wp5w_schema_upgrade', 0 )" );
Schema::maybe_upgrade();
$check( 'migrazione con lock occupato: versione ancora 3', '3', get_option( 'wp5w_db_version' ) );
$check( 'migrazione con lock occupato: chiave di site_daily invariata', 'day', $primary_key( 'site_daily' ) );
$check( 'migrazione con lock occupato: nessuna colonna origin', 0, count( array_filter( $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::table( 'object_daily' ) ), static fn( $c ) => 'origin' === $c ) ) );

// Lock libero: la migrazione parte.
$other->query( "SELECT RELEASE_LOCK( 'wp5w_schema_upgrade' )" );
$other->close();
Schema::maybe_upgrade();
// Le migrazioni da v3 e da v4 non eseguono ALTER su wp5w_hits solo perché la tabella ha già la colonna bot in questo momento.
$check( 'migrazione da v3: versione 6', '6', get_option( 'wp5w_db_version' ) );
foreach ( $v5_keys as $wp5w_name => $wp5w_expected ) {
	$check( "migrazione da v3: esiste wp5w_{$wp5w_name}", Schema::table( $wp5w_name ), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( $wp5w_name ) ) ) );
}
foreach ( $v4_keys as $wp5w_name => $wp5w_expected ) {
	$check( "migrazione: chiave primaria di {$wp5w_name}", $wp5w_expected, $primary_key( $wp5w_name ) );
	$check( "migrazione: righe esistenti di {$wp5w_name} con origin = wps", 1, $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $wp5w_name ) . " WHERE origin = 'wps'" ) );
}

// Idempotenza: con le tabelle già v4 install() non esegue nessun ALTER e non cambia SHOW CREATE TABLE.
$show_create = static function () use ( $wpdb, $v5_keys ): string {
	$out = '';
	foreach ( array_merge( Schema::TABLES, Schema::RAW_TABLES, array_keys( $v5_keys ) ) as $name ) {
		$out .= (string) $wpdb->get_var( 'SHOW CREATE TABLE ' . Schema::table( $name ), 1 ) . ';';
	}
	return $out;
};
$create_before = $show_create();
$alters        = 0;
$count_alters  = static function ( $query ) use ( &$alters ) {
	if ( 0 === stripos( ltrim( (string) $query ), 'ALTER TABLE' ) ) {
		++$alters;
	}
	return $query;
};
add_filter( 'query', $count_alters );
update_option( 'wp5w_db_version', '3' );
$wpdb->last_error = '';
Schema::install();
remove_filter( 'query', $count_alters );
$check( 'install() rieseguito: nessun errore', '', $wpdb->last_error );
$check( 'install() rieseguito: nessun ALTER', 0, $alters );
$check( 'install() rieseguito: versione 6', '6', get_option( 'wp5w_db_version' ) );
$check( 'install() rieseguito: SHOW CREATE TABLE identico', md5( $create_before ), md5( $show_create() ) );

// Da v4 a v5: schema v4 completo, senza le due tabelle nuove e con la versione 4. install() le crea, non esegue nessun ALTER e salva 5.
foreach ( array_keys( $v5_keys ) as $wp5w_name ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::table( $wp5w_name ) );
}
update_option( 'wp5w_db_version', '4' );
$alters = 0;
add_filter( 'query', $count_alters );
$wpdb->last_error = '';
Schema::install();
remove_filter( 'query', $count_alters );
$check( 'migrazione da v4: nessun errore', '', $wpdb->last_error );
$check( 'migrazione da v4: nessun ALTER', 0, $alters );
$check( 'migrazione da v4: versione 6', '6', get_option( 'wp5w_db_version' ) );
foreach ( $v5_keys as $wp5w_name => $wp5w_expected ) {
	$check( "migrazione da v4: creata wp5w_{$wp5w_name}", Schema::table( $wp5w_name ), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( $wp5w_name ) ) ) );
	$check( "migrazione da v4: chiave primaria di {$wp5w_name}", $wp5w_expected, $primary_key( $wp5w_name ) );
}
$create_v5 = $show_create();
update_option( 'wp5w_db_version', '4' );
Schema::install();
$check( 'migrazione da v4 rieseguita: SHOW CREATE TABLE identico', md5( $create_v5 ), md5( $show_create() ) );
$check( 'migrazione da v4 rieseguita: versione 6', '6', get_option( 'wp5w_db_version' ) );

// Da v5 a v6: wp5w_hits senza la colonna bot, con almeno una riga.
$hits_name  = Schema::table( 'hits' );
$hits_probe = 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$hits_name}" );
if ( $hits_probe ) {
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$hits_name} (ts, day, visitor, object_type, object_id, uri) VALUES (%s, %s, UNHEX(%s), 'post', 1, '/wp5w-verify-v6/')", gmdate( 'Y-m-d H:i:s' ), wp_date( 'Y-m-d' ), md5( 'wp5w-verify-v6' ) ) );
}
$hits_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$hits_name}" );
$has_bot   = static fn(): int => count( array_filter( $wpdb->get_col( "SHOW COLUMNS FROM {$hits_name}" ), static fn( $c ) => 'bot' === $c ) );
$wpdb->query( "ALTER TABLE {$hits_name} DROP COLUMN bot" );
update_option( 'wp5w_db_version', '5' );
$check( 'v5 simulata: nessuna colonna bot', 0, $has_bot() );

// Lock tenuto da una seconda connessione: maybe_upgrade() non cambia nulla.
$other = new mysqli( $wp5w_host, DB_USER, DB_PASSWORD, DB_NAME, (int) $wp5w_port );
$other->query( "SELECT GET_LOCK( 'wp5w_schema_upgrade', 0 )" );
Schema::maybe_upgrade();
$check( 'migrazione v5 con lock occupato: versione ancora 5', '5', get_option( 'wp5w_db_version' ) );
$check( 'migrazione v5 con lock occupato: nessuna colonna bot', 0, $has_bot() );
$other->query( "SELECT RELEASE_LOCK( 'wp5w_schema_upgrade' )" );
$other->close();

// Lock libero: un solo ALTER TABLE, riga esistente con bot vuoto, versione 6.
$alters = 0;
add_filter( 'query', $count_alters );
$wpdb->last_error = '';
Schema::install();
remove_filter( 'query', $count_alters );
$check( 'migrazione da v5: nessun errore', '', $wpdb->last_error );
$check( 'migrazione da v5: esattamente 1 ALTER', 1, $alters );
$check( 'migrazione da v5: colonna bot presente', 1, $has_bot() );
$check( 'migrazione da v5: posizione di bot dopo os', $os_position + 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT ordinal_position FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'bot'", $hits_name ) ) );
$check( 'migrazione da v5: righe conservate', $hits_rows, $wpdb->get_var( "SELECT COUNT(*) FROM {$hits_name}" ) );
$check( 'migrazione da v5: righe esistenti con bot vuoto', $hits_rows, $wpdb->get_var( "SELECT COUNT(*) FROM {$hits_name} WHERE bot = ''" ) );
$check( 'migrazione da v5: versione 6', '6', get_option( 'wp5w_db_version' ) );

// Idempotenza: con la versione forzata a 5 nessun ALTER e SHOW CREATE TABLE identico.
$create_hits = (string) $wpdb->get_var( "SHOW CREATE TABLE {$hits_name}", 1 );
update_option( 'wp5w_db_version', '5' );
$alters = 0;
add_filter( 'query', $count_alters );
Schema::install();
remove_filter( 'query', $count_alters );
$check( 'migrazione da v5 rieseguita: nessun ALTER', 0, $alters );
$check( 'migrazione da v5 rieseguita: SHOW CREATE TABLE identico', md5( $create_hits ), md5( (string) $wpdb->get_var( "SHOW CREATE TABLE {$hits_name}", 1 ) ) );
$check( 'migrazione da v5 rieseguita: versione 6', '6', get_option( 'wp5w_db_version' ) );

/**
 * Definizione di una tabella da information_schema: colonne (nome, tipo, NULL, default, posizione) e indici.
 *
 * @param string $table Nome completo della tabella.
 * @return string
 */
$table_definition = static function ( string $table ) use ( $wpdb ): string {
	$columns = (string) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT GROUP_CONCAT( CONCAT_WS( '|', column_name, column_type, is_nullable, COALESCE( column_default, '<null>' ), ordinal_position ) ORDER BY ordinal_position SEPARATOR ';' ) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s",
			$table
		)
	);
	$indexes = (string) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT GROUP_CONCAT( CONCAT_WS( '|', index_name, seq_in_index, column_name, non_unique ) ORDER BY index_name, seq_in_index SEPARATOR ';' ) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s",
			$table
		)
	);
	return $columns . ' // ' . $indexes;
};

// Installazione senza wp5w_hits: la tabella migrata si mette da parte (RENAME), install() ricrea quella nuova con la colonna bot
// senza ALTER, e le definizioni coincidono con quelle della migrazione. Poi si rimette la tabella migrata (con le sue righe).
$hits_backup = $hits_name . '_verify_bak';
$migrated    = $table_definition( $hits_name );
$wpdb->query( "DROP TABLE IF EXISTS {$hits_backup}" );
$wpdb->query( "RENAME TABLE {$hits_name} TO {$hits_backup}" );
update_option( 'wp5w_db_version', '5' );
$alters = 0;
add_filter( 'query', $count_alters );
$wpdb->last_error = '';
Schema::install();
remove_filter( 'query', $count_alters );
$check( 'installazione senza hits: nessun errore', '', $wpdb->last_error );
$check( 'installazione senza hits: nessun ALTER', 0, $alters );
$check( 'installazione senza hits: tabella ricreata con bot', 1, $has_bot() );
$check( 'installazione senza hits: colonne e indici uguali alla migrazione', md5( $migrated ), md5( $table_definition( $hits_name ) ) );
$check( 'installazione senza hits: versione 6', '6', get_option( 'wp5w_db_version' ) );
$wpdb->query( "DROP TABLE {$hits_name}" );
$wpdb->query( "RENAME TABLE {$hits_backup} TO {$hits_name}" );
$check( 'installazione senza hits: tabella migrata ripristinata', $hits_rows, $wpdb->get_var( "SELECT COUNT(*) FROM {$hits_name}" ) );
if ( $hits_probe ) {
	$wpdb->query( "DELETE FROM {$hits_name} WHERE uri = '/wp5w-verify-v6/'" );
}

// ---------------------------------------------------------------------------
// 3. Controlli esistenti dell'ETL di WP Statistics e Rank Math (somme sulle sole righe wps).
// ---------------------------------------------------------------------------
// Si parte da tabelle vuote: le righe di prove precedenti, assenti dalla sorgente, verrebbero conservate.
foreach ( array_merge( Schema::TABLES, Schema::RAW_TABLES ) as $wp5w_name ) {
	$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( $wp5w_name ) );
}
update_option( Settings::OPTION, Settings::defaults() );
update_option( Settings::OWN_SINCE, '' );
delete_option( 'wp5w_daily_salt' );

$state = Plugin::importer()->run( Importer::full_range(), 'full' );
$check( 'import completo senza errori', '1', $state['ok'] ? '1' : '0' );

// Totali del sito: summary_totals (giorni non a zero) + dettaglio per i giorni senza un totale valido.
$expected_views = (int) $wpdb->get_var(
	"SELECT SUM(views) FROM {$p}statistics_summary_totals WHERE visitors > 0 OR views > 0"
) + (int) $wpdb->get_var(
	"SELECT COALESCE(SUM(count), 0) FROM {$p}statistics_pages WHERE date NOT IN ( SELECT date FROM {$p}statistics_summary_totals WHERE visitors > 0 OR views > 0 )"
);
$check( 'site_daily.views', $expected_views, $wpdb->get_var( 'SELECT SUM(views) FROM ' . Schema::table( 'site_daily' ) . " WHERE origin = 'wps'" ) );
$check(
	'site_daily senza giorni a zero',
	0,
	$wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'site_daily' ) . " WHERE visitors = 0 AND views = 0 AND origin = 'wps'" )
);

// Contenuti, ingressi e dimensioni.
$check( 'object_daily.views', $wpdb->get_var( "SELECT SUM(count) FROM {$p}statistics_pages" ), $wpdb->get_var( 'SELECT SUM(views) FROM ' . Schema::table( 'object_daily' ) . " WHERE origin = 'wps'" ) );
$check(
	'entry_daily.visitors',
	$wpdb->get_var( "SELECT COUNT(*) FROM {$p}statistics_visitor v JOIN {$p}statistics_pages p ON p.page_id = v.first_page" ),
	$wpdb->get_var( 'SELECT SUM(visitors) FROM ' . Schema::table( 'entry_daily' ) . " WHERE origin = 'wps'" )
);
$check(
	'dim_daily per dimensione = visitatori',
	$wpdb->get_var( "SELECT COUNT(*) FROM {$p}statistics_visitor" ),
	$wpdb->get_var( 'SELECT SUM(visitors) FROM ' . Schema::table( 'dim_daily' ) . " WHERE origin = 'wps' AND dim = 'channel'" )
);

// Visitatori unici per contenuto e referrer per pagina d'ingresso.
$check(
	'object_daily.visitors = visitatori distinti per pagina e giorno',
	$wpdb->get_var(
		"SELECT COALESCE(SUM(n), 0) FROM ( SELECT COUNT(DISTINCT r.visitor_id) AS n FROM {$p}statistics_visitor_relationships r
		JOIN {$p}statistics_pages p ON p.page_id = r.page_id GROUP BY DATE(r.date), p.type, p.id ) t"
	),
	$wpdb->get_var( 'SELECT SUM(visitors) FROM ' . Schema::table( 'object_daily' ) . " WHERE origin = 'wps'" )
);
$check(
	'referrer_daily.visitors = visitatori con referrer',
	$wpdb->get_var( "SELECT COUNT(*) FROM {$p}statistics_visitor v JOIN {$p}statistics_pages p ON p.page_id = v.first_page WHERE v.referred <> ''" ),
	$wpdb->get_var( 'SELECT SUM(visitors) FROM ' . Schema::table( 'referrer_daily' ) . " WHERE origin = 'wps'" )
);

// Search Console: righe uniche e click senza duplicati.
$check(
	'gsc_daily righe = terne uniche',
	$wpdb->get_var( "SELECT COUNT(DISTINCT DATE(created), page, query) FROM {$p}rank_math_analytics_gsc" ),
	$wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'gsc_daily' ) )
);
$check(
	'gsc_daily.clicks deduplicati',
	$wpdb->get_var( "SELECT SUM(c) FROM ( SELECT MAX(clicks) AS c FROM {$p}rank_math_analytics_gsc GROUP BY DATE(created), page, query ) t" ),
	$wpdb->get_var( 'SELECT SUM(clicks) FROM ' . Schema::table( 'gsc_daily' ) )
);
$check( 'gsc_daily senza object_id', 0, $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'gsc_daily' ) . ' WHERE object_id = 0' ) );

// Idempotenza: un secondo import non cambia le tabelle.
$snapshot = static function () use ( $wpdb ): string {
	$out = '';
	foreach ( Schema::TABLES as $name ) {
		$out .= $name . ':' . $wpdb->get_var( 'CHECKSUM TABLE ' . Schema::table( $name ), 1 ) . ';';
	}
	return $out;
};
$before = $snapshot();
Plugin::importer()->run( Importer::full_range(), 'full' );
$check( 'secondo import identico', $before, $snapshot() );

// I giorni eliminati dalla sorgente restano nelle tabelle wp5w (finestra mobile di Rank Math).
$first_gsc_day = $wpdb->get_var( 'SELECT MIN(day) FROM ' . Schema::table( 'gsc_daily' ) );
$rows_before   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'gsc_daily' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}rank_math_analytics_gsc WHERE DATE(created) = %s", $first_gsc_day ) );
Plugin::importer()->run( Importer::full_range(), 'full' );
$check( 'storico GSC conservato dopo la pulizia di Rank Math', $rows_before, $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'gsc_daily' ) ) );

// Backfill dall'API: un giorno che Rank Math non ha e uno che ha (con una query in più).
$rm_day   = (string) $wpdb->get_var( "SELECT MAX(DATE(created)) FROM {$p}rank_math_analytics_gsc" );
$rm_page  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT page FROM {$p}rank_math_analytics_gsc WHERE DATE(created) = %s LIMIT 1", $rm_day ) );
$api_day  = '2020-01-15';
$api_file = get_temp_dir() . 'wp5w-verify.jsonl';
$api_rows = array(
	array( 'day' => $api_day, 'page' => $rm_page, 'query' => 'query api', 'clicks' => 5, 'impressions' => 50, 'position' => 2.0 ),
	array( 'day' => $rm_day, 'page' => $rm_page, 'query' => 'query solo api', 'clicks' => 1, 'impressions' => 10, 'position' => 9.0 ),
);
file_put_contents( $api_file, implode( "\n", array_map( 'wp_json_encode', $api_rows ) ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
( new Mavida\FiveW\Etl\GscFileImport() )->run( $api_file );
Plugin::importer()->run( Importer::full_range(), 'full' );
$check(
	'giorni dell\'API intatti dopo l\'ETL di Rank Math',
	2,
	$wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'gsc_daily' ) . " WHERE origin = 'api' AND day IN (%s, %s)", $api_day, $rm_day ) )
);
$check(
	'righe Rank Math del giorno sostituito dall\'API',
	0,
	$wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'gsc_daily' ) . " WHERE origin = 'rankmath' AND day = %s", $rm_day ) )
);
$check( 'righe API collegate al contenuto', 0, $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'gsc_daily' ) . " WHERE origin = 'api' AND object_id = 0" ) );
wp_delete_file( $api_file );

// ---------------------------------------------------------------------------
// 4. Istantanea dell'interfaccia (prima delle visite simulate): wp5w_own_since vuota e nessuna riga own.
// ---------------------------------------------------------------------------
update_option( Settings::OWN_SINCE, '' );
$today      = wp_date( 'Y-m-d' );
$seed_posts = array_map(
	'intval',
	get_posts(
		array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'numberposts' => 4,
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'fields'      => 'ids',
			// Gli articoli del seed (su un dump reale non esistono: si ripiega sui primi articoli pubblicati).
			'post_name__in' => array( 'calendario-2006', 'blocksy-tema', 'php-solutions', 'uscito-oggi' ),
		)
	)
);
if ( ! $seed_posts ) {
	$seed_posts = array_map( 'intval', get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 4, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids' ) ) );
}
$post_a     = $seed_posts[0] ?? 1;
$post_b     = $seed_posts[1] ?? 2;

/**
 * JSON dei dati di tutte le schede, per confrontare le risposte prima e dopo le righe own.
 *
 * @return array<string,string>
 */
$ui_snapshot = static function () use ( $today, $seed_posts, $post_a ): array {
	$overview = new OverviewQuery();
	$posts    = new PostsQuery();
	$audience = new AudienceQuery( new SearchQuery() );
	$trend    = new TrendQuery();
	$r30      = DateRange::last_days( 30, $today );
	$r60      = DateRange::last_days( 60, $today );

	// `tools` (le serie dei tre strumenti) legge di proposito tutte le origini: non fa parte del confronto prima/dopo le righe own.
	$overview_data = $overview->get( $r30 );
	unset( $overview_data['tools'] );

	return array(
		'overview'     => (string) wp_json_encode( $overview_data ),
		'top all'      => (string) wp_json_encode( $overview->top( $r30, 'all' ) ),
		'top post'     => (string) wp_json_encode( $overview->top( $r30, 'post' ) ),
		'latest'       => (string) wp_json_encode( $posts->latest() ),
		'top 7'        => (string) wp_json_encode( $posts->top( 7 ) ),
		'top 30'       => (string) wp_json_encode( $posts->top( 30 ) ),
		'dettaglio'    => (string) wp_json_encode( $posts->detail( $post_a, $r60 ) ),
		'visitatori'   => (string) wp_json_encode( $audience->get() ),
		'pubblico'     => (string) wp_json_encode( $audience->dimensions( $r30 ) ),
		'trend sito'   => (string) wp_json_encode( $trend->site() ),
		'trend oggetti' => (string) wp_json_encode( $trend->objects( $seed_posts ) ),
		'coverage'     => (string) wp_json_encode( $overview->coverage() ),
	);
};
$ui_before = $ui_snapshot();
$check( 'istantanea: nessuna riga own prima delle visite', 0, $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'site_daily' ) . " WHERE origin = 'own'" ) );

// ---------------------------------------------------------------------------
// 5. Visite simulate con rest_do_request() su POST /wp5w/v1/hit.
// ---------------------------------------------------------------------------
$chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

// Impostazioni predefinite e tabelle grezze vuote.
update_option( Settings::OPTION, Settings::defaults() );
$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( 'hits' ) );
$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( 'discards' ) );
delete_option( 'wp5w_daily_salt' );

/**
 * Invia una visita all'endpoint e restituisce il codice HTTP.
 *
 * @param array<string,mixed>  $params Parametri del corpo.
 * @param string               $ip     REMOTE_ADDR.
 * @param string               $ua     User agent.
 * @return int
 */
$send_hit = static function ( array $params, string $ip, string $ua ): int {
	$_SERVER['REMOTE_ADDR']     = $ip;
	$_SERVER['HTTP_USER_AGENT'] = $ua;

	$request = new WP_REST_Request( 'POST', '/wp5w/v1/hit' );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request )->get_status();
};

/**
 * Parametri di una visita valida a un articolo.
 *
 * @param int                 $id    ID dell'articolo.
 * @param array<string,mixed> $extra Parametri aggiuntivi o sostitutivi.
 * @return array<string,mixed>
 */
$payload = static function ( int $id, array $extra = array() ): array {
	return $extra + array(
		't'  => 'post',
		'id' => $id,
		's'  => Tracker::signature( 'post', $id ),
		'u'  => '/articolo-' . $id . '/',
		'r'  => '',
	);
};

$hits_table = Schema::table( 'hits' );
$count_hits = static fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$hits_table}" );
$discarded  = static fn( string $reason ): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(n), 0) FROM ' . Schema::table( 'discards' ) . ' WHERE reason = %s', $reason ) );
$discards_n = static fn(): int => (int) $wpdb->get_var( 'SELECT COALESCE(SUM(n), 0) FROM ' . Schema::table( 'discards' ) );

// Visita valida da Google: una riga, ingresso, canale search.
$check( 'hit valida: 204', 204, $send_hit( $payload( $post_a, array( 'r' => 'https://www.google.com/' ) ), '198.51.100.1', $chrome ) );
$row = $wpdb->get_row( "SELECT * FROM {$hits_table} ORDER BY id DESC LIMIT 1" );
$check( 'hit valida: una riga', 1, $count_hits() );
$check( 'hit valida: is_entry', 1, $row->is_entry ?? '' );
$check( 'hit valida: canale', 'search', $row->channel ?? '' );
$check( 'hit valida: sorgente', 'Google', $row->source ?? '' );
$check( 'hit valida: referrer_host', 'google.com', $row->referrer_host ?? '' );
$check( 'hit valida: tipo e id', "post:{$post_a}", ( $row->object_type ?? '' ) . ':' . ( $row->object_id ?? '' ) );
$check( 'hit valida: visitor di 16 byte', 16, (int) $wpdb->get_var( "SELECT LENGTH(visitor) FROM {$hits_table} ORDER BY id DESC LIMIT 1" ) );

// Seconda visita dello stesso visitatore, referrer interno: non è un ingresso.
$check( 'hit interna: 204', 204, $send_hit( $payload( $post_b, array( 'r' => home_url( '/' ) ) ), '198.51.100.1', $chrome ) );
$row = $wpdb->get_row( "SELECT * FROM {$hits_table} ORDER BY id DESC LIMIT 1" );
$check( 'hit interna: is_entry 0', 0, $row->is_entry ?? '' );
$check( 'hit interna: referrer_host vuoto', '', $row->referrer_host ?? 'x' );

// Bot dichiarati, user agent vuoto e browser automatici (0.11.0): si registrano con l'etichetta nella colonna bot, non si scartano.
$bot_label = static fn(): string => (string) $wpdb->get_var( "SELECT bot FROM {$hits_table} ORDER BY id DESC LIMIT 1" );
$googlebot = 'Googlebot/2.1 (+http://www.google.com/bot.html)';
$before    = array( $count_hits(), $discarded( 'bot' ) );
$check( 'bot Googlebot: 204', 204, $send_hit( $payload( $post_a ), '198.51.100.2', $googlebot ) );
$check( 'bot Googlebot: una riga', $before[0] + 1, $count_hits() );
$check( 'bot Googlebot: etichetta bot', 'bot', $bot_label() );
$check( 'bot UA vuoto: 204', 204, $send_hit( $payload( $post_a ), '198.51.100.3', '' ) );
$check( 'bot UA vuoto: una riga in più', $before[0] + 2, $count_hits() );
$check( 'bot UA vuoto: etichetta ua-corto', 'ua-corto', $bot_label() );
$check( 'bot: scarti bot invariati', $before[1], $discarded( 'bot' ) );

// Altre etichette: ogni prova usa un IP diverso, così la riga è inequivocabile.
$send_hit( $payload( $post_a ), '198.51.100.40', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.0; +https://openai.com/gptbot)' );
$check( 'GPTBot: etichetta gptbot', 'gptbot', $bot_label() );
$send_hit( $payload( $post_a, array( 'w' => '1' ) ), '198.51.100.41', $chrome );
$check( 'Chrome con w=1: etichetta webdriver', 'webdriver', $bot_label() );
$send_hit( $payload( $post_a, array( 'w' => '0' ) ), '198.51.100.42', $chrome );
$check( 'Chrome con w=0: etichetta vuota', '', $bot_label() );
$send_hit( $payload( $post_a, array( 'w' => 'true' ) ), '198.51.100.43', $chrome );
$check( 'Chrome con w=true: etichetta vuota', '', $bot_label() );
$warnings = array();
set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_set_error_handler -- Prova isolata, il gestore viene tolto subito dopo.
	static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
		$warnings[] = $errstr;
		return true;
	}
);
$array_w_status = $send_hit( $payload( $post_a, array( 'w' => array( '1' ) ) ), '198.51.100.44', $chrome );
restore_error_handler();
$check( 'Chrome con w[]=1: 204', 204, $array_w_status );
$check( 'Chrome con w[]=1: etichetta vuota', '', $bot_label() );
$check( 'Chrome con w[]=1: nessun warning', '', implode( ' | ', $warnings ) );
$send_hit( $payload( $post_a ), '198.51.100.45', $chrome );
$check( 'Chrome senza w: etichetta vuota', '', $bot_label() );
$send_hit( $payload( $post_a, array( 'w' => '1' ) ), '198.51.100.46', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36' );
$check( 'HeadlessChrome con w=1: etichetta headless', 'headless', $bot_label() );

// Gli scarti restano: un bot dichiarato con Do Not Track è dnt, con una firma errata è signature, senza righe.
$before = array( $count_hits(), $discarded( 'dnt' ), $discarded( 'signature' ) );
$check( 'bot con dnt=1: 204', 204, $send_hit( array( 'dnt' => '1' ), '198.51.100.47', $googlebot ) );
$check( 'bot con firma errata: 204', 204, $send_hit( $payload( $post_a, array( 's' => 'firma-sbagliata' ) ), '198.51.100.48', $googlebot ) );
$check( 'bot con dnt o firma errata: nessuna riga', $before[0], $count_hits() );
$check( 'bot con dnt=1: scarto dnt +1', $before[1] + 1, $discarded( 'dnt' ) );
$check( 'bot con firma errata: scarto signature +1', $before[2] + 1, $discarded( 'signature' ) );

// Una visita di un bot dichiarato esegue 2 query su wp5w_hits (una SELECT e una INSERT), come una visita normale.
$queries = array();
add_filter(
	'query',
	$bot_query_capture = static function ( $query ) use ( &$queries ) {
		$queries[] = (string) $query;
		return $query;
	}
);
$send_hit( $payload( $post_a ), '198.51.100.49', $googlebot );
remove_filter( 'query', $bot_query_capture );
$on_hits = array_values( array_filter( $queries, static fn( $q ) => str_contains( $q, $hits_table ) ) );
$check( 'visita di un bot: query su wp5w_hits', 2, count( $on_hits ) );
$check( 'visita di un bot: una SELECT e una INSERT', '1|1', count( array_filter( $on_hits, static fn( $q ) => 0 === stripos( ltrim( $q ), 'SELECT' ) ) ) . '|' . count( array_filter( $on_hits, static fn( $q ) => 0 === stripos( ltrim( $q ), 'INSERT' ) ) ) );

// Firma errata e tipo non valido.
$before = array( $count_hits(), $discarded( 'signature' ) );
$check( 'firma errata: 204', 204, $send_hit( $payload( $post_a, array( 's' => 'firma-sbagliata' ) ), '198.51.100.4', $chrome ) );
$check( 'tipo non valido: 204', 204, $send_hit( $payload( $post_a, array( 't' => 'Tipo NON valido!' ) ), '198.51.100.5', $chrome ) );
$check( 'firma e tipo: nessuna riga', $before[0], $count_hits() );
$check( 'firma e tipo: scarti +2', $before[1] + 2, $discarded( 'signature' ) );

// Parametri non scalari (t[]=post&id[]=4...): 204, uno scarto signature, nessuna riga e nessun warning PHP.
$before   = array( $count_hits(), $discarded( 'signature' ) );
$warnings = array();
// Cattura di ogni errore PHP generato durante la richiesta (quello atteso è "Array to string conversion").
set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_set_error_handler -- Prova isolata, il gestore viene tolto subito dopo.
	static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
		$warnings[] = $errstr;
		return true;
	}
);
$array_status = $send_hit(
	array(
		't'   => array( 'post' ),
		'id'  => array( $post_a ),
		's'   => array( 'x' ),
		'r'   => array( 'x' ),
		'u'   => array( 'x' ),
		'dnt' => array( '1' ),
	),
	'198.51.100.6',
	$chrome
);
restore_error_handler();
$check( 'parametri array: 204', 204, $array_status );
$check( 'parametri array: nessuna riga', $before[0], $count_hits() );
$check( 'parametri array: scarto signature +1', $before[1] + 1, $discarded( 'signature' ) );
$check( 'parametri array: nessun warning', '', implode( ' | ', $warnings ) );

// IP escluso con una regola CIDR.
update_option( Settings::OPTION, array( 'excluded_ips' => array( '203.0.113.0/24' ) ) + Settings::defaults() );
$before = array( $count_hits(), $discarded( 'ip' ) );
$check( 'ip escluso: 204', 204, $send_hit( $payload( $post_a ), '203.0.113.9', $chrome ) );
$check( 'ip escluso: nessuna riga', $before[0], $count_hits() );
$check( 'ip escluso: scarto +1', $before[1] + 1, $discarded( 'ip' ) );
update_option( Settings::OPTION, Settings::defaults() );

// Amministratore con il solo cookie di login e senza nonce: la REST riporta l'utente a 0.
$admins   = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$admin_id = (int) ( $admins[0] ?? 1 );
$login_as = static function ( int $user_id ): void {
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in' );
	wp_set_current_user( 0 );
};

$login_as( $admin_id );
$before = array( $count_hits(), $discarded( 'user' ) );
$check( 'amministratore (admins): 204', 204, $send_hit( $payload( $post_a ), '198.51.100.10', $chrome ) );
$check( 'amministratore (admins): nessuna riga', $before[0], $count_hits() );
$check( 'amministratore (admins): scarto user +1', $before[1] + 1, $discarded( 'user' ) );

update_option( Settings::OPTION, array( 'exclude_users' => 'none' ) + Settings::defaults() );
$check( 'amministratore (none): 204', 204, $send_hit( $payload( $post_a ), '198.51.100.10', $chrome ) );
$check( 'amministratore (none): registrata', $before[0] + 1, $count_hits() );

// Abbonato creato dal test: escluso con logged_in, registrato con admins.
$subscriber_id = (int) wp_insert_user(
	array(
		'user_login' => 'wp5w_verify_subscriber',
		'user_pass'  => wp_generate_password(),
		'user_email' => 'wp5w-verify@example.com',
		'role'       => 'subscriber',
	)
);
$login_as( $subscriber_id );
update_option( Settings::OPTION, array( 'exclude_users' => 'logged_in' ) + Settings::defaults() );
$before = array( $count_hits(), $discarded( 'user' ) );
$check( 'abbonato (logged_in): 204', 204, $send_hit( $payload( $post_a ), '198.51.100.11', $chrome ) );
$check( 'abbonato (logged_in): nessuna riga', $before[0], $count_hits() );
$check( 'abbonato (logged_in): scarto user +1', $before[1] + 1, $discarded( 'user' ) );
update_option( Settings::OPTION, Settings::defaults() );
$before = $count_hits();
$check( 'abbonato (admins): 204', 204, $send_hit( $payload( $post_a ), '198.51.100.11', $chrome ) );
$check( 'abbonato (admins): registrato', $before + 1, $count_hits() );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
wp_delete_user( $subscriber_id );

// Do Not Track: parametro dnt=1 e header DNT: 1.
$before = array( $count_hits(), $discarded( 'dnt' ) );
$check( 'dnt=1: 204', 204, $send_hit( array( 'dnt' => '1' ), '198.51.100.12', $chrome ) );
$_SERVER['HTTP_DNT'] = '1';
$check( 'header DNT: 204', 204, $send_hit( $payload( $post_a ), '198.51.100.13', $chrome ) );
$check( 'dnt: nessuna riga', $before[0], $count_hits() );
$check( 'dnt: scarti +2', $before[1] + 2, $discarded( 'dnt' ) );
update_option( Settings::OPTION, array( 'respect_dnt' => false ) + Settings::defaults() );
$check( 'header DNT con respect_dnt spento: 204', 204, $send_hit( $payload( $post_a ), '198.51.100.13', $chrome ) );
$check( 'header DNT con respect_dnt spento: registrata', $before[0] + 1, $count_hits() );
$before_dnt = array( $count_hits(), $discards_n() );
$check( 'dnt=1 con respect_dnt spento: 204', 204, $send_hit( array( 'dnt' => '1' ), '198.51.100.14', $chrome ) );
$check( 'dnt=1 con respect_dnt spento: nessuna riga e nessuno scarto', implode( '|', $before_dnt ), implode( '|', array( $count_hits(), $discards_n() ) ) );
unset( $_SERVER['HTTP_DNT'] );
update_option( Settings::OPTION, Settings::defaults() );

// Raccolta spenta: 204, nessuna riga e nessuno scarto.
update_option( Settings::OPTION, array( 'own_tracking' => false ) + Settings::defaults() );
$before = array( $count_hits(), $discards_n() );
$check( 'raccolta spenta: 204', 204, $send_hit( $payload( $post_a ), '198.51.100.15', $chrome ) );
$check( 'raccolta spenta: nessuna riga e nessuno scarto', implode( '|', $before ), implode( '|', array( $count_hits(), $discards_n() ) ) );
update_option( Settings::OPTION, Settings::defaults() );

// Una visita registrata: una SELECT e una INSERT su wp5w_hits e nessuna scrittura in wp_options (chiave del giorno già valida).
$queries       = array();
$capture_query = static function ( $query ) use ( &$queries ) {
	$queries[] = (string) $query;
	return $query;
};
add_filter( 'query', $capture_query );
$send_hit( $payload( $post_a, array( 'r' => 'https://www.bing.com/' ) ), '198.51.100.16', $chrome );
remove_filter( 'query', $capture_query );
$on_hits = array_values( array_filter( $queries, static fn( $q ) => str_contains( $q, $hits_table ) ) );
$check( 'una visita: query su wp5w_hits', 2, count( $on_hits ) );
$check( 'una visita: una SELECT', 1, count( array_filter( $on_hits, static fn( $q ) => 0 === stripos( ltrim( $q ), 'SELECT' ) ) ) );
$check( 'una visita: una INSERT', 1, count( array_filter( $on_hits, static fn( $q ) => 0 === stripos( ltrim( $q ), 'INSERT' ) ) ) );
$check( 'una visita: nessuna scrittura in wp_options', 0, count( array_filter( $queries, static fn( $q ) => 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b.*' . preg_quote( $wpdb->options, '/' ) . '/is', $q ) ) ) );

// Limite di frequenza: 31 visite dallo stesso IP e UA, 30 righe e uno scarto rate.
$before = array( $count_hits(), $discarded( 'rate' ) );
for ( $wp5w_i = 0; $wp5w_i < 31; $wp5w_i++ ) {
	$send_hit( $payload( $post_a ), '198.51.100.77', $chrome );
}
$check( 'frequenza: 30 righe', $before[0] + 30, $count_hits() );
$check( 'frequenza: scarto rate +1', $before[1] + 1, $discarded( 'rate' ) );

// Stesso limite con uno user agent di bot dichiarato: 31 visite, 30 righe e uno scarto rate.
$before = array( $count_hits(), $discarded( 'rate' ) );
for ( $wp5w_i = 0; $wp5w_i < 31; $wp5w_i++ ) {
	$send_hit( $payload( $post_a ), '198.51.100.78', $googlebot );
}
$check( 'frequenza con bot dichiarato: 30 righe', $before[0] + 30, $count_hits() );
$check( 'frequenza con bot dichiarato: scarto rate +1', $before[1] + 1, $discarded( 'rate' ) );

// Colonna bot: solo valori dell'elenco fisso, e lo scarto bot non è mai aumentato in tutta la sezione.
$allowed_bot = array_merge( array( '', Mavida\FiveW\Support\BotFilter::LABEL_SHORT, Mavida\FiveW\Support\BotFilter::LABEL_WEBDRIVER ), Mavida\FiveW\Support\BotFilter::SIGNATURES );
$found_bot   = $wpdb->get_col( "SELECT DISTINCT bot FROM {$hits_table}" );
$check( 'colonna bot: solo valori dell\'elenco fisso', '', implode( ',', array_diff( $found_bot, $allowed_bot ) ) );
$check( 'sezione 5: nessuno scarto bot', 0, $discarded( 'bot' ) );

// Chiave del giorno: valida per oggi; una chiave di ieri viene rigenerata e non resta salvata.
$check( 'chiave del giorno: inizia con la data di oggi', true, str_starts_with( (string) get_option( 'wp5w_daily_salt', '' ), $today . '|' ) );
$yesterday_key = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) . '|' . str_repeat( 'ab', 32 );
update_option( 'wp5w_daily_salt', $yesterday_key );
$send_hit( $payload( $post_a ), '198.51.100.20', $chrome );
$stored_key = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wp5w_daily_salt' ) );
$check( 'chiave del giorno: rigenerata alla prima visita', true, str_starts_with( $stored_key, $today . '|' ) );
$check( 'chiave del giorno: la vecchia non è più salvata', false, str_contains( $stored_key, str_repeat( 'ab', 32 ) ) );

// Nessun IP o user agent salvato, in nessuna forma.
$check( 'privacy: nessun IP nelle righe grezze', 0, $wpdb->get_var( "SELECT COUNT(*) FROM {$hits_table} WHERE uri LIKE '%198.51.100.%' OR referrer_host LIKE '%198.51.100.%'" ) );

// Nessun transient del plugin.
$check(
	'nessun transient wp5w',
	0,
	$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wp5w%' OR option_name LIKE '\\_transient\\_timeout\\_wp5w%'" )
);

// Tracker nel frontend: attivo sugli articoli, spento in anteprima, nei feed, negli embed e con la raccolta spenta
// (il Customizer e la cache di LiteSpeed si provano a mano, vedi la PR).
$tracker      = new Tracker();
$track_query  = static function ( array $args ) use ( $tracker ): bool {
	global $wp_query;
	$saved    = $wp_query;
	$wp_query = new WP_Query( $args ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Prova isolata, il valore originale viene ripristinato.
	$result   = $tracker->should_track();
	$wp_query = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Ripristino.
	return $result;
};
wp_set_current_user( $admin_id );
$check( 'tracker: articolo tracciato', true, $track_query( array( 'p' => $post_a ) ) );
$check( 'tracker: anteprima non tracciata', false, $track_query( array( 'p' => $post_a, 'preview' => true ) ) );
$check( 'tracker: feed non tracciato', false, $track_query( array( 'feed' => 'rss2' ) ) );
$check( 'tracker: embed non tracciato', false, $track_query( array( 'p' => $post_a, 'embed' => true ) ) );
update_option( Settings::OPTION, array( 'own_tracking' => false ) + Settings::defaults() );
$check( 'tracker: raccolta spenta non tracciata', false, $track_query( array( 'p' => $post_a ) ) );
update_option( Settings::OPTION, Settings::defaults() );
wp_set_current_user( 0 );

// ---------------------------------------------------------------------------
// 6. Aggregazione oraria.
// ---------------------------------------------------------------------------
// Chiave di ieri e nessuna visita: il passaggio orario la cancella; quella di oggi invece resta (e si ripristina).
$today_key = get_option( 'wp5w_daily_salt', false );
update_option( 'wp5w_daily_salt', $yesterday_key );
( new OwnSource() )->run_hourly();
$check( 'chiave di ieri: cancellata da run_hourly()', false, get_option( 'wp5w_daily_salt', false ) );
update_option( 'wp5w_daily_salt', $today_key );
( new OwnSource() )->run_hourly();
$check( 'chiave di oggi: resta dopo run_hourly()', $today_key, get_option( 'wp5w_daily_salt', false ) );
$own_table = static fn( string $name ): string => Schema::table( $name );
$own_site  = $wpdb->get_row( $wpdb->prepare( 'SELECT visitors, views FROM ' . $own_table( 'site_daily' ) . " WHERE origin = 'own' AND day = %s", $today ) );
$check( 'own site_daily.visitors', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor) FROM {$hits_table} WHERE day = %s", $today ) ), $own_site->visitors ?? 'nessuna riga' );
$check( 'own site_daily.views', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$hits_table} WHERE day = %s", $today ) ), $own_site->views ?? 'nessuna riga' );
$check( 'own object_daily.views', $wpdb->get_var( "SELECT COUNT(*) FROM {$hits_table}" ), $wpdb->get_var( 'SELECT SUM(views) FROM ' . $own_table( 'object_daily' ) . " WHERE origin = 'own'" ) );
$check(
	'own entry_daily.visitors',
	$wpdb->get_var( "SELECT COUNT(DISTINCT day, object_type, object_id, channel, visitor) FROM {$hits_table} WHERE is_entry = 1" ),
	$wpdb->get_var( 'SELECT SUM(visitors) FROM ' . $own_table( 'entry_daily' ) . " WHERE origin = 'own'" )
);
$check(
	'own dim_daily (canale) = visitatori del sito',
	$own_site->visitors ?? 'nessuna riga',
	$wpdb->get_var( 'SELECT SUM(visitors) FROM ' . $own_table( 'dim_daily' ) . " WHERE origin = 'own' AND dim = 'channel' AND day = '{$today}'" )
);
// Le visite segnalate (colonna bot) entrano negli aggregati own (0.11.0).
$normal_visitors = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor) FROM {$hits_table} WHERE day = %s AND bot = ''", $today ) );
$check( 'own site_daily.visitors comprende le visite segnalate', true, (int) ( $own_site->visitors ?? 0 ) > $normal_visitors );
$dims_total = array();
foreach ( array( 'channel', 'source', 'country', 'device', 'browser', 'platform' ) as $wp5w_dim ) {
	$dims_total[ $wp5w_dim ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(visitors), 0) FROM ' . $own_table( 'dim_daily' ) . " WHERE origin = 'own' AND day = %s AND dim = %s", $today, $wp5w_dim ) );
}
$check( 'own dim_daily: stessa somma per le sei dimensioni', (string) (int) ( $own_site->visitors ?? 0 ), implode( ',', array_unique( array_values( $dims_total ) ) ) );
$check(
	'own object_daily.views dell\'articolo di prova comprende le visite segnalate',
	$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$hits_table} WHERE day = %s AND object_type = 'post' AND object_id = %d", $today, $post_a ) ),
	$wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(views), 0) FROM ' . $own_table( 'object_daily' ) . " WHERE origin = 'own' AND day = %s AND object_type = 'post' AND object_id = %d", $today, $post_a ) )
);
$own_checksum = static function () use ( $wpdb ): string {
	$out = '';
	foreach ( Mavida\FiveW\Support\Origin::TABLES as $name ) {
		$out .= $name . ':' . $wpdb->get_var( 'CHECKSUM TABLE ' . Schema::table( $name ), 1 ) . ';';
	}
	return $out;
};
$checksum_before = $own_checksum();
( new OwnSource() )->run_hourly();
$check( 'secondo run_hourly identico', $checksum_before, $own_checksum() );

// Importer completo: la raccolta propria è tra le sorgenti e le sue chiavi non sovrascrivono quelle di WP Statistics.
$state = Plugin::importer()->run( Importer::full_range(), 'full' );
$check( 'importer con la raccolta propria senza errori', '1', $state['ok'] ? '1' : '0' );
$check( 'importer: righe wp_statistics e own distinte', '1', isset( $state['rows']['site_daily'], $state['rows']['own:site_daily'] ) ? '1' : '0' );

// ---------------------------------------------------------------------------
// 7. Non regressione: con righe own presenti e wp5w_own_since vuota, l'interfaccia è identica a prima.
// ---------------------------------------------------------------------------
$ui_after = $ui_snapshot();
foreach ( $ui_before as $wp5w_label => $wp5w_json ) {
	$check( "non regressione: {$wp5w_label}", md5( $wp5w_json ), md5( $ui_after[ $wp5w_label ] ) );
}

// ---------------------------------------------------------------------------
// 8. Passaggio simulato e opzioni.
// ---------------------------------------------------------------------------
update_option( Settings::OWN_SINCE, $today );
$week          = DateRange::last_days( 7, $today );
$wps_before    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(visitors), 0) FROM ' . $own_table( 'site_daily' ) . " WHERE origin = 'wps' AND day BETWEEN %s AND %s AND day < %s", $week->from, $week->to, $today ) );
$own_today     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(visitors), 0) FROM ' . $own_table( 'site_daily' ) . " WHERE origin = 'own' AND day = %s", $today ) );
$overview_week = ( new OverviewQuery() )->get( $week );
$check( 'passaggio: visitatori = wps prima di oggi + own di oggi', $wps_before + $own_today, $overview_week['kpis']['visitors']['value'] );

$admin_user = static function ( int $id ): void {
	wp_set_current_user( $id );
};
$rest_call  = static function ( string $method, string $route, array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
};
$admin_user( $admin_id );

$check( 'POST settings con data futura: 400', 400, $rest_call( 'POST', '/wp5w/v1/admin/settings', array( 'own_since' => wp_date( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) ) )->get_status() );
$check( 'POST settings con data prima dei dati own: 400', 400, $rest_call( 'POST', '/wp5w/v1/admin/settings', array( 'own_since' => wp_date( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ) ) )->get_status() );
$check( 'POST settings con data non valida: 400', 400, $rest_call( 'POST', '/wp5w/v1/admin/settings', array( 'own_since' => '2026-13-45' ) )->get_status() );
$check( 'POST settings: la data salvata non è cambiata', $today, Settings::own_since() );

$bad_ips = $rest_call( 'POST', '/wp5w/v1/admin/settings', array( 'excluded_ips' => array( '203.0.113.0/24', '999.1.1.1' ) ) );
$bad_msg = (string) ( $bad_ips->get_data()['data']['params']['excluded_ips'] ?? '' );
$check( 'POST settings con IP non valido: 400', 400, $bad_ips->get_status() );
$check( 'POST settings con IP non valido: messaggio con la riga errata', true, str_contains( $bad_msg, '999.1.1.1' ) );

$purges = 0;
add_action(
	'litespeed_purge_all',
	static function () use ( &$purges ): void {
		++$purges;
	}
);
$saved = $rest_call(
	'POST',
	'/wp5w/v1/admin/settings',
	array(
		'excluded_ips'   => array( '203.0.113.0/24' ),
		'exclude_users'  => 'logged_in',
		'retention_days' => 60,
		'own_tracking'   => false,
	)
);
$data  = $saved->get_data();
$check( 'POST settings valide: 200', 200, $saved->get_status() );
$check( 'POST settings: valori salvati', '60|logged_in|203.0.113.0/24|0', ( $data['retention_days'] ?? '' ) . '|' . ( $data['exclude_users'] ?? '' ) . '|' . implode( ',', $data['excluded_ips'] ?? array() ) . '|' . (int) ( $data['own_tracking'] ?? 1 ) );
$check( 'POST settings: purge di LiteSpeed alla modifica di own_tracking', 1, $purges );
$rest_call( 'POST', '/wp5w/v1/admin/settings', array( 'retention_days' => 61 ) );
$check( 'POST settings: nessun purge se own_tracking e DNT non cambiano', 1, $purges );
$get = $rest_call( 'GET', '/wp5w/v1/admin/settings' )->get_data();
$check( 'GET settings: chiavi', 'own_tracking,exclude_users,excluded_ips,respect_dnt,retention_days,own_since,own_first_day', implode( ',', array_keys( $get ) ) );
$check( 'GET settings: own_first_day', $today, $get['own_first_day'] ?? '' );
$check( 'GET settings: own_since', $today, $get['own_since'] ?? '' );
update_option( Settings::OPTION, Settings::defaults() );
$admin_user( 0 );

// Senza amministratore la rotta è negata.
$check( 'GET settings senza permessi: 401 o 403', true, in_array( $rest_call( 'GET', '/wp5w/v1/admin/settings' )->get_status(), array( 401, 403 ), true ) );

update_option( Settings::OWN_SINCE, '' );

// ---------------------------------------------------------------------------
// 9. L'ETL di WP Statistics lascia intatte le righe own, e l'aggregazione non tocca le righe wps.
// ---------------------------------------------------------------------------
/**
 * Impronta delle righe di una tabella aggregata per origine.
 *
 * @param string $origin Origine (wps o own).
 * @return string
 */
$rows_digest = static function ( string $origin ) use ( $wpdb ): string {
	$out = '';
	foreach ( Mavida\FiveW\Support\Origin::TABLES as $name ) {
		$table   = Schema::table( $name );
		$columns = count( $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE origin = %s ORDER BY " . implode( ',', range( 1, $columns ) ), $origin ), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out    .= $name . ':' . count( $rows ) . ':' . md5( (string) wp_json_encode( $rows ) ) . ';';
	}
	return $out;
};
$own_digest = $rows_digest( 'own' );
$wps_digest = $rows_digest( 'wps' );
( new Importer( array( new Mavida\FiveW\Sources\WpStatisticsSource() ) ) )->run( Importer::full_range(), 'full' );
$check( 'ETL di WP Statistics: righe own invariate', $own_digest, $rows_digest( 'own' ) );
( new Importer( array( new OwnSource() ) ) )->run( Importer::full_range(), 'full' );
$check( 'aggregazione own: righe wps invariate', $wps_digest, $rows_digest( 'wps' ) );
$check( 'aggregazione own ripetuta: righe own invariate', $own_digest, $rows_digest( 'own' ) );

// ---------------------------------------------------------------------------
// Cron: l'evento orario si pianifica e si toglie alla disattivazione.
// ---------------------------------------------------------------------------
$check( 'cron: wp5w_own_hourly pianificato', true, (bool) wp_next_scheduled( Mavida\FiveW\Etl\Scheduler::OWN_HOURLY_HOOK ) );
Mavida\FiveW\Deactivator::deactivate();
$check( 'cron: wp5w_own_hourly tolto alla disattivazione', false, (bool) wp_next_scheduled( Mavida\FiveW\Etl\Scheduler::OWN_HOURLY_HOOK ) );
$check( 'cron: wp5w_etl_daily tolto alla disattivazione', false, (bool) wp_next_scheduled( Mavida\FiveW\Etl\Scheduler::DAILY_HOOK ) );
( new Mavida\FiveW\Etl\Scheduler( Plugin::importer() ) )->register();
$check( 'cron: wp5w_own_hourly ripianificato', true, (bool) wp_next_scheduled( Mavida\FiveW\Etl\Scheduler::OWN_HOURLY_HOOK ) );

// ---------------------------------------------------------------------------
// 10. Confronto con WP Statistics (GET /wp5w/v1/admin/compare).
// ---------------------------------------------------------------------------
// Passo 1: si salvano le righe own, gli scarti e lo stato dell'ETL (le visite simulate delle sezioni 5-9 non servono),
// poi si inseriscono a mano righe own note. D è oggi e D-n il giorno di n giorni prima.
$d = array();
for ( $wp5w_n = 0; $wp5w_n <= 4; $wp5w_n++ ) {
	$d[ $wp5w_n ] = ( new DateTimeImmutable( $today ) )->modify( "-{$wp5w_n} days" )->format( 'Y-m-d' );
}

$own_backup = array();
foreach ( Mavida\FiveW\Support\Origin::TABLES as $wp5w_name ) {
	$own_backup[ $wp5w_name ] = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( $wp5w_name ) . " WHERE origin = 'own'", ARRAY_A );
	$wpdb->query( 'DELETE FROM ' . Schema::table( $wp5w_name ) . " WHERE origin = 'own'" );
}
$discards_backup = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'discards' ), ARRAY_A );
$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( 'discards' ) );
$state_backup = get_option( Importer::STATE_OPTION, false );

// Righe wps di D-4 ... D-1: se il seed non le ha (dump diverso), si inseriscono con valori noti e si tolgono alla fine.
$wps_inserted = array();
for ( $wp5w_n = 1; $wp5w_n <= 4; $wp5w_n++ ) {
	$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'site_daily' ) . " WHERE origin = 'wps' AND day = %s", $d[ $wp5w_n ] ) );
	if ( ! $exists ) {
		$wpdb->insert(
			Schema::table( 'site_daily' ),
			array(
				'day'      => $d[ $wp5w_n ],
				'visitors' => 10,
				'views'    => 20,
				'origin'   => 'wps',
			)
		);
		$wps_inserted[] = $d[ $wp5w_n ];
	}
}

$post_uri    = '/articolo-' . $post_a . '/';
$compare_own = array(
	array( 'site_daily', array( 'day' => $d[4], 'visitors' => 1, 'views' => 1 ) ),
	array( 'site_daily', array( 'day' => $d[3], 'visitors' => 40, 'views' => 60 ) ),
	array( 'site_daily', array( 'day' => $d[2], 'visitors' => 50, 'views' => 70 ) ),
	// Il primo articolo del seed: 30 visualizzazioni in tutto. Un contenuto con 5 visualizzazioni sta sotto la soglia di 10.
	array( 'object_daily', array( 'day' => $d[3], 'object_type' => 'post', 'object_id' => $post_a, 'uri' => $post_uri, 'views' => 15, 'visitors' => 5 ) ),
	array( 'object_daily', array( 'day' => $d[2], 'object_type' => 'post', 'object_id' => $post_a, 'uri' => $post_uri, 'views' => 15, 'visitors' => 5 ) ),
	array( 'object_daily', array( 'day' => $d[3], 'object_type' => 'post', 'object_id' => 999999, 'uri' => '/solo-own/', 'views' => 5, 'visitors' => 2 ) ),
	// Una categoria con lo stesso ID del primo articolo: il titolo non deve essere quello dell'articolo.
	array( 'object_daily', array( 'day' => $d[3], 'object_type' => 'category', 'object_id' => $post_a, 'uri' => '/categoria-prova/', 'views' => 12, 'visitors' => 3 ) ),
	array( 'entry_daily', array( 'day' => $d[3], 'object_type' => 'home', 'object_id' => 0, 'channel' => 'direct', 'visitors' => 4 ) ),
	array( 'dim_daily', array( 'day' => $d[3], 'dim' => 'channel', 'value' => 'direct', 'visitors' => 30 ) ),
	array( 'dim_daily', array( 'day' => $d[3], 'dim' => 'channel', 'value' => 'search', 'visitors' => 10 ) ),
	// La raccolta propria scrive «unassigned» dove WP Statistics scrive «(n/d)»: nel confronto devono essere una sola riga (R10.1).
	array( 'dim_daily', array( 'day' => $d[3], 'dim' => 'channel', 'value' => 'unassigned', 'visitors' => 3 ) ),
);
foreach ( $compare_own as [ $wp5w_name, $wp5w_row ] ) {
	$wpdb->insert( Schema::table( $wp5w_name ), $wp5w_row + array( 'origin' => 'own' ) );
}
$compare_discards = array(
	array( $d[4], 'bot', 100 ),
	array( $d[3], 'bot', 5 ),
	array( $d[3], 'rate', 1 ),
);
foreach ( $compare_discards as [ $wp5w_day_value, $wp5w_reason, $wp5w_count ] ) {
	$wpdb->insert(
		Schema::table( 'discards' ),
		array(
			'day'    => $wp5w_day_value,
			'reason' => $wp5w_reason,
			'n'      => $wp5w_count,
		)
	);
}

// L'ultimo ETL è partito adesso, con `to` = oggi: ieri (D-1) è completo per WP Statistics.
update_option(
	Importer::STATE_OPTION,
	array(
		'mode'    => 'daily',
		'from'    => $d[4],
		'to'      => $d[0],
		'started' => time(),
		'ok'      => true,
	),
	false
);

// Passo 2: permessi e intestazione.
$compare_route = '/wp5w/v1/admin/compare';
$admin_user( 0 );
$check( 'confronto senza utente: 401 o 403', true, in_array( $rest_call( 'GET', $compare_route )->get_status(), array( 401, 403 ), true ) );
$admin_user( $admin_id );
$compare_response = $rest_call( 'GET', $compare_route );
$compare          = $compare_response->get_data();
$check( 'confronto con amministratore: 200', 200, $compare_response->get_status() );
$check( 'confronto: Cache-Control no-store', 'no-store, max-age=0', $compare_response->get_headers()['Cache-Control'] ?? '' );

// Passo 3: copertura.
$coverage = $compare['coverage'] ?? array();
$excluded = array_map( static fn( $item ) => $item['day'] . ':' . $item['reason'], $coverage['excluded'] ?? array() );
$check( 'confronto: available', true, $compare['available'] ?? false );
$check( 'confronto: coverage.from = D-3', $d[3], $coverage['from'] ?? '' );
$check( 'confronto: coverage.to = D-1', $d[1], $coverage['to'] ?? '' );
$check( 'confronto: days = D-3, D-2', $d[3] . ',' . $d[2], implode( ',', array_column( $compare['days'] ?? array(), 'day' ) ) );
$check( 'confronto: coverage.days = 2', 2, $coverage['days'] ?? '' );
$check( 'confronto: D-4 escluso come partial', true, in_array( $d[4] . ':partial', $excluded, true ) );
$check( 'confronto: D-1 escluso come no_own', true, in_array( $d[1] . ':no_own', $excluded, true ) );
$check( 'confronto: oggi escluso come today', true, in_array( $d[0] . ':today', $excluded, true ) );
$check( 'confronto: own_first_day = D-4', $d[4], $coverage['own_first_day'] ?? '' );
$check( 'confronto: etl_ok', true, $coverage['etl_ok'] ?? false );

// Passo 4: giorni, differenze e segnalazione.
$own_expected = array(
	$d[3] => array( 40, 60 ),
	$d[2] => array( 50, 70 ),
);
foreach ( $compare['days'] ?? array() as $wp5w_row ) {
	$wps = $wpdb->get_row( $wpdb->prepare( 'SELECT visitors, views FROM ' . Schema::table( 'site_daily' ) . " WHERE origin = 'wps' AND day = %s", $wp5w_row['day'] ) );
	$check( "confronto {$wp5w_row['day']}: wps", ( $wps->visitors ?? '' ) . '|' . ( $wps->views ?? '' ), $wp5w_row['wps']['visitors'] . '|' . $wp5w_row['wps']['views'] );
	$check( "confronto {$wp5w_row['day']}: own", implode( '|', $own_expected[ $wp5w_row['day'] ] ), $wp5w_row['own']['visitors'] . '|' . $wp5w_row['own']['views'] );
	$wp5w_diff = Mavida\FiveW\Support\Metrics::change( $wp5w_row['own']['visitors'], $wp5w_row['wps']['visitors'] );
	$check( "confronto {$wp5w_row['day']}: diff.visitors", $wp5w_diff, $wp5w_row['diff']['visitors'] );
	$check(
		"confronto {$wp5w_row['day']}: flagged",
		Mavida\FiveW\Support\Comparison::flagged( $wp5w_diff, Mavida\FiveW\Support\Metrics::change( $wp5w_row['own']['views'], $wp5w_row['wps']['views'] ) ),
		$wp5w_row['flagged']
	);
}

// Passo 5: totali (senza D-4, che è parziale).
$check( 'confronto: totals.own visitatori', 90, $compare['totals']['own']['visitors'] ?? '' );
$check( 'confronto: totals.own visualizzazioni', 130, $compare['totals']['own']['views'] ?? '' );

// Passo 6: scarti (i 100 di D-4 non contano).
$check( 'confronto: scarti bot = 5, non 105', 5, $compare['discards']['reasons']['bot']['n'] ?? '' );
$check( 'confronto: scarti rate = 1', 1, $compare['discards']['reasons']['rate']['n'] ?? '' );
$check( 'confronto: quota bot', round( 5 / ( 130 + 6 ) * 100, 1 ), $compare['discards']['reasons']['bot']['share'] ?? '' );
$check( 'confronto: scarti totali', 6, $compare['discards']['total'] ?? '' );

// Passo 7: gruppo sospetto e canali.
$channels_own = array_column( $compare['channels'] ?? array(), 'own', 'channel' );
$check( 'confronto: home_direct.own = 4', 4, $compare['home_direct']['own'] ?? '' );
$check( 'confronto: canale direct own = 30', 30, $channels_own['direct'] ?? '' );
$wps_n_d = (int) $wpdb->get_var(
	$wpdb->prepare( 'SELECT COALESCE(SUM(visitors), 0) FROM ' . Schema::table( 'dim_daily' ) . " WHERE origin = 'wps' AND dim = 'channel' AND value IN ('(n/d)', 'unassigned') AND day IN (%s, %s)", $d[3], $d[2] )
);
$channels_wps = array_column( $compare['channels'] ?? array(), 'wps', 'channel' );
$check( 'confronto: nessuna riga (n/d) nei canali', false, in_array( '(n/d)', array_keys( $channels_own ), true ) );
$check( 'confronto: una sola riga unassigned', 1, count( array_filter( array_column( $compare['channels'] ?? array(), 'channel' ), static fn( $channel ) => 'unassigned' === $channel ) ) );
$check( 'confronto: unassigned con le due origini', $wps_n_d . '|3', ( $channels_wps['unassigned'] ?? 'nessuna riga' ) . '|' . ( $channels_own['unassigned'] ?? 'nessuna riga' ) );
$check( 'confronto: min_views = 10', 10, $compare['min_views'] ?? '' );
$check( 'confronto: threshold presente', true, isset( $compare['threshold'] ) );

// Passo 8: classifica.
$top_rows = $compare['top'] ?? array();
$top_own  = array_column( array_filter( $top_rows, static fn( $row ) => 'post' === $row['type'] ), 'own_views', 'id' );
$check( 'confronto: il primo articolo è in top con 30 visualizzazioni', 30, $top_own[ $post_a ] ?? '' );
$category_row = array_values( array_filter( $top_rows, static fn( $row ) => 'category' === $row['type'] ) )[0] ?? array();
$check( 'confronto: una categoria in top ha per titolo la sua URI, non l articolo con lo stesso ID', '/categoria-prova/', $category_row['title'] ?? '' );
$check( 'confronto: il contenuto con 5 visualizzazioni non è in top', false, isset( $top_own[999999] ) );
$check( 'confronto: top al massimo 10 righe', true, count( $top_rows ) <= 10 );
$diff_abs = array_column( $top_rows, 'diff_abs' );
$sorted   = $diff_abs;
rsort( $sorted );
$check( 'confronto: top ordinata per differenza assoluta', implode( ',', $sorted ), implode( ',', $diff_abs ) );

// Passo 9: ETL non ancora passato oggi (partito ieri a mezzogiorno): D-1 è in attesa e la finestra finisce a D-2.
update_option(
	Importer::STATE_OPTION,
	array(
		'mode'    => 'daily',
		'from'    => $d[4],
		'to'      => $d[1],
		'started' => ( new DateTimeImmutable( 'yesterday noon', wp_timezone() ) )->getTimestamp(),
		'ok'      => true,
	),
	false
);
$pending = $rest_call( 'GET', $compare_route )->get_data()['coverage'] ?? array();
$check( 'confronto con ETL di ieri: coverage.to = D-2', $d[2], $pending['to'] ?? '' );
$check(
	'confronto con ETL di ieri: D-1 in etl_pending',
	true,
	in_array( $d[1] . ':etl_pending', array_map( static fn( $item ) => $item['day'] . ':' . $item['reason'], $pending['excluded'] ?? array() ), true )
);

// Passo 10: la rotta non scrive nulla.
$compare_queries = array();
$compare_capture = static function ( $query ) use ( &$compare_queries ) {
	$compare_queries[] = (string) $query;
	return $query;
};
add_filter( 'query', $compare_capture );
$rest_call( 'GET', $compare_route );
remove_filter( 'query', $compare_capture );
$check( 'confronto: nessuna INSERT, UPDATE, DELETE o REPLACE', 0, count( array_filter( $compare_queries, static fn( $q ) => 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $q ) ) ) );

// Passo 11: con wp5w_own_since vuota l'interfaccia resta identica anche con queste righe own.
// L'istantanea di partenza è senza utente (edit_url dipende dai permessi), quindi si confronta senza utente.
$admin_user( 0 );
$ui_compare = $ui_snapshot();
$admin_user( $admin_id );
foreach ( $ui_before as $wp5w_label => $wp5w_json ) {
	$check( "confronto, non regressione: {$wp5w_label}", md5( $wp5w_json ), md5( $ui_compare[ $wp5w_label ] ) );
}

// Passo 12: senza righe own la rotta risponde con available falso.
foreach ( Mavida\FiveW\Support\Origin::TABLES as $wp5w_name ) {
	$wpdb->query( 'DELETE FROM ' . Schema::table( $wp5w_name ) . " WHERE origin = 'own'" );
}
$check( 'confronto senza righe own: available falso', false, $rest_call( 'GET', $compare_route )->get_data()['available'] ?? true );

// Passo 13: si rimette tutto come prima della sezione.
foreach ( $own_backup as $wp5w_name => $wp5w_rows ) {
	foreach ( $wp5w_rows as $wp5w_row ) {
		$wpdb->insert( Schema::table( $wp5w_name ), $wp5w_row );
	}
}
$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( 'discards' ) );
foreach ( $discards_backup as $wp5w_row ) {
	$wpdb->insert( Schema::table( 'discards' ), $wp5w_row );
}
foreach ( $wps_inserted as $wp5w_day_value ) {
	$wpdb->delete(
		Schema::table( 'site_daily' ),
		array(
			'day'    => $wp5w_day_value,
			'origin' => 'wps',
		)
	);
}
if ( false === $state_backup ) {
	delete_option( Importer::STATE_OPTION );
} else {
	update_option( Importer::STATE_OPTION, $state_backup, false );
}
$check( 'confronto: righe own ripristinate', md5( $own_digest ), md5( $rows_digest( 'own' ) ) );
$admin_user( 0 );

// ---------------------------------------------------------------------------
// 11. Pubblico, paese e user agent (fase 3).
// ---------------------------------------------------------------------------
// Passo 0: si salvano lo stato del database dei paesi e l'impronta del file, che i controlli simulano di rompere.
$geo_path          = CountryDatabase::path();
$geo_dir           = CountryDatabase::dir();
$geo_option_backup = get_option( CountryDatabase::OPTION, false );
$geo_this_month    = gmdate( 'Y-m' );
$geo_prev_month    = ( new DateTimeImmutable( $geo_this_month . '-01' ) )->modify( '-1 month' )->format( 'Y-m' );
$geo_prev2_month   = ( new DateTimeImmutable( $geo_this_month . '-01' ) )->modify( '-2 months' )->format( 'Y-m' );
$admin_user( 0 );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
update_option( Settings::OPTION, Settings::defaults() );
update_option( Settings::OWN_SINCE, '' );

// Passo 1: box «Pubblico» con i dati wps (wp5w_own_since vuota).
$dim_route = '/wp5w/v1/admin/audience/dimensions';
$check( 'pubblico senza utente: 401 o 403', true, in_array( $rest_call( 'GET', $dim_route, array( 'days' => 30 ) )->get_status(), array( 401, 403 ), true ) );
$admin_user( $admin_id );
$box_response = $rest_call( 'GET', $dim_route, array( 'days' => 30 ) );
$box          = $box_response->get_data();
$check( 'pubblico con amministratore: 200', 200, $box_response->get_status() );
$check( 'pubblico: Cache-Control no-store', 'no-store, max-age=0', $box_response->get_headers()['Cache-Control'] ?? '' );
$check( 'pubblico con days=15: 400', 400, $rest_call( 'GET', $dim_route, array( 'days' => 15 ) )->get_status() );
$check( 'pubblico: periodo di 30 giorni compreso oggi', DateRange::last_days( 30, $today )->from . '|' . $today, ( $box['range']['from'] ?? '' ) . '|' . ( $box['range']['to'] ?? '' ) );
$check( 'pubblico: le quattro dimensioni', implode( ',', Mavida\FiveW\Support\Dimensions::DIMS ), implode( ',', array_keys( $box['dims'] ?? array() ) ) );

$dim_table = Schema::table( 'dim_daily' );
foreach ( Mavida\FiveW\Support\Dimensions::DIMS as $wp5w_dim ) {
	$wp5w_from  = (string) ( $box['range']['from'] ?? '' );
	$wp5w_to    = (string) ( $box['range']['to'] ?? '' );
	$wp5w_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(visitors), 0) FROM {$dim_table} WHERE origin = 'wps' AND dim = %s AND day BETWEEN %s AND %s", $wp5w_dim, $wp5w_from, $wp5w_to ) );
	$wp5w_rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT IF(value IN ('', 'UNK'), '(n/d)', value) AS v, SUM(visitors) AS n FROM {$dim_table} WHERE origin = 'wps' AND dim = %s AND day BETWEEN %s AND %s GROUP BY v ORDER BY n DESC, BINARY v LIMIT 10",
			$wp5w_dim,
			$wp5w_from,
			$wp5w_to
		),
		ARRAY_A
	);
	$wp5w_sql   = implode( ',', array_map( static fn( $r ) => $r['v'] . ':' . (int) $r['n'], $wp5w_rows ) );
	$wp5w_api   = implode( ',', array_map( static fn( $r ) => $r['value'] . ':' . $r['visitors'], $box['dims'][ $wp5w_dim ]['rows'] ?? array() ) );
	$check( "pubblico {$wp5w_dim}: total = SUM(dim_daily wps)", $wp5w_total, $box['dims'][ $wp5w_dim ]['total'] ?? '' );
	$check( "pubblico {$wp5w_dim}: righe = SELECT diretta", $wp5w_sql, $wp5w_api );
}

// Con i dati del seed (le visite di WP Statistics sono tutte da smartphone Android con Chrome, da IT o US).
if ( get_page_by_path( 'calendario-2006', OBJECT, 'post' ) ) {
	$seed_countries = array_column( $box['dims']['country']['rows'] ?? array(), 'value' );
	sort( $seed_countries );
	$check( 'pubblico seed: paesi', 'IT,US', implode( ',', $seed_countries ) );
	$check( 'pubblico seed: dispositivi', 'smartphone', implode( ',', array_column( $box['dims']['device']['rows'] ?? array(), 'value' ) ) );
	$check( 'pubblico seed: browser', 'Chrome', implode( ',', array_column( $box['dims']['browser']['rows'] ?? array(), 'value' ) ) );
	$check( 'pubblico seed: sistemi', 'Android', implode( ',', array_column( $box['dims']['platform']['rows'] ?? array(), 'value' ) ) );
}
$admin_user( 0 );

// Passo 2: download reale del database. Con il file già del mese corrente lo si tiene, altrimenti update() deve riuscire.
$geo_saved = is_array( $geo_option_backup ) ? $geo_option_backup : array();
if ( ! is_readable( $geo_path ) || ( $geo_saved['month'] ?? '' ) !== $geo_this_month ) {
	$geo_update = CountryDatabase::update();
	$check( 'geo: update() riuscito', 1, (int) $geo_update['ok'] );
}
$geo_now         = get_option( CountryDatabase::OPTION, array() );
$geo_option_good = get_option( CountryDatabase::OPTION, false );
$geo_md5         = is_readable( $geo_path ) ? md5_file( $geo_path ) : '';
$check( 'geo: il file esiste ed è oltre 1 MB', true, is_readable( $geo_path ) && filesize( $geo_path ) > 1048576 );
$check( 'geo: opzione con il mese corrente o precedente', true, in_array( $geo_now['month'] ?? '', array( $geo_this_month, $geo_prev_month ), true ) );
$check( 'geo: opzione senza errore', '', $geo_now['error'] ?? 'x' );
$check( 'geo: .htaccess con Require all denied', true, str_contains( (string) @file_get_contents( $geo_dir . '/.htaccess' ), 'Require all denied' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lettura di un file locale della prova.
$check( 'geo: index.php presente', true, file_exists( $geo_dir . '/index.php' ) );
if ( ( $geo_now['month'] ?? '' ) === $geo_this_month ) {
	$geo_http_calls  = 0;
	$geo_count_calls = static function ( $pre, $args, $url ) use ( &$geo_http_calls ) {
		++$geo_http_calls;
		return $pre;
	};
	add_filter( 'pre_http_request', $geo_count_calls, 10, 3 );
	$geo_again = CountryDatabase::update();
	remove_filter( 'pre_http_request', $geo_count_calls, 10 );
	$check( 'geo: secondo update() senza richieste di rete', 0, $geo_http_calls );
	$check( 'geo: secondo update() riuscito', 1, (int) $geo_again['ok'] );
}

// Passo 3: ricerche.
$check( 'geo: 8.8.8.8 = US', 'US', CountryDatabase::lookup( '8.8.8.8' ) );
$check( 'geo: IP privato = vuoto', '', CountryDatabase::lookup( '10.0.0.1' ) );
$check( 'geo: IP non valido = vuoto', '', CountryDatabase::lookup( 'non-un-ip' ) );
$check( 'geo: IPv6 = IT', 'IT', CountryDatabase::lookup( '2a00:1450:4002::1' ) );

// Passo 4: errori di download simulati con pre_http_request (il corpo va scritto in $args['filename'], come fa WordPress).
$geo_db = (string) file_get_contents( $geo_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- File locale della prova.
$geo_gz = (string) gzencode( $geo_db );
$geo_tmp_files = static fn(): int => count( (array) glob( $geo_dir . '/*.tmp' ) );

$geo_mock = null;
$geo_mock_filter = static function ( $pre, $args, $url ) use ( &$geo_mock ) {
	if ( null === $geo_mock || ! str_contains( (string) $url, 'download.db-ip.com' ) ) {
		return $pre;
	}
	[ $code, $body ] = $geo_mock( (string) $url );
	if ( null !== $body && ! empty( $args['filename'] ) ) {
		file_put_contents( $args['filename'], $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Simula il download in streaming.
	}
	return array(
		'headers'  => array(),
		'body'     => $body ?? '',
		'response' => array(
			'code'    => $code,
			'message' => 404 === $code ? 'Not Found' : 'Simulato',
		),
		'cookies'  => array(),
		'filename' => $args['filename'] ?? null,
	);
};
add_filter( 'pre_http_request', $geo_mock_filter, 10, 3 );

/**
 * Forza il mese salvato nell'opzione (così update() sceglie i mesi da provare).
 *
 * @param string $month Mese Y-m.
 * @return void
 */
$geo_force_month = static function ( string $month ): void {
	update_option( CountryDatabase::OPTION, array( 'month' => $month ) + (array) get_option( CountryDatabase::OPTION, array() ), false );
};
$geo_state = static fn(): string => (string) wp_json_encode( get_option( CountryDatabase::OPTION, array() ) );

// Caso 1: 404 per tutti i mesi, con il mese precedente già installato: non è un errore.
$geo_force_month( $geo_prev_month );
$geo_mock = static fn( string $url ): array => array( 404, null );
$r        = CountryDatabase::update();
$o        = get_option( CountryDatabase::OPTION, array() );
$check( 'geo errori 1 (404): ok', 1, (int) $r['ok'] );
$check( 'geo errori 1 (404): file identico', $geo_md5, md5_file( $geo_path ) );
$check( 'geo errori 1 (404): month invariato', $geo_prev_month, $o['month'] ?? '' );
$check( 'geo errori 1 (404): error vuoto', '', $o['error'] ?? 'x' );
$check( 'geo errori 1 (404): nessun .tmp', 0, $geo_tmp_files() );

// Caso 2: il corpo non è un gzip.
$geo_force_month( $geo_prev_month );
$geo_mock = static fn( string $url ): array => array( 200, 'questo non è un gzip, è solo testo' );
$r        = CountryDatabase::update();
$o        = get_option( CountryDatabase::OPTION, array() );
$check( 'geo errori 2 (non gzip): ok falso', 0, (int) $r['ok'] );
$check( 'geo errori 2 (non gzip): file identico', $geo_md5, md5_file( $geo_path ) );
$check( 'geo errori 2 (non gzip): error non vuoto', true, '' !== ( $o['error'] ?? '' ) );
$check( 'geo errori 2 (non gzip): nessun .tmp', 0, $geo_tmp_files() );

// Caso 3: gzip dei primi 2 MB del database (troncato).
$geo_force_month( $geo_prev_month );
$geo_truncated = (string) gzencode( substr( $geo_db, 0, 2 * 1048576 ) );
$geo_mock      = static fn( string $url ): array => array( 200, $geo_truncated );
$r             = CountryDatabase::update();
$check( 'geo errori 3 (database troncato): ok falso', 0, (int) $r['ok'] );
$check( 'geo errori 3 (database troncato): file identico', $geo_md5, md5_file( $geo_path ) );
$check( 'geo errori 3 (database troncato): nessun .tmp', 0, $geo_tmp_files() );

// Caso 4: gzip valido di 2 MB di byte casuali (metadati non trovati).
$geo_force_month( $geo_prev_month );
$geo_random = (string) gzencode( random_bytes( 2 * 1048576 ) );
$geo_mock   = static fn( string $url ): array => array( 200, $geo_random );
$r          = CountryDatabase::update();
$check( 'geo errori 4 (byte casuali): ok falso', 0, (int) $r['ok'] );
$check( 'geo errori 4 (byte casuali): file identico', $geo_md5, md5_file( $geo_path ) );
$check( 'geo errori 4 (byte casuali): nessun .tmp', 0, $geo_tmp_files() );

// Caso 5: mese corrente 404 e mese precedente valido (ripiego). Il mese installato è di due mesi fa, quindi si provano entrambi.
$geo_force_month( $geo_prev2_month );
$geo_mock = static fn( string $url ): array => str_contains( $url, $geo_this_month ) ? array( 404, null ) : array( 200, $geo_gz );
$r        = CountryDatabase::update();
$o        = get_option( CountryDatabase::OPTION, array() );
$check( 'geo errori 5 (ripiego): ok', 1, (int) $r['ok'] );
$check( 'geo errori 5 (ripiego): month = mese precedente', $geo_prev_month, $o['month'] ?? '' );
$check( 'geo errori 5 (ripiego): file valido e uguale', $geo_md5, md5_file( $geo_path ) );
$check( 'geo errori 5 (ripiego): nessun .tmp', 0, $geo_tmp_files() );

// Caso 6: 503 per il mese corrente: è un errore vero, non «non ancora pubblicato».
$geo_force_month( $geo_prev_month );
$geo_mock = static fn( string $url ): array => array( 503, null );
$r        = CountryDatabase::update();
$o        = get_option( CountryDatabase::OPTION, array() );
$check( 'geo errori 6 (503): ok falso', 0, (int) $r['ok'] );
$check( 'geo errori 6 (503): error non vuoto', true, str_contains( (string) ( $o['error'] ?? '' ), '503' ) );
$check( 'geo errori 6 (503): file identico', $geo_md5, md5_file( $geo_path ) );

// Caso 7: gzip reale tagliato a metà (il guasto di rete più realistico).
$geo_force_month( $geo_prev_month );
$geo_half = substr( $geo_gz, 0, intdiv( strlen( $geo_gz ), 2 ) );
$geo_mock = static fn( string $url ): array => array( 200, $geo_half );
$r        = CountryDatabase::update();
$check( 'geo errori 7 (gzip a metà): ok falso', 0, (int) $r['ok'] );
$check( 'geo errori 7 (gzip a metà): file identico', $geo_md5, md5_file( $geo_path ) );
$check( 'geo errori 7 (gzip a metà): nessun .tmp', 0, $geo_tmp_files() );

// Fine dei casi: si toglie il filtro e si rimette l'opzione com'era dopo il download del passo 2.
$geo_mock = null;
remove_filter( 'pre_http_request', $geo_mock_filter, 10 );
update_option( CountryDatabase::OPTION, $geo_option_good, false );
unset( $geo_db, $geo_gz, $geo_truncated, $geo_random, $geo_half );

// Passo 5: lock tenuto da una seconda connessione (nella stessa sessione MySQL il lock è rientrante e non bloccherebbe).
$geo_state_before = $geo_state();
$other            = new mysqli( $wp5w_host, DB_USER, DB_PASSWORD, DB_NAME, (int) $wp5w_port );
$other->query( "SELECT GET_LOCK( 'wp5w_geo_update', 0 )" );
$r = CountryDatabase::update();
$check( 'geo lock occupato: ok falso', 0, (int) $r['ok'] );
$check( 'geo lock occupato: messaggio «già in corso»', true, str_contains( $r['message'], 'già in corso' ) );
$check( 'geo lock occupato: opzione invariata', $geo_state_before, $geo_state() );
$other->query( "SELECT RELEASE_LOCK( 'wp5w_geo_update' )" );
$other->close();

// Passo 6: database assente. Il paese resta vuoto, la visita è registrata e non ci sono warning.
rename( $geo_path, $geo_path . '.bak' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Prova: il file si rimette subito dopo.
$check( 'geo assente: lookup vuoto', '', CountryDatabase::lookup( '8.8.8.8' ) );
$check( 'geo assente: status().available falso', false, CountryDatabase::status()['available'] );
$warnings = array();
set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_set_error_handler -- Prova isolata, il gestore viene tolto subito dopo.
	static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
		$warnings[] = $errstr;
		return true;
	}
);
$geo_status = $send_hit( $payload( $post_a, array( 'u' => '/wp5w-v11-senza-db/' ) ), '8.8.8.8', $chrome );
restore_error_handler();
rename( $geo_path . '.bak', $geo_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Si rimette il file.
$no_db = $wpdb->get_row( "SELECT country, device FROM {$hits_table} WHERE uri = '/wp5w-v11-senza-db/'" );
$check( 'geo assente: visita 204', 204, $geo_status );
$check( 'geo assente: visita registrata con paese vuoto', '|desktop', ( $no_db->country ?? 'nessuna riga' ) . '|' . ( $no_db->device ?? '' ) );
$check( 'geo assente: nessun warning', '', implode( ' | ', $warnings ) );

// Passo 7: visite con paese e user agent.
$ua_iphone   = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
$ua_nintendo = 'Mozilla/5.0 (Nintendo Switch; WifiWebAuthApplet) AppleWebKit/606.4 (KHTML, like Gecko) NF/6.0.1.15.4 NintendoBrowser/5.1.0.20393';
$check( 'visita da 8.8.8.8 (Chrome Windows): 204', 204, $send_hit( $payload( $post_a, array( 'u' => '/wp5w-v11-us/' ) ), '8.8.8.8', $chrome ) );
$check( 'visita da 151.38.1.1 (iPhone): 204', 204, $send_hit( $payload( $post_a, array( 'u' => '/wp5w-v11-it/' ) ), '151.38.1.1', $ua_iphone ) );
$check( 'visita da 198.51.100.30 (Nintendo): 204', 204, $send_hit( $payload( $post_a, array( 'u' => '/wp5w-v11-nd/' ) ), '198.51.100.30', $ua_nintendo ) );
$geo_row = static function ( string $uri ) use ( $wpdb, $hits_table ): string {
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT country, device, browser, os FROM {$hits_table} WHERE uri = %s", $uri ) );
	return $row ? implode( '|', array( $row->country, $row->device, $row->browser, $row->os ) ) : 'nessuna riga';
};
$check( 'visita US: country, device, browser, os', 'US|desktop|Chrome|Windows', $geo_row( '/wp5w-v11-us/' ) );
$check( 'visita IT: country, device, browser, os', 'IT|smartphone|Mobile Safari|iOS', $geo_row( '/wp5w-v11-it/' ) );
$check( 'visita senza paese né user agent noto: registrata con campi vuoti', '|||', $geo_row( '/wp5w-v11-nd/' ) );
$before = array( $count_hits(), $discarded( 'bot' ) );
$check( 'bot da 8.8.8.8: 204', 204, $send_hit( $payload( $post_a, array( 'u' => '/wp5w-v11-bot/' ) ), '8.8.8.8', 'Googlebot/2.1 (+http://www.google.com/bot.html)' ) );
$check( 'bot da 8.8.8.8: registrato, una riga in più', $before[0] + 1, $count_hits() );
$check( 'bot da 8.8.8.8: etichetta bot e paese US', 'bot|US', $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT_WS( '|', bot, country ) FROM {$hits_table} WHERE uri = %s", '/wp5w-v11-bot/' ) ) );
$check( 'bot da 8.8.8.8: scarto bot invariato', $before[1], $discarded( 'bot' ) );
// La riga di prova si toglie: i controlli seguenti contano le visite per paese.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$hits_table} WHERE uri = %s", '/wp5w-v11-bot/' ) );

// Passo 8: nessuna query in più. Dopo il riscaldamento (chiave del giorno valida, database presente) una visita registrata
// esegue solo la SELECT e la INSERT su wp5w_hits: il numero totale di query è 2 e nessuna riguarda wp5w_geo.
$send_hit( $payload( $post_a, array( 'u' => '/wp5w-v11-warm/' ) ), '8.8.4.4', $chrome );
$queries = array();
add_filter( 'query', $capture_query );
$check( 'visita con paese: 204', 204, $send_hit( $payload( $post_a, array( 'u' => '/wp5w-v11-query/' ) ), '8.8.8.8', $ua_iphone ) );
remove_filter( 'query', $capture_query );
$check( 'nessuna query in più: totale delle query = query su wp5w_hits', count( array_filter( $queries, static fn( $q ) => str_contains( $q, $hits_table ) ) ), count( $queries ) );
$check( 'nessuna query in più: due query', 2, count( $queries ) );
$check( 'nessuna query in più: nessuna su wp5w_geo', 0, count( array_filter( $queries, static fn( $q ) => str_contains( $q, 'wp5w_geo' ) ) ) );
if ( 2 !== count( $queries ) ) {
	WP_CLI::log( 'Query catturate: ' . implode( ' || ', array_map( static fn( $q ) => substr( trim( $q ), 0, 120 ), $queries ) ) );
}

// Passo 9: privacy. Nessuna colonna di wp5w_hits contiene un IP usato nei test o una parte dello user agent.
$privacy_columns = "CONCAT_WS('|', object_type, uri, referrer_host, channel, source, utm_source, utm_medium, utm_campaign, country, device, browser, os, bot)";
$privacy_hits    = 0;
foreach ( array( '8.8.8.8', '8.8.4.4', '151.38.1.1', '198.51.100.30', 'Mozilla', 'AppleWebKit' ) as $wp5w_needle ) {
	$privacy_hits += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$hits_table} WHERE {$privacy_columns} LIKE %s", '%' . $wpdb->esc_like( $wp5w_needle ) . '%' ) );
}
$check( 'privacy: nessun IP né user agent nelle colonne di wp5w_hits', 0, $privacy_hits );

// Passo 10: aggregazione. La somma dei visitatori è uguale per tutte le dimensioni e ai visitatori own di site_daily.
$wps_digest_before = $rows_digest( 'wps' );
( new OwnSource() )->run_hourly();
$own_dims = array();
foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT dim, SUM(visitors) AS n FROM {$dim_table} WHERE origin = 'own' AND day = %s GROUP BY dim", $today ), ARRAY_A ) as $wp5w_row ) {
	$own_dims[ $wp5w_row['dim'] ] = (int) $wp5w_row['n'];
}
$own_visitors = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT visitors FROM ' . Schema::table( 'site_daily' ) . " WHERE origin = 'own' AND day = %s", $today ) );
ksort( $own_dims );
// L'invariante «stessa somma per ogni dimensione» vale per queste sei: le dimensioni utm_* ne sono escluse di proposito (F6).
$check( 'aggregazione: dimensioni own (senza utm_*)', 'browser,channel,country,device,platform,source', implode( ',', array_keys( array_filter( $own_dims, static fn( $dim ) => ! str_starts_with( $dim, 'utm_' ), ARRAY_FILTER_USE_KEY ) ) ) );
foreach ( array( 'channel', 'source', 'country', 'device', 'browser', 'platform' ) as $wp5w_dim ) {
	$check( "aggregazione: somma di {$wp5w_dim} = visitatori own del sito", $own_visitors, $own_dims[ $wp5w_dim ] ?? 'nessuna riga' );
}
$own_value = static fn( string $dim, string $value ): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(visitors), 0) FROM {$dim_table} WHERE origin = 'own' AND day = %s AND dim = %s AND value = %s", $today, $dim, $value ) );
$check( 'aggregazione: country US >= 1', true, $own_value( 'country', 'US' ) >= 1 );
$check( 'aggregazione: country IT >= 1', true, $own_value( 'country', 'IT' ) >= 1 );
$check( 'aggregazione: country (n/d) per le visite senza paese', true, $own_value( 'country', '(n/d)' ) >= 1 );
$check( 'aggregazione: platform Windows e iOS', true, $own_value( 'platform', 'Windows' ) >= 1 && $own_value( 'platform', 'iOS' ) >= 1 );
$check( 'aggregazione: nessuna riga own con dim = os', 0, $wpdb->get_var( "SELECT COUNT(*) FROM {$dim_table} WHERE origin = 'own' AND dim = 'os'" ) );
$check( 'aggregazione: righe wps invariate', $wps_digest_before, $rows_digest( 'wps' ) );

// Passo 11: regola dell'origine nel box.
$audience_query = new AudienceQuery( new SearchQuery() );
$box_range      = DateRange::last_days( 30, $today );
$check( 'pubblico con own_since vuota: identico al passo 1 (le righe own non entrano)', md5( (string) wp_json_encode( $box ) ), md5( (string) wp_json_encode( $audience_query->dimensions( $box_range ) ) ) );
update_option( Settings::OWN_SINCE, $today );
$box_own      = $audience_query->dimensions( $box_range );
$wps_country  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(visitors), 0) FROM {$dim_table} WHERE origin = 'wps' AND dim = 'country' AND day BETWEEN %s AND %s AND day < %s", $box_range->from, $box_range->to, $today ) );
$own_country  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(visitors), 0) FROM {$dim_table} WHERE origin = 'own' AND dim = 'country' AND day = %s", $today ) );
$check( 'pubblico con own_since = oggi: total dei paesi = wps prima di oggi + own di oggi', $wps_country + $own_country, $box_own['dims']['country']['total'] ?? '' );
$check( 'pubblico con own_since = oggi: first_day invariato', $box['first_day'] ?? '', $box_own['first_day'] ?? '' );
// Regressione dell'ottimizzatore di MariaDB: con la forma "( origin = 'wps' AND day < X ) OR ( origin = 'own' AND day >= X )" le righe own
// di dim_daily sparivano quando il periodo comprendeva la data di passaggio. Origin::where() deve restituire wps prima di oggi più own di oggi.
$straddle_sql = Mavida\FiveW\Support\Origin::where( $today );
$check(
	'regola dell\'origine su dim_daily con il periodo a cavallo della data di passaggio',
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(visitors), 0) FROM {$dim_table} WHERE dim = 'channel' AND origin = 'wps' AND day BETWEEN %s AND %s AND day < %s", $box_range->from, $box_range->to, $today ) )
		+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(visitors), 0) FROM {$dim_table} WHERE dim = 'channel' AND origin = 'own' AND day = %s", $today ) ),
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(visitors), 0) FROM {$dim_table} WHERE dim = 'channel' AND day BETWEEN %s AND %s AND {$straddle_sql}", $box_range->from, $box_range->to ) )
);
update_option( Settings::OWN_SINCE, '' );

// Passo 12: scheda Stato.
$admin_user( $admin_id );
$status_geo = $rest_call( 'GET', '/wp5w/v1/admin/status' )->get_data()['geo'] ?? array();
$admin_user( 0 );
$check( 'stato: geo.available', true, $status_geo['available'] ?? false );
$check( 'stato: geo.month non vuoto', true, '' !== ( $status_geo['month'] ?? '' ) );
$check( 'stato: geo.built > 0', true, ( $status_geo['built'] ?? 0 ) > 0 );

// Passo 13: cron giornaliero del database dei paesi.
$geo_event = wp_get_scheduled_event( Mavida\FiveW\Etl\Scheduler::GEO_HOOK );
$check( 'cron: wp5w_geo_update pianificato', true, (bool) wp_next_scheduled( Mavida\FiveW\Etl\Scheduler::GEO_HOOK ) );
$check( 'cron: wp5w_geo_update giornaliero', 'daily', $geo_event->schedule ?? '' );

// Passo 14: opzione e file sono quelli dopo il download del passo 2. Il database dei paesi non si cancella nella pulizia finale,
// così le esecuzioni successive non lo riscaricano (se l'opzione non esisteva all'inizio, ora c'è).
$check( 'geo: opzione come dopo il download', wp_json_encode( $geo_option_good ), $geo_state() );
$check( 'geo: file come dopo il download', $geo_md5, md5_file( $geo_path ) );
$check( 'geo: nessun file di prova rimasto', 0, count( (array) glob( $geo_dir . '/*.bak' ) ) + $geo_tmp_files() );

// ---------------------------------------------------------------------------
// 12. Fase 4: canale «ai», etichette, UTM, online adesso, 404, ricerche, visite nell'admin e modale da URL.
// ---------------------------------------------------------------------------
// Passo 0: stato pulito. Le righe own (e le visite grezze) delle sezioni precedenti si tolgono, così i conteggi di oggi sono quelli di questa sezione.
$admin_user( 0 );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
update_option( Settings::OPTION, Settings::defaults() );
update_option( Settings::OWN_SINCE, '' );
foreach ( array( 'hits', 'discards', 'notfound_daily', 'search_daily' ) as $wp5w_name ) {
	$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( $wp5w_name ) );
}
foreach ( Mavida\FiveW\Support\Origin::TABLES as $wp5w_name ) {
	$wpdb->query( 'DELETE FROM ' . Schema::table( $wp5w_name ) . " WHERE origin = 'own'" );
}
delete_option( 'wp5w_daily_salt' );

$notfound_table = Schema::table( 'notfound_daily' );
$search_table   = Schema::table( 'search_daily' );
$object_table   = Schema::table( 'object_daily' );
$range_30       = DateRange::last_days( 30, $today );
$seed_day       = wp_date( 'Y-m-d', time() - 5 * DAY_IN_SECONDS );
$has_seed       = (bool) get_page_by_path( 'calendario-2006', OBJECT, 'post' );

/**
 * Visitatori delle righe di una tabella aggregata, per origine, giorno, dimensione e valore.
 *
 * @param string $origin Origine.
 * @param string $day    Giorno.
 * @param string $dim    Dimensione.
 * @param string $value  Valore.
 * @return int
 */
$dim_value = static function ( string $origin, string $day, string $dim, string $value ) use ( $wpdb, $dim_table ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(visitors), 0) FROM {$dim_table} WHERE origin = %s AND day = %s AND dim = %s AND value = %s", $origin, $day, $dim, $value ) );
};

// Passo 1: canale «ai» sulle righe wps (dopo l'import del seed, sezione 3).
if ( $has_seed ) {
	$seed_visitors = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}statistics_visitor WHERE last_counter = %s", $seed_day ) );
	$check( 'ai wps: canale ai nel giorno del seed', 5, $dim_value( 'wps', $seed_day, 'channel', 'ai' ) );
	$check( 'ai wps: sorgente ChatGPT (referrer, source_name senza referrer e pacchetto Android)', 3, $dim_value( 'wps', $seed_day, 'source', 'ChatGPT' ) );
	$check( 'ai wps: sorgente Perplexity', 1, $dim_value( 'wps', $seed_day, 'source', 'Perplexity' ) );
	$check( 'ai wps: sorgente Claude', 1, $dim_value( 'wps', $seed_day, 'source', 'Claude' ) );
	$check( 'ai wps: l\'app di LinkedIn resta (n/d)', 2, $dim_value( 'wps', $seed_day, 'channel', '(n/d)' ) );
	foreach ( array( 'channel', 'source' ) as $wp5w_dim ) {
		$check( "ai wps: somma di {$wp5w_dim} = visitatori di statistics_visitor", $seed_visitors, $wpdb->get_var( $wpdb->prepare( "SELECT SUM(visitors) FROM {$dim_table} WHERE origin = 'wps' AND dim = %s AND day = %s", $wp5w_dim, $seed_day ) ) );
	}
	$check(
		'ai wps: entry_daily con 5 visitatori ai sul primo articolo',
		5,
		$wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(visitors), 0) FROM ' . Schema::table( 'entry_daily' ) . " WHERE origin = 'wps' AND day = %s AND object_type = 'post' AND object_id = %d AND channel = 'ai'", $seed_day, $post_a ) )
	);
	$ref_claude = $wpdb->get_row( $wpdb->prepare( 'SELECT channel, source FROM ' . Schema::table( 'referrer_daily' ) . " WHERE origin = 'wps' AND day = %s AND referrer = 'claude.ai'", $seed_day ) );
	$check( 'ai wps: referrer_daily claude.ai con canale ai e sorgente Claude', 'ai|Claude', ( $ref_claude->channel ?? '' ) . '|' . ( $ref_claude->source ?? '' ) );
	$ref_linkedin = $wpdb->get_row( $wpdb->prepare( 'SELECT channel FROM ' . Schema::table( 'referrer_daily' ) . " WHERE origin = 'wps' AND day = %s AND referrer = 'android-app://com.linkedin.android/'", $seed_day ) );
	$check( 'ai wps: referrer_daily dell\'app di LinkedIn senza canale', '', $ref_linkedin->channel ?? 'nessuna riga' );
}

// Passo 2: canale «ai» sulle righe own. Cinque visitatori con IP diversi.
$ai_visits = array(
	array( 'https://claude.ai/chat/x', '', 'referral' ),
	array( '', 'chatgpt.com', 'unassigned' ),
	array( 'https://www.perplexity.ai/search', '', 'referral' ),
	array( 'android-app://com.openai.chatgpt/', '', 'referral' ),
	array( 'https://www.google.com/', '', 'search' ),
);
foreach ( $ai_visits as $wp5w_n => $wp5w_visit ) {
	$send_hit( $payload( $post_a, array( 'r' => $wp5w_visit[0], 'us' => $wp5w_visit[1], 'u' => '/wp5w-v12-ai-' . $wp5w_n . '/' ) ), '192.0.2.' . ( 1 + $wp5w_n ), $chrome );
}
$raw_channels = $wpdb->get_col( "SELECT channel FROM {$hits_table} WHERE uri LIKE '/wp5w-v12-ai-%' ORDER BY id" );
$check( 'ai own: canali grezzi di wp5w_hits invariati', implode( ',', array_column( $ai_visits, 2 ) ), implode( ',', $raw_channels ) );
( new OwnSource() )->run_hourly();
$check( 'ai own: canale ai di oggi', 4, $dim_value( 'own', $today, 'channel', 'ai' ) );
$check( 'ai own: canale search di oggi', 1, $dim_value( 'own', $today, 'channel', 'search' ) );
$check( 'ai own: sorgente Claude', 1, $dim_value( 'own', $today, 'source', 'Claude' ) );
$check( 'ai own: sorgente ChatGPT (utm_source e pacchetto Android)', 2, $dim_value( 'own', $today, 'source', 'ChatGPT' ) );
$check( 'ai own: sorgente Perplexity', 1, $dim_value( 'own', $today, 'source', 'Perplexity' ) );
$check( 'ai own: entry_daily con visitatori ai', 4, $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(visitors), 0) FROM ' . Schema::table( 'entry_daily' ) . " WHERE origin = 'own' AND day = %s AND channel = 'ai'", $today ) ) );
$check( 'ai own: referrer_daily con tre siti ai (quello senza referrer non c\'è)', 3, $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'referrer_daily' ) . " WHERE origin = 'own' AND day = %s AND channel = 'ai'", $today ) ) );
$check( 'ai own: referrer_daily claude.ai con sorgente Claude', 'Claude', $wpdb->get_var( $wpdb->prepare( 'SELECT source FROM ' . Schema::table( 'referrer_daily' ) . " WHERE origin = 'own' AND day = %s AND referrer = 'claude.ai'", $today ) ) );

// Passo 2b (0.14.0): canale «bot». Un crawler AI (claudebot, con referrer claude.ai) va in `bot` e non in `ai`; la visita si toglie subito dopo,
// così i controlli seguenti ripartono dagli stessi numeri.
$send_hit( $payload( $post_a, array( 'r' => 'https://claude.ai/chat/x', 'u' => '/wp5w-v14-bot/' ) ), '192.0.2.9', 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)' );
( new OwnSource() )->run_hourly();
$check( 'bot own: etichetta della visita', 'claudebot', $wpdb->get_var( "SELECT bot FROM {$hits_table} WHERE uri = '/wp5w-v14-bot/'" ) );
$check( 'bot own: canale bot di oggi', 1, $dim_value( 'own', $today, 'channel', 'bot' ) );
$check( 'bot own: il canale ai resta a 4 (il crawler non ci entra)', 4, $dim_value( 'own', $today, 'channel', 'ai' ) );
$check( 'bot own: entry_daily con un visitatore bot', 1, $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(visitors), 0) FROM ' . Schema::table( 'entry_daily' ) . " WHERE origin = 'own' AND day = %s AND channel = 'bot'", $today ) ) );
$bot_visitors = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT visitors FROM ' . Schema::table( 'site_daily' ) . " WHERE origin = 'own' AND day = %s", $today ) );
$check( 'bot own: somma di channel = visitatori own del sito', $bot_visitors, $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(visitors), 0) FROM {$dim_table} WHERE origin = 'own' AND day = %s AND dim = 'channel'", $today ) ) );
$wpdb->query( "DELETE FROM {$hits_table} WHERE uri = '/wp5w-v14-bot/'" );
( new OwnSource() )->run_hourly();
$check( 'bot own: tolta la visita, il canale bot sparisce', 0, $dim_value( 'own', $today, 'channel', 'bot' ) );

/**
 * Controlla che le sei dimensioni dell'invariante (senza le utm_*) abbiano la stessa somma, uguale ai visitatori own del sito.
 *
 * @param string $label Prefisso dei controlli.
 * @return void
 */
$check_six_dims = static function ( string $label ) use ( $wpdb, $dim_table, $today, $check ): void {
	$visitors = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT visitors FROM ' . Schema::table( 'site_daily' ) . " WHERE origin = 'own' AND day = %s", $today ) );
	foreach ( array( 'channel', 'source', 'country', 'device', 'browser', 'platform' ) as $wp5w_dim ) {
		$check( "{$label}: somma di {$wp5w_dim} = visitatori own del sito", $visitors, $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(visitors), 0) FROM {$dim_table} WHERE origin = 'own' AND day = %s AND dim = %s", $today, $wp5w_dim ) ) );
	}
};
$check_six_dims( 'ai own' );

// Passo 2c (0.14.0): serie dei tre strumenti della Panoramica (`tools`). Legge le tre origini insieme (eccezione alla regola dell'origine);
// il giorno senza riga di uno strumento resta null, non zero.
$tools_range  = DateRange::last_days( 30, $today );
$tools        = ( new OverviewQuery() )->get( $tools_range )['tools'];
$tools_by_day = array_column( $tools, null, 'day' );
$check( 'tools: un punto per giorno del periodo', 30, count( $tools ) );
$check( 'tools: visitatori own di oggi = site_daily own', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT visitors FROM ' . Schema::table( 'site_daily' ) . " WHERE origin = 'own' AND day = %s", $today ) ), $tools_by_day[ $today ]['visitors_own'] );
$check( 'tools: visualizzazioni wps del giorno del seed = site_daily wps', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT views FROM ' . Schema::table( 'site_daily' ) . " WHERE origin = 'wps' AND day = %s", $seed_day ) ), $tools_by_day[ $seed_day ]['views_wps'] );
$check( 'tools: own prima della raccolta è null (non zero)', null, $tools_by_day[ $tools_range->from ]['visitors_own'] );
$check( 'tools: senza righe ia i visitatori ia sono null', null, $tools_by_day[ $today ]['visitors_ia'] );

// Passo 3: confronto. Giorni con righe ai in entrambe le origini, simulati come nella sezione 10.
$compare_backup = array();
foreach ( Mavida\FiveW\Support\Origin::TABLES as $wp5w_name ) {
	$compare_backup[ $wp5w_name ] = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( $wp5w_name ) . " WHERE origin = 'own'", ARRAY_A );
	$wpdb->query( 'DELETE FROM ' . Schema::table( $wp5w_name ) . " WHERE origin = 'own'" );
}
$state_backup = get_option( Importer::STATE_OPTION, false );
$wps_ai_days  = array();
foreach ( array( 4, 3, 2 ) as $wp5w_n ) {
	// Righe wps del sito: se il seed non le ha, si inseriscono e si tolgono alla fine.
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'site_daily' ) . " WHERE origin = 'wps' AND day = %s", $d[ $wp5w_n ] ) ) ) {
		$wpdb->insert( Schema::table( 'site_daily' ), array( 'day' => $d[ $wp5w_n ], 'visitors' => 10, 'views' => 20, 'origin' => 'wps' ) );
		$wps_ai_days[] = $d[ $wp5w_n ];
	}
	$wpdb->insert( Schema::table( 'site_daily' ), array( 'day' => $d[ $wp5w_n ], 'visitors' => 10, 'views' => 20, 'origin' => 'own' ) );
}
$wpdb->insert( $dim_table, array( 'day' => $d[3], 'dim' => 'channel', 'value' => 'ai', 'visitors' => 2, 'origin' => 'own' ) );
$wpdb->insert( $dim_table, array( 'day' => $d[3], 'dim' => 'channel', 'value' => 'ai', 'visitors' => 1, 'origin' => 'wps' ) );
update_option( Importer::STATE_OPTION, array( 'mode' => 'daily', 'from' => $d[4], 'to' => $d[0], 'started' => time(), 'ok' => true ), false );
$admin_user( $admin_id );
$compare_ai = array_column( $rest_call( 'GET', '/wp5w/v1/admin/compare' )->get_data()['channels'] ?? array(), null, 'channel' );
$admin_user( 0 );
$check( 'ai confronto: riga ai con wps e own', '1|2', ( $compare_ai['ai']['wps'] ?? 'nessuna riga' ) . '|' . ( $compare_ai['ai']['own'] ?? 'nessuna riga' ) );
$wpdb->delete( $dim_table, array( 'day' => $d[3], 'dim' => 'channel', 'value' => 'ai', 'origin' => 'wps' ) );
foreach ( $wps_ai_days as $wp5w_day_value ) {
	$wpdb->delete( Schema::table( 'site_daily' ), array( 'day' => $wp5w_day_value, 'origin' => 'wps' ) );
}
foreach ( Mavida\FiveW\Support\Origin::TABLES as $wp5w_name ) {
	$wpdb->query( 'DELETE FROM ' . Schema::table( $wp5w_name ) . " WHERE origin = 'own'" );
	foreach ( $compare_backup[ $wp5w_name ] as $wp5w_row ) {
		$wpdb->insert( Schema::table( $wp5w_name ), $wp5w_row );
	}
}
if ( false === $state_backup ) {
	delete_option( Importer::STATE_OPTION );
} else {
	update_option( Importer::STATE_OPTION, $state_backup, false );
}

// Passo 4: etichette delle app (solo presentazione: i dati nelle tabelle non cambiano).
if ( $has_seed ) {
	$seed_referrer = static fn( array $rows ): string => (string) ( array_column( $rows, 'label', 'referrer' )['android-app://com.linkedin.android/'] ?? 'nessuna riga' );
	$check( 'etichette: referrer della scheda Visitatori', 'LinkedIn (app)', $seed_referrer( ( new AudienceQuery( new SearchQuery() ) )->get()['referrers']['rows'] ) );
	$check( 'etichette: referrer del dettaglio', 'LinkedIn (app)', $seed_referrer( ( new PostsQuery() )->detail( $post_a, DateRange::last_days( 60, $today ) )['referrers'] ?? array() ) );
	$check( 'etichette: il dato in referrer_daily non cambia', 1, $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'referrer_daily' ) . " WHERE origin = 'wps' AND day = %s AND referrer = 'android-app://com.linkedin.android/'", $seed_day ) ) );
}
$send_hit( $payload( $post_a, array( 'r' => 'android-app://com.linkedin.android/', 'u' => '/wp5w-v12-app/' ) ), '192.0.2.9', $chrome );
( new OwnSource() )->run_hourly();
$check( 'etichette: referrer own com.linkedin.android', 1, $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'referrer_daily' ) . " WHERE origin = 'own' AND day = %s AND referrer = 'com.linkedin.android'", $today ) ) );
update_option( Settings::OWN_SINCE, $today );
$own_label = array_column( ( new AudienceQuery( new SearchQuery() ) )->get()['referrers']['rows'], 'label', 'referrer' )['com.linkedin.android'] ?? 'nessuna riga';
update_option( Settings::OWN_SINCE, '' );
$check( 'etichette: la riga own ha la stessa etichetta', 'LinkedIn (app)', $own_label );

// Passo 5: UTM. Due visitatori con tre visite (uno due volte) e un terzo senza UTM.
$utm = array( 'uc' => 'autunno', 'us' => 'linkedin', 'um' => 'social' );
$send_hit( $payload( $post_a, $utm + array( 'u' => '/wp5w-v12-utm-1/' ) ), '192.0.2.21', $chrome );
$send_hit( $payload( $post_b, $utm + array( 'u' => '/wp5w-v12-utm-2/' ) ), '192.0.2.21', $chrome );
$send_hit( $payload( $post_a, $utm + array( 'u' => '/wp5w-v12-utm-3/' ) ), '192.0.2.22', $chrome );
$send_hit( $payload( $post_a, array( 'u' => '/wp5w-v12-utm-4/' ) ), '192.0.2.23', $chrome );
( new OwnSource() )->run_hourly();
$check( 'utm: utm_campaign autunno', 2, $dim_value( 'own', $today, 'utm_campaign', 'autunno' ) );
$check( 'utm: utm_source linkedin', 2, $dim_value( 'own', $today, 'utm_source', 'linkedin' ) );
$check( 'utm: utm_medium social', 2, $dim_value( 'own', $today, 'utm_medium', 'social' ) );
$check( 'utm: nessuna riga utm_* con (n/d) o valore vuoto', 0, $wpdb->get_var( "SELECT COUNT(*) FROM {$dim_table} WHERE dim LIKE 'utm\\_%' AND value IN ('', '(n/d)')" ) );
$check( 'utm: nessuna riga wps con dimensione utm_*', 0, $wpdb->get_var( "SELECT COUNT(*) FROM {$dim_table} WHERE origin = 'wps' AND dim LIKE 'utm\\_%'" ) );
$check_six_dims( 'utm, invariante delle sei dimensioni' );

$campaigns_route = '/wp5w/v1/admin/audience/campaigns';
$check( 'campagne senza utente: 401 o 403', true, in_array( $rest_call( 'GET', $campaigns_route, array( 'days' => 30 ) )->get_status(), array( 401, 403 ), true ) );
$admin_user( $admin_id );
$campaigns_response = $rest_call( 'GET', $campaigns_route, array( 'days' => 30 ) );
$check( 'campagne con amministratore: 200', 200, $campaigns_response->get_status() );
$check( 'campagne: Cache-Control no-store', 'no-store, max-age=0', $campaigns_response->get_headers()['Cache-Control'] ?? '' );
$check( 'campagne con days=15: 400', 400, $rest_call( 'GET', $campaigns_route, array( 'days' => 15 ) )->get_status() );
$admin_user( 0 );
$audience_query = new AudienceQuery( new SearchQuery() );
$campaigns_empty = $audience_query->campaigns( $range_30 );
update_option( Settings::OWN_SINCE, $today );
$campaigns_today = $audience_query->campaigns( $range_30 );
update_option( Settings::OWN_SINCE, '' );
$check( 'campagne: stessi numeri con wp5w_own_since vuota e con la data di oggi', md5( (string) wp_json_encode( $campaigns_empty ) ), md5( (string) wp_json_encode( $campaigns_today ) ) );
$check( 'campagne: utm_campaign autunno = 2', 2, array_column( $campaigns_empty['dims']['utm_campaign']['rows'], 'visitors', 'value' )['autunno'] ?? 'nessuna riga' );
$check( 'campagne: first_day = primo giorno own di channel', $wpdb->get_var( "SELECT MIN(day) FROM {$dim_table} WHERE origin = 'own' AND dim = 'channel'" ), $campaigns_empty['first_day'] ?? '' );
$check( 'campagne: le tre dimensioni', 'utm_campaign,utm_source,utm_medium', implode( ',', array_keys( $campaigns_empty['dims'] ) ) );

// Passo 6: online adesso. Visite inserite direttamente (A due visite, B, C a cavallo della mezzanotte, D fuori dalla finestra).
$wpdb->query( "TRUNCATE TABLE {$hits_table}" );
$online_hashes = array();
$insert_online = static function ( string $who, int $seconds_ago, string $type, int $id, string $day ) use ( $wpdb, $hits_table, &$online_hashes ): void {
	$online_hashes[ $who ] = md5( 'wp5w-online-' . $who );
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$hits_table} (ts, day, visitor, object_type, object_id, uri) VALUES (%s, %s, UNHEX(%s), %s, %d, %s)",
			gmdate( 'Y-m-d H:i:s', time() - $seconds_ago ),
			$day,
			$online_hashes[ $who ],
			$type,
			$id,
			'/online-' . $who . '/'
		)
	);
};
$yesterday = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
$insert_online( 'a', 420, 'post', $post_a, $today );
$insert_online( 'a', 60, 'post', $post_b, $today );
$insert_online( 'b', 5, 'post', $post_b, $today );
$insert_online( 'c', 60, 'home', 0, $yesterday );
$insert_online( 'd', 360, 'post', $post_a, $today );
$online      = ( new OnlineQuery() )->get();
$online_rows = array_column( $online['contents'], null, 'id' );
$online_json = (string) wp_json_encode( $online );
$check( 'online: tre visitatori distinti', 3, $online['visitors'] ?? '' );
$check( 'online: il secondo articolo ha 2 visitatori (A sull\'ultima visita e B)', 2, $online_rows[ $post_b ]['visitors'] ?? 'nessuna riga' );
$check( 'online: la home ha 1 visitatore (a cavallo della mezzanotte) e il titolo Home', '1|Home', ( $online_rows[0]['visitors'] ?? 'nessuna riga' ) . '|' . ( $online_rows[0]['title'] ?? '' ) );
$check( 'online: il primo articolo non c\'è (A ha una visita più recente, D è fuori dalla finestra)', false, isset( $online_rows[ $post_a ] ) );
$check( 'online: nessun altro contenuto', 0, $online['other'] ?? '' );
$check( 'online: minuti e raccolta attiva', '5|1', ( $online['minutes'] ?? '' ) . '|' . (int) ( $online['tracking'] ?? 0 ) );
$check( 'online: nessun hash nella risposta', 0, count( array_filter( $online_hashes, static fn( $hash ) => str_contains( $online_json, $hash ) ) ) );
$check( 'online: nessuna chiave visitor o ts', 0, (int) str_contains( $online_json, '"visitor"' ) + (int) str_contains( $online_json, '"ts"' ) );
$online_route = '/wp5w/v1/admin/online';
$check( 'online senza utente: 401 o 403', true, in_array( $rest_call( 'GET', $online_route )->get_status(), array( 401, 403 ), true ) );
$admin_user( $admin_id );
$online_response = $rest_call( 'GET', $online_route );
$admin_user( 0 );
$check( 'online con amministratore: 200', 200, $online_response->get_status() );
$check( 'online: Cache-Control no-store', 'no-store, max-age=0', $online_response->get_headers()['Cache-Control'] ?? '' );
$wpdb->query( "TRUNCATE TABLE {$hits_table}" );

// Passo 7: pagine 404 (aggregati senza visitatore, solo per le visite registrate).
$sig_404 = Tracker::signature( '404', 0 );
$hit_404 = static fn( string $uri, string $referrer, string $ip, string $ua ): int => $send_hit( array( 't' => '404', 'id' => 0, 's' => $sig_404, 'u' => $uri, 'r' => $referrer ), $ip, $ua );
$nf_row  = static fn( string $uri, string $referrer ) => $wpdb->get_row( $wpdb->prepare( "SELECT hits, last_seen FROM {$notfound_table} WHERE day = %s AND uri = %s AND referrer = %s", $today, $uri, $referrer ) );
$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
$check( 'notfound: 204', 204, $hit_404( '/manca/', 'https://www.esterno.it/pagina?x=1', '192.0.2.31', $chrome ) );
$first_seen = $nf_row( '/manca/', 'esterno.it' );
$check( 'notfound: una riga con host del referrer e hits 1', '1', $first_seen->hits ?? 'nessuna riga' );
$hit_404( '/manca/', 'https://www.esterno.it/altra', '192.0.2.31', $chrome );
$second_seen = $nf_row( '/manca/', 'esterno.it' );
$check( 'notfound: la seconda visita porta hits a 2', '2', $second_seen->hits ?? 'nessuna riga' );
$check( 'notfound: last_seen non indietreggia', true, ( $second_seen->last_seen ?? '' ) >= ( $first_seen->last_seen ?? 'z' ) );
$hit_404( '/manca/', get_permalink( $post_a ), '192.0.2.32', $chrome );
$check( 'notfound: referrer del sito stesso = host del sito', '1', $nf_row( '/manca/', preg_replace( '/^www\./', '', $site_host ) )->hits ?? 'nessuna riga' );
$hit_404( '/manca-diretta/', '', '192.0.2.33', $chrome );
$check( 'notfound: referrer vuoto', '1', $nf_row( '/manca-diretta/', '' )->hits ?? 'nessuna riga' );
$nf_total = static fn(): int => (int) $wpdb->get_var( "SELECT COALESCE(SUM(hits), 0) FROM {$notfound_table}" );
$before   = $nf_total();
$hit_404( '/manca-bot/', '', '192.0.2.34', 'Googlebot/2.1 (+http://www.google.com/bot.html)' );
$check( 'notfound: un 404 da Googlebot si conta (bot segnalati, non scartati)', $before + 1, $nf_total() );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$notfound_table} WHERE uri = %s", '/manca-bot/' ) );
$send_hit( $payload( $post_a, array( 'u' => '/wp5w-v12-articolo/' ) ), '192.0.2.35', $chrome );
$check( 'notfound: nemmeno una visita a un articolo', $before, $nf_total() );

// URI codificato: la 404 con caratteri non ASCII si salva con gli ottetti %XX (non /citta/post/) e due URI diversi restano due righe.
$hit_404( '/citt%C3%A0/post%E2%80%9D/', '', '192.0.2.61', $chrome );
$check( 'notfound: URI codificato: si salva com è (%XX)', '1', $nf_row( '/citt%C3%A0/post%E2%80%9D/', '' )->hits ?? 'nessuna riga' );
$check( 'notfound: URI codificato: non si fonde con /citt/post/', 'nessuna riga', $nf_row( '/citt/post/', '' )->hits ?? 'nessuna riga' );
$hit_404( '/pagina%20con%20spazi/?x=1#y', '', '192.0.2.62', $chrome );
$check( 'notfound: spazio codificato, senza query string né frammento', '1', $nf_row( '/pagina%20con%20spazi/', '' )->hits ?? 'nessuna riga' );
$hit_404( '/<b>x"y/', '', '192.0.2.63', $chrome );
$check( 'notfound: i caratteri non ammessi in un percorso si tolgono', '1', $nf_row( '/bxy/', '' )->hits ?? 'nessuna riga' );

// Con il filtro query: una 404 registrata fa esattamente 3 query (SELECT e INSERT su wp5w_hits, poi l'INSERT sulle 404).
$queries = array();
add_filter( 'query', $capture_query );
$hit_404( '/manca-query/', 'https://www.esterno.it/', '192.0.2.36', $chrome );
remove_filter( 'query', $capture_query );
$check( 'notfound: una 404 fa 3 query', 3, count( $queries ) );
$check( 'notfound: due query su wp5w_hits', 2, count( array_filter( $queries, static fn( $q ) => str_contains( $q, $hits_table ) ) ) );
$check( 'notfound: una INSERT ON DUPLICATE KEY UPDATE sulla tabella delle 404', 1, count( array_filter( $queries, static fn( $q ) => str_contains( $q, $notfound_table ) && 0 === stripos( ltrim( $q ), 'INSERT' ) && str_contains( $q, 'ON DUPLICATE KEY UPDATE' ) ) ) );

// Lettura: internal e label.
$operations = new OperationsQuery();
$not_found  = $operations->not_found( $range_30 );
$nf_by_ref  = array();
foreach ( $not_found['rows'] as $wp5w_row ) {
	$nf_by_ref[ $wp5w_row['uri'] . '|' . $wp5w_row['referrer'] ] = $wp5w_row;
}
$site_key = '/manca/|' . preg_replace( '/^www\./', '', $site_host );
$check( 'notfound lettura: link esterno non interno, con etichetta', '0|esterno.it', (int) ( $nf_by_ref['/manca/|esterno.it']['internal'] ?? 1 ) . '|' . ( $nf_by_ref['/manca/|esterno.it']['label'] ?? '' ) );
$check( 'notfound lettura: link interno', 1, (int) ( $nf_by_ref[ $site_key ]['internal'] ?? 0 ) );
$check( 'notfound lettura: referrer vuoto senza etichetta', '0|', (int) ( $nf_by_ref['/manca-diretta/|']['internal'] ?? 1 ) . '|' . ( $nf_by_ref['/manca-diretta/|']['label'] ?? 'x' ) );
$check( 'notfound lettura: hits della riga più visitata', 2, $not_found['rows'][0]['hits'] ?? '' );
$check( 'notfound lettura: totale delle visite', $nf_total(), $not_found['hits'] ?? '' );
$check( 'notfound lettura: indirizzi distinti', 6, $not_found['uris'] ?? '' );
foreach ( array( '/wp5w/v1/admin/notfound', '/wp5w/v1/admin/searches' ) as $wp5w_route ) {
	$check( "{$wp5w_route} senza utente: 401 o 403", true, in_array( $rest_call( 'GET', $wp5w_route, array( 'days' => 30 ) )->get_status(), array( 401, 403 ), true ) );
	$admin_user( $admin_id );
	$check( "{$wp5w_route} con amministratore: 200", 200, $rest_call( 'GET', $wp5w_route, array( 'days' => 30 ) )->get_status() );
	$check( "{$wp5w_route} con days=15: 400", 400, $rest_call( 'GET', $wp5w_route, array( 'days' => 15 ) )->get_status() );
	$admin_user( 0 );
}

// Passo 8: ricerche interne.
$sig_search = Tracker::signature( 'search', 0 );
$hit_search = static fn( array $extra, string $ip ): int => $send_hit( $extra + array( 't' => 'search', 'id' => 0, 's' => $sig_search, 'u' => '/' ), $ip, $chrome );
$search_row = static fn( string $term ) => $wpdb->get_row( $wpdb->prepare( "SELECT searches, empty FROM {$search_table} WHERE day = %s AND term = %s", $today, $term ) );
$check( 'ricerche: 204', 204, $hit_search( array( 'q' => '  WordPress   Plugin ', 'n' => 5 ), '192.0.2.41' ) );
$row = $search_row( 'wordpress plugin' );
$check( 'ricerche: termine normalizzato con searches 1 ed empty 0', '1|0', ( $row->searches ?? 'nessuna riga' ) . '|' . ( $row->empty ?? '' ) );
$hit_search( array( 'q' => 'wordpress plugin', 'n' => 0 ), '192.0.2.42' );
$row = $search_row( 'wordpress plugin' );
$check( 'ricerche: con n = 0 empty sale a 1', '2|1', ( $row->searches ?? 'nessuna riga' ) . '|' . ( $row->empty ?? '' ) );
$hit_search( array( 'q' => 'wordpress plugin' ), '192.0.2.43' );
$row = $search_row( 'wordpress plugin' );
$check( 'ricerche: un n mancante conta come risultati sconosciuti (empty invariato)', '3|1', ( $row->searches ?? 'nessuna riga' ) . '|' . ( $row->empty ?? '' ) );
$hit_search( array( 'q' => 'mario.rossi@example.com', 'n' => 1 ), '192.0.2.44' );
$hit_search( array( 'q' => '333 1234567', 'n' => 1 ), '192.0.2.45' );
$check( 'ricerche: email e numero lungo vanno in (filtrato)', 2, $search_row( '(filtrato)' )->searches ?? 'nessuna riga' );
$check( 'ricerche: l\'email non è salvata', 0, $wpdb->get_var( "SELECT COUNT(*) FROM {$search_table} WHERE term LIKE '%@%' OR term LIKE '%1234567%'" ) );
$hit_search( array( 'q' => str_repeat( 'a', 100 ), 'n' => 1 ), '192.0.2.46' );
$check( 'ricerche: un termine di 100 caratteri dà un termine di 60', 1, $search_row( str_repeat( 'a', 60 ) )->searches ?? 'nessuna riga' );
$hit_search( array( 'q' => '50%ab', 'n' => 1 ), '192.0.2.64' );
$check( 'ricerche: il termine con %XX non perde nulla', 1, $search_row( '50%ab' )->searches ?? 'nessuna riga' );
$hit_search( array( 'q' => '<div>', 'n' => 1 ), '192.0.2.65' );
$check( 'ricerche: il termine tra < e > si conta', 1, $search_row( '<div>' )->searches ?? 'nessuna riga' );

// Termine vuoto o di soli spazi, q con un tipo diverso da search e q array: nessuna riga e nessun warning.
$search_total = static fn(): int => (int) $wpdb->get_var( "SELECT COALESCE(SUM(searches), 0) FROM {$search_table}" );
$before       = $search_total();
$warnings     = array();
set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_set_error_handler -- Prova isolata, il gestore viene tolto subito dopo.
	static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
		$warnings[] = $errstr;
		return true;
	}
);
$hit_search( array( 'q' => '', 'n' => 1 ), '192.0.2.47' );
$hit_search( array( 'q' => '   ', 'n' => 1 ), '192.0.2.48' );
$send_hit( $payload( $post_a, array( 'q' => 'solo per le ricerche', 'n' => 1, 'u' => '/wp5w-v12-q-post/' ) ), '192.0.2.49', $chrome );
$hit_search( array( 'q' => array( 'x' ), 'n' => array( '1' ) ), '192.0.2.50' );
restore_error_handler();
$check( 'ricerche: termine vuoto, q con un articolo e q array non scrivono righe', $before, $search_total() );
$check( 'ricerche: nessun warning con q array', '', implode( ' | ', $warnings ) );
$queries = array();
add_filter( 'query', $capture_query );
$hit_search( array( 'q' => '', 'n' => 1 ), '192.0.2.51' );
remove_filter( 'query', $capture_query );
$check( 'ricerche: con il termine vuoto la visita fa 2 query', 2, count( $queries ) );
$queries = array();
add_filter( 'query', $capture_query );
$hit_search( array( 'q' => 'una ricerca valida', 'n' => 3 ), '192.0.2.52' );
remove_filter( 'query', $capture_query );
$check( 'ricerche: una ricerca registrata fa 3 query', 3, count( $queries ) );
$check( 'ricerche: una INSERT ON DUPLICATE KEY UPDATE sulla tabella delle ricerche', 1, count( array_filter( $queries, static fn( $q ) => str_contains( $q, $search_table ) && 0 === stripos( ltrim( $q ), 'INSERT' ) && str_contains( $q, 'ON DUPLICATE KEY UPDATE' ) ) ) );

// Colonne: nessuna colonna del visitatore e nessun orario per le ricerche.
$check( 'ricerche: colonne di search_daily', 'day,term,searches,empty', implode( ',', $wpdb->get_col( "SHOW COLUMNS FROM {$search_table}" ) ) );
$check( 'notfound: colonne di notfound_daily', 'day,uri,referrer,hits,last_seen', implode( ',', $wpdb->get_col( "SHOW COLUMNS FROM {$notfound_table}" ) ) );

// Lettura delle ricerche.
$searches = $operations->searches( $range_30 );
$check( 'ricerche lettura: totale delle ricerche', $search_total(), $searches['searches'] ?? '' );
$check( 'ricerche lettura: senza risultati', (int) $wpdb->get_var( "SELECT COALESCE(SUM(empty), 0) FROM {$search_table}" ), $searches['empty'] ?? '' );
$check( 'ricerche lettura: la prima riga è la più cercata', 'wordpress plugin|3', ( $searches['rows'][0]['term'] ?? '' ) . '|' . ( $searches['rows'][0]['searches'] ?? '' ) );

// Passo 9: il blocco del tracker in una ricerca (query principale simulata).
$saved_query = $GLOBALS['wp_query'];
$saved_main  = $GLOBALS['wp_the_query'];
$print_block = static function ( array $args ): string {
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( $args ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Prova isolata, i valori originali si ripristinano alla fine.
	ob_start();
	( new Tracker() )->print_data();
	return (string) ob_get_clean();
};
$block_search = $print_block( array( 's' => 'Calendario' ) );
$check( 'blocco: tipo search', true, str_contains( $block_search, '"type":"search"' ) );
$check( 'blocco: termine normalizzato', true, str_contains( $block_search, '"q":"calendario"' ) );
$check( 'blocco: numero dei risultati', true, 1 === preg_match( '/"n":\d+/', $block_search ) );
$check( 'blocco: pagina 2 senza q', false, str_contains( $print_block( array( 's' => 'Calendario', 'paged' => 2 ) ), '"q"' ) );
$check( 'blocco: articolo singolo senza q', false, str_contains( $print_block( array( 'p' => $post_a ) ), '"q"' ) );
$GLOBALS['wp_query']     = $saved_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Ripristino.
$GLOBALS['wp_the_query'] = $saved_main; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Ripristino.

// Passo 10: una visita normale a un articolo fa ancora 2 query.
$queries = array();
add_filter( 'query', $capture_query );
$send_hit( $payload( $post_a, array( 'u' => '/wp5w-v12-normale/' ) ), '192.0.2.60', $chrome );
remove_filter( 'query', $capture_query );
$check( 'visita normale: 2 query', 2, count( $queries ) );
$check( 'visita normale: tutte su wp5w_hits', 2, count( array_filter( $queries, static fn( $q ) => str_contains( $q, $hits_table ) ) ) );

// Passo 11: visite nell'elenco dei contenuti e nella barra di amministrazione.
$posts_query = new PostsQuery();
$views_ids   = $posts_query->views_by_ids( 'post', $seed_posts, $range_30 );
foreach ( $seed_posts as $wp5w_id ) {
	$check(
		"visite 30 g del post {$wp5w_id}: views_by_ids = SUM(views) di object_daily",
		(int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(views), 0) FROM {$object_table} WHERE origin = 'wps' AND object_type = 'post' AND object_id = %d AND day BETWEEN %s AND %s", $wp5w_id, $range_30->from, $range_30->to ) ),
		$views_ids[ $wp5w_id ] ?? 0
	);
}
$check( 'visite 30 g: con nessun ID non si interroga', 0, count( $posts_query->views_by_ids( 'post', array(), $range_30 ) ) );

$post_views  = new PostViews( $posts_query );
$saved_query = $GLOBALS['wp_query'];
$GLOBALS['wp_query']        = new WP_Query( array( 'post__in' => $seed_posts, 'post_type' => 'post', 'posts_per_page' => -1, 'orderby' => 'ID' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Prova isolata, il valore originale si ripristina.
$admin_user( $admin_id );
$check( 'colonna: l\'amministratore la riceve', true, isset( $post_views->add_column( array() )['wp5w_views'] ) );
$queries = array();
add_filter( 'query', $capture_query );
ob_start();
foreach ( $seed_posts as $wp5w_id ) {
	$post_views->render_column( 'wp5w_views', $wp5w_id );
}
$cells = (string) ob_get_clean();
remove_filter( 'query', $capture_query );
$check( 'colonna: una sola query su object_daily per tutta la pagina', 1, count( array_filter( $queries, static fn( $q ) => str_contains( $q, $object_table ) ) ) );
$check( 'colonna: un link detail per ogni riga', count( $seed_posts ), substr_count( $cells, 'tab=posts&#038;detail=' ) + substr_count( $cells, 'tab=posts&detail=' ) );
$check( 'colonna: il link del primo articolo', true, str_contains( html_entity_decode( $cells ), 'detail=' . $post_a . '"' ) );
$check( 'colonna: il numero della prima cella', true, str_contains( $cells, '>' . number_format_i18n( $views_ids[ $post_a ] ?? 0 ) . '</a>' ) );
$GLOBALS['wp_query'] = $saved_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Ripristino.

$subscriber_id = (int) wp_insert_user( array( 'user_login' => 'wp5w_verify_subscriber2', 'user_pass' => wp_generate_password(), 'user_email' => 'wp5w-verify2@example.com', 'role' => 'subscriber' ) );
wp_set_current_user( $subscriber_id );
$check( 'colonna: un utente senza manage_options non la riceve', false, isset( $post_views->add_column( array() )['wp5w_views'] ) );
$admin_user( 0 );
wp_delete_user( $subscriber_id );

// Ordinamento: la guardia di PostViews non scatta in wp eval-file (is_admin() è falso e la query non è la principale),
// quindi si prova il costruttore delle clausole con un filtro posts_clauses di prova, in modo crescente e decrescente.
$order_dir   = 'DESC';
$order_probe = static function ( array $clauses ) use ( $posts_query, $range_30, &$order_dir, $wpdb ): array {
	$parts              = $posts_query->views_order_clauses( 'post', $range_30 );
	$clauses['join']   .= $parts['join'];
	$clauses['orderby'] = "{$parts['field']} {$order_dir}, {$wpdb->posts}.ID {$order_dir}";
	return $clauses;
};
add_filter( 'posts_clauses', $order_probe );
foreach ( array( 'DESC', 'ASC' ) as $order_dir ) {
	$sorted_ids = array_map( 'intval', ( new WP_Query( array( 'post__in' => $seed_posts, 'post_type' => 'post', 'posts_per_page' => -1, 'fields' => 'ids' ) ) )->posts );
	$expected   = $seed_posts;
	usort(
		$expected,
		static function ( int $a, int $b ) use ( $views_ids, $order_dir ): int {
			$by_views = ( $views_ids[ $a ] ?? 0 ) <=> ( $views_ids[ $b ] ?? 0 );
			$result   = 0 !== $by_views ? $by_views : $a <=> $b;
			return 'DESC' === $order_dir ? -$result : $result;
		}
	);
	$check( "ordinamento per visite ({$order_dir})", implode( ',', $expected ), implode( ',', $sorted_ids ) );
}
remove_filter( 'posts_clauses', $order_probe );
$guard_query = new WP_Query( array( 'post_type' => 'post', 'orderby' => 'wp5w_views', 'fields' => 'ids' ) );
$admin_user( $admin_id );
$base_clauses = array( 'join' => '', 'orderby' => 'wp_posts.post_date DESC' );
$check( 'ordinamento: con una query che non è la principale le clausole non cambiano', md5( (string) wp_json_encode( $base_clauses ) ), md5( (string) wp_json_encode( $post_views->order_by_views( $base_clauses, $guard_query ) ) ) );

// Barra di amministrazione: nodo wp5w-views su un articolo singolo simulato.
if ( ! class_exists( 'WP_Admin_Bar' ) ) {
	require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
}
$saved_query = $GLOBALS['wp_query'];
$saved_main  = $GLOBALS['wp_the_query'];
$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'p' => $post_a ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Prova isolata, i valori originali si ripristinano.
$bar = new WP_Admin_Bar();
$post_views->add_admin_bar_node( $bar );
$node = $bar->get_node( 'wp5w-views' );
$check( 'barra: nodo wp5w-views con il numero di visite', true, str_contains( (string) ( $node->title ?? '' ), number_format_i18n( $views_ids[ $post_a ] ?? 0 ) ) );
$check( 'barra: il link apre il modale', true, str_contains( (string) ( $node->href ?? '' ), 'detail=' . $post_a ) );
$admin_user( 0 );
$bar_anon = new WP_Admin_Bar();
$post_views->add_admin_bar_node( $bar_anon );
$check( 'barra: senza manage_options il nodo non c\'è', false, (bool) $bar_anon->get_node( 'wp5w-views' ) );
$GLOBALS['wp_query']     = $saved_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Ripristino.
$GLOBALS['wp_the_query'] = $saved_main; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Ripristino.

// Passo 12: modale aperto dall'URL. I dati localizzati da AdminMenu contengono `detail` solo per un articolo o una pagina leggibili.
$localized = static function () use ( $admin_id ): array {
	wp_set_current_user( $admin_id );
	wp_deregister_script( 'wp5w-admin' );
	( new AdminMenu() )->enqueue_assets( 'toplevel_page_wp5w' );
	$data = (string) wp_scripts()->get_data( 'wp5w-admin', 'data' );
	return 1 === preg_match( '/var wp5wAdmin = (\{.*\});/s', $data, $match ) ? (array) json_decode( $match[1], true ) : array( 'errore' => 'dati non trovati (npm run build?)' );
};
$_GET['detail'] = (string) $post_a;
$with_detail    = $localized();
$check( 'modale da URL: detail con id, titolo e data', $post_a . '|' . get_the_title( $post_a ) . '|' . get_the_date( 'Y-m-d', $post_a ), ( $with_detail['detail']['id'] ?? '' ) . '|' . ( $with_detail['detail']['title'] ?? '' ) . '|' . ( $with_detail['detail']['date'] ?? '' ) );
$_GET['detail'] = '99999999';
$check( 'modale da URL: ID inesistente senza detail', false, isset( $localized()['detail'] ) );
$other_type_id  = (int) wp_insert_post( array( 'post_type' => 'wp5w_tmp', 'post_title' => 'Tipo di prova', 'post_status' => 'publish' ) );
$_GET['detail'] = (string) $other_type_id;
$check( 'modale da URL: tipo diverso da post e page senza detail', false, isset( $localized()['detail'] ) );
wp_delete_post( $other_type_id, true );
unset( $_GET['detail'] );
$check( 'modale da URL: senza il parametro nessun detail', false, isset( $localized()['detail'] ) );
wp_deregister_script( 'wp5w-admin' );
$admin_user( 0 );

// ---------------------------------------------------------------------------
// 13. Iterazione 0.11.0: aggiornamenti dall'admin, link nell'admin, GET /admin/status e tracker compilato.
// ---------------------------------------------------------------------------
// Nessuna richiesta reale a GitHub e nessun download: pre_http_request simula le risposte e upgrader_pre_download
// (attivo per tutta la sezione) conta le chiamate e fallisce sempre, così nessun upgrader può sovrascrivere il checkout montato da wp-env.
$update_option = 'external_updates-wp-5w';
$update_saved  = get_option( $update_option, null );
$restore_state = static function () use ( $update_option, &$update_saved ): void {
	if ( null === $update_saved ) {
		delete_option( $update_option );
	} else {
		update_option( $update_option, $update_saved );
	}
};

$latest_tag     = 'v0.10.0';
$unmocked_calls = 0;
$download_calls = 0;
$github_mock    = static function ( $pre, $args, $url ) use ( &$latest_tag, &$unmocked_calls ) {
	$parts = wp_parse_url( (string) $url );
	if ( ! in_array( $parts['host'] ?? '', array( 'api.github.com', 'github.com', 'raw.githubusercontent.com' ), true ) ) {
		++$unmocked_calls;
		return new WP_Error( 'wp5w_verify_blocked', 'Richiesta non simulata: ' . $url );
	}
	$path     = (string) ( $parts['path'] ?? '' );
	$version  = ltrim( $latest_tag, 'v' );
	$release  = array(
		'tag_name'    => $latest_tag,
		'zipball_url' => 'https://example.invalid/zipball/' . $latest_tag,
		'created_at'  => '2026-10-15T10:00:00Z',
		'draft'       => false,
		'prerelease'  => false,
		'body'        => '',
		'assets'      => array(
			array(
				'name'                 => "wp-5w-{$version}.zip",
				'url'                  => "https://example.invalid/asset/wp-5w-{$version}.zip",
				'browser_download_url' => "https://example.invalid/wp-5w-{$version}.zip",
				'download_count'       => 1,
			),
		),
	);
	$ok       = array(
		'headers'  => array(),
		'cookies'  => array(),
		'filename' => null,
		'response' => array( 'code' => 200, 'message' => 'OK' ),
	);
	if ( str_ends_with( $path, '/releases/latest' ) ) {
		return $ok + array( 'body' => (string) wp_json_encode( $release ) );
	}
	if ( str_ends_with( $path, '/releases' ) ) {
		return $ok + array( 'body' => (string) wp_json_encode( array( $release ) ) );
	}
	// Ogni altro indirizzo di GitHub (per esempio il file principale del plugin) risponde 404: la versione viene dal tag.
	return array( 'body' => '', 'response' => array( 'code' => 404, 'message' => 'Not Found' ) ) + $ok;
};
$block_download = static function () use ( &$download_calls ) {
	++$download_calls;
	return new WP_Error( 'wp5w_verify_no_download', 'Download bloccato dal test.' );
};
add_filter( 'pre_http_request', $github_mock, 10, 3 );
add_filter( 'upgrader_pre_download', $block_download );

// Rotte registrate.
$routes = rest_get_server()->get_routes();
foreach ( array( '/wp5w/v1/admin/update', '/wp5w/v1/admin/update/check', '/wp5w/v1/admin/update/install' ) as $wp5w_route ) {
	$check( "aggiornamenti: rotta {$wp5w_route} registrata", '1', isset( $routes[ $wp5w_route ] ) ? '1' : '0' );
}

// Permessi: senza utente 401, abbonato 403.
$update_calls = array(
	'GET /admin/update'          => array( 'GET', '/wp5w/v1/admin/update' ),
	'POST /admin/update/check'   => array( 'POST', '/wp5w/v1/admin/update/check' ),
	'POST /admin/update/install' => array( 'POST', '/wp5w/v1/admin/update/install' ),
);
wp_set_current_user( 0 );
foreach ( $update_calls as $wp5w_label => $wp5w_call ) {
	$check( "aggiornamenti senza utente, {$wp5w_label}: 401", 401, $rest_call( $wp5w_call[0], $wp5w_call[1] )->get_status() );
}
$update_subscriber = (int) wp_insert_user( array( 'user_login' => 'wp5w_verify_subscriber3', 'user_pass' => wp_generate_password(), 'user_email' => 'wp5w-verify3@example.com', 'role' => 'subscriber' ) );
wp_set_current_user( $update_subscriber );
foreach ( $update_calls as $wp5w_label => $wp5w_call ) {
	$check( "aggiornamenti con un abbonato, {$wp5w_label}: 403", 403, $rest_call( $wp5w_call[0], $wp5w_call[1] )->get_status() );
}
wp_set_current_user( 0 );
wp_delete_user( $update_subscriber );

// Amministratore senza la sola capability update_plugins: stato e controllo sì, installazione no.
$without_update_cap = static function ( array $allcaps ): array {
	$allcaps['update_plugins'] = false;
	return $allcaps;
};
add_filter( 'user_has_cap', $without_update_cap );
wp_set_current_user( $admin_id );
$check( 'aggiornamenti senza update_plugins, GET: 200', 200, $rest_call( 'GET', '/wp5w/v1/admin/update' )->get_status() );
$check( 'aggiornamenti senza update_plugins, check: 200', 200, $rest_call( 'POST', '/wp5w/v1/admin/update/check' )->get_status() );
$forbidden = $rest_call( 'POST', '/wp5w/v1/admin/update/install' );
$check( 'aggiornamenti senza update_plugins, install: 403', 403, $forbidden->get_status() );
$check( 'aggiornamenti senza update_plugins, install: codice', 'wp5w_forbidden_update', $forbidden->get_data()['code'] ?? '' );
remove_filter( 'user_has_cap', $without_update_cap );

// Con l'amministratore: stato, controllo e rifiuto dell'installazione senza aggiornamenti.
$update_get = $rest_call( 'GET', '/wp5w/v1/admin/update' );
$update_now = (array) $update_get->get_data();
$check( 'aggiornamenti GET: 200', 200, $update_get->get_status() );
$check( 'aggiornamenti GET: versione installata', WP5W_VERSION, $update_now['current'] ?? '' );
$check( 'aggiornamenti GET: releases_url di GitHub', true, str_starts_with( (string) ( $update_now['releases_url'] ?? '' ), 'https://github.com/mavidasnc/wp-5w' ) );
$check( 'aggiornamenti GET: chiavi latest, update_available e checked_at', '', implode( ',', array_diff( array( 'latest', 'update_available', 'checked_at' ), array_keys( $update_now ) ) ) );
$update_check = $rest_call( 'POST', '/wp5w/v1/admin/update/check' );
$check_data   = (array) $update_check->get_data();
$check( 'aggiornamenti check: 200', 200, $update_check->get_status() );
$check( 'aggiornamenti check: nessun aggiornamento con la release v0.10.0', false, $check_data['update_available'] ?? 'assente' );
$check( 'aggiornamenti check: checked_at recente', true, (int) ( $check_data['checked_at'] ?? 0 ) >= time() - 60 );
$update_install = $rest_call( 'POST', '/wp5w/v1/admin/update/install' );
$check( 'aggiornamenti install senza aggiornamenti: 409', 409, $update_install->get_status() );
$check( 'aggiornamenti install senza aggiornamenti: codice', 'wp5w_no_update', $update_install->get_data()['code'] ?? '' );
$check( 'aggiornamenti install senza aggiornamenti: l\'upgrader non è partito', 0, $download_calls );

// Release simulata v99.0.0: il controllo la trova; install NON si chiama. Lo stato si ripristina subito.
$latest_tag = 'v99.0.0';
$fake_check = (array) $rest_call( 'POST', '/wp5w/v1/admin/update/check' )->get_data();
$check( 'aggiornamenti con v99.0.0: update_available', true, $fake_check['update_available'] ?? 'assente' );
$check( 'aggiornamenti con v99.0.0: latest', '99.0.0', $fake_check['latest'] ?? '' );
$restore_state();
$latest_tag = 'v0.10.0';

$check( 'aggiornamenti: nessuna richiesta HTTP non simulata', 0, $unmocked_calls );
$check( 'aggiornamenti: nessun download tentato', 0, $download_calls );

// Link nella riga del plugin: «Dashboard» e poi «Verifica aggiornamenti» verso la scheda Stato.
$plugin_links = ( new AdminMenu() )->add_dashboard_link( array() );
$check( 'riga del plugin: due link', 2, count( $plugin_links ) );
$check( 'riga del plugin: il primo è Dashboard', true, str_contains( (string) ( $plugin_links[0] ?? '' ), 'Dashboard' ) );
$check( 'riga del plugin: il secondo è Verifica aggiornamenti verso la scheda Aggiornamenti', true, str_contains( html_entity_decode( (string) ( $plugin_links[1] ?? '' ) ), 'page=wp5w&tab=updates' ) && str_contains( (string) ( $plugin_links[1] ?? '' ), 'Verifica aggiornamenti' ) );

// Il link «Check for updates» di plugin-update-checker si nasconde. In wp eval-file admin_init non parte e la libreria non aggancia
// plugin_row_meta, quindi questo controllo sul filtro è quello vincolante (la pagina Plugin si guarda in wp-env).
$check( 'link Check for updates: il filtro restituisce una stringa vuota', '', apply_filters( 'puc_manual_check_link-wp-5w', 'x' ) );

// GET /admin/status senza version e updates, con le altre chiavi di prima.
$status_data = (array) $rest_call( 'GET', '/wp5w/v1/admin/status' )->get_data();
$check( 'status: 200', 200, $rest_call( 'GET', '/wp5w/v1/admin/status' )->get_status() );
$check( 'status: senza le chiavi version e updates', '', implode( ',', array_intersect( array( 'version', 'updates' ), array_keys( $status_data ) ) ) );
$check( 'status: le altre chiavi ci sono', '', implode( ',', array_diff( array( 'sources', 'tables', 'last_run', 'next_run', 'geo' ), array_keys( $status_data ) ) ) );

// Box «Pubblico»: la risposta di dimensions ha la stessa forma di prima.
$dim_data = (array) $rest_call( 'GET', '/wp5w/v1/admin/audience/dimensions', array( 'days' => 30 ) )->get_data();
$check( 'dimensions: chiavi range, first_day e dims', '', implode( ',', array_diff( array( 'range', 'first_day', 'dims' ), array_keys( $dim_data ) ) ) );
$check( 'dimensions: country, device, browser e platform', '', implode( ',', array_diff( array( 'country', 'device', 'browser', 'platform' ), array_keys( (array) ( $dim_data['dims'] ?? array() ) ) ) ) );

// Tracker compilato: segnala il webdriver con il parametro w=1 e resta sotto il limite di peso.
$tracker_js = (string) file_get_contents( WP5W_PLUGIN_DIR . 'build/tracker/tracker.js' );
$check( 'tracker compilato: contiene webdriver', true, str_contains( $tracker_js, 'webdriver' ) );
$check( 'tracker compilato: imposta w=1', 1, preg_match( '/["\']w["\']\s*,\s*["\']1["\']/', $tracker_js ) );
$check( 'tracker compilato: sotto 1.536 byte', true, strlen( $tracker_js ) < 1536 );

// Chiusura della sezione: filtri e utente di prova tolti, stato di plugin-update-checker ripristinato.
remove_filter( 'pre_http_request', $github_mock, 10 );
remove_filter( 'upgrader_pre_download', $block_download );
wp_set_current_user( 0 );

// ---------------------------------------------------------------------------
// 14. Iterazione 0.12.0: collegamento OAuth a Search Console, import della differenza e import separato di Rank Math.
// ---------------------------------------------------------------------------
// Google è simulato con pre_http_request (token, proprietà, Search Analytics e revoca): nessuna richiesta di rete.
$gsc_day         = static fn( int $ago ): string => wp_date( 'Y-m-d', time() - $ago * DAY_IN_SECONDS );
$gsc_home        = home_url( '/' );
$gsc_last_final  = $gsc_day( 3 );
$gsc_foreign     = false;
$gsc_secret      = 'SEGRETO-DEL-CLIENT-9f2';
$gsc_client_id   = '1234567890-abc.apps.googleusercontent.com';
$gsc_state       = array(
	'scope_ok'      => true,
	'invalid_grant' => false,
	'fail_query'    => false,
);
$gsc_calls       = array(
	'query'  => array(),
	'token'  => array(),
	'revoke' => array(),
	'bearer' => '',
	'other'  => 0,
);

// Giorni di prova: 7, 6, 5 (vuoto), 4 e 3 giorni fa; l'ultimo è anche l'ultimo giorno definitivo. Pagina => query => click, impressioni, posizione.
$gsc_data = array(
	$gsc_day( 7 ) => array(
		array( $gsc_home . 'blog/', 'uno', 3, 30, 4.0 ),
		array( $gsc_home . 'guida/#indice', 'due', 1, 10, 2.0 ),
		array( $gsc_home . 'guida/', 'due', 2, 20, 6.0 ),
	),
	$gsc_day( 6 ) => array( array( $gsc_home . 'blog/', 'uno', 1, 5, 3.0 ) ),
	$gsc_day( 5 ) => array(),
	$gsc_day( 4 ) => array( array( $gsc_home, 'tre', 2, 8, 1.0 ) ),
	$gsc_day( 3 ) => array( array( $gsc_home . 'blog/', 'uno', 1, 2, 5.0 ) ),
);

$google_mock = static function ( $pre, $args, $url ) use ( &$gsc_calls, &$gsc_state, &$gsc_data, &$gsc_last_final, &$gsc_foreign, $gsc_home ) {
	$parts = wp_parse_url( (string) $url );
	$host  = (string) ( $parts['host'] ?? '' );
	$path  = (string) ( $parts['path'] ?? '' );
	$reply = static fn( $data, int $code = 200 ): array => array(
		'headers'  => array(),
		'body'     => wp_json_encode( $data ),
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);

	if ( 'oauth2.googleapis.com' === $host && '/token' === $path ) {
		$body = (array) $args['body'];
		if ( 'authorization_code' === $body['grant_type'] ) {
			$gsc_calls['token'][] = $body;
			return $reply(
				array(
					'access_token'  => 'AT-1',
					'expires_in'    => 3600,
					'refresh_token' => 'RT-segreto-1',
					'scope'         => $gsc_state['scope_ok'] ? 'https://www.googleapis.com/auth/webmasters.readonly' : 'openid',
				)
			);
		}
		if ( $gsc_state['invalid_grant'] ) {
			return $reply( array( 'error' => 'invalid_grant' ), 400 );
		}
		return $reply(
			array(
				'access_token' => 'AT-2',
				'expires_in'   => 3600,
			)
		);
	}

	if ( 'oauth2.googleapis.com' === $host && '/revoke' === $path ) {
		$gsc_calls['revoke'][] = (array) $args['body'];
		return $reply( array() );
	}

	if ( 'searchconsole.googleapis.com' === $host ) {
		$gsc_calls['bearer'] = (string) ( $args['headers']['Authorization'] ?? '' );

		if ( '/webmasters/v3/sites' === $path ) {
			return $reply(
				array(
					'siteEntry' => array(
						array(
							'siteUrl'         => $gsc_home,
							'permissionLevel' => 'siteOwner',
						),
						array(
							'siteUrl'         => 'sc-domain:altro-dominio.example',
							'permissionLevel' => 'siteOwner',
						),
						array(
							'siteUrl'         => 'https://non-verificato.example/',
							'permissionLevel' => 'siteUnverifiedUser',
						),
					),
				)
			);
		}

		if ( str_ends_with( $path, '/searchAnalytics/query' ) ) {
			$req = json_decode( (string) $args['body'], true );
			$gsc_calls['query'][] = array(
				'dims'   => $req['dimensions'],
				'start'  => $req['startDate'],
				'end'    => $req['endDate'],
				'filter' => $req['dimensionFilterGroups'][0]['filters'][0]['expression'] ?? '',
			);
			if ( $gsc_state['fail_query'] ) {
				return $reply( array( 'error' => array( 'message' => 'Quota esaurita' ) ), 429 );
			}

			$rows = array();
			foreach ( $gsc_data as $day => $items ) {
				if ( $day < $req['startDate'] || $day > $req['endDate'] || $day > $gsc_last_final || array() === $items ) {
					continue;
				}
				// La richiesta con la sola data serve a trovare l'ultimo giorno definitivo.
				if ( array( 'date' ) === $req['dimensions'] ) {
					$rows[] = array(
						'keys'        => array( $day ),
						'clicks'      => 1,
						'impressions' => 1,
						'position'    => 1,
					);
					continue;
				}
				foreach ( $items as $item ) {
					$rows[] = array(
						'keys'        => array( $day, $item[0], $item[1] ),
						'clicks'      => $item[2],
						'impressions' => $item[3],
						'position'    => $item[4],
					);
				}
				if ( $gsc_foreign ) {
					$rows[] = array(
						'keys'        => array( $day, 'https://www.altro-sito.example/', 'altro sito' ),
						'clicks'      => 9,
						'impressions' => 9,
						'position'    => 1,
					);
				}
			}
			return $reply( array( 'rows' => $rows ) );
		}
	}

	++$gsc_calls['other'];
	return new WP_Error( 'wp5w_verify_blocked', 'Richiesta non simulata: ' . $url );
};
add_filter( 'pre_http_request', $google_mock, 10, 3 );

// Giorni di prova puliti (tutte le origini) e una riga di Rank Math da sostituire: i giorni 4 e 6 giorni fa.
$gsc_table = Schema::table( 'gsc_daily' );
$gsc_clean = static function () use ( $wpdb, $gsc_table, $gsc_day ): void {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$gsc_table} WHERE day BETWEEN %s AND %s", $gsc_day( 9 ), $gsc_day( 1 ) ) );
};
$gsc_clean();
delete_option( GscClient::OPTION );
GscApiImport::reset();
delete_transient( 'wp5w_gsc_access' );
delete_transient( 'wp5w_gsc_sites' );
$wpdb->query(
	$wpdb->prepare(
		"INSERT INTO {$gsc_table} (day, page_hash, query_hash, object_id, page, query, clicks, impressions, pos_x_impr, origin) VALUES (%s, MD5('/'), MD5('vecchia'), 0, '/', 'vecchia', 1, 1, 1, 'rankmath')",
		$gsc_day( 4 )
	)
);

$admin_user( $admin_id );

// Prima del collegamento: niente configurato, niente collegato, nessuna chiamata a Google.
$status = $rest_call( 'GET', '/wp5w/v1/admin/gsc' )->get_data();
$check( 'GSC: non configurata all\'inizio', 0, (int) $status['configured'] );
$check( 'GSC: non collegata all\'inizio', 0, (int) $status['connected'] );
$check( 'GSC: giorni da importare senza collegamento', 'null', null === $status['pending_days'] ? 'null' : 'altro' );
$check( 'GSC: redirect URI mostrato = admin-post.php', admin_url( 'admin-post.php?action=wp5w_gsc_oauth' ), $status['redirect_uri'] );
$check( 'GSC: import senza collegamento: 500', 500, $rest_call( 'POST', '/wp5w/v1/admin/gsc/import' )->get_status() );
$check( 'GSC: nessuna richiesta a Google senza collegamento', 0, count( $gsc_calls['query'] ) + count( $gsc_calls['token'] ) );

// Credenziali: formato dell'ID, secret obbligatorio la prima volta, secret cifrato e mai restituito.
$check( 'POST credenziali con ID non valido: 400', 400, $rest_call( 'POST', '/wp5w/v1/admin/gsc/credentials', array( 'client_id' => 'abc', 'client_secret' => $gsc_secret ) )->get_status() );
$check( 'POST credenziali senza secret: 400', 400, $rest_call( 'POST', '/wp5w/v1/admin/gsc/credentials', array( 'client_id' => $gsc_client_id ) )->get_status() );
$saved = $rest_call( 'POST', '/wp5w/v1/admin/gsc/credentials', array( 'client_id' => $gsc_client_id, 'client_secret' => $gsc_secret ) );
$check( 'POST credenziali: 200', 200, $saved->get_status() );
$check( 'GSC: configurata dopo il salvataggio', 1, (int) $saved->get_data()['configured'] );
$check( 'GSC: la risposta non contiene il secret', 0, (int) str_contains( wp_json_encode( $saved->get_data() ), $gsc_secret ) );
$stored = (string) wp_json_encode( get_option( GscClient::OPTION ) );
$check( 'GSC: secret cifrato nell\'opzione', 0, (int) str_contains( $stored, $gsc_secret ) );
// Da WordPress 6.6 l'autoload spento si salva come «off» (prima «no»).
$check( 'GSC: autoload spento per l\'opzione dei segreti', 1, (int) in_array( (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", GscClient::OPTION ) ), array( 'off', 'no' ), true ) );

// Link di consenso.
$auth_url = GscClient::auth_url();
parse_str( (string) wp_parse_url( $auth_url, PHP_URL_QUERY ), $auth_query );
$check( 'consenso: redirect_uri', admin_url( 'admin-post.php?action=wp5w_gsc_oauth' ), $auth_query['redirect_uri'] ?? '' );
$check( 'consenso: access_type offline', 'offline', $auth_query['access_type'] ?? '' );
$check( 'consenso: prompt consent', 'consent', $auth_query['prompt'] ?? '' );
$check( 'consenso: scope in sola lettura', 'https://www.googleapis.com/auth/webmasters.readonly', $auth_query['scope'] ?? '' );
$check( 'consenso: client_id', $gsc_client_id, $auth_query['client_id'] ?? '' );
$check( 'consenso: state di 32 caratteri', 32, strlen( (string) ( $auth_query['state'] ?? '' ) ) );

// Ritorno da Google: state sbagliato, consenso annullato, permesso tolto, poi collegamento riuscito.
$check( 'ritorno con state sbagliato: errore', 1, (int) ( null !== OAuthHandler::finish( array( 'code' => 'CODE-1', 'state' => 'sbagliato' ) ) ) );
$check( 'ritorno con state sbagliato: non collegata', 0, (int) GscClient::is_connected() );
$check( 'ritorno con state sbagliato: nessuna richiesta di token', 0, count( $gsc_calls['token'] ) );
$check( 'ritorno con consenso annullato: errore', 1, (int) ( null !== OAuthHandler::finish( array( 'error' => 'access_denied' ) ) ) );

$auth_url = GscClient::auth_url();
parse_str( (string) wp_parse_url( $auth_url, PHP_URL_QUERY ), $auth_query );
$gsc_state['scope_ok'] = false;
$check( 'ritorno senza il permesso di Search Console: errore', 1, (int) ( null !== OAuthHandler::finish( array( 'code' => 'CODE-1', 'state' => $auth_query['state'] ) ) ) );
$check( 'ritorno senza il permesso: non collegata', 0, (int) GscClient::is_connected() );
$gsc_state['scope_ok'] = true;

$auth_url = GscClient::auth_url();
parse_str( (string) wp_parse_url( $auth_url, PHP_URL_QUERY ), $auth_query );
$check( 'ritorno corretto: nessun errore', 1, (int) ( null === OAuthHandler::finish( array( 'code' => 'CODE-1', 'state' => $auth_query['state'] ) ) ) );
$check( 'ritorno corretto: collegata', 1, (int) GscClient::is_connected() );
$check( 'ritorno corretto: richiesta di token con il redirect URI', admin_url( 'admin-post.php?action=wp5w_gsc_oauth' ), $gsc_calls['token'][ count( $gsc_calls['token'] ) - 1 ]['redirect_uri'] ?? '' );
$check( 'ritorno corretto: richiesta di token con il secret in chiaro (solo verso Google)', $gsc_secret, $gsc_calls['token'][ count( $gsc_calls['token'] ) - 1 ]['client_secret'] ?? '' );
$check( 'ritorno corretto: proprietà scelta da sola (prefisso URL della home)', $gsc_home, GscClient::config()['site'] );
$check( 'ritorno corretto: richiesta alle proprietà con il token', 'Bearer AT-1', $gsc_calls['bearer'] );
$check( 'ritorno corretto: refresh token cifrato', 0, (int) str_contains( (string) wp_json_encode( get_option( GscClient::OPTION ) ), 'RT-segreto-1' ) );
$check( 'lo state si usa una volta sola', 1, (int) ( null !== OAuthHandler::finish( array( 'code' => 'CODE-1', 'state' => $auth_query['state'] ) ) ) );

$status = $rest_call( 'GET', '/wp5w/v1/admin/gsc' )->get_data();
$check( 'GSC: proprietà elencate senza quelle non verificate', 2, count( $status['sites'] ) );
$check( 'GSC: la risposta non contiene il refresh token né il secret', 0, (int) ( str_contains( wp_json_encode( $status ), 'RT-segreto-1' ) || str_contains( wp_json_encode( $status ), $gsc_secret ) ) );
$check( 'GSC: giorni da importare prima dell\'import (16 mesi)', 1, (int) ( $status['pending_days'] > 400 ) );
$check( 'POST proprietà non accessibile: 400', 400, $rest_call( 'POST', '/wp5w/v1/admin/gsc/site', array( 'site' => 'https://non-verificato.example/' ) )->get_status() );

// Lock condiviso con l'ETL: con l'ETL in corso l'import si rifiuta e non chiama Google.
set_transient( Importer::LOCK, 1, 60 );
$queries_before = count( $gsc_calls['query'] );
$busy           = $rest_call( 'POST', '/wp5w/v1/admin/gsc/import' );
$check( 'import con l\'ETL in corso: 409', 409, $busy->get_status() );
$check( 'import con l\'ETL in corso: nessuna chiamata a Google', $queries_before, count( $gsc_calls['query'] ) );
delete_transient( Importer::LOCK );

// Primo import: tutti i lotti da 30 giorni fino a zero giorni mancanti (nel mock Google ha righe solo per una settimana).
// Richieste con pagina e query (la closure legge $gsc_calls per riferimento: una arrow function ne catturerebbe una copia).
$page_calls = static function () use ( &$gsc_calls ): array {
	return array_values( array_filter( $gsc_calls['query'], static fn( array $call ): bool => 3 === count( $call['dims'] ) ) );
};
$first      = ( new GscApiImport() )->run_all( 60 );
$check( 'import: esito ok', 1, (int) $first['ok'] );
$check( 'import: nessun giorno rimasto', 0, $first['remaining'] );
$check( 'import: quattro giorni con righe', 4, $first['days'] );
$check( 'import: lotti di al massimo 30 giorni', 1, (int) ( count( array_filter( $page_calls(), static fn( array $call ): bool => ( new DateRange( $call['start'], $call['end'] ) )->days() > 30 ) ) === 0 ) );
$check( 'import: righe dell\'API nei giorni di prova', 5, $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$gsc_table} WHERE origin = 'api' AND day BETWEEN %s AND %s", $gsc_day( 9 ), $gsc_day( 1 ) ) ) );
$check( 'import: la riga di Rank Math del giorno importato è sostituita', 0, $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$gsc_table} WHERE origin = 'rankmath' AND day = %s", $gsc_day( 4 ) ) ) );
$check( 'import: pagina con #frammento unita alla stessa pagina (click sommati)', 3, $wpdb->get_var( $wpdb->prepare( "SELECT clicks FROM {$gsc_table} WHERE day = %s AND page = '/guida/' AND query = 'due'", $gsc_day( 7 ) ) ) );
$check( 'import: pagina con #frammento (impressioni sommate)', 30, $wpdb->get_var( $wpdb->prepare( "SELECT impressions FROM {$gsc_table} WHERE day = %s AND page = '/guida/' AND query = 'due'", $gsc_day( 7 ) ) ) );
$check( 'import: pagina relativa senza host', 1, $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$gsc_table} WHERE day = %s AND page = '/blog/' AND query = 'uno'", $gsc_day( 6 ) ) ) );
$check( 'import: il giorno senza righe non ha righe', 0, $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$gsc_table} WHERE day = %s", $gsc_day( 5 ) ) ) );
$check( 'import: il giorno senza righe è ricordato come vuoto', 1, (int) in_array( $gsc_day( 5 ), (array) get_option( GscApiImport::EMPTY_OPTION ), true ) );
$check( 'import: i giorni importati non sono tra i vuoti', 0, (int) in_array( $gsc_day( 4 ), (array) get_option( GscApiImport::EMPTY_OPTION ), true ) );
$check( 'import: giorni da importare dopo l\'import', 0, (int) GscApiImport::pending_days() );
$check( 'import: ultimo giorno definitivo salvato', $gsc_last_final, (string) ( GscApiImport::state()['last_final'] ?? '' ) );
$check( 'import: nessuna richiesta fuori dai mock di Google', 0, $gsc_calls['other'] );

// Secondo import: nessun giorno mancante, quindi nessuna richiesta di righe (solo la richiesta con la data).
$pages_before = count( $page_calls() );
$again        = $rest_call( 'POST', '/wp5w/v1/admin/gsc/import' );
$check( 'secondo import: 200', 200, $again->get_status() );
$check( 'secondo import: zero giorni e zero righe', 0, $again->get_data()['days'] + $again->get_data()['rows'] );
$check( 'secondo import: nessuna richiesta di pagine e query', $pages_before, count( $page_calls() ) );
$check( 'secondo import: righe dell\'API invariate', 5, $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$gsc_table} WHERE origin = 'api' AND day BETWEEN %s AND %s", $gsc_day( 9 ), $gsc_day( 1 ) ) ) );

// Un giorno nuovo (Google lo rende definitivo): si scarica solo quello, dai suoi estremi.
$gsc_last_final           = $gsc_day( 2 );
$gsc_data[ $gsc_day( 2 ) ] = array( array( $gsc_home . 'blog/', 'nuovo', 4, 40, 2.0 ) );
$pages_before             = count( $page_calls() );
$next                     = ( new GscApiImport() )->run_batch();
$check( 'giorno nuovo: un giorno importato', 1, $next['days'] );
$check( 'giorno nuovo: una sola richiesta di pagine', $pages_before + 1, count( $page_calls() ) );
$last_call = $page_calls()[ count( $page_calls() ) - 1 ];
$check( 'giorno nuovo: la richiesta parte e finisce in quel giorno', $gsc_day( 2 ) . '|' . $gsc_day( 2 ), $last_call['start'] . '|' . $last_call['end'] );
$check( 'giorno nuovo: righe dell\'API', 1, $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$gsc_table} WHERE origin = 'api' AND day = %s", $gsc_day( 2 ) ) ) );

// Errore di Google (429): nessun giorno ricordato come vuoto e il giorno resta da importare.
$gsc_last_final           = $gsc_day( 1 );
$gsc_data[ $gsc_day( 1 ) ] = array( array( $gsc_home . 'blog/', 'ieri', 1, 1, 1.0 ) );
$empty_before             = (array) get_option( GscApiImport::EMPTY_OPTION );
$gsc_state['fail_query']  = true;
$failed                   = $rest_call( 'POST', '/wp5w/v1/admin/gsc/import' );
$check( 'errore di Google: 500', 500, $failed->get_status() );
$check( 'errore di Google: messaggio di Google', 1, (int) str_contains( (string) ( $failed->get_data()['error'] ?? '' ), 'Quota esaurita' ) );
$check( 'errore di Google: stato salvato con ok falso', 0, (int) GscApiImport::state()['ok'] );
$check( 'errore di Google: giorni vuoti invariati', count( $empty_before ), count( (array) get_option( GscApiImport::EMPTY_OPTION ) ) );
$check( 'errore di Google: il lock è rilasciato', 0, (int) (bool) get_transient( Importer::LOCK ) );
$gsc_state['fail_query']  = false;

// L'ETL notturno scarica da solo la differenza (e lascia a Rank Math solo i giorni che l'API non ha).
( new Scheduler( Plugin::importer() ) )->run_daily();
$check( 'notte: il giorno rimasto indietro è importato dal cron', 1, $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$gsc_table} WHERE origin = 'api' AND day = %s", $gsc_day( 1 ) ) ) );
$check( 'notte: giorni da importare', 0, (int) GscApiImport::pending_days() );

// Proprietà di dominio: le pagine di altri host non entrano (altrimenti «/» di un altro sito si fonderebbe con la nostra).
$gsc_domain_day                = $gsc_day( 0 );
$gsc_last_final                = $gsc_domain_day;
$gsc_data[ $gsc_domain_day ]   = array( array( $gsc_home . 'blog/', 'nostra', 1, 1, 1.0 ) );
$gsc_foreign                   = true;
$check( 'POST proprietà di dominio: 200', 200, $rest_call( 'POST', '/wp5w/v1/admin/gsc/site', array( 'site' => 'sc-domain:altro-dominio.example' ) )->get_status() );
$check( 'cambio di proprietà: stato e giorni vuoti azzerati', 0, (int) ( false !== get_option( GscApiImport::EMPTY_OPTION, false ) ) );
// Il giorno 0 non è tra i giorni importati: la finestra, con la proprietà nuova, riparte da zero e ricontrolla tutti i giorni.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$gsc_table} WHERE day >= %s", $gsc_day( 2 ) ) );
$domain = ( new GscApiImport() )->run_all( 60 );
$check( 'proprietà di dominio: esito ok', 1, (int) $domain['ok'] );
$domain_host_ok = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$gsc_table} WHERE day = %s AND query = 'nostra'", $gsc_domain_day ) );
$check( 'proprietà di dominio: la nostra pagina c\'è', 1, $domain_host_ok );
$check( 'proprietà di dominio: nessuna riga di un altro host', 0, $wpdb->get_var( "SELECT COUNT(*) FROM {$gsc_table} WHERE query = 'altro sito'" ) );
$domain_calls = array_filter( $page_calls(), static fn( array $call ): bool => '' !== $call['filter'] );
$check( 'proprietà di dominio: filtro sull\'host nella richiesta', 1, (int) ( count( $domain_calls ) > 0 ) );
$gsc_foreign = false;

// Collegamento revocato da Google: invalid_grant segna il collegamento da rifare e l'ETL notturno non insiste.
GscClient::select_site( $gsc_home );
delete_transient( 'wp5w_gsc_access' );
$gsc_state['invalid_grant'] = true;
$revoked                    = ( new GscApiImport() )->run_batch();
$check( 'revoca: l\'import fallisce', 0, (int) $revoked['ok'] );
$check( 'revoca: collegamento da rifare', 1, (int) GscClient::needs_reauth() );
$check( 'revoca: non risulta collegata', 0, (int) GscClient::is_connected() );
$check( 'revoca: lo stato REST lo segnala', 1, (int) $rest_call( 'GET', '/wp5w/v1/admin/gsc' )->get_data()['reauth'] );
$queries_before = count( $gsc_calls['query'] );
( new Scheduler( Plugin::importer() ) )->run_daily();
$check( 'revoca: l\'ETL notturno non chiama Google', $queries_before, count( $gsc_calls['query'] ) );
$gsc_state['invalid_grant'] = false;

$auth_url = GscClient::auth_url();
parse_str( (string) wp_parse_url( $auth_url, PHP_URL_QUERY ), $auth_query );
$check( 'ricollegamento: nessun errore', 1, (int) ( null === OAuthHandler::finish( array( 'code' => 'CODE-2', 'state' => $auth_query['state'] ) ) ) );
$check( 'ricollegamento: collegata e senza revoca pendente', '1|0', (int) GscClient::is_connected() . '|' . (int) GscClient::needs_reauth() );

// Import di Rank Math dal pulsante: stato proprio, l'ETL generale (e il Confronto) non cambiano.
$etl_before = get_option( Importer::STATE_OPTION );
$rm_status  = $rest_call( 'GET', '/wp5w/v1/admin/rankmath' );
$check( 'Rank Math: sorgente disponibile', 1, (int) $rm_status->get_data()['available'] );
$rm_import = $rest_call( 'POST', '/wp5w/v1/admin/rankmath/import' );
$check( 'Rank Math: import 200', 200, $rm_import->get_status() );
$check( 'Rank Math: esito della sorgente ok', 'ok', $rm_import->get_data()['sources']['rank_math']['status'] ?? '' );
$check( 'Rank Math: stato nella sua opzione', 'rankmath', (string) ( get_option( GscController::RANKMATH_STATE )['mode'] ?? '' ) );
$check( 'Rank Math: stato dell\'ETL generale invariato', 1, (int) ( $etl_before === get_option( Importer::STATE_OPTION ) ) );
$check( 'Rank Math: GET con l\'ultimo import', 'rankmath', (string) ( $rest_call( 'GET', '/wp5w/v1/admin/rankmath' )->get_data()['last']['mode'] ?? '' ) );
$check( 'Rank Math: i giorni dell\'API non sono toccati', 2, $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$gsc_table} WHERE origin = 'api' AND day = %s", $gsc_day( 7 ) ) ) );
$check( 'Rank Math: nessun giorno dell\'API ha righe di Rank Math', 0, $wpdb->get_var( "SELECT COUNT(*) FROM {$gsc_table} r WHERE r.origin = 'rankmath' AND r.day IN ( SELECT DISTINCT day FROM {$gsc_table} WHERE origin = 'api' )" ) );

// Permessi: senza un utente con manage_options tutte le rotte rifiutano.
wp_set_current_user( 0 );
foreach ( array( array( 'GET', '/admin/gsc' ), array( 'POST', '/admin/gsc/import' ), array( 'POST', '/admin/gsc/credentials' ), array( 'POST', '/admin/gsc/disconnect' ), array( 'GET', '/admin/rankmath' ), array( 'POST', '/admin/rankmath/import' ) ) as $gsc_route ) {
	$check( "permessi {$gsc_route[0]} {$gsc_route[1]}: " . rest_authorization_required_code(), rest_authorization_required_code(), $rest_call( $gsc_route[0], '/wp5w/v1' . $gsc_route[1], array( 'client_id' => $gsc_client_id ) )->get_status() );
}
$admin_user( $admin_id );

// Scollegamento: revoca il token salvato, toglie token e proprietà e tiene client ID e secret.
$disconnect = $rest_call( 'POST', '/wp5w/v1/admin/gsc/disconnect' );
$check( 'scollegamento: 200', 200, $disconnect->get_status() );
$check( 'scollegamento: token revocato presso Google', 'RT-segreto-1', $gsc_calls['revoke'][ count( $gsc_calls['revoke'] ) - 1 ]['token'] ?? '' );
$check( 'scollegamento: non collegata', 0, (int) $disconnect->get_data()['connected'] );
$check( 'scollegamento: client ID e secret restano', 1, (int) ( GscClient::is_configured() && $gsc_client_id === GscClient::config()['client_id'] ) );
$check( 'scollegamento: proprietà e refresh token tolti', '|', GscClient::config()['site'] . '|' . GscClient::config()['refresh_token'] );
$check( 'scollegamento: esito dell\'import azzerato', 'null', null === GscApiImport::state() ? 'null' : 'altro' );
$check( 'scollegamento: nessuna richiesta fuori dai mock di Google', 0, $gsc_calls['other'] );

// Chiusura della sezione: simulazione tolta, giorni di prova e opzioni ripulite.
remove_filter( 'pre_http_request', $google_mock, 10 );
$gsc_clean();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$gsc_table} WHERE day >= %s", $gsc_day( 9 ) ) );
delete_option( GscClient::OPTION );
delete_option( GscController::RANKMATH_STATE );
GscApiImport::reset();
foreach ( array( 'wp5w_gsc_access', 'wp5w_gsc_sites', 'wp5w_gsc_flash_' . $admin_id, 'wp5w_gsc_state_' . $admin_id ) as $gsc_transient ) {
	delete_transient( $gsc_transient );
}
wp_set_current_user( 0 );

// ---------------------------------------------------------------------------
// 15. Iterazione 0.13.0: Independent Analytics come terza origine (`ia`) e Confronto a tre.
// ---------------------------------------------------------------------------
// Le tabelle di IA si creano solo se mancano (in wp-env le migrazioni di IA partono dalla sua pagina in wp-admin): con le tabelle
// vere già presenti la sezione si salta, per non toccare dati di IA.
$ia_prefix = $p . 'independent_analytics_';
$ia_ddl    = array(
	'views'          => 'id bigint NOT NULL AUTO_INCREMENT, resource_id bigint NOT NULL, viewed_at datetime NOT NULL, page bigint NOT NULL DEFAULT 1, session_id bigint DEFAULT NULL, PRIMARY KEY (id)',
	'sessions'       => 'session_id bigint NOT NULL AUTO_INCREMENT, visitor_id bigint NOT NULL, created_at datetime NOT NULL, initial_view_id bigint DEFAULT NULL, referrer_id bigint DEFAULT NULL, PRIMARY KEY (session_id)',
	'resources'      => 'id bigint NOT NULL AUTO_INCREMENT, resource varchar(64) NOT NULL, singular_id bigint DEFAULT NULL, term_id bigint DEFAULT NULL, author_id bigint DEFAULT NULL, cached_url varchar(2083) DEFAULT NULL, PRIMARY KEY (id)',
	'referrers'      => 'id bigint NOT NULL AUTO_INCREMENT, domain varchar(128) NOT NULL, referrer_type_id bigint DEFAULT NULL, PRIMARY KEY (id)',
	'referrer_types' => 'id bigint NOT NULL AUTO_INCREMENT, referrer_type varchar(20) NOT NULL, PRIMARY KEY (id)',
);
$ia_real   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $ia_prefix . 'views' ) ) );

if ( $ia_real ) {
	WP_CLI::log( 'SKIP sezione 15: le tabelle di Independent Analytics esistono già (dati veri).' );
} else {
	foreach ( $ia_ddl as $wp5w_name => $wp5w_columns ) {
		$wpdb->query( "CREATE TABLE {$ia_prefix}{$wp5w_name} ( {$wp5w_columns} )" );
	}

	// Il fuso del sito è Europe/Rome, come sul blog: l'ora legale cambia il giorno delle visite di mezzanotte.
	$ia_tz_backup = array( get_option( 'timezone_string' ), get_option( 'gmt_offset' ) );
	update_option( 'timezone_string', 'Europe/Rome' );

	// Passo 1: tipi di referrer, referrer e risorse. Home con «Gli ultimi articoli» (show_on_front = posts): ID 0.
	$ia_types = array( 'Direct', 'Search', 'Social', 'Referrer', 'AI', 'Ad' );
	foreach ( $ia_types as $wp5w_type ) {
		$wpdb->insert( $ia_prefix . 'referrer_types', array( 'referrer_type' => $wp5w_type ) );
	}
	$ia_type_id = array_flip( $ia_types );
	$ia_ref     = array();
	foreach ( array(
		'direct'  => array( '', 'Direct' ),
		'google'  => array( 'www.google.com', 'Search' ),
		'claude'  => array( 'claude.ai', 'Referrer' ), // IA lo chiama «Referrer»: il canale `ai` lo decide ai_sql().
		'annuncio' => array( 'ads.example.org', 'Ad' ),
	) as $wp5w_key => [ $wp5w_domain, $wp5w_type ] ) {
		$wpdb->insert(
			$ia_prefix . 'referrers',
			array(
				'domain'           => $wp5w_domain,
				'referrer_type_id' => $ia_type_id[ $wp5w_type ] + 1,
			)
		);
		$ia_ref[ $wp5w_key ] = (int) $wpdb->insert_id;
	}
	$ia_res = array();
	foreach ( array(
		'post'    => array( 'singular', $post_a, null, null, home_url( '/articolo-prova/' ) ),
		'home'    => array( 'home', null, null, null, home_url( '/' ) ),
		'cat'     => array( 'term_archive', null, 1, null, home_url( '/category/uncategorized/' ) ),
		'e404'    => array( '404', null, null, null, home_url( '/non-esiste/' ) ),
		'virtual' => array( 'virtual_page', null, null, null, home_url( '/virtuale/' ) ),
	) as $wp5w_key => [ $wp5w_resource, $wp5w_singular, $wp5w_term, $wp5w_author, $wp5w_url ] ) {
		$wpdb->insert(
			$ia_prefix . 'resources',
			array(
				'resource'    => $wp5w_resource,
				'singular_id' => $wp5w_singular,
				'term_id'     => $wp5w_term,
				'author_id'   => $wp5w_author,
				'cached_url'  => $wp5w_url,
			)
		);
		$ia_res[ $wp5w_key ] = (int) $wpdb->insert_id;
	}

	/**
	 * Inserisce una sessione di IA con le sue visite (orari UTC) e restituisce l'ID della sessione.
	 *
	 * @param int                           $visitor  Visitatore.
	 * @param string                        $start    Inizio della sessione (UTC).
	 * @param int|null                      $referrer Referrer.
	 * @param array<int,array{0:int,1:string}> $views Visite: risorsa e orario UTC; la prima è quella d'ingresso.
	 * @return int
	 */
	$ia_session = static function ( int $visitor, string $start, ?int $referrer, array $views ) use ( $wpdb, $ia_prefix ): int {
		$wpdb->insert(
			$ia_prefix . 'sessions',
			array(
				'visitor_id'  => $visitor,
				'created_at'  => $start,
				'referrer_id' => $referrer,
			)
		);
		$session = (int) $wpdb->insert_id;
		$first   = 0;
		foreach ( $views as [ $resource, $at ] ) {
			$wpdb->insert(
				$ia_prefix . 'views',
				array(
					'resource_id' => $resource,
					'viewed_at'   => $at,
					'session_id'  => $session,
				)
			);
			$first = $first ?: (int) $wpdb->insert_id;
		}
		$wpdb->update( $ia_prefix . 'sessions', array( 'initial_view_id' => $first ), array( 'session_id' => $session ) );
		return $session;
	};

	// Passo 2: visite d'estate (+02:00) e d'inverno (+01:00), alcune a cavallo della mezzanotte locale.
	// 14/07: s1 (visitatore 1, Google) 10:00 articolo, categoria, pagina virtuale; s3 (visitatore 1, claude.ai) 12:00 articolo.
	// 14/07 alle 22:30 UTC è già il 15/07 in Italia (s2, visitatore 2, diretto, home).
	$ia_session( 1, '2026-07-14 10:00:00', $ia_ref['google'], array( array( $ia_res['post'], '2026-07-14 10:00:00' ), array( $ia_res['cat'], '2026-07-14 10:05:00' ), array( $ia_res['virtual'], '2026-07-14 11:00:00' ) ) );
	$ia_session( 2, '2026-07-14 22:30:00', $ia_ref['direct'], array( array( $ia_res['home'], '2026-07-14 22:30:00' ) ) );
	$ia_session( 1, '2026-07-14 12:00:00', $ia_ref['claude'], array( array( $ia_res['post'], '2026-07-14 12:00:00' ) ) );
	// D'inverno 14/01 alle 23:30 UTC è il 15/01 locale (404); alle 22:30 UTC è ancora il 14/01 locale (articolo, referrer a pagamento).
	$ia_session( 3, '2026-01-14 23:30:00', $ia_ref['direct'], array( array( $ia_res['e404'], '2026-01-14 23:30:00' ) ) );
	$ia_session( 4, '2026-01-14 22:30:00', $ia_ref['annuncio'], array( array( $ia_res['post'], '2026-01-14 22:30:00' ) ) );

	// Passo 3: sorgente riconosciuta e import. Le righe wps e own non devono cambiare.
	$ia_source = new Mavida\FiveW\Sources\IndependentAnalyticsSource();
	$ia_detect = $ia_source->detect();
	$check( 'IA: sorgente riconosciuta', true, $ia_detect['ok'] );
	$check( 'IA: versione = IAWP_VERSION', defined( 'IAWP_VERSION' ) ? IAWP_VERSION : '', (string) $ia_detect['version'] );

	$origin_counts = static function () use ( $wpdb ): string {
		$parts = array();
		foreach ( Mavida\FiveW\Support\Origin::TABLES as $wp5w_name ) {
			foreach ( array( 'wps', 'own' ) as $wp5w_origin ) {
				$parts[] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $wp5w_name ) . " WHERE origin = '{$wp5w_origin}'" );
			}
		}
		return implode( ',', $parts );
	};
	$before = $origin_counts();
	$ia_source->import( new DateRange( '2026-01-01', '2026-07-31' ) );
	$check( 'IA: le righe wps e own non cambiano', $before, $origin_counts() );

	$ia_rows = static function ( string $table, string $columns, string $where = '1=1' ) use ( $wpdb ): array {
		return $wpdb->get_col( "SELECT CONCAT_WS('|', {$columns}) FROM " . Schema::table( $table ) . " WHERE origin = 'ia' AND {$where} ORDER BY day, 1" );
	};
	$check( 'IA: site_daily (giorno del sito, ora legale, pagina virtuale nei totali)', '2026-01-14|1|1;2026-01-15|1|1;2026-07-14|1|4;2026-07-15|1|1', implode( ';', $ia_rows( 'site_daily', 'day, visitors, views' ) ) );
	$ia_uri = wp_make_link_relative( home_url( '/articolo-prova/' ) );
	$check(
		'IA: object_daily (tipi e ID come il Tracker, senza pagine virtuali)',
		implode( ';', array( "2026-01-14|post|{$post_a}|1|1|{$ia_uri}", '2026-01-15|404|0|1|1|' . wp_make_link_relative( home_url( '/non-esiste/' ) ), "2026-07-14|category|1|1|1|" . wp_make_link_relative( home_url( '/category/uncategorized/' ) ), "2026-07-14|post|{$post_a}|2|1|{$ia_uri}", '2026-07-15|home|0|1|1|' . wp_make_link_relative( home_url( '/' ) ) ) ),
		implode( ';', $ia_rows( 'object_daily', 'day, object_type, object_id, views, visitors, uri' ) )
	);
	$check(
		'IA: entry_daily (canale: Ad non assegnato, claude.ai è ai)',
		"2026-01-14|post|{$post_a}|unassigned|1;2026-01-15|404|0|direct|1;2026-07-14|post|{$post_a}|ai|1;2026-07-14|post|{$post_a}|search|1;2026-07-15|home|0|direct|1",
		implode( ';', $ia_rows( 'entry_daily', 'day, object_type, object_id, channel, visitors' ) )
	);
	$check( 'IA: dim_daily solo il canale, un visitatore per giorno con la prima sessione', '2026-01-14|channel|unassigned|1;2026-01-15|channel|direct|1;2026-07-14|channel|search|1;2026-07-15|channel|direct|1', implode( ';', $ia_rows( 'dim_daily', 'day, dim, value, visitors' ) ) );
	$check( 'IA: referrer_daily non scritta', 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'referrer_daily' ) . " WHERE origin = 'ia'" ) );

	// Passo 4: idempotenza.
	$ia_before = md5( implode( ';', array_merge( $ia_rows( 'site_daily', 'day, visitors, views' ), $ia_rows( 'entry_daily', 'day, object_type, object_id, channel, visitors' ) ) ) );
	$ia_source->import( new DateRange( '2026-01-01', '2026-07-31' ) );
	$check( 'IA: reimportare dà le stesse righe', $ia_before, md5( implode( ';', array_merge( $ia_rows( 'site_daily', 'day, visitors, views' ), $ia_rows( 'entry_daily', 'day, object_type, object_id, channel, visitors' ) ) ) ) );

	// Passo 5: le righe ia sono invisibili alle altre schermate (Origin::where seleziona solo wps e own).
	foreach ( array( '', '2026-03-01' ) as $wp5w_since ) {
		$where = Mavida\FiveW\Support\Origin::where( $wp5w_since, 's' );
		$check( "IA: Origin::where('{$wp5w_since}') non seleziona righe ia", 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'site_daily' ) . " s WHERE s.origin = 'ia' AND {$where}" ) );
	}

	// Passo 6: l'Importer salta IA se la sorgente non è riconosciuta, e la scrive con il suo stato se lo è.
	$ia_state = 'wp5w_verify_ia_state';
	$ia_run   = ( new Importer( array( $ia_source ), $ia_state ) )->run( new DateRange( '2026-07-01', '2026-07-31' ), 'test' );
	$check( 'IA: Importer ok', true, $ia_run['ok'] ?? false );
	delete_option( $ia_state );

	// Passo 7: Confronto a tre. D = oggi. wps su D-4..D-1; own da D-4 (primo giorno parziale); ia da D-3 (installato dopo, quindi parziale).
	$ia_backup = array();
	foreach ( array( 'site_daily', 'object_daily', 'entry_daily', 'dim_daily' ) as $wp5w_name ) {
		$ia_backup[ $wp5w_name ] = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( $wp5w_name ) . " WHERE origin IN ('wps','own') AND day >= '{$d[4]}'", ARRAY_A );
		$wpdb->query( 'DELETE FROM ' . Schema::table( $wp5w_name ) . " WHERE origin IN ('wps','own','ia','xx') AND day >= '{$d[4]}'" );
	}
	$ia_state_backup = get_option( Importer::STATE_OPTION, false );
	$ia_discards     = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'discards' ), ARRAY_A );
	$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( 'discards' ) );
	// Le righe ia dei passi precedenti (date del 2026 passato) si tolgono: per il confronto servono righe ia solo da D-3.
	foreach ( Mavida\FiveW\Support\Origin::TABLES as $wp5w_name ) {
		$wpdb->query( 'DELETE FROM ' . Schema::table( $wp5w_name ) . " WHERE origin = 'ia'" );
	}

	$set = array(
		'site_daily'   => array(
			array( 'wps', $d[2], array( 'visitors' => 10, 'views' => 20 ) ),
			array( 'wps', $d[1], array( 'visitors' => 20, 'views' => 40 ) ),
			array( 'own', $d[4], array( 'visitors' => 1, 'views' => 1 ) ),
			array( 'own', $d[3], array( 'visitors' => 5, 'views' => 5 ) ),
			array( 'own', $d[2], array( 'visitors' => 9, 'views' => 18 ) ),
			array( 'own', $d[1], array( 'visitors' => 19, 'views' => 38 ) ),
			array( 'ia', $d[3], array( 'visitors' => 2, 'views' => 2 ) ),
			array( 'ia', $d[2], array( 'visitors' => 5, 'views' => 10 ) ),
			array( 'ia', $d[1], array( 'visitors' => 20, 'views' => 40 ) ),
		),
		'object_daily' => array(
			array( 'wps', $d[2], array( 'object_type' => 'post', 'object_id' => $post_a, 'uri' => $ia_uri, 'views' => 10, 'visitors' => 5 ) ),
			array( 'wps', $d[1], array( 'object_type' => 'post', 'object_id' => $post_a, 'uri' => $ia_uri, 'views' => 20, 'visitors' => 9 ) ),
			array( 'own', $d[2], array( 'object_type' => 'post', 'object_id' => $post_a, 'uri' => $ia_uri, 'views' => 9, 'visitors' => 4 ) ),
			array( 'own', $d[1], array( 'object_type' => 'post', 'object_id' => $post_a, 'uri' => $ia_uri, 'views' => 19, 'visitors' => 9 ) ),
			array( 'ia', $d[2], array( 'object_type' => 'post', 'object_id' => $post_a, 'uri' => $ia_uri, 'views' => 4, 'visitors' => 3 ) ),
			array( 'ia', $d[1], array( 'object_type' => 'post', 'object_id' => $post_a, 'uri' => $ia_uri, 'views' => 8, 'visitors' => 5 ) ),
			// Un'origine sconosciuta non deve finire nelle visualizzazioni di WP Statistics.
			array( 'xx', $d[2], array( 'object_type' => 'post', 'object_id' => $post_a, 'uri' => $ia_uri, 'views' => 100, 'visitors' => 50 ) ),
		),
		'entry_daily'  => array(
			array( 'wps', $d[2], array( 'object_type' => 'home', 'object_id' => 0, 'channel' => 'direct', 'visitors' => 4 ) ),
			array( 'own', $d[2], array( 'object_type' => 'home', 'object_id' => 0, 'channel' => 'direct', 'visitors' => 3 ) ),
			array( 'ia', $d[2], array( 'object_type' => 'home', 'object_id' => 0, 'channel' => 'direct', 'visitors' => 6 ) ),
		),
		'dim_daily'    => array(
			array( 'wps', $d[2], array( 'dim' => 'channel', 'value' => 'direct', 'visitors' => 10 ) ),
			array( 'own', $d[2], array( 'dim' => 'channel', 'value' => 'direct', 'visitors' => 9 ) ),
			array( 'ia', $d[2], array( 'dim' => 'channel', 'value' => 'direct', 'visitors' => 5 ) ),
			array( 'ia', $d[2], array( 'dim' => 'channel', 'value' => 'ai', 'visitors' => 2 ) ),
		),
	);
	foreach ( $set as $wp5w_name => $wp5w_list ) {
		foreach ( $wp5w_list as [ $wp5w_origin, $wp5w_day_value, $wp5w_row ] ) {
			$wpdb->insert( Schema::table( $wp5w_name ), $wp5w_row + array( 'day' => $wp5w_day_value, 'origin' => $wp5w_origin ) );
		}
	}
	// L'ultimo ETL è partito adesso con `to` = oggi: ieri è completo per WP Statistics.
	update_option( Importer::STATE_OPTION, array( 'mode' => 'daily', 'from' => $d[4], 'to' => $d[0], 'started' => time(), 'ok' => true ), false );

	$admin_user( $admin_id );
	$three = $rest_call( 'GET', '/wp5w/v1/admin/compare' )->get_data();
	$ia_excluded = array_map( static fn( $item ) => $item['day'] . ':' . $item['reason'], $three['coverage']['excluded'] ?? array() );
	$check( 'tre origini: ia_available', true, $three['ia_available'] ?? false );
	$check( 'tre origini: finestra da D-2 a D-1', $d[2] . ',' . $d[1], implode( ',', array_column( $three['days'] ?? array(), 'day' ) ) );
	$check( 'tre origini: D-4 partial, D-3 ia_partial, oggi today', true, in_array( $d[4] . ':partial', $ia_excluded, true ) && in_array( $d[3] . ':ia_partial', $ia_excluded, true ) && in_array( $d[0] . ':today', $ia_excluded, true ) );
	$check( 'tre origini: totali wps', '30|60', ( $three['totals']['wps']['visitors'] ?? '' ) . '|' . ( $three['totals']['wps']['views'] ?? '' ) );
	$check( 'tre origini: totali own', '28|56', ( $three['totals']['own']['visitors'] ?? '' ) . '|' . ( $three['totals']['own']['views'] ?? '' ) );
	$check( 'tre origini: totali ia', '25|50', ( $three['totals']['ia']['visitors'] ?? '' ) . '|' . ( $three['totals']['ia']['views'] ?? '' ) );
	$check( 'tre origini: differenze own su wps', '-6.7|-6.7', ( $three['totals']['diff']['visitors'] ?? '' ) . '|' . ( $three['totals']['diff']['views'] ?? '' ) );
	$check( 'tre origini: differenze ia su wps', '-16.7|-16.7', ( $three['totals']['diff_ia']['visitors'] ?? '' ) . '|' . ( $three['totals']['diff_ia']['views'] ?? '' ) );
	$check( 'tre origini: D-2 ia 5|10, diff_ia -50|-50', '5|10|-50|-50', ( $three['days'][0]['ia']['visitors'] ?? '' ) . '|' . ( $three['days'][0]['ia']['views'] ?? '' ) . '|' . ( $three['days'][0]['diff_ia']['visitors'] ?? '' ) . '|' . ( $three['days'][0]['diff_ia']['views'] ?? '' ) );
	$check( 'tre origini: D-2 segnalato (ia oltre soglia), D-1 no', '1|0', (int) ( $three['days'][0]['flagged'] ?? 0 ) . '|' . (int) ( $three['days'][1]['flagged'] ?? 0 ) );
	$check( 'tre origini: ingressi diretti sulla home 4|3|6', '4|3|6', ( $three['home_direct']['wps'] ?? '' ) . '|' . ( $three['home_direct']['own'] ?? '' ) . '|' . ( $three['home_direct']['ia'] ?? '' ) );
	$channels_by_name = array_column( $three['channels'] ?? array(), null, 'channel' );
	$check( 'tre origini: canale direct 10|9|5', '10|9|5', ( $channels_by_name['direct']['wps'] ?? '' ) . '|' . ( $channels_by_name['direct']['own'] ?? '' ) . '|' . ( $channels_by_name['direct']['ia'] ?? '' ) );
	$check( 'tre origini: canale ai solo di ia (0|0|2)', '0|0|2', ( $channels_by_name['ai']['wps'] ?? '' ) . '|' . ( $channels_by_name['ai']['own'] ?? '' ) . '|' . ( $channels_by_name['ai']['ia'] ?? '' ) );
	$top_row = $three['top'][0] ?? array();
	$check( 'tre origini: contenuto in testa 30|28|12', '30|28|12', ( $top_row['wps_views'] ?? '' ) . '|' . ( $top_row['own_views'] ?? '' ) . '|' . ( $top_row['ia_views'] ?? '' ) );
	$check( 'tre origini: differenza assoluta ia e % (-60)', '18|-60', ( $top_row['diff_abs_ia'] ?? '' ) . '|' . ( $top_row['diff_ia'] ?? '' ) );
	$check( 'tre origini: un\'origine sconosciuta non entra in wps_views', 30, $top_row['wps_views'] ?? '' );

	// Passo 8: un giorno senza righe ia esce dalla finestra come no_ia.
	$wpdb->delete( Schema::table( 'site_daily' ), array( 'day' => $d[1], 'origin' => 'ia' ) );
	$partial = $rest_call( 'GET', '/wp5w/v1/admin/compare' )->get_data();
	$check( 'tre origini: senza ia su D-1 la finestra è solo D-2', $d[2], implode( ',', array_column( $partial['days'] ?? array(), 'day' ) ) );
	$check( 'tre origini: D-1 escluso come no_ia', true, in_array( $d[1] . ':no_ia', array_map( static fn( $item ) => $item['day'] . ':' . $item['reason'], $partial['coverage']['excluded'] ?? array() ), true ) );

	// Passo 9: senza nessuna riga ia la risposta è quella a due origini (stesse chiavi di prima, finestra da D-3).
	$wpdb->query( 'DELETE FROM ' . Schema::table( 'site_daily' ) . " WHERE origin = 'ia'" );
	$wpdb->insert( Schema::table( 'site_daily' ), array( 'day' => $d[3], 'origin' => 'wps', 'visitors' => 6, 'views' => 6 ) );
	$two = $rest_call( 'GET', '/wp5w/v1/admin/compare' )->get_data();
	$check( 'due origini: ia_available falso', false, $two['ia_available'] ?? true );
	$check( 'due origini: finestra da D-3 a D-1', $d[3] . ',' . $d[2] . ',' . $d[1], implode( ',', array_column( $two['days'] ?? array(), 'day' ) ) );
	$check( 'due origini: nessuna chiave ia nei giorni, nei totali e nei canali', '0|0|0', (int) isset( $two['days'][0]['ia'] ) . '|' . (int) isset( $two['totals']['ia'] ) . '|' . (int) isset( $two['channels'][0]['ia'] ) );
	$check( 'due origini: nessuna chiave ia nei contenuti', 0, (int) isset( $two['top'][0]['ia_views'] ) );

	// Passo 10: ripristino.
	foreach ( array( 'site_daily', 'object_daily', 'entry_daily', 'dim_daily' ) as $wp5w_name ) {
		$wpdb->query( 'DELETE FROM ' . Schema::table( $wp5w_name ) . " WHERE origin IN ('wps','own','ia','xx') AND day >= '{$d[4]}'" );
		foreach ( $ia_backup[ $wp5w_name ] as $wp5w_row ) {
			$wpdb->insert( Schema::table( $wp5w_name ), $wp5w_row );
		}
	}
	$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( 'discards' ) );
	foreach ( $ia_discards as $wp5w_row ) {
		$wpdb->insert( Schema::table( 'discards' ), $wp5w_row );
	}
	if ( false === $ia_state_backup ) {
		delete_option( Importer::STATE_OPTION );
	} else {
		update_option( Importer::STATE_OPTION, $ia_state_backup, false );
	}
	foreach ( array_keys( $ia_ddl ) as $wp5w_name ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$ia_prefix}{$wp5w_name}" );
	}
	update_option( 'timezone_string', (string) $ia_tz_backup[0] );
	update_option( 'gmt_offset', $ia_tz_backup[1] );
	$admin_user( 0 );
}

// ---------------------------------------------------------------------------
// 16. Pulizia finale.
// ---------------------------------------------------------------------------
// Filtri e simulazioni della sezione 13 (idempotente), stato di plugin-update-checker e utente di prova.
remove_filter( 'pre_http_request', $github_mock, 10 );
remove_filter( 'upgrader_pre_download', $block_download );
remove_filter( 'user_has_cap', $without_update_cap );
$restore_state();
foreach ( array( 'wp5w_verify_subscriber3' ) as $wp5w_login ) {
	$wp5w_user = get_user_by( 'login', $wp5w_login );
	if ( $wp5w_user ) {
		wp_delete_user( $wp5w_user->ID );
	}
}
update_option( Settings::OPTION, Settings::defaults() );
update_option( Settings::OWN_SINCE, '' );
$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( 'hits' ) );
$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( 'discards' ) );
$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( 'notfound_daily' ) );
$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( 'search_daily' ) );
foreach ( Mavida\FiveW\Support\Origin::TABLES as $wp5w_name ) {
	$wpdb->query( 'DELETE FROM ' . Schema::table( $wp5w_name ) . " WHERE origin = 'own'" );
}
delete_option( 'wp5w_daily_salt' );
wp_set_current_user( 0 );
unset( $_COOKIE[ LOGGED_IN_COOKIE ], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );

if ( $failures ) {
	WP_CLI::error( "{$failures} controlli falliti." );
}
WP_CLI::success( 'Tutti i controlli superati.' );
