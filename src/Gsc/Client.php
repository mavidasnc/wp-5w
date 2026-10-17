<?php
/**
 * Collegamento OAuth a Google Search Console e chiamate all'API.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Gsc;

use Mavida\FiveW\Support\GscDays;
use Mavida\FiveW\Support\Secret;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- I messaggi delle eccezioni sono per l'admin e la scheda Opzioni li mostra come testo.

/**
 * Unica classe che parla con Google (`wp_remote_*`, nessuna libreria). Ogni sito usa il proprio client OAuth
 * (creato nella Google Cloud Console dall'utente): per passare a un client centrale basta cambiare qui
 * lo scambio e il rinnovo dei token.
 *
 * Opzione `wp5w_gsc` (autoload spento): client_id, client_secret e refresh_token (gli ultimi due cifrati con Secret),
 * site (proprietà scelta), connected_at e reauth (vero se Google ha revocato l'accesso).
 */
final class Client {

	/**
	 * Opzione con le credenziali e il collegamento.
	 *
	 * @var string
	 */
	public const OPTION = 'wp5w_gsc';

	/**
	 * Azione di admin-post.php che porta l'utente alla schermata di consenso.
	 *
	 * @var string
	 */
	public const ACTION_CONNECT = 'wp5w_gsc_connect';

	/**
	 * Azione di admin-post.php a cui Google rimanda l'utente (redirect URI).
	 *
	 * @var string
	 */
	public const ACTION_CALLBACK = 'wp5w_gsc_oauth';

	/**
	 * Transient del token di accesso (vale circa un'ora).
	 *
	 * @var string
	 */
	private const ACCESS_TRANSIENT = 'wp5w_gsc_access';

	/**
	 * Permesso richiesto: sola lettura.
	 *
	 * @var string
	 */
	private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	private const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
	private const API_URL    = 'https://searchconsole.googleapis.com/webmasters/v3/sites';

	/**
	 * Righe massime per richiesta consentite dall'API.
	 *
	 * @var int
	 */
	private const ROW_LIMIT = 25000;

	/**
	 * Impostazioni salvate, unite ai valori predefiniti.
	 *
	 * @return array{client_id:string,client_secret:string,refresh_token:string,site:string,connected_at:int,reauth:bool}
	 */
	public static function config(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'client_id'     => (string) ( $saved['client_id'] ?? '' ),
			'client_secret' => (string) ( $saved['client_secret'] ?? '' ),
			'refresh_token' => (string) ( $saved['refresh_token'] ?? '' ),
			'site'          => (string) ( $saved['site'] ?? '' ),
			'connected_at'  => (int) ( $saved['connected_at'] ?? 0 ),
			'reauth'        => ! empty( $saved['reauth'] ),
		);
	}

	/**
	 * Redirect URI da registrare nel client OAuth: lo stesso valore va nel link di consenso e nello scambio del codice.
	 *
	 * @return string
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=' . self::ACTION_CALLBACK );
	}

	/**
	 * Client ID e secret salvati.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$config = self::config();
		return '' !== $config['client_id'] && '' !== $config['client_secret'];
	}

	/**
	 * Collegato a Google con un token ancora valido.
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		$config = self::config();
		return '' !== $config['refresh_token'] && ! $config['reauth'];
	}

	/**
	 * C'è un collegamento che Google ha revocato (o che non si legge più): va rifatto.
	 *
	 * @return bool
	 */
	public static function needs_reauth(): bool {
		$config = self::config();
		return '' !== $config['refresh_token'] && $config['reauth'];
	}

	/**
	 * Salva client ID e secret. Un secret vuoto lascia quello salvato; se i dati cambiano il collegamento
	 * esistente si scollega, perché il token appartiene al vecchio client.
	 *
	 * @param string $client_id     ID client OAuth.
	 * @param string $client_secret Secret (vuoto per non cambiarlo).
	 * @return void
	 */
	public static function save_credentials( string $client_id, string $client_secret ): void {
		$config  = self::config();
		$changed = $client_id !== $config['client_id'] || '' !== $client_secret;

		if ( $changed && '' !== $config['refresh_token'] ) {
			self::disconnect();
			$config = self::config();
		}

		$config['client_id'] = $client_id;
		if ( '' !== $client_secret ) {
			$config['client_secret'] = Secret::encrypt( $client_secret, self::key() );
		}
		self::save( $config );
	}

	/**
	 * Indirizzo della schermata di consenso di Google. Lo `state` casuale si salva per l'utente corrente (10 minuti).
	 *
	 * @return string
	 */
	public static function auth_url(): string {
		$state = wp_generate_password( 32, false );
		set_transient( self::state_key(), $state, 10 * MINUTE_IN_SECONDS );

		return self::AUTH_URL . '?' . http_build_query(
			array(
				'client_id'              => self::config()['client_id'],
				'redirect_uri'           => self::redirect_uri(),
				'response_type'          => 'code',
				'scope'                  => self::SCOPE,
				// Il refresh token arriva solo con l'accesso offline e il consenso esplicito.
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'false',
				'state'                  => $state,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Scambia il codice del consenso con i token e li salva. Sceglie da solo la proprietà se ne trova una per questo sito.
	 *
	 * @param string $code  Codice restituito da Google.
	 * @param string $state Parametro `state` del ritorno: deve coincidere con quello creato da auth_url().
	 * @return void
	 * @throws \RuntimeException Se lo state non coincide, Google rifiuta il codice o manca il permesso.
	 */
	public static function exchange( string $code, string $state ): void {
		$expected = get_transient( self::state_key() );
		delete_transient( self::state_key() );
		if ( ! is_string( $expected ) || '' === $state || ! hash_equals( $expected, $state ) ) {
			throw new \RuntimeException( __( 'La richiesta di collegamento è scaduta o non è valida: riprova.', 'wp-5w' ) );
		}

		$config = self::config();
		$secret = self::reveal( $config['client_secret'] );
		if ( null === $secret || '' === $config['client_id'] ) {
			throw new \RuntimeException( __( 'Client ID o secret mancanti: salvali di nuovo.', 'wp-5w' ) );
		}

		$data = self::token_request(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => self::redirect_uri(),
				'client_id'     => $config['client_id'],
				'client_secret' => $secret,
			)
		);

		// Con il consenso granulare l'utente può togliere il permesso: senza, l'API risponderebbe 403 a ogni richiesta.
		if ( ! str_contains( (string) ( $data['scope'] ?? '' ), self::SCOPE ) ) {
			throw new \RuntimeException( __( 'Non hai concesso l\'accesso a Search Console: ricollega Google e lascia spuntato il permesso.', 'wp-5w' ) );
		}
		if ( empty( $data['refresh_token'] ) ) {
			throw new \RuntimeException( __( 'Google non ha restituito il token di rinnovo: togli l\'accesso del plugin da myaccount.google.com/permissions e ricollega.', 'wp-5w' ) );
		}

		$config['refresh_token'] = Secret::encrypt( (string) $data['refresh_token'], self::key() );
		$config['connected_at']  = time();
		$config['reauth']        = false;
		$config['site']          = '';
		self::save( $config );
		self::cache_access_token( $data );

		// La proprietà si sceglie da sola se ce n'è una per questo sito; un errore qui non annulla il collegamento.
		try {
			$picked = GscDays::pick_site( self::sites(), home_url( '/' ) );
			if ( null !== $picked ) {
				$config['site'] = $picked;
				self::save( $config );
			}
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
	}

	/**
	 * Proprietà dell'utente che possono leggere i dati (esclusi i siti con accesso non verificato).
	 *
	 * @return array<int,string>
	 * @throws \RuntimeException Se la richiesta fallisce.
	 */
	public static function sites(): array {
		$data  = self::api( 'GET', self::API_URL );
		$sites = array();
		foreach ( (array) ( $data['siteEntry'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['siteUrl'] ) && 'siteUnverifiedUser' !== ( $entry['permissionLevel'] ?? '' ) ) {
				$sites[] = (string) $entry['siteUrl'];
			}
		}
		sort( $sites );
		return $sites;
	}

	/**
	 * Sceglie la proprietà da cui importare, tra quelle dell'utente.
	 *
	 * @param string $site Proprietà (`siteUrl`).
	 * @return void
	 * @throws \RuntimeException Se la proprietà non è tra quelle accessibili.
	 */
	public static function select_site( string $site ): void {
		if ( ! in_array( $site, self::sites(), true ) ) {
			throw new \RuntimeException( __( 'La proprietà scelta non è tra quelle dell\'account collegato.', 'wp-5w' ) );
		}
		$config         = self::config();
		$config['site'] = $site;
		self::save( $config );
	}

	/**
	 * Righe di Search Analytics (tutte le pagine di risultati, 25.000 righe per richiesta).
	 *
	 * @param string            $site       Proprietà.
	 * @param string            $start      Primo giorno (Y-m-d).
	 * @param string            $end        Ultimo giorno (Y-m-d).
	 * @param array<int,string> $dimensions Dimensioni (per esempio date, page, query).
	 * @param string|null       $page_host  Host che le pagine devono contenere (proprietà di dominio), null per tutte.
	 * @return array<int,array<string,mixed>> Righe dell'API (`keys`, `clicks`, `impressions`, `position`).
	 * @throws \RuntimeException Se una richiesta fallisce: i dati già letti non vanno usati.
	 */
	public static function rows( string $site, string $start, string $end, array $dimensions, ?string $page_host = null ): array {
		$body = array(
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => $dimensions,
			'rowLimit'   => self::ROW_LIMIT,
			'dataState'  => 'final',
		);
		if ( null !== $page_host && '' !== $page_host ) {
			$body['dimensionFilterGroups'] = array(
				array(
					'filters' => array(
						array(
							'dimension'  => 'page',
							'operator'   => 'contains',
							// Senza barra finale: un host con porta (sviluppo) è «//host:8895/», e il filtro locale di GscDays::group() è quello preciso.
							'expression' => '//' . $page_host,
						),
					),
				),
			);
		}

		$url      = self::API_URL . '/' . rawurlencode( $site ) . '/searchAnalytics/query';
		$rows     = array();
		$received = 0;
		do {
			$body['startRow'] = count( $rows );
			$data             = self::api( 'POST', $url, $body );
			$page             = array_values( (array) ( $data['rows'] ?? array() ) );
			$received         = count( $page );
			$rows             = array_merge( $rows, $page );
		} while ( $received >= self::ROW_LIMIT );

		return $rows;
	}

	/**
	 * Revoca il token presso Google (se riesce) e dimentica il collegamento. Client ID e secret restano.
	 *
	 * @return void
	 */
	public static function disconnect(): void {
		$config = self::config();
		$token  = self::reveal( $config['refresh_token'] );
		if ( null !== $token ) {
			// Se Google non risponde il collegamento si toglie lo stesso: l'utente può revocare da myaccount.google.com/permissions.
			wp_remote_post(
				self::REVOKE_URL,
				array(
					'timeout' => 10,
					'body'    => array( 'token' => $token ),
				)
			);
		}

		$config['refresh_token'] = '';
		$config['site']          = '';
		$config['connected_at']  = 0;
		$config['reauth']        = false;
		self::save( $config );
		delete_transient( self::ACCESS_TRANSIENT );
	}

	/**
	 * Token di accesso valido (dalla cache o rinnovato con il refresh token).
	 *
	 * @return string
	 * @throws \RuntimeException Se il collegamento non è valido o Google rifiuta il rinnovo.
	 */
	private static function access_token(): string {
		$cached = get_transient( self::ACCESS_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$config  = self::config();
		$refresh = self::reveal( $config['refresh_token'] );
		$secret  = self::reveal( $config['client_secret'] );
		if ( null === $refresh || null === $secret || '' === $config['client_id'] ) {
			// Salt cambiate o opzione danneggiata: i segreti non si leggono più.
			self::mark_reauth();
			throw new \RuntimeException( __( 'Il collegamento a Google non è più valido: ricollegalo.', 'wp-5w' ) );
		}

		$data = self::token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
				'client_id'     => $config['client_id'],
				'client_secret' => $secret,
			)
		);
		self::cache_access_token( $data );

		return (string) ( $data['access_token'] ?? '' );
	}

	/**
	 * Richiesta all'API con il token di accesso; su 401 rinnova il token e riprova una volta.
	 *
	 * @param string                   $method GET o POST.
	 * @param string                   $url    Indirizzo.
	 * @param array<string,mixed>|null $body   Corpo JSON (solo POST).
	 * @param bool                     $retry  Falso dopo il secondo tentativo.
	 * @return array<string,mixed>
	 * @throws \RuntimeException Con il messaggio di Google se la richiesta fallisce.
	 */
	private static function api( string $method, string $url, ?array $body = null, bool $retry = true ): array {
		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . self::access_token(),
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $data ) ? $data : array();

		if ( 401 === $status && $retry ) {
			delete_transient( self::ACCESS_TRANSIENT );
			return self::api( $method, $url, $body, false );
		}
		if ( $status < 200 || $status >= 300 ) {
			$message = (string) ( $data['error']['message'] ?? '' );
			/* translators: 1: codice HTTP, 2: messaggio di Google. */
			throw new \RuntimeException( sprintf( __( 'Search Console ha risposto %1$d: %2$s', 'wp-5w' ), $status, '' !== $message ? $message : __( 'errore sconosciuto', 'wp-5w' ) ) );
		}

		return $data;
	}

	/**
	 * Chiamata all'endpoint dei token.
	 *
	 * @param array<string,string> $body Parametri del form.
	 * @return array<string,mixed> Risposta di Google.
	 * @throws \RuntimeException Se Google rifiuta la richiesta; con invalid_grant il collegamento si segna da rifare.
	 */
	private static function token_request( array $body ): array {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $data['access_token'] ) ) {
			$error = (string) ( $data['error'] ?? '' );
			if ( 'invalid_grant' === $error && 'refresh_token' === ( $body['grant_type'] ?? '' ) ) {
				self::mark_reauth();
				throw new \RuntimeException( __( 'Google ha revocato l\'accesso (o il token è scaduto): ricollega Google.', 'wp-5w' ) );
			}
			$description = (string) ( $data['error_description'] ?? $error );
			/* translators: %s: messaggio di Google. */
			throw new \RuntimeException( sprintf( __( 'Google ha rifiutato la richiesta: %s', 'wp-5w' ), '' !== $description ? $description : __( 'errore sconosciuto', 'wp-5w' ) ) );
		}

		return $data;
	}

	/**
	 * Tiene il token di accesso fino a due minuti prima della scadenza.
	 *
	 * @param array<string,mixed> $data Risposta dell'endpoint dei token.
	 * @return void
	 */
	private static function cache_access_token( array $data ): void {
		set_transient( self::ACCESS_TRANSIENT, (string) $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 120 ) );
	}

	/**
	 * Segna il collegamento come da rifare (Google ha revocato l'accesso).
	 *
	 * @return void
	 */
	private static function mark_reauth(): void {
		$config           = self::config();
		$config['reauth'] = true;
		self::save( $config );
		delete_transient( self::ACCESS_TRANSIENT );
	}

	/**
	 * Salva l'opzione senza autoload: contiene segreti e serve solo nella scheda Opzioni e nell'import.
	 *
	 * @param array<string,mixed> $config Impostazioni.
	 * @return void
	 */
	private static function save( array $config ): void {
		update_option( self::OPTION, $config, false );
	}

	/**
	 * Legge un segreto cifrato.
	 *
	 * @param string $encoded Valore salvato.
	 * @return string|null Null se è vuoto o non si decifra più.
	 */
	private static function reveal( string $encoded ): ?string {
		return '' === $encoded ? null : Secret::decrypt( $encoded, self::key() );
	}

	/**
	 * Chiave di cifratura derivata dalle salt di WordPress: se cambiano, il collegamento va rifatto.
	 *
	 * @return string
	 */
	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . 'wp5w-gsc', true );
	}

	/**
	 * Chiave del transient dello state, una per utente.
	 *
	 * @return string
	 */
	private static function state_key(): string {
		return 'wp5w_gsc_state_' . get_current_user_id();
	}
}
