<?php
/**
 * Raggruppamento dei totali giornalieri per giorno della settimana.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Somme e medie per lunedì…domenica.
 */
final class Weekdays {

	/**
	 * Raggruppa righe giornaliere per giorno della settimana (1 = lunedì … 7 = domenica, ISO-8601).
	 *
	 * La media si calcola sui giorni presenti nelle righe: un giorno senza dati non abbassa la media.
	 *
	 * @param array<int,array{day:string,visitors:int|string,views:int|string}> $rows Righe { day, visitors, views }.
	 * @return array<int,array{weekday:int,days:int,visitors:int,views:int,avg_visitors:float,avg_views:float}>
	 */
	public static function group( array $rows ): array {
		$groups = array();
		for ( $weekday = 1; $weekday <= 7; $weekday++ ) {
			$groups[ $weekday ] = array(
				'weekday'      => $weekday,
				'days'         => 0,
				'visitors'     => 0,
				'views'        => 0,
				'avg_visitors' => 0.0,
				'avg_views'    => 0.0,
			);
		}

		foreach ( $rows as $row ) {
			$weekday = (int) ( new \DateTimeImmutable( $row['day'] ) )->format( 'N' );
			++$groups[ $weekday ]['days'];
			$groups[ $weekday ]['visitors'] += (int) $row['visitors'];
			$groups[ $weekday ]['views']    += (int) $row['views'];
		}

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
