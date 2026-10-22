import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { api, errorText, formatDay } from '../api';
import Card from './Card';

/**
 * Data e ora da timestamp UNIX.
 *
 * @param {?number} ts Timestamp in secondi.
 */
const formatTime = ( ts ) =>
	ts
		? new Date( ts * 1000 ).toLocaleString( 'it-IT', {
				dateStyle: 'medium',
				timeStyle: 'short',
		  } )
		: '–';

/**
 * Box «Dati di traffico» della scheda Aggiornamenti: ETL delle visite (WP Statistics, raccolta propria, Independent Analytics)
 * e dello storico di Search Console di Rank Math, ultimi giorni o tutto lo storico.
 */
export default function EtlCard() {
	const [ status, setStatus ] = useState( null );
	const [ running, setRunning ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const load = useCallback( () => {
		api( '/admin/status' )
			.then( setStatus )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			);
	}, [] );

	useEffect( load, [ load ] );

	// Avvia l'ETL (ultimi giorni o completo) e ricarica lo stato.
	const runEtl = ( full ) => {
		setRunning( true );
		setNotice( null );
		api( '/admin/etl', { method: 'POST', data: { full } } )
			.then( ( state ) =>
				setNotice( {
					status: 'success',
					text: `${ __( 'Import completato in', 'wp-5w' ) } ${
						state.duration
					} s.`,
				} )
			)
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => {
				setRunning( false );
				load();
			} );
	};

	const last = status?.last_run;

	return (
		<Card id="updates-etl" title={ __( 'Dati di traffico', 'wp-5w' ) }>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<p className="wp5w-note wp5w-note--top">
				{ __(
					'Copia nelle tabelle del plugin le visite di WP Statistics, della raccolta propria e di Independent Analytics, più lo storico di Search Console che Rank Math tiene nel database. Ogni notte alle 4:00 si aggiornano gli ultimi 7 giorni: qui puoi farlo subito, oppure rifare tutto lo storico (più lento).',
					'wp-5w'
				) }
			</p>
			{ ! status && ! notice && <Spinner /> }
			{ status && (
				<>
					{ last ? (
						<p>
							{ __( 'Ultimo import:', 'wp-5w' ) }{ ' ' }
							{ formatTime( last.started ) } ({ last.mode },{ ' ' }
							{ formatDay( last.from ) } –{ ' ' }
							{ formatDay( last.to ) }, { last.duration } s)
							{ last.ok
								? ''
								: ` — ${ __( 'con errori', 'wp-5w' ) }` }
						</p>
					) : (
						<p>{ __( 'Nessun import eseguito.', 'wp-5w' ) }</p>
					) }
					<p>
						{ __( 'Prossimo aggiornamento automatico:', 'wp-5w' ) }{ ' ' }
						{ formatTime( status.next_run ) }
					</p>
				</>
			) }
			<div className="wp5w-actions">
				<Button
					variant="primary"
					isBusy={ running }
					disabled={ running }
					onClick={ () => runEtl( false ) }
				>
					{ __( 'Aggiorna gli ultimi 7 giorni', 'wp-5w' ) }
				</Button>
				<Button
					variant="secondary"
					isBusy={ running }
					disabled={ running }
					onClick={ () => runEtl( true ) }
				>
					{ __( 'Reimporta tutto lo storico', 'wp-5w' ) }
				</Button>
			</div>
		</Card>
	);
}
