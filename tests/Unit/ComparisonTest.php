<?php
/**
 * Test di Comparison.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\Comparison;
use PHPUnit\Framework\TestCase;

/**
 * Finestra dei giorni, soglia, quote degli scarti e classifica dei contenuti.
 */
final class ComparisonTest extends TestCase {

	/**
	 * Elenco dei giorni Y-m-d da $from a $to (estremi compresi), per costruire le righe `site_daily`.
	 *
	 * @param string $from Primo giorno.
	 * @param string $to   Ultimo giorno.
	 * @return array<int,string>
	 */
	private static function range( string $from, string $to ): array {
		$days = array();
		for ( $d = new \DateTimeImmutable( $from, new \DateTimeZone( 'UTC' ) ); $d->format( 'Y-m-d' ) <= $to; $d = $d->modify( '+1 day' ) ) {
			$days[] = $d->format( 'Y-m-d' );
		}
		return $days;
	}

	/**
	 * Esclusi come mappa giorno => motivo (comoda per le asserzioni).
	 *
	 * @param array<string,mixed> $plan Risultato di plan().
	 * @return array<string,string>
	 */
	private static function reasons( array $plan ): array {
		return array_column( $plan['excluded'], 'reason', 'day' );
	}

	public function test_plan_without_own_rows_is_empty(): void {
		$plan = Comparison::plan( null, null, '2026-10-28', self::range( '2026-10-14', '2026-10-28' ), array() );

		$this->assertSame(
			array(
				'from'     => null,
				'to'       => null,
				'days'     => array(),
				'excluded' => array(),
			),
			$plan
		);
	}

	public function test_plan_first_day_today_has_no_partial_and_empty_window(): void {
		$etl  = array(
			'day' => '2026-10-14',
			'to'  => '2026-10-14',
		);
		$plan = Comparison::plan( '2026-10-14', $etl, '2026-10-14', array( '2026-10-14' ), array( '2026-10-14' ) );

		$this->assertNull( $plan['from'] );
		$this->assertNull( $plan['to'] );
		$this->assertSame( array(), $plan['days'] );
		$this->assertSame( array( array( 'day' => '2026-10-14', 'reason' => 'today' ) ), $plan['excluded'] );
	}

	public function test_plan_typical_case(): void {
		$etl  = array(
			'day' => '2026-10-28',
			'to'  => '2026-10-28',
		);
		$all  = self::range( '2026-10-14', '2026-10-28' );
		$plan = Comparison::plan( '2026-10-14', $etl, '2026-10-28', $all, $all );

		$this->assertSame( '2026-10-15', $plan['from'] );
		$this->assertSame( '2026-10-27', $plan['to'] );
		$this->assertCount( 13, $plan['days'] );
		$this->assertSame( array( '2026-10-14' => 'partial', '2026-10-28' => 'today' ), self::reasons( $plan ) );
	}

	public function test_plan_with_ia_starts_after_its_first_day_and_marks_it_partial(): void {
		// Raccolta propria dal 14/10, Independent Analytics installato il 20/10: la finestra parte il 21.
		$etl  = array(
			'day' => '2026-10-28',
			'to'  => '2026-10-27',
		);
		$all  = self::range( '2026-10-14', '2026-10-28' );
		$plan = Comparison::plan( '2026-10-14', $etl, '2026-10-28', $all, $all, '2026-10-20', self::range( '2026-10-20', '2026-10-28' ) );

		$this->assertSame( '2026-10-21', $plan['from'] );
		$this->assertSame( '2026-10-27', $plan['to'] );
		$this->assertCount( 7, $plan['days'] );
		$this->assertSame(
			array(
				'2026-10-14' => 'partial',
				'2026-10-20' => 'ia_partial',
				'2026-10-28' => 'today',
			),
			self::reasons( $plan )
		);
	}

	public function test_plan_with_ia_installed_before_own_uses_own_window(): void {
		// IA c'era già prima della raccolta propria: il suo primo giorno non è un limite e non compare tra gli esclusi.
		$etl  = array(
			'day' => '2026-10-28',
			'to'  => '2026-10-27',
		);
		$all  = self::range( '2026-10-10', '2026-10-28' );
		$plan = Comparison::plan( '2026-10-14', $etl, '2026-10-28', $all, $all, '2026-10-10', $all );

		$this->assertSame( '2026-10-15', $plan['from'] );
		$this->assertSame( array( '2026-10-14' => 'partial', '2026-10-28' => 'today' ), self::reasons( $plan ) );
	}

	public function test_plan_with_ia_same_first_day_as_own_has_a_single_partial(): void {
		$etl  = array(
			'day' => '2026-10-28',
			'to'  => '2026-10-27',
		);
		$all  = self::range( '2026-10-14', '2026-10-28' );
		$plan = Comparison::plan( '2026-10-14', $etl, '2026-10-28', $all, $all, '2026-10-14', $all );

		$this->assertSame( '2026-10-15', $plan['from'] );
		$this->assertSame( array( '2026-10-14' => 'partial', '2026-10-28' => 'today' ), self::reasons( $plan ) );
	}

	public function test_plan_with_ia_marks_days_without_ia_rows(): void {
		$etl  = array(
			'day' => '2026-10-28',
			'to'  => '2026-10-27',
		);
		$all  = self::range( '2026-10-14', '2026-10-28' );
		$ia   = array_values( array_diff( self::range( '2026-10-14', '2026-10-28' ), array( '2026-10-22' ) ) );
		$plan = Comparison::plan( '2026-10-14', $etl, '2026-10-28', $all, $all, '2026-10-14', $ia );

		$this->assertSame( 'no_ia', self::reasons( $plan )['2026-10-22'] );
		$this->assertNotContains( '2026-10-22', $plan['days'] );
	}

	public function test_plan_with_ia_first_day_today_has_empty_window(): void {
		$etl  = array(
			'day' => '2026-10-28',
			'to'  => '2026-10-27',
		);
		$all  = self::range( '2026-10-14', '2026-10-28' );
		$plan = Comparison::plan( '2026-10-14', $etl, '2026-10-28', $all, $all, '2026-10-28', array( '2026-10-28' ) );

		$this->assertNull( $plan['to'] );
		$this->assertSame( array(), $plan['days'] );
		$this->assertArrayNotHasKey( '2026-10-28', array_filter( self::reasons( $plan ), static fn( string $r ): bool => 'ia_partial' === $r ) );
	}

	public function test_plan_etl_not_yet_run_today_leaves_yesterday_pending(): void {
		// ETL di ieri (27/10), oggi 28/10: la riga `wps` del 27 è ancora parziale.
		$etl  = array(
			'day' => '2026-10-27',
			'to'  => '2026-10-27',
		);
		$all  = self::range( '2026-10-14', '2026-10-28' );
		$plan = Comparison::plan( '2026-10-14', $etl, '2026-10-28', $all, $all );

		$this->assertSame( '2026-10-26', $plan['to'] );
		$this->assertSame( 'etl_pending', self::reasons( $plan )['2026-10-27'] );
		$this->assertNotContains( '2026-10-27', $plan['days'] );
	}

	public function test_plan_older_etl_range_marks_following_days_pending(): void {
		// L'ultimo ETL (di oggi) ha però `to` = 20/10: i giorni dal 21 al 27 non sono stati rielaborati.
		$etl  = array(
			'day' => '2026-10-28',
			'to'  => '2026-10-20',
		);
		$all  = self::range( '2026-10-14', '2026-10-28' );
		$plan = Comparison::plan( '2026-10-14', $etl, '2026-10-28', $all, $all );

		$this->assertSame( '2026-10-20', $plan['to'] );
		$reasons = self::reasons( $plan );
		foreach ( self::range( '2026-10-21', '2026-10-27' ) as $day ) {
			$this->assertSame( 'etl_pending', $reasons[ $day ] );
		}
	}

	public function test_plan_without_etl_has_empty_window_and_all_days_pending(): void {
		$all  = self::range( '2026-10-14', '2026-10-28' );
		$plan = Comparison::plan( '2026-10-14', null, '2026-10-28', $all, $all );

		$this->assertNull( $plan['from'] );
		$this->assertNull( $plan['to'] );
		$this->assertSame( array(), $plan['days'] );

		$reasons = self::reasons( $plan );
		foreach ( self::range( '2026-10-15', '2026-10-27' ) as $day ) {
			$this->assertSame( 'etl_pending', $reasons[ $day ] );
		}
		$this->assertSame( 'partial', $reasons['2026-10-14'] );
		$this->assertSame( 'today', $reasons['2026-10-28'] );
	}

	public function test_plan_excludes_days_missing_one_origin(): void {
		$etl = array(
			'day' => '2026-10-28',
			'to'  => '2026-10-28',
		);
		// Il 17 manca `wps`, il 19 manca `own`, il 21 mancano entrambe (vale `no_wps`).
		$wps  = array_values( array_diff( self::range( '2026-10-14', '2026-10-28' ), array( '2026-10-17', '2026-10-21' ) ) );
		$own  = array_values( array_diff( self::range( '2026-10-14', '2026-10-28' ), array( '2026-10-19', '2026-10-21' ) ) );
		$plan = Comparison::plan( '2026-10-14', $etl, '2026-10-28', $wps, $own );

		$reasons = self::reasons( $plan );
		$this->assertSame( 'no_wps', $reasons['2026-10-17'] );
		$this->assertSame( 'no_own', $reasons['2026-10-19'] );
		$this->assertSame( 'no_wps', $reasons['2026-10-21'] );
		$this->assertNotContains( '2026-10-17', $plan['days'] );
		$this->assertNotContains( '2026-10-19', $plan['days'] );
		$this->assertNotContains( '2026-10-21', $plan['days'] );
		$this->assertCount( 10, $plan['days'] );
	}

	public function test_plan_orders_days_and_excluded_ascending(): void {
		$etl  = array(
			'day' => '2026-10-28',
			'to'  => '2026-10-28',
		);
		$all  = self::range( '2026-10-14', '2026-10-28' );
		$plan = Comparison::plan( '2026-10-14', $etl, '2026-10-28', array_diff( $all, array( '2026-10-20' ) ), $all );

		$sorted = $plan['days'];
		sort( $sorted );
		$this->assertSame( $sorted, $plan['days'] );

		$excluded_days = array_column( $plan['excluded'], 'day' );
		$sorted        = $excluded_days;
		sort( $sorted );
		$this->assertSame( $sorted, $excluded_days );
	}

	public function test_plan_across_daylight_saving_change_skips_no_day(): void {
		// In Italia l'ora legale finisce il 25/10/2026: con un fuso locale un +86400 salterebbe o raddoppierebbe un giorno.
		$previous = date_default_timezone_get();
		date_default_timezone_set( 'Europe/Rome' );
		try {
			$etl  = array(
				'day' => '2026-10-28',
				'to'  => '2026-10-28',
			);
			$all  = self::range( '2026-10-22', '2026-10-28' );
			$plan = Comparison::plan( '2026-10-22', $etl, '2026-10-28', $all, $all );
		} finally {
			date_default_timezone_set( $previous );
		}

		$this->assertSame( array( '2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26', '2026-10-27' ), $plan['days'] );
		$this->assertSame( array( '2026-10-22', '2026-10-28' ), array_column( $plan['excluded'], 'day' ) );
	}

	public function test_flagged_threshold(): void {
		$this->assertFalse( Comparison::flagged( -15.0, null ) );
		$this->assertTrue( Comparison::flagged( -15.1, 0.0 ) );
		$this->assertTrue( Comparison::flagged( null, 16.0 ) );
		$this->assertFalse( Comparison::flagged( null, null ) );
		$this->assertTrue( Comparison::flagged( 20.0, -3.0 ) );
	}

	public function test_discard_shares_lists_all_reasons_in_order(): void {
		$shares = Comparison::discard_shares( array(), 0 );

		$this->assertSame( array( 'bot', 'user', 'dnt', 'rate', 'signature', 'ip' ), array_keys( $shares['reasons'] ) );
		$this->assertSame( 0, $shares['total'] );
		// Denominatore zero: nessuna quota.
		$this->assertNull( $shares['reasons']['bot']['share'] );
		$this->assertSame( 0, $shares['reasons']['bot']['n'] );
	}

	public function test_discard_shares_are_on_requests_not_visitors(): void {
		$shares = Comparison::discard_shares(
			array(
				'bot'  => 5,
				'rate' => 1,
			),
			94
		);

		$this->assertSame( 6, $shares['total'] );
		$this->assertSame( 5.0, $shares['reasons']['bot']['share'] );
		$this->assertSame( 1.0, $shares['reasons']['rate']['share'] );
		$this->assertSame( 0.0, $shares['reasons']['user']['share'] );
	}

	public function test_discard_shares_unknown_reason_goes_last(): void {
		$shares = Comparison::discard_shares(
			array(
				'nuovo' => 2,
				'bot'   => 3,
			),
			95
		);

		$this->assertSame( 'nuovo', array_key_last( $shares['reasons'] ) );
		$this->assertSame( 5, $shares['total'] );
		$this->assertSame( 2, $shares['reasons']['nuovo']['n'] );
	}

	/**
	 * Riga di prova per top_differences().
	 *
	 * @param string $type Tipo.
	 * @param int    $id   ID.
	 * @param int    $wps  Visualizzazioni WP Statistics.
	 * @param int    $own  Visualizzazioni proprie.
	 * @return array{type:string,id:int,uri:string,wps:int,own:int}
	 */
	private static function content( string $type, int $id, int $wps, int $own ): array {
		return array(
			'type' => $type,
			'id'   => $id,
			'uri'  => "/{$type}-{$id}/",
			'wps'  => $wps,
			'own'  => $own,
		);
	}

	public function test_top_with_ia_adds_ia_differences_and_ranks_by_the_largest(): void {
		$rows = array(
			array( 'type' => 'post', 'id' => 1, 'uri' => '/a/', 'wps' => 100, 'own' => 98, 'ia' => 60 ),
			array( 'type' => 'post', 'id' => 2, 'uri' => '/b/', 'wps' => 100, 'own' => 90, 'ia' => 95 ),
			array( 'type' => 'post', 'id' => 3, 'uri' => '/c/', 'wps' => 5, 'own' => 4, 'ia' => 12 ),
			array( 'type' => 'post', 'id' => 4, 'uri' => '/d/', 'wps' => 3, 'own' => 2, 'ia' => 1 ),
		);
		$top  = Comparison::top_differences( $rows );

		// L'ultimo non arriva a MIN_VIEWS in nessuna origine; il terzo ci arriva solo con IA.
		$this->assertSame( array( 1, 2, 3 ), array_column( $top, 'id' ) );
		$this->assertSame( 40, $top[0]['diff_abs_ia'] );
		$this->assertSame( -40.0, $top[0]['diff_ia'] );
		$this->assertSame( 2, $top[0]['diff_abs'] );
	}

	public function test_top_without_ia_has_no_ia_keys(): void {
		$top = Comparison::top_differences( array( array( 'type' => 'post', 'id' => 1, 'uri' => '/a/', 'wps' => 20, 'own' => 10 ) ) );

		$this->assertArrayNotHasKey( 'diff_ia', $top[0] );
		$this->assertArrayNotHasKey( 'diff_abs_ia', $top[0] );
	}

	public function test_top_applies_minimum_views(): void {
		$top = Comparison::top_differences( array( self::content( 'post', 1, 9, 0 ), self::content( 'post', 2, 10, 0 ) ) );

		$this->assertCount( 1, $top );
		$this->assertSame( 2, $top[0]['id'] );
		$this->assertSame( 10, $top[0]['diff_abs'] );
		$this->assertSame( -100.0, $top[0]['diff'] );
	}

	public function test_top_orders_by_absolute_difference_not_percentage(): void {
		$top = Comparison::top_differences( array( self::content( 'post', 1, 12, 1 ), self::content( 'post', 2, 200, 150 ) ) );

		$this->assertSame( array( 2, 1 ), array_column( $top, 'id' ) );
	}

	public function test_top_ties_are_ordered_by_type_and_id(): void {
		$top = Comparison::top_differences(
			array(
				self::content( 'post', 9, 20, 10 ),
				self::content( 'page', 5, 20, 10 ),
				self::content( 'post', 3, 20, 10 ),
			)
		);

		$this->assertSame( array( 'page-5', 'post-3', 'post-9' ), array_map( static fn( array $r ): string => $r['type'] . '-' . $r['id'], $top ) );
	}

	public function test_top_is_limited_and_diff_is_null_without_wps(): void {
		$rows = array();
		for ( $i = 1; $i <= 15; $i++ ) {
			$rows[] = self::content( 'post', $i, 0, 10 + $i );
		}
		$top = Comparison::top_differences( $rows );

		$this->assertCount( Comparison::TOP, $top );
		$this->assertNull( $top[0]['diff'] );
		$this->assertSame( 15, $top[0]['id'] );
	}
}
