<?php
/**
 * Dati di «Online adesso» (scheda Visitatori).
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Query;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Settings;
use Mavida\FiveW\Support\ObjectType;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin, nomi costruiti con $wpdb->prefix in Schema::table().

/**
 * Visitatori distinti con almeno una visita negli ultimi 5 minuti, ognuno contato una volta sul contenuto della sua ultima visita.
 *
 * Legge solo `wp5w_hits`, che è sempre raccolta propria (nessuna regola dell'origine). La risposta non contiene
 * né hash né orari delle singole visite né altri dati per visitatore: solo conteggi per contenuto.
 */
final class OnlineQuery {

	/**
	 * Finestra «online», in minuti.
	 *
	 * @var int
	 */
	private const MINUTES = 5;

	/**
	 * Contenuti elencati: gli altri vanno in `other`.
	 *
	 * @var int
	 */
	private const LIMIT = 10;

	/**
	 * Dati per la rotta /admin/online.
	 *
	 * @return array<string,mixed>
	 */
	public function get(): array {
		global $wpdb;

		$hits = Schema::table( 'hits' );

		// Passo 1: finestra su ts (UTC) e giorni di oggi e ieri (nel fuso del sito): l'indice `day` si usa anche a cavallo della mezzanotte.
		$now    = time();
		$cutoff = gmdate( 'Y-m-d H:i:s', $now - self::MINUTES * MINUTE_IN_SECONDS );
		$days   = array( wp_date( 'Y-m-d', $now ), wp_date( 'Y-m-d', $now - DAY_IN_SECONDS ) );

		// Passo 2: l'ultima visita di ogni visitatore, poi i visitatori per contenuto.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.object_type, h.object_id, MIN(h.uri) AS uri, COUNT(*) AS visitors
				FROM {$hits} h
				JOIN ( SELECT MAX(id) AS id FROM {$hits} WHERE day IN (%s, %s) AND ts >= %s GROUP BY visitor ) l ON l.id = h.id
				GROUP BY h.object_type, h.object_id
				ORDER BY visitors DESC, h.object_type, h.object_id",
				$days[0],
				$days[1],
				$cutoff
			),
			ARRAY_A
		);

		// Passo 3: totale, primi contenuti e somma degli altri.
		$visitors = (int) array_sum( array_column( $rows, 'visitors' ) );
		$contents = array_map( array( $this, 'content' ), array_slice( $rows, 0, self::LIMIT ) );

		return array(
			'minutes'  => self::MINUTES,
			'visitors' => $visitors,
			'tracking' => Settings::get()['own_tracking'],
			'at'       => wp_date( 'H:i:s', $now ),
			'contents' => $contents,
			'other'    => $visitors - (int) array_sum( array_column( $contents, 'visitors' ) ),
		);
	}

	/**
	 * Titolo e link di un contenuto: da WordPress se l'ID è quello di un post, altrimenti dall'URI.
	 *
	 * @param array<string,mixed> $row Riga con object_type, object_id, uri e visitors.
	 * @return array{type:string,id:int,title:string,url:string,visitors:int}
	 */
	private function content( array $row ): array {
		$type = (string) $row['object_type'];
		$id   = (int) $row['object_id'];
		$uri  = (string) $row['uri'];

		if ( ObjectType::is_post( $type, $id ) ) {
			$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
			$link  = get_permalink( $id );
		} else {
			// Nome fisso per la home, le 404 e le ricerche; per il resto l'URI.
			$title = match ( true ) {
				'home' === $type && 0 === $id => __( 'Home', 'wp-5w' ),
				'404' === $type               => __( 'Pagina non trovata', 'wp-5w' ),
				'search' === $type            => __( 'Ricerca', 'wp-5w' ),
				default                       => $uri,
			};
			$link = false;
		}

		return array(
			'type'     => $type,
			'id'       => $id,
			'title'    => '' !== $title ? $title : $uri,
			'url'      => $link ? (string) $link : home_url( $uri ),
			'visitors' => (int) $row['visitors'],
		);
	}
}
