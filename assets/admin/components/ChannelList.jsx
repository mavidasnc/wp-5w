import { __ } from '@wordpress/i18n';
import { formatNumber } from '../api';

// Nomi dei canali di WP Statistics (source_channel) e il canale «ai», che il plugin calcola in aggregazione.
export const LABELS = {
	direct: __( 'Diretto', 'wp-5w' ),
	search: __( 'Motori di ricerca', 'wp-5w' ),
	social: __( 'Social', 'wp-5w' ),
	referral: __( 'Siti esterni', 'wp-5w' ),
	video: __( 'Video', 'wp-5w' ),
	email: __( 'Email', 'wp-5w' ),
	ai: __( 'Assistenti AI', 'wp-5w' ),
	bot: __( 'Bot e browser automatici', 'wp-5w' ),
	unassigned: __( 'Non assegnato', 'wp-5w' ),
	'(n/d)': __( 'Non assegnato', 'wp-5w' ),
};

/**
 * Visitatori per canale, con barra proporzionale alla quota.
 *
 * @param {Object} props
 * @param {Array}  props.channels [{ channel, visitors }].
 */
export default function ChannelList( { channels } ) {
	const total = channels.reduce( ( sum, item ) => sum + item.visitors, 0 );
	if ( ! total ) {
		return <p>{ __( 'Nessun dato nel periodo.', 'wp-5w' ) }</p>;
	}

	return (
		<ul className="wp5w-channels">
			{ channels.map( ( item ) => {
				const share = Math.round( ( item.visitors / total ) * 1000 ) / 10;
				return (
					<li key={ item.channel }>
						<div className="wp5w-channels__row">
							<span>{ LABELS[ item.channel ] ?? item.channel }</span>
							<span className="wp5w-channels__value">
								{ formatNumber( item.visitors ) } ({ formatNumber( share, '%' ) })
							</span>
						</div>
						<span className="wp5w-channels__bar" style={ { width: `${ share }%` } } />
					</li>
				);
			} ) }
		</ul>
	);
}
