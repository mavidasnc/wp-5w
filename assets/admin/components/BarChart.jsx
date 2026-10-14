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

/**
 * Grafico a barre raggruppate, con eventuali linee su un asse destro (es. totali a barre e medie al giorno a linee).
 *
 * @param {Object} props
 * @param {Array}  props.labels Etichette dell'asse X.
 * @param {Array}  props.series [{ label, data, color, line? }]: con line=true la serie è una linea sull'asse destro.
 */
export default function BarChart( { labels, series } ) {
	const hasLines = series.some( ( serie ) => serie.line );

	const data = {
		labels,
		// Le linee vanno disegnate sopra le barre (order più basso = in primo piano).
		datasets: series.map( ( serie ) =>
			serie.line
				? {
						type: 'line',
						label: serie.label,
						data: serie.data,
						borderColor: serie.color,
						backgroundColor: serie.color,
						yAxisID: 'line',
						tension: 0.25,
						pointRadius: 3,
						borderWidth: 2,
						order: 0,
				  }
				: {
						type: 'bar',
						label: serie.label,
						data: serie.data,
						backgroundColor: serie.color,
						yAxisID: 'y',
						borderRadius: 3,
						order: 1,
				  }
		),
	};

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
			y: { beginAtZero: true },
			...( hasLines ? { line: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false } } } : {} ),
		},
	};

	return (
		<div className="wp5w-chart wp5w-chart--bars">
			<Chart type="bar" data={ data } options={ options } />
		</div>
	);
}
