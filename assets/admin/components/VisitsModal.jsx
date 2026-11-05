import { __ } from '@wordpress/i18n';
import { Modal } from '@wordpress/components';
import VisitsDetail from './VisitsDetail';

/**
 * Dettaglio delle visite in un modale a sé (usato dagli avvisi della Panoramica).
 *
 * @param {Object}   props
 * @param {Object}   props.filter  Filtro di VisitsDetail.
 * @param {Function} props.onClose Chiusura del modale.
 */
export default function VisitsModal( { filter, onClose } ) {
	return (
		<Modal title={ __( 'Dettaglio delle visite', 'wp-5w' ) } onRequestClose={ onClose } size="large" className="wp5w-modal">
			<VisitsDetail filter={ filter } />
		</Modal>
	);
}
