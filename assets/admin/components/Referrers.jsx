import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';
import Card from './Card';
import PeriodSwitch, { PERIODS } from './PeriodSwitch';
import ShowMore from './ShowMore';
import ReferrerModal from './ReferrerModal';
import { LABELS } from './ChannelList';

// «Oggi» (1 giorno) prima dei tre periodi soliti.
const REFERRER_PERIODS = [ { days: 1, label: __( 'Oggi', 'wp-5w' ) }, ...PERIODS ];

/**
 * Box «Provenienza»: siti di provenienza di oggi o degli ultimi N giorni; carica da /admin/audience/referrers.
 * Il clic su un sito apre l'elenco dei contenuti che ha raggiunto nello stesso periodo.
 */
export default function Referrers() {
	const [ days, setDays ] = useState( 7 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ site, setSite ] = useState( null );

	useEffect( () => {
		setData( null );
		setError( null );
		api( `/admin/audience/referrers?days=${ days }` )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [ days ] );

	return (
		<Card
			id="referrers"
			title={
				<>
					{ __( 'Provenienza', 'wp-5w' ) }{ ' ' }
					{ data && (
						<span className="wp5w-type">
							{ data.live ? __( 'oggi', 'wp-5w' ) : `${ formatDay( data.range.from ) } – ${ formatDay( data.range.to ) }` }
						</span>
					) }
				</>
			}
			actions={ <PeriodSwitch days={ days } onChange={ setDays } periods={ REFERRER_PERIODS } /> }
		>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && ( data.rows.length ? (
				<ShowMore rows={ data.rows } size={ 15 }>
					{ ( rows ) => (
						<table className="widefat striped wp5w-table wp5w-referrers">
							<thead>
								<tr>
									<th>{ __( 'Sito', 'wp-5w' ) }</th>
									<th>{ __( 'Sorgente', 'wp-5w' ) }</th>
									<th>{ __( 'Canale', 'wp-5w' ) }</th>
									<th className="num">{ __( 'Visitatori', 'wp-5w' ) }</th>
									<th className="num">{ __( 'Contenuti', 'wp-5w' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ rows.map( ( row ) => (
									<tr key={ row.referrer }>
										<td title={ row.referrer }>
											<Button variant="link" onClick={ () => setSite( row ) }>
												{ row.label }
											</Button>
										</td>
										<td>{ row.source || '–' }</td>
										<td>{ LABELS[ row.channel ] ?? ( row.channel || '–' ) }</td>
										<td className="num">{ formatNumber( row.visitors ) }</td>
										<td className="num">{ formatNumber( row.contents ) }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</ShowMore>
			) : (
				<p>{ __( 'Nessun sito di provenienza nel periodo.', 'wp-5w' ) }</p>
			) ) }
			{ data && (
				<p className="wp5w-note">
					{ __( 'Contenuti: quanti articoli o pagine diversi hanno ricevuto visitatori da quel sito; clic sul sito per vederli.', 'wp-5w' ) }{ ' ' }
					{ data.live
						? __( 'Oggi: dati della raccolta propria, in tempo reale (vuoto se la raccolta propria è spenta).', 'wp-5w' )
						: __( 'Dati aggregati: oggi è parziale (raccolta propria, aggiornata ogni ora) o assente (WP Statistics, importato di notte).', 'wp-5w' ) }
				</p>
			) }
			{ site && data && <ReferrerModal site={ site } days={ days } onClose={ () => setSite( null ) } /> }
		</Card>
	);
}
