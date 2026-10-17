<?php
/**
 * Cifratura simmetrica dei segreti salvati nelle opzioni (client secret e refresh token di Google).
 *
 * Classe pura: la chiave arriva dal chiamante (Gsc\Client la ricava dalle salt di WordPress), quindi è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Cifra con sodium_crypto_secretbox (XSalsa20-Poly1305): un dump del database senza wp-config.php non rivela i segreti.
 */
final class Secret {

	/**
	 * Cifra un testo.
	 *
	 * @param string $plain Testo in chiaro.
	 * @param string $key   Chiave binaria di SODIUM_CRYPTO_SECRETBOX_KEYBYTES byte.
	 * @return string Nonce e testo cifrato in base64.
	 */
	public static function encrypt( string $plain, string $key ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Non è offuscamento: serve a salvare byte binari in un'opzione.
	}

	/**
	 * Decifra un testo.
	 *
	 * @param string $encoded Valore restituito da encrypt().
	 * @param string $key     Chiave usata per cifrare.
	 * @return string|null Testo in chiaro, null se il valore è danneggiato o la chiave è cambiata.
	 */
	public static function decrypt( string $encoded, string $key ): ?string {
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Vedi encrypt().
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$key
		);
		return false === $plain ? null : $plain;
	}
}
