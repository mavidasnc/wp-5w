import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';

// Descrizione delle tabelle wp5w_*.
const TABLES = {
	site_daily: __( 'Totali del sito per giorno', 'wp-5w' ),
	object_daily: __( 'Visualizzazioni per contenuto', 'wp-5w' ),
	entry_daily: __( 'Ingressi per contenuto e canale', 'wp-5w' ),
	dim_daily: __( 'Paesi, device, browser, canali e sorgenti', 'wp-5w' ),
	gsc_daily: __( 'Search Console per pagina e query', 'wp-5w' ),
};

/**
 * Data e ora da timestamp UNIX.
 *
 * @param {?number} ts Timestamp in secondi.
 */
const formatTime = ( ts ) =>
	ts ? new Date( ts * 1000 ).toLocaleString( 'it-IT', { dateStyle: 'medium', timeStyle: 'short' } ) : '–';

export default function StatusPage() {
	const [ status, setStatus ] = useState( null );
	const [ running, setRunning ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const load = useCallback( () => {
		api( '/admin/status' )
			.then( setStatus )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
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
					text: `${ __( 'Import completato in', 'wp-5w' ) } ${ state.duration } s.`,
				} )
			)
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => {
				setRunning( false );
				load();
			} );
	};

	if ( ! status ) {
		return notice ? <Notice status="error" isDismissible={ false }>{ notice.text }</Notice> : <Spinner />;
	}

	const last = status.last_run;

	return (
		<div className="wp5w-status">
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.text }
				</Notice>
			) }

			<section className="wp5w-card">
				<h2>{ __( 'Sorgenti', 'wp-5w' ) }</h2>
				<table className="widefat striped">
					<tbody>
						{ status.sources.map( ( source ) => (
							<tr key={ source.id }>
								<td>
									<strong>{ source.label }</strong>
								</td>
								<td>{ source.version ?? __( 'non attivo', 'wp-5w' ) }</td>
								<td>
									{ source.ok
										? __( 'Schema riconosciuto', 'wp-5w' )
										: `${ __( 'Manca:', 'wp-5w' ) } ${ source.missing.join( ', ' ) }` }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</section>

			<section className="wp5w-card">
				<h2>{ __( 'Dati importati', 'wp-5w' ) }</h2>
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Tabella', 'wp-5w' ) }</th>
							<th>{ __( 'Dal', 'wp-5w' ) }</th>
							<th>{ __( 'Al', 'wp-5w' ) }</th>
							<th className="num">{ __( 'Righe', 'wp-5w' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ Object.entries( status.tables ).map( ( [ name, info ] ) => (
							<tr key={ name }>
								<td>
									<code>wp5w_{ name }</code> { TABLES[ name ] }
								</td>
								<td>{ formatDay( info.from ) }</td>
								<td>{ formatDay( info.to ) }</td>
								<td className="num">{ formatNumber( info.rows ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</section>

			<section className="wp5w-card">
				<h2>{ __( 'Import', 'wp-5w' ) }</h2>
				{ last ? (
					<p>
						{ __( 'Ultimo import:', 'wp-5w' ) } { formatTime( last.started ) } ({ last.mode },{ ' ' }
						{ formatDay( last.from ) } – { formatDay( last.to ) }, { last.duration } s)
						{ last.ok ? '' : ` — ${ __( 'con errori', 'wp-5w' ) }` }
					</p>
				) : (
					<p>{ __( 'Nessun import eseguito.', 'wp-5w' ) }</p>
				) }
				<p>
					{ __( 'Prossimo aggiornamento automatico:', 'wp-5w' ) } { formatTime( status.next_run ) }
				</p>
				<div className="wp5w-actions">
					<Button variant="primary" isBusy={ running } disabled={ running } onClick={ () => runEtl( false ) }>
						{ __( 'Aggiorna gli ultimi 7 giorni', 'wp-5w' ) }
					</Button>
					<Button variant="secondary" isBusy={ running } disabled={ running } onClick={ () => runEtl( true ) }>
						{ __( 'Reimporta tutto lo storico', 'wp-5w' ) }
					</Button>
				</div>
			</section>

			<p className="wp5w-note">
				wp-5w { status.version }
				{ status.updates?.update_available && ` — ${ __( 'disponibile la', 'wp-5w' ) } ${ status.updates.latest }` }
			</p>
		</div>
	);
}
