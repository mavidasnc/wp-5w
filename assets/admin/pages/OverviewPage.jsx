import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber, shiftDay, today } from '../api';
import PeriodSwitch, { PERIODS } from '../components/PeriodSwitch';
import KpiCard from '../components/KpiCard';
import TrendChart, { TOOL_KEYS } from '../components/TrendChart';
import TopContents from '../components/TopContents';
import SearchContent from '../components/SearchContent';
import ChannelList from '../components/ChannelList';
import PostDetailModal from '../components/PostDetailModal';
import { TrendCard } from '../components/Trend';
import Alerts from '../components/Alerts';

// Preset del periodo: i soliti (Oggi, 7, 30 e 90 giorni), poi l'ultimo anno e tutto lo storico del sito (ALL_HISTORY).
const ALL_HISTORY = 0;
const OVERVIEW_PERIODS = [
	...PERIODS,
	{ days: 365, label: __( '1 anno', 'wp-5w' ) },
	{ days: ALL_HISTORY, label: __( 'Tutto', 'wp-5w' ) },
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
 * @param {number} period Giorni del preset, ALL_HISTORY per tutto lo storico.
 */
const rangeFor = ( period ) => ( {
	from: period === ALL_HISTORY ? HISTORY_FROM : shiftDay( today, -( period - 1 ) ),
	to: today,
} );

export default function OverviewPage() {
	const [ period, setPeriod ] = useState( 30 );
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
				<PeriodSwitch days={ period } onChange={ setPeriod } periods={ OVERVIEW_PERIODS } />
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

					<TrendCard trend={ data.trend } />

					<div className="wp5w-grid">
						<section className="wp5w-card wp5w-grid__main">
							<h2>{ __( 'Andamento', 'wp-5w' ) }</h2>
							<TrendChart series={ data.series.map( ( point, i ) => ( { ...point, ...data.tools[ i ] } ) ) } keys={ TOOL_KEYS } />
							<p className="wp5w-note">
								{ __( 'Search Console disponibile dal', 'wp-5w' ) } { formatDay( data.coverage.gsc_daily.from ) }
								{ '; ' }
								{ __( 'gli ultimi 2-3 giorni sono incompleti.', 'wp-5w' ) }
								{ ' ' }
								{ __( 'Una linea per ogni strumento di statistiche: clicca sulla legenda per mostrarle o nasconderle (le visualizzazioni sono tratteggiate e nascoste all\'inizio).', 'wp-5w' ) }
							</p>
						</section>
						<section className="wp5w-card">
							<h2>{ __( 'Canali', 'wp-5w' ) }</h2>
							<ChannelList channels={ data.channels } />
							{ data.no_js && (
								<p className="wp5w-note">
									{ sprintf(
										/* translators: 1: visitatori senza JavaScript, 2: giorni coperti dai log, 3: giorni del periodo. */
										__( 'Senza JavaScript, dai log del server: %1$s visitatori in %2$s giorni su %3$s del periodo, fuori dalle quote qui sopra. Il tracker non li vede: sono soprattutto crawler, ma anche lettori che tornano con la pagina già in cache e non interagiscono. Dettaglio nella scheda Stato.', 'wp-5w' ),
										formatNumber( data.no_js.visitors ),
										formatNumber( data.no_js.days ),
										formatNumber( data.no_js.period_days )
									) }
								</p>
							) }
						</section>
					</div>

					{ /* Subito sotto i grafici di andamento: l'ultimo calcolo orario, indipendente dal periodo scelto. */ }
					<Alerts />

					<TopContents range={ data.range } onOpen={ setDetail } />
					<SearchContent onOpen={ setDetail } />
				</>
			) }

			{ detail && <PostDetailModal post={ detail } initialPeriod={ 30 } onClose={ () => setDetail( null ) } /> }
		</div>
	);
}
