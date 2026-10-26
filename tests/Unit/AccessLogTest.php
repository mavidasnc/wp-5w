<?php
/**
 * Test di AccessLog.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\AccessLog;
use PHPUnit\Framework\TestCase;

/**
 * Interpretazione delle righe del log di accesso.
 */
final class AccessLogTest extends TestCase {

	private const LINE = '57.141.24.62 - - [25/Oct/2026:00:45:48 +0200] "GET /?utm_source=x HTTP/2" 200 33925 "https://www.google.com/" "Mozilla/5.0 (compatible; Test)" www.maurizio.mavida.com';

	/**
	 * Richiesta di prova per kind().
	 *
	 * @param string $method Metodo.
	 * @param string $path   Percorso.
	 * @param int    $status Stato.
	 * @return array{method:string,path:string,status:int}
	 */
	private static function request( string $method, string $path, int $status = 200 ): array {
		return array(
			'method' => $method,
			'path'   => $path,
			'status' => $status,
		);
	}

	public function test_parse_reads_every_field(): void {
		$request = AccessLog::parse( self::LINE );

		$this->assertNotNull( $request );
		$this->assertSame( '57.141.24.62', $request['ip'] );
		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( '/?utm_source=x', $request['uri'] );
		$this->assertSame( '/', $request['path'] );
		$this->assertSame( 200, $request['status'] );
		$this->assertSame( 'https://www.google.com/', $request['referrer'] );
		$this->assertSame( 'Mozilla/5.0 (compatible; Test)', $request['ua'] );
		// La data ha il fuso del server: 00:45:48 +0200 del 25/10 sono le 22:45:48 UTC del 24/10.
		$this->assertSame( gmmktime( 22, 45, 48, 10, 24, 2026 ), $request['ts'] );
	}

	public function test_parse_turns_dashes_into_empty_strings(): void {
		$request = AccessLog::parse( '1.2.3.4 - - [25/Oct/2026:10:00:00 +0200] "GET / HTTP/1.1" 200 10 "-" "-" host' );

		$this->assertNotNull( $request );
		$this->assertSame( array( '', '' ), array( $request['referrer'], $request['ua'] ) );
	}

	public function test_parse_returns_null_for_garbage(): void {
		$this->assertNull( AccessLog::parse( 'non è una riga di log' ) );
		$this->assertNull( AccessLog::parse( '1.2.3.4 - - [31/Foo/2026:10:00:00 +0200] "GET / HTTP/1.1" 200 10 "-" "-"' ) );
	}

	public function test_kind_page_for_a_get_with_200(): void {
		$this->assertSame( 'page', AccessLog::kind( self::request( 'GET', '/' ), '/public', '/app' ) );
		$this->assertSame( 'page', AccessLog::kind( self::request( 'GET', '/2026/10/16/mod-di-claude-code/' ), '/public', '/app' ) );
		$this->assertSame( 'page', AccessLog::kind( self::request( 'GET', '/tag/wordpress/' ), '/wp-content', '' ) );
	}

	public function test_kind_ignores_what_is_not_a_page(): void {
		foreach ( array( '/feed/', '/wp-json/wp/v2/posts', '/robots.txt', '/sitemap_index.xml', '/app/wp-cron.php', '/app/xmlrpc.php', '/x.php' ) as $path ) {
			$this->assertSame( 'ignore', AccessLog::kind( self::request( 'GET', $path ), '/public', '/app' ), $path );
		}
		$this->assertSame( 'ignore', AccessLog::kind( self::request( 'GET', '/', 301 ), '/public', '/app' ) );
		$this->assertSame( 'ignore', AccessLog::kind( self::request( 'GET', '/pagina-che-non-c-e/', 404 ), '/public', '/app' ) );
		$this->assertSame( 'ignore', AccessLog::kind( self::request( 'HEAD', '/' ), '/public', '/app' ) );
	}

	public function test_kind_ignores_excluded_prefixes(): void {
		$this->assertSame( 'ignore', AccessLog::kind( self::request( 'GET', '/dashboard/public/api/usage' ), '/public', '/app', array( '/dashboard/' ) ) );
		$this->assertSame( 'page', AccessLog::kind( self::request( 'GET', '/dashboardino/' ), '/public', '/app', array( '/dashboard/' ) ) );
	}

	public function test_kind_evidence_for_tracker_posts_and_site_assets(): void {
		foreach ( AccessLog::TRACKER_ENDPOINTS as $endpoint ) {
			$this->assertSame( 'evidence', AccessLog::kind( self::request( 'POST', $endpoint ), '/public', '/app' ), $endpoint );
		}
		$this->assertSame( 'evidence', AccessLog::kind( self::request( 'GET', '/public/litespeed/css/abc.css' ), '/public', '/app' ) );
		$this->assertSame( 'evidence', AccessLog::kind( self::request( 'GET', '/public/uploads/2026/10/foto.jpg.webp', 304 ), '/public', '/app' ) );
		// Un POST a un altro endpoint, un tracker con errore e un asset mancante non provano niente.
		$this->assertSame( 'ignore', AccessLog::kind( self::request( 'POST', '/public/plugins/litespeed-cache/guest.vary.php' ), '/public', '/app' ) );
		$this->assertSame( 'ignore', AccessLog::kind( self::request( 'POST', '/wp-json/wp5w/v1/hit', 500 ), '/public', '/app' ) );
		$this->assertSame( 'ignore', AccessLog::kind( self::request( 'GET', '/public/uploads/x.jpg', 404 ), '/public', '/app' ) );
	}

	public function test_kind_admin_for_wp_admin_pages_but_not_for_admin_ajax(): void {
		$this->assertSame( 'admin', AccessLog::kind( self::request( 'GET', '/app/wp-admin/index.php' ), '/public', '/app' ) );
		$this->assertSame( 'admin', AccessLog::kind( self::request( 'GET', '/wp-admin/' ), '/wp-content', '' ) );
		$this->assertSame( 'ignore', AccessLog::kind( self::request( 'GET', '/app/wp-admin/admin-ajax.php' ), '/public', '/app' ) );
		$this->assertSame( 'ignore', AccessLog::kind( self::request( 'GET', '/app/wp-admin/', 302 ), '/public', '/app' ) );
	}

	public function test_is_internal(): void {
		$this->assertTrue( AccessLog::is_internal( '185.201.65.29', 'WordPress/7.1.3; https://maurizio.mavida.com', array() ) );
		$this->assertTrue( AccessLog::is_internal( '127.0.0.1', 'Mozilla/5.0 (X11)', array() ) );
		$this->assertTrue( AccessLog::is_internal( '::1', 'Mozilla/5.0 (X11)', array() ) );
		$this->assertTrue( AccessLog::is_internal( '203.0.113.7', 'Mozilla/5.0 (X11)', array( '203.0.113.0/24' ) ) );
		$this->assertFalse( AccessLog::is_internal( '203.0.114.7', 'Mozilla/5.0 (X11)', array( '203.0.113.0/24' ) ) );
	}
}
