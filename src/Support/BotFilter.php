<?php
/**
 * Etichettatura di bot e crawler dallo user agent.
 *
 * Dalla 0.11.0 le visite dei bot non si scartano: si registrano e si segnalano con un'etichetta
 * fissa nella colonna `bot` di wp5w_hits.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Elenco di firme di bot, crawler, strumenti da riga di comando e browser automatici: le firme segnalano, non scartano.
 */
final class BotFilter {

	/**
	 * Lunghezza minima di uno user agent credibile: sotto questa soglia l'etichetta è `ua-corto`.
	 *
	 * @var int
	 */
	private const MIN_LENGTH = 16;

	/**
	 * Etichetta degli user agent vuoti o troppo corti.
	 *
	 * @var string
	 */
	public const LABEL_SHORT = 'ua-corto';

	/**
	 * Etichetta dei browser che dichiarano `navigator.webdriver` (flag `w` del tracker).
	 *
	 * @var string
	 */
	public const LABEL_WEBDRIVER = 'webdriver';

	/**
	 * Firme (minuscole) che, se contenute nello user agent, lo segnalano come bot. Facile da estendere.
	 *
	 * @var array<int,string>
	 */
	public const SIGNATURES = array(
		'bot',
		'crawl',
		'spider',
		'slurp',
		'facebookexternalhit',
		'headless',
		'lighthouse',
		'pagespeed',
		'gtmetrix',
		'pingdom',
		'uptime',
		'monitor',
		'preview',
		'curl/',
		'wget/',
		'python-',
		'go-http-client',
		'java/',
		'okhttp',
		'axios',
		'node-fetch',
		'httpclient',
		'scrapy',
		'phantomjs',
		'puppeteer',
		'playwright',
		'selenium',
		'ahrefs',
		'semrush',
		'mj12',
		'petalbot',
		'bytespider',
		'gptbot',
		'ccbot',
		'claudebot',
		'perplexity',
		'amazonbot',
		'meta-externalagent',
		'whatsapp',
		'embedly',
	);

	/**
	 * Etichetta della visita: `ua-corto`, la firma del bot, `webdriver` oppure stringa vuota.
	 *
	 * Valori da un elenco fisso (al massimo 20 caratteri): non permettono di ricostruire lo user agent.
	 *
	 * @param string $user_agent User agent della richiesta.
	 * @param bool   $webdriver  Vero se il tracker ha segnalato `navigator.webdriver`.
	 * @return string
	 */
	public static function label( string $user_agent, bool $webdriver = false ): string {
		$user_agent = trim( $user_agent );

		// Passo 1: gli user agent vuoti o troppo corti non sono browser reali.
		if ( strlen( $user_agent ) < self::MIN_LENGTH ) {
			return self::LABEL_SHORT;
		}

		// Passo 2: si cercano le firme, senza distinguere maiuscole e minuscole. Vince la più lunga
		// (`bot` corrisponde anche a gptbot o petalbot); a parità di lunghezza, la prima dell'elenco.
		$found = '';
		foreach ( self::SIGNATURES as $signature ) {
			if ( strlen( $signature ) > strlen( $found ) && false !== stripos( $user_agent, $signature ) ) {
				$found = $signature;
			}
		}
		if ( '' !== $found ) {
			return $found;
		}

		// Passo 3: browser che si dichiara automatizzato.
		return $webdriver ? self::LABEL_WEBDRIVER : '';
	}
}
