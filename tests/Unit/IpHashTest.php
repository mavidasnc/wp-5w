<?php
/**
 * Test di IpHash.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\IpHash;
use PHPUnit\Framework\TestCase;

/**
 * Hash dell'IP, user agent salvato e giorni di conservazione (schema v7).
 */
final class IpHashTest extends TestCase {

	public function test_key_is_derived_from_the_salt_with_a_context(): void {
		$key = IpHash::key( 'salt-di-prova' );

		$this->assertSame( 32, strlen( $key ) );
		$this->assertSame( hash_hmac( 'sha256', 'wp5w-ip', 'salt-di-prova', true ), $key );
		// La salt non è usata così com'è: la chiave cambia con il contesto.
		$this->assertNotSame( hash( 'sha256', 'salt-di-prova', true ), $key );
	}

	public function test_hash_is_16_hex_characters_and_stable_for_the_same_ip(): void {
		$key = IpHash::key( 'salt' );

		$hash = IpHash::hash( '203.0.113.7', $key );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $hash );
		$this->assertSame( $hash, IpHash::hash( '203.0.113.7', $key ) );
		$this->assertNotSame( $hash, IpHash::hash( '203.0.113.8', $key ) );
	}

	public function test_hash_changes_with_the_salt(): void {
		$this->assertNotSame( IpHash::hash( '203.0.113.7', IpHash::key( 'salt-a' ) ), IpHash::hash( '203.0.113.7', IpHash::key( 'salt-b' ) ) );
	}

	public function test_ipv6_is_normalized_before_hashing(): void {
		$key = IpHash::key( 'salt' );

		$this->assertSame( IpHash::hash( '2001:db8:0:0:0:0:0:1', $key ), IpHash::hash( '2001:DB8::1', $key ) );
	}

	public function test_hash_never_contains_the_ip_or_its_plain_digests(): void {
		$ip   = '198.51.100.23';
		$hash = IpHash::hash( $ip, IpHash::key( 'salt' ) );

		foreach ( array( $ip, bin2hex( (string) inet_pton( $ip ) ), md5( $ip ), hash( 'sha256', $ip ) ) as $plain ) {
			$this->assertStringNotContainsString( substr( $plain, 0, 16 ), $hash );
		}
	}

	public function test_invalid_ip_gives_an_empty_hash(): void {
		$key = IpHash::key( 'salt' );

		$this->assertSame( '', IpHash::hash( '', $key ) );
		$this->assertSame( '', IpHash::hash( 'non-un-ip', $key ) );
		$this->assertSame( '', IpHash::hash( '999.1.1.1', $key ) );
	}

	public function test_user_agent_is_cut_at_255_characters_without_breaking_utf8(): void {
		$ua = str_repeat( 'à', 300 );

		$cut = IpHash::user_agent( $ua );
		$this->assertSame( 255, mb_strlen( $cut ) );
		$this->assertTrue( mb_check_encoding( $cut, 'UTF-8' ) );
		$this->assertSame( 'Mozilla/5.0', IpHash::user_agent( '  Mozilla/5.0 ' ) );
	}

	public function test_first_kept_day_keeps_today_and_the_previous_days(): void {
		$this->assertSame( '2026-11-05', IpHash::first_kept_day( '2026-11-05', 1 ) );
		$this->assertSame( '2026-10-23', IpHash::first_kept_day( '2026-11-05', 14 ) );
		// Valori non validi valgono 1 giorno.
		$this->assertSame( '2026-11-05', IpHash::first_kept_day( '2026-11-05', 0 ) );
	}

	public function test_first_kept_day_crosses_the_daylight_saving_change(): void {
		// Il 25/10/2026 torna l'ora solare: 3 giorni dal 26/10 sono 24, 25 e 26.
		$this->assertSame( '2026-10-24', IpHash::first_kept_day( '2026-10-26', 3 ) );
	}
}
