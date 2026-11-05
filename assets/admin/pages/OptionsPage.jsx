import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, RadioControl, Spinner, TextControl, TextareaControl, ToggleControl } from '@wordpress/components';
import { api, formatDay, today } from '../api';
import Card from '../components/Card';
import GscCard from '../components/GscCard';
import HitsPurge from '../components/HitsPurge';

/**
 * Messaggio leggibile di un errore REST: per i parametri non validi (400) è quello della validazione
 * (es. l'elenco degli IP errati), che WordPress mette in data.params e non nel messaggio generico.
 *
 * @param {Object} error Errore di apiFetch.
 */
const errorMessage = ( error ) => {
	const params = error?.data?.params;
	if ( params && Object.keys( params ).length ) {
		return Object.values( params ).join( ' ' );
	}
	return error?.message ?? __( 'Errore sconosciuto.', 'wp-5w' );
};

// Soglie degli avvisi sulle visite anomale: chiave, etichetta, spiegazione e limiti (gli stessi di Settings::ALERT_LIMITS).
const ALERT_FIELDS = [
	{ key: 'alert_hours', min: 1, max: 168, label: __( 'Finestra (ore)', 'wp-5w' ), help: __( 'Le ultime ore controllate a ogni calcolo.', 'wp-5w' ) },
	{ key: 'alert_min_visitors', min: 1, max: 10000, label: __( 'Visitatori minimi', 'wp-5w' ), help: __( 'Sotto questo numero una coppia sito-contenuto non è mai un picco.', 'wp-5w' ) },
	{ key: 'alert_factor', min: 1, max: 100, label: __( 'Volte la mediana', 'wp-5w' ), help: __( 'Il picco deve valere almeno tante volte la mediana dei visitatori al giorno (con mediana minima 1).', 'wp-5w' ) },
	{ key: 'alert_baseline_days', min: 7, max: 90, label: __( 'Giorni della mediana', 'wp-5w' ), help: __( 'Giorni interi prima della finestra su cui si calcola la mediana.', 'wp-5w' ) },
	{ key: 'alert_countries', min: 2, max: 250, label: __( 'Paesi diversi', 'wp-5w' ), help: __( 'Firma anomala: visitatori da almeno tanti paesi.', 'wp-5w' ) },
	{ key: 'alert_share', min: 50, max: 100, label: __( 'Stesso browser e sistema (%)', 'wp-5w' ), help: __( 'Firma anomala: quota dei visitatori con la stessa combinazione di browser e sistema operativo.', 'wp-5w' ) },
	{ key: 'alert_ip_visits', min: 2, max: 10000, label: __( 'Visite da un solo IP', 'wp-5w' ), help: __( 'Firma anomala, e avviso a sé: visite dello stesso IP allo stesso contenuto nella finestra.', 'wp-5w' ) },
];

/**
 * Dalla risposta del server ai valori dei campi (gli IP diventano un testo, una regola per riga).
 *
 * @param {Object} data Risposta di /admin/settings.
 */
const toFields = ( data ) => ( {
	...Object.fromEntries( ALERT_FIELDS.map( ( { key } ) => [ key, String( data[ key ] ) ] ) ),
	own_tracking: !! data.own_tracking,
	exclude_users: data.exclude_users,
	excluded_ips: ( data.excluded_ips ?? [] ).join( '\n' ),
	respect_dnt: !! data.respect_dnt,
	retention_days: String( data.retention_days ),
	ip_retention_days: String( data.ip_retention_days ),
	own_since: data.own_since ?? '',
	log_glob: data.log_glob ?? '',
	log_excluded_paths: ( data.log_excluded_paths ?? [] ).join( '\n' ),
} );

export default function OptionsPage() {
	const [ fields, setFields ] = useState( null );
	// Giorni di conservazione salvati: la pulizia manuale usa questi, non quelli nel campo.
	const [ savedRetention, setSavedRetention ] = useState( null );
	const [ firstDay, setFirstDay ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	// Carica le opzioni salvate.
	const load = useCallback( () => {
		api( '/admin/settings' )
			.then( ( data ) => {
				setFields( toFields( data ) );
				setSavedRetention( String( data.retention_days ) );
				setFirstDay( data.own_first_day );
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: errorMessage( e ) } ) );
	}, [] );

	useEffect( load, [ load ] );

	// Aggiorna un solo campo.
	const set = ( key ) => ( value ) => setFields( ( current ) => ( { ...current, [ key ]: value } ) );

	// Salva tutti i campi; un errore 400 mostra il messaggio della validazione e non cambia nulla.
	const save = () => {
		setSaving( true );
		setNotice( null );
		api( '/admin/settings', {
			method: 'POST',
			data: {
				own_tracking: fields.own_tracking,
				exclude_users: fields.exclude_users,
				excluded_ips: fields.excluded_ips
					.split( '\n' )
					.map( ( line ) => line.trim() )
					.filter( Boolean ),
				respect_dnt: fields.respect_dnt,
				retention_days: parseInt( fields.retention_days, 10 ) || 0,
				ip_retention_days: parseInt( fields.ip_retention_days, 10 ) || 0,
				...Object.fromEntries( ALERT_FIELDS.map( ( { key } ) => [ key, parseInt( fields[ key ], 10 ) || 0 ] ) ),
				own_since: fields.own_since,
				log_glob: fields.log_glob.trim(),
				log_excluded_paths: fields.log_excluded_paths
					.split( '\n' )
					.map( ( line ) => line.trim() )
					.filter( Boolean ),
			},
		} )
			.then( ( data ) => {
				setFields( toFields( data ) );
				setSavedRetention( String( data.retention_days ) );
				setFirstDay( data.own_first_day );
				setNotice( { status: 'success', text: __( 'Opzioni salvate.', 'wp-5w' ) } );
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: errorMessage( e ) } ) )
			.finally( () => setSaving( false ) );
	};

	if ( ! fields ) {
		return notice ? <Notice status="error" isDismissible={ false }>{ notice.text }</Notice> : <Spinner />;
	}

	return (
		<div className="wp5w-options">
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.text }
				</Notice>
			) }

			<Card id="options-tracking" title={ __( 'Raccolta propria', 'wp-5w' ) }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Registra le visite con il tracker di wp-5w', 'wp-5w' ) }
					help={ __(
						'Nessun cookie e nessun IP in chiaro: un visitatore si riconosce da un hash che cambia ogni giorno. Per l\'analisi delle visite anomale si salvano anche un hash dell\'IP (con una chiave segreta del sito: è lo stesso per lo stesso IP, quindi collega le visite di giorni diversi finché resta) e lo user agent, cancellati dopo i giorni indicati più sotto.',
						'wp-5w'
					) }
					checked={ fields.own_tracking }
					onChange={ set( 'own_tracking' ) }
				/>
			</Card>

			<Card id="options-users" title={ __( 'Utenti collegati', 'wp-5w' ) }>
				<RadioControl
					selected={ fields.exclude_users }
					options={ [
						{ label: __( 'Escludi gli amministratori', 'wp-5w' ), value: 'admins' },
						{ label: __( 'Escludi tutti gli utenti collegati', 'wp-5w' ), value: 'logged_in' },
						{ label: __( 'Non escludere nessuno', 'wp-5w' ), value: 'none' },
					] }
					onChange={ set( 'exclude_users' ) }
				/>
			</Card>

			<Card id="options-ips" title={ __( 'IP esclusi', 'wp-5w' ) }>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Indirizzi IP o intervalli', 'wp-5w' ) }
					help={ __(
						'Una regola per riga: un indirizzo (IPv4 o IPv6) oppure un intervallo CIDR, per esempio 192.168.1.0/24.',
						'wp-5w'
					) }
					rows={ 4 }
					value={ fields.excluded_ips }
					onChange={ set( 'excluded_ips' ) }
				/>
			</Card>

			<Card id="options-logs" title={ __( 'Log di accesso del server', 'wp-5w' ) }>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Percorso dei file di log', 'wp-5w' ) }
					help={ __(
						'Facoltativo. Percorso assoluto con * e ? (per esempio /home/utente/domains/sito.com/logs/*.sito.tar.gz*): i log contano anche i crawler che non eseguono JavaScript e compaiono nella scheda Stato, accanto agli altri strumenti. Vuoto = spento. Nessun IP né user agent viene salvato.',
						'wp-5w'
					) }
					value={ fields.log_glob }
					onChange={ set( 'log_glob' ) }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Percorsi da ignorare nei log', 'wp-5w' ) }
					help={ __(
						'Un prefisso per riga, per esempio /dashboard/ se sotto lo stesso dominio gira un\'altra applicazione.',
						'wp-5w'
					) }
					rows={ 3 }
					value={ fields.log_excluded_paths }
					onChange={ set( 'log_excluded_paths' ) }
				/>
				</Card>

				<Card id="options-dnt" title={ __( 'Rispetta Do Not Track', 'wp-5w' ) }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Non registrare chi ha attivato Do Not Track nel browser', 'wp-5w' ) }
					help={ __( 'Queste visite non vengono registrate, ma si contano come scarti.', 'wp-5w' ) }
					checked={ fields.respect_dnt }
					onChange={ set( 'respect_dnt' ) }
				/>
			</Card>

			<Card id="options-retention" title={ __( 'Conservazione delle visite grezze', 'wp-5w' ) }>
				<TextControl
					__nextHasNoMarginBottom
					type="number"
					min={ 7 }
					max={ 365 }
					label={ __( 'Giorni (da 7 a 365)', 'wp-5w' ) }
					help={ __(
						'Gli aggregati restano per sempre. Le visite grezze non si cancellano da sole: si cancellano quelle più vecchie di questi giorni solo con il pulsante qui sotto, dopo l\'anteprima.',
						'wp-5w'
					) }
					value={ fields.retention_days }
					onChange={ set( 'retention_days' ) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					type="number"
					min={ 1 }
					max={ fields.retention_days }
					label={ __( 'Hash dell\'IP e user agent: giorni (da 1 ai giorni qui sopra)', 'wp-5w' ) }
					help={ __(
						'Servono al dettaglio delle visite e agli avvisi sulle visite anomale. Ogni ora si cancellano dalle visite più vecchie (la visita resta, senza hash dell\'IP né user agent). L\'IP in chiaro non viene mai salvato; se cambiano le salt di WordPress lo stesso IP dà un hash diverso.',
						'wp-5w'
					) }
					value={ fields.ip_retention_days }
					onChange={ set( 'ip_retention_days' ) }
				/>
				<HitsPurge unsaved={ fields.retention_days !== savedRetention } />
			</Card>

			<Card id="options-alerts" title={ __( 'Avvisi sulle visite anomale', 'wp-5w' ) }>
				<p className="wp5w-note wp5w-note--top">
					{ __(
						'Ogni ora, per ogni coppia sito di provenienza e contenuto (l\'accesso diretto conta come un sito): avviso se nella finestra c\'è un picco (visitatori minimi e volte la mediana) e almeno un segnale di firma anomala (paesi, stesso browser e sistema, visite da un solo IP). Un picco senza firma, come un articolo condiviso sui social, compare solo come nota. I valori predefiniti sono calibrati sulle visite del blog di ottobre e novembre 2026: scattano per lo spam da aocr.org e per il bot sulla home, non per LinkedIn e Facebook. Al salvataggio gli avvisi si ricalcolano.',
						'wp-5w'
					) }
				</p>
				<div className="wp5w-columns">
					{ ALERT_FIELDS.map( ( field ) => (
						<TextControl
							key={ field.key }
							__nextHasNoMarginBottom
							type="number"
							min={ field.min }
							max={ field.max }
							label={ `${ field.label } (${ field.min }-${ field.max })` }
							help={ field.help }
							value={ fields[ field.key ] }
							onChange={ set( field.key ) }
						/>
					) ) }
				</div>
			</Card>

			<Card id="options-origin" title={ __( 'Origine dei dati', 'wp-5w' ) }>
				<TextControl
					__nextHasNoMarginBottom
					type="date"
					label={ __( 'Usa la raccolta propria dal giorno', 'wp-5w' ) }
					help={
						firstDay
							? `${ __( 'Finché è vuota, tutte le schede usano WP Statistics. Dati della raccolta propria dal', 'wp-5w' ) } ${ formatDay( firstDay ) }.`
							: __(
									'Finché è vuota, tutte le schede usano WP Statistics. Non ci sono ancora dati della raccolta propria.',
									'wp-5w'
							  )
					}
					min={ firstDay ?? undefined }
					max={ today }
					disabled={ ! firstDay && ! fields.own_since }
					value={ fields.own_since }
					onChange={ set( 'own_since' ) }
				/>
			</Card>

			<div className="wp5w-actions">
				<Button variant="primary" isBusy={ saving } disabled={ saving } onClick={ save }>
					{ __( 'Salva', 'wp-5w' ) }
				</Button>
			</div>

			{ /* Collegamento a Google (l'import sta nella scheda Aggiornamenti): indipendente dal pulsante «Salva» delle opzioni. */ }
			<GscCard />
		</div>
	);
}
