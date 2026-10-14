import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';
import Card from './Card';
import ShowMore from './ShowMore';

// Periodi: ultimi N giorni disponibili in Search Console (come SearchQuery::DAYS).
const DAYS_OPTIONS = [ 7, 28, 90, 180 ].map( ( days ) => ( {
	value: String( days ),
	label: `${ days } ${ __( 'giorni', 'wp-5w' ) }`,
} ) );

// Tipi di opportunità: etichetta e spiegazione (vedi SearchQuery::opportunities()).
const KINDS = {
	near_first: {
		label: __( 'Quasi in prima pagina', 'wp-5w' ),
		hint: __( 'posizione media tra 8 e 20: basta poco per salire', 'wp-5w' ),
	},
	low_ctr: {
		label: __( 'Poco cliccata', 'wp-5w' ),
		hint: __( 'posizione fino a 5 ma CTR sotto il 2%: titolo e descrizione da rivedere', 'wp-5w' ),
	},
};

/**
 * Pagine o opportunità degli ultimi giorni disponibili, ricaricate quando cambia il periodo.
 *
 * @param {string} part 'pages' o 'opportunities'.
 * @param {string} days Numero di giorni.
 */
const useSearchPart = ( part, days ) => {
	const [ state, setState ] = useState( { data: null, error: null } );
	useEffect( () => {
		setState( { data: null, error: null } );
		api( `/admin/search/${ part }?days=${ days }` )
			.then( ( data ) => setState( { data, error: null } ) )
			.catch( ( e ) => setState( { data: null, error: e.message } ) );
	}, [ part, days ] );
	return state;
};

/**
 * Titolo della card con il periodo effettivo dei dati.
 *
 * @param {Object}  props
 * @param {string}  props.text  Titolo.
 * @param {?Object} props.range { from, to } restituito dal server.
 */
function Title( { text, range } ) {
	return (
		<>
			{ text }{ ' ' }
			{ range && (
				<span className="wp5w-type">
					{ formatDay( range.from ) } – { formatDay( range.to ) }
				</span>
			) }
		</>
	);
}

/**
 * Selettore del numero di giorni nell'intestazione della card.
 *
 * @param {Object}   props
 * @param {string}   props.value    Giorni.
 * @param {Function} props.onChange Cambio del periodo.
 */
function DaysSelect( { value, onChange } ) {
	return (
		<SelectControl
			__nextHasNoMarginBottom
			label={ __( 'Giorni', 'wp-5w' ) }
			hideLabelFromVision
			value={ value }
			options={ DAYS_OPTIONS }
			onChange={ onChange }
		/>
	);
}

/**
 * Stato di caricamento o errore, altrimenti il contenuto.
 *
 * @param {Object}   props
 * @param {Object}   props.state    { data, error }.
 * @param {Function} props.children ( data ) => contenuto.
 */
function Loaded( { state, children } ) {
	if ( state.error ) {
		return <Notice status="error" isDismissible={ false }>{ state.error }</Notice>;
	}
	return state.data ? children( state.data ) : <Spinner />;
}

/**
 * Tabella delle pagine di Search Console; il titolo apre il dettaglio quando la pagina è collegata a un contenuto.
 *
 * @param {Object}   props
 * @param {Array}    props.pages  Pagine.
 * @param {Function} props.onOpen Apertura del dettaglio.
 */
function PagesTable( { pages, onOpen } ) {
	if ( ! pages.length ) {
		return <p>{ __( 'Nessuna pagina nel periodo.', 'wp-5w' ) }</p>;
	}

	return (
		<table className="widefat striped wp5w-table">
			<thead>
				<tr>
					<th>{ __( 'Pagina', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Click', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Impression', 'wp-5w' ) }</th>
					<th className="num">{ __( 'CTR', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Posizione', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Query', 'wp-5w' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ pages.map( ( row ) => (
					<tr key={ row.page }>
						<td>
							{ row.id > 0 ? (
								<Button variant="link" onClick={ () => onOpen( { id: row.id, title: row.title } ) }>
									{ row.title }
								</Button>
							) : (
								row.title
							) }
							<a className="wp5w-edit" href={ row.url } target="_blank" rel="noreferrer">
								{ __( 'Apri', 'wp-5w' ) }
							</a>
						</td>
						<td className="num">{ formatNumber( row.clicks ) }</td>
						<td className="num">{ formatNumber( row.impressions ) }</td>
						<td className="num">{ formatNumber( row.ctr, '%' ) }</td>
						<td className="num">{ formatNumber( row.position ) }</td>
						<td className="num">{ formatNumber( row.queries ) }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * Opportunità in un'unica tabella: il tipo è un'etichetta colorata e la riga ha un bordo dello stesso colore.
 *
 * @param {Object} props
 * @param {Array}  props.rows Opportunità { kind, query, clicks, impressions, ctr, position, pages }.
 */
function OpportunitiesTable( { rows } ) {
	if ( ! rows.length ) {
		return <p>{ __( 'Nessuna opportunità nel periodo.', 'wp-5w' ) }</p>;
	}

	return (
		<table className="widefat striped wp5w-table wp5w-opportunities">
			<thead>
				<tr>
					<th>{ __( 'Query', 'wp-5w' ) }</th>
					<th>{ __( 'Tipo', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Click', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Impression', 'wp-5w' ) }</th>
					<th className="num">{ __( 'CTR', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Posizione', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Pagine', 'wp-5w' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<tr key={ row.query } className={ `is-${ row.kind }` }>
						<td>{ row.query }</td>
						<td>
							<span className={ `wp5w-badge is-${ row.kind }` }>{ KINDS[ row.kind ]?.label ?? row.kind }</span>
						</td>
						<td className="num">{ formatNumber( row.clicks ) }</td>
						<td className="num">{ formatNumber( row.impressions ) }</td>
						<td className="num">{ formatNumber( row.ctr, '%' ) }</td>
						<td className="num">{ formatNumber( row.position ) }</td>
						<td className="num">{ formatNumber( row.pages ) }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * Box di Search Console della Panoramica: opportunità e pagine, ciascuno con il proprio periodo.
 *
 * @param {Object}   props
 * @param {Function} props.onOpen Apertura del dettaglio di un contenuto.
 */
export default function SearchContent( { onOpen } ) {
	const [ oppDays, setOppDays ] = useState( '28' );
	const [ pageDays, setPageDays ] = useState( '28' );
	const opportunities = useSearchPart( 'opportunities', oppDays );
	const pages = useSearchPart( 'pages', pageDays );

	return (
		<>
			<Card
				id="gsc-opportunities"
				title={ <Title text={ __( 'Opportunità su Google', 'wp-5w' ) } range={ opportunities.data?.range } /> }
				actions={ <DaysSelect value={ oppDays } onChange={ setOppDays } /> }
			>
				<Loaded state={ opportunities }>
					{ ( data ) => (
						<ShowMore rows={ data.rows } size={ 15 }>
							{ ( rows ) => <OpportunitiesTable rows={ rows } /> }
						</ShowMore>
					) }
				</Loaded>
				<p className="wp5w-note">
					{ Object.values( KINDS ).map( ( kind ) => (
						<span key={ kind.label } className="wp5w-legend">
							<strong>{ kind.label }</strong>: { kind.hint }.
						</span>
					) ) }{ ' ' }
					{ __( 'Almeno 20 impression; ordinate per impression.', 'wp-5w' ) }
				</p>
			</Card>

			<Card
				id="gsc-pages"
				title={ <Title text={ __( 'Pagine su Google', 'wp-5w' ) } range={ pages.data?.range } /> }
				actions={ <DaysSelect value={ pageDays } onChange={ setPageDays } /> }
			>
				<Loaded state={ pages }>
					{ ( data ) => <ShowMore rows={ data.rows }>{ ( rows ) => <PagesTable pages={ rows } onOpen={ onOpen } /> }</ShowMore> }
				</Loaded>
				<p className="wp5w-note">
					{ __( 'Ultimi giorni disponibili in Search Console, che pubblica i dati con 2-3 giorni di ritardo.', 'wp-5w' ) }
				</p>
			</Card>
		</>
	);
}
