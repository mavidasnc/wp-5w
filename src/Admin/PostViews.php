<?php
/**
 * Visite degli ultimi 30 giorni nell'elenco di articoli e pagine e nella barra di amministrazione.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Admin;

use Mavida\FiveW\Query\PostsQuery;
use Mavida\FiveW\Support\DateRange;

defined( 'ABSPATH' ) || exit;

/**
 * Colonna «Visite (30 g)» (ordinabile) in Articoli e Pagine, e nodo «5W» nella barra di amministrazione sugli articoli e le pagine singoli.
 *
 * Tutto è riservato a chi ha `manage_options` (la capacità della pagina del plugin, da cui si apre il modale di dettaglio).
 * Il controllo sta dentro ogni callback e non alla registrazione, perché l'utente corrente non è ancora noto quando
 * il plugin si inizializza. Le visite sono le visualizzazioni (`object_daily.views`) degli ultimi 30 giorni, compreso oggi.
 */
final class PostViews {

	/**
	 * Chiave della colonna e valore di `orderby`.
	 *
	 * @var string
	 */
	private const COLUMN = 'wp5w_views';

	/**
	 * Giorni del periodo.
	 *
	 * @var int
	 */
	private const DAYS = 30;

	/**
	 * Visualizzazioni per ID dell'elenco in corso, lette con una sola query alla prima cella.
	 *
	 * @var array<int,int>|null
	 */
	private ?array $page_views = null;

	/**
	 * Costruttore.
	 *
	 * @param PostsQuery $posts Interrogazioni dei contenuti.
	 */
	public function __construct( private readonly PostsQuery $posts ) {}

	/**
	 * Aggancia colonna, ordinamento e barra di amministrazione.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
			add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'add_sortable_column' ) );
		}
		add_filter( 'posts_clauses', array( $this, 'order_by_views' ), 10, 2 );

		// La barra di amministrazione c'è anche nel frontend, per questo la registrazione non sta nel ramo is_admin().
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_node' ), 90 );
	}

	/**
	 * Aggiunge la colonna «Visite (30 g)».
	 *
	 * @param array<string,string> $columns Colonne dell'elenco.
	 * @return array<string,string>
	 */
	public function add_column( array $columns ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $columns;
		}

		$columns[ self::COLUMN ] = sprintf(
			'<span title="%s">%s</span>',
			esc_attr__( 'Visualizzazioni degli ultimi 30 giorni. Clic per il dettaglio.', 'wp-5w' ),
			esc_html__( 'Visite (30 g)', 'wp-5w' )
		);
		return $columns;
	}

	/**
	 * Rende la colonna ordinabile (al primo clic dal più visto).
	 *
	 * @param array<string,mixed> $columns Colonne ordinabili.
	 * @return array<string,mixed>
	 */
	public function add_sortable_column( array $columns ): array {
		if ( current_user_can( 'manage_options' ) ) {
			$columns[ self::COLUMN ] = array( self::COLUMN, true );
		}
		return $columns;
	}

	/**
	 * Stampa la cella: un link che apre il modale di dettaglio con il numero di visite.
	 *
	 * La prima cella legge in una sola query le visite di tutte le righe della pagina, le altre usano il risultato.
	 *
	 * @param string $column  Chiave della colonna.
	 * @param int    $post_id ID del contenuto della riga.
	 * @return void
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Passo 1: alla prima cella una sola query per tutti gli ID dell'elenco.
		if ( null === $this->page_views ) {
			$ids              = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts ?? array(), 'ID' ) );
			$this->page_views = $this->posts->views_by_ids( PostsQuery::object_type( (string) get_post_type( $post_id ) ), $ids, self::period() );
		}

		// Passo 2: link al modale di dettaglio.
		printf(
			'<a href="%s">%s</a>',
			esc_url( self::detail_url( $post_id ) ),
			esc_html( number_format_i18n( $this->page_views[ $post_id ] ?? 0 ) )
		);
	}

	/**
	 * Ordina l'elenco per visite quando `orderby=wp5w_views`.
	 *
	 * Agisce solo sulla query principale dell'elenco di articoli o pagine in wp-admin, per chi ha `manage_options`.
	 *
	 * @param array<string,string> $clauses Clausole SQL di WP_Query.
	 * @param \WP_Query            $query   Query.
	 * @return array<string,string>
	 */
	public function order_by_views( array $clauses, \WP_Query $query ): array {
		global $wpdb;

		$post_type = $query->get( 'post_type' );
		if ( ! is_admin() || ! $query->is_main_query() || ! in_array( $post_type, array( 'post', 'page' ), true ) || self::COLUMN !== $query->get( 'orderby' ) || ! current_user_can( 'manage_options' ) ) {
			return $clauses;
		}

		// Solo ASC o DESC: il valore finisce nell'SQL.
		$order  = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';
		$sorter = $this->posts->views_order_clauses( PostsQuery::object_type( (string) $post_type ), self::period() );

		$clauses['join']   .= $sorter['join'];
		$clauses['orderby'] = "{$sorter['field']} {$order}, {$wpdb->posts}.ID {$order}";
		return $clauses;
	}

	/**
	 * Aggiunge «5W: N visite (30 g)» alla barra di amministrazione sugli articoli e le pagine singoli.
	 *
	 * @param \WP_Admin_Bar $bar Barra di amministrazione.
	 * @return void
	 */
	public function add_admin_bar_node( \WP_Admin_Bar $bar ): void {
		if ( is_admin() || ! is_singular( array( 'post', 'page' ) ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Una sola chiamata, con un solo ID.
		$id    = (int) get_queried_object_id();
		$views = $this->posts->views_by_ids( PostsQuery::object_type( (string) get_post_type( $id ) ), array( $id ), self::period() );

		$bar->add_node(
			array(
				'id'    => 'wp5w-views',
				/* translators: %s: visualizzazioni degli ultimi 30 giorni. */
				'title' => esc_html( sprintf( __( '5W: %s visite (30 g)', 'wp-5w' ), number_format_i18n( $views[ $id ] ?? 0 ) ) ),
				'href'  => self::detail_url( $id ),
			)
		);
	}

	/**
	 * Ultimi 30 giorni, compreso oggi.
	 *
	 * @return DateRange
	 */
	private static function period(): DateRange {
		return DateRange::last_days( self::DAYS, wp_date( 'Y-m-d' ) );
	}

	/**
	 * Indirizzo della pagina del plugin con il modale di dettaglio aperto.
	 *
	 * @param int $post_id ID del contenuto.
	 * @return string
	 */
	private static function detail_url( int $post_id ): string {
		return admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG . '&tab=posts&detail=' . $post_id );
	}
}
