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
	 * Assistenti AI: nome => domini (corrispondenza esatta o sottodominio, senza "www.").
	 * I nomi ChatGPT e Perplexity sono gli stessi di WP Statistics (source_name).
	 * I nomi dei pacchetti Android (com.openai.chatgpt...) servono per i referrer `android-app://`.
	 * Elenco aggiornabile: ogni valore deve restare fatto di soli caratteri sicuri per SQL (lo controlla ChannelsTest).
	 *
	 * @var array<string,array<int,string>>
	 */
	public const AI = array(
		'ChatGPT'    => array( 'chatgpt.com', 'chat.openai.com', 'com.openai.chatgpt' ),
		'Perplexity' => array( 'perplexity.ai', 'ai.perplexity.app.android' ),
		'Gemini'     => array( 'gemini.google.com', 'bard.google.com', 'com.google.android.apps.bard' ),
		'Copilot'    => array( 'copilot.microsoft.com', 'com.microsoft.copilot' ),
		'Claude'     => array( 'claude.ai', 'com.anthropic.claude' ),
		'DeepSeek'   => array( 'chat.deepseek.com' ),
		'Mistral'    => array( 'chat.mistral.ai' ),
		'Grok'       => array( 'grok.com' ),
		'Meta AI'    => array( 'meta.ai' ),
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

		// Passo 3: host non valido (spazi, tag, caratteri strani) = referrer spam, si scarta.
		if ( 1 !== preg_match( '/^[a-z0-9.-]{1,190}$/', $host ) ) {
			return '';
		}

		// Passo 4: un referrer del sito stesso non è una provenienza.
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
	 * Frammento SQL che normalizza una colonna con il referrer come l'host di Channels::host():
	 * minuscolo, senza `android-app://`, senza `/` finale e senza `www.` iniziale.
	 *
	 * @param string $column Colonna (o espressione) con il referrer.
	 * @return string
	 */
	public static function host_sql( string $column ): string {
		return "TRIM(LEADING 'www.' FROM TRIM(TRAILING '/' FROM REPLACE(LOWER(TRIM({$column})), 'android-app://', '')))";
	}

	/**
	 * Frammento SQL (un `CASE`) che dà il nome dell'assistente AI di una visita, o `''` se non è un assistente.
	 *
	 * La stessa regola vale per le due origini: l'aggregazione di `OwnSource` e quella di `WpStatisticsSource`
	 * la usano per scrivere il canale `ai`, così il Confronto mette `ai` contro `ai`. Per ogni assistente la
	 * condizione è l'OR di tre parti, e una parte si omette se il relativo argomento è vuoto:
	 * host del referrer (esatto o sottodominio), `utm_source` (esatto, come in WP Statistics) e `source_name`.
	 * I valori letterali vengono solo dalla costante AI (ChannelsTest controlla che siano sicuri).
	 *
	 * @param string $host       Espressione SQL con l'host già normalizzato (vedi host_sql()).
	 * @param string $utm_source Colonna con utm_source (vuota se la sorgente non la salva).
	 * @param string $name       Colonna con il nome della sorgente di WP Statistics (vuota se non c'è).
	 * @return string
	 */
	public static function ai_sql( string $host, string $utm_source = '', string $name = '' ): string {
		$cases = array();

		foreach ( self::AI as $assistant => $domains ) {
			$conditions = array();

			foreach ( $domains as $domain ) {
				// Referrer: host uguale al dominio o suo sottodominio.
				$conditions[] = "{$host} = '{$domain}' OR {$host} LIKE '%.{$domain}'";
				// utm_source: confronto esatto, senza "www.".
				if ( '' !== $utm_source ) {
					$conditions[] = "TRIM(LEADING 'www.' FROM LOWER(TRIM({$utm_source}))) = '{$domain}'";
				}
			}

			// Nome della sorgente già riconosciuto da WP Statistics.
			if ( '' !== $name ) {
				$conditions[] = "{$name} = '{$assistant}'";
			}

			$cases[] = 'WHEN ' . implode( ' OR ', $conditions ) . " THEN '{$assistant}'";
		}

		return 'CASE ' . implode( ' ', $cases ) . " ELSE '' END";
	}

	/**
	 * Nome dell'assistente AI di una visita, o `''` se non è un assistente: la stessa regola di ai_sql(), in PHP,
	 * per le sorgenti che classificano in memoria invece che in SQL (log di accesso).
	 *
	 * @param string $host       Host del referrer (vedi host()).
	 * @param string $utm_source Parametro utm_source.
	 * @return string
	 */
	public static function ai( string $host, string $utm_source = '' ): string {
		$utm = self::strip_www( trim( $utm_source ) );

		foreach ( self::AI as $assistant => $domains ) {
			foreach ( $domains as $domain ) {
				if ( $host === $domain || str_ends_with( $host, '.' . $domain ) || $utm === $domain ) {
					return $assistant;
				}
			}
		}

		return '';
	}

	/**
	 * Canale di WP Statistics per un tipo di referrer di Independent Analytics (`referrer_types.referrer_type`).
	 *
	 * Il canale `ai` non passa di qui: si decide in aggregazione con ai_sql(), come per le altre due origini.
	 * Un tipo sconosciuto (o `Ad`, che WP Statistics non ha) diventa `unassigned`.
	 *
	 * @param string $type Tipo di referrer: Direct, Search, Social, Referrer, AI o Ad.
	 * @return string
	 */
	public static function from_iawp( string $type ): string {
		return match ( strtolower( trim( $type ) ) ) {
			'direct'   => 'direct',
			'search'   => 'search',
			'social'   => 'social',
			'referrer' => 'referral',
			'ai'       => 'ai',
			default    => 'unassigned',
		};
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
