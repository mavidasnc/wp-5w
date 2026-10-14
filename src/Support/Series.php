<?php
/**
 * Serie giornaliere per i grafici.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Trasforma righe sparse (solo i giorni con dati) in un punto per ogni giorno dell'intervallo.
 */
final class Series {

	/**
	 * Un punto per giorno, con zero dove mancano i dati, così i grafici non saltano i buchi.
	 *
	 * @param DateRange                                $range  Intervallo.
	 * @param array<string,array<string,mixed>|object> $rows Righe indicizzate per giorno (Y-m-d).
	 * @param array<int,string>                        $fields Campi numerici da copiare.
	 * @return array<int,array<string,int|string>> Punti { day, ...fields }.
	 */
	public static function fill( DateRange $range, array $rows, array $fields ): array {
		$points = array();
		$day    = new \DateTimeImmutable( $range->from );
		$end    = new \DateTimeImmutable( $range->to );

		while ( $day <= $end ) {
			$key   = $day->format( 'Y-m-d' );
			$row   = isset( $rows[ $key ] ) ? (array) $rows[ $key ] : array();
			$point = array( 'day' => $key );
			foreach ( $fields as $field ) {
				$point[ $field ] = (int) ( $row[ $field ] ?? 0 );
			}
			$points[] = $point;
			$day      = $day->modify( '+1 day' );
		}

		return $points;
	}
}
