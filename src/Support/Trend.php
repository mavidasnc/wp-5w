<?php
/**
 * Indicatore di trend: ultime 4 settimane complete contro le 4 precedenti.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Punteggio da -100 a +100 ponderato su visite, visibilità su Google e posizione media.
 *
 * Per ogni componente si calcola una variazione, la si comprime con tanh (un picco isolato non domina)
 * e si fa la media pesata. Le componenti senza dati in entrambi i periodi si escludono e i pesi
 * delle altre si ridistribuiscono.
 */
final class Trend {

	/**
	 * Settimane di ciascun periodo.
	 *
	 * @var int
	 */
	public const WEEKS = 4;

	/**
	 * Pesi delle componenti.
	 *
	 * @var array<string,float>
	 */
	public const WEIGHTS = array(
		'visitors'   => 0.5,
		'visibility' => 0.25,
		'position'   => 0.25,
	);

	/**
	 * Sotto questa soglia di visitatori in entrambi i periodi il punteggio sarebbe solo rumore.
	 *
	 * @var int
	 */
	public const MIN_VISITORS = 20;

	/**
	 * Oltre questo punteggio (in valore assoluto) il trend non è più "stabile".
	 *
	 * @var int
	 */
	public const STABLE_BAND = 10;

	/**
	 * Fattore di compressione: con 2, un raddoppio (ln 2 = 0,69) vale circa 0,88.
	 *
	 * @var float
	 */
	private const STEEPNESS = 2.0;

	/**
	 * Periodo attuale (ultime settimane complete, lunedì-domenica) e precedente.
	 *
	 * @param string $last_complete_day Ultimo giorno con dati completi (Y-m-d): ieri per il traffico, l'ultimo giorno di Search Console.
	 * @param int    $weeks             Settimane per periodo.
	 * @return array{current:DateRange,previous:DateRange}
	 */
	public static function windows( string $last_complete_day, int $weeks = self::WEEKS ): array {
		// Domenica più recente non successiva all'ultimo giorno completo ('N': 7 = domenica).
		$day = new \DateTimeImmutable( $last_complete_day );
		$end = $day->modify( '-' . ( (int) $day->format( 'N' ) % 7 ) . ' days' );

		$current = DateRange::last_days( $weeks * 7, $end->format( 'Y-m-d' ) );
		return array(
			'current'  => $current,
			'previous' => $current->previous(),
		);
	}

	/**
	 * Punteggio di trend.
	 *
	 * @param array{visitors:int,visibility:int,position:float|null} $current  Valori del periodo attuale.
	 * @param array{visitors:int,visibility:int,position:float|null} $previous Valori del periodo precedente.
	 * @return array{score:int|null,status:string,components:array<string,array{current:int|float|null,previous:int|float|null,value:float|null}>}
	 */
	public static function score( array $current, array $previous ): array {
		$components = array(
			// Conteggi: logaritmo del rapporto, così +100% e -50% pesano uguale e i numeri piccoli non esplodono.
			'visitors'   => self::log_ratio( $current['visitors'], $previous['visitors'] ),
			// Visibilità: nessun dato in entrambi i periodi = componente esclusa, non "stabile".
			'visibility' => 0 === $current['visibility'] && 0 === $previous['visibility'] ? null : self::log_ratio( $current['visibility'], $previous['visibility'] ),
			// Posizione: scendere è un miglioramento, quindi (precedente - attuale) / precedente.
			'position'   => null === $current['position'] || null === $previous['position'] || $previous['position'] <= 0
				? null
				: ( $previous['position'] - $current['position'] ) / $previous['position'],
		);

		$details = array();
		$sum     = 0.0;
		$weights = 0.0;
		foreach ( $components as $key => $change ) {
			$value           = null === $change ? null : tanh( $change * self::STEEPNESS );
			$details[ $key ] = array(
				'current'  => $current[ $key ],
				'previous' => $previous[ $key ],
				'value'    => null === $value ? null : round( $value * 100, 1 ),
			);
			if ( null !== $value ) {
				$sum     += $value * self::WEIGHTS[ $key ];
				$weights += self::WEIGHTS[ $key ];
			}
		}

		// Troppe poche visite: il confronto non è affidabile.
		if ( $current['visitors'] < self::MIN_VISITORS && $previous['visitors'] < self::MIN_VISITORS ) {
			return array(
				'score'      => null,
				'status'     => 'insufficient',
				'components' => $details,
			);
		}

		$score = (int) round( $sum / $weights * 100 );
		return array(
			'score'      => $score,
			'status'     => $score > self::STABLE_BAND ? 'up' : ( $score < -self::STABLE_BAND ? 'down' : 'stable' ),
			'components' => $details,
		);
	}

	/**
	 * Logaritmo naturale del rapporto con +1 (gestisce gli zeri).
	 *
	 * @param int $current  Valore attuale.
	 * @param int $previous Valore precedente.
	 * @return float
	 */
	private static function log_ratio( int $current, int $previous ): float {
		return log( ( $current + 1 ) / ( $previous + 1 ) );
	}
}
