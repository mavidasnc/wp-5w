<?php
/**
 * Endpoint di amministrazione per verificare e installare gli aggiornamenti.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\REST;

use Mavida\FiveW\Support\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Usa l'updater del plugin (release di GitHub) e l'upgrader di WordPress per installare.
 */
final class UpdateController extends RestController {

	/**
	 * Registra le rotte.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Stato dall'ultimo controllo e controllo immediato: bastano le opzioni.
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/update',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/update/check',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'check' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Installare un plugin richiede una capability più alta.
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/update/install',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install' ),
				'permission_callback' => array( $this, 'check_update_permission' ),
			)
		);
	}

	/**
	 * Installare plugin richiede una capability più alta di manage_options.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_update_permission(): bool|\WP_Error {
		if ( current_user_can( 'update_plugins' ) ) {
			return true;
		}
		return new \WP_Error( 'wp5w_forbidden_update', __( 'Non hai il permesso di aggiornare i plugin.', 'wp-5w' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Stato dall'ultimo controllo (senza contattare GitHub).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function status(): \WP_REST_Response|\WP_Error {
		return $this->wrap( Updater::status() );
	}

	/**
	 * Controlla subito se c'è una release più recente.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check(): \WP_REST_Response|\WP_Error {
		return $this->wrap( Updater::check() );
	}

	/**
	 * Scarica e installa l'ultima release.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function install(): \WP_REST_Response|\WP_Error {
		// Passo 1: controllo aggiornato; senza aggiornamenti si risponde 409 senza caricare l'upgrader.
		$status = Updater::check();
		if ( null === $status ) {
			return $this->unavailable();
		}
		if ( ! $status['update_available'] ) {
			return new \WP_Error( 'wp5w_no_update', __( 'Il plugin è già aggiornato.', 'wp-5w' ), array( 'status' => 409 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$basename   = plugin_basename( WP5W_PLUGIN_FILE );
		$was_active = is_plugin_active( $basename );

		// Passo 2: la skin "ajax" raccoglie i messaggi senza stamparli, così la risposta resta JSON valido.
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $basename );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			$messages = array_map( 'wp_strip_all_tags', array_map( 'strval', $skin->get_upgrade_messages() ) );
			$errors   = $skin->get_errors();
			if ( $errors->has_errors() ) {
				$messages[] = $errors->get_error_message();
			}
			return new \WP_Error( 'wp5w_update_failed', implode( ' ', array_filter( $messages ) ), array( 'status' => 500 ) );
		}

		// Passo 3: il processo di aggiornamento può lasciare il plugin disattivato, si ripristina lo stato precedente.
		if ( $was_active && ! is_plugin_active( $basename ) ) {
			activate_plugin( $basename, '', false, true );
		}

		return $this->respond(
			array(
				'installed' => true,
				'version'   => $status['latest'],
			)
		);
	}

	/**
	 * Risposta di stato o errore se l'updater non è disponibile.
	 *
	 * @param array<string,mixed>|null $status Stato.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function wrap( ?array $status ): \WP_REST_Response|\WP_Error {
		return null === $status ? $this->unavailable() : $this->respond( $status );
	}

	/**
	 * Errore: la libreria di aggiornamento non è caricata (per esempio vendor/ mancante).
	 *
	 * @return \WP_Error
	 */
	private function unavailable(): \WP_Error {
		return new \WP_Error( 'wp5w_no_updater', __( 'Il sistema di aggiornamento non è disponibile.', 'wp-5w' ), array( 'status' => 500 ) );
	}
}
