<?php
/**
 * Hash dell'IP e user agent salvati con le visite della raccolta propria (schema v7) e loro conservazione.
 *
 * Classe pura: la chiave arriva dal chiamante (HitController la ricava da wp_salt( 'auth' )), quindi è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * L'IP non si salva mai in chiaro: solo un HMAC-SHA256 troncato a 8 byte, con una chiave che non sta nel database.
 *
 * La chiave deriva dalle salt di wp-config.php: un dump del database da solo non permette di risalire agli IP
 * provando tutti gli IPv4 (2^32 valori), cosa che una chiave in wp_options renderebbe banale. Se le salt cambiano,
 * lo stesso IP dà un hash diverso: la continuità con le visite precedenti si perde (nessun dato si rompe).
 */
final class IpHash {

	/**
	 * Contesto della derivazione: la stessa salt usata per altri scopi (firma del tracker) dà una chiave diversa.
	 *
	 * @var string
	 */
	public const CONTEXT = 'wp5w-ip';

	/**
	 * Byte dell'hash salvato (binary(8)): 64 bit bastano a distinguere gli IP di un sito senza collisioni pratiche.
	 *
	 * @var int
	 */
	public const BYTES = 8;

	/**
	 * Caratteri massimi dello user agent salvato (varchar(255)).
	 *
	 * @var int
	 */
	public const UA_LENGTH = 255;

	/**
	 * Chiave binaria (32 byte) derivata dalla salt.
	 *
	 * @param string $salt Salt di WordPress (wp_salt( 'auth' )).
	 * @return string
	 */
	public static function key( string $salt ): string {
		return hash_hmac( 'sha256', self::CONTEXT, $salt, true );
	}

	/**
	 * Hash di un IP in esadecimale (16 caratteri), stringa vuota se l'IP non è valido.
	 *
	 * L'IP si normalizza prima (inet_pton/inet_ntop): «2001:DB8::1» e «2001:db8:0:0:0:0:0:1» danno lo stesso hash.
	 *
	 * @param string $ip  Indirizzo IP (REMOTE_ADDR).
	 * @param string $key Chiave di key().
	 * @return string
	 */
	public static function hash( string $ip, string $key ): string {
		$packed = false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? inet_pton( $ip ) : false;
		if ( false === $packed ) {
			return '';
		}
		return bin2hex( substr( hash_hmac( 'sha256', (string) inet_ntop( $packed ), $key, true ), 0, self::BYTES ) );
	}

	/**
	 * User agent da salvare: al massimo UA_LENGTH caratteri (non byte, così una sequenza UTF-8 non si spezza).
	 *
	 * @param string $ua User agent della richiesta.
	 * @return string
	 */
	public static function user_agent( string $ua ): string {
		return mb_substr( trim( $ua ), 0, self::UA_LENGTH );
	}

	/**
	 * Primo giorno che conserva hash dell'IP e user agent: con N giorni restano oggi e gli N - 1 giorni precedenti.
	 *
	 * Date calcolate in UTC su stringhe Y-m-d, così l'ora legale non salta né raddoppia giorni.
	 *
	 * @param string $today Oggi (Y-m-d, giorno del sito).
	 * @param int    $days  Giorni di conservazione (almeno 1).
	 * @return string
	 */
	public static function first_kept_day( string $today, int $days ): string {
		$date = new \DateTimeImmutable( $today, new \DateTimeZone( 'UTC' ) );
		return $date->modify( '-' . ( max( 1, $days ) - 1 ) . ' days' )->format( 'Y-m-d' );
	}
}
