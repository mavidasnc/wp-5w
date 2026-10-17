<?php
/**
 * Calcoli puri dell'import da Search Console: giorni mancanti, lotti, URL relativi e scelta della proprietà.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Funzioni di supporto di Gsc\Client e Etl\GscApiImport.
 */
final class GscDays {

	/**
	 * Mesi di storico che Search Console conserva.
	 *
	 * @var int
	 */
	public const RETENTION_MONTHS = 16;

	/**
	 * Giorni di un lotto: una richiesta all'API copre al massimo questo intervallo.
	 *
	 * @var int
	 */
	public const BATCH_DAYS = 30;

	/**
	 * Finestra dei dati disponibili: da 16 mesi fa (oggi compreso nel conto) all'ultimo giorno definitivo.
	 * Null se l'ultimo giorno definitivo precede l'inizio della finestra.
	 *
	 * @param string $today      Oggi (Y-m-d), nel fuso del sito.
	 * @param string $last_final Ultimo giorno con dati definitivi (Y-m-d).
	 * @return DateRange|null
	 */
	public static function window( string $today, string $last_final ): ?DateRange {
		$now = new \DateTimeImmutable( $today );

		// Si parte dal primo del mese e si aggiunge il giorno del mese: il 31 non fa slittare il mese (come in Months::first_day).
		$month  = $now->modify( 'first day of this month' )->modify( '-' . self::RETENTION_MONTHS . ' months' );
		$offset = min( (int) $now->format( 'j' ), (int) $month->format( 't' ) ) - 1;
		$from   = $month->modify( "+{$offset} days" )->modify( '+1 day' )->format( 'Y-m-d' );

		return $from <= $last_final ? new DateRange( $from, $last_final ) : null;
	}

	/**
	 * Giorni della finestra da importare: senza righe dell'API e non già risultati vuoti.
	 *
	 * @param DateRange         $window     Finestra dei dati disponibili.
	 * @param array<int,string> $api_days   Giorni che hanno già righe di origine «api».
	 * @param array<int,string> $empty_days Giorni per cui l'API ha risposto senza righe.
	 * @return array<int,string> Giorni (Y-m-d) in ordine crescente.
	 */
	public static function missing( DateRange $window, array $api_days, array $empty_days ): array {
		$skip    = array_flip( array_merge( $api_days, $empty_days ) );
		$missing = array();
		$cursor  = new \DateTimeImmutable( $window->from );
		$end     = new \DateTimeImmutable( $window->to );

		while ( $cursor <= $end ) {
			$day = $cursor->format( 'Y-m-d' );
			if ( ! isset( $skip[ $day ] ) ) {
				$missing[] = $day;
			}
			$cursor = $cursor->modify( '+1 day' );
		}

		return $missing;
	}

	/**
	 * Intervallo del prossimo lotto: dal primo giorno mancante, per al massimo $span giorni di calendario
	 * (si chiude sull'ultimo giorno mancante che ci sta). I giorni già importati dentro l'intervallo
	 * ci possono stare: chi importa salta quelli che non sono in $missing.
	 *
	 * @param array<int,string> $missing Giorni mancanti in ordine crescente.
	 * @param int               $span    Giorni di calendario del lotto.
	 * @return DateRange|null Null se non manca nulla.
	 */
	public static function next_batch( array $missing, int $span = self::BATCH_DAYS ): ?DateRange {
		if ( array() === $missing ) {
			return null;
		}

		$first = $missing[0];
		$limit = ( new \DateTimeImmutable( $first ) )->modify( '+' . ( max( 1, $span ) - 1 ) . ' days' )->format( 'Y-m-d' );
		$last  = $first;
		foreach ( $missing as $day ) {
			if ( $day > $limit ) {
				break;
			}
			$last = $day;
		}

		return new DateRange( $first, $last );
	}

	/**
	 * Giorni mancanti compresi nell'intervallo di un lotto.
	 *
	 * @param array<int,string> $missing Giorni mancanti.
	 * @param DateRange         $batch   Intervallo del lotto.
	 * @return array<int,string>
	 */
	public static function in_batch( array $missing, DateRange $batch ): array {
		return array_values(
			array_filter(
				$missing,
				static fn( string $day ): bool => $day >= $batch->from && $day <= $batch->to
			)
		);
	}

	/**
	 * Da URL completo a percorso relativo con l'eventuale query string, come lo salva Rank Math e come lo scriveva
	 * scripts/gsc-backfill.py: niente host e niente #frammento (due URL che differiscono solo per il frammento
	 * diventano la stessa pagina, e GscFileImport::replace_day() somma le loro righe).
	 *
	 * @param string $url URL come lo restituisce l'API.
	 * @return string Percorso, «/» se vuoto; stringa vuota se l'URL non è leggibile.
	 */
	public static function relative_path( string $url ): string {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Classe pura, senza WordPress.
		if ( false === $parts ) {
			return '';
		}

		$path = $parts['path'] ?? '';
		$path = '' === $path ? '/' : $path;
		return isset( $parts['query'] ) && '' !== $parts['query'] ? $path . '?' . $parts['query'] : $path;
	}

	/**
	 * Host di un URL in minuscolo, stringa vuota se manca.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function host( string $url ): string {
		$host = parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Classe pura, senza WordPress.
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Righe dell'API (chiavi data, pagina, query) raggruppate per giorno nel formato di GscFileImport::replace_day().
	 *
	 * Con una proprietà di dominio («sc-domain:») l'API restituisce anche i sottodomini e gli altri siti dello stesso
	 * dominio: passando $host si tengono solo le pagine di quell'host, altrimenti «/» e «/blog/» di siti diversi
	 * finirebbero nella stessa riga una volta tolto l'host.
	 *
	 * @param array<int,array<string,mixed>> $rows Righe dell'API (`keys` = [data, pagina, query]).
	 * @param string|null                    $host Host del sito da tenere, null per tenere tutto.
	 * @param array<int,string>|null         $only Giorni da tenere, null per tenerli tutti.
	 * @return array<string,array<int,array<string,mixed>>> Giorno => righe.
	 */
	public static function group( array $rows, ?string $host = null, ?array $only = null ): array {
		$keep = null === $only ? null : array_flip( $only );
		$days = array();

		foreach ( $rows as $row ) {
			$keys = $row['keys'] ?? null;
			if ( ! is_array( $keys ) || count( $keys ) < 3 ) {
				continue;
			}
			list( $day, $url, $query ) = array_map( 'strval', array_values( $keys ) );

			if ( null !== $keep && ! isset( $keep[ $day ] ) ) {
				continue;
			}
			if ( null !== $host && self::host( $url ) !== strtolower( $host ) ) {
				continue;
			}
			$page = self::relative_path( $url );
			if ( '' === $page ) {
				continue;
			}

			$days[ $day ][] = array(
				'day'         => $day,
				'page'        => $page,
				'query'       => $query,
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'position'    => (float) ( $row['position'] ?? 0 ),
			);
		}

		ksort( $days );
		return $days;
	}

	/**
	 * Proprietà di Search Console da usare per il sito: prima il prefisso URL che coincide con la home,
	 * poi la proprietà di dominio che contiene l'host. Null se nessuna corrisponde.
	 *
	 * @param array<int,string> $sites Proprietà dell'utente (`siteUrl`).
	 * @param string            $home  URL della home (home_url( '/' )).
	 * @return string|null
	 */
	public static function pick_site( array $sites, string $home ): ?string {
		$home = rtrim( strtolower( $home ), '/' ) . '/';
		foreach ( $sites as $site ) {
			if ( strtolower( $site ) === $home ) {
				return $site;
			}
		}

		$host = self::host( $home );
		foreach ( $sites as $site ) {
			if ( ! str_starts_with( $site, 'sc-domain:' ) ) {
				continue;
			}
			$domain = strtolower( substr( $site, strlen( 'sc-domain:' ) ) );
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return $site;
			}
		}

		return null;
	}

	/**
	 * Host con cui filtrare le righe: quello della home se la proprietà è di dominio, altrimenti null
	 * (un prefisso URL restituisce già solo le pagine di quel prefisso).
	 *
	 * @param string $site Proprietà scelta.
	 * @param string $home URL della home.
	 * @return string|null
	 */
	public static function host_filter( string $site, string $home ): ?string {
		return str_starts_with( $site, 'sc-domain:' ) ? self::host( $home ) : null;
	}
}
