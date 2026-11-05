import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { api, formatNumber, formatTime } from '../api';
import Card from './Card';
import ReferrerModal from './ReferrerModal';
import VisitsModal from './VisitsModal';

/**
 * Nome del sito di provenienza di un avviso: il referrer vuoto è l'accesso diretto.
 *
 * @param {Object} alert Avviso.
 */
const siteName = ( alert ) => alert.label || __( '(diretto)', 'wp-5w' );

/**
 * Testo di un avviso su una coppia (sito, contenuto), con i soli segnali che l'hanno fatto scattare oltre al picco.
 *
 * @param {Object} alert Avviso di tipo pair.
 * @param {number} hours Finestra in ore.
 */
const pairText = ( alert, hours ) => {
	const parts = [
		sprintf(
			/* translators: 1: visitatori, 2: ore, 3: mediana al giorno. */
			__( '%1$s visitatori in %2$s ore (mediana %3$s al giorno)', 'wp-5w' ),
			formatNumber( alert.visitors ),
			formatNumber( hours ),
			formatNumber( alert.median )
		),
	];
	/* translators: %s: numero di paesi. */
	parts.push( sprintf( __( '%s paesi', 'wp-5w' ), formatNumber( alert.countries ) ) );
	if ( alert.combo ) {
		parts.push( `${ formatNumber( alert.combo.share ) }% ${ alert.combo.combo.replace( '|', '/' ) }` );
	}
	if ( alert.reasons.includes( 'ip' ) ) {
		/* translators: %s: visite del singolo IP. */
		parts.push( sprintf( __( 'un IP con %s visite', 'wp-5w' ), formatNumber( alert.ip_max ) ) );
	}
	return parts.join( ', ' );
};

/**
 * Box «Visite anomale» della Panoramica: l'ultimo calcolo orario degli avvisi (/admin/alerts), con i pulsanti
 * per aprire i contenuti raggiunti dal sito e il dettaglio delle singole visite.
 */
export default function Alerts() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ site, setSite ] = useState( null );
	const [ visits, setVisits ] = useState( null );

	useEffect( () => {
		let active = true;
		api( '/admin/alerts' )
			.then( ( result ) => active && setData( result ) )
			.catch( ( e ) => active && setError( e.message ) );
		return () => {
			active = false;
		};
	}, [] );

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}

	const hours = data.thresholds.alert_hours;
	// Giorni del dettaglio: devono coprire tutta la finestra, anche quando comincia ieri.
	const days = Math.ceil( hours / 24 ) + 1;

	return (
		<Card id="overview-alerts" title={ __( 'Visite anomale', 'wp-5w' ) }>
			{ ! data.tracking && <p>{ __( 'La raccolta propria è spenta: nessun controllo.', 'wp-5w' ) }</p> }
			{ data.tracking && ! data.alerts.length && (
				/* translators: %s: ore della finestra. */
				<p>{ sprintf( __( 'Nessuna visita anomala nelle ultime %s ore.', 'wp-5w' ), formatNumber( hours ) ) }</p>
			) }
			{ data.alerts.map( ( alert ) => (
				<div key={ alert.key } className="wp5w-alert">
					<p>
						<strong>{ __( 'Attenzione:', 'wp-5w' ) }</strong>{ ' ' }
						{ alert.kind === 'pair'
							? sprintf(
								/* translators: 1: sito di provenienza, 2: titolo del contenuto, 3: dati. */
								__( '%1$s sta generando visite anomale su «%2$s»: %3$s.', 'wp-5w' ),
								siteName( alert ),
								alert.title,
								pairText( alert, hours )
							)
							: sprintf(
								/* translators: 1: hash dell'IP, 2: visite, 3: titolo del contenuto, 4: ore, 5: user agent diversi. */
								__( 'l\'IP %1$s ha fatto %2$s visite su «%3$s» nelle ultime %4$s ore (%5$s user agent diversi).', 'wp-5w' ),
								alert.ip.slice( 0, 8 ),
								formatNumber( alert.visits ),
								alert.title,
								formatNumber( hours ),
								formatNumber( alert.agents )
							) }{ ' ' }
						<span className="wp5w-type">
							{ /* translators: %s: data e ora del primo avviso. */ sprintf( __( 'dal %s', 'wp-5w' ), formatTime( alert.since ) ) }
						</span>
					</p>
					<div className="wp5w-alert__actions">
						<Button
							variant="secondary"
							size="compact"
							onClick={ () =>
								setVisits( {
									type: alert.type,
									id: alert.id,
									days,
									referrer: alert.kind === 'pair' ? alert.referrer : null,
									ip: alert.kind === 'ip' ? alert.ip : null,
								} )
							}
						>
							{ __( 'Dettaglio delle visite', 'wp-5w' ) }
						</Button>
						{ alert.kind === 'pair' && alert.referrer !== '' && (
							<Button variant="tertiary" size="compact" onClick={ () => setSite( { referrer: alert.referrer, label: siteName( alert ) } ) }>
								{ __( 'Contenuti da questo sito (7 giorni)', 'wp-5w' ) }
							</Button>
						) }
						<a className="wp5w-edit" href={ alert.url } target="_blank" rel="noreferrer">
							{ __( 'Apri il contenuto', 'wp-5w' ) }
						</a>
					</div>
				</div>
			) ) }
			{ data.peaks.length > 0 && (
				<p className="wp5w-note">
					{ __( 'Picchi senza firma anomala (paesi, browser e IP nella norma: di solito traffico vero, per esempio un articolo condiviso):', 'wp-5w' ) }{ ' ' }
					{ data.peaks
						.map( ( peak ) => `${ siteName( peak ) } → «${ peak.title }» (${ formatNumber( peak.visitors ) })` )
						.join( '; ' ) }
					.
				</p>
			) }
			<p className="wp5w-note">
				{ sprintf(
					/* translators: 1: ora del calcolo, 2: ore, 3: visitatori minimi, 4: volte la mediana, 5: giorni della base, 6: paesi, 7: quota %, 8: visite di un IP. */
					__( 'Calcolo delle %1$s (ogni ora, sulla raccolta propria). Avviso quando un sito porta a un contenuto, nelle ultime %2$s ore, almeno %3$s visitatori e almeno %4$s volte la mediana dei %5$s giorni prima, e in più arriva da almeno %6$s paesi o con la stessa combinazione browser e sistema per almeno il %7$s%% dei visitatori o con un IP che fa almeno %8$s visite; oppure quando un solo IP fa almeno %8$s visite allo stesso contenuto. Le soglie si cambiano nelle Opzioni.', 'wp-5w' ),
					formatTime( data.computed_at ),
					formatNumber( hours ),
					formatNumber( data.thresholds.alert_min_visitors ),
					formatNumber( data.thresholds.alert_factor ),
					formatNumber( data.thresholds.alert_baseline_days ),
					formatNumber( data.thresholds.alert_countries ),
					formatNumber( data.thresholds.alert_share ),
					formatNumber( data.thresholds.alert_ip_visits )
				) }
			</p>
			{ site && <ReferrerModal site={ site } days={ 7 } onClose={ () => setSite( null ) } /> }
			{ visits && <VisitsModal filter={ visits } onClose={ () => setVisits( null ) } /> }
		</Card>
	);
}
