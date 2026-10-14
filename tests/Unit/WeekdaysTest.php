<?php
/**
 * Test di Weekdays e Metrics::compare.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\Metrics;
use Mavida\FiveW\Support\Weekdays;
use PHPUnit\Framework\TestCase;

/**
 * Raggruppamento per giorno della settimana e confronto dei KPI.
 */
final class WeekdaysTest extends TestCase {

	public function test_groups_by_iso_weekday_with_averages_on_days_with_data(): void {
		$groups = Weekdays::group(
			array(
				array( 'day' => '2026-10-12', 'visitors' => 10, 'views' => 20 ), // Lunedì.
				array( 'day' => '2026-10-19', 'visitors' => '20', 'views' => '30' ), // Lunedì.
				array( 'day' => '2026-10-18', 'visitors' => 5, 'views' => 7 ), // Domenica.
			)
		);

		$this->assertCount( 7, $groups );
		$this->assertSame( array( 1, 2, 30, 50, 15.0, 25.0 ), array( $groups[0]['weekday'], $groups[0]['days'], $groups[0]['visitors'], $groups[0]['views'], $groups[0]['avg_visitors'], $groups[0]['avg_views'] ) );
		$this->assertSame( 0, $groups[1]['days'] );
		$this->assertSame( 0.0, $groups[1]['avg_visitors'] );
		$this->assertSame( array( 7, 1, 5, 7 ), array( $groups[6]['weekday'], $groups[6]['days'], $groups[6]['visitors'], $groups[6]['views'] ) );
	}

	public function test_compare(): void {
		$kpis = Metrics::compare(
			array(
				'clicks' => 150,
				'ctr'    => null,
			),
			array(
				'clicks' => 100,
				'ctr'    => 2.0,
			)
		);

		$this->assertSame( 50.0, $kpis['clicks']['change'] );
		$this->assertSame( 100, $kpis['clicks']['previous'] );
		$this->assertNull( $kpis['ctr']['change'] );
	}
}
