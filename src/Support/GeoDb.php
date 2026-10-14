<?php
/**
 * Regole pure del database dei paesi (DB-IP Lite Country): indirizzi, mesi da provare e controlli di integrità.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari. Il download e la lettura
 * stanno in `Geo\CountryDatabase`.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Indirizzo mensile, scelta dei mesi, codice del paese di un record e controlli di dimensione e metadati.
 */
final class GeoDb {

	/**
	 * Indirizzo del file mensile compresso (il segnaposto è il mese Y-m).
	 *
	 * @var string
	 */
	public const URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.mmdb.gz';

	/**
	 * Dimensione minima del database decompresso (1 MB).
	 *
	 * @var int
	 */
	public const MIN_SIZE = 1048576;

	/**
	 * Dimensione massima del database decompresso (64 MB).
	 *
	 * @var int
	 */
	public const MAX_SIZE = 67108864;

	/**
	 * Età massima del database, in giorni dalla compilazione.
	 *
	 * @var int
	 */
	public const MAX_AGE_DAYS = 100;

	/**
	 * Secondi in un giorno (la classe è pura, quindi senza le costanti di WordPress).
	 *
	 * @var int
	 */
	private const DAY = 86400;

	/**
	 * Indirizzo del file di un mese.
	 *
	 * @param string $month Mese in formato Y-m.
	 * @return string
	 */
	public static function url( string $month ): string {
		return sprintf( self::URL, $month );
	}

	/**
	 * Mesi da scaricare, nell'ordine in cui provarli.
	 *
	 * Il file del mese corrente esce nelle prime ore del mese (o qualche giorno dopo): se non c'è ancora
	 * e non si ha nessun database, si ripiega sul mese precedente.
	 *
	 * @param string|null $installed Mese del file installato (Y-m) oppure null se non c'è.
	 * @param string      $today_utc Oggi in UTC (Y-m-d).
	 * @return array<int,string>
	 */
	public static function candidates( ?string $installed, string $today_utc ): array {
		// Passo 1: mese corrente e mese precedente (anche a cavallo dell'anno).
		$current  = substr( $today_utc, 0, 7 );
		$previous = ( new \DateTimeImmutable( $current . '-01' ) )->modify( '-1 month' )->format( 'Y-m' );

		// Passo 2: file già del mese corrente, niente da fare.
		if ( $installed === $current ) {
			return array();
		}

		// Passo 3: il mese corrente, più il precedente se non c'è nessun file o se quello installato è più vecchio.
		$months = array( $current );
		if ( null === $installed || $installed < $previous ) {
			$months[] = $previous;
		}

		return $months;
	}

	/**
	 * Codice ISO del paese di un record del database (`country.iso_code`), oppure stringa vuota.
	 *
	 * @param mixed $record Record restituito dal lettore (array o null).
	 * @return string
	 */
	public static function country( mixed $record ): string {
		if ( ! is_array( $record ) || ! is_array( $record['country'] ?? null ) || ! is_string( $record['country']['iso_code'] ?? null ) ) {
			return '';
		}

		// Due lettere, e "ZZ" (paese sconosciuto) non è un paese.
		$code = strtoupper( $record['country']['iso_code'] );
		return 1 === preg_match( '/^[A-Z]{2}$/', $code ) && 'ZZ' !== $code ? $code : '';
	}

	/**
	 * Vero se la dimensione del database decompresso è plausibile.
	 *
	 * @param int $bytes Byte scritti.
	 * @return bool
	 */
	public static function size_ok( int $bytes ): bool {
		return $bytes >= self::MIN_SIZE && $bytes <= self::MAX_SIZE;
	}

	/**
	 * Vero se i byte decompressi coincidono con la dimensione dichiarata dal gzip (ISIZE, modulo 2^32).
	 *
	 * @param int    $written Byte scritti dalla decompressione.
	 * @param string $tail    Ultimi 4 byte del file .gz.
	 * @return bool
	 */
	public static function isize_ok( int $written, string $tail ): bool {
		if ( 4 !== strlen( $tail ) ) {
			return false;
		}

		$unpacked = unpack( 'V', $tail );
		return is_array( $unpacked ) && $unpacked[1] === $written % ( 2 ** 32 );
	}

	/**
	 * Vero se i metadati descrivono un database dei paesi recente e con indirizzi IPv6 (che contengono anche gli IPv4).
	 *
	 * Il tipo contiene "Country" senza distinzione di maiuscole, così vale anche per `GeoLite2-Country`
	 * e la sorgente si può cambiare.
	 *
	 * @param string $type        Tipo del database (`databaseType`).
	 * @param int    $ip_version  Versione degli indirizzi (`ipVersion`).
	 * @param int    $build_epoch Data di compilazione (`buildEpoch`).
	 * @param int    $now         Ora attuale (timestamp UNIX).
	 * @return bool
	 */
	public static function metadata_ok( string $type, int $ip_version, int $build_epoch, int $now ): bool {
		return false !== stripos( $type, 'Country' )
			&& 6 === $ip_version
			&& $build_epoch >= $now - self::MAX_AGE_DAYS * self::DAY
			&& $build_epoch <= $now + 2 * self::DAY;
	}
}
