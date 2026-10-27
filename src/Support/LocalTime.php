<?php
/**
 * Conversione da UTC al giorno del sito dentro una query SQL.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Independent Analytics salva gli orari in UTC. Per dare ogni visita al giorno del sito (come fanno le altre due origini)
 * servirebbe `CONVERT_TZ( ..., 'Europe/Rome' )`, che su MariaDB funziona solo con le tabelle dei fusi caricate:
 * qui lo scostamento si costruisce in PHP dalle transizioni del fuso e passa nella query come `CASE`, ora legale compresa.
 */
final class LocalTime {

	/**
	 * Transizioni del fuso nell'intervallo, nel formato che si aspetta shift_sql().
	 *
	 * Un fuso a scostamento fisso («+02:00») non ha transizioni e `DateTimeZone::getTransitions()` restituisce `false`:
	 * in quel caso basta un solo scostamento, quello valido alla fine dell'intervallo.
	 *
	 * @param \DateTimeZone $zone Fuso del sito.
	 * @param int           $from Inizio dell'intervallo (timestamp UNIX).
	 * @param int           $to   Fine dell'intervallo (timestamp UNIX).
	 * @return array<int,array{ts:int,offset:int}|array<mixed>>
	 */
	public static function transitions( \DateTimeZone $zone, int $from, int $to ): array {
		$transitions = $zone->getTransitions( $from, $to );

		if ( is_array( $transitions ) && array() !== $transitions ) {
			return $transitions;
		}

		return array(
			array(
				'ts'     => $from,
				'offset' => $zone->getOffset( new \DateTimeImmutable( '@' . $to ) ),
			),
		);
	}

	/**
	 * Espressione SQL con la colonna UTC spostata all'ora del sito.
	 *
	 * Le transizioni sono quelle di `DateTimeZone::getTransitions()`: la prima ha il `ts` dell'inizio dell'intervallo
	 * richiesto e dà lo scostamento iniziale, le altre i cambi (ora legale e solare).
	 *
	 * @param string                                           $column      Colonna (o espressione) datetime in UTC.
	 * @param array<int,array{ts:int,offset:int}|array<mixed>> $transitions Transizioni del fuso, in ordine di tempo.
	 * @return string
	 */
	public static function shift_sql( string $column, array $transitions ): string {
		// Senza transizioni (fuso non valido) la colonna resta in UTC.
		if ( array() === $transitions ) {
			return $column;
		}

		$transitions = array_values( $transitions );
		$first       = (int) $transitions[0]['offset'];

		// Passo 1: un solo scostamento, nessun CASE.
		if ( 1 === count( $transitions ) ) {
			return "{$column} + INTERVAL {$first} SECOND";
		}

		// Passo 2: dall'ultima transizione alla prima, così il CASE prende lo scostamento della più recente già passata.
		$cases = array();
		for ( $i = count( $transitions ) - 1; $i >= 1; $i-- ) {
			$at      = gmdate( 'Y-m-d H:i:s', (int) $transitions[ $i ]['ts'] );
			$cases[] = "WHEN {$column} >= '{$at}' THEN " . (int) $transitions[ $i ]['offset'];
		}

		return "{$column} + INTERVAL ( CASE " . implode( ' ', $cases ) . " ELSE {$first} END ) SECOND";
	}
}
