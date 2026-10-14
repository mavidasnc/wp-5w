/**
 * Tracker di wp-5w: invia la visita all'endpoint REST del plugin.
 *
 * Senza dipendenze, senza cookie e senza localStorage. Nessun controllo sull'utente collegato:
 * la pagina può stare in cache e se ne occupa l'endpoint.
 */
( function () {
	// 1) Dati stampati da PHP nel blocco JSON #wp5w-hit: se mancano o non sono validi, si esce.
	var el = document.getElementById( 'wp5w-hit' );
	var d;
	try {
		d = JSON.parse( el.textContent );
	} catch ( e ) {
		return;
	}

	// 2) Browser automatici: niente visita.
	if ( navigator.webdriver ) {
		return;
	}

	var p = new URLSearchParams();

	// 3) Do Not Track (se l'opzione è attiva): si invia solo dnt=1, senza contenuto, URI, referrer né UTM.
	if ( d.dnt && ( navigator.doNotTrack === '1' || window.doNotTrack === '1' ) ) {
		p.set( 'dnt', '1' );
	} else {
		// 4) Contenuto firmato, percorso, referrer e parametri UTM dell'URL (se presenti).
		var q = new URLSearchParams( location.search );
		p.set( 't', d.type );
		p.set( 'id', d.id );
		p.set( 's', d.sig );
		p.set( 'u', location.pathname );
		p.set( 'r', document.referrer );
		[ [ 'us', 'utm_source' ], [ 'um', 'utm_medium' ], [ 'uc', 'utm_campaign' ] ].forEach( function ( k ) {
			if ( q.get( k[ 1 ] ) ) {
				p.set( k[ 0 ], q.get( k[ 1 ] ) );
			}
		} );
	}

	// 5) Invio: sendBeacon, oppure fetch con keepalive se manca o rifiuta la richiesta. Gli errori si ignorano.
	if ( ! ( navigator.sendBeacon && navigator.sendBeacon( d.url, p ) ) ) {
		fetch( d.url, { method: 'POST', body: p, keepalive: true, credentials: 'same-origin' } ).catch( function () {} );
	}
} )();
