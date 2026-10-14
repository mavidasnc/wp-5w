<?php
/**
 * Endpoint pubblico che riceve le visite dal tracker.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\REST;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Front\Tracker;
use Mavida\FiveW\Geo\CountryDatabase;
use Mavida\FiveW\Settings;
use Mavida\FiveW\Support\BotFilter;
use Mavida\FiveW\Support\Channels;
use Mavida\FiveW\Support\SearchTerm;
use Mavida\FiveW\Support\UserAgent;
use Mavida\FiveW\Support\Visit;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin, nomi costruiti con $wpdb->prefix in Schema::table().

/**
 * `POST /wp5w/v1/hit`: rotta pubblica, risponde sempre 204 senza body (anche per scarti ed errori interni).
 *
 * Per una visita registrata esegue una SELECT (frequenza e visite del giorno) e una INSERT su wp5w_hits:
 * 2 query per una visita normale, 3 per una pagina 404 o una ricerca interna valida (più una INSERT ... ON DUPLICATE KEY UPDATE
 * negli aggregati giornalieri, senza legame con il visitatore). Nessun update_option e nessun transient,
 * a parte la rotazione giornaliera della chiave.
 * Paese (dal database DB-IP, senza query) e dispositivo, browser e sistema (dallo user agent) si calcolano
 * dopo l'ultimo scarto e si salvano nella riga; né l'IP né lo user agent restano da nessuna parte.
 */
final class HitController extends RestController {

	/**
	 * Visite al minuto per visitatore oltre le quali si scarta.
	 *
	 * @var int
	 */
	private const RATE_LIMIT = 30;

	/**
	 * Opzione con la chiave del giorno ("Y-m-d|<64 caratteri esadecimali>").
	 *
	 * @var string
	 */
	private const SALT_OPTION = 'wp5w_daily_salt';

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		// Nessun args: i parametri si leggono e si validano a mano, per restare leggeri.
		register_rest_route(
			self::API_NAMESPACE,
			'/hit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'hit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Registra la visita e risponde 204 in ogni caso.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response
	 */
	public function hit( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->handle( $request );
		} catch ( \Throwable $e ) {
			// Un errore interno non deve mai arrivare al visitatore: si annota solo con WP_DEBUG.
			$this->log( $e->getMessage() );
		}

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * I passi dell'endpoint, nell'ordine: ci si ferma al primo scarto.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return void
	 */
	private function handle( \WP_REST_Request $request ): void {
		global $wpdb;

		// Passo 1: schema non ancora migrato (o raccolta spenta) = niente da fare, nemmeno uno scarto.
		$settings = Settings::get();
		if ( get_option( 'wp5w_db_version' ) !== Schema::DB_VERSION || ! $settings['own_tracking'] ) {
			return;
		}

		// Dati della richiesta letti da $_SERVER (come nel test verify, dove gli header della richiesta non ci sono).
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

		// Passo 2: Do Not Track (parametro del tracker oppure header DNT).
		$dnt_param  = '1' === $this->param( $request, 'dnt' );
		$dnt_header = '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ?? '' ) );
		if ( $settings['respect_dnt'] && ( $dnt_param || $dnt_header ) ) {
			$this->discard( 'dnt' );
			return;
		}
		// Pagina in cache con l'opzione vecchia: arriva solo dnt=1 e DNT è ormai ignorato, non c'è nulla da registrare.
		if ( $dnt_param && null === $request->get_param( 't' ) ) {
			return;
		}

		// Passo 3: bot, crawler, user agent vuoti o troppo corti.
		if ( BotFilter::is_bot( $ua ) ) {
			$this->discard( 'bot' );
			return;
		}

		// Passo 4: tipo, ID e firma (un payload malformato conta come firma errata).
		$type = $this->param( $request, 't' );
		$id   = $this->param( $request, 'id' );
		if ( 1 !== preg_match( '/^[a-z0-9_]{1,40}$/', $type ) || 1 !== preg_match( '/^\d{1,18}$/', $id ) || ! Visit::check_signature( $type, (int) $id, $this->param( $request, 's' ), wp_salt( 'auth' ) ) ) {
			$this->discard( 'signature' );
			return;
		}

		// Passo 5: IP escluso dalle Opzioni.
		if ( Visit::ip_excluded( $ip, $settings['excluded_ips'] ) ) {
			$this->discard( 'ip' );
			return;
		}

		// Passo 6: utente collegato, letto dal cookie (senza nonce la REST riporta l'utente a 0, vedi D1).
		if ( 'none' !== $settings['exclude_users'] ) {
			$user_id = $this->logged_in_user_id();
			if ( Visit::user_excluded( $settings['exclude_users'], $user_id > 0, $user_id > 0 && user_can( $user_id, 'manage_options' ) ) ) {
				$this->discard( 'user' );
				return;
			}
		}

		// Passo 7: hash del visitatore con la chiave del giorno. IP e user agent non si salvano.
		$hash = bin2hex( Visit::visitor_hash( $ip, $ua, $this->daily_key() ) );

		// Passo 8: una sola SELECT per il limite di frequenza e per le visite del giorno (serve per l'ingresso).
		$hits   = Schema::table( 'hits' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
		$row    = $wpdb->get_row(
			$wpdb->prepare( "SELECT COUNT(*) AS today, COALESCE(SUM(ts >= %s), 0) AS recent FROM {$hits} WHERE visitor = UNHEX(%s)", $cutoff, $hash )
		);
		if ( (int) ( $row->recent ?? 0 ) >= self::RATE_LIMIT ) {
			$this->discard( 'rate' );
			return;
		}

		// Passo 9: referrer, canale, sorgente, ingresso, paese e user agent (solo per le visite che si registrano:
		// gli scarti restano veloci e il paese non può mai bloccare una visita). IP e user agent non si salvano.
		$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$referrer  = Channels::host( substr( $this->param( $request, 'r' ), 0, 2000 ), $site_host );
		$utm       = array(
			'source'   => $this->text( $request->get_param( 'us' ), 100 ),
			'medium'   => $this->text( $request->get_param( 'um' ), 100 ),
			'campaign' => $this->text( $request->get_param( 'uc' ), 100 ),
		);
		$channel   = Channels::classify( $referrer, $utm['source'], $utm['medium'] );
		$is_entry  = Visit::is_entry( (int) ( $row->today ?? 0 ), $referrer );
		$agent     = UserAgent::parse( $ua );
		$country   = CountryDatabase::lookup( $ip );
		$uri       = $this->relative_uri( $this->param( $request, 'u' ) );
		$day       = wp_date( 'Y-m-d' );

		// Passo 10: scrittura della visita (ts in UTC, day nel fuso del sito).
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$hits} (ts, day, visitor, object_type, object_id, uri, referrer_host, channel, source, utm_source, utm_medium, utm_campaign, is_entry, country, device, browser, os)
				VALUES (%s, %s, UNHEX(%s), %s, %d, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s)",
				gmdate( 'Y-m-d H:i:s' ),
				$day,
				$hash,
				$type,
				(int) $id,
				$uri,
				substr( $referrer, 0, 190 ),
				$channel['channel'],
				$this->text( $channel['source'], 100 ),
				$utm['source'],
				$utm['medium'],
				$utm['campaign'],
				$is_entry ? 1 : 0,
				substr( $country, 0, 2 ),
				substr( $agent['device'], 0, 20 ),
				substr( $agent['browser'], 0, 40 ),
				substr( $agent['os'], 0, 40 )
			)
		);

		// Passo 11: aggregati giornalieri di 404 e ricerche. Solo per le visite registrate (dopo tutti gli scarti)
		// e senza legame con il visitatore: né l'hash né l'ID della riga di wp5w_hits.
		if ( '404' === $type ) {
			$this->count_not_found( $day, $uri, substr( Channels::host( substr( $this->param( $request, 'r' ), 0, 2000 ), '' ), 0, 190 ) );
		} elseif ( 'search' === $type ) {
			$this->count_search( $day, $request );
		}
	}

	/**
	 * Conta una visita a una pagina inesistente (una riga per giorno, URI e host del referrer).
	 *
	 * Il referrer comprende il sito stesso: nell'admin distingue i link interni rotti da quelli esterni.
	 *
	 * @param string $day      Giorno del sito (Y-m-d).
	 * @param string $uri      Percorso della pagina, senza query string.
	 * @param string $referrer Host del referrer (solo l'host, mai il percorso).
	 * @return void
	 */
	private function count_not_found( string $day, string $uri, string $referrer ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Schema::table( 'notfound_daily' ) . ' (day, uri, referrer, hits, last_seen) VALUES (%s, %s, %s, 1, %s)
				ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen)',
				$day,
				$uri,
				$referrer,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Conta una ricerca interna (una riga per giorno e termine, senza nessun orario).
	 *
	 * Il termine (`q`) e il numero di risultati (`n`) non sono coperti dalla firma, quindi li decide qui SearchTerm:
	 * normalizza, filtra email e numeri lunghi (salvati come «(filtrato)») e ignora i termini vuoti.
	 *
	 * @param string           $day     Giorno del sito (Y-m-d).
	 * @param \WP_REST_Request $request Richiesta.
	 * @return void
	 */
	private function count_search( string $day, \WP_REST_Request $request ): void {
		global $wpdb;

		$term = SearchTerm::store( $this->text( $request->get_param( 'q' ), 200 ) );
		if ( '' === $term ) {
			return;
		}

		// Senza risultati solo con n = 0: un n mancante o non numerico vale «risultati sconosciuti», cioè con risultati.
		$empty = '0' === $this->param( $request, 'n' ) ? 1 : 0;

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Schema::table( 'search_daily' ) . ' (day, term, searches, empty) VALUES (%s, %s, 1, %d)
				ON DUPLICATE KEY UPDATE searches = searches + 1, empty = empty + VALUES(empty)',
				$day,
				$term,
				$empty
			)
		);
	}

	/**
	 * Conta uno scarto del giorno per motivo (bot, user, dnt, rate, signature, ip).
	 *
	 * @param string $reason Motivo.
	 * @return void
	 */
	private function discard( string $reason ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Schema::table( 'discards' ) . ' (day, reason, n) VALUES (%s, %s, 1) ON DUPLICATE KEY UPDATE n = n + 1',
				wp_date( 'Y-m-d' ),
				$reason
			)
		);
	}

	/**
	 * ID dell'utente collegato, letto dal cookie di login (0 se manca o non è valido).
	 *
	 * Una richiesta REST senza X-WP-Nonce viene riportata all'utente 0 da rest_cookie_check_errors(),
	 * e il beacon da una pagina in cache non può avere un nonce valido: is_user_logged_in() sarebbe sempre falso.
	 * La lettura serve solo a scartare la visita, quindi non c'è rischio CSRF.
	 *
	 * @return int
	 */
	private function logged_in_user_id(): int {
		if ( empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			return 0;
		}

		// Il valore non si sanifica: wp_validate_auth_cookie() ne verifica firma e scadenza.
		$cookie = wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verificato da wp_validate_auth_cookie().
		return (int) wp_validate_auth_cookie( is_string( $cookie ) ? $cookie : '', 'logged_in' );
	}

	/**
	 * Chiave del giorno per l'hash del visitatore (R6): si rigenera alla prima visita del giorno.
	 *
	 * La chiave del giorno prima viene sovrascritta e non resta da nessuna parte, così nemmeno il plugin
	 * può collegare le visite di giorni diversi. L'UPDATE condizionato al vecchio valore evita che due
	 * richieste simultanee creino due chiavi diverse (e contino due volte lo stesso visitatore).
	 *
	 * @return string
	 */
	private function daily_key(): string {
		global $wpdb;

		$today  = wp_date( 'Y-m-d' );
		$stored = get_option( self::SALT_OPTION, false );

		// Passo 1: chiave già valida per oggi.
		if ( is_string( $stored ) && str_starts_with( $stored, $today . '|' ) ) {
			return substr( $stored, strlen( $today ) + 1 );
		}

		// Passo 2: nuova chiave. Se l'opzione non esiste la si crea con INSERT IGNORE (add_option() fa ON DUPLICATE KEY UPDATE
		// e lascerebbe vincere due richieste simultanee), altrimenti si sostituisce solo il vecchio valore letto.
		$new = $today . '|' . bin2hex( random_bytes( 32 ) );
		if ( false === $stored ) {
			$won = 1 === $wpdb->query(
				$wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'on')", self::SALT_OPTION, $new )
			);
		} else {
			$won = 1 === $wpdb->query(
				$wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new, self::SALT_OPTION, (string) $stored )
			);
		}

		// Passo 3: cache delle opzioni da invalidare, perché l'UPDATE passa dal database.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( self::SALT_OPTION, 'options' );

		if ( $won ) {
			return substr( $new, strlen( $today ) + 1 );
		}

		// Passo 4: un'altra richiesta ha vinto, si usa la sua chiave (letta direttamente dal database).
		$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::SALT_OPTION ) );
		return str_starts_with( $current, $today . '|' ) ? substr( $current, strlen( $today ) + 1 ) : substr( $new, strlen( $today ) + 1 );
	}

	/**
	 * Percorso della pagina relativo alla home, senza query string né frammento, al massimo 190 caratteri.
	 *
	 * @param string $path Percorso inviato dal tracker (location.pathname).
	 * @return string
	 */
	private function relative_uri( string $path ): string {
		$uri = (string) wp_parse_url( '/' . ltrim( $path, '/' ), PHP_URL_PATH );

		// Sito in una sottocartella: si toglie il percorso della home.
		$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' !== $home && str_starts_with( $uri, $home ) ) {
			$uri = substr( $uri, strlen( $home ) );
		}

		return $this->text( '' === $uri ? '/' : $uri, 190 );
	}

	/**
	 * Parametro della richiesta come stringa: vuota se manca o non è scalare (t[]=x, id[]=1...).
	 *
	 * Il cast diretto di un array a stringa genera un PHP Warning, che sulla rotta pubblica
	 * chiunque potrebbe provocare a ogni richiesta.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @param string           $key     Nome del parametro.
	 * @return string
	 */
	private function param( \WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Testo sanificato e troncato alla lunghezza della colonna.
	 *
	 * @param mixed $value  Valore ricevuto.
	 * @param int   $length Lunghezza massima in caratteri.
	 * @return string
	 */
	private function text( mixed $value, int $length ): string {
		return mb_substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, $length );
	}

	/**
	 * Annota un errore interno nel log di PHP, solo con WP_DEBUG.
	 *
	 * @param string $message Messaggio.
	 * @return void
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'wp5w hit: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Solo con WP_DEBUG.
		}
	}
}
