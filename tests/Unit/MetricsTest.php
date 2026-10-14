<?php
/**
 * Test di Metrics.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\Metrics;
use PHPUnit\Framework\TestCase;

/**
 * CTR, posizione media pesata e variazioni.
 */
final class MetricsTest extends TestCase {

	public function test_ctr(): void {
		$this->assertSame( 4.0, Metrics::ctr( 2, 50 ) );
		$this->assertNull( Metrics::ctr( 0, 0 ) );
	}

	public function test_weighted_position_weights_by_impressions(): void {
		// Query A: posizione 1 con 90 impression; query B: posizione 20 con 10 impression.
		$pos_x_impr = 1 * 90 + 20 * 10;

		// Media pesata 2,9 (la media semplice darebbe 10,5).
		$this->assertSame( 2.9, Metrics::weighted_position( $pos_x_impr, 100 ) );
		$this->assertNull( Metrics::weighted_position( 0.0, 0 ) );
	}

	public function test_change(): void {
		$this->assertSame( 50.0, Metrics::change( 150, 100 ) );
		$this->assertSame( -25.0, Metrics::change( 75, 100 ) );
		$this->assertNull( Metrics::change( 10, 0 ) );
	}
}
