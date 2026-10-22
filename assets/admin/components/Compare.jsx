import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';
import Card from './Card';
import { LABELS as CHANNEL_LABELS } from './ChannelList';

// Etichette dei motivi per cui un giorno è escluso dal confronto.
const EXCLUDED = {
	partial: __( 'primo giorno della raccolta propria, parziale', 'wp-5w' ),
	today: __( 'oggi, in corso', 'wp-5w' ),
	etl_pending: __( 'in attesa dell\'import notturno delle 4:00', 'wp-5w' ),
	no_wps: __( 'manca WP Statistics', 'wp-5w' ),
	no_own: __( 'manca la raccolta propria', 'wp-5w' ),
	ia_partial: __( 'primo giorno di Independent Analytics, parziale', 'wp-5w' ),
	no_ia: __( 'manca Independent Analytics', 'wp-5w' ),
};

// Etichette dei motivi di scarto della raccolta propria.
const DISCARDS = {
	bot: __( 'Bot e crawler', 'wp-5w' ),
	user: __( 'Utenti collegati', 'wp-5w' ),
	dnt: __( 'Do Not Track', 'wp-5w' ),
	rate: __( 'Troppe visite al minuto', 'wp-5w' ),
	signature: __( 'Firma non valida', 'wp-5w' ),
	ip: __( 'IP esclusi', 'wp-5w' ),
};

/**
 * Differenza percentuale con il segno (+4,2% e −37,5%), trattino se nulla.
 *
 * @param {?number} diff Differenza in percentuale.
 */
const formatDiff = ( diff ) => {
	if ( diff === null || diff === undefined ) {
		return '–';
	}
	const sign = diff > 0 ? '+' : diff < 0 ? '−' : '';
	return `${ sign }${ formatNumber( Math.abs( diff ), '%' ) }`;
};

/**
 * Giorno breve gg/mm per l'elenco dei giorni esclusi.
 *
 * @param {string} day Data Y-m-d.
 */
const shortDay = ( day ) => day.slice( 5 ).split( '-' ).reverse().join( '/' );

/**
 * Riga di copertura: quanti giorni completi, da quando a quando, quali esclusi e perché.
 *
 * @param {Object} coverage Blocco coverage della risposta.
 */
function Coverage( { coverage } ) {
	const { days, from, to, excluded, etl_ok: etlOk } = coverage;

	return (
		<p>
			{ days > 0
				? sprintf(
					/* translators: 1: numero di giorni, 2: primo giorno, 3: ultimo giorno. */
					_n( '%1$s giorno completo confrontato, dal %2$s al %3$s.', '%1$s giorni completi confrontati, dal %2$s al %3$s.', days, 'wp-5w' ),
					formatNumber( days ),
					formatDay( from ),
					formatDay( to )
				)
				: __( 'Nessun giorno completo con tutte le origini.', 'wp-5w' ) }
			{ excluded.length > 0 &&
				` ${ __( 'Esclusi:', 'wp-5w' ) } ${ excluded.map( ( item ) => `${ shortDay( item.day ) } (${ EXCLUDED[ item.reason ] ?? item.reason })` ).join( '; ' ) }.` }
			{ etlOk === false && ` ${ __( 'L\'ultimo import ha avuto errori: vedi la scheda Aggiornamenti.', 'wp-5w' ) }` }
		</p>
	);
}

/**
 * Sezione Confronto della scheda Stato: tre card che mostrano quanto la raccolta propria e, se ha dati, Independent Analytics
 * si discostano da WP Statistics (la differenza è sempre rispetto a WP Statistics).
 */
export default function Compare() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	// Carica il confronto una volta: la finestra dei giorni la calcola il server.
	useEffect( () => {
		api( '/admin/compare' )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [] );

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}

	// Senza righe della raccolta propria non c'è nulla da confrontare: una riga di testo.
	if ( ! data.available ) {
		return <p className="wp5w-note">{ __( 'Il confronto compare dopo il primo giorno con visite registrate dalla raccolta propria.', 'wp-5w' ) }</p>;
	}

	const { coverage, totals, home_direct: home, channels, discards, top } = data;
	const hasDays = data.days.length > 0;
	// Independent Analytics: colonne in più solo se il plugin è attivo e ha giorni confrontabili.
	const ia = data.ia_available;

	return (
		<>
			<Card
				id="status-compare"
				title={ ia ? __( 'Confronto tra WP Statistics, raccolta propria e Independent Analytics', 'wp-5w' ) : __( 'Confronto con WP Statistics', 'wp-5w' ) }
			>
				<Coverage coverage={ coverage } />
				{ hasDays && (
					<>
						<p className="wp5w-note wp5w-note--top">
							{ sprintf(
								/* translators: %s: soglia di differenza in percentuale. */
								__( 'La raccolta propria può contare un po\' meno, perché esclude gli amministratori; dalla versione 0.11.0 bot e browser automatici si registrano e si segnalano. Una differenza stabile entro il %s è normale; i giorni oltre la soglia sono evidenziati.', 'wp-5w' ),
								formatNumber( data.threshold, '%' )
							) }
						</p>
						{ ia && (
							<p className="wp5w-note">
								{ __( 'Independent Analytics scarta i bot (non li segnala), non traccia gli utenti collegati, non rispetta Do Not Track e conta una nuova sessione dopo 30 minuti di pausa: gli ingressi sono quindi di più e le visite di meno. Il giorno è quello della visita nel fuso del sito.', 'wp-5w' ) }
							</p>
						) }
						<div className="wp5w-table-scroll">
							<table className="widefat striped">
								<thead>
									<tr>
										<th>{ __( 'Giorno', 'wp-5w' ) }</th>
										<th className="num">{ __( 'Visitatori WP Statistics', 'wp-5w' ) }</th>
										<th className="num">{ __( 'Visitatori propri', 'wp-5w' ) }</th>
										<th className="num">{ __( 'Differenza', 'wp-5w' ) }</th>
										{ ia && <th className="num">{ __( 'Visitatori Independent Analytics', 'wp-5w' ) }</th> }
										{ ia && <th className="num">{ __( 'Differenza', 'wp-5w' ) }</th> }
										<th className="num">{ __( 'Visualizzazioni WP Statistics', 'wp-5w' ) }</th>
										<th className="num">{ __( 'Visualizzazioni proprie', 'wp-5w' ) }</th>
										<th className="num">{ __( 'Differenza', 'wp-5w' ) }</th>
										{ ia && <th className="num">{ __( 'Visualizzazioni Independent Analytics', 'wp-5w' ) }</th> }
										{ ia && <th className="num">{ __( 'Differenza', 'wp-5w' ) }</th> }
									</tr>
								</thead>
								<tbody>
									{ data.days.map( ( day ) => (
										<tr key={ day.day } className={ day.flagged ? 'is-flagged' : undefined }>
											<td>{ formatDay( day.day ) }</td>
											<td className="num">{ formatNumber( day.wps.visitors ) }</td>
											<td className="num">{ formatNumber( day.own.visitors ) }</td>
											<td className="num">{ formatDiff( day.diff.visitors ) }</td>
											{ ia && <td className="num">{ formatNumber( day.ia.visitors ) }</td> }
											{ ia && <td className="num">{ formatDiff( day.diff_ia.visitors ) }</td> }
											<td className="num">{ formatNumber( day.wps.views ) }</td>
											<td className="num">{ formatNumber( day.own.views ) }</td>
											<td className="num">{ formatDiff( day.diff.views ) }</td>
											{ ia && <td className="num">{ formatNumber( day.ia.views ) }</td> }
											{ ia && <td className="num">{ formatDiff( day.diff_ia.views ) }</td> }
										</tr>
									) ) }
								</tbody>
								<tfoot>
									<tr>
										<th>{ __( 'Totale', 'wp-5w' ) }</th>
										<th className="num">{ formatNumber( totals.wps.visitors ) }</th>
										<th className="num">{ formatNumber( totals.own.visitors ) }</th>
										<th className="num">{ formatDiff( totals.diff.visitors ) }</th>
										{ ia && <th className="num">{ formatNumber( totals.ia.visitors ) }</th> }
										{ ia && <th className="num">{ formatDiff( totals.diff_ia.visitors ) }</th> }
										<th className="num">{ formatNumber( totals.wps.views ) }</th>
										<th className="num">{ formatNumber( totals.own.views ) }</th>
										<th className="num">{ formatDiff( totals.diff.views ) }</th>
										{ ia && <th className="num">{ formatNumber( totals.ia.views ) }</th> }
										{ ia && <th className="num">{ formatDiff( totals.diff_ia.views ) }</th> }
									</tr>
									<tr>
										<th colSpan={ ia ? 6 : 4 }>{ __( 'Visualizzazioni per visitatore', 'wp-5w' ) }</th>
										<th className="num">{ formatNumber( totals.views_per_visitor.wps ) }</th>
										<th className="num">{ formatNumber( totals.views_per_visitor.own ) }</th>
										<th />
										{ ia && <th className="num">{ formatNumber( totals.views_per_visitor.ia ) }</th> }
										{ ia && <th /> }
									</tr>
								</tfoot>
							</table>
						</div>
					</>
				) }
			</Card>

			{ hasDays && (
				<>
					<Card id="status-compare-why" title={ __( 'Dove nasce la differenza', 'wp-5w' ) }>
						<h3>{ __( 'Ingressi diretti sulla home', 'wp-5w' ) }</h3>
						<p className="wp5w-note wp5w-note--top">
							{ __( 'Dal 23 settembre WP Statistics conta circa 110 visitatori in più al giorno: diretti, solo home, una visualizzazione, distribuiti sulle 24 ore. Se qui la raccolta propria ne conta molti meno, la differenza è traffico automatico che WP Statistics accetta.', 'wp-5w' ) }
						</p>
						<table className="widefat striped">
							<thead>
								<tr>
									<th />
									<th className="num">{ __( 'Visitatori', 'wp-5w' ) }</th>
									<th className="num">{ __( 'Quota sui visitatori', 'wp-5w' ) }</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td>{ __( 'WP Statistics', 'wp-5w' ) }</td>
									<td className="num">{ formatNumber( home.wps ) }</td>
									<td className="num">{ formatNumber( home.share_wps, '%' ) }</td>
								</tr>
								<tr>
									<td>{ __( 'Propri', 'wp-5w' ) }</td>
									<td className="num">{ formatNumber( home.own ) }</td>
									<td className="num">{ formatNumber( home.share_own, '%' ) }</td>
								</tr>
								<tr>
									<td>{ __( 'Differenza', 'wp-5w' ) }</td>
									<td className="num">{ formatDiff( home.diff ) }</td>
									<td />
								</tr>
								{ ia && (
									<>
										<tr>
											<td>{ __( 'Independent Analytics', 'wp-5w' ) }</td>
											<td className="num">{ formatNumber( home.ia ) }</td>
											<td className="num">{ formatNumber( home.share_ia, '%' ) }</td>
										</tr>
										<tr>
											<td>{ __( 'Differenza Independent Analytics', 'wp-5w' ) }</td>
											<td className="num">{ formatDiff( home.diff_ia ) }</td>
											<td />
										</tr>
									</>
								) }
							</tbody>
						</table>
						<p className="wp5w-note">{ __( 'Giorno per giorno:', 'wp-5w' ) }</p>
						<table className="widefat striped">
							<thead>
								<tr>
									<th>{ __( 'Giorno', 'wp-5w' ) }</th>
									<th className="num">{ __( 'WP Statistics', 'wp-5w' ) }</th>
									<th className="num">{ __( 'Propri', 'wp-5w' ) }</th>
									{ ia && <th className="num">{ __( 'Independent Analytics', 'wp-5w' ) }</th> }
								</tr>
							</thead>
							<tbody>
								{ data.days.map( ( day ) => (
									<tr key={ day.day }>
										<td>{ formatDay( day.day ) }</td>
										<td className="num">{ formatNumber( day.home_direct.wps ) }</td>
										<td className="num">{ formatNumber( day.home_direct.own ) }</td>
										{ ia && <td className="num">{ formatNumber( day.home_direct.ia ) }</td> }
									</tr>
								) ) }
							</tbody>
						</table>

						<h3>{ __( 'Canali', 'wp-5w' ) }</h3>
						<table className="widefat striped">
							<thead>
								<tr>
									<th>{ __( 'Canale', 'wp-5w' ) }</th>
									<th className="num">{ __( 'WP Statistics', 'wp-5w' ) }</th>
									<th className="num">{ __( 'Propri', 'wp-5w' ) }</th>
									<th className="num">{ __( 'Differenza', 'wp-5w' ) }</th>
									{ ia && <th className="num">{ __( 'Independent Analytics', 'wp-5w' ) }</th> }
									{ ia && <th className="num">{ __( 'Differenza', 'wp-5w' ) }</th> }
								</tr>
							</thead>
							<tbody>
								{ channels.map( ( row ) => (
									<tr key={ row.channel }>
										<td>{ CHANNEL_LABELS[ row.channel ] ?? row.channel }</td>
										<td className="num">{ formatNumber( row.wps ) }</td>
										<td className="num">{ formatNumber( row.own ) }</td>
										<td className="num">{ formatDiff( row.diff ) }</td>
										{ ia && <td className="num">{ formatNumber( row.ia ) }</td> }
										{ ia && <td className="num">{ formatDiff( row.diff_ia ) }</td> }
									</tr>
								) ) }
							</tbody>
						</table>

						<h3>{ __( 'Scarti della raccolta propria', 'wp-5w' ) }</h3>
						<table className="widefat striped">
							<thead>
								<tr>
									<th>{ __( 'Motivo', 'wp-5w' ) }</th>
									<th className="num">{ __( 'Richieste', 'wp-5w' ) }</th>
									<th className="num">{ __( 'Quota', 'wp-5w' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ Object.entries( discards.reasons ).map( ( [ reason, item ] ) => (
									<tr key={ reason }>
										<td>{ DISCARDS[ reason ] ?? reason }</td>
										<td className="num">{ formatNumber( item.n ) }</td>
										<td className="num">{ formatNumber( item.share, '%' ) }</td>
									</tr>
								) ) }
							</tbody>
						</table>
						<p className="wp5w-note">
							{ `${ __( 'Totale scarti:', 'wp-5w' ) } ${ formatNumber( discards.total ) }. ${ __( 'Quota sulle richieste arrivate all\'endpoint (visite registrate più scarti).', 'wp-5w' ) }` }
						</p>
					</Card>

					<Card id="status-compare-top" title={ __( 'Contenuti con la differenza più grande', 'wp-5w' ) }>
						<table className="widefat striped">
							<thead>
								<tr>
									<th>{ __( 'Contenuto', 'wp-5w' ) }</th>
									<th className="num">{ __( 'WP Statistics', 'wp-5w' ) }</th>
									<th className="num">{ __( 'Propri', 'wp-5w' ) }</th>
									<th className="num">{ __( 'Differenza assoluta', 'wp-5w' ) }</th>
									<th className="num">{ __( 'Differenza %', 'wp-5w' ) }</th>
									{ ia && <th className="num">{ __( 'Independent Analytics', 'wp-5w' ) }</th> }
									{ ia && <th className="num">{ __( 'Differenza % Independent Analytics', 'wp-5w' ) }</th> }
								</tr>
							</thead>
							<tbody>
								{ top.map( ( row ) => (
									<tr key={ `${ row.type }-${ row.id }` }>
										<td>
											<a href={ row.url } target="_blank" rel="noreferrer">{ row.title }</a>
										</td>
										<td className="num">{ formatNumber( row.wps_views ) }</td>
										<td className="num">{ formatNumber( row.own_views ) }</td>
										<td className="num">{ formatNumber( row.diff_abs ) }</td>
										<td className="num">{ formatDiff( row.diff ) }</td>
										{ ia && <td className="num">{ formatNumber( row.ia_views ) }</td> }
										{ ia && <td className="num">{ formatDiff( row.diff_ia ) }</td> }
									</tr>
								) ) }
							</tbody>
						</table>
						<p className="wp5w-note">
							{ sprintf(
								/* translators: %s: visualizzazioni minime in una delle origini. */
								__( 'Visualizzazioni sui giorni completi; compaiono i contenuti con almeno %s visualizzazioni in una delle origini.', 'wp-5w' ),
								formatNumber( data.min_views )
							) }
						</p>
					</Card>
				</>
			) }
		</>
	);
}
