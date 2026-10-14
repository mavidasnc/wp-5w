import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { api, formatDay, shiftDay, today } from '../api';
import KpiCard from '../components/KpiCard';
import TrendChart from '../components/TrendChart';
import QueryTable from '../components/QueryTable';
import ShowMore from '../components/ShowMore';
import Card from '../components/Card';

// Periodi: 28 giorni come in Search Console; "all" parte dal primo giorno disponibile.
const PERIODS = [
	{ value: '7', label: __( 'Ultimi 7 giorni', 'wp-5w' ) },
	{ value: '28', label: __( 'Ultimi 28 giorni', 'wp-5w' ) },
	{ value: '90', label: __( 'Ultimi 90 giorni', 'wp-5w' ) },
	{ value: '180', label: __( 'Ultimi 180 giorni', 'wp-5w' ) },
	{ value: 'all', label: __( 'Tutto lo storico', 'wp-5w' ) },
];

const KPIS = [
	{ key: 'clicks', label: __( 'Click', 'wp-5w' ) },
	{ key: 'impressions', label: __( 'Impression', 'wp-5w' ) },
	{ key: 'ctr', label: __( 'CTR', 'wp-5w' ), suffix: '%' },
	// La posizione migliora quando scende.
	{ key: 'position', label: __( 'Posizione media', 'wp-5w' ), inverse: true },
];

// "Tutto lo storico": il server accorcia il periodo al primo giorno con dati.
const HISTORY_FROM = '2016-01-01';

/**
 * Intervallo del periodo scelto.
 *
 * @param {string} period Valore del periodo.
 */
const rangeFor = ( period ) => ( {
	from:
		period === 'all'
			? HISTORY_FROM
			: shiftDay( today, -( Number( period ) - 1 ) ),
	to: today,
} );

export default function SearchPage() {
	const [ period, setPeriod ] = useState( '28' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ filter, setFilter ] = useState( '' );

	useEffect( () => {
		const { from, to } = rangeFor( period );
		setData( null );
		setError( null );
		api( `/admin/search?from=${ from }&to=${ to }` )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [ period ] );

	// Filtro di testo sulle query, lato client.
	const queries = useMemo( () => {
		if ( ! data ) {
			return [];
		}
		const needle = filter.trim().toLowerCase();
		return needle
			? data.queries.filter( ( row ) =>
					row.query.toLowerCase().includes( needle )
			  )
			: data.queries;
	}, [ data, filter ] );

	return (
		<div className="wp5w-search">
			<div className="wp5w-toolbar">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Periodo', 'wp-5w' ) }
					value={ period }
					options={ PERIODS }
					onChange={ setPeriod }
				/>
				{ data && (
					<p className="wp5w-toolbar__compare">
						{ data.comparable && (
							<>
								{ __( 'Confronto con', 'wp-5w' ) } { formatDay( data.previous.from ) } – { formatDay( data.previous.to ) }
								{ ' · ' }
							</>
						) }
						{ __( 'Dati disponibili', 'wp-5w' ) }{ ' ' }
						{ formatDay( data.coverage.from ) } –{ ' ' }
						{ formatDay( data.coverage.to ) } (
						{ data.coverage.days } { __( 'giorni', 'wp-5w' ) })
					</p>
				) }
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! data && ! error && <Spinner /> }

			{ data && (
				<>
					<div className="wp5w-kpis">
						{ KPIS.map( ( { key, ...kpi } ) => (
							<KpiCard
								key={ key }
								{ ...kpi }
								data={ data.kpis[ key ] }
							/>
						) ) }
					</div>

					<Card id="gsc-trend" title={ __( 'Andamento', 'wp-5w' ) }>
						<TrendChart
							series={ data.series }
							keys={ [ 'clicks', 'impressions' ] }
						/>
						<p className="wp5w-note">
							{ __(
								"Gli ultimi 2-3 giorni sono incompleti. I totali escludono le query anonimizzate da Google, quindi sono più bassi di quelli dell'interfaccia di Search Console.",
								'wp-5w'
							) }
						</p>
					</Card>

					<Card
						id="gsc-queries"
						title={ __( 'Query', 'wp-5w' ) }
						actions={
							<SearchControl
								__nextHasNoMarginBottom
								label={ __( 'Filtra le query', 'wp-5w' ) }
								value={ filter }
								onChange={ setFilter }
							/>
						}
					>
						<ShowMore rows={ queries }>
							{ ( rows ) => <QueryTable rows={ rows } extra change={ data.comparable } /> }
						</ShowMore>
						<p className="wp5w-note">
							{ data.comparable
								? __( 'Variazione: differenza di click rispetto al periodo precedente.', 'wp-5w' )
								: __( 'Nessun confronto: il periodo precedente non è coperto dai dati di Search Console.', 'wp-5w' ) }
						</p>
					</Card>
				</>
			) }
		</div>
	);
}
