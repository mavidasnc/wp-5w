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
	 * User agent da scartare.
	 *
	 * @dataProvider bots
	 *
	 * @param string $user_agent User agent.
	 */
	public function test_detects_bots( string $user_agent ): void {
		$this->assertTrue( BotFilter::is_bot( $user_agent ) );
	}

	/**
	 * User agent di browser reali.
	 *
	 * @dataProvider browsers
	 *
	 * @param string $user_agent User agent.
	 */
	public function test_lets_real_browsers_through( string $user_agent ): void {
		$this->assertFalse( BotFilter::is_bot( $user_agent ) );
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
