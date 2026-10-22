import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { api, errorText, formatNumber, formatTime } from '../api';
import Card from './Card';

/**
 * Box «Search Console da Google» della scheda Aggiornamenti: importa i giorni che mancano dall'API di Google.
 * Il collegamento a Google (credenziali, proprietà) si fa nella scheda Opzioni.
 */
export default function GscImportCard() {
	const [ status, setStatus ] = useState( null );
	const [ running, setRunning ] = useState( false );
	const [ remaining, setRemaining ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	// Al montaggio si legge lo stato (collegamento, proprietà, giorni mancanti e ultimo import).
	useEffect( () => {
		api( '/admin/gsc' )
			.then( setStatus )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			);
	}, [] );

	// Importa un lotto di 30 giorni alla volta finché non ne restano; un errore ferma il ciclo e i giorni già scritti restano.
	const runImport = async () => {
		setRunning( true );
		setNotice( null );
		let days = 0;
		let rows = 0;
		try {
			let result;
			let guard = 0;
			do {
				result = await api( '/admin/gsc/import', { method: 'POST' } );
				days += result.days;
				rows += result.rows;
				setRemaining( result.remaining );
				guard++;
			} while ( result.remaining > 0 && guard < 100 );

			setNotice(
				days
					? {
							status: 'success',
							/* translators: 1: numero di giorni, 2: numero di righe. */
							text: sprintf(
								_n(
									'Importato %1$s giorno (%2$s righe).',
									'Importati %1$s giorni (%2$s righe).',
									days,
									'wp-5w'
								),
								formatNumber( days ),
								formatNumber( rows )
							),
					  }
					: {
							status: 'success',
							text: __(
								'Nessun giorno da importare: i dati sono già aggiornati.',
								'wp-5w'
							),
					  }
			);
		} catch ( e ) {
			setNotice( {
				status: 'error',
				/* translators: 1: numero di giorni già importati, 2: messaggio d'errore. */
				text: days
					? sprintf(
							__( 'Importati %1$s giorni, poi: %2$s', 'wp-5w' ),
							formatNumber( days ),
							errorText( e )
					  )
					: errorText( e ),
			} );
		}
		setRemaining( null );
		// Lo stato (giorni mancanti e ultimo import) si rilegge dal server.
		api( '/admin/gsc' )
			.then( setStatus )
			.catch( () => {} );
		setRunning( false );
	};

	const title = __( 'Search Console da Google', 'wp-5w' );

	if ( ! status ) {
		return (
			<Card id="updates-gsc" title={ title }>
				{ notice ? (
					<Notice status={ notice.status } isDismissible={ false }>
						{ notice.text }
					</Notice>
				) : (
					<Spinner />
				) }
			</Card>
		);
	}

	const last = status.last;
	const ready = status.connected && !! status.site;

	return (
		<Card id="updates-gsc" title={ title }>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<p className="wp5w-note wp5w-note--top">
				{ __(
					"Scarica dall'API di Google i dati di Search Console (clic, impression e posizione per pagina e query) dei giorni che mancano, fino a 16 mesi. Sono più completi di quelli di Rank Math (circa il 60% di query in più). Dopo il collegamento si aggiorna da solo ogni notte: qui puoi farlo subito.",
					'wp-5w'
				) }
			</p>

			{ ! ready && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Google non è collegato (o manca la proprietà): collegalo dalla scheda Opzioni, nel box «Search Console (API di Google)».',
						'wp-5w'
					) }
				</Notice>
			) }
			{ status.reauth && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						"Google ha revocato l'accesso (o il collegamento non si legge più): ricollegalo dalla scheda Opzioni.",
						'wp-5w'
					) }
				</Notice>
			) }

			<dl className="wp5w-updates__facts">
				<div>
					<dt>{ __( 'Giorni da importare', 'wp-5w' ) }</dt>
					<dd>
						{ null === ( remaining ?? status.pending_days )
							? '–'
							: formatNumber( remaining ?? status.pending_days ) }
					</dd>
				</div>
				<div>
					<dt>{ __( 'Ultimo import', 'wp-5w' ) }</dt>
					<dd>
						{ last
							? formatTime( last.time )
							: __( 'mai', 'wp-5w' ) }
						{ last && last.ok && last.days > 0 && (
							<span className="wp5w-note">
								{ ' ' }
								{
									/* translators: %s: numero di giorni. */ sprintf(
										_n(
											'%s giorno',
											'%s giorni',
											last.days,
											'wp-5w'
										),
										formatNumber( last.days )
									)
								}
							</span>
						) }
					</dd>
				</div>
			</dl>
			{ last && ! last.ok && (
				<Notice status="error" isDismissible={ false }>
					{ last.error }
				</Notice>
			) }

			<div className="wp5w-actions">
				<Button
					variant="primary"
					isBusy={ running }
					disabled={ running || ! ready }
					onClick={ runImport }
				>
					{ running && null !== remaining
						? /* translators: %s: giorni ancora da importare. */ sprintf(
								__( 'Importo… ancora %s giorni', 'wp-5w' ),
								formatNumber( remaining )
						  )
						: __( 'Importa da Search Console', 'wp-5w' ) }
				</Button>
			</div>
			<p className="wp5w-note">
				{ __(
					"I giorni già scaricati da Google non si toccano. I giorni di Rank Math si sostituiscono con quelli completi dell'API.",
					'wp-5w'
				) }
			</p>
		</Card>
	);
}
