import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { api, formatDay } from '../api';
import Card from './Card';
import DimensionList from './DimensionList';
import PeriodSwitch from './PeriodSwitch';

// Etichette dei valori di WP Statistics (device-detector) che hanno un nome più chiaro; gli altri restano come sono.
const DEVICES = {
	smartphone: __( 'Smartphone', 'wp-5w' ),
	desktop: __( 'Desktop', 'wp-5w' ),
	tablet: __( 'Tablet', 'wp-5w' ),
	phablet: __( 'Phablet', 'wp-5w' ),
	tv: __( 'TV', 'wp-5w' ),
};
const SYSTEMS = {
	Mac: __( 'macOS', 'wp-5w' ),
	'GNU/Linux': __( 'Linux', 'wp-5w' ),
};

// Nome del paese in italiano, seguito dal codice («Italia (IT)»); il codice da solo se Intl non lo conosce.
const regionNames = typeof Intl !== 'undefined' && Intl.DisplayNames ? new Intl.DisplayNames( [ 'it' ], { type: 'region' } ) : null;
const countryLabel = ( code ) => {
	try {
		const name = regionNames ? regionNames.of( code ) : null;
		return name && name !== code ? `${ name } (${ code })` : code;
	} catch {
		return code;
	}
};

// I quattro elenchi: chiave della risposta, titolo e funzione che dà l'etichetta di un valore.
const LISTS = [
	{ key: 'country', title: __( 'Paesi', 'wp-5w' ), label: countryLabel },
	{ key: 'device', title: __( 'Dispositivi', 'wp-5w' ), label: ( value ) => DEVICES[ value ] ?? value },
	{ key: 'browser', title: __( 'Browser', 'wp-5w' ), label: ( value ) => value },
	{ key: 'platform', title: __( 'Sistemi operativi', 'wp-5w' ), label: ( value ) => SYSTEMS[ value ] ?? value },
];

/**
 * Box «Pubblico»: paesi, dispositivi, browser e sistemi operativi degli ultimi N giorni; carica da /admin/audience/dimensions.
 */
export default function AudienceDimensions() {
	const [ days, setDays ] = useState( 30 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		setData( null );
		setError( null );
		api( `/admin/audience/dimensions?days=${ days }` )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [ days ] );

	const empty = data && LISTS.every( ( { key } ) => data.dims[ key ].total === 0 );

	return (
		<Card
			id="audience-dimensions"
			title={
				<>
					{ __( 'Pubblico', 'wp-5w' ) }
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
						<DimensionList key={ list.key } title={ list.title } dim={ data.dims[ list.key ] } label={ list.label } />
					) ) }
				</div>
			) }
			{ data && (
				<p className="wp5w-note">
					{ __( 'Visitatori sommati sui giorni del periodo: una persona conta una volta per ogni giorno di visita.', 'wp-5w' ) }
					{ data.first_day && data.first_day > data.range.from && ` ${ __( 'Dati disponibili dal', 'wp-5w' ) } ${ formatDay( data.first_day ) }.` }
					{ empty && ` ${ __( 'Nessun dato nel periodo.', 'wp-5w' ) }` }
				</p>
			) }
		</Card>
	);
}
