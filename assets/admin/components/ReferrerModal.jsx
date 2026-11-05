import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';
import ShowMore from './ShowMore';
import VisitsDetail from './VisitsDetail';

/**
 * Valore di una colonna che può non essere disponibile (null): trattino.
 *
 * @param {?number} value Valore.
 */
const maybe = ( value ) => ( value === null ? '–' : formatNumber( value ) );

/**
 * Da dove vengono i numeri della modale: visite grezze (raccolta propria) e, prima, dati aggregati.
 *
 * @param {Object} sources { aggregate, raw }, ognuno { from, to } oppure null.
 */
export const sourcesNote = ( { aggregate, raw } ) => {
	const parts = [];
	if ( raw ) {
		parts.push(
			/* translators: 1: primo giorno, 2: ultimo giorno (gg/mm/aaaa). */
			sprintf( __( 'dal %1$s al %2$s dalla raccolta propria (visite grezze, aggiornate a ogni visita)', 'wp-5w' ), formatDay( raw.from ), formatDay( raw.to ) )
		);
	}
	if ( aggregate ) {
		parts.push(
			/* translators: 1: primo giorno, 2: ultimo giorno (gg/mm/aaaa). */
			sprintf( __( 'dal %1$s al %2$s dai dati aggregati (WP Statistics o raccolta propria, secondo la data di passaggio)', 'wp-5w' ), formatDay( aggregate.from ), formatDay( aggregate.to ) )
		);
	}
	return `${ __( 'Numeri', 'wp-5w' ) } ${ parts.join( '; ' ) }.`;
};

/**
 * Contenuti raggiunti da un sito di provenienza nel periodo del box, dal più visitato, con oggi, ieri, totale e IP diversi;
 * carica da /admin/audience/referrer. «Dettaglio» apre, nella stessa modale, le singole visite di quel contenuto da quel sito.
 *
 * @param {Object}   props
 * @param {Object}   props.site    Riga del box: { referrer, label }.
 * @param {number}   props.days    Periodo del box (1 = oggi).
 * @param {Function} props.onClose Chiusura del modale.
 */
export default function ReferrerModal( { site, days, onClose } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ detail, setDetail ] = useState( null );

	useEffect( () => {
		// Come nel box: una risposta arrivata dopo la chiusura o un cambio di sito si ignora.
		let active = true;
		setData( null );
		setError( null );
		// Il referrer grezzo (non l'etichetta), codificato: può essere android-app://pacchetto/.
		api( `/admin/audience/referrer?referrer=${ encodeURIComponent( site.referrer ) }&days=${ days }` )
			.then( ( result ) => active && setData( result ) )
			.catch( ( e ) => active && setError( e.message ) );
		return () => {
			active = false;
		};
	}, [ site.referrer, days ] );

	return (
		<Modal title={ site.label } onRequestClose={ onClose } size="large" className="wp5w-modal">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && detail && (
				<>
					<Button variant="link" onClick={ () => setDetail( null ) }>
						{ __( '← Torna ai contenuti', 'wp-5w' ) }
					</Button>
					<VisitsDetail filter={ { type: detail.type, id: detail.id, days, referrer: site.referrer } } />
				</>
			) }
			{ data && ! detail && (
				<>
					<p>
						{ site.label !== site.referrer && <code>{ site.referrer }</code> }
						{ site.label !== site.referrer && ' · ' }
						{ data.live ? __( 'Oggi', 'wp-5w' ) : `${ formatDay( data.range.from ) } – ${ formatDay( data.range.to ) }` }
						{ ' · ' }
						{ /* translators: 1: visitatori, 2: contenuti. */ sprintf( __( '%1$s visitatori su %2$s contenuti', 'wp-5w' ), formatNumber( data.totals.visitors ), formatNumber( data.totals.contents ) ) }
					</p>
					{ data.rows.length ? (
						<ShowMore rows={ data.rows } size={ 20 }>
							{ ( rows ) => (
								<table className="widefat striped wp5w-table wp5w-referrers">
									<thead>
										<tr>
											<th>{ __( 'Contenuto', 'wp-5w' ) }</th>
											<th className="num">{ __( 'Oggi', 'wp-5w' ) }</th>
											<th className="num">{ __( 'Ieri', 'wp-5w' ) }</th>
											<th className="num">{ __( 'Totale', 'wp-5w' ) }</th>
											<th className="num">{ __( 'IP diversi', 'wp-5w' ) }</th>
											<th></th>
										</tr>
									</thead>
									<tbody>
										{ rows.map( ( row ) => (
											<tr key={ `${ row.type }-${ row.id }-${ row.url }` }>
												<td>
													<a href={ row.url } target="_blank" rel="noreferrer">{ row.title }</a>
												</td>
												<td className="num">{ maybe( row.today ) }</td>
												<td className="num">{ maybe( row.yesterday ) }</td>
												<td className="num">{ formatNumber( row.visitors ) }</td>
												<td className="num">{ row.ips === null ? __( 'n.d.', 'wp-5w' ) : formatNumber( row.ips ) }</td>
												<td className="num">
													<Button variant="secondary" size="compact" onClick={ () => setDetail( row ) }>
														{ __( 'Dettaglio', 'wp-5w' ) }
													</Button>
												</td>
											</tr>
										) ) }
									</tbody>
								</table>
							) }
						</ShowMore>
					) : (
						<p>{ __( 'Nessun contenuto raggiunto da questo sito nel periodo.', 'wp-5w' ) }</p>
					) }
					<p className="wp5w-note">
						{ __( 'Visitatori: persone entrate nel sito da questo sito di provenienza, una volta per contenuto e per giorno; il totale è quello del periodo e comprende oggi e ieri.', 'wp-5w' ) }{ ' ' }
						{ sourcesNote( data.sources ) }{ ' ' }
						{ data.ip_from
							? sprintf(
								/* translators: %s: primo giorno con l'hash dell'IP (gg/mm/aaaa). */
								__( 'IP diversi: contati dal %s sulle sole visite con l\'hash dell\'IP (raccolta propria, entro i giorni di conservazione); «n.d.» se il contenuto non ne ha.', 'wp-5w' ),
								formatDay( data.ip_from )
							)
							: __( 'IP diversi: n.d., nessuna visita del periodo ha l\'hash dell\'IP (arriva con la versione 0.19.0 e si cancella dopo i giorni di conservazione).', 'wp-5w' ) }
						{ data.totals.contents > data.rows.length &&
							` ${ /* translators: 1: contenuti mostrati, 2: contenuti totali. */ sprintf( __( 'Sono mostrati i primi %1$d contenuti su %2$d.', 'wp-5w' ), data.rows.length, data.totals.contents ) }` }
					</p>
				</>
			) }
		</Modal>
	);
}
