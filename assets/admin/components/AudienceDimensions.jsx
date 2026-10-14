import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';
import Card from './Card';

// Periodi selezionabili (ultimi N giorni, compreso oggi).
const PERIODS = [
	{ days: 7, label: __( '7 giorni', 'wp-5w' ) },
	{ days: 30, label: __( '30 giorni', 'wp-5w' ) },
	{ days: 90, label: __( '90 giorni', 'wp-5w' ) },
];

// Valore non riconosciuto: stesso testo in tutti gli elenchi.
const UNKNOWN = '(n/d)';

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
 * Un elenco con barra proporzionale (stesse classi di ChannelList).
 *
 * @param {Object}   props
 * @param {string}   props.title Titolo dell'elenco.
 * @param {Object}   props.dim   { total, rows, other }.
 * @param {Function} props.label Etichetta di un valore.
 */
function DimensionList( { title, dim, label } ) {
	const share = ( visitors ) => Math.round( ( visitors / dim.total ) * 1000 ) / 10;
	const rows = dim.other > 0 ? [ ...dim.rows, { value: null, visitors: dim.other } ] : dim.rows;

	return (
		<div>
			<h3>{ title }</h3>
			{ dim.total > 0 ? (
				<ul className="wp5w-channels">
					{ rows.map( ( row ) => (
						<li key={ row.value ?? 'other' }>
							<div className="wp5w-channels__row">
								<span>{ row.value === null ? __( 'Altri', 'wp-5w' ) : row.value === UNKNOWN ? __( 'Non riconosciuto', 'wp-5w' ) : label( row.value ) }</span>
								<span className="wp5w-channels__value">
									{ formatNumber( row.visitors ) } ({ formatNumber( share( row.visitors ), '%' ) })
								</span>
							</div>
							<span className="wp5w-channels__bar" style={ { width: `${ share( row.visitors ) }%` } } />
						</li>
					) ) }
				</ul>
			) : (
				<p>{ __( 'Nessun dato nel periodo.', 'wp-5w' ) }</p>
			) }
		</div>
	);
}

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
			actions={
				<div className="wp5w-switch">
					{ PERIODS.map( ( period ) => (
						<Button key={ period.days } size="compact" variant={ days === period.days ? 'primary' : 'secondary' } onClick={ () => setDays( period.days ) }>
							{ period.label }
						</Button>
					) ) }
				</div>
			}
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
