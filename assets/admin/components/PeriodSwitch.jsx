import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

// Periodi selezionabili (ultimi N giorni, compreso oggi).
const PERIODS = [
	{ days: 7, label: __( '7 giorni', 'wp-5w' ) },
	{ days: 30, label: __( '30 giorni', 'wp-5w' ) },
	{ days: 90, label: __( '90 giorni', 'wp-5w' ) },
];

/**
 * I tre pulsanti del periodo (7, 30 e 90 giorni) nell'intestazione di un box.
 *
 * @param {Object}   props
 * @param {number}   props.days     Periodo selezionato.
 * @param {Function} props.onChange Chiamata con il nuovo numero di giorni.
 */
export default function PeriodSwitch( { days, onChange } ) {
	return (
		<div className="wp5w-switch">
			{ PERIODS.map( ( period ) => (
				<Button key={ period.days } size="compact" variant={ days === period.days ? 'primary' : 'secondary' } onClick={ () => onChange( period.days ) }>
					{ period.label }
				</Button>
			) ) }
		</div>
	);
}
