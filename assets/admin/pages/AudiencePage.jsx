import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';
import BarChart from '../components/BarChart';
import QueryTable from '../components/QueryTable';
import ShowMore from '../components/ShowMore';

// Lunedì = 1 … domenica = 7 (ISO-8601, come Weekdays::group()).
const WEEKDAYS = [
	__( 'Lunedì', 'wp-5w' ),
	__( 'Martedì', 'wp-5w' ),
	__( 'Mercoledì', 'wp-5w' ),
	__( 'Giovedì', 'wp-5w' ),
	__( 'Venerdì', 'wp-5w' ),
	__( 'Sabato', 'wp-5w' ),
	__( 'Domenica', 'wp-5w' ),
];

const COLORS = { visitors: '#72aee6', views: '#2271b1' };

/**
 * Nome del giorno della settimana di una data Y-m-d.
 *
 * @param {string} day Data.
 */
const weekdayOf = ( day ) => WEEKDAYS[ ( new Date( `${ day }T12:00:00Z` ).getUTCDay() + 6 ) % 7 ];

export default function AudiencePage() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		api( '/admin/audience' )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [] );

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}

	const week = data.week.days;
	const half = data.half_year.weekdays;

	return (
		<div className="wp5w-audience">
			<div className="wp5w-columns">
				<section className="wp5w-card">
					<h2>{ __( 'Ultimi 7 giorni', 'wp-5w' ) }</h2>
					<BarChart
						labels={ week.map( ( day ) => `${ weekdayOf( day.day ).slice( 0, 3 ) } ${ formatDay( day.day ).slice( 0, 5 ) }` ) }
						series={ [
							{ label: __( 'Visitatori', 'wp-5w' ), data: week.map( ( day ) => day.visitors ), color: COLORS.visitors },
							{ label: __( 'Visualizzazioni', 'wp-5w' ), data: week.map( ( day ) => day.views ), color: COLORS.views },
						] }
					/>
					<table className="widefat striped wp5w-table">
						<thead>
							<tr>
								<th>{ __( 'Giorno', 'wp-5w' ) }</th>
								<th className="num">{ __( 'Visitatori', 'wp-5w' ) }</th>
								<th className="num">{ __( 'Visualizzazioni', 'wp-5w' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ week.map( ( day ) => (
								<tr key={ day.day }>
									<td>
										{ weekdayOf( day.day ) } <span className="wp5w-type">{ formatDay( day.day ) }</span>
									</td>
									<td className="num">{ formatNumber( day.visitors ) }</td>
									<td className="num">{ formatNumber( day.views ) }</td>
								</tr>
							) ) }
						</tbody>
					</table>
					<p className="wp5w-note">{ __( 'Il giorno di oggi è parziale.', 'wp-5w' ) }</p>
				</section>

				<section className="wp5w-card">
					<h2>
						{ __( 'Ultimi 6 mesi per giorno della settimana', 'wp-5w' ) }{ ' ' }
						<span className="wp5w-type">
							{ formatDay( data.half_year.range.from ) } – { formatDay( data.half_year.range.to ) }
						</span>
					</h2>
					<BarChart
						labels={ half.map( ( row ) => WEEKDAYS[ row.weekday - 1 ] ) }
						series={ [
							{ label: __( 'Media visitatori', 'wp-5w' ), data: half.map( ( row ) => row.avg_visitors ), color: COLORS.visitors },
							{ label: __( 'Media visualizzazioni', 'wp-5w' ), data: half.map( ( row ) => row.avg_views ), color: COLORS.views },
						] }
					/>
					<table className="widefat striped wp5w-table">
						<thead>
							<tr>
								<th>{ __( 'Giorno', 'wp-5w' ) }</th>
								<th className="num">{ __( 'Visitatori', 'wp-5w' ) }</th>
								<th className="num">{ __( 'Visualizzazioni', 'wp-5w' ) }</th>
								<th className="num">{ __( 'Media visitatori', 'wp-5w' ) }</th>
								<th className="num">{ __( 'Media visualizz.', 'wp-5w' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ half.map( ( row ) => (
								<tr key={ row.weekday }>
									<td>
										{ WEEKDAYS[ row.weekday - 1 ] } <span className="wp5w-type">{ row.days } { __( 'giorni', 'wp-5w' ) }</span>
									</td>
									<td className="num">{ formatNumber( row.visitors ) }</td>
									<td className="num">{ formatNumber( row.views ) }</td>
									<td className="num">{ formatNumber( row.avg_visitors ) }</td>
									<td className="num">{ formatNumber( row.avg_views ) }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</section>
			</div>

			<div className="wp5w-columns">
				<section className="wp5w-card">
					<h2>
						{ __( 'Provenienza degli ultimi 7 giorni', 'wp-5w' ) }{ ' ' }
						<span className="wp5w-type">
							{ formatDay( data.referrers.range.from ) } – { formatDay( data.referrers.range.to ) }
						</span>
					</h2>
					{ data.referrers.rows.length ? (
						<table className="widefat striped wp5w-table">
							<thead>
								<tr>
									<th>{ __( 'Sito', 'wp-5w' ) }</th>
									<th>{ __( 'Sorgente', 'wp-5w' ) }</th>
									<th className="num">{ __( 'Visitatori', 'wp-5w' ) }</th>
									<th className="num">{ __( 'Contenuti', 'wp-5w' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ data.referrers.rows.map( ( row ) => (
									<tr key={ row.referrer }>
										<td>{ row.referrer }</td>
										<td>{ row.source || row.channel || '–' }</td>
										<td className="num">{ formatNumber( row.visitors ) }</td>
										<td className="num">{ formatNumber( row.contents ) }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) : (
						<p>{ __( 'Nessun sito di provenienza negli ultimi 7 giorni.', 'wp-5w' ) }</p>
					) }
					<p className="wp5w-note">{ __( 'Contenuti: quanti articoli o pagine diversi hanno ricevuto visitatori da quel sito.', 'wp-5w' ) }</p>
				</section>

				<section className="wp5w-card">
					<h2>
						{ __( 'Keyword degli ultimi 7 giorni disponibili', 'wp-5w' ) }{ ' ' }
						{ data.keywords.range && (
							<span className="wp5w-type">
								{ formatDay( data.keywords.range.from ) } – { formatDay( data.keywords.range.to ) }
							</span>
						) }
					</h2>
					<ShowMore rows={ data.keywords.rows } size={ 15 }>{ ( rows ) => <QueryTable rows={ rows } /> }</ShowMore>
					<p className="wp5w-note">{ __( 'Search Console pubblica i dati con 2-3 giorni di ritardo.', 'wp-5w' ) }</p>
				</section>
			</div>
		</div>
	);
}
