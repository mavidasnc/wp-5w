import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Modal, Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';
import ShowMore from './ShowMore';

/**
 * Contenuti raggiunti da un sito di provenienza nel periodo del box, dal più visitato; carica da /admin/audience/referrer.
 *
 * @param {Object}   props
 * @param {Object}   props.site    Riga del box: { referrer, label, visitors, contents }.
 * @param {number}   props.days    Periodo del box (1 = oggi).
 * @param {Function} props.onClose Chiusura del modale.
 */
export default function ReferrerModal( { site, days, onClose } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		setData( null );
		setError( null );
		// Il referrer grezzo (non l'etichetta), codificato: può essere android-app://pacchetto/.
		api( `/admin/audience/referrer?referrer=${ encodeURIComponent( site.referrer ) }&days=${ days }` )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [ site.referrer, days ] );

	return (
		<Modal title={ site.label } onRequestClose={ onClose } size="medium">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && (
				<>
					<p>
						{ site.label !== site.referrer && <code>{ site.referrer }</code> }
						{ site.label !== site.referrer && ' · ' }
						{ data.live ? __( 'Oggi', 'wp-5w' ) : `${ formatDay( data.range.from ) } – ${ formatDay( data.range.to ) }` }
						{ ' · ' }
						{ /* translators: 1: visitatori, 2: contenuti. */ sprintf( __( '%1$s visitatori su %2$s contenuti', 'wp-5w' ), formatNumber( site.visitors ), formatNumber( site.contents ) ) }
					</p>
					{ data.rows.length ? (
						<ShowMore rows={ data.rows } size={ 20 }>
							{ ( rows ) => (
								<table className="widefat striped wp5w-table">
									<thead>
										<tr>
											<th>{ __( 'Contenuto', 'wp-5w' ) }</th>
											<th className="num">{ __( 'Visitatori', 'wp-5w' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ rows.map( ( row ) => (
											<tr key={ `${ row.type }-${ row.id }-${ row.url }` }>
												<td>
													<a href={ row.url } target="_blank" rel="noreferrer">{ row.title }</a>
												</td>
												<td className="num">{ formatNumber( row.visitors ) }</td>
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
						{ data.live
							? __( 'Dati della raccolta propria, in tempo reale.', 'wp-5w' )
							: __( 'Dati aggregati (WP Statistics o raccolta propria, secondo la data di passaggio); oggi è parziale.', 'wp-5w' ) }{ ' ' }
						{ __( 'Visitatori: persone entrate nel sito da questo sito di provenienza, una volta per contenuto e per giorno. Le visualizzazioni per sito di provenienza non vengono salvate.', 'wp-5w' ) }
						{ site.contents > data.rows.length &&
							` ${ /* translators: 1: contenuti mostrati, 2: contenuti totali. */ sprintf( __( 'Sono mostrati i primi %1$d contenuti su %2$d.', 'wp-5w' ), data.rows.length, site.contents ) }` }
					</p>
				</>
			) }
		</Modal>
	);
}
