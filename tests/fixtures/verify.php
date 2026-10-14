<?php
/**
 * Verifica l'ETL e la raccolta propria sui dati di seed.php (oppure su un dump reale).
 *
 * Uso: npx wp-env run cli wp eval-file wp-content/plugins/wp-5w/tests/fixtures/verify.php
 *
 * Controlla lo schema v4 e la migrazione da v3, confronta le tabelle wp5w_* con le sorgenti, simula le visite
 * all'endpoint /hit, verifica l'aggregazione, la regola dell'origine e le rotte delle opzioni.
 *
 * @package Mavida\FiveW
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Etl\Importer;
use Mavida\FiveW\Front\Tracker;
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
$check( 'install() rieseguito: SHOW CREATE TABLE identico', $create_before, $show_create() );

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
		)
	)
);
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
( new OwnSource() )->run_hourly();
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
// 10. Pulizia finale.
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
