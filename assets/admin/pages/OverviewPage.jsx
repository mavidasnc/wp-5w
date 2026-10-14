import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, SelectControl, Spinner } from '@wordpress/components';
import { api, formatDay, shiftDay, today } from '../api';
import KpiCard from '../components/KpiCard';
import TrendChart from '../components/TrendChart';
import TopPostsTable from '../components/TopPostsTable';
import ChannelList from '../components/ChannelList';
import PostDetailModal from '../components/PostDetailModal';

// Preset del periodo: giorni fino a oggi, oppure tutto lo storico del sito.
const PERIODS = [
	{ value: '7', label: __( 'Ultimi 7 giorni', 'wp-5w' ) },
	{ value: '30', label: __( 'Ultimi 30 giorni', 'wp-5w' ) },
	{ value: '90', label: __( 'Ultimi 90 giorni', 'wp-5w' ) },
	{ value: '365', label: __( 'Ultimo anno', 'wp-5w' ) },
	{ value: 'all', label: __( 'Tutto lo storico', 'wp-5w' ) },
];

// Primo giorno con dati di traffico sul blog (i totali di WP Statistics partono dal 2023).
const HISTORY_FROM = '2023-01-01';

const KPIS = [
	{ key: 'visitors', label: __( 'Visitatori', 'wp-5w' ) },
	{ key: 'views', label: __( 'Visualizzazioni', 'wp-5w' ) },
	{ key: 'clicks', label: __( 'Click da Google', 'wp-5w' ) },
	{ key: 'impressions', label: __( 'Impression su Google', 'wp-5w' ) },
	{ key: 'ctr', label: __( 'CTR', 'wp-5w' ), suffix: '%' },
	// La posizione migliora quando scende: la variazione va letta al contrario.
	{ key: 'position', label: __( 'Posizione media', 'wp-5w' ), inverse: true },
];

/**
 * Intervallo del preset scelto.
 *
 * @param {string} period Valore del preset.
 */
const rangeFor = ( period ) => ( {
	from: period === 'all' ? HISTORY_FROM : shiftDay( today, -( Number( period ) - 1 ) ),
	to: today,
} );

export default function OverviewPage() {
	const [ period, setPeriod ] = useState( '30' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ detail, setDetail ] = useState( null );

	useEffect( () => {
		const { from, to } = rangeFor( period );
		setData( null );
		setError( null );
		api( `/admin/overview?from=${ from }&to=${ to }` )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [ period ] );

	return (
		<div className="wp5w-overview">
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
						{ __( 'Confronto con', 'wp-5w' ) } { formatDay( data.previous.from ) } – { formatDay( data.previous.to ) }
					</p>
				) }
			</div>

			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }

			{ data && (
				<>
					<div className="wp5w-kpis">
						{ KPIS.map( ( { key, ...kpi } ) => (
							<KpiCard key={ key } { ...kpi } data={ data.kpis[ key ] } />
						) ) }
					</div>

					<div className="wp5w-grid">
						<section className="wp5w-card wp5w-grid__main">
							<h2>{ __( 'Andamento', 'wp-5w' ) }</h2>
							<TrendChart series={ data.series } />
							<p className="wp5w-note">
								{ __( 'Search Console disponibile dal', 'wp-5w' ) } { formatDay( data.coverage.gsc_daily.from ) }
								{ '; ' }
								{ __( 'gli ultimi 2-3 giorni sono incompleti.', 'wp-5w' ) }
							</p>
						</section>
						<section className="wp5w-card">
							<h2>{ __( 'Canali', 'wp-5w' ) }</h2>
							<ChannelList channels={ data.channels } />
						</section>
					</div>

					<section className="wp5w-card">
						<h2>{ __( 'Contenuti più visti', 'wp-5w' ) }</h2>
						<TopPostsTable rows={ data.top } onOpen={ setDetail } />
					</section>
				</>
			) }

			{ detail && <PostDetailModal post={ detail } initialPeriod="30" onClose={ () => setDetail( null ) } /> }
		</div>
	);
}
