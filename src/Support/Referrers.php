<?php
/**
 * Etichette leggibili per i referrer delle app Android.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Trasforma `android-app://com.linkedin.android/` (WP Statistics) e `com.linkedin.android` (raccolta propria)
 * nella stessa etichetta «LinkedIn (app)». È solo presentazione: i dati nelle tabelle non cambiano.
 */
final class Referrers {

	/**
	 * Nome dei pacchetti Android più comuni.
	 *
	 * @var array<string,string>
	 */
	private const APPS = array(
		'com.linkedin.android'                    => 'LinkedIn',
		'com.google.android.googlequicksearchbox' => 'Google',
		'com.google.android.gm'                   => 'Gmail',
		'com.slack'                               => 'Slack',
		'com.facebook.katana'                     => 'Facebook',
		'com.facebook.orca'                       => 'Messenger',
		'com.instagram.android'                   => 'Instagram',
		'com.whatsapp'                            => 'WhatsApp',
		'org.telegram.messenger'                  => 'Telegram',
		'com.twitter.android'                     => 'X',
		'com.reddit.frontpage'                    => 'Reddit',
		'com.microsoft.teams'                     => 'Teams',
		'com.openai.chatgpt'                      => 'ChatGPT',
	);

	/**
	 * Etichetta di un referrer.
	 *
	 * @param string $referrer Referrer come salvato (host, pacchetto o URL `android-app://`).
	 * @return string
	 */
	public static function label( string $referrer ): string {
		// Passo 1: normalizzazione (minuscolo, senza spazi, senza schema android-app e senza "/" finale).
		$clean  = strtolower( trim( $referrer ) );
		$is_app = str_starts_with( $clean, 'android-app://' );
		$name   = rtrim( $is_app ? substr( $clean, strlen( 'android-app://' ) ) : $clean, '/' );

		// Passo 2: pacchetto noto.
		if ( isset( self::APPS[ $name ] ) ) {
			return self::APPS[ $name ] . ' (app)';
		}

		// Passo 3: ripiego, un nome di pacchetto comincia con com., org. o net. (un host pubblico non comincia con un dominio di primo livello).
		if ( 1 === preg_match( '/^(?:com|org|net)\./', $name ) ) {
			return $name . ' (app)';
		}

		// Passo 4: host web dentro un URL android-app, oppure il referrer così com'è.
		return $is_app ? $name : $referrer;
	}
}
