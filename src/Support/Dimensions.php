<?php
/**
 * Raggruppamento dei valori di una dimensione (paese, dispositivo, browser, sistema) per il box «Pubblico».
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Unisce i valori «non riconosciuto», ordina per visitatori e tiene i primi N più una riga «Altri».
 */
final class Dimensions {

	/**
	 * Nomi delle dimensioni in `dim_daily` (il sistema operativo si chiama `platform`, come nell'ETL di WP Statistics).
	 *
	 * @var array<int,string>
	 */
	public const DIMS = array( 'country', 'device', 'browser', 'platform' );

	/**
	 * Valore di un dato non riconosciuto.
	 *
	 * @var string
	 */
	public const UNKNOWN = '(n/d)';

	/**
	 * Righe mostrate per dimensione.
	 *
	 * @var int
	 */
	public const LIMIT = 10;

	/**
	 * Le prime righe di una dimensione.
	 *
	 * Il valore vuoto e `UNK` (il codice interno di device-detector) valgono come `(n/d)`. A parità di visitatori
	 * l'ordine è quello binario del valore, che coincide con `ORDER BY ..., BINARY value` in SQL.
	 *
	 * @param array<string,int> $values Visitatori per valore.
	 * @param int               $limit  Righe da restituire (le altre vanno in `other`).
	 * @return array{total:int,rows:array<int,array{value:string,visitors:int}>,other:int}
	 */
	public static function top( array $values, int $limit = self::LIMIT ): array {
		// Passo 1: i valori «non riconosciuti» si sommano in una sola riga.
		$merged = array();
		foreach ( $values as $value => $visitors ) {
			$value            = (string) $value;
			$value            = '' === $value || 'UNK' === $value ? self::UNKNOWN : $value;
			$merged[ $value ] = ( $merged[ $value ] ?? 0 ) + $visitors;
		}

		// Passo 2: visitatori decrescenti, poi valore in ordine binario (le chiavi numeriche diventano int: si riportano a stringa).
		$rows = array();
		foreach ( $merged as $value => $visitors ) {
			$rows[] = array(
				'value'    => (string) $value,
				'visitors' => $visitors,
			);
		}
		usort(
			$rows,
			static fn( array $a, array $b ): int => $a['visitors'] === $b['visitors'] ? strcmp( $a['value'], $b['value'] ) : $b['visitors'] <=> $a['visitors']
		);

		// Passo 3: totale, prime righe e somma delle escluse.
		$total = array_sum( array_column( $rows, 'visitors' ) );
		$kept  = array_slice( $rows, 0, max( 0, $limit ) );

		return array(
			'total' => $total,
			'rows'  => $kept,
			'other' => $total - array_sum( array_column( $kept, 'visitors' ) ),
		);
	}
}
