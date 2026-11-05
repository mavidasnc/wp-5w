<?php
/**
 * Test delle etichette dei referrer delle app.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Tests\Unit;

use Mavida\FiveW\Support\Referrers;
use PHPUnit\Framework\TestCase;

/**
 * Pacchetti noti, ripiego dei pacchetti sconosciuti e host web.
 */
final class ReferrersTest extends TestCase {

	/**
	 * @dataProvider labels
	 *
	 * @param string $referrer Referrer.
	 * @param string $expected Etichetta attesa.
	 */
	public function test_label( string $referrer, string $expected ): void {
		$this->assertSame( $expected, Referrers::label( $referrer ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function labels(): array {
		return array(
			'LinkedIn con schema'        => array( 'android-app://com.linkedin.android/', 'LinkedIn (app)' ),
			'LinkedIn solo pacchetto'    => array( 'com.linkedin.android', 'LinkedIn (app)' ),
			'Google maiuscolo'           => array( 'ANDROID-APP://com.google.android.googlequicksearchbox/', 'Google (app)' ),
			'Slack'                      => array( 'android-app://com.slack/', 'Slack (app)' ),
			'ChatGPT'                    => array( 'com.openai.chatgpt', 'ChatGPT (app)' ),
			'pacchetto sconosciuto com.' => array( 'android-app://com.example.reader/', 'com.example.reader (app)' ),
			'pacchetto sconosciuto org.' => array( 'org.example.app', 'org.example.app (app)' ),
			'host web in android-app'    => array( 'android-app://m.facebook.com/', 'm.facebook.com' ),
			'host web'                   => array( 'google.com', 'google.com' ),
			'sottodominio'               => array( 'lm.facebook.com', 'lm.facebook.com' ),
			'vuoto'                      => array( '', '' ),
		);
	}

	/**
	 * La chiave dei referrer di WP Statistics coincide con quella della raccolta propria.
	 *
	 * @dataProvider hits_keys
	 *
	 * @param string $referrer Referrer salvato.
	 * @param string $expected Chiave di wp5w_hits.
	 */
	public function test_hits_key( string $referrer, string $expected ): void {
		$this->assertSame( $expected, Referrers::hits_key( $referrer ) );
	}

	/**
	 * Casi di hits_key().
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function hits_keys(): array {
		return array(
			'app di WP Statistics'  => array( 'android-app://com.linkedin.android/', 'com.linkedin.android' ),
			'app della raccolta'    => array( 'com.linkedin.android', 'com.linkedin.android' ),
			'host con www'          => array( 'www.aocr.org', 'aocr.org' ),
			'host maiuscolo'        => array( ' AOCR.org ', 'aocr.org' ),
			'host senza www'        => array( 'aocr.org', 'aocr.org' ),
			'web dentro android'    => array( 'android-app://www.example.com/', 'example.com' ),
			'diretto'               => array( '', '' ),
		);
	}
}
