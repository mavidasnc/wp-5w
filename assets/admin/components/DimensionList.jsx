import { __ } from '@wordpress/i18n';
import { formatNumber } from '../api';

// Valore non riconosciuto: stesso testo in tutti gli elenchi.
const UNKNOWN = '(n/d)';

/**
 * Un elenco con barra proporzionale (stesse classi di ChannelList).
 *
 * @param {Object}   props
 * @param {string}   props.title Titolo dell'elenco.
 * @param {Object}   props.dim   { total, rows, other }.
 * @param {Function} props.label Etichetta di un valore.
 */
export default function DimensionList( { title, dim, label } ) {
	const share = ( visitors ) => Math.round( ( visitors / dim.total ) * 1000 ) / 10;
	const rows = dim.other > 0 ? [ ...dim.rows, { value: null, visitors: dim.other } ] : dim.rows;

	return (
		<div>
			<h3>{ title }</h3>
			{ dim.total > 0 ? (
				<ul className="wp5w-channels">
					{ rows.map( ( row ) => (
						<li key={ row.value ?? 'other' }>
							<div className="wp5w-channels__row">
								<span>{ row.value === null ? __( 'Altri', 'wp-5w' ) : row.value === UNKNOWN ? __( 'Non riconosciuto', 'wp-5w' ) : label( row.value ) }</span>
								<span className="wp5w-channels__value">
									{ formatNumber( row.visitors ) } ({ formatNumber( share( row.visitors ), '%' ) })
								</span>
							</div>
							<span className="wp5w-channels__bar" style={ { width: `${ share( row.visitors ) }%` } } />
						</li>
					) ) }
				</ul>
			) : (
				<p>{ __( 'Nessun dato nel periodo.', 'wp-5w' ) }</p>
			) }
		</div>
	);
}
