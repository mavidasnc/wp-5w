<?php
/**
 * Riconosce i tipi di oggetto il cui ID è quello di un articolo o di una pagina.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Per i termini (category, post_tag, tax_*), gli autori, gli archivi, le 404 e le ricerche l'ID non è un post:
 * titolo e link non si leggono da get_the_title() e get_permalink().
 */
final class ObjectType {

	/**
	 * Vero se l'ID maggiore di zero è quello di un post: tipo `post`, `page`, `home` o `post_type_*`.
	 *
	 * @param string $type Tipo di oggetto (come in `object_daily` e `wp5w_hits`).
	 * @param int    $id   ID dell'oggetto.
	 * @return bool
	 */
	public static function is_post( string $type, int $id ): bool {
		return $id > 0 && ( in_array( $type, array( 'post', 'page', 'home' ), true ) || str_starts_with( $type, 'post_type_' ) );
	}
}
