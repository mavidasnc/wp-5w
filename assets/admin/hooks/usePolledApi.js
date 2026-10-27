import { useEffect, useState } from '@wordpress/element';
import { api } from '../api';

/**
 * Carica una rotta della REST API del plugin al montaggio e la ricarica a intervalli regolari.
 *
 * Si aggiorna solo mentre la scheda del browser è visibile: quando viene nascosta l'intervallo si ferma, e al ritorno si ricarica
 * subito e si riparte. Un errore non ferma l'aggiornamento e non cancella gli ultimi dati arrivati.
 *
 * @param {string} path     Percorso dopo wp5w/v1.
 * @param {number} interval Millisecondi tra un aggiornamento e il successivo.
 * @return {{data: ?Object, error: ?string}} Ultimi dati ricevuti e ultimo errore (null se l'ultima chiamata è riuscita).
 */
export default function usePolledApi( path, interval ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		let timer = null;
		let active = true;

		// Una chiamata: l'esito si applica solo se il componente è ancora montato.
		const load = () =>
			api( path )
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
			timer = setInterval( load, interval );
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
	}, [ path, interval ] );

	return { data, error };
}
