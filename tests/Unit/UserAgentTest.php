<?php
/**
 * Test del riconoscimento di dispositivo, browser e sistema operativo.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\UserAgent;
use PHPUnit\Framework\TestCase;

/**
 * User agent reali (i 31 casi della specifica della fase 3) e controllo dei nomi restituiti.
 */
final class UserAgentTest extends TestCase {

	/**
	 * Valori di WP Statistics (device-detector) che UserAgent può restituire, oltre alla stringa vuota.
	 */
	private const DEVICES  = array( 'smartphone', 'desktop', 'tablet' );
	private const BROWSERS = array(
		'Chrome Mobile',
		'Chrome Mobile iOS',
		'Chrome',
		'Mobile Safari',
		'Samsung Browser',
		'Microsoft Edge',
		'Firefox',
		'Safari',
		'Google Search App',
		'Facebook',
		'Firefox Mobile',
		'Chrome Webview',
		'Opera Mobile',
		'Opera',
		'Firefox Mobile iOS',
	);
	private const SYSTEMS  = array( 'Android', 'iOS', 'Windows', 'GNU/Linux', 'Mac', 'iPadOS', 'Chrome OS', 'Ubuntu' );

	/**
	 * Ogni user agent dà i tre valori attesi.
	 *
	 * @dataProvider agents
	 *
	 * @param string $ua      User agent.
	 * @param string $device  Dispositivo atteso.
	 * @param string $browser Browser atteso.
	 * @param string $os      Sistema atteso.
	 */
	public function test_parse( string $ua, string $device, string $browser, string $os ): void {
		$this->assertSame(
			array(
				'device'  => $device,
				'browser' => $browser,
				'os'      => $os,
			),
			UserAgent::parse( $ua )
		);
	}

	/**
	 * Solo nomi di WP Statistics (o stringa vuota) e lunghezze compatibili con lo schema (20, 40 e 40 caratteri).
	 *
	 * @dataProvider agents
	 *
	 * @param string $ua User agent.
	 */
	public function test_values_are_wp_statistics_names( string $ua ): void {
		$parsed = UserAgent::parse( $ua );

		$this->assertContains( $parsed['device'], array_merge( array( '' ), self::DEVICES ) );
		$this->assertContains( $parsed['browser'], array_merge( array( '' ), self::BROWSERS ) );
		$this->assertContains( $parsed['os'], array_merge( array( '' ), self::SYSTEMS ) );
		$this->assertLessThanOrEqual( 20, strlen( $parsed['device'] ) );
		$this->assertLessThanOrEqual( 40, strlen( $parsed['browser'] ) );
		$this->assertLessThanOrEqual( 40, strlen( $parsed['os'] ) );
	}

	/**
	 * I 31 casi della specifica: user agent, dispositivo, browser, sistema.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function agents(): array {
		$chrome_win    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
		$chrome_and    = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
		$webview       = 'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A.240905.003; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36';
		$safari_mac    = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';
		$iphone_safari = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

		return array(
			'1 Chrome Windows'            => array( $chrome_win, 'desktop', 'Chrome', 'Windows' ),
			'2 Edge Windows'              => array( $chrome_win . ' Edg/129.0.0.0', 'desktop', 'Microsoft Edge', 'Windows' ),
			'3 Firefox Windows'           => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0', 'desktop', 'Firefox', 'Windows' ),
			'4 Opera Windows'             => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 OPR/114.0.0.0', 'desktop', 'Opera', 'Windows' ),
			'5 Chrome Windows 7'          => array( 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36', 'desktop', 'Chrome', 'Windows' ),
			'6 Safari Mac'                => array( $safari_mac, 'desktop', 'Safari', 'Mac' ),
			'7 Chrome Mac'                => array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'desktop', 'Chrome', 'Mac' ),
			'8 Firefox Mac'               => array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.7; rv:131.0) Gecko/20100101 Firefox/131.0', 'desktop', 'Firefox', 'Mac' ),
			'9 Chrome Linux'              => array( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'desktop', 'Chrome', 'GNU/Linux' ),
			'10 Firefox Ubuntu'           => array( 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'desktop', 'Firefox', 'Ubuntu' ),
			'11 Chrome OS'                => array( 'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'desktop', 'Chrome', 'Chrome OS' ),
			'12 Chrome Android'           => array( $chrome_and, 'smartphone', 'Chrome Mobile', 'Android' ),
			'13 Samsung Browser'          => array( 'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36', 'smartphone', 'Samsung Browser', 'Android' ),
			'14 Firefox Android'          => array( 'Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0', 'smartphone', 'Firefox Mobile', 'Android' ),
			'15 Edge Android'             => array( $chrome_and . ' EdgA/129.0.0.0', 'smartphone', 'Microsoft Edge', 'Android' ),
			'16 Opera Android'            => array( $chrome_and . ' OPR/85.0.0.0', 'smartphone', 'Opera Mobile', 'Android' ),
			'17 Chrome Webview'           => array( $webview, 'smartphone', 'Chrome Webview', 'Android' ),
			'18 Facebook Android'         => array( $webview . ' [FB_IAB/FB4A;FBAV/480.0.0.47.109;]', 'smartphone', 'Facebook', 'Android' ),
			'19 Chrome tablet Android'    => array( 'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'tablet', 'Chrome', 'Android' ),
			'20 Samsung tablet'           => array( 'Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Safari/537.36', 'tablet', 'Samsung Browser', 'Android' ),
			'21 Firefox tablet'           => array( 'Mozilla/5.0 (Android 14; Tablet; rv:131.0) Gecko/131.0 Firefox/131.0', 'tablet', 'Firefox Mobile', 'Android' ),
			'22 Safari iPhone'            => array( $iphone_safari, 'smartphone', 'Mobile Safari', 'iOS' ),
			'23 Chrome iOS'               => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0.6668.69 Mobile/15E148 Safari/604.1', 'smartphone', 'Chrome Mobile iOS', 'iOS' ),
			'24 Firefox iOS'              => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/131.0 Mobile/15E148 Safari/605.1.15', 'smartphone', 'Firefox Mobile iOS', 'iOS' ),
			'25 Edge iOS'                 => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 EdgiOS/129.0.2792.84 Mobile/15E148 Safari/605.1.15', 'smartphone', 'Microsoft Edge', 'iOS' ),
			'26 Google Search App iOS'    => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) GSA/337.0.672716006 Mobile/15E148 Safari/604.1', 'smartphone', 'Google Search App', 'iOS' ),
			'27 Facebook iOS'             => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/480.0.0.40.109;FBBV/650000000;FBDV/iPhone15,2;FBMD/iPhone;FBSN/iOS;FBSV/17.6;FBSS/3;FBCR/;FBID/phone;FBLC/it_IT;FBOP/5]', 'smartphone', 'Facebook', 'iOS' ),
			'28 Safari iPad'              => array( 'Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', 'tablet', 'Mobile Safari', 'iPadOS' ),
			'29 iPad con iPadOS 13+ (Mac)' => array( str_replace( 'Version/18.0', 'Version/17.6', $safari_mac ), 'desktop', 'Safari', 'Mac' ),
			'30 Nintendo Switch'          => array( 'Mozilla/5.0 (Nintendo Switch; WifiWebAuthApplet) AppleWebKit/606.4 (KHTML, like Gecko) NF/6.0.1.15.4 NintendoBrowser/5.1.0.20393', '', '', '' ),
			'31 vuoto'                    => array( '', '', '', '' ),
		);
	}
}
