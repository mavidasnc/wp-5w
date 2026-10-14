<?php
/**
 * Verifica l'ETL sui dati di seed.php (oppure su un dump reale).
 *
 * Uso: npx wp-env run cli wp eval-file wp-content/plugins/wp-5w/tests/fixtures/verify.php
 *
 * Confronta le tabelle wp5w_* con le sorgenti e controlla che un secondo import non cambi nulla.
 *
 * @package Mavida\FiveW
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Etl\Importer;
use Mavida\FiveW\Plugin;

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

// Si parte da tabelle vuote: le righe di prove precedenti, assenti dalla sorgente, verrebbero conservate.
foreach ( Schema::TABLES as $wp5w_name ) {
	$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( $wp5w_name ) );
}

$state = Plugin::importer()->run( Importer::full_range(), 'full' );
$check( 'import completo senza errori', '1', $state['ok'] ? '1' : '0' );

// Totali del sito: summary_totals (giorni non a zero) + dettaglio per i giorni senza un totale valido.
$expected_views = (int) $wpdb->get_var(
	"SELECT SUM(views) FROM {$p}statistics_summary_totals WHERE visitors > 0 OR views > 0"
) + (int) $wpdb->get_var(
	"SELECT COALESCE(SUM(count), 0) FROM {$p}statistics_pages WHERE date NOT IN ( SELECT date FROM {$p}statistics_summary_totals WHERE visitors > 0 OR views > 0 )"
);
$check( 'site_daily.views', $expected_views, $wpdb->get_var( 'SELECT SUM(views) FROM ' . Schema::table( 'site_daily' ) ) );
$check(
	'site_daily senza giorni a zero',
	0,
	$wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'site_daily' ) . ' WHERE visitors = 0 AND views = 0' )
);

// Contenuti, ingressi e dimensioni.
$check( 'object_daily.views', $wpdb->get_var( "SELECT SUM(count) FROM {$p}statistics_pages" ), $wpdb->get_var( 'SELECT SUM(views) FROM ' . Schema::table( 'object_daily' ) ) );
$check(
	'entry_daily.visitors',
	$wpdb->get_var( "SELECT COUNT(*) FROM {$p}statistics_visitor v JOIN {$p}statistics_pages p ON p.page_id = v.first_page" ),
	$wpdb->get_var( 'SELECT SUM(visitors) FROM ' . Schema::table( 'entry_daily' ) )
);
$check(
	'dim_daily per dimensione = visitatori',
	$wpdb->get_var( "SELECT COUNT(*) FROM {$p}statistics_visitor" ),
	$wpdb->get_var( 'SELECT SUM(visitors) FROM ' . Schema::table( 'dim_daily' ) . " WHERE dim = 'channel'" )
);

// Visitatori unici per contenuto e referrer per pagina d'ingresso.
$check(
	'object_daily.visitors = visitatori distinti per pagina e giorno',
	$wpdb->get_var(
		"SELECT COALESCE(SUM(n), 0) FROM ( SELECT COUNT(DISTINCT r.visitor_id) AS n FROM {$p}statistics_visitor_relationships r
		JOIN {$p}statistics_pages p ON p.page_id = r.page_id GROUP BY DATE(r.date), p.type, p.id ) t"
	),
	$wpdb->get_var( 'SELECT SUM(visitors) FROM ' . Schema::table( 'object_daily' ) )
);
$check(
	'referrer_daily.visitors = visitatori con referrer',
	$wpdb->get_var( "SELECT COUNT(*) FROM {$p}statistics_visitor v JOIN {$p}statistics_pages p ON p.page_id = v.first_page WHERE v.referred <> ''" ),
	$wpdb->get_var( 'SELECT SUM(visitors) FROM ' . Schema::table( 'referrer_daily' ) )
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

if ( $failures ) {
	WP_CLI::error( "{$failures} controlli falliti." );
}
WP_CLI::success( 'Tutti i controlli superati.' );
