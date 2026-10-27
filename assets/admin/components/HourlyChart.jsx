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

ChartJS.register( CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend );

// Ore del giorno, come etichette dell'asse.
const HOURS = Array.from( { length: 24 }, ( _, hour ) => `${ String( hour ).padStart( 2, '0' ) }:00` );

// Colore per giorno; i visitatori sono pieni, le visualizzazioni tratteggiate e nascoste all'inizio (come nel grafico della Panoramica).
const DAYS = [
	{ key: 'today', label: __( 'oggi', 'wp-5w' ), color: '#2271b1' },
	{ key: 'yesterday', label: __( 'ieri', 'wp-5w' ), color: '#a7aaad' },
];

/**
 * Grafico orario cumulato di oggi contro ieri: a ogni ora il totale dall'inizio della giornata.
 * Le serie si mostrano e nascondono cliccando sulla legenda.
 *
 * @param {Object} props
 * @param {Object} props.hourly { today|yesterday: { visitors: [24], views: [24] } }, con null per le ore non ancora arrivate.
 */
export default function HourlyChart( { hourly } ) {
	const datasets = [ 'visitors', 'views' ].flatMap( ( metric ) =>
		DAYS.map( ( day ) => ( {
			label: `${ metric === 'visitors' ? __( 'Visitatori', 'wp-5w' ) : __( 'Visualizzazioni', 'wp-5w' ) } ${ day.label }`,
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
		} ) )
	);

	const options = {
		responsive: true,
		maintainAspectRatio: false,
		interaction: { mode: 'index', intersect: false },
		plugins: { legend: { position: 'bottom' } },
		scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
	};

	return (
		<div className="wp5w-chart">
			<Line data={ { labels: HOURS, datasets } } options={ options } />
		</div>
	);
}
