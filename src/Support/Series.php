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
	 * I campi in `$nullable` restano `null` dove il giorno non ha nessuna riga (o la riga non ha il campo): servono alle serie
	 * di strumenti che non c'erano ancora, che in un grafico non devono scendere a zero.
	 *
	 * @param DateRange                                $range    Intervallo.
	 * @param array<string,array<string,mixed>|object> $rows     Righe indicizzate per giorno (Y-m-d).
	 * @param array<int,string>                        $fields   Campi numerici da copiare.
	 * @param array<int,string>                        $nullable Campi che restano null dove manca il dato.
	 * @return array<int,array<string,int|string|null>> Punti { day, ...fields }.
	 */
	public static function fill( DateRange $range, array $rows, array $fields, array $nullable = array() ): array {
		$points = array();
		$day    = new \DateTimeImmutable( $range->from );
		$end    = new \DateTimeImmutable( $range->to );

		while ( $day <= $end ) {
			$key   = $day->format( 'Y-m-d' );
			$row   = isset( $rows[ $key ] ) ? (array) $rows[ $key ] : array();
			$point = array( 'day' => $key );
			foreach ( $fields as $field ) {
				$point[ $field ] = isset( $row[ $field ] ) ? (int) $row[ $field ] : ( in_array( $field, $nullable, true ) ? null : 0 );
			}
			$points[] = $point;
			$day      = $day->modify( '+1 day' );
		}

		return $points;
	}
}
