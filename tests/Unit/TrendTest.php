<?php
/**
 * Test di Trend.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\Trend;
use PHPUnit\Framework\TestCase;

/**
 * Finestre di 4 settimane complete e punteggio ponderato.
 */
final class TrendTest extends TestCase {

	public function test_windows_end_on_last_complete_sunday(): void {
		// Martedì 13/10/2026: l'ultima domenica è l'11/10.
		$windows = Trend::windows( '2026-10-13' );
		$this->assertSame( array( '2026-09-14', '2026-10-11' ), array( $windows['current']->from, $windows['current']->to ) );
		$this->assertSame( array( '2026-08-17', '2026-09-13' ), array( $windows['previous']->from, $windows['previous']->to ) );

		// Una domenica è già completa.
		$this->assertSame( '2026-10-11', Trend::windows( '2026-10-11' )['current']->to );
	}

	public function test_steady_values_are_stable(): void {
		$trend = Trend::score(
			array( 'visitors' => 1000, 'visibility' => 80, 'position' => 10.0 ),
			array( 'visitors' => 1000, 'visibility' => 80, 'position' => 10.0 )
		);
		$this->assertSame( 0, $trend['score'] );
		$this->assertSame( 'stable', $trend['status'] );
	}

	public function test_weighted_score_and_inverse_position(): void {
		// Visite raddoppiate, visibilità invariata, posizione da 10 a 8 (migliora del 20%).
		$trend = Trend::score(
			array( 'visitors' => 2000, 'visibility' => 80, 'position' => 8.0 ),
			array( 'visitors' => 1000, 'visibility' => 80, 'position' => 10.0 )
		);
		$expected = (int) round( ( 0.5 * tanh( 2 * log( 2001 / 1001 ) ) + 0.25 * tanh( 0.4 ) ) * 100 );
		$this->assertSame( $expected, $trend['score'] );
		$this->assertSame( 'up', $trend['status'] );
		$this->assertGreaterThan( 0, $trend['components']['position']['value'] );

		// Posizione peggiorata: componente negativa.
		$worse = Trend::score(
			array( 'visitors' => 1000, 'visibility' => 80, 'position' => 15.0 ),
			array( 'visitors' => 1000, 'visibility' => 80, 'position' => 10.0 )
		);
		$this->assertSame( 'down', $worse['status'] );
	}

	public function test_missing_search_console_redistributes_weights(): void {
		// Nessun dato di Google: conta solo il traffico (calo del 50%).
		$trend = Trend::score(
			array( 'visitors' => 500, 'visibility' => 0, 'position' => null ),
			array( 'visitors' => 1000, 'visibility' => 0, 'position' => null )
		);
		$this->assertNull( $trend['components']['visibility']['value'] );
		$this->assertNull( $trend['components']['position']['value'] );
		$this->assertSame( (int) round( tanh( 2 * log( 501 / 1001 ) ) * 100 ), $trend['score'] );
	}

	public function test_few_visitors_are_insufficient(): void {
		$trend = Trend::score(
			array( 'visitors' => 12, 'visibility' => 3, 'position' => 9.0 ),
			array( 'visitors' => 4, 'visibility' => 1, 'position' => 20.0 )
		);
		$this->assertNull( $trend['score'] );
		$this->assertSame( 'insufficient', $trend['status'] );
	}
}
