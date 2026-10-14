import { BarElement, CategoryScale, Chart as ChartJS, Legend, LinearScale, Tooltip } from 'chart.js';
import { Bar } from 'react-chartjs-2';
import { formatNumber } from '../api';

ChartJS.register( CategoryScale, LinearScale, BarElement, Tooltip, Legend );

/**
 * Grafico a barre raggruppate (es. visitatori e visualizzazioni per giorno della settimana).
 *
 * @param {Object} props
 * @param {Array}  props.labels Etichette dell'asse X.
 * @param {Array}  props.series [{ label, data, color }].
 */
export default function BarChart( { labels, series } ) {
	const data = {
		labels,
		datasets: series.map( ( serie ) => ( {
			label: serie.label,
			data: serie.data,
			backgroundColor: serie.color,
			borderRadius: 3,
		} ) ),
	};

	const options = {
		responsive: true,
		maintainAspectRatio: false,
		plugins: {
			legend: { position: 'bottom' },
			tooltip: { callbacks: { label: ( context ) => `${ context.dataset.label }: ${ formatNumber( context.parsed.y ) }` } },
		},
		scales: { y: { beginAtZero: true } },
	};

	return (
		<div className="wp5w-chart wp5w-chart--bars">
			<Bar data={ data } options={ options } />
		</div>
	);
}
