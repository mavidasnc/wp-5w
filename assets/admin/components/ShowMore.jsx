import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/**
 * Mostra le prime righe di un elenco e un pulsante per vederle tutte.
 *
 * @param {Object}   props
 * @param {Array}    props.rows     Righe complete.
 * @param {number}   props.size     Righe visibili all'inizio.
 * @param {Function} props.children ( visibleRows ) => contenuto.
 */
export default function ShowMore( { rows, size = 25, children } ) {
	const [ all, setAll ] = useState( false );
	const visible = all ? rows : rows.slice( 0, size );

	return (
		<>
			{ children( visible ) }
			{ rows.length > size && (
				<Button className="wp5w-more" variant="link" onClick={ () => setAll( ! all ) }>
					{ all
						? __( 'Mostra meno', 'wp-5w' )
						: /* translators: %d: numero totale di righe. */ sprintf( __( 'Mostra tutte (%d)', 'wp-5w' ), rows.length ) }
				</Button>
			) }
		</>
	);
}
