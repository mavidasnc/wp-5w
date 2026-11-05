import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';
import ShowMore from './ShowMore';

/**
 * Indirizzo della rotta /admin/visits: il referrer vuoto («diretto») si invia, quello assente no.
 *
 * @param {Object}  filter
 * @param {string}  filter.type     Tipo di contenuto.
 * @param {number}  filter.id       ID del contenuto.
 * @param {number}  filter.days     Giorni fino a oggi.
 * @param {?string} filter.referrer Referrer (null = qualunque, '' = diretto).
 * @param {?string} filter.ip       Hash dell'IP (16 caratteri) o null.
 */
const visitsPath = ( { type, id, days, referrer = null, ip = null } ) => {
	const params = new URLSearchParams( { type, id: String( id ), days: String( days ) } );
	if ( referrer !== null ) {
		params.set( 'referrer', referrer );
	}
	if ( ip ) {
		params.set( 'ip', ip );
	}
	return `/admin/visits?${ params.toString() }`;
};

// Valori vuoti mostrati come trattino.
const text = ( value ) => value || '–';

/**
 * Singole visite di un contenuto (orario, hash dell'IP, paese, dispositivo, browser, sistema, bot e user agent),
 * con i conteggi per hash dell'IP e per user agent. Solo raccolta propria; carica da /admin/visits.
 * Lo user agent è scelto da chi visita: si mostra solo come testo.
 *
 * @param {Object} props
 * @param {Object} props.filter Vedi visitsPath().
 */
export default function VisitsDetail( { filter } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const path = visitsPath( filter );

	useEffect( () => {
		let active = true;
		setData( null );
		setError( null );
		api( path )
			.then( ( result ) => active && setData( result ) )
			.catch( ( e ) => active && setError( e.message ) );
		return () => {
			active = false;
		};
	}, [ path ] );

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}

	const { totals } = data;
	return (
		<div className="wp5w-visits">
			<p>
				<a href={ data.content.url } target="_blank" rel="noreferrer">{ data.content.title }</a>
				{ ' · ' }
				{ data.filter.referrer === null ? __( 'tutte le provenienze', 'wp-5w' ) : data.filter.label || __( '(diretto)', 'wp-5w' ) }
				{ data.filter.ip && ` · IP ${ data.filter.ip }` }
				{ ' · ' }
				{ formatDay( data.range.from ) } – { formatDay( data.range.to ) }
				{ ' · ' }
				{ sprintf(
					/* translators: 1: visite, 2: visitatori, 3: IP diversi. */
					__( '%1$s visite, %2$s visitatori, %3$s IP diversi', 'wp-5w' ),
					formatNumber( totals.visits ),
					formatNumber( totals.visitors ),
					data.ip_first ? formatNumber( totals.ips ) : __( 'n.d.', 'wp-5w' )
				) }
			</p>

			<div className="wp5w-columns">
				<section>
					<h3>{ __( 'Visite per hash dell\'IP', 'wp-5w' ) }</h3>
					<table className="widefat striped wp5w-table">
						<thead>
							<tr>
								<th>{ __( 'IP (hash)', 'wp-5w' ) }</th>
								<th className="num">{ __( 'Visite', 'wp-5w' ) }</th>
								<th>{ __( 'Prima', 'wp-5w' ) }</th>
								<th>{ __( 'Ultima', 'wp-5w' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ data.by_ip.map( ( row ) => (
								<tr key={ row.ip || 'nd' }>
									<td><code>{ row.ip || __( 'n.d.', 'wp-5w' ) }</code></td>
									<td className="num">{ formatNumber( row.visits ) }</td>
									<td className="wp5w-nowrap">{ row.first }</td>
									<td className="wp5w-nowrap">{ row.last }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</section>
				<section>
					<h3>{ __( 'Visite per user agent', 'wp-5w' ) }</h3>
					<table className="widefat striped wp5w-table">
						<thead>
							<tr>
								<th>{ __( 'User agent', 'wp-5w' ) }</th>
								<th className="num">{ __( 'Visite', 'wp-5w' ) }</th>
								<th className="num">{ __( 'IP', 'wp-5w' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ data.by_ua.map( ( row ) => (
								<tr key={ row.ua || 'nd' }>
									<td className="wp5w-ua">{ row.ua || __( 'n.d.', 'wp-5w' ) }</td>
									<td className="num">{ formatNumber( row.visits ) }</td>
									<td className="num">{ row.ua ? formatNumber( row.ips ) : __( 'n.d.', 'wp-5w' ) }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</section>
			</div>

			<h3>{ __( 'Visite, dalla più recente', 'wp-5w' ) }</h3>
			<div className="wp5w-table-scroll">
				<ShowMore rows={ data.rows } size={ 50 }>
					{ ( rows ) => (
						<table className="widefat striped wp5w-table">
							<thead>
								<tr>
									<th>{ __( 'Orario', 'wp-5w' ) }</th>
									<th>{ __( 'IP (hash)', 'wp-5w' ) }</th>
									<th>{ __( 'Paese', 'wp-5w' ) }</th>
									<th>{ __( 'Dispositivo', 'wp-5w' ) }</th>
									<th>{ __( 'Browser', 'wp-5w' ) }</th>
									<th>{ __( 'Sistema', 'wp-5w' ) }</th>
									<th>{ __( 'Bot', 'wp-5w' ) }</th>
									<th>{ __( 'User agent', 'wp-5w' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ rows.map( ( row, index ) => (
									<tr key={ `${ row.time }-${ index }` }>
										<td className="wp5w-nowrap">{ row.time }</td>
										<td><code>{ row.ip || __( 'n.d.', 'wp-5w' ) }</code></td>
										<td>{ text( row.country ) }</td>
										<td>{ text( row.device ) }</td>
										<td>{ text( row.browser ) }</td>
										<td>{ text( row.os ) }</td>
										<td>{ text( row.bot ) }</td>
										<td className="wp5w-ua">{ row.ua || __( 'n.d.', 'wp-5w' ) }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</ShowMore>
			</div>

			<p className="wp5w-note">
				{ __( 'Dati della raccolta propria; orari nel fuso del sito. L\'IP è un hash (stesso IP, stesso hash): l\'indirizzo in chiaro non viene salvato.', 'wp-5w' ) }{ ' ' }
				{ data.ip_first
					? sprintf(
						/* translators: %s: primo giorno con l'hash dell'IP (gg/mm/aaaa). */
						__( 'Hash dell\'IP e user agent disponibili dal %s e cancellati dopo i giorni impostati nelle Opzioni («n.d.» per le visite precedenti).', 'wp-5w' ),
						formatDay( data.ip_first )
					)
					: __( 'Nessuna visita ha ancora l\'hash dell\'IP e lo user agent: arrivano con la versione 0.19.0.', 'wp-5w' ) }
				{ totals.visits > data.rows.length &&
					` ${ sprintf(
						/* translators: 1: righe mostrate, 2: visite totali. */
						__( 'Sono mostrate le %1$d visite più recenti su %2$s; i conteggi qui sopra le comprendono tutte.', 'wp-5w' ),
						data.rows.length,
						formatNumber( totals.visits )
					) }` }
			</p>
		</div>
	);
}
