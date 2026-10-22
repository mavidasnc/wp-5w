<?php
/**
 * Sorgente Independent Analytics (tabelle independent_analytics_*).
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Sources;

use Mavida\FiveW\Support\Channels;
use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\LocalTime;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle di un altro plugin e del plugin, nomi costruiti con $wpdb->prefix.

/**
 * Trasforma le visite di Independent Analytics (una riga per visita, orari in UTC) nelle righe `origin = 'ia'`
 * di `site_daily`, `object_daily`, `entry_daily` e della sola dimensione `channel` di `dim_daily`.
 *
 * Le righe `ia` servono solo al Confronto: `Origin::where()` non le seleziona mai, quindi nessun'altra schermata le vede.
 * Ogni tabella passa da `replace_by_month()` con la condizione `origin = 'ia'`: le righe `wps` e `own` non si toccano.
 *
 * Differenze di raccolta rispetto alle altre due origini (sono il motivo del Confronto): IA scarta i bot invece di segnalarli,
 * non traccia gli utenti collegati, non rispetta DNT, legge l'IP anche da X-Forwarded-For e una sessione dura 30 minuti.
 */
final class IndependentAnalyticsSource extends Source {

	/**
	 * Prefisso delle tabelle di IA (dopo il prefisso di WordPress).
	 *
	 * @var string
	 */
	private const PREFIX = 'independent_analytics_';

	/**
	 * Tipi di referrer di IA (`referrer_types.referrer_type`) tradotti nel canale di WP Statistics da Channels::from_iawp().
	 *
	 * @var array<int,string>
	 */
	private const REFERRER_TYPES = array( 'Direct', 'Search', 'Social', 'Referrer', 'AI' );

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'ia';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Independent Analytics', 'wp-5w' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function version(): ?string {
		return defined( 'IAWP_VERSION' ) ? (string) constant( 'IAWP_VERSION' ) : null;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function required(): array {
		return array(
			self::PREFIX . 'views'          => array( 'id', 'resource_id', 'viewed_at', 'session_id' ),
			self::PREFIX . 'sessions'       => array( 'session_id', 'visitor_id', 'created_at', 'initial_view_id', 'referrer_id' ),
			self::PREFIX . 'resources'      => array( 'id', 'resource', 'singular_id', 'term_id', 'author_id', 'cached_url' ),
			self::PREFIX . 'referrers'      => array( 'id', 'domain', 'referrer_type_id' ),
			self::PREFIX . 'referrer_types' => array( 'id', 'referrer_type' ),
		);
	}

	/**
	 * Aggrega le visite dell'intervallo nelle righe ia.
	 *
	 * @param DateRange $range Intervallo richiesto.
	 * @return array<string,int> Chiavi con prefisso "ia:", per non sovrascrivere quelle delle altre sorgenti in Importer::run().
	 * @throws \RuntimeException Se una query fallisce.
	 */
	public function import( DateRange $range ): array {
		$views     = $this->table( self::PREFIX . 'views' );
		$sessions  = $this->table( self::PREFIX . 'sessions' );
		$resources = $this->table( self::PREFIX . 'resources' );
		$referrers = $this->table( self::PREFIX . 'referrers' );
		$types     = $this->table( self::PREFIX . 'referrer_types' );

		// Passo 1: giorno del sito di ogni orario UTC (ora legale compresa, senza tabelle dei fusi in MariaDB).
		$transitions = wp_timezone()->getTransitions( (int) strtotime( '2020-01-01 00:00:00 UTC' ), time() + YEAR_IN_SECONDS );
		$view_day    = 'DATE( ' . LocalTime::shift_sql( 'v.viewed_at', $transitions ) . ' )';
		$session_day = 'DATE( ' . LocalTime::shift_sql( 's.created_at', $transitions ) . ' )';

		// Passo 2: intervallo con dati, in giorni del sito (stessa conversione di sopra, sull'intera tabella).
		$available = $this->data_range( self::PREFIX . 'views', 'DATE( ' . LocalTime::shift_sql( 'viewed_at', $transitions ) . ' )' );

		// Passo 3: tipo e ID del contenuto con gli stessi valori di Tracker::current_object(), e canale come nelle altre origini.
		$object = $this->object_joins();
		$type   = $this->object_type_sql();
		$id     = $this->object_id_sql();
		$ai     = Channels::ai_sql( Channels::host_sql( 'ref.domain' ) );
		$cases  = array();
		foreach ( self::REFERRER_TYPES as $referrer_type ) {
			$cases[] = "WHEN rt.referrer_type = '{$referrer_type}' THEN '" . Channels::from_iawp( $referrer_type ) . "'";
		}
		$channel = "IF( {$ai} <> '', 'ai', CASE " . implode( ' ', $cases ) . " ELSE 'unassigned' END )";
		$delete  = " AND origin = 'ia'";
		$rows    = array();

		// Totali del sito: visitatori distinti e visualizzazioni per giorno.
		$rows['ia:site_daily'] = $this->replace_by_month(
			'site_daily',
			$range,
			$available,
			"SELECT {$view_day} AS day, COUNT(DISTINCT s.visitor_id), COUNT(*), 'ia' FROM {$views} v
			JOIN {$sessions} s ON s.session_id = v.session_id
			WHERE {$view_day} BETWEEN %s AND %s GROUP BY day",
			'day, visitors, views, origin',
			$delete
		);

		// Contenuti: visualizzazioni e visitatori distinti per contenuto e giorno. Le pagine virtuali non hanno un tipo e si saltano.
		$rows['ia:object_daily'] = $this->replace_by_month(
			'object_daily',
			$range,
			$available,
			"SELECT day, object_type, object_id, MIN(uri), COUNT(*), COUNT(DISTINCT visitor), 'ia' FROM (
				SELECT {$view_day} AS day, {$type} AS object_type, {$id} AS object_id,
					LEFT( COALESCE( NULLIF( SUBSTRING( r.cached_url, LOCATE( '/', r.cached_url, 9 ) ), '' ), '/' ), 190 ) AS uri, s.visitor_id AS visitor
				FROM {$views} v
				JOIN {$sessions} s ON s.session_id = v.session_id
				JOIN {$resources} r ON r.id = v.resource_id
				{$object}
				WHERE {$view_day} BETWEEN %s AND %s
			) x WHERE object_type IS NOT NULL GROUP BY day, object_type, object_id",
			'day, object_type, object_id, uri, views, visitors, origin',
			$delete
		);

		// Ingressi per canale: una sessione entra dal contenuto della sua prima visita.
		$rows['ia:entry_daily'] = $this->replace_by_month(
			'entry_daily',
			$range,
			$available,
			"SELECT day, object_type, object_id, channel, COUNT(DISTINCT visitor), 'ia' FROM (
				SELECT {$session_day} AS day, {$type} AS object_type, {$id} AS object_id, {$channel} AS channel, s.visitor_id AS visitor
				FROM {$sessions} s
				JOIN {$views} iv ON iv.id = s.initial_view_id
				JOIN {$resources} r ON r.id = iv.resource_id
				LEFT JOIN {$referrers} ref ON ref.id = s.referrer_id
				LEFT JOIN {$types} rt ON rt.id = ref.referrer_type_id
				{$object}
				WHERE {$session_day} BETWEEN %s AND %s
			) x WHERE object_type IS NOT NULL GROUP BY day, object_type, object_id, channel",
			'day, object_type, object_id, channel, visitors, origin',
			$delete
		);

		// Canale: un visitatore per giorno, con la prima sessione del giorno (come le altre origini, così la somma è il numero dei visitatori).
		$rows['ia:dim_daily'] = $this->replace_by_month(
			'dim_daily',
			$range,
			$available,
			"SELECT f.day, 'channel', {$channel}, COUNT(*), 'ia' FROM (
				SELECT {$session_day} AS day, s.visitor_id, MIN(s.session_id) AS session_id FROM {$sessions} s
				WHERE {$session_day} BETWEEN %s AND %s GROUP BY day, s.visitor_id
			) f
			JOIN {$sessions} s ON s.session_id = f.session_id
			LEFT JOIN {$referrers} ref ON ref.id = s.referrer_id
			LEFT JOIN {$types} rt ON rt.id = ref.referrer_type_id
			GROUP BY f.day, 3",
			'day, dim, value, visitors, origin',
			$delete . " AND dim = 'channel'"
		);

		return $rows;
	}

	/**
	 * Join che servono a tipo e ID del contenuto: l'articolo (per il tipo di contenuto) e la tassonomia del termine.
	 *
	 * @return string
	 */
	private function object_joins(): string {
		global $wpdb;

		return "LEFT JOIN {$wpdb->posts} p ON r.resource = 'singular' AND p.ID = r.singular_id
			LEFT JOIN {$wpdb->term_taxonomy} tt ON r.resource = 'term_archive' AND tt.term_id = r.term_id";
	}

	/**
	 * `object_type` di Tracker::current_object() per una risorsa di IA; NULL se non ha un equivalente (pagine virtuali).
	 *
	 * @return string
	 */
	private function object_type_sql(): string {
		$front = $this->front_page_id();

		return "CASE r.resource
			WHEN 'singular' THEN CASE
				WHEN r.singular_id = {$front} THEN 'home'
				WHEN p.post_type IN ( 'post', 'page' ) THEN p.post_type
				ELSE LEFT( CONCAT( 'post_type_', p.post_type ), 40 ) END
			WHEN 'home' THEN 'home'
			WHEN 'term_archive' THEN CASE tt.taxonomy
				WHEN 'category' THEN 'category'
				WHEN 'post_tag' THEN 'post_tag'
				ELSE LEFT( CONCAT( 'tax_', tt.taxonomy ), 40 ) END
			WHEN 'author_archive' THEN 'author'
			WHEN 'date_archive' THEN 'archive'
			WHEN 'post_type_archive' THEN 'archive'
			WHEN 'search' THEN 'search'
			WHEN '404' THEN '404'
			END";
	}

	/**
	 * `object_id` di Tracker::current_object() (get_queried_object_id()): 0 per ricerche, 404 e archivi per data o tipo.
	 *
	 * @return string
	 */
	private function object_id_sql(): string {
		$posts_page = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;

		return "CASE r.resource
			WHEN 'singular' THEN r.singular_id
			WHEN 'home' THEN {$posts_page}
			WHEN 'term_archive' THEN r.term_id
			WHEN 'author_archive' THEN r.author_id
			ELSE 0 END";
	}

	/**
	 * ID della pagina statica impostata come home (0 con «Gli ultimi articoli»): per WP Statistics e per il Tracker
	 * quella pagina è la `home`, non una `page`.
	 *
	 * @return int
	 */
	private function front_page_id(): int {
		return 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
	}
}
