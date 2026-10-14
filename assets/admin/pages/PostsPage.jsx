import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { api, formatDay, formatNumber } from '../api';
import Sparkline from '../components/Sparkline';
import PostDetailModal from '../components/PostDetailModal';
import Card from '../components/Card';
import { TrendBadge } from '../components/Trend';

/**
 * Dati di un endpoint, con stato di caricamento ed errore.
 *
 * @param {string} path Percorso REST.
 */
const useEndpoint = ( path ) => {
	const [ state, setState ] = useState( { data: null, error: null } );
	useEffect( () => {
		setState( { data: null, error: null } );
		api( path )
			.then( ( data ) => setState( { data, error: null } ) )
			.catch( ( e ) => setState( { data: null, error: e.message } ) );
	}, [ path ] );
	return state;
};

/**
 * Tabella di articoli con sparkline della settimana; il titolo apre il dettaglio.
 *
 * @param {Object}   props
 * @param {Object}   props.state   { data, error } dell'endpoint.
 * @param {Array}    props.columns [{ label, value: ( row ) => string }] colonne dopo la sparkline.
 * @param {Function} props.onOpen  Apertura del dettaglio.
 * @param {boolean}  props.showDate Mostra la data di pubblicazione.
 */
function PostsTable( { state, columns, onOpen, showDate = false } ) {
	if ( state.error ) {
		return <Notice status="error" isDismissible={ false }>{ state.error }</Notice>;
	}
	if ( ! state.data ) {
		return <Spinner />;
	}
	if ( ! state.data.length ) {
		return <p>{ __( 'Nessun articolo nel periodo.', 'wp-5w' ) }</p>;
	}

	return (
		<table className="widefat striped wp5w-table wp5w-posts">
			<thead>
				<tr>
					<th>{ __( 'Articolo', 'wp-5w' ) }</th>
					{ showDate && <th>{ __( 'Pubblicato', 'wp-5w' ) }</th> }
					<th>{ __( 'Ultimi 7 giorni', 'wp-5w' ) }</th>
					<th className="num" title={ __( 'Ultime 4 settimane complete contro le 4 precedenti', 'wp-5w' ) }>
						{ __( 'Trend', 'wp-5w' ) }
					</th>
					{ columns.map( ( column ) => (
						<th key={ column.label } className="num">
							{ column.label }
						</th>
					) ) }
				</tr>
			</thead>
			<tbody>
				{ state.data.map( ( row ) => (
					<tr key={ row.id }>
						<td>
							<Button variant="link" onClick={ () => onOpen( row ) }>
								{ row.title }
							</Button>
						</td>
						{ showDate && <td>{ formatDay( row.date ) }</td> }
						<td>
							<Sparkline points={ row.spark } />
						</td>
						<td className="num">
							<TrendBadge trend={ row.trend } />
						</td>
						{ columns.map( ( column ) => (
							<td key={ column.label } className="num">
								{ column.value( row ) }
							</td>
						) ) }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

export default function PostsPage() {
	const [ days, setDays ] = useState( 7 );
	const [ detail, setDetail ] = useState( null );
	const latest = useEndpoint( '/admin/posts/latest' );
	const top = useEndpoint( `/admin/posts/top?days=${ days }` );

	// Visualizzazioni con i visitatori tra parentesi.
	const pair = ( totals ) => `${ formatNumber( totals.views ) } (${ formatNumber( totals.visitors ) })`;

	return (
		<div className="wp5w-posts-page">
			<Card id="posts-latest" title={ __( 'Ultimi articoli pubblicati', 'wp-5w' ) }>
				<PostsTable
					state={ latest }
					showDate
					onOpen={ ( row ) => setDetail( { post: row, period: 'pub' } ) }
					columns={ [
						{ label: __( 'Settimana', 'wp-5w' ), value: ( row ) => pair( row.week ) },
						{
							label: __( 'Dalla pubblicazione', 'wp-5w' ),
							value: ( row ) => (
								<>
									{ pair( row.total ) }
									{ row.since !== row.date && (
										<span className="wp5w-type">
											{ __( 'dal', 'wp-5w' ) } { formatDay( row.since ) }
										</span>
									) }
								</>
							),
						},
					] }
				/>
				<p className="wp5w-note">
					{ __( 'Visualizzazioni e, tra parentesi, visitatori (una persona conta una volta per ogni giorno di visita).', 'wp-5w' ) }
				</p>
			</Card>

			<Card
				id="posts-top"
				title={ __( 'Articoli più visti', 'wp-5w' ) }
				actions={
					<div className="wp5w-switch">
						<Button size="compact" variant={ days === 7 ? 'primary' : 'secondary' } onClick={ () => setDays( 7 ) }>
							{ __( 'Settimana', 'wp-5w' ) }
						</Button>
						<Button size="compact" variant={ days === 30 ? 'primary' : 'secondary' } onClick={ () => setDays( 30 ) }>
							{ __( 'Mese', 'wp-5w' ) }
						</Button>
					</div>
				}
			>
				<PostsTable
					state={ top }
					onOpen={ ( row ) => setDetail( { post: row, period: String( days ) } ) }
					columns={ [ { label: days === 7 ? __( 'Settimana', 'wp-5w' ) : __( 'Mese', 'wp-5w' ), value: ( row ) => pair( row.period ) } ] }
				/>
			</Card>

			{ detail && (
				<PostDetailModal post={ detail.post } initialPeriod={ detail.period } onClose={ () => setDetail( null ) } />
			) }
		</div>
	);
}
