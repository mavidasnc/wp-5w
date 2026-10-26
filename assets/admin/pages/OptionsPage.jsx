import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, RadioControl, Spinner, TextControl, TextareaControl, ToggleControl } from '@wordpress/components';
import { api, formatDay, today } from '../api';
import Card from '../components/Card';
import GscCard from '../components/GscCard';

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

/**
 * Dalla risposta del server ai valori dei campi (gli IP diventano un testo, una regola per riga).
 *
 * @param {Object} data Risposta di /admin/settings.
 */
const toFields = ( data ) => ( {
	own_tracking: !! data.own_tracking,
	exclude_users: data.exclude_users,
	excluded_ips: ( data.excluded_ips ?? [] ).join( '\n' ),
	respect_dnt: !! data.respect_dnt,
	retention_days: String( data.retention_days ),
	own_since: data.own_since ?? '',
	log_glob: data.log_glob ?? '',
	log_excluded_paths: ( data.log_excluded_paths ?? [] ).join( '\n' ),
} );

export default function OptionsPage() {
	const [ fields, setFields ] = useState( null );
	const [ firstDay, setFirstDay ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	// Carica le opzioni salvate.
	const load = useCallback( () => {
		api( '/admin/settings' )
			.then( ( data ) => {
				setFields( toFields( data ) );
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
						'Nessun cookie e nessun IP salvato: un visitatore si riconosce da un hash che cambia ogni giorno.',
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
						'Gli aggregati restano per sempre. La pulizia automatica delle visite grezze arriverà con il passaggio alla raccolta propria.',
						'wp-5w'
					) }
					value={ fields.retention_days }
					onChange={ set( 'retention_days' ) }
				/>
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
