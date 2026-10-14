import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { api, formatNumber } from '../api';
import Card from './Card';

// Ogni quanto si aggiorna, in millisecondi.
const REFRESH_MS = 30000;

/**
 * Box «Online adesso»: visitatori degli ultimi 5 minuti e contenuti che stanno guardando; carica da /admin/online.
 *
 * Si carica al montaggio, poi ogni 30 secondi solo mentre la scheda del browser è visibile: quando viene nascosta
 * l'intervallo si ferma, e al ritorno si ricarica subito e si riparte. Un errore non ferma l'aggiornamento.
 */
export default function OnlineNow() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		let timer = null;
		let active = true;

		// Una chiamata: l'esito si applica solo se il componente è ancora montato.
		const load = () =>
			api( '/admin/online' )
				.then( ( response ) => {
					if ( active ) {
						setData( response );
						setError( null );
					}
				} )
				.catch( ( e ) => {
					if ( active ) {
						setError( e.message );
					}
				} );

		const stop = () => {
			if ( timer !== null ) {
				clearInterval( timer );
				timer = null;
			}
		};
		const start = () => {
			stop();
			timer = setInterval( load, REFRESH_MS );
		};

		// Scheda nascosta: si ferma. Scheda di nuovo visibile: si ricarica subito e si riparte.
		const onVisibility = () => {
			if ( document.visibilityState === 'visible' ) {
				load();
				start();
			} else {
				stop();
			}
		};

		// Primo caricamento, e intervallo solo se la scheda è visibile.
		load();
		if ( document.visibilityState === 'visible' ) {
			start();
		}
		document.addEventListener( 'visibilitychange', onVisibility );

		// Allo smontaggio si tolgono intervallo e listener.
		return () => {
			active = false;
			stop();
			document.removeEventListener( 'visibilitychange', onVisibility );
		};
	}, [] );

	return (
		<Card
			id="audience-online"
			title={
				<>
					{ __( 'Online adesso', 'wp-5w' ) }
					{ data && (
						<span className="wp5w-type">
							{ /* translators: %s: ora dell'ultimo aggiornamento (hh:mm:ss). */ sprintf( __( 'aggiornato alle %s', 'wp-5w' ), data.at ) }
						</span>
					) }
				</>
			}
		>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && (
				<div className="wp5w-online">
					<p className="wp5w-online__count">{ formatNumber( data.visitors ) }</p>
					{ data.contents.length > 0 && (
						<ul className="wp5w-online__list">
							{ data.contents.map( ( row ) => (
								<li key={ `${ row.type }-${ row.id }-${ row.url }` }>
									<a href={ row.url } target="_blank" rel="noreferrer">{ row.title }</a>
									<span className="wp5w-online__value">{ formatNumber( row.visitors ) }</span>
								</li>
							) ) }
							{ data.other > 0 && (
								<li>
									{ __( 'Altri', 'wp-5w' ) }
									<span className="wp5w-online__value">{ formatNumber( data.other ) }</span>
								</li>
							) }
						</ul>
					) }
				</div>
			) }
			{ data && (
				<p className="wp5w-note">
					{ __( 'Visitatori con almeno una visita negli ultimi 5 minuti, ognuno sul contenuto che ha aperto per ultimo. Gli utenti esclusi dalle Opzioni non compaiono.', 'wp-5w' ) }
					{ ! data.tracking && ` ${ __( 'La raccolta propria è spenta nelle Opzioni.', 'wp-5w' ) }` }
				</p>
			) }
		</Card>
	);
}
