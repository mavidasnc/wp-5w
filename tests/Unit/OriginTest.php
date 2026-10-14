<?php
/**
 * Test della regola dell'origine.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\Origin;
use PHPUnit\Framework\TestCase;

/**
 * Frammento SQL che sceglie tra righe wps e own.
 */
final class OriginTest extends TestCase {

	public function test_empty_date_reads_only_wps(): void {
		$this->assertSame( "origin = 'wps'", Origin::where( '' ) );
	}

	public function test_alias_prefixes_the_columns(): void {
		$this->assertSame( "o.origin = 'wps'", Origin::where( '', 'o' ) );
	}

	public function test_valid_date_splits_wps_and_own(): void {
		$this->assertSame(
			"o.origin = IF( o.day < '2026-11-01', 'wps', 'own' )",
			Origin::where( '2026-11-01', 'o' )
		);
		// Senza alias le colonne non hanno prefisso.
		$this->assertSame(
			"origin = IF( day < '2026-11-01', 'wps', 'own' )",
			Origin::where( '2026-11-01' )
		);
	}

	/**
	 * Date non valide: solo wps e la stringa ricevuta non compare mai nell'SQL.
	 *
	 * @dataProvider invalid_dates
	 *
	 * @param string $date Data non valida.
	 */
	public function test_invalid_date_falls_back_to_wps( string $date ): void {
		$sql = Origin::where( $date, 'o' );

		$this->assertSame( "o.origin = 'wps'", $sql );
		$this->assertStringNotContainsString( $date, $sql );
	}

	/**
	 * Date non valide, compreso un tentativo di SQL injection.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function invalid_dates(): array {
		return array(
			'giorno inesistente' => array( '2026-02-30' ),
			'mese inesistente'   => array( '2026-13-01' ),
			'injection'          => array( "x' OR 1=1 --" ),
		);
	}

	public function test_tables_are_the_five_aggregates(): void {
		$this->assertSame( array( 'site_daily', 'object_daily', 'entry_daily', 'referrer_daily', 'dim_daily' ), Origin::TABLES );
	}
}
