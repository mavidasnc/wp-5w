<?php
/**
 * Passaggi in wp-admin del collegamento OAuth: partenza verso Google e ritorno dal consenso.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Gsc;

use Mavida\FiveW\Admin\AdminMenu;
use Mavida\FiveW\Etl\GscApiImport;
use Mavida\FiveW\REST\GscController;

defined( 'ABSPATH' ) || exit;

/**
 * Usa admin-post.php e non la REST API: al ritorno da Google la richiesta è una normale navigazione del browser,
 * autenticata dal cookie dell'admin (una rotta REST senza nonce verrebbe trattata come anonima).
 * L'esito non viaggia nell'URL: si salva in un transient per utente e lo legge GET /admin/gsc (`flash()`).
 */
final class OAuthHandler {

	/**
	 * Nome del nonce del link «Collega Google».
	 *
	 * @var string
	 */
	public const NONCE = 'wp5w_gsc_connect';

	/**
	 * Aggancia le due azioni di admin-post.php.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . Client::ACTION_CONNECT, array( $this, 'connect' ) );
		add_action( 'admin_post_' . Client::ACTION_CALLBACK, array( $this, 'callback' ) );
	}

	/**
	 * Indirizzo del link «Collega Google» (con il nonce), per la scheda Opzioni.
	 *
	 * @return string
	 */
	public static function connect_url(): string {
		return add_query_arg(
			'_wpnonce',
			wp_create_nonce( self::NONCE ),
			admin_url( 'admin-post.php?action=' . Client::ACTION_CONNECT )
		);
	}

	/**
	 * Porta l'utente alla schermata di consenso di Google.
	 *
	 * @return void
	 */
	public function connect(): void {
		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'wp-5w' ), '', array( 'response' => 403 ) );
		}
		if ( ! Client::is_configured() ) {
			self::back( __( 'Salva prima client ID e secret.', 'wp-5w' ) );
		}

		wp_redirect( Client::auth_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Destinazione fissa: accounts.google.com, non un host di WordPress.
		exit;
	}

	/**
	 * Ritorno da Google (il redirect URI del client OAuth).
	 *
	 * @return void
	 */
	public function callback(): void {
		// Si controlla prima chi è l'utente: senza permessi non si legge nemmeno il codice.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'wp-5w' ), '', array( 'response' => 403 ) );
		}

		// Il ritorno da Google non ha un nonce di WordPress: lo protegge lo state di Client::exchange().
		self::back( self::finish( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Elabora i parametri del ritorno da Google.
	 *
	 * @param array<string,mixed> $query Parametri della richiesta (`code`, `state`, `error`).
	 * @return string|null Messaggio d'errore, null se il collegamento è riuscito.
	 */
	public static function finish( array $query ): ?string {
		$param = static fn( string $key ): string => isset( $query[ $key ] ) && is_string( $query[ $key ] ) ? sanitize_text_field( wp_unslash( $query[ $key ] ) ) : '';

		if ( '' !== $param( 'error' ) ) {
			// L'utente ha rifiutato (access_denied) o Google segnala un problema del client.
			return 'access_denied' === $param( 'error' )
				? __( 'Hai annullato il consenso: Search Console non è stata collegata.', 'wp-5w' )
				/* translators: %s: codice d'errore di Google. */
				: sprintf( __( 'Google ha risposto con un errore: %s.', 'wp-5w' ), $param( 'error' ) );
		}

		try {
			Client::exchange( $param( 'code' ), $param( 'state' ) );
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}

		// Un account nuovo ha altri dati: i giorni vuoti e l'esito di prima non valgono più.
		GscApiImport::reset();
		delete_transient( GscController::SITES_TRANSIENT );
		return null;
	}

	/**
	 * Esito del collegamento da mostrare una sola volta nella scheda Opzioni.
	 *
	 * @return array{status:string,text:string}|null
	 */
	public static function flash(): ?array {
		$key   = self::flash_key();
		$flash = get_transient( $key );
		delete_transient( $key );
		return is_array( $flash ) ? array(
			'status' => (string) ( $flash['status'] ?? 'error' ),
			'text'   => (string) ( $flash['text'] ?? '' ),
		) : null;
	}

	/**
	 * Salva l'esito e torna alla scheda Opzioni.
	 *
	 * @param string|null $error Messaggio d'errore, null se è andato tutto bene.
	 * @return never
	 */
	private static function back( ?string $error ): never {
		set_transient(
			self::flash_key(),
			null === $error
				? array(
					'status' => 'success',
					'text'   => __( 'Google collegato.', 'wp-5w' ),
				)
				: array(
					'status' => 'error',
					'text'   => $error,
				),
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG . '&tab=options' ) );
		exit;
	}

	/**
	 * Chiave del transient dell'esito, una per utente.
	 *
	 * @return string
	 */
	private static function flash_key(): string {
		return 'wp5w_gsc_flash_' . get_current_user_id();
	}
}
