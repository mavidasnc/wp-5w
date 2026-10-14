<?php
/**
 * Regola dell'origine dei dati aggregati (WP Statistics o raccolta propria).
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Per ogni giorno le tabelle aggregate hanno al massimo una origine valida:
 * `wps` (importata da WP Statistics) prima della data di passaggio, `own` (raccolta propria) da quella data in poi.
 */
final class Origin {

	/**
	 * Tabelle aggregate (nomi brevi) che hanno la colonna origin. gsc_daily è esclusa: la sua origin ha un altro significato.
	 *
	 * @var array<int,string>
	 */
	public const TABLES = array( 'site_daily', 'object_daily', 'entry_daily', 'referrer_daily', 'dim_daily' );

	/**
	 * Frammento SQL (senza segnaposto) che seleziona le righe valide per la data di passaggio.
	 *
	 * La data è letterale, ma solo dopo la validazione con DateRange::is_valid(): una stringa Y-m-d valida
	 * non può contenere apici. Se la data è vuota o non valida si usa solo `wps`.
	 *
	 * @param string $own_since Data di passaggio alla raccolta propria (Y-m-d) oppure stringa vuota.
	 * @param string $alias     Alias della tabella nella query, senza punto (vuoto se la query non ne ha).
	 * @return string
	 */
	public static function where( string $own_since, string $alias = '' ): string {
		// Prefisso delle colonne: con l'alias "o." davanti a origin e day, senza alias nessun prefisso.
		$prefix = '' === $alias ? '' : $alias . '.';

		// Nessun passaggio (o data non valida): le interrogazioni leggono solo WP Statistics.
		if ( '' === $own_since || ! DateRange::is_valid( $own_since ) ) {
			return "{$prefix}origin = 'wps'";
		}

		// Passaggio: wps prima della data, own dalla data in poi.
		return "( ( {$prefix}origin = 'wps' AND {$prefix}day < '{$own_since}' ) OR ( {$prefix}origin = 'own' AND {$prefix}day >= '{$own_since}' ) )";
	}
}
