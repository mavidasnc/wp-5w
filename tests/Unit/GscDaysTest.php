<?php
/**
 * Test di GscDays e Secret.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\GscDays;
use Mavida\FiveW\Support\Secret;
use PHPUnit\Framework\TestCase;

/**
 * Giorni mancanti, lotti, URL relativi, filtro degli host e cifratura dei segreti.
 */
final class GscDaysTest extends TestCase {

	public function test_window_covers_sixteen_months_up_to_last_final_day(): void {
		$window = GscDays::window( '2026-10-17', '2026-10-14' );
		$this->assertSame( '2025-06-18', $window->from );
		$this->assertSame( '2026-10-14', $window->to );
	}

	public function test_window_does_not_slip_a_month_on_the_thirty_first(): void {
		// Dal 31 ottobre si parte dalla fine di giugno 2025 (giugno ha 30 giorni), non da luglio.
		$this->assertSame( '2025-07-01', GscDays::window( '2026-10-31', '2026-10-28' )->from );
	}

	public function test_window_is_null_when_nothing_is_final_yet(): void {
		$this->assertNull( GscDays::window( '2026-10-17', '2025-01-01' ) );
	}

	public function test_missing_skips_api_and_empty_days(): void {
		$window  = new DateRange( '2026-10-01', '2026-10-07' );
		$missing = GscDays::missing( $window, array( '2026-10-01', '2026-10-02', '2026-10-05' ), array( '2026-10-03' ) );
		$this->assertSame( array( '2026-10-04', '2026-10-06', '2026-10-07' ), $missing );
	}

	public function test_missing_is_empty_when_everything_is_imported(): void {
		$window = new DateRange( '2026-10-01', '2026-10-02' );
		$this->assertSame( array(), GscDays::missing( $window, array( '2026-10-01', '2026-10-02' ), array() ) );
	}

	public function test_next_batch_starts_at_first_missing_day_and_is_capped(): void {
		$all = GscDays::missing( new DateRange( '2026-01-01', '2026-03-31' ), array(), array() );
		$one = GscDays::next_batch( $all, 30 );
		$this->assertSame( '2026-01-01', $one->from );
		$this->assertSame( '2026-01-30', $one->to );
		$this->assertSame( 30, $one->days() );
	}

	public function test_next_batch_closes_on_the_last_missing_day_inside_the_span(): void {
		// Il lotto non si allunga su giorni già importati in fondo all'intervallo.
		$batch = GscDays::next_batch( array( '2026-10-04', '2026-10-06', '2026-11-20' ), 30 );
		$this->assertSame( '2026-10-04', $batch->from );
		$this->assertSame( '2026-10-06', $batch->to );
	}

	public function test_next_batch_is_null_without_missing_days(): void {
		$this->assertNull( GscDays::next_batch( array() ) );
	}

	public function test_in_batch_keeps_only_missing_days_of_the_range(): void {
		$missing = array( '2026-10-04', '2026-10-06', '2026-11-20' );
		$this->assertSame( array( '2026-10-04', '2026-10-06' ), GscDays::in_batch( $missing, new DateRange( '2026-10-04', '2026-10-31' ) ) );
	}

	public function test_relative_path_matches_the_backfill_script(): void {
		// Stessi casi di relative_path() in scripts/gsc-backfill.py.
		$this->assertSame( '/', GscDays::relative_path( 'https://maurizio.mavida.com/' ) );
		$this->assertSame( '/', GscDays::relative_path( 'https://maurizio.mavida.com' ) );
		$this->assertSame( '/blog/', GscDays::relative_path( 'https://maurizio.mavida.com/blog/' ) );
		$this->assertSame( '/?s=claude', GscDays::relative_path( 'https://maurizio.mavida.com/?s=claude' ) );
		$this->assertSame( '/guida/?a=1&b=2', GscDays::relative_path( 'https://maurizio.mavida.com/guida/?a=1&b=2' ) );
		// Il frammento non fa parte della pagina.
		$this->assertSame( '/guida/', GscDays::relative_path( 'https://maurizio.mavida.com/guida/#indice' ) );
		// Percorso con caratteri codificati: resta com'è.
		$this->assertSame( '/caff%C3%A8/', GscDays::relative_path( 'https://maurizio.mavida.com/caff%C3%A8/' ) );
	}

	public function test_group_normalizes_and_groups_by_day(): void {
		$rows = array(
			array(
				'keys'        => array( '2026-10-10', 'https://maurizio.mavida.com/a/', 'uno' ),
				'clicks'      => 2,
				'impressions' => 20,
				'position'    => 3.5,
			),
			array(
				'keys'        => array( '2026-10-09', 'https://maurizio.mavida.com/a/#x', 'uno' ),
				'clicks'      => 1,
				'impressions' => 5,
				'position'    => 8.0,
			),
		);

		$days = GscDays::group( $rows );

		$this->assertSame( array( '2026-10-09', '2026-10-10' ), array_keys( $days ) );
		$this->assertSame( '/a/', $days['2026-10-09'][0]['page'] );
		$this->assertSame( 2, $days['2026-10-10'][0]['clicks'] );
		$this->assertSame( 3.5, $days['2026-10-10'][0]['position'] );
	}

	public function test_group_drops_other_hosts_of_a_domain_property(): void {
		$rows = array(
			array(
				'keys'        => array( '2026-10-10', 'https://maurizio.mavida.com/', 'mio' ),
				'clicks'      => 1,
				'impressions' => 1,
				'position'    => 1,
			),
			array(
				'keys'        => array( '2026-10-10', 'https://www.mavida.com/', 'altro sito' ),
				'clicks'      => 9,
				'impressions' => 9,
				'position'    => 1,
			),
			array(
				'keys'        => array( '2026-10-10', 'http://MAURIZIO.mavida.com/blog/', 'mio http' ),
				'clicks'      => 1,
				'impressions' => 1,
				'position'    => 1,
			),
		);

		$days = GscDays::group( $rows, 'maurizio.mavida.com' );

		$this->assertSame( array( 'mio', 'mio http' ), array_column( $days['2026-10-10'], 'query' ) );
	}

	public function test_group_can_keep_only_requested_days_and_skips_invalid_rows(): void {
		$rows = array(
			array(
				'keys'        => array( '2026-10-10', 'https://x.it/', 'tenuto' ),
				'clicks'      => 1,
				'impressions' => 1,
				'position'    => 1,
			),
			array(
				'keys'        => array( '2026-10-11', 'https://x.it/', 'scartato' ),
				'clicks'      => 1,
				'impressions' => 1,
				'position'    => 1,
			),
			array(
				'keys'   => array( '2026-10-10' ),
				'clicks' => 1,
			),
			array( 'clicks' => 1 ),
		);

		$days = GscDays::group( $rows, null, array( '2026-10-10' ) );

		$this->assertSame( array( '2026-10-10' ), array_keys( $days ) );
		$this->assertCount( 1, $days['2026-10-10'] );
	}

	public function test_pick_site_prefers_the_url_prefix_that_matches_the_home(): void {
		$sites = array( 'sc-domain:mavida.com', 'https://maurizio.mavida.com/', 'https://altro.it/' );
		$this->assertSame( 'https://maurizio.mavida.com/', GscDays::pick_site( $sites, 'https://maurizio.mavida.com' ) );
	}

	public function test_pick_site_falls_back_to_the_domain_property(): void {
		$sites = array( 'https://altro.it/', 'sc-domain:mavida.com', 'sc-domain:vanut.net' );
		$this->assertSame( 'sc-domain:mavida.com', GscDays::pick_site( $sites, 'https://maurizio.mavida.com/' ) );
		$this->assertNull( GscDays::pick_site( $sites, 'https://esempio.org/' ) );
		// Un dominio che finisce con le stesse lettere non è un sottodominio.
		$this->assertNull( GscDays::pick_site( array( 'sc-domain:avida.com' ), 'https://maurizio.mavida.com/' ) );
	}

	public function test_host_filter_applies_only_to_domain_properties(): void {
		$this->assertSame( 'maurizio.mavida.com', GscDays::host_filter( 'sc-domain:mavida.com', 'https://maurizio.mavida.com/' ) );
		$this->assertNull( GscDays::host_filter( 'https://maurizio.mavida.com/', 'https://maurizio.mavida.com/' ) );
	}

	public function test_secret_round_trip_and_wrong_key(): void {
		$key    = hash( 'sha256', 'chiave', true );
		$cipher = Secret::encrypt( '1//refresh-token', $key );

		$this->assertNotSame( '1//refresh-token', $cipher );
		$this->assertStringNotContainsString( 'refresh-token', $cipher );
		$this->assertSame( '1//refresh-token', Secret::decrypt( $cipher, $key ) );
		$this->assertNull( Secret::decrypt( $cipher, hash( 'sha256', 'altra', true ) ) );
		$this->assertNull( Secret::decrypt( 'non base64 !', $key ) );
		$this->assertNull( Secret::decrypt( '', $key ) );
	}
}
