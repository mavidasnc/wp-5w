<?php
/**
 * Test di SchemaCheck.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\SchemaCheck;
use PHPUnit\Framework\TestCase;

/**
 * Riconoscimento degli schemi delle sorgenti.
 */
final class SchemaCheckTest extends TestCase {

	public function test_nothing_missing(): void {
		$this->assertSame(
			array(),
			SchemaCheck::missing(
				array( 'statistics_pages' => array( 'id', 'type' ) ),
				array( 'statistics_pages' => array( 'page_id', 'ID', 'Type' ) )
			)
		);
	}

	public function test_reports_missing_tables_and_columns(): void {
		$missing = SchemaCheck::missing(
			array(
				'statistics_visitor'      => array( 'last_counter', 'first_page' ),
				'rank_math_analytics_gsc' => array( 'created' ),
			),
			array(
				'statistics_visitor'      => array( 'last_counter' ),
				'rank_math_analytics_gsc' => null,
			)
		);

		$this->assertSame( array( 'statistics_visitor.first_page', 'tabella rank_math_analytics_gsc' ), $missing );
	}
}
