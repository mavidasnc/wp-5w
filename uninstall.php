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

if ( ! defined( 'WP5W_DELETE_DATA' ) || ! WP5W_DELETE_DATA ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( array( 'site_daily', 'object_daily', 'entry_daily', 'referrer_daily', 'dim_daily', 'gsc_daily' ) as $wp5w_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wp5w_{$wp5w_table}" );
}

delete_option( 'wp5w_db_version' );
delete_option( 'wp5w_etl_state' );
