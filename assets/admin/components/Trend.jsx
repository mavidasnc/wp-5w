import { __, sprintf } from '@wordpress/i18n';
import { formatDay, formatNumber } from '../api';

// Etichette delle componenti (vedi Support\Trend::WEIGHTS) e dello stato.
const COMPONENTS = {
	visitors: __( 'Visitatori', 'wp-5w' ),
	visibility: __( 'Visibilità su Google', 'wp-5w' ),
	position: __( 'Posizione media', 'wp-5w' ),
};

const STATUS = {
	up: { arrow: '▲', label: __( 'In miglioramento', 'wp-5w' ) },
	down: { arrow: '▼', label: __( 'In calo', 'wp-5w' ) },
	stable: { arrow: '►', label: __( 'Stabile', 'wp-5w' ) },
};

const INSUFFICIENT = __( 'Dati insufficienti: meno di 20 visitatori in entrambi i periodi.', 'wp-5w' );

/**
 * Punteggio con segno (es. +23).
 *
 * @param {number} score Punteggio.
 */
const signed = ( score ) => ( score > 0 ? `+${ score }` : String( score ) );

/**
 * Classe colore di un contributo: positivo, negativo o neutro.
 *
 * @param {?number} value Contributo da -100 a +100.
 */
const tone = ( value ) => {
	if ( value === null || value === 0 ) {
		return '';
	}
	return value > 0 ? 'is-good' : 'is-bad';
};

/**
 * Periodo leggibile.
 *
 * @param {Object} range { from, to }.
 */
const period = ( range ) => `${ formatDay( range.from ) } – ${ formatDay( range.to ) }`;

/**
 * Freccia e punteggio del trend di un contenuto; il dettaglio delle componenti è nel tooltip.
 *
 * @param {Object}  props
 * @param {?Object} props.trend { score, status, components } da TrendQuery.
 */
export function TrendBadge( { trend } ) {
	if ( ! trend ) {
		return '–';
	}
	if ( trend.status === 'insufficient' ) {
		return (
			<span className="wp5w-trend-badge" title={ INSUFFICIENT }>
				n.d.
			</span>
		);
	}
	if ( trend.status === 'new' ) {
		return (
			<span className="wp5w-trend-badge" title={ __( 'Pubblicato da meno di 8 settimane: non c’è un periodo precedente da confrontare.', 'wp-5w' ) }>
				{ __( 'nuovo', 'wp-5w' ) }
			</span>
		);
	}

	// Tooltip: stato e, per ogni componente con dati, valore precedente → attuale.
	const title = [
		STATUS[ trend.status ].label,
		...Object.entries( trend.components )
			.filter( ( [ , component ] ) => component.value !== null )
			.map( ( [ key, component ] ) => `${ COMPONENTS[ key ] }: ${ formatNumber( component.previous ) } → ${ formatNumber( component.current ) }` ),
	].join( '\n' );

	return (
		<span className={ `wp5w-trend-badge is-${ trend.status }` } title={ title }>
			{ STATUS[ trend.status ].arrow } { signed( trend.score ) }
		</span>
	);
}

/**
 * Card del trend del sito: punteggio, stato, componenti e periodi confrontati.
 *
 * @param {Object} props
 * @param {Object} props.trend { score, status, components, windows } da TrendQuery::site().
 */
export function TrendCard( { trend } ) {
	const { windows } = trend;
	const insufficient = trend.status === 'insufficient';

	return (
		<section className="wp5w-card wp5w-trend">
			<div className="wp5w-trend__score">
				<span className="wp5w-kpi__label">{ __( 'Trend 4 settimane', 'wp-5w' ) }</span>
				<strong className={ `wp5w-kpi__value wp5w-trend-badge is-${ trend.status }` }>
					{ insufficient ? 'n.d.' : `${ STATUS[ trend.status ].arrow } ${ signed( trend.score ) }` }
				</strong>
				<span className="wp5w-note">{ insufficient ? INSUFFICIENT : STATUS[ trend.status ].label }</span>
			</div>
			<ul className="wp5w-trend__components">
				{ Object.entries( trend.components ).map( ( [ key, component ] ) => (
					<li key={ key } className={ tone( component.value ) }>
						<span>{ COMPONENTS[ key ] }</span>
						<strong>
							{ formatNumber( component.previous ) } → { formatNumber( component.current ) }
						</strong>
						<span className="wp5w-note">
							{ component.value === null
								? __( 'nessun dato, esclusa', 'wp-5w' )
								: /* translators: %s: contributo da -100 a +100. */ sprintf( __( 'contributo %s', 'wp-5w' ), signed( Math.round( component.value ) ) ) }
						</span>
					</li>
				) ) }
			</ul>
			<p className="wp5w-note wp5w-trend__note">
				{ __( 'Settimane complete a confronto. Visite:', 'wp-5w' ) } { period( windows.traffic.current ) } { __( 'contro', 'wp-5w' ) }{ ' ' }
				{ period( windows.traffic.previous ) }.
				{ windows.gsc && (
					<>
						{ ' ' }
						{ __( 'Google:', 'wp-5w' ) } { period( windows.gsc.current ) } { __( 'contro', 'wp-5w' ) } { period( windows.gsc.previous ) }.
					</>
				) }{ ' ' }
				{ __( 'Pesi: visitatori 50%, pagine con impression 25%, posizione media 25%.', 'wp-5w' ) }
			</p>
		</section>
	);
}
