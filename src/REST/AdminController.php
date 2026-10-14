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
use Mavida\FiveW\Query\AudienceQuery;
use Mavida\FiveW\Query\PostsQuery;
use Mavida\FiveW\Query\SearchQuery;
use Mavida\FiveW\Query\TrendQuery;
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
	 * @param PostsQuery    $posts    Interrogazioni della scheda Articoli e del dettaglio.
	 * @param SearchQuery   $search   Interrogazioni di Search Console (pagine e opportunità).
	 * @param AudienceQuery $audience Interrogazioni della scheda Visitatori.
	 * @param TrendQuery    $trend    Indicatore di trend del sito e dei contenuti.
	 */
	public function __construct(
		private readonly Importer $importer,
		private readonly OverviewQuery $overview,
		private readonly PostsQuery $posts,
		private readonly SearchQuery $search,
		private readonly AudienceQuery $audience,
		private readonly TrendQuery $trend
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
				'args'                => self::range_args(),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/admin/top',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'top' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => self::range_args() + array(
					'type'  => array(
						'type'    => 'string',
						'enum'    => array_keys( OverviewQuery::TOP_TYPES ),
						'default' => 'all',
					),
					'limit' => array(
						'type'    => 'integer',
						'enum'    => OverviewQuery::TOP_LIMITS,
						'default' => OverviewQuery::TOP_LIMITS[0],
					),
				),
			)
		);

		// Pagine e opportunità di Search Console sugli ultimi N giorni disponibili.
		foreach ( array( 'pages', 'opportunities' ) as $part ) {
			register_rest_route(
				self::API_NAMESPACE,
				'/admin/search/' . $part,
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( \WP_REST_Request $request ) => $this->respond( $this->search_part( $part, (int) $request->get_param( 'days' ) ) ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'days' => array(
							'type'    => 'integer',
							'enum'    => SearchQuery::DAYS,
							'default' => 28,
						),
					),
				)
			);
		}

		register_rest_route(
			self::API_NAMESPACE,
			'/admin/audience',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn() => $this->respond( $this->audience->get() ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/admin/posts/latest',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn() => $this->respond( $this->trend->attach( $this->posts->latest() ) ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/admin/posts/top',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn( \WP_REST_Request $request ) => $this->respond( $this->trend->attach( $this->posts->top( (int) $request->get_param( 'days' ) ) ) ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'days' => array(
						'type'    => 'integer',
						'enum'    => array( 7, 30 ),
						'default' => 7,
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/admin/posts/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'post_detail' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => self::range_args(),
			)
		);
	}

	/**
	 * Parametri from e to (Y-m-d) comuni alle rotte con un periodo.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function range_args(): array {
		$date = array(
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => array( DateRange::class, 'is_valid' ),
		);
		return array(
			'from' => $date,
			'to'   => $date,
		);
	}

	/**
	 * Periodo dai parametri della richiesta.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return DateRange|\WP_Error
	 */
	private static function range_from( \WP_REST_Request $request ): DateRange|\WP_Error {
		try {
			return new DateRange( (string) $request->get_param( 'from' ), (string) $request->get_param( 'to' ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'wp5w_invalid_range', $e->getMessage(), array( 'status' => 400 ) );
		}
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
		$range = self::range_from( $request );
		// Il trend non dipende dal periodo scelto: confronta sempre le ultime 4 settimane complete con le 4 precedenti.
		return $range instanceof DateRange ? $this->respond( $this->overview->get( $range ) + array( 'trend' => $this->trend->site() ) ) : $range;
	}

	/**
	 * Classifica dei contenuti più visti, filtrata per tipo.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function top( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$range = self::range_from( $request );
		if ( ! $range instanceof DateRange ) {
			return $range;
		}
		return $this->respond( $this->trend->attach( $this->overview->top( $range, (string) $request->get_param( 'type' ), (int) $request->get_param( 'limit' ) ) ) );
	}

	/**
	 * Pagine o opportunità di Search Console sugli ultimi giorni disponibili, con il periodo effettivo.
	 *
	 * @param string $part 'pages' o 'opportunities'.
	 * @param int    $days Numero di giorni.
	 * @return array{range:array{from:string,to:string}|null,rows:array<int,array<string,mixed>>}
	 */
	private function search_part( string $part, int $days ): array {
		$range = $this->search->latest_range( $days );
		if ( null === $range ) {
			return array(
				'range' => null,
				'rows'  => array(),
			);
		}
		return array(
			'range' => array(
				'from' => $range->from,
				'to'   => $range->to,
			),
			'rows'  => 'pages' === $part ? $this->search->pages( $range ) : $this->search->opportunities( $range ),
		);
	}

	/**
	 * Dettaglio di un contenuto nel periodo.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_detail( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$range = self::range_from( $request );
		if ( ! $range instanceof DateRange ) {
			return $range;
		}

		$detail = $this->posts->detail( (int) $request->get_param( 'id' ), $range );
		if ( null === $detail ) {
			return new \WP_Error( 'wp5w_not_found', __( 'Contenuto non trovato.', 'wp-5w' ), array( 'status' => 404 ) );
		}
		return $this->respond( $detail );
	}
}
