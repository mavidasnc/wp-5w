<?php
/**
 * Menu e pagina di amministrazione.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registra la pagina "5W" e carica l'app React.
 */
final class AdminMenu {

	/**
	 * Slug del menu.
	 *
	 * @var string
	 */
	public const MENU_SLUG = 'wp5w';

	/**
	 * Aggancia gli hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WP5W_PLUGIN_FILE ), array( $this, 'add_dashboard_link' ) );
	}

	/**
	 * Aggiunge il link "Dashboard" nella riga del plugin, nell'elenco dei plugin.
	 *
	 * @param array<int|string,string> $links Link esistenti.
	 * @return array<int|string,string>
	 */
	public function add_dashboard_link( array $links ): array {
		$url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Dashboard', 'wp-5w' ) . '</a>' );
		return $links;
	}

	/**
	 * Aggiunge la voce di menu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( '5W Analytics', 'wp-5w' ),
			__( '5W', 'wp-5w' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-chart-area',
			3
		);
	}

	/**
	 * Stampa il mount point dell'app React.
	 *
	 * @return void
	 */
	public function render_page(): void {
		echo '<div class="wrap"><div id="wp5w-admin-root"></div></div>';
	}

	/**
	 * Carica gli asset solo nella pagina del plugin.
	 *
	 * @param string $hook_suffix Suffisso della pagina admin corrente.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		$asset_file = WP5W_PLUGIN_DIR . 'build/admin/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			'wp5w-admin',
			WP5W_PLUGIN_URL . 'build/admin/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_enqueue_style(
			'wp5w-admin',
			WP5W_PLUGIN_URL . 'build/admin/index.css',
			array( 'wp-components' ),
			$asset['version']
		);
		wp_set_script_translations( 'wp5w-admin', 'wp-5w', WP5W_PLUGIN_DIR . 'languages' );

		// Dati per il client JS.
		wp_localize_script(
			'wp5w-admin',
			'wp5wAdmin',
			array(
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'apiRoot' => esc_url_raw( rest_url( 'wp5w/v1' ) ),
				'today'   => wp_date( 'Y-m-d' ),
				'version' => WP5W_VERSION,
			)
		);
	}
}
