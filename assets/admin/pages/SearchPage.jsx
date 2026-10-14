import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { api, formatDay, formatNumber, shiftDay, today } from '../api';
import KpiCard from '../components/KpiCard';
import TrendChart from '../components/TrendChart';
import QueryTable from '../components/QueryTable';
import PostDetailModal from '../components/PostDetailModal';
import ShowMore from '../components/ShowMore';

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
	const [ detail, setDetail ] = useState( null );

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

					<section className="wp5w-card">
						<h2>{ __( 'Andamento', 'wp-5w' ) }</h2>
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
					</section>

					<section className="wp5w-card">
						<div className="wp5w-card__head">
							<h2>{ __( 'Query', 'wp-5w' ) }</h2>
							<SearchControl
								__nextHasNoMarginBottom
								label={ __( 'Filtra le query', 'wp-5w' ) }
								value={ filter }
								onChange={ setFilter }
							/>
						</div>
						<ShowMore rows={ queries }>
							{ ( rows ) => <QueryTable rows={ rows } extra change={ data.comparable } /> }
						</ShowMore>
						<p className="wp5w-note">
							{ data.comparable
								? __( 'Variazione: differenza di click rispetto al periodo precedente.', 'wp-5w' )
								: __( 'Nessun confronto: il periodo precedente non è coperto dai dati di Search Console.', 'wp-5w' ) }
						</p>
					</section>

					<div className="wp5w-columns">
						<section className="wp5w-card">
							<h2>{ __( 'Quasi in prima pagina', 'wp-5w' ) }</h2>
							<p className="wp5w-note">
								{ __(
									'Posizione media tra 8 e 20, almeno 20 impression: basta poco per salire.',
									'wp-5w'
								) }
							</p>
							<ShowMore
								rows={ data.opportunities.near_first }
								size={ 10 }
							>
								{ ( rows ) => (
									<QueryTable
										rows={ rows }
										empty={ __(
											'Nessuna query in questa fascia.',
											'wp-5w'
										) }
									/>
								) }
							</ShowMore>
						</section>
						<section className="wp5w-card">
							<h2>
								{ __( 'In alto ma poco cliccate', 'wp-5w' ) }
							</h2>
							<p className="wp5w-note">
								{ __(
									'Posizione fino a 5 e CTR sotto il 2%: titolo e descrizione da rivedere.',
									'wp-5w'
								) }
							</p>
							<ShowMore
								rows={ data.opportunities.low_ctr }
								size={ 10 }
							>
								{ ( rows ) => (
									<QueryTable
										rows={ rows }
										empty={ __(
											'Nessuna query in questa fascia.',
											'wp-5w'
										) }
									/>
								) }
							</ShowMore>
						</section>
					</div>

					<section className="wp5w-card">
						<h2>{ __( 'Pagine', 'wp-5w' ) }</h2>
						<ShowMore rows={ data.pages }>
							{ ( pages ) => (
								<table className="widefat striped wp5w-table">
									<thead>
										<tr>
											<th>{ __( 'Pagina', 'wp-5w' ) }</th>
											<th className="num">
												{ __( 'Click', 'wp-5w' ) }
											</th>
											<th className="num">
												{ __( 'Impression', 'wp-5w' ) }
											</th>
											<th className="num">
												{ __( 'CTR', 'wp-5w' ) }
											</th>
											<th className="num">
												{ __( 'Posizione', 'wp-5w' ) }
											</th>
											<th className="num">
												{ __( 'Query', 'wp-5w' ) }
											</th>
										</tr>
									</thead>
									<tbody>
										{ pages.map( ( row ) => (
											<tr key={ row.page }>
												<td>
													{ row.id > 0 ? (
														<Button
															variant="link"
															onClick={ () =>
																setDetail( {
																	id: row.id,
																	title: row.title,
																} )
															}
														>
															{ row.title }
														</Button>
													) : (
														row.title
													) }
													<a
														className="wp5w-edit"
														href={ row.url }
														target="_blank"
														rel="noreferrer"
													>
														{ __(
															'Apri',
															'wp-5w'
														) }
													</a>
												</td>
												<td className="num">
													{ formatNumber(
														row.clicks
													) }
												</td>
												<td className="num">
													{ formatNumber(
														row.impressions
													) }
												</td>
												<td className="num">
													{ formatNumber(
														row.ctr,
														'%'
													) }
												</td>
												<td className="num">
													{ formatNumber(
														row.position
													) }
												</td>
												<td className="num">
													{ formatNumber(
														row.queries
													) }
												</td>
											</tr>
										) ) }
									</tbody>
								</table>
							) }
						</ShowMore>
					</section>
				</>
			) }

			{ detail && (
				<PostDetailModal
					post={ detail }
					initialPeriod="90"
					onClose={ () => setDetail( null ) }
				/>
			) }
		</div>
	);
}
