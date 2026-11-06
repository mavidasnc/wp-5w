import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

// Periodi selezionabili in ogni scheda e in ogni box: oggi (1 giorno) e gli ultimi N giorni, compreso oggi.
export const PERIODS = [
	{ days: 1, label: __( 'Oggi', 'wp-5w' ) },
	{ days: 7, label: __( '7 giorni', 'wp-5w' ) },
	{ days: 30, label: __( '30 giorni', 'wp-5w' ) },
	{ days: 90, label: __( '90 giorni', 'wp-5w' ) },
];

/**
 * I pulsanti del periodo (Oggi, 7, 30 e 90 giorni, oppure quelli di `periods`) nell'intestazione di un box o di una scheda.
 *
 * @param {Object}   props
 * @param {number|string} props.days Periodo selezionato.
 * @param {Function} props.onChange  Chiamata con il nuovo periodo.
 * @param {Array}    props.periods   Periodi { days, label } (facoltativo, predefiniti i quattro di PERIODS).
 */
export default function PeriodSwitch( { days, onChange, periods = PERIODS } ) {
	return (
		<div className="wp5w-switch">
			{ periods.map( ( period ) => (
				<Button key={ period.days } size="compact" variant={ days === period.days ? 'primary' : 'secondary' } onClick={ () => onChange( period.days ) }>
					{ period.label }
				</Button>
			) ) }
		</div>
	);
}
