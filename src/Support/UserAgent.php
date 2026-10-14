<?php
/**
 * Riconoscimento di dispositivo, browser e sistema operativo dallo user agent.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari. I nomi restituiti sono quelli di
 * device-detector, che usa WP Statistics: così le righe `own` e `wps` di `dim_daily` si possono confrontare.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Regole essenziali (nessuna dipendenza): per ogni campo vince la prima regola che corrisponde.
 */
final class UserAgent {

	/**
	 * Dispositivo, browser e sistema operativo di uno user agent. Un valore non riconosciuto è una stringa vuota.
	 *
	 * Limiti noti: gli iPad con iPadOS 13 e successivi inviano lo user agent di un Mac (contano come desktop),
	 * e i browser minori (Brave, Vivaldi, Yandex, Instagram, LinkedIn...) risultano come il browser su cui si basano.
	 *
	 * @param string $ua User agent della richiesta.
	 * @return array{device:string,browser:string,os:string}
	 */
	public static function parse( string $ua ): array {
		return array(
			'device'  => self::device( $ua ),
			'browser' => self::browser( $ua ),
			'os'      => self::os( $ua ),
		);
	}

	/**
	 * Vero se lo user agent contiene almeno una delle stringhe (le firme dei browser hanno sempre la stessa grafia).
	 *
	 * @param string            $ua      User agent.
	 * @param array<int,string> $needles Stringhe da cercare.
	 * @return bool
	 */
	private static function has( string $ua, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( str_contains( $ua, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Dispositivo: tablet, smartphone, desktop.
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	private static function device( string $ua ): string {
		$android = str_contains( $ua, 'Android' );

		// Passo 1: tablet (iPad, Firefox per tablet Android, Android senza "Mobile").
		if ( str_contains( $ua, 'iPad' ) || str_contains( $ua, 'Tablet' ) || ( $android && ! str_contains( $ua, 'Mobile' ) ) ) {
			return 'tablet';
		}

		// Passo 2: smartphone (iPhone, iPod, Android con "Mobile").
		if ( $android || self::has( $ua, array( 'iPhone', 'iPod' ) ) ) {
			return 'smartphone';
		}

		// Passo 3: computer.
		if ( self::has( $ua, array( 'Windows NT', 'Macintosh', 'X11', 'CrOS' ) ) ) {
			return 'desktop';
		}

		return '';
	}

	/**
	 * Sistema operativo (la dimensione `platform` di dim_daily).
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	private static function os( string $ua ): string {
		// L'ordine conta: gli user agent di iPhone contengono "like Mac OS X", quindi iOS viene prima di Mac.
		$rules = array(
			'Windows'   => array( 'Windows' ),
			'iPadOS'    => array( 'iPad' ),
			'iOS'       => array( 'iPhone', 'iPod' ),
			'Android'   => array( 'Android' ),
			'Chrome OS' => array( 'CrOS' ),
			'Mac'       => array( 'Macintosh', 'Mac OS X' ),
			'Ubuntu'    => array( 'Ubuntu' ),
			'GNU/Linux' => array( 'Linux', 'X11' ),
		);

		foreach ( $rules as $name => $needles ) {
			if ( self::has( $ua, $needles ) ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Browser: le firme più specifiche prima, perché Chrome, Edge e Opera contengono tutti "Chrome/" e "Safari/".
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	private static function browser( string $ua ): string {
		$mobile = str_contains( $ua, 'Mobile' );

		// Passo 1: browser incorporati e derivati di Chrome, dalla firma più specifica.
		if ( self::has( $ua, array( 'FBAN', 'FBAV', 'FB_IAB' ) ) ) {
			return 'Facebook';
		}
		if ( str_contains( $ua, 'GSA/' ) ) {
			return 'Google Search App';
		}
		if ( self::has( $ua, array( 'Edg/', 'EdgA/', 'EdgiOS/' ) ) ) {
			return 'Microsoft Edge';
		}
		if ( str_contains( $ua, 'OPR/' ) ) {
			return $mobile ? 'Opera Mobile' : 'Opera';
		}
		if ( str_contains( $ua, 'SamsungBrowser/' ) ) {
			return 'Samsung Browser';
		}

		// Passo 2: Firefox e Chrome su iOS, su Android e sul desktop.
		if ( str_contains( $ua, 'FxiOS/' ) ) {
			return 'Firefox Mobile iOS';
		}
		if ( str_contains( $ua, 'Firefox/' ) ) {
			return str_contains( $ua, 'Android' ) ? 'Firefox Mobile' : 'Firefox';
		}
		if ( str_contains( $ua, 'CriOS/' ) ) {
			return 'Chrome Mobile iOS';
		}
		if ( str_contains( $ua, '; wv)' ) && str_contains( $ua, 'Chrome/' ) ) {
			return 'Chrome Webview';
		}
		if ( self::has( $ua, array( 'Chrome/', 'Chromium/' ) ) ) {
			return $mobile ? 'Chrome Mobile' : 'Chrome';
		}

		// Passo 3: Safari (senza Chrome nella firma).
		if ( str_contains( $ua, 'Version/' ) && str_contains( $ua, 'Safari/' ) ) {
			return self::has( $ua, array( 'iPhone', 'iPad', 'iPod' ) ) ? 'Mobile Safari' : 'Safari';
		}

		return '';
	}
}
