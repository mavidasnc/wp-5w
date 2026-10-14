# wp-5w

Analytics privacy-first per WordPress che incrocia i dati di traffico (pageview, sorgenti, trend) con quelli di Google Search Console (query, impression, CTR, posizione) per singolo URL.

Il nome richiama le 5W (who, what, when, where, why): il valore del progetto sta nel **collegare e incrociare** le fonti, non nel contare le visite, cosa che fanno già molti plugin.

- **Fase 1:** solo per `maurizio.mavida.com`.
- **Prospettiva:** nuova SPA dell'ecosistema Mavida SaaS (`projects/mavida-saas-ecosystem`), multi-sito.

Analisi iniziale del 2026-10-13.

---

## 1. Panorama dei plugin esistenti

| Soluzione | Dove stanno i dati | Cookie | Dettaglio per articolo | Trend | GSC |
|---|---|---|---|---|---|
| WP Statistics (in uso, 14.16.15) | DB di WordPress | No (opzionale) | Discreto | Basilari | Solo con add-on a pagamento (da verificare) |
| Koko Analytics | DB di WP, solo dati aggregati | No | Base | Base | No |
| Independent Analytics | DB di WP | No | Buono, con filtri e confronti | Buoni | No |
| Burst Statistics | DB di WP | Si possono evitare | Buono, con obiettivi | Buoni | No |
| Matomo (plugin o self-hosted) | Server proprio | Si possono evitare | Ottimo | Ottimi | Con plugin premium |
| Umami / Plausible self-hosted | Server proprio (Docker) | No | Buono | Buoni | Plausible sì, Umami no |
| Rank Math Analytics (già installato) | DB di WP | n/a | Keyword, posizione e CTR per post | Sì | Nativo |

**Il punto debole di tutti:** nessuno mostra nella stessa vista e per lo stesso URL pageview, sorgenti, query GSC, CTR e posizione, conservando lo storico oltre i 16 mesi di GSC. Rank Math Analytics si avvicina sul lato GSC, ma nella versione free conserva circa 90 giorni e per il traffico richiede Google Analytics, che vogliamo evitare.

## 2. Ha senso sviluppare?

- **Per il solo blog personale, no:** Rank Math Analytics affiancato a un plugin di stats cookieless copre circa l'80% delle esigenze.
- **Ha senso come modulo dell'ecosistema Mavida SaaS:** si ottiene un servizio multi-sito (siti propri e dei clienti, eventualmente collegato a `wp-health-check` e `fleet`) che unisce traffico e GSC per URL, con lo storico illimitato. Questo è il differenziale.

Decisione: si parte dal blog, ma si progetta già con l'architettura dell'ecosistema (collector e job nell'hub, SPA separata), così la fase 1 non si butta.

## 3. Tracciamento: server o JS sul client

La questione dei cookie **non dipende** dalla scelta tra server e client: un beacon JS senza cookie e senza `localStorage` è privacy-friendly quanto il tracciamento lato server.

- **Server-side in PHP dentro WP: escluso.** Con LiteSpeed Cache le pagine servite dalla cache non eseguono PHP. Verificato: la homepage risponde `x-litespeed-cache: hit`, e infatti WP Statistics già oggi traccia via JS (`WP_Statistics_Tracker_Object`, hit su `/wp-json/wp-statistics/v2/hit`).
- **Server-side sui log di accesso: sconsigliato.** Sull'hosting condiviso l'accesso ai log è limitato e il traffico dei bot va filtrato a mano.
- **Scelta: beacon JS minimale** (circa 1 KB, `navigator.sendBeacon`) con URL, referrer, UTM e dimensione dello schermo. Il visitatore unico si calcola lato server con `hash(IP + UA + salt giornaliero)`, con il salt che ruota e viene scartato ogni giorno, come fa Plausible. Niente cookie, nessun dato personale salvato, nessun banner. Buona parte dei bot, che non eseguono JS, viene filtrata in modo naturale.

## 4. Architettura proposta

1. **Collector** (hub): endpoint che riceve i beacon, salva gli eventi grezzi e li aggrega per giorno, URL e sorgente.
2. **Job giornaliero GSC** (hub): Search Analytics API con service account aggiunto come utente alla proprietà, dimensioni `page` + `query` + `date`. I dati arrivano con 2 o 3 giorni di ritardo e vanno salvati in locale, per avere uno storico oltre i 16 mesi.
3. **Plugin WP leggerissimo**: inserisce lo script, invia i metadati dei post (titolo, data di pubblicazione, categorie) e mostra un widget nella dashboard e un metabox per articolo, leggendo i dati dall'hub.
4. **SPA nell'ecosistema**: trend, dettaglio per articolo (pageview, sorgenti, query GSC, CTR, posizione), confronto tra periodi, contenuti in crescita o in calo, opportunità (molte impression con CTR basso).
5. **Import dello storico** dalle tabelle di WP Statistics (prefisso `maurizio_`).

## 5. Stato attuale del blog (verificato il 2026-10-13 via REST API)

- WordPress 7.1.3, PHP 8.3.30, MariaDB 10.11.16, prefisso tabelle `maurizio_`.
- **WP Statistics 14.16.15** attivo, con tracciamento JS via REST.
- **Rank Math 1.0.280** (free): l'account Google è già collegato e il permesso Search Console risulta accordato. **Il modulo Analytics però non è attivo** (moduli attivi: seo-analysis, sitemap, rich-snippet, 404-monitor, link-counter, instant-indexing, image-seo, content-ai, ai-visibility, redirections). Le tabelle `rank_math_analytics_*` esistono ma sono quasi vuote (GSC 80 KB).
- `rank-math/get-top-keywords` restituisce `connected: true` ma nessuna keyword, coerente con il modulo spento.
- **Storico WP Statistics:** 6 mesi di dettaglio (finestra mobile di 180 giorni, che avanza ogni notte) più i totali giornalieri del sito dal 2023, per circa 27 MB in tutto. Dettagli e implicazioni in [fattibilita-dashboard-locale.md](fattibilita-dashboard-locale.md).
- **Chiave SSH `mavida-blog` non più valida:** il 2026-10-12 alle 10:48 `~/.ssh/authorized_keys` dell'account `mavida` è stato sovrascritto. Ora contiene una sola chiave RSA con commento `claude-staging@mavida`, mentre la chiave `claude, blog maurizio.mavida.com` documentata in `credenzials/maurizio-blog/wordpress.md` non c'è più.

### Problemi trovati per strada

- `WP_DEBUG_DISPLAY` è **attivo in produzione**: le risposte REST includono un warning PHP (`open_basedir restriction ... /robots.txt` in `class-wp-debug-data.php`), che rischia di finire anche nelle pagine pubbliche e di rompere il JSON. Va disattivato, lasciando attivo solo `WP_DEBUG_LOG`.
- `WP_MEMORY_LIMIT` a 40M è solo il default di WordPress: il `memory_limit` di PHP è 2G e WordPress non lo abbassa, quindi non serve intervenire.
- **Risolto il 2026-10-13:** `WP_DEBUG_DISPLAY` è stato disattivato e il log spostato in `/home/mavida/logs/`. Il vecchio `public/debug.log` (193 MB) era scaricabile pubblicamente. Dettagli in `credenzials/maurizio-blog/wordpress.md`.

## 6. Prossimi passi

1. Attivare il modulo Analytics di Rank Math come baseline gratuita e lasciarlo raccogliere dati per qualche settimana → verifica: le keyword compaiono in `get-top-keywords`.
2. Ripristinare la chiave `id_ed25519_mavida_blog` in `authorized_keys`, dopo aver chiarito l'origine della chiave `claude-staging@mavida` → verifica: `ssh mavida-blog "wp core version"` risponde.
3. Definire il modello dati (eventi, aggregati giornalieri, GSC per pagina e query) e le API dell'hub.
4. Prototipo: beacon + collector + job GSC sul solo blog, con una prima vista per articolo nella SPA.

## Domande aperte

- Dove ospitare il collector: hub (server `72.61.191.67`) o servizio dedicato?
- Retention degli eventi grezzi (per esempio 90 giorni) contro aggregati conservati per sempre?
- Serve tracciare eventi oltre alle pageview (click sulle CTA, download di `wp-paid-attachments`, prenotazioni di `wp-book-a-call`)?
