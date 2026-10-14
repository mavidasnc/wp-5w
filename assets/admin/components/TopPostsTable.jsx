import { __ } from '@wordpress/i18n';
import { formatNumber } from '../api';

// Etichette dei tipi di contenuto registrati da WP Statistics.
const TYPES = {
	post: __( 'Articolo', 'wp-5w' ),
	page: __( 'Pagina', 'wp-5w' ),
	post_type_attachment: __( 'Allegato', 'wp-5w' ),
};

/**
 * Classifica dei contenuti: traffico di WP Statistics e Search Console di Rank Math sulla stessa riga.
 *
 * @param {Object} props
 * @param {Array}  props.rows Contenuti restituiti da /admin/overview.
 */
export default function TopPostsTable( { rows } ) {
	if ( ! rows.length ) {
		return <p>{ __( 'Nessun dato nel periodo.', 'wp-5w' ) }</p>;
	}

	return (
		<table className="widefat striped wp5w-table">
			<thead>
				<tr>
					<th>{ __( 'Contenuto', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Visualizzazioni', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Ingressi', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Di cui da ricerca', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Click Google', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Impression', 'wp-5w' ) }</th>
					<th className="num">{ __( 'CTR', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Posizione', 'wp-5w' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<tr key={ `${ row.type }-${ row.id }` }>
						<td>
							<a href={ row.url } target="_blank" rel="noreferrer">
								{ row.title }
							</a>
							<span className="wp5w-type">{ TYPES[ row.type ] ?? row.type }</span>
							{ row.edit_url && (
								<a className="wp5w-edit" href={ row.edit_url }>
									{ __( 'Modifica', 'wp-5w' ) }
								</a>
							) }
						</td>
						<td className="num">{ formatNumber( row.views ) }</td>
						<td className="num">{ formatNumber( row.entries ) }</td>
						<td className="num">{ formatNumber( row.search_entries ) }</td>
						<td className="num">{ formatNumber( row.clicks ) }</td>
						<td className="num">{ formatNumber( row.impressions ) }</td>
						<td className="num">{ formatNumber( row.ctr, '%' ) }</td>
						<td className="num">{ formatNumber( row.position ) }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
