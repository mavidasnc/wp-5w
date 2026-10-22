<?php
/**
 * Bootstrap del plugin.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW;

use Mavida\FiveW\Admin\AdminMenu;
use Mavida\FiveW\Admin\PostViews;
use Mavida\FiveW\Cli\Command;
use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Etl\Importer;
use Mavida\FiveW\Etl\Scheduler;
use Mavida\FiveW\Front\Tracker;
use Mavida\FiveW\Gsc\OAuthHandler;
use Mavida\FiveW\Query\CompareQuery;
use Mavida\FiveW\Query\OnlineQuery;
use Mavida\FiveW\Query\OperationsQuery;
use Mavida\FiveW\Query\OverviewQuery;
use Mavida\FiveW\Query\AudienceQuery;
use Mavida\FiveW\Query\PostsQuery;
use Mavida\FiveW\Query\SearchQuery;
use Mavida\FiveW\Query\TrendQuery;
use Mavida\FiveW\REST\AdminController;
use Mavida\FiveW\REST\GscController;
use Mavida\FiveW\REST\HitController;
use Mavida\FiveW\REST\RestController;
use Mavida\FiveW\REST\UpdateController;
use Mavida\FiveW\Sources\IndependentAnalyticsSource;
use Mavida\FiveW\Sources\OwnSource;
use Mavida\FiveW\Sources\RankMathSource;
use Mavida\FiveW\Sources\WpStatisticsSource;
use Mavida\FiveW\Support\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton che collega i componenti del plugin.
 */
final class Plugin {

	/**
	 * Istanza unica.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Evita doppie inizializzazioni.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Costruttore privato (singleton).
	 */
	private function __construct() {}

	/**
	 * Restituisce l'istanza unica.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Importer con le sorgenti supportate (usato anche dall'Activator e dalla CLI).
	 *
	 * @return Importer
	 */
	public static function importer(): Importer {
		return new Importer( array( new WpStatisticsSource(), new RankMathSource(), new OwnSource(), new IndependentAnalyticsSource() ) );
	}

	/**
	 * Registra i componenti.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Aggiornamenti automatici dalle release GitHub.
		Updater::init();

		// Traduzioni: da WP 6.7 vanno caricate non prima di init.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Aggiorna lo schema del database se la versione è cambiata.
		Schema::register();

		// Impostazioni della raccolta propria (register_setting su init).
		Settings::register();

		$importer = self::importer();

		// ETL giornaliero e import iniziale in background.
		( new Scheduler( $importer ) )->register();

		// Le risposte REST del plugin non devono mai finire nelle cache di pagina.
		add_filter( 'rest_post_dispatch', array( RestController::class, 'no_cache' ), 10, 3 );
		$search = new SearchQuery();
		$posts  = new PostsQuery();
		( new UpdateController() )->register();
		( new GscController() )->register();
		( new AdminController( $importer, new OverviewQuery(), $posts, $search, new AudienceQuery( $search ), new TrendQuery(), new CompareQuery(), new OnlineQuery(), new OperationsQuery() ) )->register();

		// Visite degli ultimi 30 giorni nell'elenco di articoli e pagine e nella barra di amministrazione (anche nel frontend).
		( new PostViews( $posts ) )->register();

		// Raccolta propria: endpoint pubblico delle visite e tracker nel frontend.
		( new HitController() )->register();
		if ( ! is_admin() ) {
			( new Tracker() )->register();
		}

		if ( is_admin() ) {
			( new AdminMenu() )->register();
			// Partenza e ritorno del collegamento OAuth a Search Console (admin-post.php conta come admin).
			( new OAuthHandler() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( '5w', new Command( $importer ) );
		}
	}

	/**
	 * Carica il text domain.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wp-5w', false, dirname( plugin_basename( WP5W_PLUGIN_FILE ) ) . '/languages' );
	}
}
