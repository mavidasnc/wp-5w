<?php
/**
 * Test di DateRange.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\DateRange;
use PHPUnit\Framework\TestCase;

/**
 * Intervalli, periodo precedente, intersezione e blocchi mensili.
 */
final class DateRangeTest extends TestCase {

	public function test_rejects_invalid_or_inverted_dates(): void {
		$this->assertFalse( DateRange::is_valid( '2026-02-30' ) );
		$this->assertFalse( DateRange::is_valid( '14/10/2026' ) );
		$this->assertTrue( DateRange::is_valid( '2024-02-29' ) );

		$this->expectException( \InvalidArgumentException::class );
		new DateRange( '2026-10-14', '2026-10-01' );
	}

	public function test_days_counts_both_ends(): void {
		$this->assertSame( 1, ( new DateRange( '2026-10-14', '2026-10-14' ) )->days() );
		$this->assertSame( 30, ( new DateRange( '2026-09-15', '2026-10-14' ) )->days() );
	}

	public function test_previous_has_same_length_and_ends_the_day_before(): void {
		$previous = ( new DateRange( '2026-10-01', '2026-10-30' ) )->previous();

		$this->assertSame( '2026-09-01', $previous->from );
		$this->assertSame( '2026-09-30', $previous->to );
	}

	public function test_intersect(): void {
		$range = new DateRange( '2026-01-01', '2026-06-30' );

		$overlap = $range->intersect( new DateRange( '2026-04-17', '2026-10-14' ) );
		$this->assertNotNull( $overlap );
		$this->assertSame( array( '2026-04-17', '2026-06-30' ), array( $overlap->from, $overlap->to ) );

		$this->assertNull( $range->intersect( new DateRange( '2026-07-01', '2026-07-31' ) ) );
	}

	public function test_months_splits_on_calendar_months(): void {
		$chunks = ( new DateRange( '2026-01-20', '2026-03-05' ) )->months();

		$this->assertCount( 3, $chunks );
		$this->assertSame( array( '2026-01-20', '2026-01-31' ), array( $chunks[0]->from, $chunks[0]->to ) );
		$this->assertSame( array( '2026-02-01', '2026-02-28' ), array( $chunks[1]->from, $chunks[1]->to ) );
		$this->assertSame( array( '2026-03-01', '2026-03-05' ), array( $chunks[2]->from, $chunks[2]->to ) );
	}

	public function test_last_days(): void {
		$range = DateRange::last_days( 7, '2026-10-14' );

		$this->assertSame( '2026-10-08', $range->from );
		$this->assertSame( '2026-10-14', $range->to );
	}
}
