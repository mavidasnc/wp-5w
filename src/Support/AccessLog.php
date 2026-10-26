<?php
/**
 * Righe del log di accesso del server (formato combined con il vhost in coda).
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Interpreta una riga di log e dice che cosa rappresenta: una pagina vista, la prova che il client esegue JavaScript
 * o carica le risorse (un browser), una visita da amministratore o niente di tutto questo.
 */
final class AccessLog {

	/**
	 * Endpoint dei tre tracker: un POST andato a buon fine prova che il client ha eseguito JavaScript.
	 *
	 * @var array<int,string>
	 */
	public const TRACKER_ENDPOINTS = array( '/wp-json/wp5w/v1/hit', '/wp-json/wp-statistics/v2/hit', '/wp-json/iawp/search' );

	/**
	 * Estensioni delle risorse statiche (e dei file che non sono pagine).
	 *
	 * @var string
	 */
	private const STATIC_EXTENSIONS = 'css|js|map|json|xml|txt|php|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|mp4|webm|mp3|pdf|zip';

	/**
	 * Secondi entro cui la stessa pagina richiesta di nuovo dallo stesso visitatore conta una volta sola
	 * (la «guest mode» di LiteSpeed carica la pagina, chiama guest.vary.php e la ricarica).
	 *
	 * @var int
	 */
	public const RELOAD_SECONDS = 30;

	/**
	 * Interpreta una riga. Null se non è nel formato atteso.
	 *
	 * @param string $line Riga del log.
	 * @return array{ip:string,ts:int,method:string,uri:string,path:string,status:int,referrer:string,ua:string}|null
	 */
	public static function parse( string $line ): ?array {
		// Passo 1: «ip - - [data] "METODO uri protocollo" stato byte "referrer" "user agent" ...».
		if ( 1 !== preg_match( '/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+)[^"]*" (\d{3}) \S+ "([^"]*)" "([^"]*)"/', $line, $m ) ) {
			return null;
		}

		// Passo 2: la data ha il fuso del server (es. 25/Oct/2026:00:45:48 +0200): il timestamp è assoluto.
		$time = \DateTimeImmutable::createFromFormat( 'd/M/Y:H:i:s O', $m[2] );
		if ( false === $time ) {
			return null;
		}

		return array(
			'ip'       => $m[1],
			'ts'       => $time->getTimestamp(),
			'method'   => $m[3],
			'uri'      => $m[4],
			'path'     => (string) strtok( $m[4], '?#' ),
			'status'   => (int) $m[5],
			'referrer' => '-' === $m[6] ? '' : $m[6],
			'ua'       => '-' === $m[7] ? '' : $m[7],
		);
	}

	/**
	 * Che cosa rappresenta la richiesta.
	 *
	 * - `page`: una pagina del sito servita con 200 (GET).
	 * - `evidence`: la prova di un browser, cioè un POST andato a buon fine a uno dei tracker oppure un GET riuscito di una risorsa statica del sito.
	 * - `admin`: una pagina dell'area di amministrazione servita con 200 (chi la apre è un utente collegato).
	 * - `ignore`: tutto il resto (REST, feed, cron, file statici, percorsi esclusi, errori e reindirizzamenti).
	 *
	 * @param array{method:string,path:string,status:int} $request Richiesta (vedi parse()).
	 * @param string                                      $content Percorso della cartella dei contenuti (es. /public o /wp-content).
	 * @param string                                      $core    Percorso del core di WordPress (es. /app, vuoto se il sito sta nella radice).
	 * @param array<int,string>                           $excluded Prefissi di percorso da ignorare (es. /dashboard/).
	 * @return string
	 */
	public static function kind( array $request, string $content, string $core, array $excluded = array() ): string {
		$path   = $request['path'];
		$status = $request['status'];

		// Passo 1: i POST dei tracker.
		if ( 'POST' === $request['method'] ) {
			return $status >= 200 && $status < 300 && in_array( $path, self::TRACKER_ENDPOINTS, true ) ? 'evidence' : 'ignore';
		}
		if ( 'GET' !== $request['method'] ) {
			return 'ignore';
		}

		// Passo 2: risorse del sito (CSS, JS, immagini sotto la cartella dei contenuti): un browser le scarica, un crawler di solito no.
		$content = rtrim( $content, '/' );
		if ( str_starts_with( $path, $content . '/' ) ) {
			return ( 200 === $status || 304 === $status ) && self::is_static( $path ) ? 'evidence' : 'ignore';
		}

		// Passo 3: area di amministrazione (admin-ajax.php lo chiamano anche gli anonimi).
		$admin = rtrim( $core, '/' ) . '/wp-admin/';
		if ( str_starts_with( $path, $admin ) ) {
			return 200 === $status && ! str_ends_with( $path, '/admin-ajax.php' ) ? 'admin' : 'ignore';
		}

		// Passo 4: una pagina è un GET con 200, fuori da REST, feed, file statici e percorsi esclusi.
		if ( 200 !== $status || str_starts_with( $path, '/wp-json' ) || self::is_static( $path ) || 1 === preg_match( '~/feed/?$~', $path ) ) {
			return 'ignore';
		}
		foreach ( $excluded as $prefix ) {
			if ( '' !== $prefix && str_starts_with( $path, $prefix ) ) {
				return 'ignore';
			}
		}
		return str_starts_with( $path, rtrim( $core, '/' ) . '/' ) && '' !== $core ? 'ignore' : 'page';
	}

	/**
	 * Vero se la richiesta viene dal server stesso (cron, loopback, controlli di salute) o da un IP escluso nelle Opzioni.
	 *
	 * @param string            $ip       Indirizzo IP.
	 * @param string            $ua       User agent.
	 * @param array<int,string> $excluded IP o intervalli CIDR esclusi.
	 * @return bool
	 */
	public static function is_internal( string $ip, string $ua, array $excluded ): bool {
		return str_starts_with( $ua, 'WordPress/' )
			|| '::1' === $ip
			|| str_starts_with( $ip, '127.' )
			|| Visit::ip_excluded( $ip, $excluded );
	}

	/**
	 * Vero se il percorso è un file statico (estensione nota), non una pagina.
	 *
	 * @param string $path Percorso senza query.
	 * @return bool
	 */
	private static function is_static( string $path ): bool {
		return 1 === preg_match( '~\.(' . self::STATIC_EXTENSIONS . ')$~i', $path );
	}
}
