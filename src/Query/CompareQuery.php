<?php
/**
 * Dati della sezione Confronto della scheda Stato.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Query;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Etl\Importer;
use Mavida\FiveW\Settings;
use Mavida\FiveW\Support\Comparison;
use Mavida\FiveW\Support\Metrics;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Tabelle del plugin, nomi costruiti con $wpdb->prefix in Schema::table(); segnaposto generati per ogni giorno.

/**
 * Confronta la raccolta propria (`own`) con WP Statistics (`wps`) sui giorni completi.
 *
 * ECCEZIONE ALLA REGOLA DELL'ORIGINE: è l'unica classe di src/Query/ che legge le tabelle aggregate
 * senza Origin::where(), perché confrontare le due origini è il suo scopo. Legge solo tabelle wp5w_*
 * e non scrive nulla. Le regole (finestra, soglia, quote, classifica) sono in Support\Comparison.
 */
final class CompareQuery {

	/**
	 * Dati del confronto per la rotta /admin/compare.
	 *
	 * @return array<string,mixed>
	 */
	public function get(): array {
		global $wpdb;

		$own_first = Settings::own_first_day();

		// Passo 1: stato dell'ultimo ETL (anche con errori: li segnala etl_ok, senza togliere giorni).
		$last = Importer::last_state();
		$etl  = null;
		if ( is_array( $last ) && isset( $last['started'], $last['to'] ) ) {
			$etl = array(
				'day' => wp_date( 'Y-m-d', (int) $last['started'] ),
				'to'  => (string) $last['to'],
			);
		}

		// Passo 2: senza righe `own` non c'è nulla da mostrare.
		if ( null === $own_first ) {
			return array(
				'available' => false,
				'coverage'  => $this->coverage( Comparison::plan( null, $etl, wp_date( 'Y-m-d' ), array(), array() ), null, $etl, $last ),
			);
		}

		// Passo 3: totali del sito per giorno, per origine, dal primo giorno `own` in poi.
		$site = array();
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT day, origin, visitors, views FROM ' . Schema::table( 'site_daily' ) . ' WHERE day >= %s', $own_first ),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$site[ (string) $row['origin'] ][ (string) $row['day'] ] = array(
				'visitors' => (int) $row['visitors'],
				'views'    => (int) $row['views'],
			);
		}

		// Passo 4: finestra e copertura.
		$plan = Comparison::plan(
			$own_first,
			$etl,
			wp_date( 'Y-m-d' ),
			array_keys( $site['wps'] ?? array() ),
			array_keys( $site['own'] ?? array() )
		);
		$days = $plan['days'];

		// Passo 5: letture sui soli giorni completi (con nessun giorno non si interroga: IN () sarebbe un errore SQL).
		$home_direct = $this->home_direct( $days );
		$discards    = $this->discards( $days );

		// Passo 6: una riga per giorno e i totali.
		$day_rows = array();
		$totals   = array(
			'wps' => array(
				'visitors' => 0,
				'views'    => 0,
			),
			'own' => array(
				'visitors' => 0,
				'views'    => 0,
			),
		);
		foreach ( $days as $day ) {
			$wps = $site['wps'][ $day ];
			$own = $site['own'][ $day ];
			foreach ( array( 'visitors', 'views' ) as $key ) {
				$totals['wps'][ $key ] += $wps[ $key ];
				$totals['own'][ $key ] += $own[ $key ];
			}

			$diff       = array(
				'visitors' => Metrics::change( $own['visitors'], $wps['visitors'] ),
				'views'    => Metrics::change( $own['views'], $wps['views'] ),
			);
			$day_rows[] = array(
				'day'         => $day,
				'wps'         => $wps,
				'own'         => $own,
				'diff'        => $diff,
				'flagged'     => Comparison::flagged( $diff['visitors'], $diff['views'] ),
				'home_direct' => array(
					'wps' => $home_direct[ $day ]['wps'] ?? 0,
					'own' => $home_direct[ $day ]['own'] ?? 0,
				),
				'discards'    => $discards[ $day ] ?? array(),
			);
		}

		// Passo 7: ingressi diretti sulla home, somme e quote sui visitatori dell'origine.
		$home_totals = array(
			'wps' => array_sum( array_column( array_column( $day_rows, 'home_direct' ), 'wps' ) ),
			'own' => array_sum( array_column( array_column( $day_rows, 'home_direct' ), 'own' ) ),
		);

		// Passo 8: scarti totali per motivo sugli stessi giorni.
		$per_reason = array();
		foreach ( $discards as $reasons ) {
			foreach ( $reasons as $reason => $n ) {
				$per_reason[ $reason ] = ( $per_reason[ $reason ] ?? 0 ) + $n;
			}
		}

		return array(
			'available'   => true,
			'threshold'   => (int) Comparison::THRESHOLD,
			'coverage'    => $this->coverage( $plan, $own_first, $etl, $last ),
			'days'        => $day_rows,
			'totals'      => array(
				'wps'               => $totals['wps'],
				'own'               => $totals['own'],
				'diff'              => array(
					'visitors' => Metrics::change( $totals['own']['visitors'], $totals['wps']['visitors'] ),
					'views'    => Metrics::change( $totals['own']['views'], $totals['wps']['views'] ),
				),
				'views_per_visitor' => array(
					'wps' => self::per_visitor( $totals['wps'] ),
					'own' => self::per_visitor( $totals['own'] ),
				),
			),
			'home_direct' => array(
				'wps'       => $home_totals['wps'],
				'own'       => $home_totals['own'],
				'diff'      => Metrics::change( $home_totals['own'], $home_totals['wps'] ),
				'share_wps' => self::share( $home_totals['wps'], $totals['wps']['visitors'] ),
				'share_own' => self::share( $home_totals['own'], $totals['own']['visitors'] ),
			),
			'channels'    => $this->channels( $days ),
			'discards'    => Comparison::discard_shares( $per_reason, $totals['own']['views'] ),
			'top'         => $this->top( $days ),
		);
	}

	/**
	 * Blocco `coverage` della risposta.
	 *
	 * @param array<string,mixed>       $plan      Risultato di Comparison::plan().
	 * @param string|null               $own_first Primo giorno `own`.
	 * @param array<string,string>|null $etl      Giorno e fine dell'ultimo ETL.
	 * @param array<string,mixed>|null  $last      Stato completo dell'ultimo ETL.
	 * @return array<string,mixed>
	 */
	private function coverage( array $plan, ?string $own_first, ?array $etl, ?array $last ): array {
		return array(
			'from'          => $plan['from'],
			'to'            => $plan['to'],
			'days'          => count( $plan['days'] ),
			'own_first_day' => $own_first,
			'etl_day'       => $etl['day'] ?? null,
			'etl_ok'        => null === $last ? null : ! empty( $last['ok'] ),
			'excluded'      => $plan['excluded'],
		);
	}

	/**
	 * Segnaposto %s per l'elenco dei giorni in una clausola IN.
	 *
	 * @param array<int,string> $days Giorni.
	 * @return string
	 */
	private static function in_days( array $days ): string {
		return implode( ',', array_fill( 0, count( $days ), '%s' ) );
	}

	/**
	 * Visitatori per giorno con ingresso diretto sulla home, per origine.
	 *
	 * @param array<int,string> $days Giorni completi.
	 * @return array<string,array<string,int>> Giorno => origine => visitatori.
	 */
	private function home_direct( array $days ): array {
		global $wpdb;

		if ( ! $days ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT day, origin, SUM(visitors) AS visitors FROM ' . Schema::table( 'entry_daily' ) . " WHERE object_type = 'home' AND channel = 'direct' AND day IN (" . self::in_days( $days ) . ') GROUP BY day, origin',
				$days
			),
			ARRAY_A
		);

		$result = array();
		foreach ( $rows as $row ) {
			$result[ (string) $row['day'] ][ (string) $row['origin'] ] = (int) $row['visitors'];
		}
		return $result;
	}

	/**
	 * Scarti della raccolta propria per giorno e motivo.
	 *
	 * @param array<int,string> $days Giorni completi.
	 * @return array<string,array<string,int>> Giorno => motivo => richieste.
	 */
	private function discards( array $days ): array {
		global $wpdb;

		if ( ! $days ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT day, reason, n FROM ' . Schema::table( 'discards' ) . ' WHERE day IN (' . self::in_days( $days ) . ') ORDER BY day, reason',
				$days
			),
			ARRAY_A
		);

		$result = array();
		foreach ( $rows as $row ) {
			$result[ (string) $row['day'] ][ (string) $row['reason'] ] = (int) $row['n'];
		}
		return $result;
	}

	/**
	 * Visitatori per canale nelle due origini, dal più grande al più piccolo.
	 *
	 * @param array<int,string> $days Giorni completi.
	 * @return array<int,array<string,mixed>>
	 */
	private function channels( array $days ): array {
		global $wpdb;

		if ( ! $days ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT value, origin, SUM(visitors) AS visitors FROM ' . Schema::table( 'dim_daily' ) . " WHERE dim = 'channel' AND day IN (" . self::in_days( $days ) . ') GROUP BY value, origin',
				$days
			),
			ARRAY_A
		);

		// Passo 1: una riga per canale con le due origini affiancate.
		$by_channel = array();
		foreach ( $rows as $row ) {
			$by_channel[ (string) $row['value'] ][ (string) $row['origin'] ] = (int) $row['visitors'];
		}

		// Passo 2: differenza e ordine per max( wps, own ) decrescente, poi per nome.
		$channels = array();
		foreach ( $by_channel as $channel => $values ) {
			$wps        = $values['wps'] ?? 0;
			$own        = $values['own'] ?? 0;
			$channels[] = array(
				'channel' => (string) $channel,
				'wps'     => $wps,
				'own'     => $own,
				'diff'    => Metrics::change( $own, $wps ),
			);
		}
		usort( $channels, static fn( array $a, array $b ): int => array( max( $b['wps'], $b['own'] ), $a['channel'] ) <=> array( max( $a['wps'], $a['own'] ), $b['channel'] ) );

		return $channels;
	}

	/**
	 * Contenuti con la differenza assoluta più grande di visualizzazioni (le righe con ID 0, come la home, restano).
	 *
	 * @param array<int,string> $days Giorni completi.
	 * @return array<int,array<string,mixed>>
	 */
	private function top( array $days ): array {
		global $wpdb;

		if ( ! $days ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT object_type, object_id, origin, MIN(uri) AS uri, SUM(views) AS views FROM ' . Schema::table( 'object_daily' ) . ' WHERE day IN (' . self::in_days( $days ) . ') GROUP BY object_type, object_id, origin',
				$days
			),
			ARRAY_A
		);

		// Passo 1: una riga per contenuto con le visualizzazioni delle due origini.
		$contents = array();
		foreach ( $rows as $row ) {
			$key = $row['object_type'] . '|' . $row['object_id'];
			if ( ! isset( $contents[ $key ] ) ) {
				$contents[ $key ] = array(
					'type' => (string) $row['object_type'],
					'id'   => (int) $row['object_id'],
					'uri'  => (string) $row['uri'],
					'wps'  => 0,
					'own'  => 0,
				);
			}
			$contents[ $key ][ 'own' === $row['origin'] ? 'own' : 'wps' ] += (int) $row['views'];
		}

		// Passo 2: la classifica (soglia minima, ordine, limite) è una regola pura.
		return array_map(
			static function ( array $row ): array {
				$id    = $row['id'];
				$title = $id > 0 ? html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) : '';
				$link  = $id > 0 ? get_permalink( $id ) : false;

				return array(
					'type'      => $row['type'],
					'id'        => $id,
					'title'     => '' !== $title ? $title : $row['uri'],
					'url'       => $link ? (string) $link : home_url( $row['uri'] ),
					'wps_views' => $row['wps'],
					'own_views' => $row['own'],
					'diff_abs'  => $row['diff_abs'],
					'diff'      => $row['diff'],
				);
			},
			Comparison::top_differences( array_values( $contents ) )
		);
	}

	/**
	 * Visualizzazioni per visitatore arrotondate a 2 cifre, null senza visitatori.
	 *
	 * @param array{visitors:int,views:int} $totals Visitatori e visualizzazioni.
	 * @return float|null
	 */
	private static function per_visitor( array $totals ): ?float {
		return $totals['visitors'] > 0 ? round( $totals['views'] / $totals['visitors'], 2 ) : null;
	}

	/**
	 * Quota in percentuale (una cifra), null senza denominatore.
	 *
	 * @param int $part  Parte.
	 * @param int $whole Totale.
	 * @return float|null
	 */
	private static function share( int $part, int $whole ): ?float {
		return $whole > 0 ? round( $part / $whole * 100, 1 ) : null;
	}
}
