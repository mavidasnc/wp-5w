<?php
/**
 * Base delle sorgenti dati (tabelle di altri plugin).
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Sources;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\SchemaCheck;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabelle di altri plugin e del plugin, nomi costruiti con $wpdb->prefix.

/**
 * Una sorgente legge le tabelle interne di un altro plugin e le riversa nelle tabelle wp5w_*.
 *
 * Gli schemi di quelle tabelle possono cambiare con gli aggiornamenti: prima di ogni import
 * si verificano le colonne richieste e, se manca qualcosa, la sorgente viene saltata.
 */
abstract class Source {

	/**
	 * Identificativo breve (usato nello stato dell'ETL).
	 *
	 * @return string
	 */
	abstract public function id(): string;

	/**
	 * Nome leggibile del plugin sorgente.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Versione del plugin sorgente, null se non è attivo.
	 *
	 * @return string|null
	 */
	abstract public function version(): ?string;

	/**
	 * Tabelle (senza prefisso) e colonne necessarie.
	 *
	 * @return array<string,array<int,string>>
	 */
	abstract protected function required(): array;

	/**
	 * Riversa i dati dell'intervallo nelle tabelle wp5w_*.
	 *
	 * Deve essere idempotente: rieseguire lo stesso intervallo produce lo stesso risultato.
	 *
	 * @param DateRange $range Intervallo richiesto.
	 * @return array<string,int> Tabella wp5w => righe scritte.
	 */
	abstract public function import( DateRange $range ): array;

	/**
	 * Stato della sorgente per la pagina Stato e per la CLI.
	 *
	 * @return array{id:string,label:string,version:string|null,ok:bool,missing:array<int,string>}
	 */
	public function detect(): array {
		$actual = array();
		foreach ( array_keys( $this->required() ) as $table ) {
			$actual[ $table ] = $this->columns( $table );
		}
		$missing = SchemaCheck::missing( $this->required(), $actual );

		return array(
			'id'      => $this->id(),
			'label'   => $this->label(),
			'version' => $this->version(),
			'ok'      => array() === $missing,
			'missing' => $missing,
		);
	}

	/**
	 * Nome completo di una tabella della sorgente.
	 *
	 * @param string $name Nome senza prefisso.
	 * @return string
	 */
	protected function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	/**
	 * Colonne di una tabella della sorgente, null se la tabella non esiste.
	 *
	 * @param string $name Nome senza prefisso.
	 * @return array<int,string>|null
	 */
	protected function columns( string $name ): ?array {
		global $wpdb;

		$table = $this->table( $name );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return null;
		}
		return $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
	}

	/**
	 * Giorni in cui una tabella sorgente ha dati, null se è vuota.
	 *
	 * @param string $name   Tabella senza prefisso.
	 * @param string $column Espressione SQL della data (es. "date", "DATE(created)").
	 * @return DateRange|null
	 */
	protected function data_range( string $name, string $column ): ?DateRange {
		global $wpdb;

		$table = $this->table( $name );
		$row   = $wpdb->get_row( "SELECT MIN({$column}) AS d_from, MAX({$column}) AS d_to FROM {$table}" );
		if ( ! $row || ! $row->d_from || ! DateRange::is_valid( (string) $row->d_from ) ) {
			return null;
		}
		return new DateRange( (string) $row->d_from, (string) $row->d_to );
	}

	/**
	 * Sostituisce, mese per mese, le righe di una tabella wp5w nell'intervallo in cui la sorgente ha dati.
	 *
	 * Si cancella solo dove la sorgente ha ancora dati: i giorni che WP Statistics o Rank Math hanno
	 * già eliminato restano nelle tabelle wp5w_* (sono l'unica copia).
	 *
	 * @param string         $target    Tabella wp5w (nome breve).
	 * @param DateRange      $requested Intervallo richiesto.
	 * @param DateRange|null $available Intervallo con dati nella sorgente.
	 * @param string         $select    SELECT che produce le colonne di $columns; ogni coppia di segnaposto %s riceve primo e ultimo giorno.
	 * @param string         $columns   Colonne di destinazione, separate da virgola.
	 * @param string         $delete    Condizione SQL aggiuntiva per la DELETE (con coppie di segnaposto come $select),
	 *                                  per le sorgenti con buchi o con righe di altra origine: si cancella solo ciò
	 *                                  che la sorgente rimpiazza (es. non i giorni recuperati dall'API di Search Console).
	 * @return int Righe inserite.
	 * @throws \RuntimeException Se una query fallisce (la transazione del mese viene annullata).
	 */
	protected function replace_by_month( string $target, DateRange $requested, ?DateRange $available, string $select, string $columns, string $delete = '' ): int {
		global $wpdb;

		$range = $available ? $requested->intersect( $available ) : null;
		if ( null === $range ) {
			return 0;
		}

		$table = Schema::table( $target );
		$rows  = 0;

		foreach ( $range->months() as $month ) {
			$wpdb->query( 'START TRANSACTION' );

			$deleted  = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day BETWEEN %s AND %s {$delete}", self::month_args( $month, 'day BETWEEN %s AND %s' . $delete ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $delete contiene i segnaposto preparati qui.
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} ({$columns}) {$select}", self::month_args( $month, $select ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $select contiene i segnaposto preparati qui.

			if ( false === $deleted || false === $inserted ) {
				$wpdb->query( 'ROLLBACK' );
				throw new \RuntimeException( "{$target} {$month->from}/{$month->to}: {$wpdb->last_error}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messaggio per stato e CLI, l'interfaccia lo stampa come testo.
			}

			$wpdb->query( 'COMMIT' );
			$rows += (int) $inserted;
		}

		return $rows;
	}

	/**
	 * Una coppia (primo, ultimo giorno) per ogni coppia di segnaposto %s dell'SQL (le UNION e le subquery ne hanno più di una).
	 *
	 * @param DateRange $month Mese.
	 * @param string    $sql   SQL con i segnaposto.
	 * @return array<int,string>
	 */
	private static function month_args( DateRange $month, string $sql ): array {
		return array_merge( ...array_fill( 0, intdiv( substr_count( $sql, '%s' ), 2 ), array( $month->from, $month->to ) ) );
	}
}
