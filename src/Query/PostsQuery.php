<?php
/**
 * Dati della scheda Articoli e del dettaglio di un contenuto.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Query;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\Metrics;
use Mavida\FiveW\Support\Series;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin, nomi costruiti con $wpdb->prefix in Schema::table().

/**
 * Elenchi di articoli con la serie dell'ultima settimana e dettaglio di un singolo contenuto.
 *
 * I visitatori sommati su più giorni contano una persona una volta per ogni giorno di visita.
 */
final class PostsQuery {

	/**
	 * Articoli per elenco.
	 *
	 * @var int
	 */
	private const LIMIT = 10;

	/**
	 * Giorni del grafico su ogni riga.
	 *
	 * @var int
	 */
	private const SPARK_DAYS = 7;

	/**
	 * Ultimi articoli pubblicati, con serie della settimana e totali dalla pubblicazione.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function latest(): array {
		$ids = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'numberposts' => self::LIMIT,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'fields'      => 'ids',
			)
		);
		if ( ! $ids ) {
			return array();
		}

		$ids    = array_map( 'intval', $ids );
		$week   = DateRange::last_days( self::SPARK_DAYS, wp_date( 'Y-m-d' ) );
		$series = $this->daily( $ids, $week );
		$weekly = $this->totals( $ids, $week );
		$first  = $this->first_day();
		$zero   = array(
			'views'    => 0,
			'visitors' => 0,
		);

		return array_map(
			function ( int $id ) use ( $week, $series, $weekly, $first, $zero ): array {
				// Il totale parte dalla pubblicazione, ma non prima del primo giorno con dati di dettaglio.
				$published = (string) get_the_date( 'Y-m-d', $id );
				$since     = null !== $first && $first > $published ? $first : $published;
				$total     = $since <= $week->to ? $this->totals( array( $id ), new DateRange( $since, $week->to ) ) : array();

				return $this->row( $id, $week, $series[ $id ] ?? array() ) + array(
					'week'  => $weekly[ $id ] ?? $zero,
					'total' => $total[ $id ] ?? $zero,
					'since' => $since,
				);
			},
			$ids
		);
	}

	/**
	 * Articoli più visti negli ultimi giorni, con serie della settimana e totali del periodo.
	 *
	 * @param int $days Giorni del periodo (7 o 30).
	 * @return array<int,array<string,mixed>>
	 */
	public function top( int $days ): array {
		global $wpdb;

		$period = DateRange::last_days( $days, wp_date( 'Y-m-d' ) );
		$ids    = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT object_id FROM ' . Schema::table( 'object_daily' ) . "
					WHERE object_type = 'post' AND object_id > 0 AND day BETWEEN %s AND %s
					GROUP BY object_id ORDER BY SUM(views) DESC LIMIT %d",
					$period->from,
					$period->to,
					self::LIMIT
				)
			)
		);
		if ( ! $ids ) {
			return array();
		}

		$week   = DateRange::last_days( self::SPARK_DAYS, wp_date( 'Y-m-d' ) );
		$series = $this->daily( $ids, $week );
		$totals = $this->totals( $ids, $period );

		return array_map(
			fn( int $id ): array => $this->row( $id, $week, $series[ $id ] ?? array() ) + array( 'period' => $totals[ $id ] ),
			$ids
		);
	}

	/**
	 * Dettaglio di un contenuto: KPI, serie, query di Search Console, referrer e canali.
	 *
	 * @param int       $id    ID del post.
	 * @param DateRange $range Intervallo.
	 * @return array<string,mixed>|null Null se il post non esiste.
	 */
	public function detail( int $id, DateRange $range ): ?array {
		global $wpdb;

		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}

		// Prima del primo giorno di dettaglio non ci sono dati: si accorcia il periodo (es. "dalla pubblicazione" di un post del 2005).
		$first = $this->first_day();
		if ( null !== $first && $first > $range->from && $first <= $range->to ) {
			$range = new DateRange( $first, $range->to );
		}

		$type     = self::object_type( $post->post_type );
		$object   = Schema::table( 'object_daily' );
		$entry    = Schema::table( 'entry_daily' );
		$gsc      = Schema::table( 'gsc_daily' );
		$referrer = Schema::table( 'referrer_daily' );

		// Visualizzazioni e visitatori per giorno.
		$traffic = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, SUM(views) AS views, SUM(visitors) AS visitors FROM {$object}
				WHERE object_type = %s AND object_id = %d AND day BETWEEN %s AND %s GROUP BY day",
				$type,
				$id,
				$range->from,
				$range->to
			),
			OBJECT_K
		);

		// Search Console per giorno.
		$search = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, SUM(clicks) AS clicks, SUM(impressions) AS impressions, SUM(pos_x_impr) AS pos_x_impr FROM {$gsc}
				WHERE object_id = %d AND day BETWEEN %s AND %s GROUP BY day",
				$id,
				$range->from,
				$range->to
			),
			OBJECT_K
		);

		$rows = array();
		foreach ( $traffic as $day => $row ) {
			$rows[ $day ] = (array) $row;
		}
		foreach ( $search as $day => $row ) {
			$rows[ $day ] = ( $rows[ $day ] ?? array() ) + (array) $row;
		}

		// Ingressi per canale.
		$channels = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT channel, SUM(visitors) AS visitors FROM {$entry}
				WHERE object_type = %s AND object_id = %d AND day BETWEEN %s AND %s
				GROUP BY channel ORDER BY visitors DESC",
				$type,
				$id,
				$range->from,
				$range->to
			),
			ARRAY_A
		);

		// Query di ricerca: le più cliccate, poi le più viste.
		$queries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT MIN(query) AS query, SUM(clicks) AS clicks, SUM(impressions) AS impressions, SUM(pos_x_impr) AS pos_x_impr FROM {$gsc}
				WHERE object_id = %d AND day BETWEEN %s AND %s
				GROUP BY query_hash ORDER BY clicks DESC, impressions DESC LIMIT 50",
				$id,
				$range->from,
				$range->to
			),
			ARRAY_A
		);

		// Siti di provenienza di chi è entrato da questo contenuto.
		$referrers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer, MAX(channel) AS channel, MAX(source) AS source, SUM(visitors) AS visitors FROM {$referrer}
				WHERE object_type = %s AND object_id = %d AND day BETWEEN %s AND %s
				GROUP BY referrer ORDER BY visitors DESC LIMIT 30",
				$type,
				$id,
				$range->from,
				$range->to
			),
			ARRAY_A
		);

		$sum = static fn( array $items, string $field ): int => (int) array_sum( array_map( static fn( $item ) => (int) $item->$field, $items ) );

		$clicks      = $sum( $search, 'clicks' );
		$impressions = $sum( $search, 'impressions' );
		$pos_x_impr  = (float) array_sum( array_map( static fn( $item ) => (float) $item->pos_x_impr, $search ) );
		$entries     = (int) array_sum( array_column( $channels, 'visitors' ) );

		return array(
			'post'      => $this->post_info( $id ),
			'range'     => array(
				'from' => $range->from,
				'to'   => $range->to,
			),
			'kpis'      => array(
				'views'          => $sum( $traffic, 'views' ),
				'visitors'       => $sum( $traffic, 'visitors' ),
				'entries'        => $entries,
				'search_entries' => (int) array_sum( array_column( array_filter( $channels, static fn( $row ) => 'search' === $row['channel'] ), 'visitors' ) ),
				'clicks'         => $clicks,
				'impressions'    => $impressions,
				'ctr'            => Metrics::ctr( $clicks, $impressions ),
				'position'       => Metrics::weighted_position( $pos_x_impr, $impressions ),
			),
			'series'    => Series::fill( $range, $rows, array( 'views', 'visitors', 'clicks', 'impressions' ) ),
			'channels'  => array_map(
				static fn( array $row ): array => array(
					'channel'  => (string) $row['channel'],
					'visitors' => (int) $row['visitors'],
				),
				$channels
			),
			'queries'   => array_map(
				static fn( array $row ): array => array(
					'query'       => (string) $row['query'],
					'clicks'      => (int) $row['clicks'],
					'impressions' => (int) $row['impressions'],
					'ctr'         => Metrics::ctr( (int) $row['clicks'], (int) $row['impressions'] ),
					'position'    => Metrics::weighted_position( (float) $row['pos_x_impr'], (int) $row['impressions'] ),
				),
				$queries
			),
			'referrers' => array_map(
				static fn( array $row ): array => array(
					'referrer' => (string) $row['referrer'],
					'channel'  => (string) $row['channel'],
					'source'   => (string) $row['source'],
					'visitors' => (int) $row['visitors'],
				),
				$referrers
			),
		);
	}

	/**
	 * Tipo di contenuto come lo registra WP Statistics (post, page, post_type_attachment...).
	 *
	 * @param string $post_type Tipo di post WordPress.
	 * @return string
	 */
	public static function object_type( string $post_type ): string {
		return in_array( $post_type, array( 'post', 'page' ), true ) ? $post_type : 'post_type_' . $post_type;
	}

	/**
	 * Riga di un elenco: dati dell'articolo e serie della settimana.
	 *
	 * @param int                             $id     ID del post.
	 * @param DateRange                       $week   Ultimi 7 giorni.
	 * @param array<string,array<string,int>> $daily  Righe per giorno dell'articolo.
	 * @return array<string,mixed>
	 */
	private function row( int $id, DateRange $week, array $daily ): array {
		return $this->post_info( $id ) + array( 'spark' => Series::fill( $week, $daily, array( 'views', 'visitors' ) ) );
	}

	/**
	 * Titolo, data e collegamenti di un post.
	 *
	 * @param int $id ID del post.
	 * @return array{id:int,title:string,date:string,url:string,edit_url:string}
	 */
	private function post_info( int $id ): array {
		return array(
			'id'       => $id,
			'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'date'     => (string) get_the_date( 'Y-m-d', $id ),
			'url'      => (string) get_permalink( $id ),
			'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
		);
	}

	/**
	 * Visualizzazioni e visitatori per articolo e giorno.
	 *
	 * @param array<int,int> $ids   ID degli articoli.
	 * @param DateRange      $range Intervallo.
	 * @return array<int,array<string,array<string,int>>> ID => giorno => { views, visitors }.
	 */
	private function daily( array $ids, DateRange $range ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Segnaposto %d generati per ogni id.
				'SELECT object_id, day, SUM(views) AS views, SUM(visitors) AS visitors FROM ' . Schema::table( 'object_daily' ) . "
				WHERE object_type = 'post' AND object_id IN ({$placeholders}) AND day BETWEEN %s AND %s
				GROUP BY object_id, day",
				array_merge( $ids, array( $range->from, $range->to ) )
			),
			ARRAY_A
		);

		$daily = array();
		foreach ( $rows as $row ) {
			$daily[ (int) $row['object_id'] ][ $row['day'] ] = array(
				'views'    => (int) $row['views'],
				'visitors' => (int) $row['visitors'],
			);
		}
		return $daily;
	}

	/**
	 * Totali per articolo nell'intervallo.
	 *
	 * @param array<int,int> $ids   ID degli articoli.
	 * @param DateRange      $range Intervallo.
	 * @return array<int,array{views:int,visitors:int}>
	 */
	private function totals( array $ids, DateRange $range ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Segnaposto %d generati per ogni id.
				'SELECT object_id, SUM(views) AS views, SUM(visitors) AS visitors FROM ' . Schema::table( 'object_daily' ) . "
				WHERE object_type = 'post' AND object_id IN ({$placeholders}) AND day BETWEEN %s AND %s
				GROUP BY object_id",
				array_merge( $ids, array( $range->from, $range->to ) )
			),
			ARRAY_A
		);

		$totals = array();
		foreach ( $rows as $row ) {
			$totals[ (int) $row['object_id'] ] = array(
				'views'    => (int) $row['views'],
				'visitors' => (int) $row['visitors'],
			);
		}
		return $totals;
	}

	/**
	 * Primo giorno con dati di dettaglio per contenuto.
	 *
	 * @return string|null
	 */
	private function first_day(): ?string {
		global $wpdb;
		$day = $wpdb->get_var( 'SELECT MIN(day) FROM ' . Schema::table( 'object_daily' ) );
		return $day ? (string) $day : null;
	}
}
