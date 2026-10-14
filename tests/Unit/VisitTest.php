<?php
/**
 * Test delle regole della singola visita.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\Visit;
use PHPUnit\Framework\TestCase;

/**
 * Hash, firma, utenti esclusi, IP esclusi e ingresso.
 */
final class VisitTest extends TestCase {

	public function test_visitor_hash_is_16_bytes_and_deterministic(): void {
		$hash = Visit::visitor_hash( '203.0.113.7', 'Mozilla/5.0', 'chiave' );

		$this->assertSame( 16, strlen( $hash ) );
		$this->assertSame( $hash, Visit::visitor_hash( '203.0.113.7', 'Mozilla/5.0', 'chiave' ) );
	}

	public function test_visitor_hash_changes_with_ip_ua_or_key(): void {
		$hash = Visit::visitor_hash( '203.0.113.7', 'Mozilla/5.0', 'chiave' );

		$this->assertNotSame( $hash, Visit::visitor_hash( '203.0.113.8', 'Mozilla/5.0', 'chiave' ) );
		$this->assertNotSame( $hash, Visit::visitor_hash( '203.0.113.7', 'Firefox', 'chiave' ) );
		$this->assertNotSame( $hash, Visit::visitor_hash( '203.0.113.7', 'Mozilla/5.0', 'altra' ) );
	}

	public function test_valid_signature_passes(): void {
		$signature = Visit::signature( 'post', 42, 'segreto' );

		$this->assertSame( 32, strlen( $signature ) );
		$this->assertTrue( Visit::check_signature( 'post', 42, $signature, 'segreto' ) );
	}

	public function test_signature_fails_with_other_type_id_key_or_empty(): void {
		$signature = Visit::signature( 'post', 42, 'segreto' );

		$this->assertFalse( Visit::check_signature( 'page', 42, $signature, 'segreto' ) );
		$this->assertFalse( Visit::check_signature( 'post', 43, $signature, 'segreto' ) );
		$this->assertFalse( Visit::check_signature( 'post', 42, $signature, 'altro' ) );
		$this->assertFalse( Visit::check_signature( 'post', 42, '', 'segreto' ) );
	}

	/**
	 * Le 9 combinazioni di modalità e utente.
	 *
	 * @dataProvider user_cases
	 *
	 * @param string $mode      Modalità.
	 * @param bool   $logged_in Utente collegato.
	 * @param bool   $is_admin  Amministratore.
	 * @param bool   $excluded  Esito atteso.
	 */
	public function test_user_excluded( string $mode, bool $logged_in, bool $is_admin, bool $excluded ): void {
		$this->assertSame( $excluded, Visit::user_excluded( $mode, $logged_in, $is_admin ) );
	}

	/**
	 * Modalità x (anonimo, collegato non admin, amministratore).
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function user_cases(): array {
		return array(
			'admins anonimo'         => array( 'admins', false, false, false ),
			'admins collegato'       => array( 'admins', true, false, false ),
			'admins amministratore'  => array( 'admins', true, true, true ),
			'logged_in anonimo'      => array( 'logged_in', false, false, false ),
			'logged_in collegato'    => array( 'logged_in', true, false, true ),
			'logged_in admin'        => array( 'logged_in', true, true, true ),
			'none anonimo'           => array( 'none', false, false, false ),
			'none collegato'         => array( 'none', true, false, false ),
			'none amministratore'    => array( 'none', true, true, false ),
		);
	}

	public function test_valid_ip_rules(): void {
		foreach ( array( '203.0.113.7', '203.0.113.0/24', '2001:db8::1', '2001:db8::/64' ) as $rule ) {
			$this->assertTrue( Visit::is_valid_ip_rule( $rule ), $rule );
		}
	}

	public function test_invalid_ip_rules(): void {
		foreach ( array( '203.0.113.0/33', '999.1.1.1', 'abc', '', '203.0.113.0/', '203.0.113.0/024', '1.2.3.4/8/8' ) as $rule ) {
			$this->assertFalse( Visit::is_valid_ip_rule( $rule ), $rule );
		}
	}

	public function test_ip_excluded_by_single_address_and_cidr(): void {
		$this->assertTrue( Visit::ip_excluded( '203.0.113.7', array( '203.0.113.7' ) ) );
		$this->assertFalse( Visit::ip_excluded( '203.0.113.8', array( '203.0.113.7' ) ) );

		// /24: dentro e fuori.
		$this->assertTrue( Visit::ip_excluded( '203.0.113.200', array( '203.0.113.0/24' ) ) );
		$this->assertFalse( Visit::ip_excluded( '203.0.114.1', array( '203.0.113.0/24' ) ) );

		// /32 equivale all'indirizzo singolo e /0 prende tutto.
		$this->assertTrue( Visit::ip_excluded( '203.0.113.7', array( '203.0.113.7/32' ) ) );
		$this->assertFalse( Visit::ip_excluded( '203.0.113.8', array( '203.0.113.7/32' ) ) );
		$this->assertTrue( Visit::ip_excluded( '8.8.8.8', array( '0.0.0.0/0' ) ) );

		// Prefisso che non cade sul confine del byte.
		$this->assertTrue( Visit::ip_excluded( '203.0.113.127', array( '203.0.113.0/25' ) ) );
		$this->assertFalse( Visit::ip_excluded( '203.0.113.128', array( '203.0.113.0/25' ) ) );
	}

	public function test_ip_excluded_with_ipv6(): void {
		$this->assertTrue( Visit::ip_excluded( '2001:db8::5', array( '2001:db8::/64' ) ) );
		$this->assertFalse( Visit::ip_excluded( '2001:db8:0:1::5', array( '2001:db8::/64' ) ) );
		// IPv4 contro una regola IPv6: mai.
		$this->assertFalse( Visit::ip_excluded( '203.0.113.7', array( '2001:db8::/64' ) ) );
	}

	public function test_ip_excluded_ignores_invalid_rules_and_ips(): void {
		$this->assertFalse( Visit::ip_excluded( '203.0.113.7', array( 'abc', '203.0.113.0/33', '' ) ) );
		$this->assertTrue( Visit::ip_excluded( '203.0.113.7', array( 'abc', '203.0.113.0/24' ) ) );
		$this->assertFalse( Visit::ip_excluded( 'non-un-ip', array( '0.0.0.0/0' ) ) );
	}

	public function test_is_entry(): void {
		$this->assertTrue( Visit::is_entry( 0, '' ) );
		$this->assertFalse( Visit::is_entry( 3, '' ) );
		$this->assertTrue( Visit::is_entry( 3, 'google.com' ) );
	}
}
