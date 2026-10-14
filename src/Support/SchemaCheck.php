<?php
/**
 * Confronto tra le colonne richieste e quelle presenti nelle tabelle delle sorgenti.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Verifica che le tabelle di altri plugin abbiano lo schema atteso prima di leggerle.
 */
final class SchemaCheck {

	/**
	 * Elenca ciò che manca rispetto ai requisiti.
	 *
	 * @param array<string,array<int,string>>      $required Tabella => colonne richieste.
	 * @param array<string,array<int,string>|null> $actual   Tabella => colonne presenti (null se la tabella non esiste).
	 * @return array<int,string> Messaggi leggibili, vuoto se tutto è a posto.
	 */
	public static function missing( array $required, array $actual ): array {
		$missing = array();

		foreach ( $required as $table => $columns ) {
			$present = $actual[ $table ] ?? null;

			// Tabella assente: inutile elencare le singole colonne.
			if ( null === $present ) {
				$missing[] = "tabella {$table}";
				continue;
			}

			// Il confronto ignora maiuscole e minuscole, come MySQL.
			$present = array_map( 'strtolower', $present );
			foreach ( $columns as $column ) {
				if ( ! in_array( strtolower( $column ), $present, true ) ) {
					$missing[] = "{$table}.{$column}";
				}
			}
		}

		return $missing;
	}
}
