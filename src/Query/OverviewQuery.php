<?php
/**
 * Dati della Panoramica.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Query;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Settings;
use Mavida\FiveW\Sources\AccessLogSource;
use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\Metrics;
use Mavida\FiveW\Support\Origin;
use Mavida\FiveW\Support\Series;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin, nomi costruiti con $wpdb->prefix in Schema::table().

/**
 * Interroga solo le tabelle wp5w_* (mai quelle delle sorgenti).
 */
final class OverviewQuery {

	/**
	 * Filtri della classifica: nome => condizione SQL su object_type.
	 * "all" esclude home, archivi e 404, che restano nei totali del sito.
	 *
	 * @var array<string,string>
	 */
	public const TOP_TYPES = array(
		'all'        => "o.object_type NOT IN ('home', '404', 'search', 'archive', 'author', 'category', 'post_tag', 'loginpage')",
		'post'       => "o.object_type = 'post'",
		'page'       => "o.object_type = 'page'",
		'attachment' => "o.object_type = 'post_type_attachment'",
	);

	/**
	 * Righe consentite nella classifica.
	 *
	 * @var array<int,int>
	 */
	public const TOP_LIMITS = array( 20, 50, 100 );

	/**
	 * Tutti i dati della Panoramica per l'intervallo.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array<string,mixed>
	 */
	public function get( DateRange $range ): array {
		$previous = $range->previous();
		$current  = $this->totals( $range );
		$before   = $this->totals( $previous );

		$kpis = Metrics::compare( $current, $before );

		return array(
			'range'    => array(
				'from' => $range->from,
				'to'   => $range->to,
			),
			'previous' => array(
				'from' => $previous->from,
				'to'   => $previous->to,
			),
			'kpis'     => $kpis,
			'series'   => $this->series( $range ),
			'tools'    => $this->tools( $range ),
			'channels' => $this->channels( $range ),
			'no_js'    => $this->no_js( $range ),
			'coverage' => $this->coverage(),
		);
	}

	/**
	 * Totali del periodo: traffico da site_daily, Search Console da gsc_daily.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array{visitors:int,views:int,clicks:int,impressions:int,ctr:float|null,position:float|null}
	 */
	private function totals( DateRange $range ): array {
		global $wpdb;

		// Regola dell'origine: solo righe wps, o own dalla data di passaggio.
		$origin = Origin::where( Settings::own_since() );

		$site = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(visitors), 0) AS visitors, COALESCE(SUM(views), 0) AS views FROM ' . Schema::table( 'site_daily' ) . ' WHERE day BETWEEN %s AND %s AND ' . $origin,
				$range->from,
				$range->to
			)
		);
		$gsc  = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(pos_x_impr), 0) AS pos_x_impr FROM ' . Schema::table( 'gsc_daily' ) . ' WHERE day BETWEEN %s AND %s',
				$range->from,
				$range->to
			)
		);

		$clicks      = (int) ( $gsc->clicks ?? 0 );
		$impressions = (int) ( $gsc->impressions ?? 0 );

		return array(
			'visitors'    => (int) ( $site->visitors ?? 0 ),
			'views'       => (int) ( $site->views ?? 0 ),
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'ctr'         => Metrics::ctr( $clicks, $impressions ),
			'position'    => Metrics::weighted_position( (float) ( $gsc->pos_x_impr ?? 0 ), $impressions ),
		);
	}

	/**
	 * Serie giornaliera: visitatori, visualizzazioni, click e impression.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array<int,array{day:string,visitors:int,views:int,clicks:int,impressions:int}>
	 */
	private function series( DateRange $range ): array {
		global $wpdb;

		// Regola dell'origine: solo righe wps, o own dalla data di passaggio.
		$origin = Origin::where( Settings::own_since() );

		$site = $wpdb->get_results(
			$wpdb->prepare( 'SELECT day, visitors, views FROM ' . Schema::table( 'site_daily' ) . ' WHERE day BETWEEN %s AND %s AND ' . $origin, $range->from, $range->to ),
			OBJECT_K
		);
		$gsc  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT day, SUM(clicks) AS clicks, SUM(impressions) AS impressions FROM ' . Schema::table( 'gsc_daily' ) . ' WHERE day BETWEEN %s AND %s GROUP BY day', $range->from, $range->to ),
			OBJECT_K
		);

		// Traffico e Search Console sulla stessa riga del giorno.
		$rows = array();
		foreach ( $site as $day => $row ) {
			$rows[ $day ] = (array) $row;
		}
		foreach ( $gsc as $day => $row ) {
			$rows[ $day ] = ( $rows[ $day ] ?? array() ) + (array) $row;
		}

		return Series::fill( $range, $rows, array( 'visitors', 'views', 'clicks', 'impressions' ) );
	}

	/**
	 * Serie giornaliera di ogni strumento di statistiche (WP Statistics, raccolta propria, Independent Analytics), per le linee del grafico.
	 *
	 * ECCEZIONE ALLA REGOLA DELL'ORIGINE: legge di proposito le tre origini insieme, una per strumento, senza `Origin::where()`.
	 * Il grafico le mostra tutte, e un giorno senza riga di uno strumento (che non c'era ancora) resta `null`, non zero.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array<int,array<string,int|string|null>> Punti { day, visitors_wps, views_wps, visitors_own, views_own, visitors_ia, views_ia }.
	 */
	private function tools( DateRange $range ): array {
		global $wpdb;

		$rows = array();
		$list = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT day, origin, visitors, views FROM ' . Schema::table( 'site_daily' ) . " WHERE day BETWEEN %s AND %s AND origin IN ( 'wps', 'own', 'ia' )",
				$range->from,
				$range->to
			)
		);
		foreach ( (array) $list as $row ) {
			$rows[ $row->day ][ 'visitors_' . $row->origin ] = (int) $row->visitors;
			$rows[ $row->day ][ 'views_' . $row->origin ]    = (int) $row->views;
		}

		$fields = array( 'visitors_wps', 'views_wps', 'visitors_own', 'views_own', 'visitors_ia', 'views_ia' );
		return Series::fill( $range, $rows, $fields, $fields );
	}

	/**
	 * Visitatori per canale (search, direct, social...).
	 *
	 * @param DateRange $range Intervallo.
	 * @return array<int,array{channel:string,visitors:int}>
	 */
	private function channels( DateRange $range ): array {
		global $wpdb;

		// Regola dell'origine: solo righe wps, o own dalla data di passaggio.
		$origin = Origin::where( Settings::own_since() );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT value AS channel, SUM(visitors) AS visitors FROM ' . Schema::table( 'dim_daily' ) . " WHERE dim = 'channel' AND day BETWEEN %s AND %s AND {$origin} GROUP BY value ORDER BY visitors DESC, value",
				$range->from,
				$range->to
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'channel'  => (string) $row['channel'],
				'visitors' => (int) $row['visitors'],
			),
			$rows
		);
	}

	/**
	 * Visitatori senza JavaScript visti solo dai log del server (etichetta `senza-js` dell'origine `logbot`), per la riga sotto i canali.
	 *
	 * ECCEZIONE ALLA REGOLA DELL'ORIGINE: legge le righe `logbot` e `log`, che `Origin::where()` non seleziona mai. Sono un'altra fonte
	 * (il tracker è JavaScript, quindi questi visitatori non arrivano né alla raccolta propria né a WP Statistics): il numero resta
	 * fuori dalle quote dei canali e porta con sé i giorni del periodo coperti dai log.
	 *
	 * @param DateRange $range Intervallo.
	 * @return array{visitors:int,days:int,period_days:int}|null Null se i log non coprono nessun giorno del periodo.
	 */
	private function no_js( DateRange $range ): ?array {
		global $wpdb;

		// Passo 1: giorni coperti dai log (AccessLogSource scrive site_daily per ogni giorno completo, anche senza bot) e visitatori senza JS.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ( SELECT COUNT(DISTINCT day) FROM ' . Schema::table( 'site_daily' ) . ' WHERE origin IN ( %s, %s ) AND day BETWEEN %s AND %s ) AS days,
					( SELECT COALESCE( SUM(visitors), 0 ) FROM ' . Schema::table( 'dim_daily' ) . " WHERE dim = 'bot' AND value = %s AND origin = %s AND day BETWEEN %s AND %s ) AS visitors",
				AccessLogSource::ORIGIN,
				AccessLogSource::ORIGIN_BOT,
				$range->from,
				$range->to,
				AccessLogSource::LABEL_NO_JS,
				AccessLogSource::ORIGIN_BOT,
				$range->from,
				$range->to
			),
			ARRAY_A
		);

		// Passo 2: senza giorni coperti la riga non si mostra.
		$days = (int) ( $row['days'] ?? 0 );
		if ( 0 === $days ) {
			return null;
		}

		return array(
			'visitors'    => (int) $row['visitors'],
			'days'        => $days,
			'period_days' => $range->days(),
		);
	}

	/**
	 * Contenuti più visti, con ingressi da ricerca e dati Search Console.
	 *
	 * @param DateRange $range Intervallo.
	 * @param string    $type  Filtro (chiave di TOP_TYPES).
	 * @param int       $limit Righe (uno dei TOP_LIMITS).
	 * @return array<int,array<string,mixed>>
	 */
	public function top( DateRange $range, string $type = 'all', int $limit = 20 ): array {
		global $wpdb;

		$object = Schema::table( 'object_daily' );
		$entry  = Schema::table( 'entry_daily' );
		$gsc    = Schema::table( 'gsc_daily' );
		$filter = self::TOP_TYPES[ $type ] ?? self::TOP_TYPES['all'];
		$limit  = in_array( $limit, self::TOP_LIMITS, true ) ? $limit : self::TOP_LIMITS[0];

		// Regola dell'origine, con l'alias della tabella: o per object_daily, e per entry_daily nelle due subquery.
		$own_since = Settings::own_since();
		$origin_o  = Origin::where( $own_since, 'o' );
		$origin_e  = Origin::where( $own_since, 'e' );

		// Solo contenuti veri (id > 0).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.object_type, o.object_id, MIN(o.uri) AS uri, SUM(o.views) AS views,
					( SELECT COALESCE(SUM(e.visitors), 0) FROM {$entry} e WHERE e.object_type = o.object_type AND e.object_id = o.object_id AND e.channel = 'search' AND e.day BETWEEN %s AND %s AND {$origin_e} ) AS search_entries,
					( SELECT COALESCE(SUM(e.visitors), 0) FROM {$entry} e WHERE e.object_type = o.object_type AND e.object_id = o.object_id AND e.day BETWEEN %s AND %s AND {$origin_e} ) AS entries
				FROM {$object} o
				WHERE o.day BETWEEN %s AND %s AND o.object_id > 0 AND {$filter} AND {$origin_o}
				GROUP BY o.object_type, o.object_id
				ORDER BY views DESC
				LIMIT %d",
				$range->from,
				$range->to,
				$range->from,
				$range->to,
				$range->from,
				$range->to,
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		// Search Console per gli stessi contenuti, in una sola query.
		$ids          = array_map( 'intval', wp_list_pluck( $rows, 'object_id' ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$gsc_rows     = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Segnaposto %d generati per ogni id.
				"SELECT object_id, SUM(clicks) AS clicks, SUM(impressions) AS impressions, SUM(pos_x_impr) AS pos_x_impr
				FROM {$gsc} WHERE day BETWEEN %s AND %s AND object_id IN ({$placeholders}) GROUP BY object_id",
				array_merge( array( $range->from, $range->to ), $ids )
			),
			OBJECT_K
		);

		return array_map(
			static function ( array $row ) use ( $gsc_rows ): array {
				$id          = (int) $row['object_id'];
				$clicks      = isset( $gsc_rows[ $id ] ) ? (int) $gsc_rows[ $id ]->clicks : 0;
				$impressions = isset( $gsc_rows[ $id ] ) ? (int) $gsc_rows[ $id ]->impressions : 0;
				$title       = get_the_title( $id );

				return array(
					'id'             => $id,
					'type'           => (string) $row['object_type'],
					'title'          => '' !== $title ? html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) : (string) $row['uri'],
					'url'            => (string) ( get_permalink( $id ) ? get_permalink( $id ) : home_url( (string) $row['uri'] ) ),
					'edit_url'       => (string) get_edit_post_link( $id, 'raw' ),
					'views'          => (int) $row['views'],
					'entries'        => (int) $row['entries'],
					'search_entries' => (int) $row['search_entries'],
					'clicks'         => $clicks,
					'impressions'    => $impressions,
					'ctr'            => Metrics::ctr( $clicks, $impressions ),
					'position'       => isset( $gsc_rows[ $id ] ) ? Metrics::weighted_position( (float) $gsc_rows[ $id ]->pos_x_impr, $impressions ) : null,
				);
			},
			$rows
		);
	}

	/**
	 * Periodo coperto da ciascuna tabella, per spiegare i buchi nei grafici.
	 *
	 * @return array<string,array{from:string|null,to:string|null,rows:int}>
	 */
	public function coverage(): array {
		global $wpdb;

		$own_since = Settings::own_since();
		$coverage  = array();
		foreach ( Schema::TABLES as $name ) {
			// Le cinque tabelle aggregate seguono la regola dell'origine, gsc_daily no (la sua origin ha un altro significato).
			$where             = in_array( $name, Origin::TABLES, true ) ? ' WHERE ' . Origin::where( $own_since ) : '';
			$row               = $wpdb->get_row( 'SELECT MIN(day) AS d_from, MAX(day) AS d_to, COUNT(*) AS n FROM ' . Schema::table( $name ) . $where );
			$coverage[ $name ] = array(
				'from' => $row->d_from ?? null,
				'to'   => $row->d_to ?? null,
				'rows' => (int) ( $row->n ?? 0 ),
			);
		}
		return $coverage;
	}
}
