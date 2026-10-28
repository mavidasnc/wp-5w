<?php
/**
 * Sorgente dei log di accesso del server (origini `log` e `logbot`).
 *
 * @package Mavida\FiveW
 */

declare(strict_types=1);

namespace Mavida\FiveW\Sources;

use Mavida\FiveW\Database\Schema;
use Mavida\FiveW\Settings;
use Mavida\FiveW\Support\AccessLog;
use Mavida\FiveW\Support\BotFilter;
use Mavida\FiveW\Support\Channels;
use Mavida\FiveW\Support\DateRange;
use Mavida\FiveW\Support\LogCoverage;
use Mavida\FiveW\Support\TarGz;
use Mavida\FiveW\Support\Visit;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Tabelle del plugin, nomi costruiti con $wpdb->prefix in Schema::table().

/**
 * Conta le pagine servite dal server leggendo gli archivi dei log di accesso: l'unico modo di vedere anche i crawler che non
 * eseguono JavaScript (con la cache di LiteSpeed la richiesta non passa da PHP).
 *
 * Due origini in `site_daily` e `dim_daily`:
 * - `log`: i client che hanno dato prova di essere browser (POST a un tracker o risorse del sito scaricate), senza amministratori;
 * - `logbot`: i bot dichiarati nello user agent (BotFilter::label()) e i client senza nessuna prova (etichetta `senza-js`).
 * Per `log` scrive anche `object_daily` (visualizzazioni per contenuto) e `entry_daily` (ingressi per contenuto e canale), solo per
 * la home e i contenuti singoli: tipo e ID si ricavano dal percorso (object_for()), il resto si salta.
 *
 * `Origin::where()` non le seleziona mai: le legge solo CompareQuery. IP e user agent non escono dalla memoria: i visitatori
 * si contano con un hash calcolato al volo (IP e user agent, per giorno del sito) e non si salva nulla che li identifichi.
 * Si scrivono solo i giorni interamente coperti dagli archivi (LogCoverage) e mai si cancella ciò che i log non coprono più.
 */
final class AccessLogSource extends Source {

	/**
	 * Origine dei browser.
	 *
	 * @var string
	 */
	public const ORIGIN = 'log';

	/**
	 * Origine dei bot.
	 *
	 * @var string
	 */
	public const ORIGIN_BOT = 'logbot';

	/**
	 * Etichetta dei client senza nessuna prova di essere un browser (non caricano risorse né eseguono i tracker).
	 *
	 * @var string
	 */
	public const LABEL_NO_JS = 'senza-js';

	/**
	 * Nome del file (archivio compresso, rotazione numerata o log in chiaro) che si accetta.
	 *
	 * @var string
	 */
	private const FILE_PATTERN = '~\.(log|gz)(\.\d+)?$~i';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'log';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Log di accesso del server', 'wp-5w' );
	}

	/**
	 * La sorgente è attiva quando le Opzioni indicano un percorso dei log.
	 *
	 * {@inheritDoc}
	 */
	public function version(): ?string {
		$glob = Settings::get()['log_glob'];
		if ( '' === $glob ) {
			return null;
		}

		/* translators: %d: numero di file di log trovati. */
		return sprintf( __( '%d file', 'wp-5w' ), count( self::files( $glob ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function required(): array {
		return array();
	}

	/**
	 * File di log che corrispondono al percorso (con `*` e `?`), esclusi i log degli errori e quelli illeggibili.
	 *
	 * @param string $glob Percorso con i caratteri jolly.
	 * @return array<int,string>
	 */
	public static function files( string $glob ): array {
		if ( '' === $glob ) {
			return array();
		}

		$found = array();
		foreach ( (array) glob( $glob ) as $file ) {
			$name = basename( (string) $file );
			if ( is_file( $file ) && is_readable( $file ) && 1 === preg_match( self::FILE_PATTERN, $name ) && ! str_contains( $name, 'error' ) ) {
				$found[] = (string) $file;
			}
		}
		return $found;
	}

	/**
	 * Controlla il percorso delle Opzioni: vuoto oppure assoluto con almeno un file di log leggibile.
	 *
	 * @param string $glob Percorso proposto.
	 * @return true|string True se ammesso, altrimenti il messaggio d'errore.
	 */
	public static function check_glob( string $glob ): bool|string {
		if ( '' === $glob ) {
			return true;
		}
		if ( 1 !== preg_match( '~^(/|[A-Za-z]:[\\\\/])~', $glob ) ) {
			return __( 'Il percorso dei log deve essere assoluto (inizia con «/»).', 'wp-5w' );
		}
		if ( array() === self::files( $glob ) ) {
			return __( 'Nessun file di log leggibile corrisponde a questo percorso (si accettano .log e .tar.gz, anche con il numero della rotazione).', 'wp-5w' );
		}
		return true;
	}

	/**
	 * Legge gli archivi che toccano l'intervallo e riscrive i giorni completi.
	 *
	 * @param DateRange $range Intervallo richiesto.
	 * @return array<string,int> Righe scritte per tabella (chiavi con prefisso «log:»).
	 * @throws \RuntimeException Se una scrittura fallisce.
	 */
	public function import( DateRange $range ): array {
		$settings = Settings::get();
		$files    = self::files( $settings['log_glob'] );
		if ( array() === $files ) {
			return array();
		}

		$timezone  = wp_timezone();
		$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$content   = (string) wp_parse_url( content_url(), PHP_URL_PATH );
		$core      = rtrim( (string) wp_parse_url( site_url(), PHP_URL_PATH ), '/' );

		// Passo 1: archivi che possono toccare l'intervallo. Il file ha la data della rotazione (fine del periodo che copre):
		// si tengono quelli degli ultimi due giorni prima di `from`, perché il primo giorno si completa con l'archivio precedente.
		$cutoff = ( new \DateTimeImmutable( $range->from . ' 00:00:00', $timezone ) )->getTimestamp() - 2 * DAY_IN_SECONDS;

		// Passo 2: lettura in streaming. Per ogni visitatore e giorno si tengono pochi contatori, mai IP e user agent.
		$visitors  = array();
		$js        = array();
		$intervals = array();
		$day_cache = array();
		$home_path = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		foreach ( $files as $file ) {
			if ( (int) filemtime( $file ) < $cutoff ) {
				continue;
			}

			$first = null;
			$last  = null;
			foreach ( TarGz::lines( $file ) as $line ) {
				$request = AccessLog::parse( $line );
				if ( null === $request ) {
					continue;
				}
				$first = min( $first ?? $request['ts'], $request['ts'] );
				$last  = max( $last ?? $request['ts'], $request['ts'] );

				$kind = AccessLog::kind( $request, $content, $core, $settings['log_excluded_paths'] );
				if ( 'ignore' === $kind || AccessLog::is_internal( $request['ip'], $request['ua'], $settings['excluded_ips'] ) ) {
					continue;
				}

				// Hash del visitatore (IP e user agent): vive solo in memoria, per la durata dell'import.
				$hash = Visit::visitor_hash( $request['ip'], $request['ua'], '' );
				if ( 'evidence' === $kind ) {
					$js[ $hash ] = true;
					continue;
				}

				// Giorno del sito: si calcola una volta ogni 15 minuti (i fusi orari sono multipli di 15 minuti).
				$bucket = intdiv( $request['ts'], 900 );
				if ( ! isset( $day_cache[ $bucket ] ) ) {
					$day_cache[ $bucket ] = ( new \DateTimeImmutable( '@' . ( $bucket * 900 ) ) )->setTimezone( $timezone )->format( 'Y-m-d' );
				}
				$key = $day_cache[ $bucket ] . "\0" . $hash;

				$visitor = $visitors[ $key ] ?? array(
					'views'    => 0,
					'path'     => '',
					'ts'       => 0,
					'referrer' => '',
					'source'   => '',
					'medium'   => '',
					'admin'    => false,
					'bot'      => BotFilter::label( $request['ua'] ),
					'entry'    => '',
					'entry_ts' => 0,
					'pages'    => array(),
				);

				if ( 'admin' === $kind ) {
					$visitor['admin'] = true;
				} elseif ( $visitor['path'] !== $request['path'] || abs( $request['ts'] - $visitor['ts'] ) > AccessLog::RELOAD_SECONDS ) {
					// La stessa pagina richiesta di nuovo entro pochi secondi (guest mode di LiteSpeed) conta una volta sola.
					// Ingresso, referrer e UTM vengono dalla pagina più vecchia del giorno: gli archivi non si leggono in ordine di tempo
					// (glob() elenca prima il più recente), quindi la prima pagina letta non è sempre la prima vista.
					// Le ricerche (/?s=...) hanno il percorso della home ma non sono la home: niente ingresso né contenuto.
					$is_search = 1 === preg_match( '/[?&]s=/', $request['uri'] );
					if ( 0 === $visitor['views'] || $request['ts'] < $visitor['entry_ts'] ) {
						$visitor['entry']    = $is_search ? '' : $request['path'];
						$visitor['entry_ts'] = $request['ts'];
						$visitor['referrer'] = Channels::host( $request['referrer'], $site_host );
						$visitor['source']   = '';
						$visitor['medium']   = '';
						if ( str_contains( $request['uri'], 'utm_' ) ) {
							parse_str( (string) wp_parse_url( $request['uri'], PHP_URL_QUERY ), $query );
							$visitor['source'] = is_string( $query['utm_source'] ?? null ) ? $query['utm_source'] : '';
							$visitor['medium'] = is_string( $query['utm_medium'] ?? null ) ? $query['utm_medium'] : '';
						}
					}
					++$visitor['views'];
					$visitor['path'] = $request['path'];
					$visitor['ts']   = $request['ts'];
					// Pagine viste per percorso, solo per chi può finire in `log` (un bot dichiarato non ci finisce mai).
					if ( '' === $visitor['bot'] && ! $is_search ) {
						$visitor['pages'][ $request['path'] ] = ( $visitor['pages'][ $request['path'] ] ?? 0 ) + 1;
					}
				}
				$visitors[ $key ] = $visitor;
			}

			if ( null !== $first && null !== $last ) {
				$intervals[] = array( $first, $last );
			}
		}

		// Passo 3: giorni scrivibili = coperti per intero dai log e dentro l'intervallo richiesto.
		$complete = array_filter(
			LogCoverage::complete_days( $intervals, $timezone ),
			static fn( string $day ): bool => $day >= $range->from && $day <= $range->to
		);
		if ( array() === $complete ) {
			return array();
		}
		$complete = array_flip( $complete );

		// Passo 4: classificazione di ogni visitatore del giorno e totali per origine, canale ed etichetta del bot.
		$totals  = array();
		$objects = array();
		foreach ( $visitors as $key => $visitor ) {
			[ $day, $hash ] = explode( "\0", $key, 2 );
			if ( ! isset( $complete[ $day ] ) || $visitor['admin'] || 0 === $visitor['views'] ) {
				continue;
			}

			// Bot dichiarato → logbot; browser provato → log; nessuna prova → logbot «senza-js».
			$label = $visitor['bot'];
			if ( '' === $label && ! isset( $js[ $hash ] ) ) {
				$label = self::LABEL_NO_JS;
			}
			$origin  = '' === $label ? self::ORIGIN : self::ORIGIN_BOT;
			$channel = Channels::ai( $visitor['referrer'], $visitor['source'] );
			$channel = '' !== $channel ? 'ai' : Channels::classify( $visitor['referrer'], $visitor['source'], $visitor['medium'] )['channel'];

			$totals[ $day ][ $origin ]['visitors']            = ( $totals[ $day ][ $origin ]['visitors'] ?? 0 ) + 1;
			$totals[ $day ][ $origin ]['views']               = ( $totals[ $day ][ $origin ]['views'] ?? 0 ) + $visitor['views'];
			$totals[ $day ][ $origin ]['channel'][ $channel ] = ( $totals[ $day ][ $origin ]['channel'][ $channel ] ?? 0 ) + 1;
			if ( '' !== $label ) {
				$totals[ $day ][ $origin ]['bot'][ $label ] = ( $totals[ $day ][ $origin ]['bot'][ $label ] ?? 0 ) + 1;
				continue;
			}

			// Solo `log`: ingresso con il canale e visualizzazioni per contenuto (un visitatore conta una volta per contenuto).
			$entry = self::object_for( $visitor['entry'], $home_path, $objects );
			if ( null !== $entry ) {
				$entry_key                                        = $entry['type'] . '|' . $entry['id'] . '|' . $channel;
				$totals[ $day ][ $origin ]['entry'][ $entry_key ] = ( $totals[ $day ][ $origin ]['entry'][ $entry_key ] ?? 0 ) + 1;
			}
			$seen = array();
			foreach ( $visitor['pages'] as $path => $views ) {
				$object = self::object_for( (string) $path, $home_path, $objects );
				if ( null === $object ) {
					continue;
				}
				$object_key = $object['type'] . '|' . $object['id'];
				$item       = $totals[ $day ][ $origin ]['object'][ $object_key ] ?? $object + array(
					'uri'      => substr( (string) $path, 0, 190 ),
					'views'    => 0,
					'visitors' => 0,
				);

				$item['views']      += $views;
				$item['visitors']   += isset( $seen[ $object_key ] ) ? 0 : 1;
				$seen[ $object_key ] = true;

				$totals[ $day ][ $origin ]['object'][ $object_key ] = $item;
			}
		}

		// Passo 5: scrittura giorno per giorno (anche i giorni senza visite si svuotano: i log dicono che erano vuoti).
		$rows = array(
			'log:site_daily'   => 0,
			'log:dim_daily'    => 0,
			'log:object_daily' => 0,
			'log:entry_daily'  => 0,
		);
		foreach ( array_keys( $complete ) as $day ) {
			$written                   = $this->replace_day( (string) $day, $totals[ $day ] ?? array() );
			$rows['log:site_daily']   += $written['site'];
			$rows['log:dim_daily']    += $written['dim'];
			$rows['log:object_daily'] += $written['object'];
			$rows['log:entry_daily']  += $written['entry'];
		}

		return $rows;
	}

	/**
	 * Tipo e ID del contenuto di un percorso, come Tracker::current_object(): la home (la pagina statica, la pagina degli articoli
	 * o «Gli ultimi articoli») e i contenuti singoli. Null per tutto il resto (archivi, ricerche, pagine successive, 404), che si salta.
	 *
	 * @param string                                       $path      Percorso della richiesta, senza query; vuoto per un ingresso da una ricerca.
	 * @param string                                       $home_path Percorso della home senza barra finale (vuoto nella radice).
	 * @param array<string,array{type:string,id:int}|null> $cache     Risultati già calcolati per percorso (url_to_postid() fa query).
	 * @return array{type:string,id:int}|null
	 */
	private static function object_for( string $path, string $home_path, array &$cache ): ?array {
		// Un percorso vero inizia sempre con «/»: vuoto è una ricerca (nella radice coinciderebbe con la home).
		if ( '' === $path ) {
			return null;
		}
		if ( array_key_exists( $path, $cache ) ) {
			return $cache[ $path ];
		}

		// Passo 1: la home (ID come get_queried_object_id(): la pagina statica oppure 0 con «Gli ultimi articoli»).
		$static = 'page' === get_option( 'show_on_front' );
		$object = null;
		if ( rtrim( $path, '/' ) === $home_path ) {
			$object = array(
				'type' => 'home',
				'id'   => $static ? (int) get_option( 'page_on_front' ) : 0,
			);
		} else {
			// Passo 2: contenuti singoli; la pagina della home e quella degli articoli sono la `home`, come nel Tracker.
			$id = url_to_postid( home_url( $path ) );
			if ( $id > 0 ) {
				if ( $static && in_array( $id, array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ), true ) ) {
					$type = 'home';
				} else {
					$post_type = (string) get_post_type( $id );
					$type      = in_array( $post_type, array( 'post', 'page' ), true ) ? $post_type : 'post_type_' . $post_type;
					$type      = substr( (string) preg_replace( '/[^a-z0-9_]/', '_', strtolower( $type ) ), 0, 40 );
				}
				$object = array(
					'type' => $type,
					'id'   => $id,
				);
			}
		}

		$cache[ $path ] = $object;
		return $object;
	}

	/**
	 * Sostituisce le righe `log` e `logbot` di un giorno in una transazione.
	 *
	 * @param string                            $day    Giorno (Y-m-d).
	 * @param array<string,array<string,mixed>> $totals Totali per origine (visitors, views, channel, bot; per `log` anche object ed entry).
	 * @return array{site:int,dim:int,object:int,entry:int} Righe scritte.
	 * @throws \RuntimeException Se una query fallisce.
	 */
	private function replace_day( string $day, array $totals ): array {
		global $wpdb;

		$site   = array();
		$dim    = array();
		$object = array();
		$entry  = array();
		foreach ( $totals as $origin => $item ) {
			$site[] = $wpdb->prepare( '(%s, %d, %d, %s)', $day, $item['visitors'], $item['views'], $origin );
			foreach ( $item['channel'] as $channel => $visitors ) {
				$dim[] = $wpdb->prepare( "(%s, 'channel', %s, %d, %s)", $day, $channel, $visitors, $origin );
			}
			foreach ( $item['bot'] ?? array() as $label => $visitors ) {
				$dim[] = $wpdb->prepare( "(%s, 'bot', %s, %d, %s)", $day, $label, $visitors, $origin );
			}
			foreach ( $item['object'] ?? array() as $row ) {
				$object[] = $wpdb->prepare( '(%s, %s, %d, %s, %d, %d, %s)', $day, $row['type'], $row['id'], $row['uri'], $row['views'], $row['visitors'], $origin );
			}
			foreach ( $item['entry'] ?? array() as $key => $visitors ) {
				[ $type, $id, $channel ] = explode( '|', (string) $key, 3 );
				$entry[]                 = $wpdb->prepare( '(%s, %s, %d, %s, %d, %s)', $day, $type, (int) $id, $channel, $visitors, $origin );
			}
		}

		$site_table   = Schema::table( 'site_daily' );
		$dim_table    = Schema::table( 'dim_daily' );
		$object_table = Schema::table( 'object_daily' );
		$entry_table  = Schema::table( 'entry_daily' );
		$origins      = "'" . self::ORIGIN . "', '" . self::ORIGIN_BOT . "'";

		$wpdb->query( 'START TRANSACTION' );
		$ok = false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$site_table} WHERE day = %s AND origin IN ( {$origins} )", $day ) )
			&& false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$dim_table} WHERE day = %s AND origin IN ( {$origins} )", $day ) )
			&& false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$object_table} WHERE day = %s AND origin IN ( {$origins} )", $day ) )
			&& false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$entry_table} WHERE day = %s AND origin IN ( {$origins} )", $day ) );
		if ( $ok && $site ) {
			$ok = false !== $wpdb->query( "INSERT INTO {$site_table} (day, visitors, views, origin) VALUES " . implode( ',', $site ) );
		}
		if ( $ok && $dim ) {
			$ok = false !== $wpdb->query( "INSERT INTO {$dim_table} (day, dim, value, visitors, origin) VALUES " . implode( ',', $dim ) );
		}
		if ( $ok && $object ) {
			$ok = false !== $wpdb->query( "INSERT INTO {$object_table} (day, object_type, object_id, uri, views, visitors, origin) VALUES " . implode( ',', $object ) );
		}
		if ( $ok && $entry ) {
			$ok = false !== $wpdb->query( "INSERT INTO {$entry_table} (day, object_type, object_id, channel, visitors, origin) VALUES " . implode( ',', $entry ) );
		}

		if ( ! $ok ) {
			$wpdb->query( 'ROLLBACK' );
			throw new \RuntimeException( "{$day}: {$wpdb->last_error}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messaggio per stato e CLI.
		}
		$wpdb->query( 'COMMIT' );

		return array(
			'site'   => count( $site ),
			'dim'    => count( $dim ),
			'object' => count( $object ),
			'entry'  => count( $entry ),
		);
	}
}
