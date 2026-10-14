import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { api, formatNumber } from '../api';
import Card from './Card';
import QueryTable from './QueryTable';
import ShowMore from './ShowMore';

/**
 * Tabella delle pagine di Search Console; il titolo apre il dettaglio quando la pagina è collegata a un contenuto.
 *
 * @param {Object}   props
 * @param {Array}    props.pages  Pagine restituite da /admin/search/content.
 * @param {Function} props.onOpen Apertura del dettaglio.
 */
function PagesTable( { pages, onOpen } ) {
	if ( ! pages.length ) {
		return <p>{ __( 'Nessuna pagina nel periodo.', 'wp-5w' ) }</p>;
	}

	return (
		<table className="widefat striped wp5w-table">
			<thead>
				<tr>
					<th>{ __( 'Pagina', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Click', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Impression', 'wp-5w' ) }</th>
					<th className="num">{ __( 'CTR', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Posizione', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Query', 'wp-5w' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ pages.map( ( row ) => (
					<tr key={ row.page }>
						<td>
							{ row.id > 0 ? (
								<Button variant="link" onClick={ () => onOpen( { id: row.id, title: row.title } ) }>
									{ row.title }
								</Button>
							) : (
								row.title
							) }
							<a className="wp5w-edit" href={ row.url } target="_blank" rel="noreferrer">
								{ __( 'Apri', 'wp-5w' ) }
							</a>
						</td>
						<td className="num">{ formatNumber( row.clicks ) }</td>
						<td className="num">{ formatNumber( row.impressions ) }</td>
						<td className="num">{ formatNumber( row.ctr, '%' ) }</td>
						<td className="num">{ formatNumber( row.position ) }</td>
						<td className="num">{ formatNumber( row.queries ) }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * Box di Search Console della Panoramica: opportunità affiancate e pagine; carica da /admin/search/content.
 *
 * @param {Object}   props
 * @param {Object}   props.range  { from, to } della Panoramica.
 * @param {Function} props.onOpen Apertura del dettaglio di un contenuto.
 */
export default function SearchContent( { range, onOpen } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		setData( null );
		setError( null );
		api( `/admin/search/content?from=${ range.from }&to=${ range.to }` )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [ range.from, range.to ] );

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}

	const empty = __( 'Nessuna query in questa fascia.', 'wp-5w' );

	return (
		<>
			<div className="wp5w-columns">
				<section className="wp5w-card">
					<h2>{ __( 'Quasi in prima pagina', 'wp-5w' ) }</h2>
					<p className="wp5w-note wp5w-note--top">
						{ __( 'Posizione media tra 8 e 20, almeno 20 impression: basta poco per salire.', 'wp-5w' ) }
					</p>
					<ShowMore rows={ data.opportunities.near_first } size={ 10 }>
						{ ( rows ) => <QueryTable rows={ rows } empty={ empty } /> }
					</ShowMore>
				</section>
				<section className="wp5w-card">
					<h2>{ __( 'In alto ma poco cliccate', 'wp-5w' ) }</h2>
					<p className="wp5w-note wp5w-note--top">
						{ __( 'Posizione fino a 5 e CTR sotto il 2%: titolo e descrizione da rivedere.', 'wp-5w' ) }
					</p>
					<ShowMore rows={ data.opportunities.low_ctr } size={ 10 }>
						{ ( rows ) => <QueryTable rows={ rows } empty={ empty } /> }
					</ShowMore>
				</section>
			</div>

			<Card id="gsc-pages" title={ __( 'Pagine su Google', 'wp-5w' ) }>
				<ShowMore rows={ data.pages }>{ ( pages ) => <PagesTable pages={ pages } onOpen={ onOpen } /> }</ShowMore>
			</Card>
		</>
	);
}
