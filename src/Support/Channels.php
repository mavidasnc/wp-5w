<?php
/**
 * Classificazione del canale e della sorgente di una visita.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Riproduce i valori di WP Statistics (canali direct, search, social, referral, email, unassigned),
 * così il confronto tra le due raccolte misura la raccolta e non le differenze di classificazione.
 */
final class Channels {

	/**
	 * Motori di ricerca: nome => espressioni regolari sull'host (senza www.).
	 * Per google e yandex la regola copre i domini nazionali (google.it, google.co.uk, google.com.br).
	 *
	 * @var array<string,array<int,string>>
	 */
	private const SEARCH = array(
		'Google'     => array( '/^google\.(?:com?\.)?[a-z]{2,3}$/' ),
		'Bing'       => array( '/^bing\.com$/' ),
		'DuckDuckGo' => array( '/^duckduckgo\.com$/' ),
		'Yahoo'      => array( '/^(?:search\.)?yahoo\.com$/' ),
		'Brave'      => array( '/^search\.brave\.com$/' ),
		'ChatGPT'    => array( '/^chatgpt\.com$/' ),
		'Ecosia'     => array( '/^ecosia\.org$/' ),
		'Qwant'      => array( '/^qwant\.com$/' ),
		'Yandex'     => array( '/^yandex\.(?:com?\.)?[a-z]{2,3}$/' ),
		'Baidu'      => array( '/^baidu\.com$/' ),
	);

	/**
	 * Social: nome => domini. Un host appartiene al social se coincide con il dominio o ne è un sottodominio
	 * (m.facebook.com, l.facebook.com, lm.facebook.com, l.instagram.com...).
	 *
	 * @var array<string,array<int,string>>
	 */
	private const SOCIAL = array(
		'Facebook'  => array( 'facebook.com' ),
		'LinkedIn'  => array( 'linkedin.com', 'lnkd.in', 'com.linkedin.android' ),
		'Instagram' => array( 'instagram.com' ),
		'Twitter'   => array( 't.co', 'x.com', 'twitter.com' ),
		'Reddit'    => array( 'reddit.com' ),
		'Pinterest' => array( 'pinterest.com' ),
		'YouTube'   => array( 'youtube.com' ),
	);

	/**
	 * Host del referrer: minuscolo, senza "www.", vuoto se il referrer manca, non è analizzabile o è il sito stesso.
	 *
	 * @param string $referrer  Referrer completo (document.referrer).
	 * @param string $site_host Host del sito.
	 * @return string
	 */
	public static function host( string $referrer, string $site_host ): string {
		if ( '' === trim( $referrer ) ) {
			return '';
		}

		// Passo 1: classe pura, senza WordPress, quindi parse_url() e non wp_parse_url().
		$host = parse_url( trim( $referrer ), PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Classe pura, wp_parse_url() non è disponibile nei test unitari.
		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}

		// Passo 2: normalizzazione (minuscolo e senza www.).
		$host = self::strip_www( $host );

		// Passo 3: un referrer del sito stesso non è una provenienza.
		return $host === self::strip_www( $site_host ) ? '' : $host;
	}

	/**
	 * Canale e sorgente di una visita (le regole si provano nell'ordine indicato).
	 *
	 * @param string $host       Host del referrer (vedi host()).
	 * @param string $utm_source Parametro utm_source.
	 * @param string $utm_medium Parametro utm_medium.
	 * @return array{channel:string,source:string}
	 */
	public static function classify( string $host, string $utm_source, string $utm_medium ): array {
		// 1. Mezzo email o newsletter: ha la precedenza sul referrer.
		if ( in_array( strtolower( trim( $utm_medium ) ), array( 'email', 'newsletter' ), true ) ) {
			return array(
				'channel' => 'email',
				'source'  => '' !== $utm_source ? $utm_source : 'Email',
			);
		}

		// 2. Motori di ricerca.
		foreach ( self::SEARCH as $name => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( 1 === preg_match( $pattern, $host ) ) {
					return array(
						'channel' => 'search',
						'source'  => $name,
					);
				}
			}
		}

		// 3. Social.
		foreach ( self::SOCIAL as $name => $domains ) {
			foreach ( $domains as $domain ) {
				if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
					return array(
						'channel' => 'social',
						'source'  => $name,
					);
				}
			}
		}

		// 4. Altro sito: referral, con l'host come sorgente.
		if ( '' !== $host ) {
			return array(
				'channel' => 'referral',
				'source'  => $host,
			);
		}

		// 5. Nessun referrer ma c'è utm_source: non assegnato.
		if ( '' !== $utm_source ) {
			return array(
				'channel' => 'unassigned',
				'source'  => $utm_source,
			);
		}

		// 6. Accesso diretto.
		return array(
			'channel' => 'direct',
			'source'  => 'Direct',
		);
	}

	/**
	 * Minuscolo e senza "www." iniziale.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function strip_www( string $host ): string {
		$host = strtolower( $host );
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
