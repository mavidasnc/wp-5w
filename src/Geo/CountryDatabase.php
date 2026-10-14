<?php
/**
 * Database dei paesi (DB-IP Lite Country): lettura dell'IP e aggiornamento mensile.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Geo;

use Mavida\FiveW\Etl\Scheduler;
use Mavida\FiveW\Support\GeoDb;
use MaxMind\Db\Reader;

defined( 'ABSPATH' ) || exit;

/**
 * Il file `.mmdb` sta in `uploads/wp5w/`. Il paese si calcola nell'endpoint delle visite e l'IP non si salva mai.
 * L'aggiornamento usa solo funzioni PHP e WordPress (sul blog le funzioni di esecuzione dei comandi sono disabilitate).
 */
final class CountryDatabase {

	/**
	 * Opzione con lo stato dell'ultimo aggiornamento (non autoload: l'endpoint non la legge mai).
	 *
	 * @var string
	 */
	public const OPTION = 'wp5w_geo';

	/**
	 * Nome del file del database.
	 *
	 * @var string
	 */
	public const FILE = 'dbip-country-lite.mmdb';

	/**
	 * Lock MySQL dell'aggiornamento (nessun transient).
	 *
	 * @var string
	 */
	public const LOCK = 'wp5w_geo_update';

	/**
	 * Indirizzo di prova per controllare un database appena scaricato.
	 *
	 * @var string
	 */
	public const PROBE_IP = '8.8.8.8';

	/**
	 * Byte letti per volta durante la decompressione.
	 *
	 * @var int
	 */
	private const CHUNK = 65536;

	/**
	 * Lettore aperto una sola volta per richiesta.
	 *
	 * @var Reader|null
	 */
	private static ?Reader $reader = null;

	/**
	 * Cartella del database.
	 *
	 * @return string
	 */
	public static function dir(): string {
		// Il secondo argomento evita la creazione della cartella del mese a ogni chiamata.
		$upload = wp_upload_dir( null, false );
		return $upload['basedir'] . '/wp5w';
	}

	/**
	 * Percorso del file del database.
	 *
	 * @return string
	 */
	public static function path(): string {
		return self::dir() . '/' . self::FILE;
	}

	/**
	 * Codice ISO del paese di un IP, oppure stringa vuota (IP non valido o privato, database mancante o corrotto).
	 *
	 * Nessuna lettura di opzioni e nessuna query: è chiamata dall'endpoint a ogni visita registrata.
	 *
	 * @param string $ip Indirizzo IP.
	 * @return string
	 */
	public static function lookup( string $ip ): string {
		// Passo 1: senza file leggibile il paese resta vuoto (e un lettore già aperto non va usato su un file sparito).
		if ( ! is_readable( self::path() ) ) {
			return '';
		}

		// Passo 2: lettore aperto una volta per richiesta, poi ricerca. Qualunque errore dà paese vuoto.
		try {
			if ( null === self::$reader ) {
				self::$reader = new Reader( self::path() );
			}
			return GeoDb::country( self::$reader->get( $ip ) );
		} catch ( \Throwable $e ) {
			self::log( $e->getMessage() );
			return '';
		}
	}

	/**
	 * Scarica il database del mese e sostituisce il file installato solo se tutti i controlli passano.
	 *
	 * @return array{ok:bool,month:?string,message:string}
	 */
	public static function update(): array {
		global $wpdb;

		// Passo 1: lock contro gli aggiornamenti simultanei (se è occupato non si tocca nemmeno l'opzione).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- GET_LOCK, nessuna tabella.
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', self::LOCK ) ) ) {
			return array(
				'ok'      => false,
				'month'   => null,
				'message' => 'Aggiornamento già in corso',
			);
		}

		try {
			return self::run_update();
		} finally {
			// Il lock si rilascia sempre.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- RELEASE_LOCK, nessuna tabella.
		}
	}

	/**
	 * Stato per la scheda Stato.
	 *
	 * @return array{available:bool,month:string,built:int,size:int,updated:int,checked:int,error:string,next:?int}
	 */
	public static function status(): array {
		$saved = self::saved();
		$next  = wp_next_scheduled( Scheduler::GEO_HOOK );

		return array(
			'available' => is_readable( self::path() ),
			'month'     => $saved['month'],
			'built'     => $saved['built'],
			'size'      => $saved['size'],
			'updated'   => $saved['updated'],
			'checked'   => $saved['checked'],
			'error'     => $saved['error'],
			'next'      => $next ? (int) $next : null,
		);
	}

	/**
	 * Corpo dell'aggiornamento (il lock lo prende update()).
	 *
	 * @return array{ok:bool,month:?string,message:string}
	 */
	private static function run_update(): array {
		// Passo 1: mese del file installato e mesi da provare.
		$saved     = self::saved();
		$installed = is_readable( self::path() ) && '' !== $saved['month'] ? $saved['month'] : null;
		$months    = GeoDb::candidates( $installed, gmdate( 'Y-m-d' ) );
		if ( ! $months ) {
			self::save( array( 'checked' => time() ) );
			return array(
				'ok'      => true,
				'month'   => $installed,
				'message' => 'Già aggiornato',
			);
		}

		// Passo 2: cartella protetta e scrivibile.
		$folder = self::prepare_dir();
		if ( '' !== $folder ) {
			return self::fail( $folder );
		}

		// Passo 3: si prova un mese per volta. Solo lo stato HTTP 404 vale «non ancora pubblicato».
		$errors    = array();
		$not_found = 0;
		foreach ( $months as $month ) {
			$result = self::install_month( $month );
			if ( 'ok' === $result['status'] ) {
				return array(
					'ok'      => true,
					'month'   => $month,
					'message' => 'Database aggiornato',
				);
			}
			if ( 'missing' === $result['status'] ) {
				++$not_found;
			} else {
				$errors[] = $month . ': ' . $result['message'];
			}
		}

		// Passo 4: nessun mese riuscito, il file installato resta com'è. Senza nessun file, i soli 404 sono un errore.
		if ( ! $errors && null === $installed && $not_found ) {
			$errors[] = 'database non ancora pubblicato';
		}
		if ( $errors ) {
			return self::fail( implode( '; ', $errors ) );
		}

		self::save(
			array(
				'checked' => time(),
				'error'   => '',
			)
		);
		return array(
			'ok'      => true,
			'month'   => $installed,
			'message' => 'Mese non ancora pubblicato',
		);
	}

	/**
	 * Scarica, controlla e installa il file di un mese.
	 *
	 * @param string $month Mese in formato Y-m.
	 * @return array{status:string,message:string} `ok`, `missing` (HTTP 404) oppure `error`.
	 */
	private static function install_month( string $month ): array {
		// download_url() non c'è nel cron senza questo file.
		require_once ABSPATH . 'wp-admin/includes/file.php';

		// Passo 1: download in un file temporaneo. download_url() usa il codice http_404 per ogni stato diverso da 200: conta lo stato HTTP.
		$gz = download_url( GeoDb::url( $month ), 300 );
		if ( is_wp_error( $gz ) ) {
			$data   = $gz->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['code'] ?? 0 ) : 0;
			return 404 === $status
				? array(
					'status'  => 'missing',
					'message' => 'non ancora pubblicato',
				)
				: array(
					'status'  => 'error',
					'message' => $status ? 'HTTP ' . $status : $gz->get_error_message(),
				);
		}

		$tmp = self::path() . '.tmp';
		try {
			// Passo 2: decompressione, controlli e sostituzione. Un controllo che fallisce lancia un'eccezione.
			$meta = self::unpack_and_check( $gz, $tmp );
			self::replace( $tmp, $month, $meta );
			return array(
				'status'  => 'ok',
				'message' => '',
			);
		} catch ( \Throwable $e ) {
			return array(
				'status'  => 'error',
				'message' => $e->getMessage(),
			);
		} finally {
			// Passo 3: il file scaricato e l'eventuale temporaneo si cancellano in ogni caso.
			wp_delete_file( $gz );
			wp_delete_file( $tmp );
		}
	}

	/**
	 * Decomprime il gzip in streaming e controlla integrità e formato del database.
	 *
	 * @param string $gz  File .gz scaricato.
	 * @param string $tmp File temporaneo da scrivere.
	 * @return array{built:int,size:int} Data di compilazione e dimensione del database controllato.
	 * @throws \RuntimeException Se un controllo fallisce.
	 */
	private static function unpack_and_check( string $gz, string $tmp ): array {
		// Passo 1: decompressione a blocchi (la memoria resta bassa); il CRC del gzip lo controlla zlib.
		$in = gzopen( $gz, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_gzopen -- Streaming senza WP_Filesystem.
		if ( false === $in ) {
			throw new \RuntimeException( 'file gzip non leggibile' );
		}
		$out = fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming senza WP_Filesystem.
		if ( false === $out ) {
			gzclose( $in );
			throw new \RuntimeException( 'file temporaneo non scrivibile' );
		}

		$written = 0;
		try {
			while ( ! gzeof( $in ) ) {
				$chunk = gzread( $in, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_gzread -- Streaming senza WP_Filesystem.
				if ( false === $chunk ) {
					throw new \RuntimeException( 'decompressione non riuscita' );
				}
				if ( '' === $chunk ) {
					break;
				}
				$written += strlen( $chunk );
				// Un file enorme (gzip bomb) si ferma prima di riempire il disco.
				if ( $written > GeoDb::MAX_SIZE ) {
					throw new \RuntimeException( 'file troppo grande' );
				}
				if ( fwrite( $out, $chunk ) !== strlen( $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming senza WP_Filesystem.
					throw new \RuntimeException( 'scrittura non riuscita' );
				}
			}
		} finally {
			gzclose( $in );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming senza WP_Filesystem.
		}

		// Passo 2: i byte scritti devono essere quelli dichiarati dal gzip (ISIZE negli ultimi 4 byte) e di una dimensione plausibile.
		if ( ! GeoDb::isize_ok( $written, self::gzip_tail( $gz ) ) ) {
			throw new \RuntimeException( 'file gzip incompleto' );
		}
		if ( ! GeoDb::size_ok( $written ) ) {
			throw new \RuntimeException( 'dimensione del database non plausibile' );
		}

		// Passo 3: formato, metadati e ricerca di prova.
		try {
			$reader = new Reader( $tmp );
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( 'formato del database non valido' );
		}
		try {
			// Proprietà dei metadati della libreria MaxMind (camelCase).
			$meta  = $reader->metadata();
			$type  = (string) $meta->databaseType; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Proprietà della libreria MaxMind.
			$ipver = (int) $meta->ipVersion; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Proprietà della libreria MaxMind.
			$built = (int) $meta->buildEpoch; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Proprietà della libreria MaxMind.
			if ( ! GeoDb::metadata_ok( $type, $ipver, $built, time() ) ) {
				throw new \RuntimeException( 'metadati del database non validi' );
			}
			if ( '' === GeoDb::country( $reader->get( self::PROBE_IP ) ) ) {
				throw new \RuntimeException( 'ricerca di prova non riuscita' );
			}
			return array(
				'built' => $built,
				'size'  => $written,
			);
		} finally {
			$reader->close();
		}
	}

	/**
	 * Ultimi 4 byte di un file (per il gzip: la dimensione originale).
	 *
	 * @param string $file File.
	 * @return string
	 */
	private static function gzip_tail( string $file ): string {
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Lettura puntuale senza WP_Filesystem.
		if ( false === $handle ) {
			return '';
		}
		$tail = '';
		if ( 0 === fseek( $handle, -4, SEEK_END ) ) {
			$tail = (string) fread( $handle, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Lettura puntuale senza WP_Filesystem.
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Lettura puntuale senza WP_Filesystem.
		return $tail;
	}

	/**
	 * Sostituisce il database con il file controllato (rename nella stessa cartella: atomico) e salva lo stato.
	 *
	 * @param string                    $tmp   File temporaneo valido.
	 * @param string                    $month Mese del database.
	 * @param array{built:int,size:int} $meta  Data di compilazione e dimensione.
	 * @return void
	 * @throws \RuntimeException Se la sostituzione fallisce.
	 */
	private static function replace( string $tmp, string $month, array $meta ): void {
		// Il lettore aperto sul vecchio file si chiude prima (su Windows un file aperto non si sostituisce).
		self::$reader = null;

		if ( ! rename( $tmp, self::path() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Sostituzione atomica nella stessa cartella: WP_Filesystem::move() cancellerebbe prima il vecchio file.
			throw new \RuntimeException( 'sostituzione del file non riuscita' );
		}

		$now = time();
		self::save(
			array(
				'month'   => $month,
				'built'   => $meta['built'],
				'size'    => $meta['size'],
				'updated' => $now,
				'checked' => $now,
				'error'   => '',
			)
		);
	}

	/**
	 * Crea la cartella con `.htaccess` e `index.php`.
	 *
	 * @return string Stringa vuota se tutto è pronto, altrimenti il motivo dell'errore.
	 */
	private static function prepare_dir(): string {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
			return 'cartella non scrivibile';
		}

		// L'accesso diretto si nega per ordine (i dati di DB-IP Lite sono pubblici); su Nginx .htaccess è ignorato.
		$files = array(
			'.htaccess' => "# wp-5w: accesso diretto negato al database dei paesi.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
			'index.php' => "<?php // Silence is golden.\n",
		);
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) && false === file_put_contents( $dir . '/' . $name, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- File di due righe, senza WP_Filesystem.
				return 'file di protezione non scrivibile';
			}
		}

		return '';
	}

	/**
	 * Annota un errore vero (il file installato non cambia) e lo restituisce.
	 *
	 * @param string $message Motivo.
	 * @return array{ok:bool,month:?string,message:string}
	 */
	private static function fail( string $message ): array {
		self::save(
			array(
				'checked' => time(),
				'error'   => $message,
			)
		);
		return array(
			'ok'      => false,
			'month'   => null,
			'message' => $message,
		);
	}

	/**
	 * Stato salvato, con i valori predefiniti per i campi mancanti.
	 *
	 * @return array{month:string,built:int,size:int,updated:int,checked:int,error:string}
	 */
	private static function saved(): array {
		$option = get_option( self::OPTION, array() );
		$option = is_array( $option ) ? $option : array();

		return array(
			'month'   => is_string( $option['month'] ?? null ) ? $option['month'] : '',
			'built'   => (int) ( $option['built'] ?? 0 ),
			'size'    => (int) ( $option['size'] ?? 0 ),
			'updated' => (int) ( $option['updated'] ?? 0 ),
			'checked' => (int) ( $option['checked'] ?? 0 ),
			'error'   => is_string( $option['error'] ?? null ) ? $option['error'] : '',
		);
	}

	/**
	 * Aggiorna alcuni campi dell'opzione (senza autoload).
	 *
	 * @param array<string,mixed> $changes Campi da cambiare.
	 * @return void
	 */
	private static function save( array $changes ): void {
		update_option( self::OPTION, $changes + self::saved(), false );
	}

	/**
	 * Annota un errore nel log di PHP, solo con WP_DEBUG.
	 *
	 * @param string $message Messaggio.
	 * @return void
	 */
	private static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'wp5w geo: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Solo con WP_DEBUG.
		}
	}
}
