import { Fragment, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';
import Card from './Card';
import { LABELS as CHANNEL_LABELS } from './ChannelList';
import TrendChart from './TrendChart';

// Serie del grafico del Confronto, nell'ordine della legenda: raccolta propria, WP Statistics, Independent Analytics e log (con visitatori prima delle visualizzazioni).
const COMPARE_KEYS = [ 'own', 'wps', 'ia', 'log' ].flatMap( ( id ) => [ `visitors_${ id }`, `views_${ id }` ] );

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
 * Differenza percentuale rispetto a WP Statistics come badge: verde entro la soglia, rosso oltre. Niente se manca il valore.
 *
 * @param {Object}  props
 * @param {?number} props.value     Differenza in percentuale.
 * @param {number}  props.threshold Soglia in percentuale.
 * @param {?number} props.base      Valore di WP Statistics usato per la differenza, se diverso da quello della riga (il log usa solo i suoi giorni).
 */
function Diff( { value, threshold, base } ) {
	if ( value === null || value === undefined ) {
		return null;
	}
	const title =
		base === null || base === undefined
			? undefined
			: sprintf(
				/* translators: %s: valore di WP Statistics. */
				__( 'Rispetto a %s di WP Statistics nei soli giorni coperti dai log', 'wp-5w' ),
				formatNumber( base )
			);
	return (
		<span className={ `wp5w-diff ${ Math.abs( value ) > threshold ? 'is-bad' : 'is-good' }` } title={ title }>
			{ formatDiff( value ) }
		</span>
	);
}

// Chiave della differenza (rispetto a WP Statistics) nella riga del giorno, per strumento.
const DIFF_KEY = { own: 'diff', ia: 'diff_ia', log: 'diff_log' };

/**
 * Differenza percentuale di un valore rispetto a WP Statistics, con un decimale come Metrics::change(); null se manca un valore o WP Statistics è zero.
 *
 * @param {?number} value Valore dello strumento.
 * @param {?number} wps   Valore di WP Statistics.
 */
const change = ( value, wps ) =>
	value === null || value === undefined || ! wps ? null : Math.round( ( ( value - wps ) / wps ) * 1000 ) / 10;

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

	// Gli strumenti da mostrare, nello stesso ordine in riquadri, grafico e tabella: raccolta propria, WP Statistics, Independent Analytics e log
	// (la differenza è sempre rispetto a WP Statistics).
	const tools = [
		{ id: 'own', label: __( 'Raccolta propria', 'wp-5w' ), ...totals.own, diff_visitors: totals.diff.visitors, diff_views: totals.diff.views },
		{ id: 'wps', label: __( 'WP Statistics', 'wp-5w' ), ...totals.wps },
		...( ia
			? [ { id: 'ia', label: __( 'Independent Analytics', 'wp-5w' ), ...totals.ia, diff_visitors: totals.diff_ia.visitors, diff_views: totals.diff_ia.views } ]
			: [] ),
		...( data.log_available
			? [ { id: 'log', label: __( 'Log del server (browser)', 'wp-5w' ), ...totals.log, diff_visitors: totals.diff_log.visitors, diff_views: totals.diff_log.views } ]
			: [] ),
	];

	// Tabelle per contenuto (ingressi diretti sulla home e contenuti): il log c'è solo se ha giorni con il dettaglio per contenuto.
	const detailTools = tools.filter( ( tool ) => tool.id !== 'log' || home.log_days !== undefined );

	// Un punto per giorno confrontato, con le chiavi delle serie del grafico della Panoramica.
	const chartSeries = data.days.map( ( day ) => ( {
		day: day.day,
		visitors_own: day.own.visitors,
		views_own: day.own.views,
		visitors_wps: day.wps.visitors,
		views_wps: day.wps.views,
		...( ia ? { visitors_ia: day.ia.visitors, views_ia: day.ia.views } : {} ),
		...( data.log_available ? { visitors_log: day.log?.visitors ?? null, views_log: day.log?.views ?? null } : {} ),
	} ) );

	return (
		<>
			<Card
				id="status-compare"
				title={ ia ? __( 'Confronto tra raccolta propria, WP Statistics e Independent Analytics', 'wp-5w' ) : __( 'Confronto tra raccolta propria e WP Statistics', 'wp-5w' ) }
			>
				<Coverage coverage={ coverage } />
				{ hasDays && (
					<>
						<div className="wp5w-tools">
							{ tools.map( ( tool ) => (
								<section key={ tool.id } className="wp5w-card wp5w-tool">
									<h3>{ tool.label }</h3>
									<p className="wp5w-tool__row">
										<span>{ __( 'Visitatori', 'wp-5w' ) }</span>
										<strong>{ formatNumber( tool.visitors ) }</strong>
										<Diff value={ tool.diff_visitors } threshold={ data.threshold } />
									</p>
									<p className="wp5w-tool__row">
										<span>{ __( 'Visualizzazioni', 'wp-5w' ) }</span>
										<strong>{ formatNumber( tool.views ) }</strong>
										<Diff value={ tool.diff_views } threshold={ data.threshold } />
									</p>
									<p className="wp5w-note">
										{ __( 'Visualizzazioni per visitatore:', 'wp-5w' ) } { formatNumber( totals.views_per_visitor[ tool.id ] ) }
									</p>
								</section>
							) ) }
						</div>
						<p className="wp5w-note">
							{ sprintf(
								/* translators: %s: soglia di differenza in percentuale. */
								__( 'La differenza è sempre rispetto a WP Statistics: verde entro il %s, rosso oltre. La raccolta propria esclude gli amministratori e rispetta Do Not Track; dalla 0.11.0 bot e browser automatici si registrano e si segnalano (canale «Bot e browser automatici»).', 'wp-5w' ),
								formatNumber( data.threshold, '%' )
							) }
						</p>
						<TrendChart series={ chartSeries } keys={ COMPARE_KEYS } />
						<p className="wp5w-note">
							{ __( 'WP Statistics e Independent Analytics contano come visitatori anche una flotta di browser automatici con user agent da telefono: eseguono JavaScript ma non caricano immagini né fogli di stile. Fino alla 0.13.0 il tracker proprio ne perdeva molti, con ogni probabilità perché inviava con sendBeacon, che quei browser sembrano bloccare; dalla 0.14.0 invia con fetch come gli altri due (se dopo un giorno intero la differenza resta, questa spiegazione va rivista), quindi la differenza dei giorni precedenti è più grande. Independent Analytics non traccia gli utenti collegati, non rispetta Do Not Track e conta una nuova sessione dopo 30 minuti di pausa: gli ingressi sono di più e le visite di meno. Il giorno è quello della visita nel fuso del sito.', 'wp-5w' ) }
						</p>
						<div className="wp5w-table-scroll">
							<table className="widefat striped wp5w-compare-table">
								<thead>
									<tr>
										<th rowSpan={ 2 }>{ __( 'Giorno', 'wp-5w' ) }</th>
										{ tools.map( ( tool ) => (
											<th key={ tool.id } colSpan={ 2 } className="wp5w-compare-table__group">{ tool.label }</th>
										) ) }
									</tr>
									<tr>
										{ tools.map( ( tool ) => (
											<Fragment key={ tool.id }>
												<th className="num">{ __( 'Visitatori', 'wp-5w' ) }</th>
												<th className="num">{ __( 'Visualizzazioni', 'wp-5w' ) }</th>
											</Fragment>
										) ) }
									</tr>
								</thead>
								<tbody>
									{ data.days.map( ( day ) => (
										<tr key={ day.day } className={ day.flagged ? 'is-flagged' : undefined }>
											<td>{ formatDay( day.day ) }</td>
											{ tools.map( ( tool ) => (
												<Fragment key={ tool.id }>
													<td className="num">
														{ formatNumber( day[ tool.id ]?.visitors ) }
														<Diff value={ day[ DIFF_KEY[ tool.id ] ]?.visitors } threshold={ data.threshold } />
													</td>
													<td className="num">
														{ formatNumber( day[ tool.id ]?.views ) }
														<Diff value={ day[ DIFF_KEY[ tool.id ] ]?.views } threshold={ data.threshold } />
													</td>
												</Fragment>
											) ) }
										</tr>
									) ) }
								</tbody>
								<tfoot>
									<tr>
										<th>{ __( 'Totale', 'wp-5w' ) }</th>
										{ tools.map( ( tool ) => (
											<Fragment key={ tool.id }>
												<th className="num">
													{ formatNumber( tool.visitors ) }
													<Diff value={ tool.diff_visitors } threshold={ data.threshold } />
												</th>
												<th className="num">
													{ formatNumber( tool.views ) }
													<Diff value={ tool.diff_views } threshold={ data.threshold } />
												</th>
											</Fragment>
										) ) }
									</tr>
									<tr>
										<th>{ __( 'Visualizzazioni per visitatore', 'wp-5w' ) }</th>
										{ tools.map( ( tool ) => (
											<th key={ tool.id } colSpan={ 2 } className="num">{ formatNumber( totals.views_per_visitor[ tool.id ] ) }</th>
										) ) }
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
							{ __( 'WP Statistics e Independent Analytics contano ogni giorno molti visitatori diretti, solo sulla home, con una visualizzazione e distribuiti sulle 24 ore: è la flotta di browser automatici descritta sopra. Se qui la raccolta propria ne conta molti meno, la differenza è traffico automatico che gli altri due accettano.', 'wp-5w' ) }
						</p>
						<table className="widefat striped wp5w-compact">
							<thead>
								<tr>
									<th />
									<th className="num">{ __( 'Visitatori', 'wp-5w' ) }</th>
									<th className="num">{ __( 'Quota sui visitatori', 'wp-5w' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ detailTools.map( ( tool ) => (
									<tr key={ tool.id }>
										<td>{ tool.label }</td>
										<td className="num">
											{ formatNumber( home[ tool.id ] ) }
											<Diff value={ home[ DIFF_KEY[ tool.id ] ] } threshold={ data.threshold } base={ tool.id === 'log' ? home.wps_log : undefined } />
										</td>
										<td className="num">{ formatNumber( home[ `share_${ tool.id }` ], '%' ) }</td>
									</tr>
								) ) }
							</tbody>
						</table>
						<p className="wp5w-note">
							{ __( 'Giorno per giorno:', 'wp-5w' ) }
							{ home.log_days !== undefined &&
								` ${ sprintf(
									/* translators: %s: giorni con il dettaglio dei log. */
									__( 'i log contano solo i giorni con il dettaglio per contenuto (%s; il totale del log è confrontato con WP Statistics sugli stessi giorni).', 'wp-5w' ),
									formatNumber( home.log_days )
								) }` }
						</p>
						<table className="widefat striped wp5w-compact">
							<thead>
								<tr>
									<th>{ __( 'Giorno', 'wp-5w' ) }</th>
									{ detailTools.map( ( tool ) => (
										<th key={ tool.id } className="num">{ tool.label }</th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ data.days.map( ( day ) => (
									<tr key={ day.day }>
										<td>{ formatDay( day.day ) }</td>
										{ detailTools.map( ( tool ) => (
											<td key={ tool.id } className="num">
												{ formatNumber( day.home_direct[ tool.id ] ) }
												{ tool.id !== 'wps' && <Diff value={ change( day.home_direct[ tool.id ], day.home_direct.wps ) } threshold={ data.threshold } /> }
											</td>
										) ) }
									</tr>
								) ) }
							</tbody>
						</table>

						<h3>{ __( 'Canali', 'wp-5w' ) }</h3>
						<table className="widefat striped wp5w-compact">
							<thead>
								<tr>
									<th>{ __( 'Canale', 'wp-5w' ) }</th>
									{ tools.map( ( tool ) => (
										<th key={ tool.id } className="num">{ tool.label }</th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ channels.map( ( row ) => (
									<tr key={ row.channel }>
										<td>{ CHANNEL_LABELS[ row.channel ] ?? row.channel }</td>
										{ tools.map( ( tool ) => (
											<td key={ tool.id } className="num">
												{ formatNumber( row[ tool.id ] ) }
												<Diff value={ row[ DIFF_KEY[ tool.id ] ] } threshold={ data.threshold } base={ tool.id === 'log' ? row.wps_log : undefined } />
											</td>
										) ) }
									</tr>
								) ) }
							</tbody>
						</table>
						{ data.log_available && (
							<p className="wp5w-note">
								{ __( 'La differenza del log è rispetto a WP Statistics sui soli giorni coperti dai log, quindi non sempre corrisponde alla colonna WP Statistics: passa il mouse sul badge per vedere la base.', 'wp-5w' ) }
							</p>
						) }

						{ data.log_available && (
							<>
								<h3>{ __( 'Bot dai log del server', 'wp-5w' ) }</h3>
								<p className="wp5w-note wp5w-note--top">
									{ sprintf(
										/* translators: 1: visitatori dei bot, 2: visualizzazioni dei bot, 3: giorni coperti dai log. */
										__( 'I log vedono anche i crawler che non eseguono JavaScript: nei %3$s giorni coperti sono %1$s visitatori con %2$s visualizzazioni, fuori dalla colonna «Log del server (browser)». Un visitatore senza nessuna prova di essere un browser (né risorse del sito scaricate né un tracker eseguito) è «senza-js»: un ritorno di un lettore vero con tutto in cache e senza interazioni finisce lì.', 'wp-5w' ),
										formatNumber( totals.logbot.visitors ),
										formatNumber( totals.logbot.views ),
										formatNumber( totals.log_days )
									) }
								</p>
								<table className="widefat striped">
									<thead>
										<tr>
											<th>{ __( 'Bot', 'wp-5w' ) }</th>
											<th className="num">{ __( 'Visitatori', 'wp-5w' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ data.bots.map( ( bot ) => (
											<tr key={ bot.label }>
												<td>{ bot.label }</td>
												<td className="num">{ formatNumber( bot.visitors ) }</td>
											</tr>
										) ) }
									</tbody>
								</table>
							</>
						) }

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
						<div className="wp5w-table-scroll">
							<table className="widefat striped wp5w-compact">
								<thead>
									<tr>
										<th>{ __( 'Contenuto', 'wp-5w' ) }</th>
										{ detailTools.map( ( tool ) => (
											<th key={ tool.id } className="num">{ tool.label }</th>
										) ) }
									</tr>
								</thead>
								<tbody>
									{ top.map( ( row ) => (
										<tr key={ `${ row.type }-${ row.id }` }>
											<td>
												<a href={ row.url } target="_blank" rel="noreferrer">{ row.title }</a>
											</td>
											{ detailTools.map( ( tool ) => (
												<td key={ tool.id } className="num">
													{ formatNumber( row[ `${ tool.id }_views` ] ) }
													<Diff value={ row[ DIFF_KEY[ tool.id ] ] } threshold={ data.threshold } base={ tool.id === 'log' ? row.wps_log_views : undefined } />
												</td>
											) ) }
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
						<p className="wp5w-note">
							{ sprintf(
								/* translators: %s: visualizzazioni minime in una delle origini. */
								__( 'Visualizzazioni sui giorni completi, in ordine di differenza assoluta rispetto a WP Statistics (la più grande tra raccolta propria e Independent Analytics); compaiono i contenuti con almeno %s visualizzazioni in una delle origini. Il log conta solo i giorni con il dettaglio per contenuto, riconosce solo la home e i contenuti singoli (– per archivi e ricerche) e non entra nella classifica.', 'wp-5w' ),
								formatNumber( data.min_views )
							) }
						</p>
					</Card>
				</>
			) }
		</>
	);
}
