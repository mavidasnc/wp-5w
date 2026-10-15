<?php
/**
 * Test del riconoscimento dei bot.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\BotFilter;
use PHPUnit\Framework\TestCase;

/**
 * Bot, strumenti da riga di comando e browser reali.
 */
final class BotFilterTest extends TestCase {

	/**
	 * User agent da segnalare.
	 *
	 * @dataProvider bots
	 *
	 * @param string $user_agent User agent.
	 */
	public function test_detects_bots( string $user_agent ): void {
		$this->assertNotSame( '', BotFilter::label( $user_agent ) );
	}

	/**
	 * User agent di browser reali.
	 *
	 * @dataProvider browsers
	 *
	 * @param string $user_agent User agent.
	 */
	public function test_lets_real_browsers_through( string $user_agent ): void {
		$this->assertSame( '', BotFilter::label( $user_agent ) );
	}

	/**
	 * Etichetta attesa per ogni user agent (senza webdriver).
	 *
	 * @dataProvider labels
	 *
	 * @param string $user_agent User agent.
	 * @param string $expected   Etichetta attesa.
	 */
	public function test_label( string $user_agent, string $expected ): void {
		$this->assertSame( $expected, BotFilter::label( $user_agent ) );
	}

	/**
	 * Il flag webdriver conta solo se nessun'altra etichetta lo precede.
	 */
	public function test_label_webdriver(): void {
		$chrome = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36';
		$this->assertSame( 'webdriver', BotFilter::label( $chrome, true ) );
		$this->assertSame( '', BotFilter::label( $chrome, false ) );
		$this->assertSame( 'headless', BotFilter::label( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36', true ) );
		$this->assertSame( 'ua-corto', BotFilter::label( '', true ) );
	}

	/**
	 * Ogni etichetta entra nella colonna varchar(20), è minuscola e non vuota.
	 */
	public function test_labels_fit_the_column(): void {
		$labels = array_merge( BotFilter::SIGNATURES, array( BotFilter::LABEL_SHORT, BotFilter::LABEL_WEBDRIVER ) );
		foreach ( $labels as $label ) {
			$this->assertNotSame( '', $label );
			$this->assertLessThanOrEqual( 20, strlen( $label ), $label );
			$this->assertSame( strtolower( $label ), $label );
		}
	}

	/**
	 * Tra più firme vince la più lunga, anche se `bot` viene prima nell'elenco.
	 */
	public function test_longest_signature_wins_regardless_of_order(): void {
		$this->assertLessThan( (int) array_search( 'petalbot', BotFilter::SIGNATURES, true ), (int) array_search( 'bot', BotFilter::SIGNATURES, true ) );
		$this->assertSame( 'petalbot', BotFilter::label( 'Mozilla/5.0 (compatible; PetalBot;+https://webmaster.petalsearch.com/site/petalbot)' ) );
	}

	/**
	 * Etichette attese.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function labels(): array {
		return array(
			'Googlebot'        => array( 'Googlebot/2.1 (+http://www.google.com/bot.html)', 'bot' ),
			'GPTBot'           => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.0; +https://openai.com/gptbot)', 'gptbot' ),
			'ClaudeBot'        => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'claudebot' ),
			'PetalBot'         => array( 'Mozilla/5.0 (compatible; PetalBot;+https://webmaster.petalsearch.com/site/petalbot)', 'petalbot' ),
			'Bytespider'       => array( 'Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)', 'bytespider' ),
			'AhrefsBot'        => array( 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', 'ahrefs' ),
			'UptimeRobot'      => array( 'Mozilla/5.0 (compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)', 'uptime' ),
			'HeadlessChrome'   => array( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36', 'headless' ),
			'Facebook'         => array( 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'facebookexternalhit' ),
			'Meta'             => array( 'meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)', 'meta-externalagent' ),
			'vuoto'            => array( '', 'ua-corto' ),
			'20 spazi'         => array( '                    ', 'ua-corto' ),
			'Mozilla/5.0'      => array( 'Mozilla/5.0', 'ua-corto' ),
			'curl corto'       => array( 'curl/8.4.0', 'ua-corto' ),
			'curl lungo'       => array( 'curl/8.4.0 (x86_64-pc-linux-gnu) libcurl', 'curl/' ),
			'GPTBot maiuscolo' => array( 'GPTBOT/1.0 (COMPATIBLE; +HTTPS://OPENAI.COM/GPTBOT)', 'gptbot' ),
		);
	}

	/**
	 * Bot, strumenti automatici e user agent vuoti o troppo corti.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function bots(): array {
		return array(
			'Googlebot'       => array( 'Googlebot/2.1 (+http://www.google.com/bot.html)' ),
			'Bingbot'         => array( 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' ),
			'curl'            => array( 'curl/8.4.0' ),
			'python-requests' => array( 'python-requests/2.31' ),
			'HeadlessChrome'  => array( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36' ),
			'GPTBot'          => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.0; +https://openai.com/gptbot)' ),
			'Facebook'        => array( 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)' ),
			'vuoto'           => array( '' ),
			'solo spazi'      => array( '                    ' ),
			'troppo corto'    => array( 'Mozilla/5.0' ),
		);
	}

	/**
	 * Chrome, Safari, Firefox ed Edge.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function browsers(): array {
		return array(
			'Chrome 129 su Windows' => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36' ),
			'Safari su iPhone'      => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' ),
			'Firefox su Android'    => array( 'Mozilla/5.0 (Android 14; Mobile; rv:128.0) Gecko/128.0 Firefox/128.0' ),
			'Edge'                  => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0' ),
		);
	}
}
