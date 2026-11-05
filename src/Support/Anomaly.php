<?php
/**
 * Regola degli avvisi sulle visite anomale della Panoramica.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Una coppia (sito di provenienza, contenuto) è anomala se ha un picco E una firma anomala.
 *
 * - Picco: visitatori nelle ultime W ore >= MIN e >= K volte la mediana dei visitatori al giorno dei B giorni precedenti
 *   (con la mediana minima a 1). La mediana e non la media: un attacco passato (il bot sulla home di fine ottobre 2026) alza
 *   la media dei giorni dopo e nasconderebbe il successivo, mentre la mediana resta quella dei giorni normali.
 * - Firma anomala, almeno una: paesi diversi >= P, quota della combinazione browser + sistema più frequente >= Q% (le visite
 *   senza browser e sistema riconosciuti non possono essere la combinazione dominante), un solo hash dell'IP con >= M visite.
 * Il picco da solo (un articolo condiviso su LinkedIn, che prima aveva zero visite) non è un avviso.
 */
final class Anomaly {

	/**
	 * Mediana dei valori dei giorni indicati; un giorno senza valore conta zero. 0 se non ci sono giorni.
	 *
	 * @param array<string,int> $by_day Valori per giorno (Y-m-d).
	 * @param array<int,string> $days   Giorni della base.
	 * @return float
	 */
	public static function median( array $by_day, array $days ): float {
		if ( ! $days ) {
			return 0.0;
		}
		$values = array_map( static fn( string $day ): int => (int) ( $by_day[ $day ] ?? 0 ), $days );
		sort( $values );
		$mid = intdiv( count( $values ), 2 );
		return 0 === count( $values ) % 2 ? ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2 : (float) $values[ $mid ];
	}

	/**
	 * Vero se i visitatori della finestra sono un picco rispetto alla mediana.
	 *
	 * @param int   $visitors Visitatori nella finestra.
	 * @param float $median   Mediana dei visitatori al giorno nella base.
	 * @param int   $min      Visitatori minimi (MIN).
	 * @param int   $factor   Volte la mediana (K).
	 * @return bool
	 */
	public static function is_peak( int $visitors, float $median, int $min, int $factor ): bool {
		return $visitors >= $min && $visitors >= $factor * max( 1.0, $median );
	}

	/**
	 * Combinazione browser + sistema più frequente e sua quota sul totale dei visitatori.
	 *
	 * La combinazione sconosciuta (browser e sistema vuoti, per esempio le visite di prima del riconoscimento del 14/10/2026)
	 * resta nel totale ma non può essere quella dominante.
	 *
	 * @param array<string,int> $counts Visitatori per combinazione «browser|sistema».
	 * @return array{combo:string,share:float}|null Null se non c'è nessuna combinazione riconosciuta.
	 */
	public static function dominant( array $counts ): ?array {
		$total = array_sum( $counts );
		$known = array_filter( $counts, static fn( int $n, string $combo ): bool => '|' !== $combo && '' !== $combo && $n > 0, ARRAY_FILTER_USE_BOTH );
		if ( ! $known || $total <= 0 ) {
			return null;
		}
		arsort( $known );
		$combo = (string) array_key_first( $known );
		return array(
			'combo' => $combo,
			'share' => round( 100 * $known[ $combo ] / $total, 1 ),
		);
	}

	/**
	 * Motivi della firma anomala (vuoto se non ce n'è nessuno): `countries`, `combo`, `ip`.
	 *
	 * @param int                                          $countries Paesi diversi.
	 * @param float|null                                   $share     Quota della combinazione dominante (0-100) o null.
	 * @param int|null                                     $ip_max    Visite del singolo hash dell'IP più attivo, null senza hash (visite di prima della v7).
	 * @param array{countries:int,share:int,ip_visits:int} $limits Soglie P, Q e M.
	 * @return array<int,string>
	 */
	public static function signature( int $countries, ?float $share, ?int $ip_max, array $limits ): array {
		$reasons = array();
		if ( $countries >= $limits['countries'] ) {
			$reasons[] = 'countries';
		}
		if ( null !== $share && $share >= $limits['share'] ) {
			$reasons[] = 'combo';
		}
		if ( null !== $ip_max && $ip_max >= $limits['ip_visits'] ) {
			$reasons[] = 'ip';
		}
		return $reasons;
	}
}
