<?php
/**
 * Sorgente della raccolta propria (tabella wp5w_hits).
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Sources;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Settings;
use Mavida\FiveW\Support\Channels;
use Mavida\FiveW\Support\DateRange;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin, nomi costruiti con $wpdb->prefix.

/**
 * Trasforma le visite grezze di `wp5w_hits` nelle righe `origin = 'own'` delle cinque tabelle aggregate.
 *
 * Ogni tabella passa da `replace_by_month()` con la condizione `origin = 'own'`: le righe `wps` non si toccano mai,
 * e i giorni che non sono più in `wp5w_hits` restano come sono.
 */
final class OwnSource extends Source {

	/**
	 * Lock MySQL tra ETL notturno e passaggio orario: i due percorsi che scrivono righe own non si sovrappongono.
	 *
	 * @var string
	 */
	private const LOCK = 'wp5w_own_aggregate';

	/**
	 * Giorni rielaborati dal passaggio orario: ieri (si chiude entro un'ora dalla mezzanotte) e oggi.
	 *
	 * @var int
	 */
	private const HOURLY_DAYS = 2;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'own';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Raccolta propria', 'wp-5w' );
	}

	/**
	 * La sorgente è "attiva" quando la raccolta è accesa nelle Opzioni.
	 *
	 * {@inheritDoc}
	 */
	public function version(): ?string {
		return Settings::get()['own_tracking'] ? WP5W_VERSION : null;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function required(): array {
		return array(
			'wp5w_hits' => array( 'ts', 'day', 'visitor', 'object_type', 'object_id', 'uri', 'referrer_host', 'channel', 'source', 'utm_source', 'utm_medium', 'utm_campaign', 'is_entry', 'country', 'device', 'browser', 'os' ),
		);
	}

	/**
	 * Aggrega le visite dell'intervallo nelle righe own.
	 *
	 * @param DateRange $range Intervallo richiesto.
	 * @return array<string,int> Chiavi con prefisso "own:", per non sovrascrivere quelle di WP Statistics in Importer::run().
	 * @throws \RuntimeException Se un'altra aggregazione è in corso.
	 */
	public function import( DateRange $range ): array {
		global $wpdb;

		// Passo 1: lock (attesa fino a 30 secondi), preso sia dall'ETL notturno sia dal passaggio orario.
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 30 )', self::LOCK ) ) ) {
			throw new \RuntimeException( 'Aggregazione della raccolta propria già in corso' );
		}

		try {
			$hits      = Schema::table( 'hits' );
			$available = $this->data_range( 'wp5w_hits', 'day' );
			$delete    = " AND origin = 'own'";
			$rows      = array();

			// Canale «ai»: la regola è la stessa che WpStatisticsSource applica alle righe wps. Si decide qui, in aggregazione,
			// guardando host del referrer e utm_source: wp5w_hits resta con i canali di WP Statistics (Channels::classify()).
			$ai     = Channels::ai_sql( 'referrer_host', 'utm_source' );
			$ai_hit = Channels::ai_sql( 'h.referrer_host', 'h.utm_source' );

			// Totali del sito: visitatori distinti e visualizzazioni per giorno.
			$rows['own:site_daily'] = $this->replace_by_month(
				'site_daily',
				$range,
				$available,
				"SELECT day, COUNT(DISTINCT visitor), COUNT(*), 'own' FROM {$hits} WHERE day BETWEEN %s AND %s GROUP BY day",
				'day, visitors, views, origin',
				$delete
			);

			// Contenuti: un visitatore conta una volta per contenuto e per giorno.
			$rows['own:object_daily'] = $this->replace_by_month(
				'object_daily',
				$range,
				$available,
				"SELECT day, object_type, object_id, MIN(uri), COUNT(*), COUNT(DISTINCT visitor), 'own' FROM {$hits}
				WHERE day BETWEEN %s AND %s GROUP BY day, object_type, object_id",
				'day, object_type, object_id, uri, views, visitors, origin',
				$delete
			);

			// Ingressi per canale: solo le visite con is_entry = 1.
			$rows['own:entry_daily'] = $this->replace_by_month(
				'entry_daily',
				$range,
				$available,
				"SELECT day, object_type, object_id, IF( {$ai} <> '', 'ai', COALESCE(NULLIF(channel, ''), 'unassigned') ) AS entry_channel, COUNT(DISTINCT visitor), 'own' FROM {$hits}
				WHERE is_entry = 1 AND day BETWEEN %s AND %s GROUP BY day, object_type, object_id, entry_channel",
				'day, object_type, object_id, channel, visitors, origin',
				$delete
			);

			// Siti di provenienza: ingressi con un referrer esterno.
			$rows['own:referrer_daily'] = $this->replace_by_month(
				'referrer_daily',
				$range,
				$available,
				"SELECT day, object_type, object_id, referrer_host, MAX(IF( {$ai} <> '', 'ai', channel )), MAX(IF( {$ai} <> '', {$ai}, source )), COUNT(DISTINCT visitor), 'own' FROM {$hits}
				WHERE is_entry = 1 AND referrer_host <> '' AND day BETWEEN %s AND %s GROUP BY day, object_type, object_id, referrer_host",
				'day, object_type, object_id, referrer, channel, source, visitors, origin',
				$delete
			);

			// Dimensioni (canale, sorgente, paese, dispositivo, browser e sistema): un visitatore per giorno, con la prima visita del giorno,
			// così la somma dei visitatori è uguale per tutte le dimensioni. Il sistema operativo si chiama "platform" (come in WP Statistics).
			// Le dimensioni utm_campaign, utm_source e utm_medium sono fuori da questa regola: contano i visitatori distinti del giorno che hanno
			// quel valore (uno può averne zero o più di uno) e le visite senza UTM non generano righe.
			$first                 = "SELECT MIN(id) AS id FROM {$hits} WHERE day BETWEEN %s AND %s GROUP BY day, visitor";
			$rows['own:dim_daily'] = $this->replace_by_month(
				'dim_daily',
				$range,
				$available,
				"SELECT h.day, 'channel', IF( {$ai_hit} <> '', 'ai', h.channel ), COUNT(*), 'own' FROM {$hits} h JOIN ( {$first} ) f ON f.id = h.id GROUP BY h.day, 3
				UNION ALL
				SELECT h.day, 'source', COALESCE(NULLIF(IF( {$ai_hit} <> '', {$ai_hit}, h.source ), ''), '(n/d)'), COUNT(*), 'own' FROM {$hits} h JOIN ( {$first} ) f ON f.id = h.id GROUP BY h.day, 3
				UNION ALL
				SELECT h.day, 'country', COALESCE(NULLIF(h.country, ''), '(n/d)'), COUNT(*), 'own' FROM {$hits} h JOIN ( {$first} ) f ON f.id = h.id GROUP BY h.day, 3
				UNION ALL
				SELECT h.day, 'device', COALESCE(NULLIF(h.device, ''), '(n/d)'), COUNT(*), 'own' FROM {$hits} h JOIN ( {$first} ) f ON f.id = h.id GROUP BY h.day, 3
				UNION ALL
				SELECT h.day, 'browser', COALESCE(NULLIF(h.browser, ''), '(n/d)'), COUNT(*), 'own' FROM {$hits} h JOIN ( {$first} ) f ON f.id = h.id GROUP BY h.day, 3
				UNION ALL
				SELECT h.day, 'platform', COALESCE(NULLIF(h.os, ''), '(n/d)'), COUNT(*), 'own' FROM {$hits} h JOIN ( {$first} ) f ON f.id = h.id GROUP BY h.day, 3
				UNION ALL
				SELECT day, 'utm_campaign', LEFT(utm_campaign, 100), COUNT(DISTINCT visitor), 'own' FROM {$hits} WHERE utm_campaign <> '' AND day BETWEEN %s AND %s GROUP BY day, 3
				UNION ALL
				SELECT day, 'utm_source', LEFT(utm_source, 100), COUNT(DISTINCT visitor), 'own' FROM {$hits} WHERE utm_source <> '' AND day BETWEEN %s AND %s GROUP BY day, 3
				UNION ALL
				SELECT day, 'utm_medium', LEFT(utm_medium, 100), COUNT(DISTINCT visitor), 'own' FROM {$hits} WHERE utm_medium <> '' AND day BETWEEN %s AND %s GROUP BY day, 3",
				'day, dim, value, visitors, origin',
				$delete
			);

			return $rows;
		} finally {
			// Passo finale: il lock si rilascia sempre.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::LOCK ) );
		}
	}

	/**
	 * Passaggio orario (cron): rielabora ieri e oggi. Non tocca wp5w_etl_state, così «Ultimo import»
	 * nella scheda Stato resta quello dell'ETL notturno. Gli errori vanno in error_log.
	 *
	 * @return void
	 */
	public function run_hourly(): void {
		try {
			// Chiave del giorno scaduta: con poche visite non ruota da sola, quindi si cancella qui (nessuna chiave di ieri resta salvata).
			$salt = get_option( 'wp5w_daily_salt', false );
			if ( false !== $salt && ( ! is_string( $salt ) || ! str_starts_with( $salt, wp_date( 'Y-m-d' ) . '|' ) ) ) {
				delete_option( 'wp5w_daily_salt' );
			}

			if ( ! $this->detect()['ok'] ) {
				return;
			}
			$this->import( DateRange::last_days( self::HOURLY_DAYS, wp_date( 'Y-m-d' ) ) );
		} catch ( \Throwable $e ) {
			error_log( 'wp5w: aggregazione oraria fallita: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Il passaggio orario non ha altra interfaccia per gli errori.
		}
	}
}
