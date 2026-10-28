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
use Mavida\FiveW\Sources\IndependentAnalyticsSource;
use Mavida\FiveW\Support\Comparison;
use Mavida\FiveW\Support\Metrics;
use Mavida\FiveW\Support\ObjectType;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin, nomi costruiti con $wpdb->prefix in Schema::table().

/**
 * Confronta la raccolta propria (`own`) e, se ha dati, Independent Analytics (`ia`) con WP Statistics (`wps`) sui giorni completi.
 * Con i log di accesso (`log`: i client da browser; `logbot`: i bot) aggiunge le loro colonne, senza cambiare la finestra dei giorni:
 * un buco negli archivi dei log non deve togliere giorni al confronto tra gli altri strumenti.
 *
 * ECCEZIONE ALLA REGOLA DELL'ORIGINE: è l'unica classe di src/Query/ che legge le tabelle aggregate
 * senza Origin::where(), perché confrontare le origini è il suo scopo. Legge solo tabelle wp5w_*
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
				'available'     => false,
				'ia_available'  => false,
				'log_available' => false,
				'coverage'      => $this->coverage( Comparison::plan( null, null, wp_date( 'Y-m-d' ), array(), array() ), null, null, null ),
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

		// Passo 3b: Independent Analytics entra solo se il plugin è attivo e ha righe: altrimenti la risposta resta quella a due origini
		// (un IA spento non deve riempire la finestra di giorni «no_ia»).
		$with_ia  = null !== ( new IndependentAnalyticsSource() )->version() && ! empty( $site['ia'] );
		$ia_days  = $with_ia ? array_keys( $site['ia'] ) : array();
		$ia_first = $with_ia ? min( $ia_days ) : null;

		// Passo 3c: i log di accesso entrano se ci sono righe `log` (la scrittura la fa AccessLogSource solo con un percorso nelle Opzioni).
		$with_log = ! empty( $site['log'] );

		// Passo 4: finestra e copertura.
		$plan = Comparison::plan(
			$own_first,
			$etl,
			wp_date( 'Y-m-d' ),
			array_keys( $site['wps'] ?? array() ),
			array_keys( $site['own'] ?? array() ),
			$ia_first,
			$ia_days
		);
		$days = $plan['days'];

		// Passo 5: letture sui soli giorni completi (con nessun giorno non si interroga: IN () sarebbe un errore SQL).
		$home_direct = $this->home_direct( $days );
		$discards    = $this->discards( $days );

		// Passo 5b: giorni coperti dai log (righe `log` in site_daily) e, tra questi, quelli con il dettaglio per contenuto
		// (object_daily ed entry_daily, scritti dalla 0.17.0: i giorni importati prima ne sono senza finché non si rilancia l'import).
		// Le differenze del log si calcolano sempre rispetto a WP Statistics sugli stessi giorni.
		$log_days        = $with_log ? array_values( array_filter( $days, static fn( string $day ): bool => isset( $site['log'][ $day ] ) ) ) : array();
		$log_detail_days = $this->log_detail_days( $log_days );

		// Passo 6: una riga per giorno e i totali (le chiavi `ia` e `diff_ia` ci sono solo con Independent Analytics).
		$day_rows = array();
		$empty    = array(
			'visitors' => 0,
			'views'    => 0,
		);
		$totals   = array(
			'wps' => $empty,
			'own' => $empty,
		);
		if ( $with_ia ) {
			$totals['ia'] = $empty;
		}
		// Log: i totali sommano solo i giorni che hanno righe `log`, e la differenza è rispetto a WP Statistics su quegli stessi giorni.
		$log_totals = array(
			'log'    => $empty,
			'logbot' => $empty,
			'wps'    => $empty,
			'days'   => 0,
		);
		foreach ( $days as $day ) {
			$wps = $site['wps'][ $day ];
			$own = $site['own'][ $day ];
			foreach ( array( 'visitors', 'views' ) as $key ) {
				$totals['wps'][ $key ] += $wps[ $key ];
				$totals['own'][ $key ] += $own[ $key ];
			}

			$diff = array(
				'visitors' => Metrics::change( $own['visitors'], $wps['visitors'] ),
				'views'    => Metrics::change( $own['views'], $wps['views'] ),
			);
			$row  = array(
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

			if ( $with_ia ) {
				$ia = $site['ia'][ $day ];
				foreach ( array( 'visitors', 'views' ) as $key ) {
					$totals['ia'][ $key ] += $ia[ $key ];
				}
				$row['ia']                = $ia;
				$row['diff_ia']           = array(
					'visitors' => Metrics::change( $ia['visitors'], $wps['visitors'] ),
					'views'    => Metrics::change( $ia['views'], $wps['views'] ),
				);
				$row['flagged']           = $row['flagged'] || Comparison::flagged( $row['diff_ia']['visitors'], $row['diff_ia']['views'] );
				$row['home_direct']['ia'] = $home_direct[ $day ]['ia'] ?? 0;
			}

			// Log di accesso: `log` e `logbot` del giorno (null se i log non lo coprono), differenza di `log` rispetto a WP Statistics.
			// `flagged` non li guarda: i log contano anche ciò che WP Statistics non conta e la soglia non è fatta per loro.
			if ( $with_log ) {
				$log             = $site['log'][ $day ] ?? null;
				$row['log']      = $log;
				$row['logbot']   = $site['logbot'][ $day ] ?? null;
				$row['diff_log'] = null === $log ? null : array(
					'visitors' => Metrics::change( $log['visitors'], $wps['visitors'] ),
					'views'    => Metrics::change( $log['views'], $wps['views'] ),
				);
				if ( array() !== $log_detail_days ) {
					$row['home_direct']['log'] = in_array( $day, $log_detail_days, true ) ? ( $home_direct[ $day ]['log'] ?? 0 ) : null;
				}
				if ( null !== $log ) {
					++$log_totals['days'];
					foreach ( array( 'visitors', 'views' ) as $key ) {
						$log_totals['log'][ $key ]    += $log[ $key ];
						$log_totals['wps'][ $key ]    += $wps[ $key ];
						$log_totals['logbot'][ $key ] += $row['logbot'][ $key ] ?? 0;
					}
				}
			}
			$day_rows[] = $row;
		}

		// Passo 7: ingressi diretti sulla home, somme e quote sui visitatori dell'origine.
		$home_totals = array(
			'wps' => array_sum( array_column( array_column( $day_rows, 'home_direct' ), 'wps' ) ),
			'own' => array_sum( array_column( array_column( $day_rows, 'home_direct' ), 'own' ) ),
		);
		if ( $with_ia ) {
			$home_totals['ia'] = array_sum( array_column( array_column( $day_rows, 'home_direct' ), 'ia' ) );
		}

		// Passo 8: scarti totali per motivo sugli stessi giorni.
		$per_reason = array();
		foreach ( $discards as $reasons ) {
			foreach ( $reasons as $reason => $n ) {
				$per_reason[ $reason ] = ( $per_reason[ $reason ] ?? 0 ) + $n;
			}
		}

		$response = array(
			'available'     => true,
			'ia_available'  => $with_ia,
			'log_available' => $with_log,
			'threshold'     => (int) Comparison::THRESHOLD,
			'min_views'     => Comparison::MIN_VIEWS,
			'coverage'      => $this->coverage( $plan, $own_first, $etl, $last ),
			'days'          => $day_rows,
			'totals'        => array(
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
			'home_direct'   => array(
				'wps'       => $home_totals['wps'],
				'own'       => $home_totals['own'],
				'diff'      => Metrics::change( $home_totals['own'], $home_totals['wps'] ),
				'share_wps' => self::share( $home_totals['wps'], $totals['wps']['visitors'] ),
				'share_own' => self::share( $home_totals['own'], $totals['own']['visitors'] ),
			),
			'channels'      => $this->channels( $days, $with_ia, $with_log ? $log_days : null ),
			'discards'      => Comparison::discard_shares( $per_reason, $totals['own']['views'] ),
			'top'           => $this->top( $days, $with_ia, $log_detail_days ),
		);

		// Independent Analytics: chiavi in più, la forma delle altre resta quella del confronto a due origini.
		if ( $with_ia ) {
			$response['totals']['ia']                      = $totals['ia'];
			$response['totals']['diff_ia']                 = array(
				'visitors' => Metrics::change( $totals['ia']['visitors'], $totals['wps']['visitors'] ),
				'views'    => Metrics::change( $totals['ia']['views'], $totals['wps']['views'] ),
			);
			$response['totals']['views_per_visitor']['ia'] = self::per_visitor( $totals['ia'] );
			$response['home_direct']['ia']                 = $home_totals['ia'];
			$response['home_direct']['diff_ia']            = Metrics::change( $home_totals['ia'], $home_totals['wps'] );
			$response['home_direct']['share_ia']           = self::share( $home_totals['ia'], $totals['ia']['visitors'] );
		}

		// Log di accesso: totali dei giorni coperti, differenza di `log` rispetto a WP Statistics sugli stessi giorni, bot per etichetta.
		if ( $with_log ) {
			$response['totals']['log']                      = $log_totals['log'];
			$response['totals']['logbot']                   = $log_totals['logbot'];
			$response['totals']['log_days']                 = $log_totals['days'];
			$response['totals']['diff_log']                 = array(
				'visitors' => Metrics::change( $log_totals['log']['visitors'], $log_totals['wps']['visitors'] ),
				'views'    => Metrics::change( $log_totals['log']['views'], $log_totals['wps']['views'] ),
			);
			$response['totals']['views_per_visitor']['log'] = self::per_visitor( $log_totals['log'] );
			$response['bots']                               = $this->bots( $days );
		}

		// Ingressi diretti sulla home dai log: solo sui giorni con il dettaglio per contenuto, confrontati con WP Statistics sugli stessi giorni.
		if ( array() !== $log_detail_days ) {
			$home_log     = 0;
			$home_wps     = 0;
			$log_visitors = 0;
			foreach ( $log_detail_days as $day ) {
				$home_log     += $home_direct[ $day ]['log'] ?? 0;
				$home_wps     += $home_direct[ $day ]['wps'] ?? 0;
				$log_visitors += $site['log'][ $day ]['visitors'];
			}
			$response['home_direct']['log']       = $home_log;
			$response['home_direct']['diff_log']  = Metrics::change( $home_log, $home_wps );
			$response['home_direct']['share_log'] = self::share( $home_log, $log_visitors );
			$response['home_direct']['log_days']  = count( $log_detail_days );
			$response['home_direct']['wps_log']   = $home_wps;
		}

		return $response;
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
	 * Giorni dei log che hanno anche il dettaglio per contenuto (righe `log` in object_daily).
	 *
	 * @param array<int,string> $log_days Giorni completi coperti dai log.
	 * @return array<int,string>
	 */
	private function log_detail_days( array $log_days ): array {
		global $wpdb;

		if ( ! $log_days ) {
			return array();
		}

		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT day FROM ' . Schema::table( 'object_daily' ) . " WHERE origin = 'log' AND day IN (" . self::in_days( $log_days ) . ') ORDER BY day', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Segnaposto generati per ogni giorno.
				$log_days
			)
		);

		return array_map( 'strval', $found );
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
				'SELECT day, origin, SUM(visitors) AS visitors FROM ' . Schema::table( 'entry_daily' ) . " WHERE object_type = 'home' AND channel = 'direct' AND day IN (" . self::in_days( $days ) . ') GROUP BY day, origin', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Segnaposto generati per ogni giorno.
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
				'SELECT day, reason, n FROM ' . Schema::table( 'discards' ) . ' WHERE day IN (' . self::in_days( $days ) . ') ORDER BY day, reason', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Segnaposto generati per ogni giorno.
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
	 * Visitatori per canale nelle origini, dal più grande al più piccolo.
	 *
	 * @param array<int,string>      $days     Giorni completi.
	 * @param bool                   $with_ia  Aggiunge le colonne di Independent Analytics.
	 * @param array<int,string>|null $log_days Giorni coperti dai log, per la colonna dei log di accesso (client da browser); null senza log.
	 * @return array<int,array<string,mixed>>
	 */
	private function channels( array $days, bool $with_ia, ?array $log_days = null ): array {
		global $wpdb;

		if ( ! $days ) {
			return array();
		}

		// Visitatori sui soli giorni dei log (per la differenza del log rispetto a WP Statistics sugli stessi giorni).
		$on_log_days = $log_days ? 'SUM( IF( day IN (' . self::in_days( $log_days ) . '), visitors, 0 ) )' : '0';
		$rows        = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT value, origin, SUM(visitors) AS visitors, {$on_log_days} AS visitors_log_days FROM " . Schema::table( 'dim_daily' ) . " WHERE dim = 'channel' AND day IN (" . self::in_days( $days ) . ') GROUP BY value, origin', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Segnaposto generati per ogni giorno.
				array_merge( $log_days ?? array(), $days )
			),
			ARRAY_A
		);

		// Passo 1: una riga per canale con le due origini affiancate. WP Statistics scrive «(n/d)» dove la raccolta propria scrive
		// «unassigned»: sono lo stesso canale, quindi si uniscono prima di raggruppare (e si sommano, se un'origine li ha entrambi).
		$by_channel = array();
		foreach ( $rows as $row ) {
			$channel = '(n/d)' === $row['value'] ? 'unassigned' : (string) $row['value'];
			$origin  = (string) $row['origin'];

			// I bot dei log (`logbot`) non sono una colonna del confronto: un canale presente solo lì darebbe una riga di zeri.
			if ( 'logbot' === $origin ) {
				continue;
			}

			$by_channel[ $channel ][ $origin ] = ( $by_channel[ $channel ][ $origin ] ?? 0 ) + (int) $row['visitors'];
			if ( 'wps' === $origin ) {
				$by_channel[ $channel ]['wps_log_days'] = ( $by_channel[ $channel ]['wps_log_days'] ?? 0 ) + (int) $row['visitors_log_days'];
			}
		}

		// Passo 2: differenza (rispetto a wps) e ordine per il massimo tra le origini decrescente, poi per nome.
		$channels = array();
		foreach ( $by_channel as $channel => $values ) {
			$wps  = $values['wps'] ?? 0;
			$own  = $values['own'] ?? 0;
			$item = array(
				'channel' => (string) $channel,
				'wps'     => $wps,
				'own'     => $own,
				'diff'    => Metrics::change( $own, $wps ),
			);
			if ( $with_ia ) {
				$ia              = $values['ia'] ?? 0;
				$item['ia']      = $ia;
				$item['diff_ia'] = Metrics::change( $ia, $wps );
			}
			if ( null !== $log_days ) {
				$log              = $values['log'] ?? 0;
				$item['log']      = $log;
				$item['wps_log']  = $values['wps_log_days'] ?? 0;
				$item['diff_log'] = Metrics::change( $log, $item['wps_log'] );
			}
			$channels[] = $item;
		}
		$largest = static fn( array $row ): int => max( $row['wps'], $row['own'], $row['ia'] ?? 0, $row['log'] ?? 0 );
		usort( $channels, static fn( array $a, array $b ): int => array( $largest( $b ), $a['channel'] ) <=> array( $largest( $a ), $b['channel'] ) );

		return $channels;
	}

	/**
	 * Bot dei log di accesso per etichetta (BotFilter::label() o `senza-js`), dal più frequente: visitatori sommati sui giorni completi.
	 *
	 * @param array<int,string> $days Giorni completi.
	 * @return array<int,array{label:string,visitors:int}>
	 */
	private function bots( array $days ): array {
		global $wpdb;

		if ( ! $days ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT value, SUM(visitors) AS visitors FROM ' . Schema::table( 'dim_daily' ) . " WHERE dim = 'bot' AND origin = 'logbot' AND day IN (" . self::in_days( $days ) . ') GROUP BY value ORDER BY visitors DESC, value LIMIT 20', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Segnaposto generati per ogni giorno.
				$days
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'label'    => (string) $row['value'],
				'visitors' => (int) $row['visitors'],
			),
			$rows
		);
	}

	/**
	 * Contenuti con la differenza assoluta più grande di visualizzazioni (le righe con ID 0, come la home, restano).
	 *
	 * Il log è solo una colonna in più: non entra nella soglia né nella classifica, vale null per i tipi che il log non sa
	 * riconoscere (archivi, ricerche) e la sua differenza è rispetto a WP Statistics sui soli giorni con il dettaglio dei log.
	 *
	 * @param array<int,string> $days            Giorni completi.
	 * @param bool              $with_ia         Aggiunge le visualizzazioni di Independent Analytics.
	 * @param array<int,string> $log_detail_days Giorni con il dettaglio per contenuto dei log (vuoto: nessuna colonna del log).
	 * @return array<int,array<string,mixed>>
	 */
	private function top( array $days, bool $with_ia, array $log_detail_days = array() ): array {
		global $wpdb;

		if ( ! $days ) {
			return array();
		}

		$with_log    = array() !== $log_detail_days;
		$on_log_days = $with_log ? 'SUM( IF( day IN (' . self::in_days( $log_detail_days ) . '), views, 0 ) )' : '0';
		$rows        = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_type, object_id, origin, MIN(uri) AS uri, SUM(views) AS views, {$on_log_days} AS views_log_days FROM " . Schema::table( 'object_daily' ) . ' WHERE day IN (' . self::in_days( $days ) . ') GROUP BY object_type, object_id, origin', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Segnaposto generati per ogni giorno.
				array_merge( $log_detail_days, $days )
			),
			ARRAY_A
		);

		// Passo 1: una riga per contenuto con le visualizzazioni di ogni origine. Un'origine che non è nel confronto si ignora
		// (non va sommata a wps).
		$origins = array_merge( array( 'wps', 'own' ), $with_ia ? array( 'ia' ) : array(), $with_log ? array( 'log' ) : array() );
		/**
		 * Visualizzazioni per contenuto e origine.
		 *
		 * @var array<string,array{type:string,id:int,uri:string,wps:int,own:int,ia?:int,log?:int,wps_log?:int}> $contents
		 */
		$contents = array();
		foreach ( $rows as $row ) {
			if ( ! in_array( $row['origin'], $origins, true ) ) {
				continue;
			}
			$key = $row['object_type'] . '|' . $row['object_id'];
			if ( ! isset( $contents[ $key ] ) ) {
				$contents[ $key ] = array(
					'type' => (string) $row['object_type'],
					'id'   => (int) $row['object_id'],
					'uri'  => (string) $row['uri'],
					'wps'  => 0,
					'own'  => 0,
				);
				if ( $with_ia ) {
					$contents[ $key ]['ia'] = 0;
				}
				if ( $with_log ) {
					$contents[ $key ]['log']     = 0;
					$contents[ $key ]['wps_log'] = 0;
				}
			}
			$contents[ $key ][ $row['origin'] ] += (int) $row['views'];
			if ( $with_log && 'wps' === $row['origin'] ) {
				$contents[ $key ]['wps_log'] += (int) $row['views_log_days'];
			}
		}

		// Passo 2: la classifica (soglia minima, ordine, limite) è una regola pura.
		return array_map(
			static function ( array $row ): array {
				// Titolo e link da WordPress solo se l'ID è quello di un articolo o di una pagina; altrimenti l'URI.
				$id      = $row['id'];
				$is_post = ObjectType::is_post( $row['type'], $id );
				$title   = $is_post ? html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) : '';
				$link    = $is_post ? get_permalink( $id ) : false;

				$item = array(
					'type'      => $row['type'],
					'id'        => $id,
					'title'     => '' !== $title ? $title : $row['uri'],
					'url'       => $link ? (string) $link : home_url( $row['uri'] ),
					'wps_views' => $row['wps'],
					'own_views' => $row['own'],
					'diff_abs'  => $row['diff_abs'],
					'diff'      => $row['diff'],
				);
				if ( isset( $row['ia'] ) ) {
					$item['ia_views']    = $row['ia'];
					$item['diff_abs_ia'] = $row['diff_abs_ia'];
					$item['diff_ia']     = $row['diff_ia'];
				}
				// Log: solo home e contenuti singoli (AccessLogSource non riconosce gli altri tipi).
				if ( isset( $row['log'] ) ) {
					$known             = in_array( $row['type'], array( 'home', 'post', 'page' ), true ) || str_starts_with( $row['type'], 'post_type_' );
					$item['log_views'] = $known ? $row['log'] : null;
					$item['diff_log']  = $known ? Metrics::change( $row['log'], $row['wps_log'] ) : null;
					// Base della differenza: WP Statistics sui soli giorni dei log (il tooltip del badge la mostra).
					$item['wps_log_views'] = $known ? $row['wps_log'] : null;
				}

				return $item;
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
