import { __ } from '@wordpress/i18n';
import { formatNumber } from '../api';

/**
 * Tabella di query di ricerca (Search Console).
 *
 * @param {Object}  props
 * @param {Array}   props.rows   [{ query, clicks, impressions, ctr, position, clicks_change?, pages? }].
 * @param {boolean} props.extra  Mostra il numero di pagine e, se change, la variazione dei click.
 * @param {boolean} props.change Mostra la variazione dei click (solo se il periodo precedente ha dati).
 * @param {string}  props.empty  Testo quando non ci sono righe.
 */
export default function QueryTable( { rows, extra = false, change = true, empty } ) {
	const showChange = extra && change;
	if ( ! rows.length ) {
		return <p>{ empty ?? __( 'Nessuna ricerca nel periodo.', 'wp-5w' ) }</p>;
	}

	return (
		<table className="widefat striped wp5w-table">
			<thead>
				<tr>
					<th>{ __( 'Query', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Click', 'wp-5w' ) }</th>
					{ showChange && <th className="num">{ __( 'Variazione', 'wp-5w' ) }</th> }
					<th className="num">{ __( 'Impression', 'wp-5w' ) }</th>
					<th className="num">{ __( 'CTR', 'wp-5w' ) }</th>
					<th className="num">{ __( 'Posizione', 'wp-5w' ) }</th>
					{ extra && <th className="num">{ __( 'Pagine', 'wp-5w' ) }</th> }
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<tr key={ row.query }>
						<td>{ row.query }</td>
						<td className="num">{ formatNumber( row.clicks ) }</td>
						{ showChange && (
							<td className={ `num ${ row.clicks_change > 0 ? 'is-good' : '' } ${ row.clicks_change < 0 ? 'is-bad' : '' }` }>
								{ row.clicks_change > 0 ? '+' : '' }
								{ formatNumber( row.clicks_change ) }
							</td>
						) }
						<td className="num">{ formatNumber( row.impressions ) }</td>
						<td className="num">{ formatNumber( row.ctr, '%' ) }</td>
						<td className="num">{ formatNumber( row.position ) }</td>
						{ extra && <td className="num">{ formatNumber( row.pages ) }</td> }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
