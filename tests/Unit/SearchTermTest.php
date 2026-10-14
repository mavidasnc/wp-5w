<?php
/**
 * Test della normalizzazione dei termini di ricerca interna.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\SearchTerm;
use PHPUnit\Framework\TestCase;

/**
 * normalize(), is_private() e store().
 */
final class SearchTermTest extends TestCase {

	public function test_normalize_trims_collapses_spaces_and_lowercases(): void {
		$this->assertSame( 'wordpress plugin', SearchTerm::normalize( '  WordPress   Plugin ' ) );
		$this->assertSame( 'àèì', SearchTerm::normalize( 'ÀÈÌ' ) );
	}

	public function test_normalize_turns_tabs_and_newlines_into_one_space(): void {
		$this->assertSame( 'a b c', SearchTerm::normalize( "a\tb\r\n c" ) );
		// Spazio non separabile (Unicode).
		$this->assertSame( 'a b', SearchTerm::normalize( "a\u{00A0}\u{00A0}b" ) );
	}

	public function test_normalize_removes_control_characters(): void {
		$this->assertSame( 'ab', SearchTerm::normalize( "a\x00b\x07" ) );
	}

	public function test_normalize_cuts_to_60_characters_even_with_multibyte(): void {
		$this->assertSame( 60, mb_strlen( SearchTerm::normalize( str_repeat( 'x', 100 ) ) ) );
		$this->assertSame( 60, mb_strlen( SearchTerm::normalize( str_repeat( 'è', 100 ) ) ) );
		// Il taglio che lascia uno spazio finale lo toglie.
		$this->assertSame( str_repeat( 'a', 59 ), SearchTerm::normalize( str_repeat( 'a', 59 ) . ' bbb' ) );
	}

	/**
	 * @dataProvider private_terms
	 *
	 * @param string $term Termine.
	 */
	public function test_private_terms( string $term ): void {
		$this->assertTrue( SearchTerm::is_private( $term ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function private_terms(): array {
		return array(
			'email'                  => array( 'mario.rossi@example.com' ),
			'email in una frase'     => array( 'scrivi a a@b.it' ),
			'telefono con spazio'    => array( '333 1234567' ),
			'telefono internazionale' => array( '+39 333-123-4567' ),
			'iban'                   => array( 'IT60X0542811101000000123456' ),
			'carta'                  => array( '4111 1111 1111 1111' ),
		);
	}

	/**
	 * @dataProvider public_terms
	 *
	 * @param string $term Termine.
	 */
	public function test_public_terms( string $term ): void {
		$this->assertFalse( SearchTerm::is_private( $term ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function public_terms(): array {
		return array(
			'due anni'           => array( '2005 2006' ),
			'modello'            => array( 'iphone 15' ),
			'anno'               => array( 'calendario 2006' ),
			'versione'           => array( 'php 8.3' ),
			'menzione senza dominio' => array( '@mavida' ),
		);
	}

	public function test_store(): void {
		$this->assertSame( '', SearchTerm::store( '' ) );
		$this->assertSame( '', SearchTerm::store( "  \t " ) );
		$this->assertSame( '(filtrato)', SearchTerm::store( 'mario.rossi@example.com' ) );
		$this->assertSame( 'topolinux', SearchTerm::store( '  Topolinux ' ) );
	}
}
