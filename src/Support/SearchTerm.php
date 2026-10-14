<?php
/**
 * Normalizzazione e filtro della privacy dei termini di ricerca interna.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Usata dal tracker (stampa del termine) e dall'endpoint, che è quello che decide cosa si salva.
 */
final class SearchTerm {

	/**
	 * Lunghezza massima del termine, in caratteri.
	 *
	 * @var int
	 */
	public const MAX = 60;

	/**
	 * Valore salvato al posto di un termine privato (email, numeri lunghi).
	 *
	 * @var string
	 */
	public const FILTERED = '(filtrato)';

	/**
	 * Termine normalizzato: senza caratteri di controllo, spazi ridotti a uno, minuscolo, al massimo 60 caratteri.
	 *
	 * @param string $raw Termine come arriva.
	 * @return string
	 */
	public static function normalize( string $raw ): string {
		// Passo 1: via i caratteri di controllo (tabulazioni e a capo diventano spazi al passo 2, quindi si salvano).
		$term = (string) preg_replace( '/[^\P{Cc}\s]/u', '', $raw );

		// Passo 2: ogni sequenza di spazi (anche Unicode) diventa uno spazio solo.
		$term = trim( (string) preg_replace( '/\s+/u', ' ', $term ) );

		// Passo 3: minuscolo e lunghezza massima.
		return trim( mb_substr( mb_strtolower( $term ), 0, self::MAX ) );
	}

	/**
	 * Vero se il termine sembra un dato personale: un'email oppure almeno 9 cifre consecutive
	 * (anche separate da un solo spazio, punto, trattino o barra), cioè telefoni, IBAN, carte e partite IVA.
	 *
	 * @param string $term Termine normalizzato.
	 * @return bool
	 */
	public static function is_private( string $term ): bool {
		return 1 === preg_match( '/[^\s@]+@[^\s@]+\.[^\s@]+/u', $term ) || 1 === preg_match( '/\d(?:[\s.\/-]?\d){8,}/u', $term );
	}

	/**
	 * Valore da salvare: vuoto se il termine è vuoto, `(filtrato)` se è privato, altrimenti il termine normalizzato.
	 *
	 * @param string $raw Termine come arriva.
	 * @return string
	 */
	public static function store( string $raw ): string {
		$term = self::normalize( $raw );
		if ( '' === $term ) {
			return '';
		}
		return self::is_private( $term ) ? self::FILTERED : $term;
	}
}
