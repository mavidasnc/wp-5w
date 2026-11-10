import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';
import { LABELS } from './ChannelList';
import ShowMore from './ShowMore';
import VisitsDetail from './VisitsDetail';

/**
 * Nome della provenienza di una riga: la firma del bot, il sito (o l'app) oppure, senza referrer, «diretto» solo per il canale diretto.
 *
 * @param {Object} row Riga di /admin/today/page.
 */
const sourceName = ( row ) => {
	if ( row.bot ) {
		return row.bot;
	}
	if ( row.label ) {
		return row.label;
	}
	return row.channel === 'direct' ? __( '(diretto)', 'wp-5w' ) : '–';
};

/**
 * Da dove sono arrivati i visitatori di una pagina vista oggi, con il conteggio parziale dei visitatori per provenienza;
 * carica da /admin/today/page. «Dettaglio visite» mostra, nella stessa modale, le singole visite di oggi a quella pagina.
 *
 * @param {Object}   props
 * @param {Object}   props.page    Riga della tabella «Pagine viste oggi»: { type, id, title }.
 * @param {Function} props.onClose Chiusura del modale.
 */
export default function TodayPageModal( { page, onClose } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ detail, setDetail ] = useState( false );

	useEffect( () => {
		// Si carica una volta all'apertura: l'aggiornamento della scheda ogni minuto non tocca la modale.
		let active = true;
		setData( null );
		setError( null );
		api( `/admin/today/page?type=${ encodeURIComponent( page.type ) }&id=${ page.id }` )
			.then( ( result ) => active && setData( result ) )
			.catch( ( e ) => active && setError( e.message ) );
		return () => {
			active = false;
		};
	}, [ page.type, page.id ] );

	return (
		<Modal title={ page.title } onRequestClose={ onClose } size="large" className="wp5w-modal">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && detail && (
				<>
					<Button variant="link" onClick={ () => setDetail( false ) }>
						{ __( '← Torna alle provenienze', 'wp-5w' ) }
					</Button>
					<VisitsDetail filter={ { type: page.type, id: page.id, days: 1 } } />
				</>
			) }
			{ data && ! detail && (
				<>
					<p>
						<a href={ data.content.url } target="_blank" rel="noreferrer">{ data.content.title }</a>
						{ ' · ' }
						{ formatDay( data.day ) }
						{ ' · ' }
						{ /* translators: 1: visitatori, 2: visualizzazioni. */ sprintf( __( '%1$s visitatori, %2$s visualizzazioni', 'wp-5w' ), formatNumber( data.totals.visitors ), formatNumber( data.totals.views ) ) }
						{ ' ' }
						<Button variant="secondary" size="compact" onClick={ () => setDetail( true ) }>
							{ __( 'Dettaglio visite', 'wp-5w' ) }
						</Button>
					</p>
					{ data.rows.length ? (
						<ShowMore rows={ data.rows } size={ 20 }>
							{ ( rows ) => (
								<div className="wp5w-table-scroll">
									<table className="widefat striped wp5w-table">
										<thead>
											<tr>
												<th>{ __( 'Provenienza', 'wp-5w' ) }</th>
												<th>{ __( 'Sorgente', 'wp-5w' ) }</th>
												<th>{ __( 'Canale', 'wp-5w' ) }</th>
												<th className="num">{ __( 'Visitatori', 'wp-5w' ) }</th>
												<th className="num">{ __( 'Quota', 'wp-5w' ) }</th>
												<th className="num">{ __( 'Visualizzazioni', 'wp-5w' ) }</th>
												<th className="num">{ __( 'IP diversi', 'wp-5w' ) }</th>
											</tr>
										</thead>
										<tbody>
											{ rows.map( ( row ) => (
												<tr key={ `${ row.channel }|${ row.referrer }|${ row.bot }` }>
													<td title={ row.referrer }>{ sourceName( row ) }</td>
													<td>{ row.source || '–' }</td>
													<td>{ LABELS[ row.channel ] ?? ( row.channel || '–' ) }</td>
													<td className="num">{ formatNumber( row.visitors ) }</td>
													<td className="num">{ formatNumber( ( row.visitors / data.totals.visitors ) * 100, '%' ) }</td>
													<td className="num">{ formatNumber( row.views ) }</td>
													<td className="num">{ row.ips === null ? __( 'n.d.', 'wp-5w' ) : formatNumber( row.ips ) }</td>
												</tr>
											) ) }
										</tbody>
										<tfoot>
											<tr>
												<th colSpan={ 3 }>{ __( 'Totale', 'wp-5w' ) }</th>
												<th className="num">{ formatNumber( data.totals.visitors ) }</th>
												<th className="num">{ formatNumber( 100, '%' ) }</th>
												<th className="num">{ formatNumber( data.totals.views ) }</th>
												<th></th>
											</tr>
										</tfoot>
									</table>
								</div>
							) }
						</ShowMore>
					) : (
						<p>{ __( 'Nessuna visita a questa pagina oggi.', 'wp-5w' ) }</p>
					) }
					<p className="wp5w-note">
						{ __( 'Come nella tabella, la provenienza di un visitatore è quella della sua prima visita di oggi, anche se era su un\'altra pagina: chi entra da Google sulla home e poi apre questo contenuto conta sotto Google. Ogni visitatore compare in una sola riga, quindi i visitatori si sommano; le visualizzazioni sono quelle di questa pagina. Bot e browser automatici sono compresi. Dati della raccolta propria, caricati all\'apertura della finestra.', 'wp-5w' ) }{ ' ' }
						{ __( 'IP diversi: contati sulle sole visite con l\'hash dell\'IP (raccolta propria, entro i giorni di conservazione); «n.d.» se la riga non ne ha.', 'wp-5w' ) }
					</p>
				</>
			) }
		</Modal>
	);
}
