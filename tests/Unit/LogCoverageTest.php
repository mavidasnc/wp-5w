<?php
/**
 * Test di LogCoverage.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\LogCoverage;
use PHPUnit\Framework\TestCase;

/**
 * Giorni interamente coperti dai log.
 */
final class LogCoverageTest extends TestCase {

	/**
	 * Timestamp di un orario locale (Europe/Rome).
	 *
	 * @param string $local Data e ora locali.
	 * @return int
	 */
	private static function at( string $local ): int {
		return ( new \DateTimeImmutable( $local, new \DateTimeZone( 'Europe/Rome' ) ) )->getTimestamp();
	}

	public function test_consecutive_archives_complete_the_days_between_the_edges(): void {
		// Tre archivi come quelli del server: ruotano poco dopo mezzanotte, il 25/10/2026 c'è il ritorno all'ora solare.
		$days = LogCoverage::complete_days(
			array(
				array( self::at( '2026-10-23 00:45:10' ), self::at( '2026-10-24 00:45:00' ) ),
				array( self::at( '2026-10-24 00:45:20' ), self::at( '2026-10-25 00:45:00' ) ),
				array( self::at( '2026-10-25 00:45:30' ), self::at( '2026-10-26 00:45:00' ) ),
			),
			new \DateTimeZone( 'Europe/Rome' )
		);

		// Il 23 inizia prima del primo log e il 26 finisce dopo l'ultimo: restano il 24 e il 25.
		$this->assertSame( array( '2026-10-24', '2026-10-25' ), $days );
	}

	public function test_a_missing_archive_leaves_no_complete_day(): void {
		$days = LogCoverage::complete_days(
			array(
				array( self::at( '2026-10-23 00:45:10' ), self::at( '2026-10-24 00:45:00' ) ),
				array( self::at( '2026-10-25 00:45:30' ), self::at( '2026-10-26 00:45:00' ) ),
			),
			new \DateTimeZone( 'Europe/Rome' )
		);

		$this->assertSame( array(), $days );
	}

	public function test_a_few_seconds_without_requests_at_midnight_do_not_break_a_day(): void {
		$days = LogCoverage::complete_days(
			array( array( self::at( '2026-10-20 00:00:07' ), self::at( '2026-10-21 23:59:55' ) ) ),
			new \DateTimeZone( 'Europe/Rome' )
		);

		$this->assertSame( array( '2026-10-20', '2026-10-21' ), $days );
	}

	public function test_the_day_of_the_clock_change_lasts_25_hours(): void {
		$timezone = new \DateTimeZone( 'Europe/Rome' );
		$start    = self::at( '2026-10-25 00:00:00' );

		// 24 ore dopo la mezzanotte sono le 23:00: al 25/10 ne manca ancora una (più della tolleranza).
		$this->assertSame( array(), LogCoverage::complete_days( array( array( $start, $start + 24 * 3600 ) ), $timezone ) );
		$this->assertSame( array( '2026-10-25' ), LogCoverage::complete_days( array( array( $start, $start + 25 * 3600 ) ), $timezone ) );
	}

	public function test_unsorted_and_overlapping_archives_are_merged(): void {
		$days = LogCoverage::complete_days(
			array(
				array( self::at( '2026-10-22 10:00:00' ), self::at( '2026-10-24 10:00:00' ) ),
				array( self::at( '2026-10-20 10:00:00' ), self::at( '2026-10-22 12:00:00' ) ),
			),
			new \DateTimeZone( 'Europe/Rome' )
		);

		$this->assertSame( array( '2026-10-21', '2026-10-22', '2026-10-23' ), $days );
	}
}
