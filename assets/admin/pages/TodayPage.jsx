import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';
import Card from '../components/Card';
import ShowMore from '../components/ShowMore';
import TodayKpi from '../components/TodayKpi';

/**
 * Scheda «Oggi»: visitatori e visualizzazioni del giorno in corso, confrontati alla stessa ora con ieri e con le medie di 7 e 30 giorni,
 * e pagine viste oggi con il canale con cui sono arrivati i visitatori. Legge solo la raccolta propria.
 */
export default function TodayPage() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	// Carica i dati una volta al montaggio della scheda.
	useEffect( () => {
		api( '/admin/today' )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [] );

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}

	const { kpis, pages } = data;

	return (
		<div className="wp5w-today">
			<p className="wp5w-note wp5w-note--top">
				{ sprintf(
					/* translators: 1: giorno (gg/mm/aaaa), 2: ora dell'aggiornamento. */
					__( 'Oggi, %1$s, fino alle %2$s. I confronti sono alla stessa ora: ieri e i giorni delle medie sono contati fino alle %2$s; sotto ogni valore, in piccolo, il totale della giornata intera.', 'wp-5w' ),
					formatDay( data.day ),
					data.at.slice( 0, 5 )
				) }
				{ ! data.tracking && ` ${ __( 'La raccolta propria è spenta nelle Opzioni.', 'wp-5w' ) }` }
			</p>

			<div className="wp5w-kpis">
				<TodayKpi label={ __( 'Visitatori', 'wp-5w' ) } data={ kpis.visitors } />
				<TodayKpi label={ __( 'Visualizzazioni', 'wp-5w' ) } data={ kpis.views } />
			</div>

			<Card id="today-pages" title={ __( 'Pagine viste oggi', 'wp-5w' ) }>
				{ pages.rows.length === 0 ? (
					<p>{ __( 'Nessuna visita registrata oggi.', 'wp-5w' ) }</p>
				) : (
					<ShowMore rows={ pages.rows } size={ 25 }>
						{ ( visible ) => (
							<div className="wp5w-table-scroll">
								<table className="widefat striped">
									<thead>
										<tr>
											<th>{ __( 'Pagina', 'wp-5w' ) }</th>
											<th className="num">{ __( 'Visualizzazioni', 'wp-5w' ) }</th>
											<th className="num">{ __( 'Visitatori', 'wp-5w' ) }</th>
											<th className="num">{ __( 'Diretto', 'wp-5w' ) }</th>
											<th className="num">{ __( 'Motori di ricerca', 'wp-5w' ) }</th>
											<th className="num">{ __( 'Bot', 'wp-5w' ) }</th>
											<th className="num">{ __( 'Altri canali', 'wp-5w' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ visible.map( ( row ) => (
											<tr key={ `${ row.type }-${ row.id }-${ row.url }` }>
												<td>
													<a href={ row.url } target="_blank" rel="noreferrer">{ row.title }</a>
												</td>
												<td className="num">{ formatNumber( row.views ) }</td>
												<td className="num">{ formatNumber( row.visitors ) }</td>
												<td className="num">{ formatNumber( row.direct ) }</td>
												<td className="num">{ formatNumber( row.search ) }</td>
												<td className="num">{ formatNumber( row.bot ) }</td>
												<td className="num">{ formatNumber( row.other ) }</td>
											</tr>
										) ) }
									</tbody>
									<tfoot>
										<tr>
											<th>{ __( 'Totale', 'wp-5w' ) }</th>
											<th className="num">{ formatNumber( pages.totals.views ) }</th>
											<th className="num">{ formatNumber( pages.totals.visitors ) }</th>
											<th className="num">{ formatNumber( pages.totals.direct ) }</th>
											<th className="num">{ formatNumber( pages.totals.search ) }</th>
											<th className="num">{ formatNumber( pages.totals.bot ) }</th>
											<th className="num">{ formatNumber( pages.totals.other ) }</th>
										</tr>
									</tfoot>
								</table>
							</div>
						) }
					</ShowMore>
				) }
				<p className="wp5w-note">
					{ __( 'Ogni visualizzazione prende il canale con cui il visitatore è arrivato oggi (la sua prima visita del giorno): Diretto, Motori di ricerca, Bot (crawler e browser automatici, compresi nei totali) e Altri canali (social, siti esterni, email, assistenti AI). I visitatori in fondo sono quelli distinti del giorno: chi vede più pagine compare in ognuna.', 'wp-5w' ) }
				</p>
			</Card>
		</div>
	);
}
