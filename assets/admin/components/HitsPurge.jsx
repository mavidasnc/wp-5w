import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { api, errorText, formatDay, formatNumber } from '../api';

/**
 * Pulizia manuale delle visite grezze più vecchie dei giorni di conservazione salvati (mai automatica).
 * Il pulsante apre un modale con l'anteprima (/admin/hits/purge); la cancellazione parte solo dalla conferma
 * e invia il primo giorno conservato dell'anteprima, così cancella esattamente ciò che è stato mostrato.
 *
 * @param {Object}  props
 * @param {boolean} props.unsaved Il campo dei giorni ha un valore non ancora salvato.
 */
export default function HitsPurge( { unsaved } ) {
	const [ preview, setPreview ] = useState( null );
	const [ open, setOpen ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ result, setResult ] = useState( null );

	// Apre il modale e carica l'anteprima.
	const start = () => {
		setOpen( true );
		setPreview( null );
		setError( null );
		api( '/admin/hits/purge' )
			.then( setPreview )
			.catch( ( e ) => setError( errorText( e ) ) );
	};

	// Cancella dopo la conferma.
	const confirm = () => {
		setBusy( true );
		setError( null );
		api( '/admin/hits/purge', { method: 'POST', data: { before: preview.before } } )
			.then( ( data ) => {
				setResult( data );
				setOpen( false );
			} )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="wp5w-purge">
			{ result && (
				<Notice status="success" onRemove={ () => setResult( null ) }>
					{ sprintf(
						/* translators: 1: righe cancellate, 2: primo giorno conservato (gg/mm/aaaa). */
						__( 'Cancellate %1$s visite grezze precedenti al %2$s. Gli aggregati non sono cambiati.', 'wp-5w' ),
						formatNumber( result.deleted ),
						formatDay( result.before )
					) }
				</Notice>
			) }
			<Button variant="secondary" isDestructive onClick={ start }>
				{ __( 'Cancella le visite grezze più vecchie…', 'wp-5w' ) }
			</Button>
			{ open && (
				<Modal title={ __( 'Pulizia delle visite grezze', 'wp-5w' ) } onRequestClose={ () => ! busy && setOpen( false ) } size="medium">
					{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
					{ ! preview && ! error && <Spinner /> }
					{ preview && (
						<>
							<p>
								{ preview.rows > 0
									? sprintf(
										/* translators: 1: righe, 2: primo giorno conservato, 3: giorni di conservazione. */
										__( 'Verranno cancellate %1$s visite grezze precedenti al %2$s (conservazione: %3$s giorni, il valore salvato).', 'wp-5w' ),
										formatNumber( preview.rows ),
										formatDay( preview.before ),
										formatNumber( preview.retention_days )
									)
									: sprintf(
										/* translators: 1: primo giorno conservato, 2: giorni di conservazione. */
										__( 'Nessuna visita grezza precedente al %1$s (conservazione: %2$s giorni): non c\'è niente da cancellare.', 'wp-5w' ),
										formatDay( preview.before ),
										formatNumber( preview.retention_days )
									) }
							</p>
							<p className="wp5w-note">
								{ __( 'Le statistiche aggregate (Panoramica, Articoli, Visitatori) restano. Si perdono il dettaglio delle singole visite di quei giorni e, nella modale delle provenienze, quei giorni passano ai dati aggregati. L\'operazione non si può annullare.', 'wp-5w' ) }
								{ unsaved && ` ${ __( 'Attenzione: il numero di giorni nel campo non è ancora salvato, vale quello salvato.', 'wp-5w' ) }` }
							</p>
							<div className="wp5w-actions">
								<Button variant="tertiary" disabled={ busy } onClick={ () => setOpen( false ) }>
									{ __( 'Annulla', 'wp-5w' ) }
								</Button>
								{ preview.rows > 0 && (
									<Button variant="primary" isDestructive isBusy={ busy } disabled={ busy } onClick={ confirm }>
										{ /* translators: %s: righe da cancellare. */ sprintf( __( 'Cancella %s visite', 'wp-5w' ), formatNumber( preview.rows ) ) }
									</Button>
								) }
							</div>
						</>
					) }
				</Modal>
			) }
		</div>
	);
}
