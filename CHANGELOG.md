# Changelog

Tutte le modifiche rilevanti del plugin. Formato basato su [Keep a Changelog](https://keepachangelog.com/it/1.1.0/), versioni [SemVer](https://semver.org/lang/it/).

## [Unreleased]

Collegamento OAuth a Search Console dalla scheda Opzioni e due import separati. Nessuna tabella nuova e nessuna migrazione dello schema; nessun evento cron nuovo (l'import notturno gira dentro `wp5w_etl_daily`).

### Added
- **Collegamento a Google Search Console** (`Gsc\Client`, `Gsc\OAuthHandler`): ogni sito usa il proprio client OAuth «Applicazione web» (guida passo per passo in Opzioni), scambio e rinnovo dei token con `wp_remote_*` e senza librerie, `state` anti-CSRF per utente, ritorno da Google su `admin-post.php`, scope `webmasters.readonly`, revoca dal pulsante «Scollega Google». Client secret e refresh token cifrati con `sodium_crypto_secretbox` (`Support\Secret`, chiave dalle salt) nell'opzione `wp5w_gsc` senza autoload.
- **«Importa da Search Console»** (`Etl\GscApiImport`): scarica solo i giorni dei 16 mesi che non hanno righe di origine `api`, a lotti di 30 giorni con una richiesta per lotto, e li scrive con `GscFileImport::replace_day()` (sostituisce le righe di Rank Math di quel giorno). I giorni per cui l'API risponde senza righe si ricordano in `wp5w_gsc_empty_days`. Con una proprietà di dominio filtra le pagine dell'host del sito. Importa anche ogni notte dopo l'ETL (al massimo 20 secondi).
- **«Importa da Rank Math»** separato, con il proprio stato (`wp5w_rankmath_state`): non cambia `wp5w_etl_state`, da cui legge il Confronto.
- Rotte REST `GET /admin/gsc`, `POST /admin/gsc/credentials`, `/site`, `/disconnect`, `/import`, `GET /admin/rankmath` e `POST /admin/rankmath/import` (tutte `manage_options`; il secret non si restituisce mai).
- `Support\GscDays` (finestra di 16 mesi, giorni mancanti, lotti, percorso relativo come lo scriveva `gsc-backfill.py`, filtro degli host, scelta della proprietà) con `GscDaysTest`; `verify.php` sezione 14 con Google simulato (la pulizia finale diventa la 15).

### Changed
- `Importer::LOCK` è pubblico e lo rispetta anche l'import da Search Console (stesse righe di `gsc_daily`); `Importer` accetta un'opzione di stato (predefinita `wp5w_etl_state`) e `last_state()` un'opzione.
- `GscFileImport::replace_day()` è pubblico (lo usano i due import).
- `uninstall.php` toglie sempre `wp5w_gsc` (segreti) e, con `WP5W_DELETE_DATA`, `wp5w_gsc_state`, `wp5w_gsc_empty_days` e `wp5w_rankmath_state`.
- Per eseguire `GscDaysTest` serve l'estensione sodium (in locale `php -d extension=sodium vendor/bin/phpunit`).

## [0.11.0] — 2026-10-15

Iterazione extra 1: tracciamento completo, quattro box del pubblico e aggiornamenti dall'admin (specifica in [docs/fasi/fase-extra1.md](docs/fasi/fase-extra1.md)). Lo schema passa alla **versione 6**, con una sola colonna nuova (`bot`) in `wp5w_hits`; nessuna tabella, opzione o evento cron nuovo, quindi `uninstall.php` e `Deactivator` non cambiano.

### Added
- **Colonna `bot` in `wp5w_hits`** (`varchar(20) NOT NULL DEFAULT ''`, dopo `os`, senza indice) con l'etichetta della visita: `ua-corto`, la firma del bot dichiarato, `webdriver` oppure vuota. Migrazione esplicita e idempotente (`Schema::migrate_hits_columns()`, un solo `ALTER TABLE` sotto il lock `wp5w_schema_upgrade`); le righe esistenti ricevono `bot = ''`.
- **`BotFilter::label()`** con le costanti `LABEL_SHORT` e `LABEL_WEBDRIVER`: tra più firme vince la più lunga (`bot` corrisponde anche a GPTBot, ClaudeBot e PetalBot), a parità la prima dell'elenco.
- **Il tracker segnala `navigator.webdriver`** con il parametro `w=1` (solo se esattamente `'1'` lato server) invece di scartare la visita.
- **Box «Aggiornamenti»** nella scheda Stato (`UpdatesCard`) con `GET /wp5w/v1/admin/update`, `POST /admin/update/check` (`manage_options`) e `POST /admin/update/install` (`update_plugins`, `409` senza aggiornamenti), e link «Verifica aggiornamenti» nella riga del plugin verso `admin.php?page=wp5w&tab=status`. Il link «Check for updates» di plugin-update-checker si nasconde.
- `verify.php`: migrazione da v5 a v6 (lock, un solo `ALTER`, idempotenza), installazione senza `wp5w_hits` con le stesse definizioni, etichette e query delle visite segnalate, aggregati con le visite segnalate e nuova sezione 13 «Iterazione 0.11.0» (la pulizia finale diventa la 14); `BotFilterTest` con `label()`.

### Changed
- **Bot e browser automatici non si scartano più:** le visite entrano in `wp5w_hits`, negli aggregati `own`, in «Online adesso», `wp5w_notfound_daily` e `wp5w_search_daily`. Il motivo di scarto `bot` non si scrive più (resta nei dati storici). Gli altri scarti (`dnt`, `signature`, `ip`, `user`, `rate`) e le query dell'endpoint (2 per una visita normale o segnalata, 3 per 404 e ricerche) non cambiano. La raccolta propria può ora contare più di WP Statistics nei giorni in cui crawler dichiarati eseguono JavaScript.
- `BotFilter::is_bot()` è eliminato (nessun codice lo usa più).
- Il box «Pubblico» diventa quattro box affiancati («Pubblico (paesi)», «Dispositivi», «Browser», «Sistemi operativi») con un solo selettore del periodo e una sola chiamata a `dimensions` per cambio; classe `.wp5w-columns--4` (4, 2 e 1 colonne).
- Il testo d'aiuto del Confronto non dice più che la raccolta propria esclude i bot.
- `Schema::DB_VERSION` passa a `6`. Il tracker passa da 706 a 716 byte (limite 1.536).
- `GET /admin/status` non restituisce più `version` e `updates` (li leggeva solo la riga in fondo alla scheda Stato, sostituita dal box «Aggiornamenti»).

## [0.10.0] — 2026-10-15

Fase 4 della [roadmap](docs/roadmap-sostituzione-wp-statistics.md): canali, tempo reale e dati operativi (specifica in [docs/fasi/fase-4.md](docs/fasi/fase-4.md)). Lo schema passa alla **versione 5**, con due tabelle nuove e nessuna migrazione delle chiavi; nessun evento cron nuovo.

### Added
- **Canale «ai» (Assistenti AI)** per ChatGPT, Perplexity, Gemini, Copilot, Claude, DeepSeek, Mistral, Grok e Meta AI (elenco `Channels::AI`). Lo producono `OwnSource` e `WpStatisticsSource` in aggregazione (`entry_daily`, `referrer_daily` e `dim_daily`) con lo stesso frammento SQL `Channels::ai_sql()`, che guarda host del referrer (anche `android-app://pacchetto/`), `utm_source` (solo raccolta propria) e `source_name` (solo WP Statistics). `wp5w_hits`, `Channels::classify()` e l'elenco dei motori di ricerca non cambiano; le somme dei visitatori restano identiche.
- **Etichette dei referrer delle app** con `Support\Referrers` (`android-app://com.linkedin.android/` e `com.linkedin.android` diventano «LinkedIn (app)»): solo presentazione, i dati non cambiano.
- **Campagne UTM:** dimensioni `utm_campaign`, `utm_source` e `utm_medium` in `dim_daily` (solo righe `own`, senza `(n/d)`) e box «Campagne» nella scheda Visitatori, con la rotta `GET /wp5w/v1/admin/audience/campaigns`.
- **«Online adesso»** in cima alla scheda Visitatori (`GET /wp5w/v1/admin/online`): visitatori distinti degli ultimi 5 minuti sul contenuto dell'ultima visita, aggiornato ogni 30 secondi solo mentre la scheda del browser è visibile. La risposta non contiene identificativi.
- **Pagine non trovate** e **Ricerche interne** nella scheda Articoli (rotte `GET /wp5w/v1/admin/notfound` e `/admin/searches`), alimentate dalle nuove tabelle `wp5w_notfound_daily` e `wp5w_search_daily` (solo aggregati giornalieri, senza visitatore né orario per le ricerche). Il tracker invia termine e numero di risultati (`q`, `n`, non firmati) e li decide `Support\SearchTerm` nell'endpoint: normalizzazione, `(filtrato)` per email e numeri lunghi, solo la prima pagina dei risultati.
- **Visite nell'admin:** colonna ordinabile «Visite (30 g)» in Articoli e Pagine, nodo «5W: N visite (30 g)» nella barra di amministrazione (`Admin\PostViews`) e modale di dettaglio aperto da `admin.php?page=wp5w&tab=posts&detail=<ID>`.
- `Support\ObjectType` e test `ReferrersTest`, `SearchTermTest`, `ObjectTypeTest` e nuovi casi di `ChannelsTest`.
- `verify.php`: nuova sezione 12 «Fase 4» (la pulizia finale diventa la 13), migrazione da v4 a v5 e controlli aggiornati; il seed aggiunge 6 visitatori da assistenti AI e app.

### Changed
- `Schema::DB_VERSION` passa a `5`. Il tracker passa da 669 a 706 byte (limite 1.536).
- Nel Confronto un canale non assegnato è una sola riga (prima `(n/d)` di WP Statistics e `unassigned` della raccolta propria erano due), con etichette italiane (compresa «Assistenti AI»); soglia e minimo di visualizzazioni arrivano dalla risposta e le frasi usano `sprintf()` e `_n()`.
- `CompareQuery` usa `ObjectType::is_post()` (titolo e link solo per `post`, `page`, `home` e `post_type_*` con ID maggiore di zero) e il suo `phpcs:disable` è ristretto a quello delle altre classi `Query`.
- Ordine deterministico a parità di visitatori nei referrer del dettaglio (`PostsQuery`) e a parità di click e impression nelle query (`SearchQuery`).
- La regola dell'origine ha ora due eccezioni documentate: `CompareQuery` e `AudienceQuery::campaigns()` (solo `utm_*`).
- Le 404 salvano l'URI codificato (`%XX`) come lo invia il browser, con soli caratteri di un percorso RFC 3986 (prima `sanitize_text_field()` toglieva gli ottetti e URI diversi si fondevano); `wp5w_hits.uri` non cambia. Il termine di ricerca arriva a `SearchTerm::store()` senza `sanitize_text_field()` (`50%ab` e `<div>` non si perdono). La tabella «Provenienza» mostra le etichette italiane dei canali.

## [0.9.0] — 2026-10-14

Fase 3 della [roadmap](docs/roadmap-sostituzione-wp-statistics.md): paese, dispositivo, browser e sistema operativo (specifica in [docs/fasi/fase-3.md](docs/fasi/fase-3.md)). Lo schema resta alla versione 4 e non ci sono tabelle nuove: le colonne `country`, `device`, `browser` e `os` di `wp5w_hits` esistono dalla 0.7.0.

### Added
- **Paese delle visite proprie** con il database DB-IP Lite Country (`maxmind-db/reader` `^1.14`, senza prefisso: WP Statistics usa una copia con il prefisso `WP_Statistics\Dependencies`, quindi non c'è conflitto). Il paese si calcola nell'endpoint dopo l'ultimo scarto, senza nessuna query in più; l'IP non si salva.
- **`Geo\CountryDatabase`:** scarica `dbip-country-lite.mmdb` in `uploads/wp5w/` (con `.htaccess` e `index.php`) con un evento giornaliero `wp5w_geo_update`, che non fa richieste di rete se il file è del mese corrente. Prova il mese corrente e poi il precedente; solo lo stato HTTP 404 vale «non ancora pubblicato». Controlla gzip (CRC e ISIZE), dimensione, metadati e una ricerca di prova prima di sostituire il file con `rename()`; usa solo funzioni PHP e WordPress (sul blog `exec()` è disabilitato). Stato nell'opzione `wp5w_geo`.
- **Dispositivo, browser e sistema operativo** con la classe pura `Support\UserAgent` (regole essenziali, nomi di device-detector come in WP Statistics: `smartphone`, `Mac`, `GNU/Linux`, `Chrome Mobile`...). Lo user agent non si salva.
- **Aggregazione:** `OwnSource` scrive in `dim_daily` le dimensioni `country`, `device`, `browser` e `platform` (il sistema operativo) con la prima visita del giorno di ogni visitatore; il valore sconosciuto è `(n/d)`.
- **Box «Pubblico»** nella scheda Visitatori (paesi, dispositivi, browser e sistemi operativi degli ultimi 7, 30 o 90 giorni, al massimo 10 valori più «Altri») e rotta `GET /wp5w/v1/admin/audience/dimensions`.
- **Card «Database dei paesi»** nella scheda Stato e attribuzione «IP Geolocation by DB-IP» (CC BY 4.0) in fondo alla pagina.
- `Support\GeoDb` e `Support\Dimensions` (classi pure) con `UserAgentTest` (31 user agent reali), `GeoDbTest` e `DimensionsTest`.
- `verify.php`: voce `pubblico` nell'istantanea e nuova sezione 11 (box, download reale, guasti simulati, lock, database assente, visite con paese e user agent, nessuna query in più, privacy, aggregazione); la pulizia finale diventa la 12.

### Changed
- La disattivazione toglie anche l'evento `wp5w_geo_update`; la disinstallazione cancella sempre l'evento, l'opzione `wp5w_geo` e la cartella `uploads/wp5w/`.
- Lo zip esclude da `vendor/maxmind-db/reader` i sorgenti C (`ext/`), la documentazione e i file di tooling, e tiene `src/`, `autoload.php`, `composer.json` e `LICENSE`.

### Fixed
- **`Support\Origin::where()`:** con la forma `( origin = 'wps' AND day < X ) OR ( origin = 'own' AND day >= X )` l'ottimizzatore di MariaDB (10.11 del blog e 12.3 di wp-env) scartava le righe `own` delle tabelle con chiave primaria composta (`dim_daily`...) quando il periodo comprendeva la data di passaggio. Ora la condizione è `origin = IF( day < X, 'wps', 'own' )`, equivalente e corretta. Con `wp5w_own_since` vuota nulla cambia (la regola resta `origin = 'wps'`).

## [0.8.0] — 2026-10-14

Fase 2 della [roadmap](docs/roadmap-sostituzione-wp-statistics.md): confronto in parallelo con WP Statistics, senza cambiare nessun numero delle altre schede (specifica in [docs/fasi/fase-2.md](docs/fasi/fase-2.md)). Nessuna tabella, opzione o cron nuovi: lo schema resta alla versione 4.

### Added
- **Sezione «Confronto»** in cima alla scheda Stato, con tre card: confronto per giorno (visitatori e visualizzazioni delle due origini, differenza percentuale, righe oltre la soglia del 15% evidenziate, totali e visualizzazioni per visitatore), «Dove nasce la differenza» (ingressi diretti sulla home, canali, scarti della raccolta propria con la quota sulle richieste) e «Contenuti con la differenza più grande» (almeno 10 visualizzazioni, ordinati per differenza assoluta).
- **Rotta** `GET /wp5w/v1/admin/compare` (solo amministratori, senza parametri, nessuna scrittura): la finestra la calcola il server e comprende solo giorni completi con entrambe le origini; gli altri sono elencati con il motivo (`partial`, `today`, `etl_pending`, `no_wps`, `no_own`).
- `Support\Comparison` (finestra, soglia, quote degli scarti, classifica) con `ComparisonTest`, e `Query\CompareQuery`, l'unica classe di `src/Query/` che legge le due origini senza `Origin::where()`.
- `verify.php`: nuova sezione 10 «Confronto» (la pulizia finale diventa la 11).

## [0.7.0] — 2026-10-14

Fase 1 della [roadmap](docs/roadmap-sostituzione-wp-statistics.md): raccolta propria delle visite, in parallelo a WP Statistics e senza cambiare nessun numero dell'interfaccia (specifica in [docs/fasi/fase-1.md](docs/fasi/fase-1.md)).

### Added
- **Tracker** `build/tracker/tracker.js` (sotto 1,5 KB, senza dipendenze, senza cookie né `localStorage`): legge il blocco JSON `#wp5w-hit` stampato in `wp_footer` e invia la visita con `sendBeacon` (ripiego `fetch` con `keepalive`). Non parte con `navigator.webdriver`; con Do Not Track (opzione attiva) invia solo `dnt=1`.
- **Endpoint pubblico** `POST /wp5w/v1/hit`, sempre `204`: scarta bot, DNT, firma errata, IP esclusi, utenti collegati (letti dal cookie di login, perché dalle pagine in cache non c'è un nonce) e oltre 30 visite al minuto per visitatore. Per una visita registrata fa una `SELECT` e una `INSERT`.
- **Privacy:** nessun IP né user agent salvati; il visitatore è un hash `md5( ip . user_agent . chiave_del_giorno )` e la chiave (`wp5w_daily_salt`) si rigenera alla prima visita del giorno, sovrascrivendo la precedente; il passaggio orario la cancella se è di un giorno passato e la disattivazione o la disinstallazione la tolgono sempre.
- **Tabelle** `wp5w_hits` (visite grezze) e `wp5w_discards` (scarti per giorno e motivo: `bot`, `user`, `dnt`, `rate`, `signature`, `ip`).
- **Aggregazione** `Sources\OwnSource`: scrive righe `origin = 'own'` nelle cinque tabelle aggregate, con l'ETL notturno e con un passaggio orario (`wp5w_own_hourly`, ieri e oggi, lock proprio, senza toccare «Ultimo import»).
- **Scheda Opzioni** (`/admin/settings`): raccolta propria, utenti collegati da escludere, IP esclusi (anche CIDR), Do Not Track, conservazione delle visite grezze (salvata ma non ancora applicata) e data di passaggio `wp5w_own_since`. La modifica di raccolta o DNT svuota la cache di LiteSpeed.
- Classi pure con test PHPUnit: `Support\Origin`, `BotFilter`, `Channels`, `Visit`.

### Changed
- **Schema alla versione 4:** colonna `origin` (`wps` o `own`, ultima colonna della chiave primaria) nelle cinque tabelle aggregate; migrazione con `ALTER TABLE` esplicito e idempotente sotto un lock MySQL (`dbDelta` non cambia le chiavi primarie).
- **Regola dell'origine:** tutte le interrogazioni in `src/Query/` leggono le righe `wps`, e `own` solo dal giorno di `wp5w_own_since`; finché è vuota i numeri sono quelli di prima. La scheda Stato mostra la copertura con la stessa regola.
- L'ETL di WP Statistics scrive e cancella solo righe `wps`.
- Panoramica: i canali con gli stessi visitatori si ordinano per nome, così l'ordine è stabile.
- `verify.php` copre schema, migrazione da v3, visite simulate, aggregazione, non regressione e opzioni.

## [0.6.0] — 2026-10-14

### Added
- **Indicatore di trend** da -100 a +100: ultime 4 settimane complete contro le 4 precedenti, ponderato su visitatori (50%), visibilità su Google (25%: pagine con impression per il sito, query con impression per un contenuto) e posizione media (25%).
  - Ogni variazione è compressa con tanh, così un picco isolato non domina; le componenti senza dati si escludono e i pesi si ridistribuiscono.
  - Oltre ±10 è «in miglioramento» o «in calo», altrimenti «stabile»; «n.d.» sotto i 20 visitatori in entrambi i periodi, «nuovo» per i contenuti pubblicati da meno di 8 settimane.
  - Panoramica: card del trend del sito con le tre componenti e i periodi confrontati. Colonna «Trend» nella classifica dei contenuti e nelle tabelle della scheda Articoli, con il dettaglio nel tooltip.

## [0.5.0] — 2026-10-14

### Changed
- Eliminata la scheda Search Console (rotta `/admin/search`): KPI e andamento di Google sono già nella Panoramica, le query recenti nella scheda Visitatori.
- Panoramica: «Quasi in prima pagina» e «In alto ma poco cliccate» diventano un'unica tabella «Opportunità su Google» a riga intera, riducibile, con il tipo evidenziato da etichetta e bordo colorati.
- Panoramica: opportunità e pagine su Google hanno un proprio selettore del periodo (ultimi 7, 28, 90 o 180 giorni disponibili in Search Console) e mostrano le date effettive; rotte `/admin/search/opportunities` e `/admin/search/pages` al posto di `/admin/search/content`.
- Visitatori: il grafico degli ultimi 6 mesi mostra i totali di visitatori e visualizzazioni a barre e le medie al giorno a linee su un secondo asse.

## [0.4.0] — 2026-10-14

### Added
- Toggle per ridurre i box a riga intera di tutte le schede; lo stato resta memorizzato nel browser.
- Keyword degli ultimi 7 giorni: variazione dei click rispetto ai 7 giorni precedenti e numero di pagine, come nella tabella Query.

### Changed
- Visitatori: «Ultimi 6 mesi» è raggruppato per mese (totali e medie al giorno) invece che per giorno della settimana; le tabelle dei 7 giorni e dei mesi mostrano il più recente in alto.
- Visitatori: provenienza e keyword occupano tutta la riga; la provenienza separa sorgente e canale.
- Le opportunità («Quasi in prima pagina», «In alto ma poco cliccate») e le pagine su Google passano dalla scheda Search Console alla Panoramica, con il periodo della Panoramica (nuova rotta `/admin/search/content`).
- Tabelle e grafici a barre più compatti.

## [0.3.0] — 2026-10-14

### Added
- Panoramica: nella classifica «Contenuti più visti», filtro per tipo (tutti, articoli, pagine, allegati) e numero di righe (20, 50, 100).
- Scheda **Search Console**:
  - KPI con confronto (solo se il periodo precedente ha dati) e andamento;
  - query con filtro di testo e variazione dei click;
  - pagine, che aprono il dettaglio;
  - opportunità: «quasi in prima pagina» e «in alto ma poco cliccate».
- Scheda **Visitatori**: visitatori e visualizzazioni per giorno della settimana (ultimi 7 giorni e ultimi 6 mesi, con medie), siti di provenienza degli ultimi 7 giorni e keyword degli ultimi 7 giorni disponibili.
- Recupero dello storico di Search Console: `scripts/gsc-backfill.py` scarica giorno, pagina e query dall'API e `wp 5w gsc-import` li importa.

### Changed
- Schema del database alla versione 3: `wp5w_gsc_daily.origin` (`rankmath` o `api`). I giorni importati dall'API, più completi, non vengono più toccati dall'ETL di Rank Math.
- L'ETL di Rank Math sostituisce solo i giorni presenti nella sorgente, quindi i giorni recuperati non vengono più cancellati.
- Le pagine GSC si collegano ai contenuti in PHP: il JOIN tra tabelle con collation diverse falliva.

## [0.2.0] — 2026-10-14

### Added
- Scheda **Articoli**: ultimi 10 articoli pubblicati (settimana e totale dalla pubblicazione) e 10 più visti nella settimana o nel mese, ciascuno con il grafico delle visite giornaliere degli ultimi 7 giorni.
- **Dettaglio di un contenuto in un modale** (dalla scheda Articoli e dalla classifica della Panoramica), con periodo selezionabile, KPI, andamento, ricerche su Google (query, click, impression, CTR, posizione), canali di ingresso e siti di provenienza.
- Visitatori unici per contenuto e giorno (`wp5w_object_daily.visitors`, da `statistics_visitor_relationships`).
- Tabella `wp5w_referrer_daily`, con i siti di provenienza di chi entra da un contenuto.

### Changed
- Schema del database alla versione 2: l'aggiornamento è automatico, ma per popolare i nuovi dati serve `wp 5w etl --full` oppure «Reimporta tutto lo storico».

## [0.1.0] — 2026-10-14

### Added
- Tabelle aggregate `wp5w_site_daily`, `wp5w_object_daily`, `wp5w_entry_daily`, `wp5w_dim_daily` e `wp5w_gsc_daily`.
- Sorgenti WP Statistics 14.x e Rank Math Analytics, con controllo dello schema prima di ogni import.
- ETL idempotente a blocchi mensili. Conserva i giorni già eliminati dalle sorgenti e deduplica le righe di Search Console.
- Cron giornaliero alle 4:00 (ultimi 7 giorni) e import completo dopo l'attivazione.
- Pagina **5W** in wp-admin, con le schede Panoramica (KPI, andamento, canali, contenuti più visti) e Stato.
- Comandi WP-CLI `wp 5w status` e `wp 5w etl`.
- Aggiornamenti automatici dalle release di GitHub.
