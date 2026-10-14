<?php
/**
 * Test del raggruppamento delle dimensioni del pubblico.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\Dimensions;
use PHPUnit\Framework\TestCase;

/**
 * Valori sconosciuti, ordine, limite di righe e totale.
 */
final class DimensionsTest extends TestCase {

	/**
	 * Vuoto, (n/d) e UNK si sommano in una sola riga.
	 */
	public function test_unknown_values_are_merged(): void {
		$result = Dimensions::top(
			array(
				''      => 2,
				'(n/d)' => 3,
				'UNK'   => 1,
			)
		);

		$this->assertSame(
			array(
				'total' => 6,
				'rows'  => array(
					array(
						'value'    => '(n/d)',
						'visitors' => 6,
					),
				),
				'other' => 0,
			),
			$result
		);
	}

	/**
	 * A parità di visitatori decide il valore.
	 */
	public function test_order_by_visitors_then_value(): void {
		$result = Dimensions::top(
			array(
				'US' => 5,
				'IT' => 5,
				'DE' => 7,
			)
		);

		$this->assertSame( array( 'DE', 'IT', 'US' ), array_column( $result['rows'], 'value' ) );
	}

	/**
	 * L'ordine del valore è binario (maiuscole prima), come BINARY in SQL.
	 */
	public function test_binary_order_on_ties(): void {
		$result = Dimensions::top(
			array(
				'Opera'       => 2,
				'opera mini'  => 2,
				'Opera Mobile' => 2,
			)
		);

		$this->assertSame( array( 'Opera', 'Opera Mobile', 'opera mini' ), array_column( $result['rows'], 'value' ) );
	}

	/**
	 * Oltre il limite le righe escluse vanno in other e il totale le comprende tutte.
	 */
	public function test_limit_other_and_total(): void {
		$values = array();
		for ( $i = 1; $i <= 15; $i++ ) {
			$values[ 'v' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ) ] = 100 - $i;
		}

		$result = Dimensions::top( $values );

		$this->assertCount( 10, $result['rows'] );
		$this->assertSame( array_sum( $values ), $result['total'] );
		$this->assertSame( array_sum( array_slice( $values, 10 ) ), $result['other'] );
		$this->assertSame( 'v01', $result['rows'][0]['value'] );
	}

	/**
	 * Un valore numerico (chiave intera in PHP) resta una stringa.
	 */
	public function test_numeric_keys_stay_strings(): void {
		$result = Dimensions::top( array( '10' => 4 ) );

		$this->assertSame( '10', $result['rows'][0]['value'] );
	}

	/**
	 * Nessun dato.
	 */
	public function test_empty_input(): void {
		$this->assertSame(
			array(
				'total' => 0,
				'rows'  => array(),
				'other' => 0,
			),
			Dimensions::top( array() )
		);
	}
}
