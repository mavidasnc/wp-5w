import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { api, formatDay } from '../api';
import Card from './Card';
import DimensionList from './DimensionList';
import PeriodSwitch from './PeriodSwitch';

// I tre elenchi: chiave della risposta e titolo. I valori UTM si mostrano così come arrivano.
const LISTS = [
	{ key: 'utm_campaign', title: __( 'Campagne', 'wp-5w' ) },
	{ key: 'utm_source', title: __( 'Sorgenti', 'wp-5w' ) },
	{ key: 'utm_medium', title: __( 'Mezzi', 'wp-5w' ) },
];

/**
 * Box «Campagne»: campagne, sorgenti e mezzi UTM degli ultimi N giorni; carica da /admin/audience/campaigns.
 * Legge solo la raccolta propria (WP Statistics non salva i parametri UTM).
 */
export default function Campaigns() {
	const [ days, setDays ] = useState( 30 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		setData( null );
		setError( null );
		api( `/admin/audience/campaigns?days=${ days }` )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [ days ] );

	// Nessuna visita con UTM nel periodo: tutti e tre gli elenchi sono vuoti.
	const empty = data && LISTS.every( ( { key } ) => data.dims[ key ].total === 0 );

	return (
		<Card
			id="audience-campaigns"
			title={
				<>
					{ __( 'Campagne', 'wp-5w' ) }
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
			{ data && (
				<div className="wp5w-columns wp5w-dimensions">
					{ LISTS.map( ( list ) => (
						<DimensionList key={ list.key } title={ list.title } dim={ data.dims[ list.key ] } label={ ( value ) => value } />
					) ) }
				</div>
			) }
			{ data && (
				<p className="wp5w-note">
					{ data.first_day
						? /* translators: %s: primo giorno della raccolta propria (gg/mm/aaaa). */ sprintf( __( 'Dati della sola raccolta propria, disponibili dal %s: WP Statistics non salva i parametri UTM.', 'wp-5w' ), formatDay( data.first_day ) )
						: __( 'Dati della sola raccolta propria: WP Statistics non salva i parametri UTM.', 'wp-5w' ) }{ ' ' }
					{ __( 'Un visitatore conta una volta per ogni valore e per ogni giorno; chi arriva senza parametri UTM non compare.', 'wp-5w' ) }
					{ ! data.first_day && ` ${ __( 'La raccolta propria non ha ancora dati.', 'wp-5w' ) }` }
					{ empty && data.first_day && ` ${ __( 'Nessuna visita con parametri UTM nel periodo.', 'wp-5w' ) }` }
				</p>
			) }
		</Card>
	);
}
