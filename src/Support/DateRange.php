<?php
/**
 * Intervalli di date usati dall'ETL e dalle interrogazioni.
 *
 * Classe pura (nessun accesso a WordPress): è coperta dai test unitari.
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Support;

/**
 * Intervallo chiuso di giorni [from, to] in formato Y-m-d.
 */
final class DateRange {

	/**
	 * Costruttore.
	 *
	 * @param string $from Primo giorno (Y-m-d).
	 * @param string $to   Ultimo giorno incluso (Y-m-d).
	 * @throws \InvalidArgumentException Se le date non sono valide o sono invertite.
	 */
	public function __construct( public readonly string $from, public readonly string $to ) {
		if ( ! self::is_valid( $from ) || ! self::is_valid( $to ) ) {
			throw new \InvalidArgumentException( "Date non valide: {$from} - {$to}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Classe pura, il messaggio non viene stampato come HTML.
		}
		if ( $from > $to ) {
			throw new \InvalidArgumentException( "Intervallo invertito: {$from} - {$to}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Classe pura, il messaggio non viene stampato come HTML.
		}
	}

	/**
	 * Controlla il formato Y-m-d e l'esistenza della data.
	 *
	 * @param string $date Data da verificare.
	 * @return bool
	 */
	public static function is_valid( string $date ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Numero di giorni dell'intervallo (estremi inclusi).
	 *
	 * @return int
	 */
	public function days(): int {
		return (int) ( new \DateTimeImmutable( $this->from ) )->diff( new \DateTimeImmutable( $this->to ) )->days + 1;
	}

	/**
	 * Periodo precedente della stessa durata, che termina il giorno prima di from.
	 *
	 * @return DateRange
	 */
	public function previous(): DateRange {
		$end   = ( new \DateTimeImmutable( $this->from ) )->modify( '-1 day' );
		$start = $end->modify( '-' . ( $this->days() - 1 ) . ' days' );
		return new self( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) );
	}

	/**
	 * Intersezione con un altro intervallo, null se non si sovrappongono.
	 *
	 * @param DateRange $other Altro intervallo.
	 * @return DateRange|null
	 */
	public function intersect( DateRange $other ): ?DateRange {
		$from = max( $this->from, $other->from );
		$to   = min( $this->to, $other->to );
		return $from <= $to ? new self( $from, $to ) : null;
	}

	/**
	 * Divide l'intervallo in blocchi per mese solare, per contenere memoria e durata delle query.
	 *
	 * @return array<int,DateRange>
	 */
	public function months(): array {
		$chunks = array();
		$cursor = new \DateTimeImmutable( $this->from );
		$end    = new \DateTimeImmutable( $this->to );

		while ( $cursor <= $end ) {
			$last     = min( $cursor->modify( 'last day of this month' ), $end );
			$chunks[] = new self( $cursor->format( 'Y-m-d' ), $last->format( 'Y-m-d' ) );
			$cursor   = $last->modify( '+1 day' );
		}

		return $chunks;
	}

	/**
	 * Ultimi N giorni fino a oggi incluso.
	 *
	 * @param int    $days  Numero di giorni (almeno 1).
	 * @param string $today Oggi (Y-m-d), nel fuso del sito.
	 * @return DateRange
	 */
	public static function last_days( int $days, string $today ): DateRange {
		$end = new \DateTimeImmutable( $today );
		return new self( $end->modify( '-' . ( max( 1, $days ) - 1 ) . ' days' )->format( 'Y-m-d' ), $today );
	}
}
