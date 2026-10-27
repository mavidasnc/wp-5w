<?php
/**
 * Test di LocalTime.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\LocalTime;
use PHPUnit\Framework\TestCase;

/**
 * Spostamento da UTC al giorno del sito.
 */
final class LocalTimeTest extends TestCase {

	/**
	 * Transizioni reali di Europe/Rome tra l'1/1/2026 e il 31/12/2026.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function rome(): array {
		$zone = new \DateTimeZone( 'Europe/Rome' );
		return $zone->getTransitions( strtotime( '2026-01-01 00:00:00 UTC' ), strtotime( '2026-12-31 23:59:59 UTC' ) );
	}

	public function test_empty_transitions_leave_the_column_unchanged(): void {
		$this->assertSame( 'viewed_at', LocalTime::shift_sql( 'viewed_at', array() ) );
	}

	public function test_a_single_transition_is_a_fixed_offset(): void {
		$this->assertSame(
			'viewed_at + INTERVAL 3600 SECOND',
			LocalTime::shift_sql( 'viewed_at', array( array( 'ts' => 0, 'offset' => 3600 ) ) )
		);
	}

	public function test_rome_uses_winter_and_summer_offsets(): void {
		$sql = LocalTime::shift_sql( 'v.viewed_at', self::rome() );

		// Inverno prima del 29/3/2026 01:00 UTC (3600), estate dopo (7200), di nuovo inverno dal 25/10/2026 01:00 UTC.
		$this->assertStringContainsString( "WHEN v.viewed_at >= '2026-10-25 01:00:00' THEN 3600", $sql );
		$this->assertStringContainsString( "WHEN v.viewed_at >= '2026-03-29 01:00:00' THEN 7200", $sql );
		$this->assertStringContainsString( 'ELSE 3600 END', $sql );
	}

	public function test_a_fixed_offset_zone_gives_a_single_offset_instead_of_false(): void {
		// Con «+02:00» getTransitions() restituisce false: senza il controllo shift_sql() andrebbe in TypeError.
		$from        = strtotime( '2026-10-01 00:00:00 UTC' );
		$transitions = LocalTime::transitions( new \DateTimeZone( '+02:00' ), $from, strtotime( '2026-10-27 00:00:00 UTC' ) );

		$this->assertSame(
			array(
				array(
					'ts'     => $from,
					'offset' => 7200,
				),
			),
			$transitions
		);
		$this->assertSame( 'ts + INTERVAL 7200 SECOND', LocalTime::shift_sql( 'ts', $transitions ) );
	}

	public function test_utc_gives_a_zero_offset(): void {
		$transitions = LocalTime::transitions( new \DateTimeZone( 'UTC' ), strtotime( '2026-10-01 00:00:00 UTC' ), strtotime( '2026-10-27 00:00:00 UTC' ) );

		$this->assertSame( 'ts + INTERVAL 0 SECOND', LocalTime::shift_sql( 'ts', $transitions ) );
	}

	public function test_a_zone_with_daylight_saving_keeps_its_real_transitions(): void {
		$transitions = LocalTime::transitions( new \DateTimeZone( 'Europe/Rome' ), strtotime( '2026-01-01 00:00:00 UTC' ), strtotime( '2026-12-31 23:59:59 UTC' ) );

		$this->assertSame( self::rome(), $transitions );
	}

	public function test_later_transitions_come_first_so_the_most_recent_one_wins(): void {
		$sql = LocalTime::shift_sql( 'v.viewed_at', self::rome() );

		$this->assertLessThan( strpos( $sql, "'2026-03-29" ), strpos( $sql, "'2026-10-25" ) );
	}
}
