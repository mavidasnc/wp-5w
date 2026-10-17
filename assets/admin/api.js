import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const { apiRoot, nonce, today } = window.wp5wAdmin;

// Il nonce wp_rest autentica le chiamate con il cookie di sessione dell'admin.
apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );

/**
 * Chiamata alla REST API del plugin.
 *
 * @param {string} path    Percorso dopo wp5w/v1.
 * @param {Object} options Opzioni di apiFetch (method, data).
 */
export const api = ( path, options = {} ) =>
	apiFetch( { url: apiRoot + path, ...options } );

export { today };

/**
 * Messaggio di un errore REST: `message` (WP_Error) oppure `error` (risposta dei nostri import con ok falso).
 *
 * @param {Object} error Errore di apiFetch.
 */
export const errorText = ( error ) => error?.message ?? error?.error ?? __( 'Errore sconosciuto.', 'wp-5w' );

/**
 * Data e ora da timestamp UNIX (secondi), oppure «mai».
 *
 * @param {?number} ts Timestamp in secondi.
 */
export const formatTime = ( ts ) =>
	ts ? new Date( ts * 1000 ).toLocaleString( 'it-IT', { dateStyle: 'medium', timeStyle: 'short' } ) : __( 'mai', 'wp-5w' );

const numberFormat = new Intl.NumberFormat( 'it-IT' );

/**
 * Formatta un numero all'italiana; trattino per i valori assenti.
 *
 * @param {?number} value  Valore.
 * @param {string}  suffix Suffisso (es. '%').
 */
export const formatNumber = ( value, suffix = '' ) =>
	value === null || value === undefined ? '–' : numberFormat.format( value ) + suffix;

/**
 * Formatta una data Y-m-d in gg/mm/aaaa.
 *
 * @param {?string} day Data.
 */
export const formatDay = ( day ) =>
	day ? day.split( '-' ).reverse().join( '/' ) : '–';

/**
 * Sposta una data Y-m-d di n giorni.
 *
 * @param {string} day  Data di partenza.
 * @param {number} days Giorni (anche negativi).
 */
export const shiftDay = ( day, days ) => {
	const date = new Date( `${ day }T12:00:00Z` );
	date.setUTCDate( date.getUTCDate() + days );
	return date.toISOString().slice( 0, 10 );
};
