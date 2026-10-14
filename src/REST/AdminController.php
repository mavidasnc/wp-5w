<?php
/**
 * Rotte REST della pagina di amministrazione.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\REST;

use Mavida\FiveW\Etl\Importer;
use Mavida\FiveW\Etl\Scheduler;
use Mavida\FiveW\Query\OverviewQuery;
use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Stato, avvio dell'ETL e dati della Panoramica (solo amministratori).
 */
final class AdminController extends RestController {

	/**
	 * Costruttore.
	 *
	 * @param Importer      $importer Importer.
	 * @param OverviewQuery $overview Interrogazioni della Panoramica.
	 */
	public function __construct(
		private readonly Importer $importer,
		private readonly OverviewQuery $overview
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/admin/etl',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_etl' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'full' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/admin/overview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'overview' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'from' => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => array( DateRange::class, 'is_valid' ),
					),
					'to'   => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => array( DateRange::class, 'is_valid' ),
					),
				),
			)
		);
	}

	/**
	 * Sorgenti, copertura delle tabelle, ultimo ETL e prossima esecuzione.
	 *
	 * @return \WP_REST_Response
	 */
	public function status(): \WP_REST_Response {
		$next = wp_next_scheduled( Scheduler::DAILY_HOOK );

		return $this->respond(
			array(
				'sources'  => array_map( static fn( $source ) => $source->detect(), $this->importer->sources() ),
				'tables'   => $this->overview->coverage(),
				'last_run' => Importer::last_state(),
				'next_run' => $next ? (int) $next : null,
				'version'  => WP5W_VERSION,
				'updates'  => Updater::status(),
			)
		);
	}

	/**
	 * Avvia l'ETL: ultimi giorni oppure import completo.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response
	 */
	public function run_etl( \WP_REST_Request $request ): \WP_REST_Response {
		$range = $request->get_param( 'full' )
			? Importer::full_range()
			: DateRange::last_days( Scheduler::DAILY_DAYS, wp_date( 'Y-m-d' ) );

		$state = $this->importer->run( $range, $request->get_param( 'full' ) ? 'full' : 'manual' );
		return $this->respond( $state, ! empty( $state['ok'] ) ? 200 : 500 );
	}

	/**
	 * Dati della Panoramica.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function overview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$range = new DateRange( (string) $request->get_param( 'from' ), (string) $request->get_param( 'to' ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'wp5w_invalid_range', $e->getMessage(), array( 'status' => 400 ) );
		}

		return $this->respond( $this->overview->get( $range ) );
	}
}
