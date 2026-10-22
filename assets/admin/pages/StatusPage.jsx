import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';
import Card from '../components/Card';
import Compare from '../components/Compare';

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

/**
 * Solo la data da timestamp UNIX.
 *
 * @param {?number} ts Timestamp in secondi.
 */
const formatDate = ( ts ) => ( ts ? new Date( ts * 1000 ).toLocaleDateString( 'it-IT', { dateStyle: 'medium' } ) : '–' );

/**
 * Mese e anno di una chiave Y-m (es. «ottobre 2026»).
 *
 * @param {string} month Mese.
 */
const formatMonth = ( month ) =>
	new Date( `${ month }-15T12:00:00Z` ).toLocaleDateString( 'it-IT', { month: 'long', year: 'numeric', timeZone: 'UTC' } );

export default function StatusPage() {
	const [ status, setStatus ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const load = useCallback( () => {
		api( '/admin/status' )
			.then( setStatus )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
	}, [] );

	useEffect( load, [ load ] );

	if ( ! status ) {
		return notice ? <Notice status="error" isDismissible={ false }>{ notice.text }</Notice> : <Spinner />;
	}

	return (
		<div className="wp5w-status">
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.text }
				</Notice>
			) }

			<Compare />

			<Card id="status-sources" title={ __( 'Sorgenti', 'wp-5w' ) }>
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
			</Card>

			<Card id="status-tables" title={ __( 'Dati importati', 'wp-5w' ) }>
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
			</Card>

			<Card id="status-geo" title={ __( 'Database dei paesi', 'wp-5w' ) }>
				{ status.geo.available ? (
					<p>
						{ __( 'DB-IP Lite Country di', 'wp-5w' ) } { status.geo.month ? formatMonth( status.geo.month ) : '–' }, { __( 'compilato il', 'wp-5w' ) }{ ' ' }
						{ formatDate( status.geo.built ) }, { formatNumber( Math.round( ( status.geo.size / 1048576 ) * 10 ) / 10 ) } MB. { __( 'Installato il', 'wp-5w' ) }{ ' ' }
						{ formatDate( status.geo.updated ) }.
					</p>
				) : (
					<p>{ __( 'Non ancora scaricato: il paese delle visite resta vuoto finché non arriva il primo aggiornamento.', 'wp-5w' ) }</p>
				) }
				<p>
					{ __( 'Ultimo controllo:', 'wp-5w' ) } { formatTime( status.geo.checked ) }. { __( 'Prossimo:', 'wp-5w' ) } { formatTime( status.geo.next ) }.
				</p>
				{ status.geo.error && (
					<Notice status="warning" isDismissible={ false }>
						{ status.geo.error }
					</Notice>
				) }
				<p className="wp5w-note">
					<a href="https://db-ip.com" target="_blank" rel="noopener noreferrer">
						{ __( 'IP Geolocation by DB-IP', 'wp-5w' ) }
					</a>{ ' ' }
					({ __( 'licenza CC BY 4.0', 'wp-5w' ) })
				</p>
			</Card>
		</div>
	);
}
