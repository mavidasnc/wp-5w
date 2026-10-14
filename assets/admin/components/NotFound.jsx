import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';
import Card from './Card';
import PeriodSwitch from './PeriodSwitch';
import ShowMore from './ShowMore';

/**
 * L'URI in chiaro (%20 e accenti), oppure com'è se la decodifica fallisce.
 *
 * @param {string} uri Percorso.
 */
const readable = ( uri ) => {
	try {
		return decodeURI( uri );
	} catch {
		return uri;
	}
};

/**
 * Provenienza di una visita: link interno, diretto o sconosciuto, oppure il sito (con il nome dell'app, se lo è).
 *
 * @param {Object} row Riga della risposta.
 */
const origin = ( row ) => {
	if ( row.internal ) {
		return __( 'Link interno', 'wp-5w' );
	}
	return row.referrer === '' ? __( 'Diretto o sconosciuto', 'wp-5w' ) : row.label;
};

/**
 * Box «Pagine non trovate» della scheda Articoli: indirizzi inesistenti visitati, con la provenienza; carica da /admin/notfound.
 */
export default function NotFound() {
	const [ days, setDays ] = useState( 30 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		setData( null );
		setError( null );
		api( `/admin/notfound?days=${ days }` )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [ days ] );

	return (
		<Card
			id="posts-notfound"
			title={
				<>
					{ __( 'Pagine non trovate', 'wp-5w' ) }
					{ data && (
						<span className="wp5w-type">
							{ formatDay( data.range.from ) } – { formatDay( data.range.to ) }
						</span>
					) }
				</>
			}
			actions={ <PeriodSwitch days={ days } onChange={ setDays } /> }
		>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && ( data.rows.length ? (
				<ShowMore rows={ data.rows } size={ 15 }>
					{ ( rows ) => (
						<table className="widefat striped wp5w-table">
							<thead>
								<tr>
									<th>{ __( 'Indirizzo', 'wp-5w' ) }</th>
									<th>{ __( 'Provenienza', 'wp-5w' ) }</th>
									<th className="num">{ __( 'Visite', 'wp-5w' ) }</th>
									<th>{ __( 'Ultima', 'wp-5w' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ rows.map( ( row ) => (
									<tr key={ `${ row.uri }|${ row.referrer }` }>
										<td>
											<a href={ row.url } target="_blank" rel="noreferrer">{ readable( row.uri ) }</a>
										</td>
										<td title={ row.referrer }>{ origin( row ) }</td>
										<td className="num">{ formatNumber( row.hits ) }</td>
										<td className="wp5w-nowrap">{ row.last_seen }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</ShowMore>
			) : (
				<p>{ __( 'Nessuna pagina non trovata nel periodo.', 'wp-5w' ) }</p>
			) ) }
			<p className="wp5w-note">
				{ __( 'Visite a indirizzi inesistenti registrate dal tracker. Le immagini mancanti non compaiono: il server risponde senza passare da WordPress.', 'wp-5w' ) }
			</p>
		</Card>
	);
}
