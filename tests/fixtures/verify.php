<?php
/**
 * Verifica l'ETL e la raccolta propria sui dati di seed.php (oppure su un dump reale).
 *
 * Uso: npx wp-env run cli wp eval-file wp-content/plugins/wp-5w/tests/fixtures/verify.php
 *
 * Controlla lo schema v4 e la migrazione da v3, confronta le tabelle wp5w_* con le sorgenti, simula le visite
 * all'endpoint /hit, verifica l'aggregazione, la regola dell'origine, le rotte delle opzioni, il confronto con WP Statistics
 * e, dalla fase 3, il box Pubblico, il database dei paesi (download reale e guasti simulati) e paese e user agent delle visite.
 *
 * @package Mavida\FiveW
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Etl\Importer;
use Mavida\FiveW\Front\Tracker;
use Mavida\FiveW\Geo\CountryDatabase;
use Mavida\FiveW\Plugin;
use Mavida\FiveW\Query\AudienceQuery;
use Mavida\FiveW\Query\OverviewQuery;
use Mavida\FiveW\Query\PostsQuery;
use Mavida\FiveW\Query\SearchQuery;
use Mavida\FiveW\Query\TrendQuery;
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

// Chiavi primarie attese della versione 4.
$v4_keys = array(
	'site_daily'     => 'day,origin',
	'object_daily'   => 'day,object_type,object_id,origin',
	'entry_daily'    => 'day,object_type,object_id,channel,origin',
	'referrer_daily' => 'day,object_type,object_id,referrer,origin',
	'dim_daily'      => 'day,dim,value,origin',
);

// ---------------------------------------------------------------------------
// 1. Schema v4.
// ---------------------------------------------------------------------------
$check( 'schema: wp5w_db_version = Schema::DB_VERSION', Schema::DB_VERSION, get_option( 'wp5w_db_version' ) );
$check( 'schema: versione 4', '4', Schema::DB_VERSION );
foreach ( $v4_keys as $wp5w_name => $wp5w_expected ) {
	$check( "schema: chiave primaria di {$wp5w_name}", $wp5w_expected, $primary_key( $wp5w_name ) );
}
foreach ( Schema::RAW_TABLES as $wp5w_name ) {
	$check( "schema: esiste wp5w_{$wp5w_name}", Schema::table( $wp5w_name ), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( $wp5w_name ) ) ) );
}
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
$check( 'migrazione: versione 4', '4', get_option( 'wp5w_db_version' ) );
foreach ( $v4_keys as $wp5w_name => $wp5w_expected ) {
	$check( "migrazione: chiave primaria di {$wp5w_name}", $wp5w_expected, $primary_key( $wp5w_name ) );
	$check( "migrazione: righe esistenti di {$wp5w_name} con origin = wps", 1, $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $wp5w_name ) . " WHERE origin = 'wps'" ) );
}

// Idempotenza: con le tabelle già v4 install() non esegue nessun ALTER e non cambia SHOW CREATE TABLE.
$show_create = static function () use ( $wpdb ): string {
	$out = '';
	foreach ( array_merge( Schema::TABLES, Schema::RAW_TABLES ) as $name ) {
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
$check( 'install() rieseguito: versione 4', '4', get_option( 'wp5w_db_version' ) );
$check( 'install() rieseguito: SHOW CREATE TABLE identico', md5( $create_before ), md5( $show_create() ) );

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

	return array(
		'overview'     => (string) wp_json_encode( $overview->get( $r30 ) ),
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

// Bot e user agent vuoto.
$before = array( $count_hits(), $discarded( 'bot' ) );
$check( 'bot Googlebot: 204', 204, $send_hit( $payload( $post_a ), '198.51.100.2', 'Googlebot/2.1 (+http://www.google.com/bot.html)' ) );
$check( 'bot UA vuoto: 204', 204, $send_hit( $payload( $post_a ), '198.51.100.3', '' ) );
$check( 'bot: nessuna riga', $before[0], $count_hits() );
$check( 'bot: scarti +2', $before[1] + 2, $discarded( 'bot' ) );

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
		if ( str_contains( (string) $url, 'download.db-ip.com' ) ) {
			++$geo_http_calls;
		}
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
$check( 'bot da 8.8.8.8: nessuna riga', $before[0], $count_hits() );
$check( 'bot da 8.8.8.8: scarto bot +1', $before[1] + 1, $discarded( 'bot' ) );

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
$privacy_columns = "CONCAT_WS('|', object_type, uri, referrer_host, channel, source, utm_source, utm_medium, utm_campaign, country, device, browser, os)";
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
$check( 'aggregazione: dimensioni own', 'browser,channel,country,device,platform,source', implode( ',', array_keys( array_diff_key( $own_dims, array( 'os' => 1 ) ) ) ) );
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
// 12. Pulizia finale.
// ---------------------------------------------------------------------------
update_option( Settings::OPTION, Settings::defaults() );
update_option( Settings::OWN_SINCE, '' );
$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( 'hits' ) );
$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( 'discards' ) );
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
