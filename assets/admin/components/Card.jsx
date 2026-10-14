import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/**
 * Stato salvato di una card (aperta se non c'è nulla o se localStorage non è disponibile).
 *
 * @param {string} key Chiave in localStorage.
 */
const readOpen = ( key ) => {
	try {
		return window.localStorage.getItem( key ) !== 'closed';
	} catch {
		return true;
	}
};

/**
 * Card a riga intera riducibile: il toggle nasconde contenuto e filtri e lo stato resta tra un caricamento e l'altro.
 *
 * @param {Object}  props
 * @param {string}  props.id       Identificativo per ricordare lo stato.
 * @param {*}       props.title    Titolo (testo o elementi).
 * @param {*}       props.actions  Filtri o pulsanti nell'intestazione (facoltativi).
 * @param {*}       props.children Contenuto.
 */
export default function Card( { id, title, actions = null, children } ) {
	const key = `wp5w-card-${ id }`;
	const [ open, setOpen ] = useState( () => readOpen( key ) );

	// Inverte lo stato e lo salva; un errore di localStorage non blocca il toggle.
	const toggle = () => {
		setOpen( ! open );
		try {
			window.localStorage.setItem( key, open ? 'closed' : 'open' );
		} catch {}
	};

	return (
		<section className={ `wp5w-card${ open ? '' : ' is-collapsed' }` }>
			<div className="wp5w-card__head">
				<h2>{ title }</h2>
				<div className="wp5w-card__actions">
					{ open && actions }
					<Button
						className="wp5w-card__toggle"
						size="compact"
						label={ open ? __( 'Riduci', 'wp-5w' ) : __( 'Espandi', 'wp-5w' ) }
						aria-expanded={ open }
						onClick={ toggle }
					>
						<span className={ `dashicons dashicons-arrow-${ open ? 'up' : 'down' }-alt2` } aria-hidden="true" />
					</Button>
				</div>
			</div>
			{ open && children }
		</section>
	);
}
