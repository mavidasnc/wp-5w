<?php
/**
 * Test di Series.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\Series;
use PHPUnit\Framework\TestCase;

/**
 * Riempimento dei giorni senza dati.
 */
final class SeriesTest extends TestCase {

	public function test_fills_missing_days_with_zero(): void {
		$points = Series::fill(
			new DateRange( '2026-10-12', '2026-10-14' ),
			array(
				'2026-10-12' => array( 'views' => '5', 'visitors' => 3 ),
				'2026-10-14' => (object) array( 'views' => 7 ),
			),
			array( 'views', 'visitors' )
		);

		$this->assertSame(
			array(
				array( 'day' => '2026-10-12', 'views' => 5, 'visitors' => 3 ),
				array( 'day' => '2026-10-13', 'views' => 0, 'visitors' => 0 ),
				array( 'day' => '2026-10-14', 'views' => 7, 'visitors' => 0 ),
			),
			$points
		);
	}

	public function test_ignores_rows_outside_the_range(): void {
		$points = Series::fill( new DateRange( '2026-10-14', '2026-10-14' ), array( '2026-10-01' => array( 'views' => 9 ) ), array( 'views' ) );

		$this->assertSame( array( array( 'day' => '2026-10-14', 'views' => 0 ) ), $points );
	}
}
