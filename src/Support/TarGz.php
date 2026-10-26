<?php
/**
 * Lettura in streaming dei log di accesso compressi (tar.gz) o in chiaro.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Legge le righe dei file `.log` dentro un archivio tar.gz, senza decomprimerlo su disco.
 *
 * PharData non va bene: non riconosce i nomi della rotazione dei log (`Oct-2026.maurizio.tar.gz.1`).
 * Il lettore tar è minimale (nome, dimensione e tipo dell'header da 512 byte): basta per gli archivi
 * che il server crea, con un file di accesso e uno degli errori.
 */
final class TarGz {

	/**
	 * Dimensione dei blocchi di un tar.
	 *
	 * @var int
	 */
	private const BLOCK = 512;

	/**
	 * Byte letti per volta dal flusso compresso.
	 *
	 * @var int
	 */
	private const CHUNK = 65536;

	/**
	 * Righe dei file `.log` (non `.error.log`) dell'archivio, una alla volta.
	 *
	 * Se il file non è un tar (un `.log` in chiaro o un `.gz` di un solo file) le righe sono quelle del file stesso.
	 *
	 * @param string $path Percorso del file.
	 * @return \Generator<int,string>
	 */
	public static function lines( string $path ): \Generator {
		// gzopen() legge anche i file non compressi, quindi vale per `.log`, `.gz` e `.tar.gz`.
		$handle = @gzopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Un file illeggibile non deve fermare l'import: si salta.
		if ( false === $handle ) {
			return;
		}

		try {
			$block = (string) gzread( $handle, self::BLOCK );

			// Passo 1: non è un tar (manca "ustar" nell'header): il contenuto è già testo.
			if ( self::BLOCK !== strlen( $block ) || 'ustar' !== substr( $block, 257, 5 ) ) {
				yield from self::text( $handle, null, $block );
				return;
			}

			// Passo 2: un header alla volta; il nome vuoto segna la fine dell'archivio.
			$complete = self::BLOCK === strlen( $block );
			while ( $complete && "\0" !== $block[0] ) {
				$name = rtrim( substr( $block, 0, 100 ), "\0" );
				$size = (int) octdec( trim( substr( $block, 124, 12 ), "\0 " ) );
				$kind = $block[156];
				$pad  = ( self::BLOCK - $size % self::BLOCK ) % self::BLOCK;

				if ( ( '0' === $kind || "\0" === $kind ) && str_ends_with( $name, '.log' ) && ! str_ends_with( $name, '.error.log' ) ) {
					yield from self::text( $handle, $size, '' );
					self::skip( $handle, $pad );
				} else {
					self::skip( $handle, $size + $pad );
				}

				$block    = (string) gzread( $handle, self::BLOCK );
				$complete = self::BLOCK === strlen( $block );
			}
		} finally {
			gzclose( $handle );
		}
	}

	/**
	 * Righe di testo di un flusso, fino a `$limit` byte (senza limite se null).
	 *
	 * @param resource $handle Flusso gz.
	 * @param int|null $limit  Byte da leggere (anche quelli già in `$buffer`).
	 * @param string   $buffer Byte già letti.
	 * @return \Generator<int,string>
	 */
	private static function text( $handle, ?int $limit, string $buffer ): \Generator {
		$left = null === $limit ? null : $limit - strlen( $buffer );

		while ( true ) {
			// Le righe complete escono, l'ultima (forse spezzata) resta nel buffer.
			$lines  = explode( "\n", $buffer );
			$buffer = (string) array_pop( $lines );
			foreach ( $lines as $line ) {
				yield rtrim( $line, "\r" );
			}

			if ( ( null !== $left && $left <= 0 ) || gzeof( $handle ) ) {
				break;
			}
			$chunk = gzread( $handle, null === $left ? self::CHUNK : min( self::CHUNK, $left ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			if ( null !== $left ) {
				$left -= strlen( $chunk );
			}
			$buffer .= $chunk;
		}

		if ( '' !== $buffer ) {
			yield rtrim( $buffer, "\r" );
		}
	}

	/**
	 * Salta dei byte del flusso.
	 *
	 * @param resource $handle Flusso gz.
	 * @param int      $bytes  Byte da saltare.
	 * @return void
	 */
	private static function skip( $handle, int $bytes ): void {
		while ( $bytes > 0 ) {
			$chunk = gzread( $handle, min( self::CHUNK, $bytes ) );
			if ( false === $chunk || '' === $chunk ) {
				return;
			}
			$bytes -= strlen( $chunk );
		}
	}
}
