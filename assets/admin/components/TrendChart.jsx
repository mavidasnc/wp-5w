import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Chart as ChartJS,
	CategoryScale,
	Legend,
	LinearScale,
	LineElement,
	PointElement,
	Tooltip,
} from 'chart.js';
import { Line } from 'react-chartjs-2';
import { formatDay } from '../api';

ChartJS.register( CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend );

// Oltre queste soglie i punti giornalieri diventano illeggibili: si raggruppa per settimana o mese.
const WEEKLY_FROM = 120;
const MONTHLY_FROM = 730;

// Colore per strumento: le serie dello stesso strumento hanno lo stesso colore, le visualizzazioni sono tratteggiate.
const TOOLS = {
	wps: { name: __( 'WP Statistics', 'wp-5w' ), color: '#2271b1' },
	own: { name: __( 'Raccolta propria', 'wp-5w' ), color: '#d63638' },
	ia: { name: __( 'Independent Analytics', 'wp-5w' ), color: '#7e57c2' },
	// Solo nel Confronto della scheda Stato (la Panoramica non ha questa serie e la legenda la nasconde).
	log: { name: __( 'Log del server', 'wp-5w' ), color: '#e65100' },
};

const SERIES = [
	{ key: 'views', label: __( 'Visualizzazioni', 'wp-5w' ), color: '#2271b1', axis: 'traffic' },
	{ key: 'visitors', label: __( 'Visitatori', 'wp-5w' ), color: '#72aee6', axis: 'traffic' },
	// Una coppia di serie (visitatori e visualizzazioni) per ogni strumento: le chiavi arrivano dalla risposta della Panoramica (`tools`).
	...Object.entries( TOOLS ).flatMap( ( [ id, tool ] ) => [
		{ key: `visitors_${ id }`, label: `${ __( 'Visitatori', 'wp-5w' ) } ${ tool.name }`, color: tool.color, axis: 'traffic' },
		{ key: `views_${ id }`, label: `${ __( 'Visualizzazioni', 'wp-5w' ) } ${ tool.name }`, color: tool.color, axis: 'traffic', dashed: true, hidden: true },
	] ),
	{ key: 'clicks', label: __( 'Click Google', 'wp-5w' ), color: '#00a32a', axis: 'gsc' },
	{ key: 'impressions', label: __( 'Impression Google', 'wp-5w' ), color: '#dba617', axis: 'gsc', hidden: true },
];

/**
 * Chiavi delle serie dei tre strumenti più quelle di Search Console (per la Panoramica e il Confronto).
 */
export const TOOL_KEYS = [ ...Object.keys( TOOLS ).flatMap( ( id ) => [ `visitors_${ id }`, `views_${ id }` ] ), 'clicks', 'impressions' ];

/**
 * Raggruppa i punti giornalieri sommando i valori per chiave (settimana o mese).
 *
 * @param {Array}    series Punti giornalieri.
 * @param {Function} keyOf  Funzione giorno -> chiave del gruppo.
 */
const group = ( series, keyOf ) => {
	const groups = new Map();
	series.forEach( ( point ) => {
		const key = keyOf( point.day );
		const current = groups.get( key ) ?? { day: key };
		// I valori null (strumento non ancora attivo) non si sommano: un gruppo senza nessun valore resta null, non zero.
		SERIES.forEach( ( { key: field } ) => {
			if ( point[ field ] !== null && point[ field ] !== undefined ) {
				current[ field ] = ( current[ field ] ?? 0 ) + point[ field ];
			} else if ( ! ( field in current ) ) {
				current[ field ] = null;
			}
		} );
		groups.set( key, current );
	} );
	return [ ...groups.values() ];
};

/**
 * Lunedì della settimana di un giorno Y-m-d.
 *
 * @param {string} day Giorno.
 */
const mondayOf = ( day ) => {
	const date = new Date( `${ day }T12:00:00Z` );
	date.setUTCDate( date.getUTCDate() - ( ( date.getUTCDay() + 6 ) % 7 ) );
	return date.toISOString().slice( 0, 10 );
};

/**
 * Grafico del traffico (asse sinistro) e di Search Console (asse destro).
 * Le serie si mostrano e nascondono cliccando sulla legenda.
 *
 * @param {Object} props
 * @param {Array}  props.series Punti giornalieri { day, visitors, views, clicks, impressions e, per strumento, visitors_wps, views_own... }.
 * @param {Array}  props.keys   Serie da mostrare (predefinito: tutte).
 */
export default function TrendChart( { series, keys = null } ) {
	const { points, unit } = useMemo( () => {
		if ( series.length >= MONTHLY_FROM ) {
			return { points: group( series, ( day ) => day.slice( 0, 7 ) ), unit: 'month' };
		}
		if ( series.length >= WEEKLY_FROM ) {
			return { points: group( series, mondayOf ), unit: 'week' };
		}
		return { points: series, unit: 'day' };
	}, [ series ] );

	const label = ( day ) => ( unit === 'month' ? day.split( '-' ).reverse().join( '/' ) : formatDay( day ) );

	const data = {
		labels: points.map( ( point ) => label( point.day ) ),
		// Una serie senza nessun valore (strumento non installato) non compare nemmeno nella legenda.
		datasets: SERIES.filter( ( serie ) => ( ! keys || keys.includes( serie.key ) ) && points.some( ( point ) => point[ serie.key ] != null ) ).map( ( serie ) => ( {
			label: serie.label,
			data: points.map( ( point ) => point[ serie.key ] ),
			borderColor: serie.color,
			backgroundColor: serie.color,
			yAxisID: serie.axis,
			hidden: serie.hidden ?? false,
			borderDash: serie.dashed ? [ 6, 4 ] : [],
			// Un giorno senza dato (null) interrompe la linea invece di scendere a zero.
			spanGaps: false,
			tension: 0.25,
			pointRadius: points.length > 60 ? 0 : 2,
			borderWidth: 2,
		} ) ),
	};

	const options = {
		responsive: true,
		maintainAspectRatio: false,
		interaction: { mode: 'index', intersect: false },
		plugins: { legend: { position: 'bottom' } },
		scales: {
			traffic: { type: 'linear', position: 'left', beginAtZero: true, display: 'auto' },
			gsc: { type: 'linear', position: 'right', beginAtZero: true, display: 'auto', grid: { drawOnChartArea: false } },
		},
	};

	return (
		<div className="wp5w-chart">
			<Line data={ data } options={ options } />
			{ unit !== 'day' && (
				<p className="wp5w-note">
					{ unit === 'week'
						? __( 'Valori sommati per settimana.', 'wp-5w' )
						: __( 'Valori sommati per mese.', 'wp-5w' ) }
				</p>
			) }
		</div>
	);
}
