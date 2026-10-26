<?php
/**
 * Giorni del sito interamente coperti dai log di accesso.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Gli archivi dei log coprono da una rotazione all'altra (di notte), non da mezzanotte a mezzanotte:
 * un giorno si scrive solo se i log lo coprono tutto, altrimenti il totale sarebbe parziale e il Confronto sbagliato.
 */
final class LogCoverage {

	/**
	 * Secondi di tolleranza: tra due archivi vicini e tra l'inizio di un giorno e la prima riga del log
	 * (a mezzanotte può non esserci nessuna richiesta nei primi secondi).
	 *
	 * @var int
	 */
	public const TOLERANCE = 600;

	/**
	 * Giorni del sito (Y-m-d) interamente coperti dagli intervalli dei log.
	 *
	 * Ogni archivio porta il suo intervallo dalla prima all'ultima riga (non si fida né del nome né della data del file):
	 * gli intervalli vicini si uniscono, un giorno è completo se sta tutto dentro un intervallo unito.
	 *
	 * @param array<int,array{0:int,1:int}> $intervals Intervalli [primo timestamp, ultimo timestamp] di ogni archivio.
	 * @param \DateTimeZone                 $timezone  Fuso del sito.
	 * @param int                           $tolerance Secondi di tolleranza.
	 * @return array<int,string> Giorni in ordine crescente.
	 */
	public static function complete_days( array $intervals, \DateTimeZone $timezone, int $tolerance = self::TOLERANCE ): array {
		// Passo 1: intervalli ordinati e uniti quando il buco è sotto la tolleranza.
		usort( $intervals, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );
		$merged = array();
		foreach ( $intervals as $interval ) {
			$last = count( $merged ) - 1;
			if ( $last >= 0 && $interval[0] - $merged[ $last ][1] <= $tolerance ) {
				$merged[ $last ][1] = max( $merged[ $last ][1], $interval[1] );
			} else {
				$merged[] = $interval;
			}
		}

		// Passo 2: per ogni intervallo unito, i giorni che ci stanno interi (i confini si calcolano nel fuso del sito, ora legale compresa).
		$days = array();
		foreach ( $merged as $interval ) {
			$day = ( new \DateTimeImmutable( '@' . $interval[0] ) )->setTimezone( $timezone )->setTime( 0, 0 );
			while ( $day->getTimestamp() <= $interval[1] ) {
				$next = $day->modify( '+1 day' );
				if ( $day->getTimestamp() >= $interval[0] - $tolerance && $next->getTimestamp() <= $interval[1] + $tolerance ) {
					$days[] = $day->format( 'Y-m-d' );
				}
				$day = $next;
			}
		}

		return $days;
	}
}
