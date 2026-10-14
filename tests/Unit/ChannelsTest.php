<?php
/**
 * Test della classificazione di canali e sorgenti.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\Channels;
use PHPUnit\Framework\TestCase;

/**
 * Host del referrer e regole di classify().
 */
final class ChannelsTest extends TestCase {

	public function test_host_of_empty_referrer_is_empty(): void {
		$this->assertSame( '', Channels::host( '', 'maurizio.mavida.com' ) );
	}

	public function test_host_is_lowercase_without_www(): void {
		$this->assertSame( 'google.it', Channels::host( 'https://www.Google.it/', 'maurizio.mavida.com' ) );
	}

	public function test_internal_referrer_is_empty(): void {
		$this->assertSame( '', Channels::host( 'https://maurizio.mavida.com/altro-articolo/', 'maurizio.mavida.com' ) );
		// Anche con www. da una parte sola.
		$this->assertSame( '', Channels::host( 'https://www.maurizio.mavida.com/', 'maurizio.mavida.com' ) );
	}

	public function test_app_referrer_gives_the_package_name(): void {
		$this->assertSame( 'com.linkedin.android', Channels::host( 'android-app://com.linkedin.android/', 'maurizio.mavida.com' ) );
	}

	public function test_unparsable_referrer_is_empty(): void {
		$this->assertSame( '', Channels::host( 'not a url', 'maurizio.mavida.com' ) );
	}

	/**
	 * Canale e sorgente attesi per un host, senza UTM.
	 *
	 * @dataProvider hosts
	 *
	 * @param string $host    Host del referrer.
	 * @param string $channel Canale atteso.
	 * @param string $source  Sorgente attesa.
	 */
	public function test_classifies_hosts( string $host, string $channel, string $source ): void {
		$this->assertSame(
			array(
				'channel' => $channel,
				'source'  => $source,
			),
			Channels::classify( $host, '', '' )
		);
	}

	/**
	 * Host, canale e sorgente.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function hosts(): array {
		return array(
			'diretto'        => array( '', 'direct', 'Direct' ),
			'google.com'     => array( 'google.com', 'search', 'Google' ),
			'google.co.uk'   => array( 'google.co.uk', 'search', 'Google' ),
			'google.com.br'  => array( 'google.com.br', 'search', 'Google' ),
			'mail.google'    => array( 'mail.google.com', 'referral', 'mail.google.com' ),
			'docs.google'    => array( 'docs.google.com', 'referral', 'docs.google.com' ),
			'bing'           => array( 'bing.com', 'search', 'Bing' ),
			'duckduckgo'     => array( 'duckduckgo.com', 'search', 'DuckDuckGo' ),
			'chatgpt'        => array( 'chatgpt.com', 'search', 'ChatGPT' ),
			'yahoo'          => array( 'search.yahoo.com', 'search', 'Yahoo' ),
			'brave'          => array( 'search.brave.com', 'search', 'Brave' ),
			'yandex'         => array( 'yandex.ru', 'search', 'Yandex' ),
			'facebook'       => array( 'lm.facebook.com', 'social', 'Facebook' ),
			'linkedin corto' => array( 'lnkd.in', 'social', 'LinkedIn' ),
			'linkedin app'   => array( 'com.linkedin.android', 'social', 'LinkedIn' ),
			'instagram'      => array( 'l.instagram.com', 'social', 'Instagram' ),
			'twitter'        => array( 't.co', 'social', 'Twitter' ),
			'altro sito'     => array( 'example.org', 'referral', 'example.org' ),
			'box.com'        => array( 'box.com', 'referral', 'box.com' ),
		);
	}

	public function test_email_medium_wins_over_the_referrer(): void {
		$this->assertSame(
			array(
				'channel' => 'email',
				'source'  => 'Email',
			),
			Channels::classify( 'google.com', '', 'Email' )
		);
		$this->assertSame(
			array(
				'channel' => 'email',
				'source'  => 'weekly',
			),
			Channels::classify( 'google.com', 'weekly', 'newsletter' )
		);
	}

	public function test_empty_host_with_utm_source_is_unassigned(): void {
		$this->assertSame(
			array(
				'channel' => 'unassigned',
				'source'  => 'newsletter-ott',
			),
			Channels::classify( '', 'newsletter-ott', '' )
		);
	}
}
