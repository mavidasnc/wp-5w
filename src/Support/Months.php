<?php
/**
 * Raggruppamento dei totali giornalieri per mese.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Somme e medie al giorno per mese solare.
 */
final class Months {

	/**
	 * Primo giorno del mese che apre una finestra di $count mesi terminante con il mese di $today.
	 *
	 * @param string $today Giorno di riferimento (Y-m-d).
	 * @param int    $count Numero di mesi, compreso quello di $today.
	 * @return string Y-m-d
	 */
	public static function first_day( string $today, int $count ): string {
		// "first day of" evita lo slittamento di -N months sui giorni 29-31.
		return ( new \DateTimeImmutable( $today ) )->modify( 'first day of -' . ( $count - 1 ) . ' months' )->format( 'Y-m-d' );
	}

	/**
	 * Raggruppa righe giornaliere per mese (Y-m), con un elemento per ogni mese anche senza dati.
	 *
	 * La media si calcola sui giorni presenti nelle righe: un giorno senza dati non abbassa la media.
	 *
	 * @param array<int,array{day:string,visitors:int|string,views:int|string}> $rows      Righe { day, visitors, views }.
	 * @param string                                                            $first_day Primo giorno del primo mese (Y-m-d).
	 * @param int                                                               $count     Numero di mesi.
	 * @return array<int,array{month:string,days:int,visitors:int,views:int,avg_visitors:float,avg_views:float}>
	 */
	public static function group( array $rows, string $first_day, int $count ): array {
		// Mesi della finestra in ordine cronologico, tutti a zero.
		$groups = array();
		$month  = new \DateTimeImmutable( substr( $first_day, 0, 7 ) . '-01' );
		for ( $i = 0; $i < $count; $i++ ) {
			$key            = $month->format( 'Y-m' );
			$groups[ $key ] = array(
				'month'        => $key,
				'days'         => 0,
				'visitors'     => 0,
				'views'        => 0,
				'avg_visitors' => 0.0,
				'avg_views'    => 0.0,
			);
			$month          = $month->modify( '+1 month' );
		}

		// Somme per mese; le righe fuori finestra si ignorano.
		foreach ( $rows as $row ) {
			$key = substr( $row['day'], 0, 7 );
			if ( ! isset( $groups[ $key ] ) ) {
				continue;
			}
			++$groups[ $key ]['days'];
			$groups[ $key ]['visitors'] += (int) $row['visitors'];
			$groups[ $key ]['views']    += (int) $row['views'];
		}

		// Medie sui giorni con dati.
		foreach ( $groups as &$group ) {
			if ( $group['days'] > 0 ) {
				$group['avg_visitors'] = round( $group['visitors'] / $group['days'], 1 );
				$group['avg_views']    = round( $group['views'] / $group['days'], 1 );
			}
		}
		unset( $group );

		return array_values( $groups );
	}
}
