<?php
/**
 * Test di TodayWindow.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\TodayWindow;
use PHPUnit\Framework\TestCase;

/**
 * Giorni e medie della scheda «Oggi».
 */
final class TodayWindowTest extends TestCase {

	public function test_long_history_gives_the_last_n_full_days_up_to_yesterday(): void {
		$days = TodayWindow::days( '2026-10-27', '2026-01-01', 7 );

		$this->assertSame( array( '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26' ), $days );
	}

	public function test_window_crosses_the_daylight_saving_change_without_skipping_days(): void {
		// Il 25/10/2026 torna l'ora solare: i giorni restano consecutivi.
		$days = TodayWindow::days( '2026-10-27', '2026-01-01', 30 );

		$this->assertCount( 30, $days );
		$this->assertSame( '2026-09-27', $days[0] );
		$this->assertContains( '2026-10-25', $days );
		$this->assertSame( '2026-10-26', $days[29] );
	}

	public function test_first_collection_day_is_always_excluded_because_it_is_partial(): void {
		$days = TodayWindow::days( '2026-10-27', '2026-10-22', 7 );

		$this->assertSame( array( '2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26' ), $days );
	}

	public function test_first_day_yesterday_or_today_leaves_no_full_day(): void {
		$this->assertSame( array(), TodayWindow::days( '2026-10-27', '2026-10-26', 7 ) );
		$this->assertSame( array(), TodayWindow::days( '2026-10-27', '2026-10-27', 7 ) );
	}

	public function test_no_hits_or_invalid_size_give_no_days(): void {
		$this->assertSame( array(), TodayWindow::days( '2026-10-27', null, 7 ) );
		$this->assertSame( array(), TodayWindow::days( '2026-10-27', '2026-01-01', 0 ) );
	}

	public function test_mean_counts_a_day_without_visits_as_zero(): void {
		$days = array( '2026-10-24', '2026-10-25', '2026-10-26' );

		$this->assertSame(
			4.0,
			TodayWindow::mean(
				array(
					'2026-10-24' => 6,
					'2026-10-26' => 6,
				),
				$days
			)
		);
	}

	public function test_mean_without_days_is_null(): void {
		$this->assertNull( TodayWindow::mean( array( '2026-10-24' => 6 ), array() ) );
	}
}
