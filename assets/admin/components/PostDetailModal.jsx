import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Modal, Notice, SelectControl, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber, shiftDay, today } from '../api';
import KpiCard from './KpiCard';
import TrendChart from './TrendChart';
import ChannelList from './ChannelList';
import QueryTable from './QueryTable';

// Periodi del dettaglio: giorni fino a oggi oppure dalla pubblicazione.
export const DETAIL_PERIODS = [
	{ value: '7', label: __( 'Ultimi 7 giorni', 'wp-5w' ) },
	{ value: '30', label: __( 'Ultimi 30 giorni', 'wp-5w' ) },
	{ value: '90', label: __( 'Ultimi 90 giorni', 'wp-5w' ) },
	{ value: 'pub', label: __( 'Dalla pubblicazione', 'wp-5w' ) },
];

const KPIS = [
	{ key: 'views', label: __( 'Visualizzazioni', 'wp-5w' ) },
	{ key: 'visitors', label: __( 'Visitatori', 'wp-5w' ) },
	{ key: 'entries', label: __( 'Ingressi', 'wp-5w' ) },
	{ key: 'search_entries', label: __( 'Ingressi da ricerca', 'wp-5w' ) },
	{ key: 'clicks', label: __( 'Click Google', 'wp-5w' ) },
	{ key: 'impressions', label: __( 'Impression', 'wp-5w' ) },
	{ key: 'ctr', label: __( 'CTR', 'wp-5w' ), suffix: '%' },
	{ key: 'position', label: __( 'Posizione media', 'wp-5w' ) },
];

/**
 * Intervallo di un periodo; "dalla pubblicazione" parte dalla data del post
 * (il server lo accorcia al primo giorno con dati).
 *
 * @param {string}  period Periodo.
 * @param {?string} date   Data di pubblicazione (Y-m-d).
 */
const rangeFor = ( period, date ) => ( {
	from: period === 'pub' ? date || '1970-01-01' : shiftDay( today, -( Number( period ) - 1 ) ),
	to: today,
} );

/**
 * Dettaglio di un contenuto: KPI, andamento, ricerche su Google e provenienza.
 *
 * @param {Object}   props
 * @param {Object}   props.post          { id, title, date? }.
 * @param {string}   props.initialPeriod Periodo iniziale (vedi DETAIL_PERIODS).
 * @param {Function} props.onClose       Chiusura del modale.
 */
export default function PostDetailModal( { post, initialPeriod = '30', onClose } ) {
	const [ period, setPeriod ] = useState( initialPeriod );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		const { from, to } = rangeFor( period, post.date );
		setData( null );
		setError( null );
		api( `/admin/posts/${ post.id }?from=${ from }&to=${ to }` )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [ period, post.id, post.date ] );

	return (
		<Modal title={ post.title } onRequestClose={ onClose } size="large" className="wp5w-modal">
			<div className="wp5w-toolbar">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Periodo', 'wp-5w' ) }
					value={ period }
					options={ DETAIL_PERIODS }
					onChange={ setPeriod }
				/>
				{ data && (
					<p className="wp5w-toolbar__compare">
						{ formatDay( data.range.from ) } – { formatDay( data.range.to ) }
						{ ' · ' }
						<a href={ data.post.url } target="_blank" rel="noreferrer">
							{ __( 'Apri', 'wp-5w' ) }
						</a>
						{ data.post.edit_url && (
							<>
								{ ' · ' }
								<a href={ data.post.edit_url }>{ __( 'Modifica', 'wp-5w' ) }</a>
							</>
						) }
					</p>
				) }
			</div>

			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }

			{ data && (
				<>
					<div className="wp5w-kpis">
						{ KPIS.map( ( { key, ...kpi } ) => (
							<KpiCard key={ key } { ...kpi } data={ { value: data.kpis[ key ], change: null } } />
						) ) }
					</div>

					<section className="wp5w-card">
						<h2>{ __( 'Andamento', 'wp-5w' ) }</h2>
						<TrendChart series={ data.series } keys={ [ 'views', 'visitors', 'clicks' ] } />
						<p className="wp5w-note">
							{ __( 'Search Console: gli ultimi 2-3 giorni sono incompleti.', 'wp-5w' ) }
						</p>
					</section>

					<div className="wp5w-grid">
						<section className="wp5w-card wp5w-grid__main">
							<h2>{ __( 'Ricerche su Google', 'wp-5w' ) }</h2>
							<QueryTable rows={ data.queries } empty={ __( 'Nessuna ricerca registrata da Search Console nel periodo.', 'wp-5w' ) } />
						</section>

						<section className="wp5w-card">
							<h2>{ __( 'Canali di ingresso', 'wp-5w' ) }</h2>
							<ChannelList channels={ data.channels } />

							<h2 className="wp5w-card__subtitle">{ __( 'Provenienza', 'wp-5w' ) }</h2>
							{ data.referrers.length ? (
								<table className="widefat striped wp5w-table">
									<tbody>
										{ data.referrers.map( ( row ) => (
											<tr key={ row.referrer }>
												<td>
													{ row.referrer }
													{ row.source && <span className="wp5w-type">{ row.source }</span> }
												</td>
												<td className="num">{ formatNumber( row.visitors ) }</td>
											</tr>
										) ) }
									</tbody>
								</table>
							) : (
								<p>{ __( 'Nessun sito di provenienza nel periodo.', 'wp-5w' ) }</p>
							) }
						</section>
					</div>
				</>
			) }
		</Modal>
	);
}
