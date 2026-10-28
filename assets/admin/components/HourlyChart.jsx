import { __, sprintf } from '@wordpress/i18n';
import {
	BarController,
	BarElement,
	CategoryScale,
	Chart as ChartJS,
	Legend,
	LinearScale,
	LineController,
	LineElement,
	PointElement,
	Tooltip,
} from 'chart.js';
import { Chart } from 'react-chartjs-2';
import { formatNumber } from '../api';

ChartJS.register( CategoryScale, LinearScale, BarController, BarElement, LineController, LineElement, PointElement, Tooltip, Legend );

// Ore del giorno, come etichette dell'asse.
const HOURS = Array.from( { length: 24 }, ( _, hour ) => `${ String( hour ).padStart( 2, '0' ) }:00` );

// Colore per giorno; i visitatori sono pieni, le visualizzazioni tratteggiate e nascoste all'inizio (come nel grafico della Panoramica).
const DAYS = [
	{ key: 'today', label: __( 'oggi', 'wp-5w' ), color: '#2271b1' },
	{ key: 'yesterday', label: __( 'ieri', 'wp-5w' ), color: '#a7aaad' },
	{ key: 'avg7', label: __( 'media 7 giorni', 'wp-5w' ), color: '#00a32a' },
];

// Nome della metrica nella legenda.
const METRICS = {
	visitors: __( 'Visitatori', 'wp-5w' ),
	views: __( 'Visualizzazioni', 'wp-5w' ),
};

/**
 * Valori di ogni singola ora ricavati dal cumulato: differenza con l'ora prima (null dove il cumulato è null).
 *
 * @param {Array<?number>} cumulative 24 valori cumulati.
 * @return {Array<?number>} 24 valori orari, con al massimo un decimale (le medie).
 */
const perHour = ( cumulative ) =>
	cumulative.map( ( value, hour ) =>
		value === null ? null : Math.round( ( value - ( hour > 0 ? cumulative[ hour - 1 ] : 0 ) ) * 10 ) / 10
	);

/**
 * Grafico orario di oggi contro ieri e la media degli ultimi 7 giorni: le linee sono i cumulati (a ogni ora il totale dall'inizio
 * della giornata, asse sinistro), le barre i valori di ogni ora (asse destro, altrimenti sarebbero schiacciate in basso).
 * Le serie si mostrano e nascondono cliccando sulla legenda.
 *
 * @param {Object} props
 * @param {Object} props.hourly { today|yesterday|avg7: { visitors: [24], views: [24] } } cumulati, con null per le ore non ancora
 *                              arrivate; avg7 è null senza nessun giorno intero.
 */
export default function HourlyChart( { hourly } ) {
	// Solo i giorni con dati (la media manca il primo giorno di raccolta).
	const days = DAYS.filter( ( day ) => hourly[ day.key ] );

	// Prima le linee, poi le barre: la legenda segue quest'ordine; order più basso = disegnato sopra.
	const lines = [ 'visitors', 'views' ].flatMap( ( metric ) =>
		days.map( ( day ) => ( {
			type: 'line',
			label: `${ METRICS[ metric ] } ${ day.label }`,
			data: hourly[ day.key ][ metric ],
			borderColor: day.color,
			backgroundColor: day.color,
			hidden: metric === 'views',
			borderDash: metric === 'views' ? [ 6, 4 ] : [],
			// Le ore di oggi non ancora arrivate sono null: la linea si ferma.
			spanGaps: false,
			tension: 0.25,
			pointRadius: 2,
			borderWidth: 2,
			yAxisID: 'y',
			order: 0,
		} ) )
	);
	const bars = [ 'visitors', 'views' ].flatMap( ( metric ) =>
		days.map( ( day ) => ( {
			type: 'bar',
			/* translators: %s: serie, es. «Visitatori oggi». */
			label: sprintf( __( '%s, per ora', 'wp-5w' ), `${ METRICS[ metric ] } ${ day.label }` ),
			data: perHour( hourly[ day.key ][ metric ] ),
			// Barre trasparenti, così le linee restano leggibili.
			backgroundColor: `${ day.color }59`,
			borderColor: day.color,
			borderWidth: metric === 'views' ? 1 : 0,
			hidden: metric === 'views',
			yAxisID: 'hour',
			order: 1,
		} ) )
	);

	const options = {
		responsive: true,
		maintainAspectRatio: false,
		interaction: { mode: 'index', intersect: false },
		plugins: {
			// Legenda nell'ordine delle serie, non in quello di disegno.
			legend: { position: 'bottom', labels: { sort: ( a, b ) => a.datasetIndex - b.datasetIndex } },
			tooltip: { callbacks: { label: ( context ) => `${ context.dataset.label }: ${ formatNumber( context.parsed.y ) }` } },
		},
		scales: {
			y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: __( 'Cumulato', 'wp-5w' ) } },
			hour: {
				position: 'right',
				beginAtZero: true,
				ticks: { precision: 0 },
				grid: { drawOnChartArea: false },
				title: { display: true, text: __( 'Per ora', 'wp-5w' ) },
			},
		},
	};

	return (
		<div className="wp5w-chart">
			<Chart type="bar" data={ { labels: HOURS, datasets: [ ...lines, ...bars ] } } options={ options } />
		</div>
	);
}
