<?php
/**
 * Plugin Name:       wp-5w
 * Plugin URI:        https://github.com/mavidasnc/wp-5w
 * Description:       Dashboard di analytics in wp-admin che incrocia i dati già raccolti da WP Statistics e da Rank Math (Search Console), salvandoli in tabelle proprie pronte per le interrogazioni.
 * Version:           0.10.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            Mavida snc
 * Author URI:        https://mavida.it
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-5w
 * Domain Path:       /languages
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

// Blocco accesso diretto.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Costanti del plugin (guard per evitare conflitti con wp-config.php).
if ( ! defined( 'WP5W_VERSION' ) ) {
	define( 'WP5W_VERSION', '0.10.0' );
}
if ( ! defined( 'WP5W_PLUGIN_FILE' ) ) {
	define( 'WP5W_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WP5W_PLUGIN_DIR' ) ) {
	define( 'WP5W_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WP5W_PLUGIN_URL' ) ) {
	define( 'WP5W_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Autoload PSR-4 via Composer.
$wp5w_autoload = WP5W_PLUGIN_DIR . 'vendor/autoload.php';
if ( ! file_exists( $wp5w_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'wp-5w: dipendenze Composer non trovate. Esegui "composer install" nella cartella del plugin.',
				'wp-5w'
			);
			echo '</p></div>';
		}
	);
	return;
}
require_once $wp5w_autoload;

// Hook di attivazione e disattivazione: devono stare nel file principale.
register_activation_hook( __FILE__, array( 'Mavida\\FiveW\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Mavida\\FiveW\\Deactivator', 'deactivate' ) );

// Inizializzazione su plugins_loaded, quando WP Statistics e Rank Math sono già caricati.
add_action(
	'plugins_loaded',
	static function (): void {
		Mavida\FiveW\Plugin::get_instance()->init();
	}
);
