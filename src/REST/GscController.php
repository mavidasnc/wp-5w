<?php
/**
 * Endpoint di amministrazione del collegamento a Search Console e dei due import (Search Console e Rank Math).
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\REST;

use Mavida\FiveW\Etl\GscApiImport;
use Mavida\FiveW\Etl\Importer;
use Mavida\FiveW\Gsc\Client;
use Mavida\FiveW\Gsc\OAuthHandler;
use Mavida\FiveW\Sources\RankMathSource;

defined( 'ABSPATH' ) || exit;

/**
 * Tutte le rotte richiedono `manage_options`. Il client secret si scrive ma non si legge mai.
 */
final class GscController extends RestController {

	/**
	 * Opzione con l'esito dell'ultimo import di Rank Math dal pulsante (separata da quella dell'ETL generale).
	 *
	 * @var string
	 */
	public const RANKMATH_STATE = 'wp5w_rankmath_state';

	/**
	 * Transient con l'elenco delle proprietà (evita una chiamata a Google a ogni apertura della scheda).
	 *
	 * @var string
	 */
	public const SITES_TRANSIENT = 'wp5w_gsc_sites';

	/**
	 * Registra le rotte.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$routes = array(
			'/admin/gsc'             => array( \WP_REST_Server::READABLE, 'status', array() ),
			'/admin/gsc/credentials' => array(
				\WP_REST_Server::CREATABLE,
				'save_credentials',
				array(
					'client_id'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'client_secret' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			),
			'/admin/gsc/site'        => array(
				\WP_REST_Server::CREATABLE,
				'select_site',
				array(
					'site' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			),
			'/admin/gsc/disconnect'  => array( \WP_REST_Server::CREATABLE, 'disconnect', array() ),
			'/admin/gsc/import'      => array( \WP_REST_Server::CREATABLE, 'import_gsc', array() ),
			'/admin/rankmath'        => array( \WP_REST_Server::READABLE, 'rankmath_status', array() ),
			'/admin/rankmath/import' => array( \WP_REST_Server::CREATABLE, 'import_rankmath', array() ),
		);

		foreach ( $routes as $path => list( $methods, $callback, $args ) ) {
			register_rest_route(
				self::API_NAMESPACE,
				$path,
				array(
					'methods'             => $methods,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => $args,
				)
			);
		}
	}

	/**
	 * Stato del collegamento: credenziali (senza il secret), proprietà, giorni da importare e ultimo import.
	 *
	 * @return \WP_REST_Response
	 */
	public function status(): \WP_REST_Response {
		$config = Client::config();
		$data   = array(
			'configured'   => Client::is_configured(),
			'connected'    => Client::is_connected(),
			'reauth'       => Client::needs_reauth(),
			'client_id'    => $config['client_id'],
			'has_secret'   => '' !== $config['client_secret'],
			'site'         => $config['site'],
			'sites'        => array(),
			'sites_error'  => '',
			'connected_at' => $config['connected_at'],
			'redirect_uri' => Client::redirect_uri(),
			'connect_url'  => OAuthHandler::connect_url(),
			'pending_days' => GscApiImport::pending_days(),
			'last'         => GscApiImport::state(),
			'flash'        => OAuthHandler::flash(),
		);

		if ( $data['connected'] ) {
			try {
				$data['sites'] = $this->sites();
			} catch ( \RuntimeException $e ) {
				$data['sites_error'] = $e->getMessage();
				// Il rinnovo del token può aver scoperto una revoca: lo stato va riletto.
				$data['connected'] = Client::is_connected();
				$data['reauth']    = Client::needs_reauth();
			}
		}

		return $this->respond( $data );
	}

	/**
	 * Salva client ID e secret.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_credentials( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$client_id = trim( sanitize_text_field( (string) $request->get_param( 'client_id' ) ) );
		$secret    = trim( (string) $request->get_param( 'client_secret' ) );

		if ( 1 !== preg_match( '/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/', $client_id ) ) {
			return new \WP_Error( 'wp5w_gsc_client_id', __( 'Il client ID non ha il formato di Google (finisce con .apps.googleusercontent.com).', 'wp-5w' ), array( 'status' => 400 ) );
		}
		if ( '' === $secret && '' === Client::config()['client_secret'] ) {
			return new \WP_Error( 'wp5w_gsc_secret', __( 'Inserisci anche il client secret.', 'wp-5w' ), array( 'status' => 400 ) );
		}

		Client::save_credentials( $client_id, $secret );
		GscApiImport::reset();
		delete_transient( self::SITES_TRANSIENT );
		return $this->status();
	}

	/**
	 * Sceglie la proprietà da cui importare.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function select_site( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! Client::is_connected() ) {
			return new \WP_Error( 'wp5w_gsc_not_connected', __( 'Collega prima Google.', 'wp-5w' ), array( 'status' => 409 ) );
		}

		try {
			Client::select_site( (string) $request->get_param( 'site' ) );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'wp5w_gsc_site', $e->getMessage(), array( 'status' => 400 ) );
		}

		// Con un'altra proprietà i giorni vuoti ricordati non valgono più.
		GscApiImport::reset();
		return $this->status();
	}

	/**
	 * Scollega Google (revoca il token); client ID e secret restano.
	 *
	 * @return \WP_REST_Response
	 */
	public function disconnect(): \WP_REST_Response {
		Client::disconnect();
		GscApiImport::reset();
		delete_transient( self::SITES_TRANSIENT );
		return $this->status();
	}

	/**
	 * Importa un lotto di giorni mancanti da Search Console; la scheda richiama finché `remaining` è zero.
	 *
	 * @return \WP_REST_Response
	 */
	public function import_gsc(): \WP_REST_Response {
		$result = ( new GscApiImport() )->run_batch();
		return $this->respond( $result, ! empty( $result['ok'] ) ? 200 : ( ! empty( $result['busy'] ) ? 409 : 500 ) );
	}

	/**
	 * Stato dell'import di Rank Math: sorgente disponibile e ultimo esito.
	 *
	 * @return \WP_REST_Response
	 */
	public function rankmath_status(): \WP_REST_Response {
		$info = ( new RankMathSource() )->detect();
		return $this->respond(
			array(
				'available' => (bool) $info['ok'],
				'version'   => $info['version'] ?? null,
				'missing'   => $info['missing'],
				'last'      => Importer::last_state( self::RANKMATH_STATE ),
			)
		);
	}

	/**
	 * Importa tutto lo storico di Rank Math, con un proprio stato: l'ETL generale e il Confronto non cambiano.
	 *
	 * @return \WP_REST_Response
	 */
	public function import_rankmath(): \WP_REST_Response {
		$state = ( new Importer( array( new RankMathSource() ), self::RANKMATH_STATE ) )->run( Importer::full_range(), 'rankmath' );
		return $this->respond( $state, ! empty( $state['ok'] ) ? 200 : 500 );
	}

	/**
	 * Proprietà di Search Console, con cache di 5 minuti.
	 *
	 * @return array<int,string>
	 * @throws \RuntimeException Se Google non risponde.
	 */
	private function sites(): array {
		$cached = get_transient( self::SITES_TRANSIENT );
		if ( is_array( $cached ) ) {
			return array_map( 'strval', $cached );
		}

		$sites = Client::sites();
		set_transient( self::SITES_TRANSIENT, $sites, 5 * MINUTE_IN_SECONDS );
		return $sites;
	}
}
