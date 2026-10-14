<?php
/**
 * Metriche derivate (CTR, posizione media pesata, variazioni).
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Calcoli condivisi tra interrogazioni e REST, così le regole sono in un solo punto.
 */
final class Metrics {

	/**
	 * Click-through rate in percentuale, null senza impression.
	 *
	 * @param int $clicks      Click.
	 * @param int $impressions Impression.
	 * @return float|null
	 */
	public static function ctr( int $clicks, int $impressions ): ?float {
		return $impressions > 0 ? round( $clicks / $impressions * 100, 2 ) : null;
	}

	/**
	 * Posizione media pesata sulle impression (come la calcola Search Console), null senza impression.
	 *
	 * Una media semplice delle posizioni darebbe lo stesso peso a una query vista una volta e a una
	 * vista mille volte: per questo l'ETL salva position * impressions in pos_x_impr.
	 *
	 * @param float $pos_x_impr  Somma di position * impressions.
	 * @param int   $impressions Somma delle impression.
	 * @return float|null
	 */
	public static function weighted_position( float $pos_x_impr, int $impressions ): ?float {
		return $impressions > 0 ? round( $pos_x_impr / $impressions, 1 ) : null;
	}

	/**
	 * Variazione percentuale rispetto al periodo precedente, null se il precedente è zero.
	 *
	 * @param float|int $current  Valore attuale.
	 * @param float|int $previous Valore precedente.
	 * @return float|null
	 */
	public static function change( float|int $current, float|int $previous ): ?float {
		if ( 0 == $previous ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Confronto voluto tra int e float.
			return null;
		}
		return round( ( $current - $previous ) / $previous * 100, 1 );
	}

	/**
	 * KPI con valore attuale, precedente e variazione percentuale.
	 *
	 * @param array<string,int|float|null> $current  Valori del periodo.
	 * @param array<string,int|float|null> $previous Valori del periodo precedente.
	 * @return array<string,array{value:int|float|null,previous:int|float|null,change:float|null}>
	 */
	public static function compare( array $current, array $previous ): array {
		$kpis = array();
		foreach ( $current as $key => $value ) {
			$before       = $previous[ $key ] ?? null;
			$kpis[ $key ] = array(
				'value'    => $value,
				'previous' => $before,
				'change'   => null === $value || null === $before ? null : self::change( $value, $before ),
			);
		}
		return $kpis;
	}
}
