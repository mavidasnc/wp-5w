<?php
/**
 * Import da Search Console (API) dei soli giorni che mancano.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Etl;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Gsc\Client;
use Mavida\FiveW\Sources\RankMathSource;
use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\GscDays;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabella del plugin, nome costruito con $wpdb->prefix in Schema::table().

/**
 * Scarica i giorni dei 16 mesi di Search Console che non hanno ancora righe di origine «api», a lotti di 30 giorni.
 *
 * Un giorno importato dall'API sostituisce per intero le righe di Rank Math di quel giorno (l'API ne dà circa il 60% in più)
 * e da lì in poi l'ETL di Rank Math lo salta. I giorni già dell'API non si toccano. Un giorno per cui l'API risponde
 * senza righe si ricorda in `wp5w_gsc_empty_days` e non si richiede più.
 */
final class GscApiImport {

	/**
	 * Opzione con l'esito dell'ultimo lotto.
	 *
	 * @var string
	 */
	public const STATE_OPTION = 'wp5w_gsc_state';

	/**
	 * Opzione con i giorni per cui l'API non ha restituito righe.
	 *
	 * @var string
	 */
	public const EMPTY_OPTION = 'wp5w_gsc_empty_days';

	/**
	 * Durata massima del lock: se il processo muore, dopo questo tempo si può ripartire.
	 *
	 * @var int
	 */
	private const LOCK_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Giorni tra oggi e l'ultimo giorno definitivo quando l'API non dice nulla (ritardo normale: 2-3 giorni).
	 *
	 * @var int
	 */
	private const FINAL_LAG_DAYS = 3;

	/**
	 * Importa il prossimo lotto di giorni mancanti.
	 *
	 * @return array{ok:bool,busy?:bool,error?:string,days:int,rows:int,remaining:int,from:?string,to:?string,last_final:?string,time:int}
	 */
	public function run_batch(): array {
		$result = array(
			'ok'         => true,
			'days'       => 0,
			'rows'       => 0,
			'remaining'  => 0,
			'from'       => null,
			'to'         => null,
			'last_final' => null,
			'time'       => time(),
		);

		// Senza collegamento o proprietà non c'è nulla da importare: lo si dice prima di prendere il lock.
		$config = Client::config();
		if ( ! Client::is_connected() || '' === $config['site'] ) {
			$result['ok']    = false;
			$result['error'] = __( 'Search Console non è collegata: collega Google e scegli la proprietà.', 'wp-5w' );
			update_option( self::STATE_OPTION, $result, false );
			return $result;
		}

		// Stesso lock dell'ETL: Rank Math e Search Console scrivono le stesse righe di gsc_daily.
		if ( get_transient( Importer::LOCK ) ) {
			return array(
				'ok'    => false,
				'busy'  => true,
				'error' => __( 'Un import è già in corso: riprova tra qualche minuto.', 'wp-5w' ),
			) + $result;
		}
		set_transient( Importer::LOCK, 1, self::LOCK_TTL );

		try {
			$site                 = $config['site'];
			$host                 = GscDays::host_filter( $site, home_url( '/' ) );
			$last_final           = $this->last_final_day( $site, $host );
			$window               = GscDays::window( wp_date( 'Y-m-d' ), $last_final );
			$result['last_final'] = $last_final;

			if ( null !== $window ) {
				$empty   = $this->empty_days( $window );
				$missing = GscDays::missing( $window, $this->api_days( $window ), $empty );
				$batch   = GscDays::next_batch( $missing );

				if ( null !== $batch ) {
					$todo = GscDays::in_batch( $missing, $batch );
					$rows = Client::rows( $site, $batch->from, $batch->to, array( 'date', 'page', 'query' ), $host );
					$days = GscDays::group( $rows, $host, $todo );

					// Solo i giorni richiesti: quelli già importati dentro l'intervallo non si toccano.
					$writer = new GscFileImport();
					foreach ( $days as $day => $items ) {
						$result['rows'] += $writer->replace_day( (string) $day, $items );
						++$result['days'];
					}

					// Una risposta 200 senza righe per un giorno definitivo è un giorno vuoto: non si richiede più.
					// Con un errore di rete si è già usciti dall'eccezione, quindi qui non si registra nulla.
					$empty_now = array_values( array_diff( $todo, array_map( 'strval', array_keys( $days ) ) ) );
					$this->save_empty_days( array_merge( $empty, $empty_now ), $window );

					( new RankMathSource() )->map_unknown_pages( $batch );

					$result['from']      = $batch->from;
					$result['to']        = $batch->to;
					$result['remaining'] = count( $missing ) - count( $todo );
				}
			}
		} catch ( \Throwable $e ) {
			$result['ok']    = false;
			$result['error'] = $e->getMessage();
		} finally {
			delete_transient( Importer::LOCK );
		}

		update_option( self::STATE_OPTION, $result, false );
		return $result;
	}

	/**
	 * Importa lotti finché non finiscono i giorni mancanti o il tempo a disposizione (per il cron).
	 *
	 * @param int $seconds Secondi dopo i quali non si avvia un altro lotto.
	 * @return array<string,mixed> Esito dell'ultimo lotto, con giorni e righe sommati.
	 */
	public function run_all( int $seconds ): array {
		$deadline = microtime( true ) + $seconds;
		$total    = array(
			'days' => 0,
			'rows' => 0,
		);

		do {
			$result         = $this->run_batch();
			$total['days'] += $result['days'];
			$total['rows'] += $result['rows'];
		} while ( $result['ok'] && $result['remaining'] > 0 && microtime( true ) < $deadline );

		return $total + $result;
	}

	/**
	 * Giorni ancora da importare, senza chiamare Google: la finestra finisce all'ultimo giorno definitivo noto dall'ultimo lotto
	 * (o a oggi meno tre giorni, il ritardo normale di Search Console).
	 *
	 * @return int|null Null se Search Console non è collegata.
	 */
	public static function pending_days(): ?int {
		if ( ! Client::is_connected() || '' === Client::config()['site'] ) {
			return null;
		}

		$today = wp_date( 'Y-m-d' );
		$state = self::state();
		$final = is_string( $state['last_final'] ?? null ) ? $state['last_final'] : gmdate( 'Y-m-d', strtotime( $today . ' -' . self::FINAL_LAG_DAYS . ' days' ) );
		$win   = GscDays::window( $today, $final );
		if ( null === $win ) {
			return 0;
		}

		$self = new self();
		return count( GscDays::missing( $win, $self->api_days( $win ), $self->empty_days( $win ) ) );
	}

	/**
	 * Esito dell'ultimo lotto.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function state(): ?array {
		$state = get_option( self::STATE_OPTION );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Dimentica i giorni vuoti e l'esito (cambio di proprietà o di account: i dati da richiedere sono altri).
	 *
	 * @return void
	 */
	public static function reset(): void {
		delete_option( self::EMPTY_OPTION );
		delete_option( self::STATE_OPTION );
	}

	/**
	 * Ultimo giorno con dati definitivi: il più recente tra le righe degli ultimi 10 giorni.
	 *
	 * @param string      $site Proprietà.
	 * @param string|null $host Host delle pagine (proprietà di dominio).
	 * @return string
	 * @throws \RuntimeException Se la richiesta fallisce.
	 */
	private function last_final_day( string $site, ?string $host ): string {
		$today = wp_date( 'Y-m-d' );
		$rows  = Client::rows( $site, gmdate( 'Y-m-d', strtotime( $today . ' -10 days' ) ), $today, array( 'date' ), $host );

		$days = array_filter( array_map( static fn( array $row ): string => (string) ( $row['keys'][0] ?? '' ), $rows ), array( DateRange::class, 'is_valid' ) );
		return array() === $days ? gmdate( 'Y-m-d', strtotime( $today . ' -' . self::FINAL_LAG_DAYS . ' days' ) ) : max( $days );
	}

	/**
	 * Giorni della finestra che hanno già righe dell'API.
	 *
	 * @param DateRange $window Finestra.
	 * @return array<int,string>
	 */
	private function api_days( DateRange $window ): array {
		global $wpdb;

		$table = Schema::table( 'gsc_daily' );
		return array_map(
			'strval',
			$wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT day FROM {$table} WHERE origin = 'api' AND day BETWEEN %s AND %s", $window->from, $window->to ) )
		);
	}

	/**
	 * Giorni vuoti ricordati, limitati alla finestra.
	 *
	 * @param DateRange $window Finestra.
	 * @return array<int,string>
	 */
	private function empty_days( DateRange $window ): array {
		$saved = get_option( self::EMPTY_OPTION, array() );
		return array_values(
			array_filter(
				is_array( $saved ) ? array_map( 'strval', $saved ) : array(),
				static fn( string $day ): bool => $day >= $window->from && $day <= $window->to
			)
		);
	}

	/**
	 * Salva i giorni vuoti togliendo i duplicati e quelli usciti dalla finestra (la lista non cresce all'infinito).
	 *
	 * @param array<int,string> $days   Giorni vuoti.
	 * @param DateRange         $window Finestra.
	 * @return void
	 */
	private function save_empty_days( array $days, DateRange $window ): void {
		$days = array_values(
			array_unique( array_filter( $days, static fn( string $day ): bool => $day >= $window->from && $day <= $window->to ) )
		);
		sort( $days );
		update_option( self::EMPTY_OPTION, $days, false );
	}
}
