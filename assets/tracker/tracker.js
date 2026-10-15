/**
 * Tracker di wp-5w: invia la visita all'endpoint REST del plugin.
 *
 * Senza dipendenze, senza cookie e senza localStorage. Nessun controllo sull'utente collegato:
 * la pagina può stare in cache e se ne occupa l'endpoint. I browser automatici (navigator.webdriver)
 * si segnalano con il parametro w=1 e non si scartano.
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

	var p = new URLSearchParams();

	// 2) Do Not Track (se l'opzione è attiva): si invia solo dnt=1, senza contenuto, URI, referrer né UTM.
	if ( d.dnt && ( navigator.doNotTrack === '1' || window.doNotTrack === '1' ) ) {
		p.set( 'dnt', '1' );
	} else {
		// 3) Contenuto firmato, percorso, referrer e parametri UTM dell'URL (se presenti).
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

		// 4) Browser automatico: la visita si registra e l'endpoint la segnala (il flag non è firmato).
		if ( navigator.webdriver ) {
			p.set( 'w', '1' );
		}

		// 5) Ricerca interna: termine e numero di risultati (solo nella prima pagina dei risultati).
		if ( d.q ) {
			p.set( 'q', d.q );
			p.set( 'n', d.n );
		}
	}

	// 6) Invio: sendBeacon, oppure fetch con keepalive se manca o rifiuta la richiesta. Gli errori si ignorano.
	if ( ! ( navigator.sendBeacon && navigator.sendBeacon( d.url, p ) ) ) {
		fetch( d.url, { method: 'POST', body: p, keepalive: true, credentials: 'same-origin' } ).catch( function () {} );
	}
} )();
