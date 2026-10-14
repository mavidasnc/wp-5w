<?php
/**
 * Test delle regole pure del database dei paesi.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\GeoDb;
use PHPUnit\Framework\TestCase;

/**
 * Indirizzo, mesi da provare, codice del paese, dimensione, ISIZE e metadati.
 */
final class GeoDbTest extends TestCase {

	/**
	 * Indirizzo del file mensile.
	 */
	public function test_url(): void {
		$this->assertSame( 'https://download.db-ip.com/free/dbip-country-lite-2026-10.mmdb.gz', GeoDb::url( '2026-10' ) );
	}

	/**
	 * Mesi da provare in base al file installato.
	 *
	 * @dataProvider candidates
	 *
	 * @param string|null $installed Mese installato.
	 * @param string      $today     Oggi in UTC.
	 * @param array       $expected  Mesi attesi.
	 */
	public function test_candidates( ?string $installed, string $today, array $expected ): void {
		$this->assertSame( $expected, GeoDb::candidates( $installed, $today ) );
	}

	/**
	 * Casi di candidates().
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function candidates(): array {
		return array(
			'nessun file'            => array( null, '2026-10-14', array( '2026-10', '2026-09' ) ),
			'già del mese corrente'  => array( '2026-10', '2026-10-14', array() ),
			'mese precedente'        => array( '2026-09', '2026-10-14', array( '2026-10' ) ),
			'file vecchio'           => array( '2026-07', '2026-10-14', array( '2026-10', '2026-09' ) ),
			'cambio d\'anno'         => array( null, '2027-01-01', array( '2027-01', '2026-12' ) ),
			'cambio d\'anno, aggiornato' => array( '2026-12', '2027-01-01', array( '2027-01' ) ),
		);
	}

	/**
	 * Codice del paese di un record.
	 *
	 * @dataProvider records
	 *
	 * @param mixed  $record   Record del lettore.
	 * @param string $expected Codice atteso.
	 */
	public function test_country( mixed $record, string $expected ): void {
		$this->assertSame( $expected, GeoDb::country( $record ) );
	}

	/**
	 * Casi di country().
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function records(): array {
		return array(
			'US'                  => array( array( 'country' => array( 'iso_code' => 'US' ) ), 'US' ),
			'minuscolo'           => array( array( 'country' => array( 'iso_code' => 'it' ) ), 'IT' ),
			'record completo'     => array(
				array(
					'continent' => array( 'code' => 'NA' ),
					'country'   => array( 'iso_code' => 'US', 'names' => array( 'en' => 'United States' ) ),
				),
				'US',
			),
			'ZZ'                  => array( array( 'country' => array( 'iso_code' => 'ZZ' ) ), '' ),
			'tre lettere'         => array( array( 'country' => array( 'iso_code' => 'USA' ) ), '' ),
			'cifra e lettera'     => array( array( 'country' => array( 'iso_code' => '1A' ) ), '' ),
			'null'                => array( null, '' ),
			'stringa'             => array( 'US', '' ),
			'senza country'       => array( array( 'continent' => array() ), '' ),
			'senza iso_code'      => array( array( 'country' => array( 'names' => array() ) ), '' ),
			'country non array'   => array( array( 'country' => 'US' ), '' ),
			'iso_code non stringa' => array( array( 'country' => array( 'iso_code' => 12 ) ), '' ),
		);
	}

	/**
	 * Limiti della dimensione.
	 */
	public function test_size_ok(): void {
		$this->assertFalse( GeoDb::size_ok( 1048575 ) );
		$this->assertTrue( GeoDb::size_ok( 1048576 ) );
		$this->assertTrue( GeoDb::size_ok( 8348509 ) );
		$this->assertTrue( GeoDb::size_ok( 67108864 ) );
		$this->assertFalse( GeoDb::size_ok( 67108865 ) );
	}

	/**
	 * ISIZE del gzip contro i byte scritti.
	 */
	public function test_isize_ok(): void {
		$this->assertTrue( GeoDb::isize_ok( 8348509, pack( 'V', 8348509 ) ) );
		$this->assertFalse( GeoDb::isize_ok( 8348508, pack( 'V', 8348509 ) ) );
		$this->assertFalse( GeoDb::isize_ok( 8348509, substr( pack( 'V', 8348509 ), 0, 3 ) ) );
		$this->assertFalse( GeoDb::isize_ok( 8348509, '' ) );
		// Oltre 2^32 l'ISIZE vale il resto della divisione.
		$this->assertTrue( GeoDb::isize_ok( 2 ** 32 + 5, pack( 'V', 5 ) ) );
	}

	/**
	 * Metadati del database.
	 */
	public function test_metadata_ok(): void {
		$now = 1792000000;
		$day = 86400;

		$this->assertTrue( GeoDb::metadata_ok( 'DBIP-Country-Lite', 6, $now - 13 * $day, $now ) );
		$this->assertTrue( GeoDb::metadata_ok( 'GeoLite2-Country', 6, $now - 13 * $day, $now ) );
		$this->assertFalse( GeoDb::metadata_ok( 'DBIP-City-Lite', 6, $now - 13 * $day, $now ) );
		$this->assertFalse( GeoDb::metadata_ok( 'DBIP-Country-Lite', 4, $now - 13 * $day, $now ) );
		$this->assertTrue( GeoDb::metadata_ok( 'DBIP-Country-Lite', 6, $now - 100 * $day, $now ) );
		$this->assertFalse( GeoDb::metadata_ok( 'DBIP-Country-Lite', 6, $now - 101 * $day, $now ) );
		$this->assertTrue( GeoDb::metadata_ok( 'DBIP-Country-Lite', 6, $now + 2 * $day, $now ) );
		$this->assertFalse( GeoDb::metadata_ok( 'DBIP-Country-Lite', 6, $now + 3 * $day, $now ) );
	}
}
