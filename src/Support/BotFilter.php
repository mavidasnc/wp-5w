<?php
/**
 * Riconoscimento di bot e crawler dallo user agent.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Elenco di firme di bot, crawler, strumenti da riga di comando e browser automatici.
 */
final class BotFilter {

	/**
	 * Lunghezza minima di uno user agent credibile: sotto questa soglia si considera un bot.
	 *
	 * @var int
	 */
	private const MIN_LENGTH = 16;

	/**
	 * Firme (minuscole) che, se contenute nello user agent, lo classificano come bot. Facile da estendere.
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
	 * Vero se lo user agent è di un bot, vuoto o troppo corto.
	 *
	 * @param string $user_agent User agent della richiesta.
	 * @return bool
	 */
	public static function is_bot( string $user_agent ): bool {
		$user_agent = trim( $user_agent );

		// Passo 1: gli user agent vuoti o troppo corti non sono browser reali.
		if ( strlen( $user_agent ) < self::MIN_LENGTH ) {
			return true;
		}

		// Passo 2: si cerca una delle firme, senza distinguere maiuscole e minuscole.
		foreach ( self::SIGNATURES as $signature ) {
			if ( false !== stripos( $user_agent, $signature ) ) {
				return true;
			}
		}

		return false;
	}
}
