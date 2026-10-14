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

	public function test_host_with_invalid_characters_is_empty(): void {
		$this->assertSame( '', Channels::host( 'http://<img src=x>.com/', 'maurizio.mavida.com' ) );
		$this->assertSame( '', Channels::host( 'https://ex ample.com/', 'maurizio.mavida.com' ) );
	}

	public function test_host_longer_than_190_characters_is_empty(): void {
		$this->assertSame( '', Channels::host( 'https://' . str_repeat( 'a', 191 ) . '.com/', 'maurizio.mavida.com' ) );
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

	public function test_ai_list_has_only_safe_values_and_no_duplicate_domains(): void {
		$seen = array();
		foreach ( Channels::AI as $name => $domains ) {
			// Niente apici, % o _ nei nomi: finiscono letterali nel frammento SQL.
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9 .]+$/', $name );
			foreach ( $domains as $domain ) {
				$this->assertMatchesRegularExpression( '/^[a-z0-9.-]+$/', $domain );
				$this->assertNotContains( $domain, $seen, "Dominio doppio: {$domain}" );
				$seen[] = $domain;
			}
		}
	}

	public function test_host_sql_normalizes_the_column(): void {
		$this->assertSame(
			"TRIM(LEADING 'www.' FROM TRIM(TRAILING '/' FROM REPLACE(LOWER(TRIM(v.referred)), 'android-app://', '')))",
			Channels::host_sql( 'v.referred' )
		);
	}

	public function test_ai_sql_with_all_arguments_covers_every_assistant_and_domain(): void {
		$sql = Channels::ai_sql( 'h.host', 'h.utm', 'v.name' );

		foreach ( Channels::AI as $name => $domains ) {
			// Un solo THEN per assistente.
			$this->assertSame( 1, substr_count( $sql, "THEN '{$name}'" ), $name );
			foreach ( $domains as $domain ) {
				// Due confronti esatti (host e utm_source) e un sottodominio (LIKE).
				$this->assertSame( 2, substr_count( $sql, "= '{$domain}'" ), $domain );
				$this->assertSame( 1, substr_count( $sql, "LIKE '%.{$domain}'" ), $domain );
			}
			$this->assertSame( 1, substr_count( $sql, "v.name = '{$name}'" ), $name );
		}
		$this->assertStringEndsWith( "ELSE '' END", $sql );
	}

	public function test_ai_sql_omits_the_parts_without_argument(): void {
		$sql = Channels::ai_sql( 'h.host' );

		$this->assertStringNotContainsString( 'utm', $sql );
		$this->assertStringNotContainsString( 'LOWER(TRIM(', $sql );
		// Senza nome della sorgente nessun confronto "= 'ChatGPT'": il nome compare solo dopo THEN.
		$this->assertStringNotContainsString( "= 'ChatGPT'", $sql );
		$this->assertSame( 1, substr_count( $sql, "THEN 'ChatGPT'" ) );
	}

	public function test_ai_sql_literals_come_only_from_the_list(): void {
		$allowed = array( '', 'www.' );
		foreach ( Channels::AI as $name => $domains ) {
			$allowed[] = $name;
			array_push( $allowed, ...$domains );
		}

		preg_match_all( "/'([^']*)'/", Channels::ai_sql( 'h.host', 'h.utm', 'v.name' ), $found );
		foreach ( $found[1] as $literal ) {
			// Il prefisso "%." del LIKE non fa parte del valore.
			$this->assertContains( preg_replace( '/^%\./', '', $literal ), $allowed, "Valore non previsto: {$literal}" );
		}
	}

	public function test_classify_is_not_changed_by_the_ai_channel(): void {
		$this->assertSame(
			array(
				'channel' => 'search',
				'source'  => 'ChatGPT',
			),
			Channels::classify( 'chatgpt.com', '', '' )
		);
		$this->assertSame(
			array(
				'channel' => 'referral',
				'source'  => 'claude.ai',
			),
			Channels::classify( 'claude.ai', '', '' )
		);
	}
}
