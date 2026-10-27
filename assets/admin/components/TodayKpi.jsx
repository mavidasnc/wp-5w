import { __, sprintf } from '@wordpress/i18n';
import { formatNumber } from '../api';

// Confronti mostrati, con i giorni interi che la media dovrebbe avere.
const ROWS = [
	{ key: 'yesterday', label: __( 'Ieri', 'wp-5w' ), size: 1 },
	{ key: 'avg7', label: __( 'Media 7 giorni', 'wp-5w' ), size: 7 },
	{ key: 'avg30', label: __( 'Media 30 giorni', 'wp-5w' ), size: 30 },
];

/**
 * Variazione con freccia e colore (più traffico è un risultato positivo), «=» se uguale, trattino se manca il termine di confronto.
 *
 * @param {Object}  props
 * @param {?number} props.change Variazione in percentuale.
 */
function Change( { change } ) {
	if ( change === null || change === undefined ) {
		return <span className="wp5w-kpi__change">–</span>;
	}
	if ( change === 0 ) {
		return <span className="wp5w-kpi__change">=</span>;
	}
	return (
		<span className={ `wp5w-kpi__change ${ change > 0 ? 'is-good' : 'is-bad' }` }>
			{ change > 0 ? '▲' : '▼' } { formatNumber( Math.abs( change ), '%' ) }
		</span>
	);
}

/**
 * Indicatore di oggi: valore fino a adesso e, per ieri e le medie di 7 e 30 giorni, il valore alla stessa ora con la variazione
 * e, in piccolo, il totale della giornata intera.
 *
 * @param {Object} props
 * @param {string} props.label Etichetta.
 * @param {Object} props.data  { value, full, compare: { yesterday|avg7|avg30: { days, at, full, change } } }.
 */
export default function TodayKpi( { label, data } ) {
	return (
		<div className="wp5w-card wp5w-kpi wp5w-today-kpi">
			<span className="wp5w-kpi__label">{ label }</span>
			<strong className="wp5w-kpi__value">{ formatNumber( data.value ) }</strong>
			<ul className="wp5w-today-kpi__rows">
				{ ROWS.map( ( { key, label: rowLabel, size } ) => {
					const row = data.compare[ key ];
					return (
						<li key={ key }>
							<span className="wp5w-today-kpi__name">{ rowLabel }</span>
							<span className="wp5w-today-kpi__value">{ formatNumber( row.at ) }</span>
							<Change change={ row.change } />
							{ row.days > 0 && (
								<span className="wp5w-note wp5w-today-kpi__full">
									{ __( 'giornata intera', 'wp-5w' ) } { formatNumber( row.full ) }
									{ row.days < size && ` · ${ sprintf( /* translators: %d: giorni su cui è calcolata la media. */ __( 'su %d giorni', 'wp-5w' ), row.days ) }` }
								</span>
							) }
							{ row.days === 0 && <span className="wp5w-note wp5w-today-kpi__full">{ __( 'nessun giorno intero disponibile', 'wp-5w' ) }</span> }
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}
