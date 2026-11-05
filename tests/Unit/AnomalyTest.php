<?php
/**
 * Test di Anomaly.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\Anomaly;
use PHPUnit\Framework\TestCase;

/**
 * Regola degli avvisi: mediana, picco, combinazione dominante e firma anomala.
 */
final class AnomalyTest extends TestCase {

	private const LIMITS = array(
		'countries' => 10,
		'share'     => 90,
		'ip_visits' => 10,
	);

	public function test_median_counts_missing_days_as_zero(): void {
		$days = array( '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05' );

		$this->assertSame( 0.0, Anomaly::median( array( '2026-10-05' => 40 ), $days ) );
		$this->assertSame( 3.0, Anomaly::median( array( '2026-10-01' => 3, '2026-10-02' => 3, '2026-10-03' => 3 ), $days ) );
	}

	public function test_median_of_an_even_number_of_days_and_of_no_days(): void {
		$this->assertSame( 5.0, Anomaly::median( array( 'a' => 2, 'b' => 4, 'c' => 6, 'd' => 100 ), array( 'a', 'b', 'c', 'd' ) ) );
		$this->assertSame( 0.0, Anomaly::median( array( 'a' => 2 ), array() ) );
	}

	public function test_median_ignores_a_past_burst_that_would_inflate_the_mean(): void {
		// Il bot sulla home di fine ottobre: tre giorni da 55, 122 e 96 in mezzo a giorni da 2-8 visitatori.
		$values = array( 6, 4, 6, 4, 4, 6, 2, 2, 8, 2, 4, 55, 122, 96, 21, 23, 11, 3, 9, 7 );
		$days   = array();
		$by_day = array();
		foreach ( $values as $i => $value ) {
			$days[]         = "d{$i}";
			$by_day[ "d{$i}" ] = $value;
		}

		$this->assertSame( 6.0, Anomaly::median( $by_day, $days ) );
		$this->assertGreaterThan( 19, array_sum( $values ) / count( $values ) );
	}

	public function test_peak_needs_the_minimum_and_k_times_the_median(): void {
		// Contenuto nuovo (mediana 0, vale 1): basta il minimo.
		$this->assertTrue( Anomaly::is_peak( 43, 0.0, 20, 5 ) );
		$this->assertFalse( Anomaly::is_peak( 19, 0.0, 20, 5 ) );
		// Home con mediana 6: servono 30 visitatori.
		$this->assertFalse( Anomaly::is_peak( 29, 6.0, 20, 5 ) );
		$this->assertTrue( Anomaly::is_peak( 30, 6.0, 20, 5 ) );
	}

	public function test_dominant_combo_and_share(): void {
		$this->assertSame(
			array(
				'combo' => 'Chrome|Windows',
				'share' => 100.0,
			),
			Anomaly::dominant( array( 'Chrome|Windows' => 43 ) )
		);
		$this->assertSame(
			array(
				'combo' => 'Chrome Mobile|Android',
				'share' => 50.8,
			),
			Anomaly::dominant( array( 'Chrome Mobile|Android' => 62, 'Chrome Mobile iOS|iOS' => 60 ) )
		);
	}

	public function test_unknown_combo_is_never_dominant(): void {
		// Visite senza browser e sistema (prima del riconoscimento): restano nel totale ma non dominano.
		$this->assertSame(
			array(
				'combo' => 'Firefox|Mac',
				'share' => 10.0,
			),
			Anomaly::dominant( array( '|' => 9, 'Firefox|Mac' => 1 ) )
		);
		$this->assertNull( Anomaly::dominant( array( '|' => 20 ) ) );
		$this->assertNull( Anomaly::dominant( array() ) );
	}

	public function test_signature_reasons(): void {
		// aocr.org: 20 paesi e 100% Chrome/Windows.
		$this->assertSame( array( 'countries', 'combo' ), Anomaly::signature( 20, 100.0, null, self::LIMITS ) );
		// Bot sulla home: 14 paesi, due combinazioni alla pari.
		$this->assertSame( array( 'countries' ), Anomaly::signature( 14, 49.6, null, self::LIMITS ) );
		// LinkedIn su un articolo nuovo: pochi paesi e browser vari.
		$this->assertSame( array(), Anomaly::signature( 3, 52.6, null, self::LIMITS ) );
		// Un solo IP con 12 visite.
		$this->assertSame( array( 'ip' ), Anomaly::signature( 1, 60.0, 12, self::LIMITS ) );
		// Senza hash dell'IP (visite di prima della v7) il terzo segnale non c'è.
		$this->assertSame( array(), Anomaly::signature( 1, null, null, self::LIMITS ) );
	}
}
