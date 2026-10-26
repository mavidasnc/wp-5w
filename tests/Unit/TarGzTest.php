<?php
/**
 * Test di TarGz.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\TarGz;
use PHPUnit\Framework\TestCase;

/**
 * Lettura dei log dentro gli archivi tar.gz.
 */
final class TarGzTest extends TestCase {

	/**
	 * File temporanei da togliere.
	 *
	 * @var array<int,string>
	 */
	private array $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Pulizia di un file di prova.
		}
	}

	/**
	 * Un membro del tar: header da 512 byte, dati e riempimento fino al blocco.
	 *
	 * @param string $name Nome del file.
	 * @param string $data Contenuto.
	 * @return string
	 */
	private static function member( string $name, string $data ): string {
		$header = str_pad( $name, 100, "\0" ) . "0000644\0" . "0000000\0" . "0000000\0" . sprintf( '%011o', strlen( $data ) ) . "\0" . sprintf( '%011o', 0 ) . "\0" . '        ' . '0' . str_repeat( "\0", 100 ) . "ustar\0" . '00';
		return str_pad( $header, 512, "\0" ) . str_pad( $data, (int) ( 512 * ceil( strlen( $data ) / 512 ) ), "\0" );
	}

	/**
	 * Scrive un file temporaneo con il nome indicato (anche con il numero della rotazione).
	 *
	 * @param string $name    Nome del file.
	 * @param string $content Contenuto.
	 * @return string Percorso.
	 */
	private function write( string $name, string $content ): string {
		$path          = sys_get_temp_dir() . '/' . uniqid( 'wp5w-', true ) . '-' . $name;
		$this->files[] = $path;
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- File di prova.
		return $path;
	}

	public function test_reads_the_access_log_and_skips_the_error_log(): void {
		$access = "riga uno\nriga due\r\nriga tre\n";
		$tar    = self::member( 'mavida.com.maurizio.error.log', "errore\n" ) . self::member( 'mavida.com.maurizio.log', $access ) . str_repeat( "\0", 1024 );
		$path   = $this->write( 'Oct-2026.maurizio.tar.gz.1', (string) gzencode( $tar ) );

		$this->assertSame( array( 'riga uno', 'riga due', 'riga tre' ), iterator_to_array( TarGz::lines( $path ), false ) );
	}

	public function test_reads_a_log_bigger_than_the_read_chunk(): void {
		$lines = array();
		for ( $i = 0; $i < 6000; $i++ ) {
			$lines[] = "riga numero {$i} con un po' di testo per allungarla";
		}
		$tar  = self::member( 'a.log', implode( "\n", $lines ) . "\n" ) . str_repeat( "\0", 1024 );
		$path = $this->write( 'big.tar.gz', (string) gzencode( $tar ) );

		$this->assertSame( $lines, iterator_to_array( TarGz::lines( $path ), false ) );
	}

	public function test_reads_a_plain_log_and_a_plain_gzip(): void {
		$this->assertSame( array( 'a', 'b' ), iterator_to_array( TarGz::lines( $this->write( 'x.log', "a\nb\n" ) ), false ) );
		$this->assertSame( array( 'a', 'b' ), iterator_to_array( TarGz::lines( $this->write( 'x.log.gz', (string) gzencode( "a\nb" ) ) ), false ) );
	}

	public function test_a_missing_file_gives_no_lines(): void {
		$this->assertSame( array(), iterator_to_array( TarGz::lines( sys_get_temp_dir() . '/non-esiste.tar.gz' ), false ) );
	}
}
