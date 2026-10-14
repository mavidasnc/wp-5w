import { __ } from '@wordpress/i18n';
import { BarElement, CategoryScale, Chart as ChartJS, LinearScale, Tooltip } from 'chart.js';
import { Bar } from 'react-chartjs-2';
import { formatDay, formatNumber } from '../api';

ChartJS.register( CategoryScale, LinearScale, BarElement, Tooltip );

/**
 * Mini grafico a barre delle visualizzazioni giornaliere (ultima settimana), senza assi.
 * Il tooltip mostra data, visualizzazioni e visitatori.
 *
 * @param {Object} props
 * @param {Array}  props.points [{ day, views, visitors }].
 */
export default function Sparkline( { points } ) {
	const data = {
		labels: points.map( ( point ) => formatDay( point.day ) ),
		datasets: [
			{
				data: points.map( ( point ) => point.views ),
				backgroundColor: '#2271b1',
				borderRadius: 2,
				// Una barra minima anche a zero, così si vede che il giorno c'è.
				minBarLength: 1,
			},
		],
	};

	const options = {
		responsive: false,
		maintainAspectRatio: false,
		animation: false,
		plugins: {
			legend: { display: false },
			tooltip: {
				displayColors: false,
				callbacks: {
					label: ( context ) => [
						`${ __( 'Visualizzazioni', 'wp-5w' ) }: ${ formatNumber( points[ context.dataIndex ].views ) }`,
						`${ __( 'Visitatori', 'wp-5w' ) }: ${ formatNumber( points[ context.dataIndex ].visitors ) }`,
					],
				},
			},
		},
		scales: {
			x: { display: false },
			y: { display: false, beginAtZero: true },
		},
	};

	return <Bar className="wp5w-spark" width={ 140 } height={ 36 } data={ data } options={ options } />;
}
