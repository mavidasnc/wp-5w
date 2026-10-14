<?php
/**
 * Pulizia alla disinstallazione.
 *
 * Le tabelle wp5w_* sono l'unica copia dello storico che WP Statistics e Rank Math cancellano
 * con le loro finestre mobili: si eliminano solo se è stata definita la costante WP5W_DELETE_DATA.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Gli eventi cron si tolgono sempre.
wp_clear_scheduled_hook( 'wp5w_etl_daily' );
wp_clear_scheduled_hook( 'wp5w_etl_full' );
wp_clear_scheduled_hook( 'wp5w_own_hourly' );
wp_clear_scheduled_hook( 'wp5w_geo_update' );

// La chiave del giorno dell'hash dei visitatori si toglie sempre, anche senza WP5W_DELETE_DATA.
delete_option( 'wp5w_daily_salt' );

// Il database dei paesi è una cache che si riscarica, non uno storico: opzione e cartella si tolgono sempre.
delete_option( 'wp5w_geo' );
$wp5w_upload  = wp_upload_dir( null, false );
$wp5w_geo_dir = $wp5w_upload['basedir'] . '/wp5w';
foreach ( array( 'dbip-country-lite.mmdb', 'dbip-country-lite.mmdb.tmp', '.htaccess', 'index.php' ) as $wp5w_geo_file ) {
	wp_delete_file( $wp5w_geo_dir . '/' . $wp5w_geo_file );
}
// La cartella si elimina solo se è vuota (rmdir() fallisce altrimenti, e va bene così).
@rmdir( $wp5w_geo_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cartella creata dal plugin e quasi sempre vuota.

if ( ! defined( 'WP5W_DELETE_DATA' ) || ! WP5W_DELETE_DATA ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( array( 'site_daily', 'object_daily', 'entry_daily', 'referrer_daily', 'dim_daily', 'gsc_daily', 'hits', 'discards' ) as $wp5w_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wp5w_{$wp5w_table}" );
}

delete_option( 'wp5w_db_version' );
delete_option( 'wp5w_etl_state' );
delete_option( 'wp5w_settings' );
delete_option( 'wp5w_own_since' );
