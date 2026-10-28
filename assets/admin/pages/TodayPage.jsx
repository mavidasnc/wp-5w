import { __, sprintf } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { formatDay, formatNumber } from '../api';
import Card from '../components/Card';
import HourlyChart from '../components/HourlyChart';
import ShowMore from '../components/ShowMore';
import TodayKpi from '../components/TodayKpi';
import usePolledApi from '../hooks/usePolledApi';

// Ogni quanto si aggiorna, in millisecondi.
const REFRESH_MS = 60000;

/**
 * Scheda «Oggi»: visitatori e visualizzazioni del giorno in corso, confrontati alla stessa ora con ieri e con le medie di 7 e 30 giorni,
 * andamento orario di oggi contro ieri e la media di 7 giorni e pagine viste oggi con il canale con cui sono arrivati i visitatori. Legge solo la raccolta propria
 * e si aggiorna da sola ogni minuto mentre la scheda del browser è visibile.
 */
export default function TodayPage() {
	const { data, error } = usePolledApi( '/admin/today', REFRESH_MS );

	// Senza dati: l'errore del primo caricamento oppure lo spinner. Un errore successivo non toglie gli ultimi dati.
	if ( ! data ) {
		return error ? <Notice status="error" isDismissible={ false }>{ error }</Notice> : <Spinner />;
	}

	const { kpis, pages } = data;

	return (
		<div className="wp5w-today">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			<p className="wp5w-note wp5w-note--top">
				{ sprintf(
					/* translators: 1: giorno (gg/mm/aaaa), 2: ora dell'aggiornamento. */
					__( 'Oggi, %1$s, fino alle %2$s, dalla raccolta propria (bot e browser automatici compresi, quindi i numeri possono differire da WP Statistics). I confronti sono alla stessa ora: ieri e i giorni delle medie sono contati fino alle %2$s; sotto ogni valore, in piccolo, il totale della giornata intera. La scheda si aggiorna da sola ogni minuto.', 'wp-5w' ),
					formatDay( data.day ),
					data.at.slice( 0, 5 )
				) }
				{ ! data.tracking && ` ${ __( 'La raccolta propria è spenta nelle Opzioni.', 'wp-5w' ) }` }
			</p>

			<div className="wp5w-kpis">
				<TodayKpi label={ __( 'Visitatori', 'wp-5w' ) } data={ kpis.visitors } />
				<TodayKpi label={ __( 'Visualizzazioni', 'wp-5w' ) } data={ kpis.views } />
			</div>

			<Card id="today-hourly" title={ __( 'Andamento orario: oggi, ieri e media degli ultimi 7 giorni', 'wp-5w' ) }>
				<HourlyChart hourly={ data.hourly } />
				<p className="wp5w-note">
					{ __( 'Le linee sono i valori cumulati (asse sinistro): a ogni ora, i visitatori o le visualizzazioni dall\'inizio della giornata. Le barre sono i valori di ogni singola ora (asse destro): per i visitatori sono i nuovi visitatori dell\'ora, contati nell\'ora della loro prima visita del giorno, non quelli attivi in quell\'ora. La media usa gli stessi giorni del confronto «media 7 giorni» qui sopra. Clicca sulla legenda per mostrare o nascondere le serie (le visualizzazioni sono nascoste all\'inizio). Ora locale del sito.', 'wp-5w' ) }
				</p>
			</Card>

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
