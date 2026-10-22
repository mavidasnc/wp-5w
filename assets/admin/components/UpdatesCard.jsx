import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, ExternalLink, Notice, Spinner } from '@wordpress/components';
import { api } from '../api';
import Card from './Card';

/**
 * Data e ora dell'ultimo controllo da timestamp UNIX, oppure «mai».
 *
 * @param {?number} ts Timestamp in secondi.
 */
const checkedAt = ( ts ) =>
	ts ? new Date( ts * 1000 ).toLocaleString( 'it-IT', { dateStyle: 'medium', timeStyle: 'short' } ) : __( 'mai', 'wp-5w' );

/**
 * Box «Versione del plugin» della scheda Aggiornamenti: versione installata e ultima, verifica immediata e installazione dalle release di GitHub.
 */
export default function UpdatesCard() {
	const [ status, setStatus ] = useState( null );
	const [ busy, setBusy ] = useState( '' ); // '', 'check' o 'install'.
	const [ notice, setNotice ] = useState( null );
	const [ error, setError ] = useState( null );

	// Al montaggio si legge lo stato dell'ultimo controllo (senza contattare GitHub).
	useEffect( () => {
		api( '/admin/update' )
			.then( setStatus )
			.catch( ( e ) => setError( e.message ) );
	}, [] );

	// Controlla subito se c'è una release più recente.
	const check = () => {
		setBusy( 'check' );
		setNotice( null );
		api( '/admin/update/check', { method: 'POST' } )
			.then( ( data ) => {
				setStatus( data );
				setNotice(
					data.update_available
						? { status: 'warning', text: /* translators: %s: numero di versione (per esempio 0.11.0). */ sprintf( __( 'È disponibile la versione %s.', 'wp-5w' ), data.latest ) }
						: { status: 'success', text: __( 'Il plugin è aggiornato all\'ultima versione.', 'wp-5w' ) }
				);
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBusy( '' ) );
	};

	// Installa la release e ricarica la pagina: gli script dell'admin sono cambiati.
	const install = () => {
		setBusy( 'install' );
		setNotice( null );
		api( '/admin/update/install', { method: 'POST' } )
			.then( ( data ) => {
				setNotice( { status: 'success', text: /* translators: %s: numero di versione (per esempio 0.11.0). */ sprintf( __( 'Aggiornato alla versione %s. Ricarico la pagina…', 'wp-5w' ), data.version ) } );
				setTimeout( () => window.location.reload(), 2000 );
			} )
			.catch( ( e ) => {
				setNotice( { status: 'error', text: e.message } );
				setBusy( '' );
			} );
	};

	return (
		<Card id="updates-plugin" title={ __( 'Versione del plugin', 'wp-5w' ) }>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! status && ! error && <Spinner /> }
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.text }
				</Notice>
			) }
			{ status && (
				<>
					<dl className="wp5w-updates__facts">
						<div>
							<dt>{ __( 'Versione installata', 'wp-5w' ) }</dt>
							<dd>{ status.current }</dd>
						</div>
						<div>
							<dt>{ __( 'Ultima versione', 'wp-5w' ) }</dt>
							<dd>
								{ status.latest }
								{ status.update_available && <span className="wp5w-updates__badge">{ __( 'Nuova', 'wp-5w' ) }</span> }
							</dd>
						</div>
						<div>
							<dt>{ __( 'Ultimo controllo', 'wp-5w' ) }</dt>
							<dd>{ checkedAt( status.checked_at ) }</dd>
						</div>
					</dl>
					<div className="wp5w-actions">
						<Button variant="secondary" isBusy={ 'check' === busy } disabled={ !! busy } onClick={ check }>
							{ __( 'Verifica aggiornamenti', 'wp-5w' ) }
						</Button>
						{ status.update_available && (
							<Button variant="primary" isBusy={ 'install' === busy } disabled={ !! busy } onClick={ install }>
								{ /* translators: %s: numero di versione (per esempio 0.11.0). */ sprintf( __( 'Installa la versione %s', 'wp-5w' ), status.latest ) }
							</Button>
						) }
					</div>
					<p className="wp5w-note">
						<ExternalLink href={ status.releases_url }>{ __( 'Note di versione su GitHub', 'wp-5w' ) }</ExternalLink>
						{ ' ' }
						{ __( 'WordPress controlla da solo ogni 12 ore: qui puoi farlo subito.', 'wp-5w' ) }
					</p>
				</>
			) }
		</Card>
	);
}
