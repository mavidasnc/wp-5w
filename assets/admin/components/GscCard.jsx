import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, ExternalLink, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import { api, errorText, formatTime } from '../api';
import Card from './Card';

/**
 * Passi da fare una volta nella Google Cloud Console (ogni sito usa il proprio client OAuth).
 *
 * @param {Object} props
 * @param {string} props.redirectUri Redirect URI da registrare nel client.
 */
function Steps( { redirectUri } ) {
	// Copia l'indirizzo; se il browser non lo permette l'utente lo seleziona dal campo.
	const copy = () => {
		// writeText restituisce una promise: il rifiuto (permesso negato, pagina non sicura) non passa da un try/catch.
		window.navigator.clipboard?.writeText( redirectUri ).catch( () => {} );
	};

	return (
		<ol className="wp5w-gsc__steps">
			<li>
				{ __( 'Nella Google Cloud Console crea un progetto e abilita la', 'wp-5w' ) }{ ' ' }
				<ExternalLink href="https://console.cloud.google.com/apis/library/searchconsole.googleapis.com">
					{ __( 'Google Search Console API', 'wp-5w' ) }
				</ExternalLink>
				.
			</li>
			<li>
				{ __( 'In', 'wp-5w' ) }{ ' ' }
				<ExternalLink href="https://console.cloud.google.com/auth/overview">{ __( 'Google Auth Platform', 'wp-5w' ) }</ExternalLink>{ ' ' }
				{ __( 'configura la schermata di consenso (tipo «Esterno») e pubblica l\'app «In produzione»: in «Test» il collegamento scade dopo 7 giorni. Google mostra un avviso perché l\'app non è verificata: va bene, l\'app è tua.', 'wp-5w' ) }
			</li>
			<li>
				{ __( 'Crea un', 'wp-5w' ) }{ ' ' }
				<ExternalLink href="https://console.cloud.google.com/auth/clients">{ __( 'client OAuth', 'wp-5w' ) }</ExternalLink>{ ' ' }
				{ __( 'di tipo «Applicazione web» e come «URI di reindirizzamento autorizzato» incolla questo indirizzo:', 'wp-5w' ) }
				<div className="wp5w-gsc__uri">
					<TextControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						label={ __( 'URI di reindirizzamento', 'wp-5w' ) }
						readOnly
						value={ redirectUri }
						onFocus={ ( event ) => event.target.select() }
					/>
					<Button variant="secondary" onClick={ copy }>
						{ __( 'Copia', 'wp-5w' ) }
					</Button>
				</div>
			</li>
			<li>{ __( 'Copia ID client e segreto del client qui sotto.', 'wp-5w' ) }</li>
		</ol>
	);
}

/**
 * Box «Search Console»: credenziali del client OAuth, collegamento a Google e proprietà.
 * L'import dei giorni mancanti sta nella scheda Aggiornamenti (GscImportCard).
 */
export default function GscCard() {
	const [ status, setStatus ] = useState( null );
	const [ fields, setFields ] = useState( { client_id: '', client_secret: '' } );
	const [ busy, setBusy ] = useState( '' ); // '', 'save', 'site' o 'disconnect'.
	const [ notice, setNotice ] = useState( null );

	// Applica lo stato ricevuto dal server (e l'esito del collegamento, che arriva una sola volta).
	const apply = useCallback( ( data ) => {
		setStatus( data );
		setFields( ( current ) => ( { ...current, client_id: data.client_id } ) );
		if ( data.flash?.text ) {
			setNotice( { status: data.flash.status, text: data.flash.text } );
		}
	}, [] );

	// Al montaggio si legge lo stato.
	useEffect( () => {
		api( '/admin/gsc' )
			.then( apply )
			.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) );
	}, [ apply ] );

	// Esegue una chiamata che restituisce lo stato aggiornato.
	const call = ( name, path, data ) => {
		setBusy( name );
		setNotice( null );
		return api( path, { method: 'POST', data } )
			.then( ( result ) => {
				apply( result );
				return true;
			} )
			.catch( ( e ) => {
				setNotice( { status: 'error', text: errorText( e ) } );
				return false;
			} )
			.finally( () => setBusy( '' ) );
	};

	// Salva client ID e segreto (il segreto non si rilegge: il campo si svuota).
	const saveCredentials = () =>
		call( 'save', '/admin/gsc/credentials', fields ).then( ( ok ) => {
			if ( ok ) {
				setFields( ( current ) => ( { ...current, client_secret: '' } ) );
				setNotice( { status: 'success', text: __( 'Credenziali salvate.', 'wp-5w' ) } );
			}
		} );

	const chooseSite = ( site ) => call( 'site', '/admin/gsc/site', { site } );

	if ( ! status ) {
		return (
			<Card id="options-gsc" title={ __( 'Search Console', 'wp-5w' ) }>
				{ notice ? <Notice status={ notice.status } isDismissible={ false }>{ notice.text }</Notice> : <Spinner /> }
			</Card>
		);
	}

	const sites = status.sites.includes( status.site ) || ! status.site ? status.sites : [ status.site, ...status.sites ];

	return (
		<Card id="options-gsc" title={ __( 'Search Console (API di Google)', 'wp-5w' ) }>
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.text }
				</Notice>
			) }

			<p className="wp5w-note wp5w-note--top">
				{ __( 'Collega il sito all\'API di Search Console di Google: i dati sono più completi di quelli di Rank Math (circa il 60% di query in più, fino a 16 mesi). Una volta collegato, l\'import si aggiorna da solo ogni notte e si può lanciare a mano dalla scheda Aggiornamenti.', 'wp-5w' ) }
			</p>

			{ ! status.configured && <Steps redirectUri={ status.redirect_uri } /> }

			{ ( ! status.configured || ! status.connected ) && (
				<div className="wp5w-gsc__credentials">
					{ status.configured && (
						<details>
							<summary>{ __( 'Cambia le credenziali del client OAuth', 'wp-5w' ) }</summary>
							<Steps redirectUri={ status.redirect_uri } />
						</details>
					) }
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'ID client', 'wp-5w' ) }
						help={ __( 'Finisce con .apps.googleusercontent.com.', 'wp-5w' ) }
						value={ fields.client_id }
						onChange={ ( value ) => setFields( { ...fields, client_id: value } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="password"
						autoComplete="new-password"
						label={ __( 'Segreto del client', 'wp-5w' ) }
						help={ status.has_secret ? __( 'Già salvato: lascia vuoto per non cambiarlo.', 'wp-5w' ) : '' }
						value={ fields.client_secret }
						onChange={ ( value ) => setFields( { ...fields, client_secret: value } ) }
					/>
					<div className="wp5w-actions">
						<Button
							variant={ status.configured ? 'secondary' : 'primary' }
							isBusy={ 'save' === busy }
							disabled={ !! busy || ! fields.client_id || ( ! fields.client_secret && ! status.has_secret ) }
							onClick={ saveCredentials }
						>
							{ __( 'Salva credenziali', 'wp-5w' ) }
						</Button>
					</div>
				</div>
			) }

			{ status.reauth && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'Google ha revocato l\'accesso (o il collegamento non si legge più): ricollega Google per riprendere gli import.', 'wp-5w' ) }
				</Notice>
			) }

			{ status.configured && ! status.connected && (
				<div className="wp5w-actions">
					<Button variant="primary" href={ status.connect_url }>
						{ __( 'Collega Google', 'wp-5w' ) }
					</Button>
				</div>
			) }

			{ status.connected && (
				<>
					{ status.sites_error && <Notice status="warning" isDismissible={ false }>{ status.sites_error }</Notice> }
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Proprietà di Search Console', 'wp-5w' ) }
						help={ status.site ? '' : __( 'Scegli la proprietà di questo sito: senza, non si può importare.', 'wp-5w' ) }
						value={ status.site }
						disabled={ !! busy }
						options={ [ { label: __( '— Scegli —', 'wp-5w' ), value: '' }, ...sites.map( ( site ) => ( { label: site, value: site } ) ) ] }
						onChange={ chooseSite }
					/>

					<dl className="wp5w-updates__facts">
						<div>
							<dt>{ __( 'Collegato dal', 'wp-5w' ) }</dt>
							<dd>{ formatTime( status.connected_at ) }</dd>
						</div>
					</dl>
					<div className="wp5w-actions">
						<Button variant="tertiary" isDestructive isBusy={ 'disconnect' === busy } disabled={ !! busy } onClick={ () => call( 'disconnect', '/admin/gsc/disconnect' ) }>
							{ __( 'Scollega Google', 'wp-5w' ) }
						</Button>
					</div>
				</>
			) }
		</Card>
	);
}
