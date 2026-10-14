<?php
/**
 * Test di Months e Metrics::compare.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\Metrics;
use Mavida\FiveW\Support\Months;
use PHPUnit\Framework\TestCase;

/**
 * Raggruppamento per mese e confronto dei KPI.
 */
final class MonthsTest extends TestCase {

	public function test_first_day_of_window(): void {
		$this->assertSame( '2026-05-01', Months::first_day( '2026-10-14', 6 ) );
		// Il 31 non deve far slittare il mese (31/08 -5 mesi non è "31/03 -> 03/04").
		$this->assertSame( '2026-03-01', Months::first_day( '2026-08-31', 6 ) );
		$this->assertSame( '2025-12-01', Months::first_day( '2026-01-15', 2 ) );
	}

	public function test_groups_by_month_with_empty_months_and_averages_on_days_with_data(): void {
		$groups = Months::group(
			array(
				array( 'day' => '2026-08-01', 'visitors' => 10, 'views' => 20 ),
				array( 'day' => '2026-08-31', 'visitors' => '20', 'views' => '30' ),
				array( 'day' => '2026-10-14', 'visitors' => 5, 'views' => 7 ), // Mese corrente parziale.
				array( 'day' => '2026-04-30', 'visitors' => 99, 'views' => 99 ), // Fuori finestra.
			),
			'2026-08-01',
			3
		);

		$this->assertSame( array( '2026-08', '2026-09', '2026-10' ), array_column( $groups, 'month' ) );
		$this->assertSame( array( 2, 30, 50, 15.0, 25.0 ), array( $groups[0]['days'], $groups[0]['visitors'], $groups[0]['views'], $groups[0]['avg_visitors'], $groups[0]['avg_views'] ) );
		$this->assertSame( 0, $groups[1]['days'] );
		$this->assertSame( 0.0, $groups[1]['avg_visitors'] );
		$this->assertSame( array( 1, 5, 7, 5.0 ), array( $groups[2]['days'], $groups[2]['visitors'], $groups[2]['views'], $groups[2]['avg_visitors'] ) );
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
