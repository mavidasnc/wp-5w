<?php
/**
 * Giorni di confronto della scheda «Oggi».
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Sceglie i giorni su cui calcolare le medie e calcola la media. Le letture SQL sono in Query\TodayQuery.
 */
final class TodayWindow {

	/**
	 * Giorni interi precedenti a oggi su cui fare la media.
	 *
	 * Parte dal giorno `$today - $n`, ma mai dal primo giorno di raccolta (sempre parziale, perché la raccolta
	 * parte a metà giornata) e arriva fino a ieri. Con meno storico di `$n` giorni la finestra è più corta;
	 * senza nessun giorno intero è vuota. Date come stringhe `Y-m-d` calcolate in UTC, così l'ora legale
	 * non salta né raddoppia giorni.
	 *
	 * @param string      $today     Oggi (Y-m-d, fuso del sito).
	 * @param string|null $first_day Primo giorno con visite grezze (Y-m-d), null se non ce ne sono.
	 * @param int         $n         Giorni richiesti (almeno 1).
	 * @return array<int,string> Giorni in ordine crescente.
	 */
	public static function days( string $today, ?string $first_day, int $n ): array {
		// Passo 1: senza visite non c'è storico.
		if ( null === $first_day || $n < 1 ) {
			return array();
		}

		// Passo 2: primo giorno utile = il maggiore tra «N giorni fa» e il giorno dopo il primo di raccolta.
		$start = max( self::shift( $today, -$n ), self::shift( $first_day, 1 ) );
		$end   = self::shift( $today, -1 );

		// Passo 3: tutti i giorni da $start a ieri.
		$days = array();
		for ( $day = $start; $day <= $end; $day = self::shift( $day, 1 ) ) {
			$days[] = $day;
		}

		return $days;
	}

	/**
	 * Media dei valori sui giorni dati; un giorno senza valore conta zero (nessuna visita).
	 *
	 * @param array<string,int|float> $by_day Valore per giorno (Y-m-d).
	 * @param array<int,string>       $days   Giorni della media.
	 * @return float|null Null se non ci sono giorni.
	 */
	public static function mean( array $by_day, array $days ): ?float {
		if ( array() === $days ) {
			return null;
		}

		$sum = 0.0;
		foreach ( $days as $day ) {
			$sum += (float) ( $by_day[ $day ] ?? 0 );
		}

		return $sum / count( $days );
	}

	/**
	 * Valori orari cumulati: per ogni ora da 0 a 23 il totale dall'inizio della giornata.
	 *
	 * Le ore dopo `$last_hour` (la giornata in corso non è ancora arrivata) valgono null, così la linea del grafico si ferma invece di restare piatta.
	 *
	 * @param array<int,int> $by_hour   Valore per ora locale (0-23); le ore mancanti valgono zero.
	 * @param int|null       $last_hour Ultima ora da mostrare, null per tutte e 24.
	 * @return array<int,int|null> Sempre 24 valori, indicizzati dall'ora.
	 */
	public static function cumulate( array $by_hour, ?int $last_hour = null ): array {
		$total  = 0;
		$result = array();

		for ( $hour = 0; $hour < 24; $hour++ ) {
			$total += (int) ( $by_hour[ $hour ] ?? 0 );
			// Ore future: nessun valore.
			$result[ $hour ] = null !== $last_hour && $hour > $last_hour ? null : $total;
		}

		return $result;
	}

	/**
	 * Sposta un giorno Y-m-d di un numero di giorni.
	 *
	 * @param string $day  Giorno.
	 * @param int    $days Giorni da aggiungere (anche negativi).
	 * @return string
	 */
	private static function shift( string $day, int $days ): string {
		return ( new \DateTimeImmutable( $day, new \DateTimeZone( 'UTC' ) ) )->modify( sprintf( '%+d days', $days ) )->format( 'Y-m-d' );
	}
}
