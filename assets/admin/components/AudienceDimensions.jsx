import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { api, formatDay } from '../api';
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
	{ key: 'country', title: __( 'Pubblico (paesi)', 'wp-5w' ), label: countryLabel },
	{ key: 'device', title: __( 'Dispositivi', 'wp-5w' ), label: ( value ) => DEVICES[ value ] ?? value },
	{ key: 'browser', title: __( 'Browser', 'wp-5w' ), label: ( value ) => value },
	{ key: 'platform', title: __( 'Sistemi operativi', 'wp-5w' ), label: ( value ) => SYSTEMS[ value ] ?? value },
];

/**
 * Quattro box affiancati (paesi, dispositivi, browser e sistemi operativi) degli ultimi N giorni, con un solo
 * selettore del periodo e una sola chiamata a /admin/audience/dimensions per ogni cambio.
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
		<div className="wp5w-audience-dims">
			{ /* Barra sopra la riga: intervallo (solo con i dati) e selettore del periodo. */ }
			<div className="wp5w-card__head">
				<span className="wp5w-type">
					{ data && `${ formatDay( data.range.from ) } – ${ formatDay( data.range.to ) }` }
				</span>
				<PeriodSwitch days={ days } onChange={ setDays } />
			</div>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && (
				<div className="wp5w-columns wp5w-columns--4">
					{ LISTS.map( ( list ) => (
						<section key={ list.key } className="wp5w-card wp5w-dimensions">
							<DimensionList title={ list.title } dim={ data.dims[ list.key ] } label={ list.label } />
						</section>
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
		</div>
	);
}
