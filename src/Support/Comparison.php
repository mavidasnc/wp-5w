<?php
/**
 * Regole del confronto tra la raccolta propria, WP Statistics e (se presente) Independent Analytics.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Finestra dei giorni confrontabili, soglia delle differenze, quote degli scarti e classifica dei contenuti.
 * Le letture SQL sono in Query\CompareQuery: qui ci sono solo le regole.
 */
final class Comparison {

	/**
	 * Oltre questa differenza percentuale (in valore assoluto) un giorno è «da spiegare».
	 *
	 * @var float
	 */
	public const THRESHOLD = 15.0;

	/**
	 * Un contenuto entra in classifica con almeno queste visualizzazioni in una delle origini.
	 *
	 * @var int
	 */
	public const MIN_VIEWS = 10;

	/**
	 * Righe della classifica dei contenuti.
	 *
	 * @var int
	 */
	public const TOP = 10;

	/**
	 * Motivi di scarto della raccolta propria, nell'ordine in cui si mostrano.
	 *
	 * @var array<int,string>
	 */
	public const REASONS = array( 'bot', 'user', 'dnt', 'rate', 'signature', 'ip' );

	/**
	 * Finestra del confronto e giorni esclusi con il motivo.
	 *
	 * Motivi: partial (primo giorno `own`), ia_partial (primo giorno `ia`, se successivo al primo `own`), today (giorno in corso),
	 * etl_pending (la riga `wps` non è ancora completa), no_wps, no_own e no_ia (manca una delle origini).
	 * Senza righe `ia` (`$ia_first_day` null) il comportamento è quello del confronto a due origini.
	 *
	 * @param string|null                      $own_first_day Primo giorno con righe `own` (Y-m-d), null se non ce ne sono.
	 * @param array{day:string,to:string}|null $etl           Giorno di avvio e fine dell'ultimo ETL, null se non c'è stato.
	 * @param string                           $today         Oggi (Y-m-d, fuso del sito).
	 * @param array<int,string>                $wps_days      Giorni con una riga site_daily `wps`.
	 * @param array<int,string>                $own_days      Giorni con una riga site_daily `own`.
	 * @param string|null                      $ia_first_day  Primo giorno con righe `ia` (Y-m-d), null se Independent Analytics non ha dati.
	 * @param array<int,string>                $ia_days       Giorni con una riga site_daily `ia`.
	 * @return array{from:?string,to:?string,days:array<int,string>,excluded:array<int,array{day:string,reason:string}>}
	 */
	public static function plan( ?string $own_first_day, ?array $etl, string $today, array $wps_days, array $own_days, ?string $ia_first_day = null, array $ia_days = array() ): array {
		// Passo 1: senza righe `own` non c'è niente da confrontare.
		if ( null === $own_first_day ) {
			return array(
				'from'     => null,
				'to'       => null,
				'days'     => array(),
				'excluded' => array(),
			);
		}

		$excluded = array();

		// Passo 2: il primo giorno `own` è parziale (la raccolta parte a metà giornata), salvo che sia oggi.
		if ( $own_first_day !== $today ) {
			$excluded[] = array(
				'day'    => $own_first_day,
				'reason' => 'partial',
			);
		}

		// Passo 2b: se Independent Analytics è stato installato dopo la raccolta propria, il suo primo giorno è parziale
		// e la finestra parte dopo di esso (i giorni precedenti non hanno modo di essere confrontati a tre).
		$start = $own_first_day;
		if ( null !== $ia_first_day && $ia_first_day > $own_first_day ) {
			$start = $ia_first_day;
			if ( $ia_first_day !== $today ) {
				$excluded[] = array(
					'day'    => $ia_first_day,
					'reason' => 'ia_partial',
				);
			}
		}

		// Passo 3: oggi è in corso.
		$excluded[] = array(
			'day'    => $today,
			'reason' => 'today',
		);

		// Passo 4: ultimo giorno con la riga `wps` completa. Ieri diventa completo solo con l'ETL delle 4:00,
		// quindi non si va oltre il giorno prima dell'ultimo ETL né oltre il suo `to`.
		$yesterday = self::shift( $today, -1 );
		$last      = null;
		if ( null !== $etl ) {
			$last = min( $yesterday, self::shift( $etl['day'], -1 ), $etl['to'] );
		}

		// Passo 5: la finestra parte il giorno dopo il primo giorno parziale (`own` o `ia`) e finisce all'ultimo giorno completo.
		$from   = self::shift( $start, 1 );
		$to     = ( null !== $last && $from <= $last ) ? $last : null;
		$window = null === $to ? array( null, null ) : array( $from, $to );

		// Passo 6: i giorni dopo l'ultimo completo, fino a ieri, aspettano l'ETL.
		$pending_from = null === $last ? $from : max( $from, self::shift( $last, 1 ) );
		for ( $day = $pending_from; $day <= $yesterday; $day = self::shift( $day, 1 ) ) {
			$excluded[] = array(
				'day'    => $day,
				'reason' => 'etl_pending',
			);
		}

		// Passo 7: dentro la finestra un giorno è completo solo se ha tutte le origini in uso.
		$days = array();
		if ( null !== $to ) {
			$has_wps = array_flip( $wps_days );
			$has_own = array_flip( $own_days );
			$has_ia  = array_flip( $ia_days );
			for ( $day = $from; $day <= $to; $day = self::shift( $day, 1 ) ) {
				if ( ! isset( $has_wps[ $day ] ) ) {
					$excluded[] = array(
						'day'    => $day,
						'reason' => 'no_wps',
					);
				} elseif ( ! isset( $has_own[ $day ] ) ) {
					$excluded[] = array(
						'day'    => $day,
						'reason' => 'no_own',
					);
				} elseif ( null !== $ia_first_day && ! isset( $has_ia[ $day ] ) ) {
					$excluded[] = array(
						'day'    => $day,
						'reason' => 'no_ia',
					);
				} else {
					$days[] = $day;
				}
			}
		}

		// Passo 8: esclusi in ordine di giorno (i giorni sono già crescenti).
		usort( $excluded, static fn( array $a, array $b ): int => strcmp( $a['day'], $b['day'] ) );

		return array(
			'from'     => $window[0],
			'to'       => $window[1],
			'days'     => $days,
			'excluded' => $excluded,
		);
	}

	/**
	 * Vero se una delle due differenze (non nulle) supera la soglia in valore assoluto.
	 *
	 * @param float|null $visitors_diff Differenza percentuale dei visitatori.
	 * @param float|null $views_diff    Differenza percentuale delle visualizzazioni.
	 * @return bool
	 */
	public static function flagged( ?float $visitors_diff, ?float $views_diff ): bool {
		foreach ( array( $visitors_diff, $views_diff ) as $diff ) {
			if ( null !== $diff && abs( $diff ) > self::THRESHOLD ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scarti per motivo con la quota sulle richieste arrivate all'endpoint (visite registrate più scarti).
	 *
	 * @param array<string,int> $per_reason Scarti per motivo.
	 * @param int               $own_views  Visualizzazioni della raccolta propria.
	 * @return array{total:int,reasons:array<string,array{n:int,share:?float}>}
	 */
	public static function discard_shares( array $per_reason, int $own_views ): array {
		// Prima i motivi noti (anche a zero), poi gli eventuali sconosciuti.
		$counts = array();
		foreach ( self::REASONS as $reason ) {
			$counts[ $reason ] = (int) ( $per_reason[ $reason ] ?? 0 );
		}
		foreach ( $per_reason as $reason => $n ) {
			if ( ! isset( $counts[ $reason ] ) ) {
				$counts[ $reason ] = (int) $n;
			}
		}

		$total       = array_sum( $counts );
		$denominator = $own_views + $total;

		$reasons = array();
		foreach ( $counts as $reason => $n ) {
			$reasons[ $reason ] = array(
				'n'     => $n,
				'share' => $denominator > 0 ? round( $n / $denominator * 100, 1 ) : null,
			);
		}

		return array(
			'total'   => $total,
			'reasons' => $reasons,
		);
	}

	/**
	 * Contenuti con la differenza assoluta più grande rispetto a WP Statistics.
	 *
	 * Con la chiave facoltativa `ia` la riga riporta anche la differenza di Independent Analytics (`diff_abs_ia`, `diff_ia`)
	 * e la classifica usa la più grande delle due.
	 *
	 * @param array<int,array{type:string,id:int,uri:string,wps:int,own:int,ia?:int}> $rows Visualizzazioni per contenuto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function top_differences( array $rows ): array {
		$top = array();
		foreach ( $rows as $row ) {
			// Sotto la soglia minima il confronto è rumore (1 visualizzazione contro 3).
			if ( max( $row['wps'], $row['own'], $row['ia'] ?? 0 ) < self::MIN_VIEWS ) {
				continue;
			}
			$item = $row + array(
				'diff_abs' => abs( $row['own'] - $row['wps'] ),
				'diff'     => Metrics::change( $row['own'], $row['wps'] ),
			);
			if ( isset( $row['ia'] ) ) {
				$item['diff_abs_ia'] = abs( $row['ia'] - $row['wps'] );
				$item['diff_ia']     = Metrics::change( $row['ia'], $row['wps'] );
			}
			$top[] = $item;
		}

		// Differenza assoluta decrescente (la più grande tra Propri e IA); a parità tipo e ID crescenti (l'ordinamento di PHP è stabile).
		$rank = static fn( array $row ): int => max( $row['diff_abs'], $row['diff_abs_ia'] ?? 0 );
		usort(
			$top,
			static fn( array $a, array $b ): int => array( $rank( $b ), $a['type'], $a['id'] ) <=> array( $rank( $a ), $b['type'], $b['id'] )
		);

		return array_slice( $top, 0, self::TOP );
	}

	/**
	 * Sposta una data Y-m-d di n giorni, in UTC: un'ora legale non può saltare o raddoppiare un giorno.
	 *
	 * @param string $day  Data di partenza (Y-m-d).
	 * @param int    $days Giorni (anche negativi).
	 * @return string
	 */
	private static function shift( string $day, int $days ): string {
		return ( new \DateTimeImmutable( $day . ' 12:00:00', new \DateTimeZone( 'UTC' ) ) )
			->modify( sprintf( '%+d days', $days ) )
			->format( 'Y-m-d' );
	}
}
