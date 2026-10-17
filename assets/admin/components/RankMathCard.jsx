import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { api, errorText, formatNumber, formatTime } from '../api';
import Card from './Card';

/**
 * Box «Rank Math»: import dello storico di Search Console che Rank Math ha nel database, separato da quello di Google.
 */
export default function RankMathCard() {
	const [ status, setStatus ] = useState( null );
	const [ running, setRunning ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	// Al montaggio si legge lo stato (sorgente disponibile e ultimo import).
	useEffect( () => {
		api( '/admin/rankmath' )
			.then( setStatus )
			.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) );
	}, [] );

	// Importa tutto lo storico di Rank Math (i giorni già scaricati da Google non si toccano).
	const runImport = () => {
		setRunning( true );
		setNotice( null );
		api( '/admin/rankmath/import', { method: 'POST' } )
			.then( ( state ) => {
				setStatus( ( current ) => ( { ...current, last: state } ) );
				setNotice( { status: 'success', text: __( 'Import da Rank Math completato.', 'wp-5w' ) } );
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
			.finally( () => setRunning( false ) );
	};

	const last = status?.last;
	const source = last?.sources?.rank_math;

	return (
		<Card id="options-rankmath" title={ __( 'Rank Math Analytics', 'wp-5w' ) }>
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.text }
				</Notice>
			) }
			{ ! status && ! notice && <Spinner /> }
			{ status && (
				<>
					<p className="wp5w-note wp5w-note--top">
						{ __( 'Copia nel plugin lo storico di Search Console che Rank Math ha nel database (la versione gratuita ne tiene 90 giorni). Succede già ogni notte: qui puoi farlo subito.', 'wp-5w' ) }
					</p>
					{ ! status.available && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'Rank Math Analytics non è disponibile o ha uno schema che il plugin non conosce.', 'wp-5w' ) }
						</Notice>
					) }
					<dl className="wp5w-updates__facts">
						<div>
							<dt>{ __( 'Ultimo import', 'wp-5w' ) }</dt>
							<dd>{ last ? formatTime( last.started ) : __( 'mai', 'wp-5w' ) }</dd>
						</div>
						{ last?.rows?.gsc_daily !== undefined && (
							<div>
								<dt>{ __( 'Righe copiate', 'wp-5w' ) }</dt>
								<dd>{ formatNumber( last.rows.gsc_daily ) }</dd>
							</div>
						) }
					</dl>
					{ last && ( ! last.ok || 'error' === source?.status ) && (
						<Notice status="error" isDismissible={ false }>
							{ source?.error ?? last.error ?? __( 'Import non riuscito.', 'wp-5w' ) }
						</Notice>
					) }
					<div className="wp5w-actions">
						<Button variant="primary" isBusy={ running } disabled={ running || ! status.available } onClick={ runImport }>
							{ __( 'Importa da Rank Math', 'wp-5w' ) }
						</Button>
					</div>
					<p className="wp5w-note">
						{ __( 'I giorni già scaricati da Google (Search Console) non si toccano.', 'wp-5w' ) }
					</p>
				</>
			) }
		</Card>
	);
}
