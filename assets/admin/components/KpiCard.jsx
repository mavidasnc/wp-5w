import { formatNumber } from '../api';

/**
 * Indicatore con valore e variazione rispetto al periodo precedente.
 *
 * @param {Object}  props
 * @param {string}  props.label   Etichetta.
 * @param {Object}  props.data    { value, previous, change }.
 * @param {string}  props.suffix  Suffisso del valore.
 * @param {boolean} props.inverse Se true un calo è positivo (posizione media).
 */
export default function KpiCard( { label, data, suffix = '', inverse = false } ) {
	const { value, change } = data;
	const good = change === null ? null : ( change > 0 ) !== inverse;

	return (
		<div className="wp5w-card wp5w-kpi">
			<span className="wp5w-kpi__label">{ label }</span>
			<strong className="wp5w-kpi__value">{ formatNumber( value, suffix ) }</strong>
			{ change !== null && change !== 0 && (
				<span className={ `wp5w-kpi__change ${ good ? 'is-good' : 'is-bad' }` }>
					{ change > 0 ? '▲' : '▼' } { formatNumber( Math.abs( change ), '%' ) }
				</span>
			) }
		</div>
	);
}
