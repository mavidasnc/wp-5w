<?php
/**
 * Regole della singola visita: hash del visitatore, firma, esclusioni e ingresso.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Funzioni senza effetti collaterali usate dall'endpoint e dal tracker.
 */
final class Visit {

	/**
	 * Hash del visitatore: md5 grezzo (16 byte) di IP, user agent e chiave del giorno.
	 * Con la chiave che cambia ogni giorno nessuno può collegare le visite di giorni diversi.
	 *
	 * @param string $ip  Indirizzo IP (non viene mai salvato).
	 * @param string $ua  User agent (non viene mai salvato).
	 * @param string $key Chiave del giorno.
	 * @return string 16 byte binari.
	 */
	public static function visitor_hash( string $ip, string $ua, string $key ): string {
		return md5( $ip . $ua . $key, true );
	}

	/**
	 * Firma HMAC di tipo e ID del contenuto: non scade, quindi resta valida nelle pagine in cache.
	 *
	 * @param string $type Tipo di contenuto.
	 * @param int    $id   ID del contenuto.
	 * @param string $key  Chiave segreta (wp_salt( 'auth' )).
	 * @return string 32 caratteri esadecimali.
	 */
	public static function signature( string $type, int $id, string $key ): string {
		return substr( hash_hmac( 'sha256', "{$type}|{$id}", $key ), 0, 32 );
	}

	/**
	 * Confronta la firma ricevuta con quella attesa, in tempo costante.
	 *
	 * @param string $type      Tipo di contenuto.
	 * @param int    $id        ID del contenuto.
	 * @param string $signature Firma ricevuta.
	 * @param string $key       Chiave segreta.
	 * @return bool
	 */
	public static function check_signature( string $type, int $id, string $signature, string $key ): bool {
		return '' !== $signature && hash_equals( self::signature( $type, $id, $key ), $signature );
	}

	/**
	 * Vero se la visita va scartata in base all'utente collegato.
	 *
	 * @param string $mode      Modalità: admins, logged_in o none.
	 * @param bool   $logged_in L'utente è collegato.
	 * @param bool   $is_admin  L'utente ha manage_options.
	 * @return bool
	 */
	public static function user_excluded( string $mode, bool $logged_in, bool $is_admin ): bool {
		return match ( $mode ) {
			'logged_in' => $logged_in,
			'none'      => false,
			// Modalità predefinita (e valore sconosciuto): solo gli amministratori.
			default     => $logged_in && $is_admin,
		};
	}

	/**
	 * Vero se la regola è un IP (v4 o v6) oppure un intervallo CIDR valido.
	 *
	 * @param string $rule Regola (es. 203.0.113.7, 203.0.113.0/24, 2001:db8::/64).
	 * @return bool
	 */
	public static function is_valid_ip_rule( string $rule ): bool {
		return null !== self::parse_rule( $rule );
	}

	/**
	 * Vero se l'IP rientra in almeno una regola. Le regole non valide si ignorano.
	 *
	 * @param string            $ip    Indirizzo IP del visitatore.
	 * @param array<int,string> $rules Regole (IP singoli o CIDR).
	 * @return bool
	 */
	public static function ip_excluded( string $ip, array $rules ): bool {
		$packed = false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? inet_pton( $ip ) : false;
		if ( false === $packed ) {
			return false;
		}

		foreach ( $rules as $rule ) {
			$parsed = self::parse_rule( (string) $rule );

			// Famiglie diverse (IPv4 contro IPv6) non si confrontano.
			if ( null === $parsed || strlen( $parsed['net'] ) !== strlen( $packed ) ) {
				continue;
			}

			if ( self::same_prefix( $packed, $parsed['net'], $parsed['bits'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Vero se la visita è un ingresso: prima del giorno oppure con un referrer esterno.
	 *
	 * @param int    $hits_today    Visite già registrate oggi dallo stesso visitatore.
	 * @param string $referrer_host Host del referrer (vuoto se interno o assente).
	 * @return bool
	 */
	public static function is_entry( int $hits_today, string $referrer_host ): bool {
		return 0 === $hits_today || '' !== $referrer_host;
	}

	/**
	 * Interpreta una regola: indirizzo in forma binaria e lunghezza del prefisso.
	 *
	 * @param string $rule Regola.
	 * @return array{net:string,bits:int}|null Null se non è valida.
	 */
	private static function parse_rule( string $rule ): ?array {
		$parts = explode( '/', trim( $rule ) );
		if ( count( $parts ) > 2 || false === filter_var( $parts[0], FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$net = inet_pton( $parts[0] );
		if ( false === $net ) {
			return null;
		}
		$max = strlen( $net ) * 8;

		// Senza prefisso vale l'indirizzo intero (/32 o /128).
		if ( 1 === count( $parts ) ) {
			return array(
				'net'  => $net,
				'bits' => $max,
			);
		}

		// Il prefisso è un numero senza zeri iniziali, da 0 al massimo della famiglia.
		if ( 1 !== preg_match( '/^(?:0|[1-9]\d{0,2})$/', $parts[1] ) || (int) $parts[1] > $max ) {
			return null;
		}

		return array(
			'net'  => $net,
			'bits' => (int) $parts[1],
		);
	}

	/**
	 * Confronta i primi $bits bit di due indirizzi in forma binaria.
	 *
	 * @param string $a    Primo indirizzo.
	 * @param string $b    Secondo indirizzo.
	 * @param int    $bits Bit da confrontare.
	 * @return bool
	 */
	private static function same_prefix( string $a, string $b, int $bits ): bool {
		// Byte interi.
		$bytes = intdiv( $bits, 8 );
		if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}

		// Bit residui del byte successivo.
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
	}
}
