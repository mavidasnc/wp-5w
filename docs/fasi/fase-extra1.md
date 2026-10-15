# Iterazione extra 1: tracciamento completo, quattro box del pubblico e aggiornamenti dall'admin

Questa è la specifica di implementazione dell'iterazione **0.11.0**. Non è una fase della roadmap ([roadmap-sostituzione-wp-statistics.md](../roadmap-sostituzione-wp-statistics.md)): nasce dal confronto del 2026-10-15 e dalle richieste di Maurizio, approvate nel piano `analizza-il-documento-docs-roadmap-sosti-refactored-leaf.md`. Valgono comunque le sezioni «Decisioni già prese», «Principio di progetto», «Processo di sviluppo» e «Scostamenti» della roadmap, insieme alle decisioni di [fase-1.md](fase-1.md), [fase-2.md](fase-2.md), [fase-3.md](fase-3.md) e [fase-4.md](fase-4.md). Dove questo documento è più preciso, prevale.

- **Branch:** `iterazione-0.11-tracciamento-completo`, creato da `main` alla release `v0.10.0` (commit `4fc0ba2`).
- **Versione da rilasciare:** `0.11.0` (con `npm run release -- 0.11.0` dopo il merge: la PR non cambia la versione). Lo schema del database **passa alla versione `6`**, con una sola colonna nuova in `wp5w_hits`.
- **Ciclo:** Sonnet implementa su questo branch e apre la PR verso `main` con la checklist dei criteri della sezione 9. Segue la review di Opus 5.5, che rilancia `composer ci`, `npm run check` e `verify.php`. Al massimo 3 giri di correzioni, poi squash merge, release, deploy secondo il runbook (sezione 10) e nota di consegna.
- **Obiettivo:** tre lavori indipendenti.
  - **A.** Registrare tutto il traffico senza filtri anti-bot, segnalando le visite sospette in una colonna nuova, per capire fra qualche giorno chi sono i circa 120 visitatori al giorno che WP Statistics conta e la raccolta propria no.
  - **B.** Separare il box «Pubblico» della scheda Visitatori in quattro box affiancati.
  - **C.** Verificare e installare gli aggiornamenti da GitHub dalla scheda Stato.

---

## 1. Dati di partenza

Dalla nota di consegna della fase 4 e dai controlli del 2026-10-15:

- **Nessun proxy davanti al sito:** hash e paesi diversi, quindi `REMOTE_ADDR` è l'IP del client.
- **Il buco:** dal 2026-10-14 alle 18:39 UTC WP Statistics conta 99 visitatori, la raccolta propria 19. Circa 120 visitatori al giorno di WP Statistics (da almeno il 2026-10-01) hanno un profilo uniforme: smartphone, Chrome Mobile su Android e iOS (versioni 147-151), canale `direct`, una sola visita, sempre la home, paesi sparsi (US, PL, NL, BR, IN), distribuiti nelle 24 ore. Il resto del traffico coincide (20 contro 19).
- **Gli scarti propri sono pochissimi** (`bot` 1, `signature` 4, `user` 11): i filtri sui bot dichiarati e sullo user agent corto non spiegano la differenza.
- **Ipotesi principale:** il controllo di `navigator.webdriver` in `assets/tracker/tracker.js` (righe 17-20). Scarta in silenzio i browser automatizzati e non lascia traccia nei contatori. Togliere solo il filtro dei bot dichiarati cambierebbe poco: è la modifica sul webdriver che può far emergere quel traffico.
- **Peso del tracker oggi:** `build/tracker/tracker.js` pesa 706 byte, contro un tetto di 1.536 (`scripts/check-build.js`). Il tetto resta com'è.
- **Zip della release 0.10.0:** 446.786 byte.
- **plugin-update-checker:** è già nel plugin (`src/Support/Updater.php`, versione `v5p7`), con `enableReleaseAssets()`, quindi WordPress propone già l'aggiornamento dallo zip della release (con `vendor/` e `build/`). `Updater::check()` non è mai chiamato. La libreria aggiunge da sola il link «Check for updates» nei meta della riga del plugin (`Plugin/Ui.php`, `plugin_row_meta`), con il filtro `puc_manual_check_link-wp-5w` (nome da `getUniqueName( 'manual_check_link' )`) che lo nasconde se restituisce una stringa vuota.

---

## 2. Decisioni prese in questa specifica

Le scelte di Maurizio (limite di frequenza invariato, box degli aggiornamenti nella scheda Stato, bot segnalati e non scartati) sono vincolanti. Dove la richiesta lasciava una scelta aperta, si decide così:

| # | Decisione | Motivo |
|---|---|---|
| D1 | `BotFilter::label( string $user_agent, bool $webdriver = false ): string` è una funzione pura che restituisce l'etichetta: prima `ua-corto`, poi la firma, poi `webdriver`, altrimenti `''`. | La precedenza si prova con PHPUnit e l'endpoint non ha logica propria. Le etichette lato server sono più precise del flag del client, che non è firmato. |
| D2 | Tra le firme che corrispondono vince **la più lunga**. A parità di lunghezza vince la prima dell'elenco, che non si riordina. | `bot` è la prima firma e corrisponde anche a GPTBot, ClaudeBot, PetalBot e Amazonbot: con la prima corrispondenza quasi tutto finirebbe in `bot` e l'analisi non direbbe nulla. |
| D3 | `BotFilter::is_bot()` si **elimina** e i suoi test passano a `label()`. | Dopo la modifica non la usa più nessun codice di `src/`: per le regole del progetto un elemento reso orfano dalle proprie modifiche va tolto. |
| D4 | L'etichetta si calcola nel passo 9 dell'endpoint, insieme a paese e user agent, cioè dopo tutti gli scarti. | Gli scarti restano veloci e le query restano 2 per una visita normale (verify 12.7, `CLAUDE.md`). |
| D5 | Il flag `w` vale solo se è esattamente `'1'`. Qualsiasi altro valore vale «assente». | Nella colonna arrivano solo valori di un elenco fisso: nessun testo del client finisce nel database. |
| D6 | Colonna `bot varchar(20) NOT NULL DEFAULT ''` dopo `os`, **senza indice**. | La firma più lunga (`facebookexternalhit`) ha 19 caratteri. L'analisi riguarda pochi giorni e usa la chiave `day`; un indice in più rallenterebbe ogni `INSERT`. |
| D7 | «Online adesso», `wp5w_notfound_daily` e `wp5w_search_daily` contano **anche** le visite segnalate. | Stessa regola degli aggregati: il traffico segnalato si separa quando si saprà cosa è, ricalcolando dai dati grezzi. |
| D8 | Il testo d'aiuto del Confronto (`Compare.jsx`) si corregge, perché dalla 0.11.0 non è più vero che la raccolta propria «esclude i bot». | Un testo falso nell'interfaccia porterebbe a leggere male i numeri. |
| D9 | Nei quattro box, il titolo resta l'`h3` di `DimensionList` (che non cambia, perché lo usa anche `Campaigns`), e ogni box è `section.wp5w-card.wp5w-dimensions`. Sopra la riga c'è una barra con l'intervallo e il `PeriodSwitch`. | Si evitano doppi titoli e si riusa lo stile `.wp5w-dimensions h3` già esistente. |
| D10 | Il changelog **non** compare nel box degli aggiornamenti: resta il link alle note di versione su GitHub. | In `v5p7` il changelog sta solo in `PluginInfo->sections` (`Vcs/PluginUpdateChecker.php`, riga 58), non nell'oggetto `Update`. Servirebbe `requestInfo()`, cioè un'altra chiamata a GitHub, e HTML di Parsedown da inserire in React: non è «semplice». |
| D11 | Il link «Verifica aggiornamenti» si aggiunge dentro il filtro esistente `AdminMenu::add_dashboard_link()`, dopo «Dashboard». Il link «Check for updates» di plugin-update-checker si nasconde con `puc_manual_check_link-wp-5w` in `Updater::init()`. | Due link quasi uguali nella stessa riga confondono, e quello del plugin porta al box con installazione e note di versione. |
| D12 | Le chiavi `version` e `updates` di `GET /admin/status` si **tolgono**, insieme all'import di `Updater` in `AdminController` se resta inutilizzato. | Le leggeva solo la riga in fondo a `StatusPage.jsx`, che sparisce: ora i dati arrivano da `GET /admin/update`. |
| D13 | Nessuna installazione reale dell'aggiornamento in wp-env né sul blog. In `verify.php` si provano permessi, controllo e rifiuto con `409`, con le risposte di GitHub simulate. | wp-env monta il checkout come cartella del plugin: `Plugin_Upgrader` svuota la destinazione e cancellerebbe la copia di lavoro. Sul blog l'unico aggiornamento reale è il deploy stesso. Il percorso completo si prova alla prossima release (sezione 10.14). |
| D14 | `uninstall.php` e `Deactivator` non cambiano. | Non ci sono tabelle, opzioni né eventi nuovi. L'opzione `external_updates-wp-5w` di plugin-update-checker non viene tolta dalla disinstallazione, ma è così dalla 0.1: si segnala nella sezione 11, senza correggerla. |
| D15 | La verifica visiva si fa sull'istanza **tests** di wp-env (porta 8896, `tests-cli`), come chiesto, con i dati di `seed.php` caricati lì. | `seed.php` e `verify.php` girano di solito su `cli` (porta 8895): senza il seed sull'istanza tests i quattro box sarebbero vuoti. |

---

## 3. Regole di business

### R1. Visite registrate e scarti (`REST\HitController`)

L'ordine dei passi dell'endpoint diventa questo. Ci si ferma al primo scarto:

1. schema non migrato o raccolta spenta: niente da fare (invariato);
2. Do Not Track, con scarto `dnt` (invariato);
3. **tolto:** lo scarto `bot` per firme e user agent vuoti o corti non esiste più;
4. tipo, ID e firma HMAC, con scarto `signature` (invariato);
5. IP esclusi, con scarto `ip` (invariato);
6. utenti collegati, con scarto `user` (invariato);
7. hash del visitatore (invariato);
8. limite di frequenza di **30 visite al minuto per hash**, con scarto `rate` (invariato, scelta di Maurizio). Vale anche per le visite segnalate;
9. referrer, canale, sorgente, ingresso, paese, user agent e, in più, `$bot = BotFilter::label( $ua, '1' === $this->param( $request, 'w' ) )`;
10. `INSERT` in `wp5w_hits` con la colonna `bot` (troncata a 20 caratteri, per prudenza);
11. aggregati di 404 e ricerche (invariato).

I passi si rinumerano nei commenti. Il docblock della classe e quello di `discard()` elencano i motivi attuali (`user`, `dnt`, `rate`, `signature`, `ip`) e dicono che `bot` resta solo nei dati storici. L'import di `BotFilter` resta, perché serve `label()`.

### R2. Etichetta (`Support\BotFilter::label()`)

- **Passo 1:** si toglie lo spazio iniziale e finale dallo user agent. Se è più corto di `MIN_LENGTH` (16 byte), l'etichetta è `ua-corto`.
- **Passo 2:** si cercano tutte le firme di `SIGNATURES` senza distinguere maiuscole e minuscole (`stripos`). Se ce n'è almeno una, l'etichetta è la firma più lunga; a parità di lunghezza, la prima dell'elenco. La firma si restituisce com'è nell'elenco, sempre in minuscolo (per esempio `curl/` o `python-`).
- **Passo 3:** se `$webdriver` è vero, l'etichetta è `webdriver`.
- **Altrimenti** è `''`.

Due costanti pubbliche nuove: `LABEL_SHORT = 'ua-corto'` e `LABEL_WEBDRIVER = 'webdriver'`. `SIGNATURES` e `MIN_LENGTH` non cambiano. La classe resta pura, senza guard ABSPATH.

Esempi attesi: Googlebot dà `bot`, GPTBot `gptbot`, ClaudeBot `claudebot`, PetalBot `petalbot`, Bytespider `bytespider`, AhrefsBot `ahrefs`, UptimeRobot `uptime`, HeadlessChrome `headless`, `facebookexternalhit/1.1` `facebookexternalhit`, `curl/8.4.0` (11 caratteri) `ua-corto`, `curl/8.4.0 (x86_64-pc-linux-gnu)` `curl/`, Chrome Mobile con `webdriver` vero `webdriver`, HeadlessChrome con `webdriver` vero `headless`.

### R3. Tracker (`assets/tracker/tracker.js`)

- Il blocco «2) Browser automatici: niente visita» si toglie.
- Nel solo ramo senza Do Not Track (il 4), dopo i parametri UTM: `if ( navigator.webdriver ) { p.set( 'w', '1' ); }`. Con Do Not Track il corpo resta solo `dnt=1`, come prevede R2 della fase 1.
- I commenti si rinumerano, e l'intestazione dice che i browser automatici si segnalano e non si scartano.
- Il peso deve restare sotto 1.536 byte. Con circa 30 byte in più si arriva a circa 740, quindi il tetto non cambia.

### R4. Aggregati

- `OwnSource` **non cambia**: aggrega già tutte le righe di `wp5w_hits`, quindi le visite segnalate entrano in `site_daily`, `object_daily`, `entry_daily`, `referrer_daily` e `dim_daily` (righe `own`). Così il Confronto con WP Statistics resta omogeneo.
- **Non** si aggiunge `bot` all'elenco delle colonne richieste di `OwnSource` (riga 71): la sorgente verrebbe saltata se lo schema non fosse ancora migrato.
- `OnlineQuery`, `wp5w_notfound_daily` e `wp5w_search_daily` contano anche le visite segnalate (D7).
- `Comparison::REASONS` tiene `bot` per i giorni storici. Il Confronto mostra `bot` a 0 per i giorni dalla 0.11.0.
- **Nuova aspettativa:** da ora la raccolta propria può contare **più** di WP Statistics nei giorni in cui crawler dichiarati eseguono JavaScript, perché WP Statistics li scarta. Il segno delle differenze del Confronto può invertirsi: è atteso e va spiegato nella nota.
- Gli aggregati si possono ricalcolare dai dati grezzi quando si deciderà come mostrare il traffico segnalato: `retention_days` oggi non viene applicata da nessun codice, quindi le righe di `wp5w_hits` restano.

### R5. Testo del Confronto (`assets/admin/components/Compare.jsx`)

Il testo d'aiuto diventa: «La raccolta propria può contare un po' meno, perché esclude gli amministratori; dalla versione 0.11.0 bot e browser automatici si registrano e si segnalano. Una differenza stabile entro il %s è normale; i giorni oltre la soglia sono evidenziati.» L'etichetta `bot` («Bot e crawler») resta per i giorni storici.

### R6. Quattro box nella scheda Visitatori (`components/AudienceDimensions.jsx`)

Struttura del componente, al posto della `Card` riducibile:

```jsx
<div className="wp5w-audience-dims">
	<div className="wp5w-card__head">
		<span className="wp5w-type">{ /* intervallo dd/mm/aaaa – dd/mm/aaaa, solo con i dati */ }</span>
		<PeriodSwitch days={ days } onChange={ setDays } />
	</div>
	{ /* Notice di errore oppure Spinner, uno solo per tutta la riga */ }
	<div className="wp5w-columns wp5w-columns--4">
		{ LISTS.map( ( list ) => (
			<section key={ list.key } className="wp5w-card wp5w-dimensions">
				<DimensionList title={ list.title } dim={ data.dims[ list.key ] } label={ list.label } />
			</section>
		) ) }
	</div>
	<p className="wp5w-note">{ /* nota esistente: visitatori sommati, «Dati disponibili dal», «Nessun dato nel periodo» */ }</p>
</div>
```

- I titoli di `LISTS` diventano **«Pubblico (paesi)»**, «Dispositivi», «Browser» e «Sistemi operativi».
- Un solo `PeriodSwitch` (predefinito 30 giorni) e una sola chiamata a `GET /admin/audience/dimensions?days=N` a ogni cambio di periodo. `AudienceQuery::dimensions()` e la rotta non cambiano.
- `DimensionList.jsx` e `Campaigns.jsx` non cambiano.
- Il blocco `.wp5w-audience-dims` non ha bordo né sfondo: la barra sta sopra la riga e i box sono le quattro `section.wp5w-card`.
- Il nome `wp5w-audience-dims` è nuovo. Se serve uno stile (per esempio il margine sotto la barra), si aggiunge in `admin.scss` con questo prefisso BEM.

### R7. Stile `.wp5w-columns--4` (`assets/admin/styles/admin.scss`)

Va scritto **dopo** il blocco `.wp5w-columns`, perché la regola `@media (max-width: 1100px) { grid-template-columns: 1fr; }` della classe base ha la stessa specificità e altrimenti vincerebbe:

```scss
// Quattro box affiancati (scheda Visitatori, 0.11.0): 4 colonne da 1280 px, 2 fino a 700 px, 1 sotto.
.wp5w-columns--4 {
	grid-template-columns: repeat(4, minmax(0, 1fr));

	@media (max-width: 1279px) {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	@media (max-width: 699px) {
		grid-template-columns: minmax(0, 1fr);
	}
}
```

Le larghezze sono quelle della finestra del browser, come negli altri breakpoint del file. `minmax(0, 1fr)` impedisce che un'etichetta lunga allarghi una colonna.

### R8. REST degli aggiornamenti (`src/REST/UpdateController.php`, nuovo)

Il modello è `projects/wp-book-a-call/src/REST/UpdateController.php`, adattato così:

- estende `Mavida\FiveW\REST\RestController` (namespace `wp5w/v1`, `check_admin_permission()`, `respond()` senza cache);
- le rotte sono `GET /admin/update` (`status`, `manage_options`), `POST /admin/update/check` (`check`, `manage_options`) e `POST /admin/update/install` (`install`, permesso `update_plugins` in `check_update_permission()`);
- i codici di errore sono `wp5w_forbidden_update` (permesso, stato `rest_authorization_required_code()`), `wp5w_no_update` (`409`), `wp5w_update_failed` (`500`) e `wp5w_no_updater` (`500`); il text domain è `wp-5w`;
- `install()` chiama prima `Updater::check()`. Se non c'è un aggiornamento risponde `409` **senza** caricare l'upgrader. Altrimenti esegue `Plugin_Upgrader::upgrade( plugin_basename( WP5W_PLUGIN_FILE ) )` con `WP_Ajax_Upgrader_Skin`, raccoglie i messaggi dello skin in caso di errore e riattiva il plugin se era attivo e non lo è più (`activate_plugin( $basename, '', false, true )`);
- la risposta di `install` è `{ installed: true, version: <latest> }`;
- si registra in `Plugin::init()` accanto agli altri controller: `( new UpdateController() )->register();`.

`Updater` cambia solo in `init()`: dopo `enableReleaseAssets()` si aggiunge `add_filter( $checker->getUniqueName( 'manual_check_link' ), '__return_empty_string' );` (D11). `status()` e `check()` non cambiano.

### R9. Box «Aggiornamenti» nella scheda Stato (`components/UpdatesCard.jsx`, nuovo)

Il modello è `projects/wp-book-a-call/assets/admin/pages/Updates.jsx`, dentro `<Card id="status-updates" title={ __( 'Aggiornamenti', 'wp-5w' ) }>`:

- al montaggio carica `GET /admin/update`; durante il caricamento mostra `Spinner`, e in caso di errore una `Notice` di errore;
- un elenco `dl.wp5w-updates__facts` con «Versione installata», «Ultima versione» (con `span.wp5w-updates__badge` «Nuova» se `update_available`) e «Ultimo controllo» (data e ora in `it-IT`, oppure «mai»);
- nella barra `div.wp5w-actions`: «Verifica aggiornamenti» (`secondary`, `POST /admin/update/check`, con avviso «È disponibile la versione X» oppure «Il plugin è aggiornato all'ultima versione»), e «Installa la versione X» (`primary`, solo se `update_available`, `POST /admin/update/install`). Dopo un'installazione riuscita compare l'avviso «Aggiornato alla versione X. Ricarico la pagina…» e la pagina si ricarica dopo 2 secondi;
- i pulsanti sono disabilitati durante un'operazione, con `isBusy` su quello in corso;
- sotto c'è il link esterno «Note di versione su GitHub» (`releases_url`) e la nota «WordPress controlla da solo ogni 12 ore: qui puoi farlo subito.»;
- in `StatusPage.jsx` la `Card` va in fondo, al posto del paragrafo `wp5w-note` con la versione (righe 181-184), che si toglie.

Stili in `admin.scss`, adattati da `wp-book-a-call/assets/admin/styles/admin.scss` (righe 230-261) con il blocco BEM `wp5w-updates`: `.wp5w-updates__facts` (griglia `repeat(auto-fit, minmax(180px, 1fr))`, `dt` piccolo e maiuscolo grigio `#646970`, `dd` 1,25rem in grassetto) e `.wp5w-updates__badge` (pillola grigia `#f0f0f1`, 12px, grassetto, margine sinistro di 8px).

### R10. Link nella riga del plugin (`src/Admin/AdminMenu.php`)

`add_dashboard_link()` aggiunge, dopo «Dashboard», `<a href="…admin.php?page=wp5w&tab=status">Verifica aggiornamenti</a>` (`esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=status' ) )`, testo `esc_html__`). L'app apre già la scheda da `?tab=` (`assets/admin/index.jsx`, riga 21). Il docblock del metodo si aggiorna.

### R11. Privacy

- `bot` contiene solo valori di un elenco fisso (`''`, `ua-corto`, `webdriver` e le firme di `SIGNATURES`): non è un dato personale e non permette di ricostruire lo user agent.
- Nessuna colonna contiene `ip` o `agent` nel nome. Il controllo esistente di verify resta.
- IP e user agent continuano a non essere salvati.

---

## 4. Schema del database e migrazione alla versione 6

- `Schema::DB_VERSION` diventa `'6'`.
- In `create_tables()` la definizione di `wp5w_hits` riceve, dopo `os`, la riga `bot varchar(20) NOT NULL DEFAULT '',`. Il commento sopra la tabella dice che `bot` è vuoto per le visite normali e vale `ua-corto`, `webdriver` o la firma del bot dichiarato.
- Il commento della tabella `wp5w_discards` dice che `bot` non si scrive più dalla 0.11.0 e resta per i giorni storici.
- **Migrazione esplicita e idempotente** come quella della v4. Un nuovo metodo privato `migrate_hits_columns(): bool` viene chiamato in `install()` dopo `migrate_primary_keys()` e prima di `create_tables()`, sempre sotto `GET_LOCK( 'wp5w_schema_upgrade', 0 )`:
  1. se `wp5w_hits` non esiste, restituisce vero (ci pensa `dbDelta()`);
  2. se `SHOW COLUMNS` contiene già `bot`, restituisce vero senza eseguire nulla;
  3. altrimenti esegue `ALTER TABLE {hits} ADD COLUMN bot varchar(20) NOT NULL DEFAULT '' AFTER os`. Se fallisce, annota l'errore con `error_log()` e restituisce falso: `install()` esce senza aggiornare `wp5w_db_version`.
- La colonna è nella stessa posizione con la migrazione e con l'installazione nuova, quindi `SHOW CREATE TABLE` è identico nei due casi.
- Le righe esistenti ricevono `bot = ''`.
- **Rollback:** la 0.10.0 su un database v6 riesegue `install()`, salva la versione `5` e lascia la colonna (`dbDelta()` non toglie colonne). La sua `INSERT` non nomina `bot`, quindi vale il valore predefinito. Un nuovo deploy della 0.11.x trova la colonna e non esegue `ALTER`.
- `uninstall.php` e `Deactivator` non cambiano (D14).

---

## 5. File da creare o modificare

### Da creare

| File | Contenuto |
|---|---|
| `src/REST/UpdateController.php` | R8. |
| `assets/admin/components/UpdatesCard.jsx` | R9. |

### Da modificare

| File | Modifica |
|---|---|
| `src/Support/BotFilter.php` | `label()`, `LABEL_SHORT`, `LABEL_WEBDRIVER`; si elimina `is_bot()` (R2, D3). Docblock della classe aggiornato: le firme segnalano, non scartano. |
| `src/REST/HitController.php` | R1: via il passo 3, etichetta nel passo 9, colonna `bot` nell'`INSERT`, docblock e numerazione dei passi. |
| `assets/tracker/tracker.js` | R3. |
| `src/Database/Schema.php` | Sezione 4. |
| `src/Plugin.php` | Registrazione di `UpdateController`. |
| `src/Support/Updater.php` | Filtro `manual_check_link` in `init()` (D11). |
| `src/Admin/AdminMenu.php` | R10. |
| `src/REST/AdminController.php` | Via le chiavi `version` e `updates` da `status()`, e l'import di `Updater` se resta inutilizzato (D12). |
| `assets/admin/components/AudienceDimensions.jsx` | R6. |
| `assets/admin/pages/StatusPage.jsx` | R9: `UpdatesCard` al posto della riga in fondo. |
| `assets/admin/components/Compare.jsx` | R5. |
| `assets/admin/styles/admin.scss` | R7, gli stili di R9 ed eventualmente `.wp5w-audience-dims` (R6). |
| `tests/Unit/BotFilterTest.php` | Sezione 6. |
| `tests/fixtures/verify.php` | Sezione 7. |
| `CHANGELOG.md` | Sezione `[0.11.0]` con Added, Changed e la nota sullo schema v6. |
| `README.md` | Riga «Esclusioni» (i bot e i browser automatici si segnalano nella colonna `bot`, non si scartano), descrizione di `wp5w_hits` (colonna `bot`) e di `wp5w_discards` (`bot` solo storico), «Aggiornamenti» (box nella scheda Stato con verifica e installazione, link nella riga del plugin), box «Pubblico» in quattro box. |
| `CLAUDE.md` | Struttura: `UpdateController`, `UpdatesCard`, `BotFilter::label()`, schema v6 nel commento di verify. Punti da ricordare: la voce su `navigator.webdriver` (Playwright registra la visita con `bot='webdriver'`, a meno di sovrascrivere il flag; con lo user agent predefinito di Playwright l'etichetta è `headless`); una voce nuova «Bot segnalati, non scartati» (colonna `bot`, etichette fisse, la firma più lunga, aggregati e Online adesso li comprendono, `discards.bot` solo storico); una voce «Aggiornamenti dall'admin» (zip della release con `vendor/` e `build/`; mai provare l'installazione in wp-env, che monta il checkout; non copiare gli updater di woo-customers-price-list, wp-multi-magazzino e wp-semantic-ai). |
| `docs/roadmap-sostituzione-wp-statistics.md` | Riga negli «Scostamenti»: già aggiunta con questa specifica. |

La versione (`wp-5w.php`, `package.json`) la cambia `npm run release`, non la PR.

---

## 6. Test PHPUnit (`tests/Unit/BotFilterTest.php`)

`test_detects_bots` e `test_lets_real_browsers_through` usano `label()`: per ogni caso di `bots()` l'etichetta non è vuota, per ogni caso di `browsers()` è vuota (con `$webdriver` falso). In più:

| Test | Casi |
|---|---|
| `test_label` (data provider) | Gli esempi di R2: Googlebot `bot`, GPTBot `gptbot`, ClaudeBot `claudebot`, PetalBot `petalbot`, Bytespider `bytespider`, AhrefsBot `ahrefs`, UptimeRobot `uptime`, HeadlessChrome `headless`, `facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)` `facebookexternalhit`, `meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)` `meta-externalagent`, `''` `ua-corto`, 20 spazi `ua-corto`, `Mozilla/5.0` `ua-corto`, `curl/8.4.0` `ua-corto`, `curl/8.4.0 (x86_64-pc-linux-gnu) libcurl` `curl/`, `GPTBOT/1.0 (COMPATIBLE; +HTTPS://OPENAI.COM/GPTBOT)` in maiuscolo `gptbot`. |
| `test_label_webdriver` | Chrome Mobile su Android 14 con `webdriver` vero dà `webdriver`; lo stesso con `webdriver` falso dà `''`; HeadlessChrome con `webdriver` vero dà `headless`; `''` con `webdriver` vero dà `ua-corto`. |
| `test_labels_fit_the_column` | Ogni elemento di `SIGNATURES`, `LABEL_SHORT` e `LABEL_WEBDRIVER` è lungo al massimo 20 byte, è in minuscolo e non è vuoto. |
| `test_longest_signature_wins_regardless_of_order` | Per uno user agent che contiene `bot` e `petalbot`, l'etichetta è `petalbot`, anche se `bot` viene prima in `SIGNATURES`. |

`ComparisonTest` non cambia: il caso «tutti i 6 motivi presenti e in ordine» copre già la voce `bot`.

---

## 7. Controlli in `tests/fixtures/verify.php`

`seed.php` non cambia. Il commento in testa a verify cita lo schema v6 e l'iterazione 0.11.0.

### Sezione 1 (schema)

- `schema: versione 6` (letterale `'6'`) e `wp5w_db_version = Schema::DB_VERSION`.
- La colonna `bot` di `wp5w_hits` esiste, è `varchar(20)`, `NOT NULL`, con valore predefinito `''`, e viene subito dopo `os` (da `information_schema.columns`, `ORDINAL_POSITION`).
- Il controllo «nessuna colonna ip o agent» resta com'è.

### Sezione 2 (migrazioni)

- I controlli letterali `'5'` dopo le migrazioni da v3 e da v4 diventano `'6'`. Le migrazioni da v3 e da v4 continuano a non eseguire `ALTER` su `wp5w_hits`, solo perché la tabella ha già la colonna in quel momento: un commento lo dice.
- **Nuovo: da v5 a v6.**
  1. `ALTER TABLE … DROP COLUMN bot` su `wp5w_hits`, con almeno una riga presente (se non ce ne sono, se ne inserisce una di prova da togliere alla fine), e `wp5w_db_version` a `5`.
  2. Con il lock tenuto da una seconda connessione (come per la v3), `Schema::maybe_upgrade()` non cambia nulla: versione ancora `5` e nessuna colonna `bot`.
  3. Con il lock libero, `Schema::install()` esegue **esattamente 1** `ALTER TABLE` (contato con il filtro `query`), nessun errore, la colonna esiste dopo `os`, la riga esistente ha `bot = ''`, la versione è `6`.
  4. Rieseguita con la versione forzata a `5`: **0** `ALTER` e `SHOW CREATE TABLE` identico.
- **Nuovo: installazione senza `wp5w_hits`.** Si salva il contenuto della tabella, la si elimina, si porta la versione a `5`: `install()` la ricrea con la colonna `bot`, senza `ALTER`. `SHOW CREATE TABLE` di `wp5w_hits` è identico a quello ottenuto con la migrazione del punto 3. Poi si ripristinano le righe.

### Sezione 5 (visite simulate)

I controlli attuali sui bot (righe 486-491 circa) **si invertono**:

- `Googlebot/2.1 (+http://www.google.com/bot.html)`: `204`, **una riga** con `bot = 'bot'`, scarti `bot` invariati;
- user agent vuoto: `204`, una riga con `bot = 'ua-corto'`;
- in più: GPTBot (`gptbot`), Chrome con `w=1` (`webdriver`), Chrome con `w=0`, `w=true` e `w[]=1` (`''`, e nessun warning PHP per il parametro non scalare), Chrome senza `w` (`''`), HeadlessChrome con `w=1` (`headless`);
- un bot dichiarato con Do Not Track viene scartato come `dnt`, e con una firma errata come `signature`, senza righe;
- una visita di un bot dichiarato esegue 2 query su `wp5w_hits` (una `SELECT` e una `INSERT`), come una visita normale;
- `SELECT DISTINCT bot FROM wp5w_hits` restituisce solo valori di `array_merge( array( '', BotFilter::LABEL_SHORT, BotFilter::LABEL_WEBDRIVER ), BotFilter::SIGNATURES )`;
- per tutta la sezione, lo scarto `bot` non aumenta mai;
- il limite di frequenza (31 visite, 30 righe, uno scarto `rate`) resta com'è, e un secondo blocco di 31 visite **con un user agent di bot dichiarato** dà lo stesso risultato.

Ogni user agent di prova usa un IP diverso o un'etichetta diversa, così le righe si riconoscono senza ambiguità.

### Sezione 6 (aggregazione oraria)

Dopo `run_hourly()`:

- i visitatori `own` di oggi in `site_daily` sono uguali a `COUNT(DISTINCT visitor)` su **tutte** le righe di oggi di `wp5w_hits`, e sono maggiori di `COUNT(DISTINCT visitor)` sulle sole righe con `bot = ''` (cioè le visite segnalate sono comprese);
- la somma dei visitatori `own` di oggi è la stessa per le sei dimensioni `channel`, `source`, `country`, `device`, `browser` e `platform`, ed è uguale ai visitatori `own` di `site_daily`;
- le visualizzazioni `own` di `object_daily` per l'articolo di prova sono uguali a `COUNT(*)` delle sue righe di oggi, comprese quelle segnalate.

### Nuova sezione 13. «Iterazione 0.11.0» (la pulizia finale diventa la 14)

1. **Rotte:** `GET /wp5w/v1/admin/update`, `POST /wp5w/v1/admin/update/check` e `POST /wp5w/v1/admin/update/install` sono registrate.
2. **Permessi:**
   - senza utente, tutte e tre rispondono `401`;
   - con un abbonato creato dal test, `403`;
   - con un amministratore a cui un filtro `user_has_cap` toglie solo `update_plugins`, `GET` e `check` rispondono `200` e `install` `403` con codice `wp5w_forbidden_update`.
3. **Risposte di GitHub simulate** con `pre_http_request` per tutti gli URL di `api.github.com`, `github.com` e `raw.githubusercontent.com`. Nessuna richiesta reale: un contatore delle richieste non simulate deve restare a 0. L'ultima release simulata è `v0.10.0`, con lo zip tra gli asset. Lo stato di plugin-update-checker (opzione `external_updates-wp-5w`) si salva prima e si ripristina alla fine.
4. **Con l'amministratore:**
   - `GET /admin/update` risponde `200`, con `current = WP5W_VERSION`, `releases_url` di GitHub e le chiavi `latest`, `update_available` e `checked_at`;
   - `POST /admin/update/check` risponde `200`, con `update_available` falso e `checked_at` non più vecchio di 60 secondi;
   - `POST /admin/update/install` risponde `409` con codice `wp5w_no_update`, e un filtro su `upgrader_pre_download` (che deve restare a 0 chiamate) prova che l'upgrader non è partito.
5. **Con una release simulata `v99.0.0`:** `check` risponde con `update_available` vero e `latest = '99.0.0'`. Su questo stato **non** si chiama `install`.
6. **Link nella riga del plugin:** `( new AdminMenu() )->add_dashboard_link( array() )` contiene, nell'ordine, «Dashboard» e un link con `page=wp5w&amp;tab=status` e il testo «Verifica aggiornamenti».
7. **Link di plugin-update-checker:** `apply_filters( 'plugin_row_meta', array(), plugin_basename( WP5W_PLUGIN_FILE ), array(), '' )` non contiene `puc_check_for_updates`. Se il filtro della libreria si aggancia solo in admin, si prova `apply_filters( 'puc_manual_check_link-wp-5w', 'x' )`, che deve dare `''`.
8. **`GET /admin/status`** risponde `200` senza le chiavi `version` e `updates`, e con le altre chiavi di prima (`sources`, `tables`, `last_run`, `next_run`, `geo`).
9. **`GET /admin/audience/dimensions?days=30`** ha la stessa forma di prima: `range`, `first_day` e `dims` con `country`, `device`, `browser` e `platform`. Non cambia niente lato server.
10. **Tracker compilato:** `build/tracker/tracker.js` contiene `webdriver` e la sequenza `"w","1"` (o `'w','1'`, secondo l'output di webpack). È sotto 1.536 byte.

### Sezione 14 (pulizia finale)

Toglie anche l'abbonato, i filtri, le simulazioni HTTP della sezione 13, e ripristina `external_updates-wp-5w`.

---

## 8. Verifiche in wp-env (prima della PR)

1. `composer ci`, `npm run check`, poi `seed.php` e `verify.php` su `cli`: «Tutti i controlli superati».
2. **Tracker con un browser automatico vero** (istanza `cli`, porta 8895): Playwright (`node_modules/playwright-core`, come negli script delle fasi precedenti) apre un articolo **senza** sovrascrivere `navigator.webdriver` e con uno user agent da browser, per esempio Chrome Mobile su Android. Il beacon risponde `204` e il suo corpo contiene `w=1`; in `wp5w_hits` c'è una riga con `bot = 'webdriver'`. Con lo user agent predefinito di Playwright (HeadlessChrome) la riga ha `bot = 'headless'`. Con lo script di init che mette `webdriver` a falso, la riga ha `bot = ''` e il corpo non contiene `w`. Con `navigator.doNotTrack` a `'1'` e l'opzione attiva il corpo è solo `dnt=1`.
3. **Quattro box** (istanza tests, porta 8896, D15): `npx wp-env run tests-cli wp eval-file wp-content/plugins/wp-5w/tests/fixtures/seed.php`, poi l'ETL (`npx wp-env run tests-cli wp 5w etl`). Accesso con `admin`/`password`, scheda Visitatori. Screenshot in `docs/fasi/img/`:
   - `extra1-pubblico-1440.jpg`: finestra larga 1440 px, quattro box su una riga;
   - `extra1-pubblico-1000.jpg`: circa 1000 px, due righe di due box;
   - `extra1-pubblico-600.jpg`: circa 600 px, una colonna.

   Con i tre periodi parte una sola chiamata a `dimensions` per ogni cambio (scheda Network). Gli screenshot si fanno con claude-in-chrome; se l'estensione non è disponibile, con Playwright alle stesse larghezze, e la PR lo dice.
4. **Box Aggiornamenti** (stessa istanza): screenshot `extra1-aggiornamenti.jpg` della scheda Stato con il box in fondo. «Verifica aggiornamenti» risponde senza errori e aggiorna «Ultimo controllo». Il pulsante «Installa» **non** si preme (D13), anche se comparisse. Nella pagina Plugin si vede «Dashboard | Verifica aggiornamenti» e non c'è più «Check for updates» tra i meta.
5. La console del browser è senza errori nelle schede Visitatori e Stato.

---

## 9. Criteri di accettazione

Ogni criterio va nella checklist della PR, con l'esito.

1. `composer ci` passa: PHPCS, PHPStan livello 6 e PHPUnit, compresi i test nuovi di `BotFilterTest` (sezione 6). Nessuna nuova esclusione globale.
2. `npm run check` passa. La PR riporta il peso di `build/tracker/tracker.js` prima (706 byte) e dopo, e il nuovo è sotto 1.536 byte. `TRACKER_MAX_BYTES` non cambia.
3. `seed.php` seguito da `verify.php` termina con «Tutti i controlli superati», comprese le sezioni 1, 2, 5 e 6 aggiornate e la nuova sezione 13.
4. **Nessuno scarto anti-bot:** in `src/` non c'è più `discard( 'bot' )`, e `BotFilter::is_bot` non esiste (`grep -rn "is_bot\|discard( 'bot' )" src tests` non trova nulla). `tracker.js` non ha più un `return` legato a `navigator.webdriver`.
5. **Controlli invariati:** Do Not Track, firma HMAC, IP esclusi, utenti collegati e limite di 30 visite al minuto per hash funzionano come prima, e si contano come `dnt`, `signature`, `ip`, `user` e `rate` (verify, sezione 5).
6. **Etichette:** la colonna `bot` vale `''` per le visite normali, `webdriver` con `w=1`, `ua-corto` per gli user agent sotto 16 caratteri, e la firma più lunga per i bot dichiarati. Contiene solo valori dell'elenco fisso (verify, sezione 5).
7. **Aggregati:** le visite segnalate entrano in `site_daily`, `object_daily` e `dim_daily` `own`. L'invariante delle sei dimensioni regge (verify, sezione 6). `OwnSource` non è cambiato (`git diff main -- src/Sources/OwnSource.php` vuoto).
8. **Query dell'endpoint:** 2 per una visita normale e per una segnalata, 3 per una 404 o una ricerca valida (verify 5 e 12.7-12.10).
9. **Schema v6:** `Schema::DB_VERSION` vale `'6'`. La migrazione da v5 esegue un solo `ALTER` sotto lock, è idempotente e non parte con il lock occupato. Installazione nuova e migrazione danno lo stesso `SHOW CREATE TABLE` (verify, sezione 2).
10. **Privacy:** nessuna colonna con `ip` o `agent` nel nome, nessun IP né user agent nelle righe (verify, sezioni 1 e 5).
11. **Browser automatico in wp-env:** la prova con Playwright di 8.2 registra `bot = 'webdriver'` con uno user agent da browser. La PR riporta il corpo del beacon e la riga.
12. **Quattro box:** `AudienceDimensions` mostra quattro `section.wp5w-card` («Pubblico (paesi)», «Dispositivi», «Browser», «Sistemi operativi»), senza `Card` riducibile, con un solo `PeriodSwitch` e una sola chiamata a `dimensions` per periodo. I tre screenshot di 8.3 mostrano 4, 2 e 1 colonne. `DimensionList.jsx`, `Campaigns.jsx` e `AudienceQuery.php` non cambiano.
13. **Aggiornamenti:** le tre rotte rispondono con i permessi di R8 (`manage_options` per stato e controllo, `update_plugins` per l'installazione). `install` risponde `409` senza aggiornamenti e non avvia l'upgrader. Il box è in una `Card id="status-updates"` al posto della riga in fondo alla scheda Stato, e lo screenshot di 8.4 è nella PR (verify, sezione 13).
14. **Link:** nella riga del plugin compare «Verifica aggiornamenti» verso `?page=wp5w&tab=status`, e il link «Check for updates» di plugin-update-checker non c'è più.
15. **Testi:** il testo del Confronto non dice più che la raccolta propria esclude i bot (R5). `README.md`, `CLAUDE.md` e `CHANGELOG.md` (0.11.0) sono aggiornati come nella sezione 5, e la roadmap ha la riga negli scostamenti.
16. Nessun file degli updater di woo-customers-price-list, wp-multi-magazzino o wp-semantic-ai è stato copiato, e non ci sono token.
17. Lo zip della release pesa **al massimo 460.000 byte** (0.10.0: 446.786).
18. Con `wp5w_own_since` vuota, le altre schede restituiscono gli stessi numeri (verify, sezioni 4 e 7).
19. Dopo il deploy sul blog sono verdi tutti i passi bloccanti del runbook. Il gate dei tempi (10.7) è entro il +20%, oppure è stato segnalato a Maurizio come `blocked` secondo 10.7.

---

## 10. Runbook di deploy

Blog: `ssh mavida-blog`, cartella `~/domains/mavida.com/public_html/maurizio`, prefisso delle tabelle `maurizio_`, plugin in `public/plugins`, admin su `/app/wp-admin`, log in `/home/mavida/logs/maurizio-debug.log`.

- `exec()` è disabilitato: niente `wp db export` né `wp db import`. I backup si fanno con `mysqldump`, e `MYSQL_PWD` si prende da `wp config get DB_PASSWORD` **senza stamparlo**.
- WP-CLI usa PHP 8.3 di default; il `PATH` del crontab punta a `php74`, quindi nessun comando di questo runbook passa da PHP 7.4.
- `wp5w_hits.ts` è in UTC, `day` è in ora locale (`Europe/Rome`).
- In Git Bash serve `MSYS_NO_PATHCONV=1` per gli argomenti che iniziano con `/`, compresi i `curl` con `u=/...`.
- L'application password dell'utente `maurizio` è in `C:\Users\maurizio.MAVIDA\Claude\credenzials\maurizio-blog\wordpress.md`. Il file contiene anche una password SSH in chiaro: si legge in una variabile e **non si stampa mai**, né l'una né l'altra.
- Python su Windows non legge `/tmp` di Git Bash: i file temporanei vanno nello scratchpad.
- Si evita di fare i passi da 10.1 a 10.4 a cavallo delle 4:00 (ETL notturno).

```bash
cd ~/domains/mavida.com/public_html/maurizio
WP="wp --url=https://maurizio.mavida.com"
D=$($WP eval 'echo wp_date( "Y-m-d" );'); echo "$D"   # giorno del sito, da usare in tutte le query su day
```

### 10.1 Backup e cattura di non regressione

```bash
mkdir -p ~/backups
STAMP=$(date +%F-%H%M)
TABLES=$($WP db tables 'maurizio_wp5w_*' --all-tables --format=csv | tr ',' ' ')
export MYSQL_PWD="$($WP config get DB_PASSWORD)"
mysqldump -h "$($WP config get DB_HOST)" -u "$($WP config get DB_USER)" --single-transaction "$($WP config get DB_NAME)" $TABLES > ~/backups/wp5w-pre-0.11.0-$STAMP.sql
unset MYSQL_PWD
ls -l ~/backups/wp5w-pre-0.11.0-$STAMP.sql            # non vuoto
$WP option list --search='wp5w_*' --format=json > ~/backups/wp5w-options-pre-0.11.0.json
$WP plugin get wp-5w --field=version                  # 0.10.0
$WP option get wp5w_db_version                         # 5
LOG_WP5W=$(grep -c "wp5w" ~/logs/maurizio-debug.log 2>/dev/null || echo 0); echo "$LOG_WP5W"
WPS_FROM=$($WP db query "SELECT MIN(last_counter) FROM maurizio_statistics_visitor" --skip-column-names); echo "$WPS_FROM"
{
  # Intervallo chiuso dello snapshot della fase 1.
  $WP db query "SELECT origin, COUNT(*), SUM(visitors), SUM(views) FROM maurizio_wp5w_site_daily WHERE day BETWEEN '2026-09-14' AND '2026-10-12' GROUP BY origin"
  $WP db query "SELECT origin, COUNT(*), SUM(views), SUM(visitors) FROM maurizio_wp5w_object_daily WHERE day BETWEEN '2026-09-14' AND '2026-10-12' GROUP BY origin"
  for t in entry_daily referrer_daily dim_daily; do
    $WP db query "SELECT '$t', origin, COUNT(*), SUM(visitors) FROM maurizio_wp5w_$t WHERE day BETWEEN '2026-09-14' AND '2026-10-12' GROUP BY origin"
  done
  # Righe wps dell'intera finestra di WP Statistics, giorno per giorno.
  $WP db query "SELECT 'site', day, visitors, views FROM maurizio_wp5w_site_daily WHERE origin = 'wps' AND day >= '$WPS_FROM' ORDER BY day"
  $WP db query "SELECT 'dim', day, dim, SUM(visitors) FROM maurizio_wp5w_dim_daily WHERE origin = 'wps' AND day >= '$WPS_FROM' GROUP BY day, dim"
  # Righe own chiuse (fino a ieri): non devono cambiare con la migrazione.
  $WP db query "SELECT 'own', day, visitors, views FROM maurizio_wp5w_site_daily WHERE origin = 'own' AND day < '$D' ORDER BY day"
  $WP eval '$r = ( new Mavida\FiveW\Query\OverviewQuery() )->get( new Mavida\FiveW\Support\DateRange( "2026-09-14", "2026-10-12" ) ); echo $r["kpis"]["visitors"]["value"], " ", $r["kpis"]["views"]["value"], "\n";'
} > ~/backups/wp5w-nonreg-pre-0.11.0.txt
$WP db query "SELECT reason, n FROM maurizio_wp5w_discards WHERE day = '$D'" > ~/backups/wp5w-discards-pre-0.11.0.txt
cat ~/backups/wp5w-nonreg-pre-0.11.0.txt | head -20
```

**Riferimento fisso** (`scratchpad/snapshot-pre-fase-1.txt`, intervallo 2026-09-14 → 2026-10-12): `site_daily` 29 righe, 3.532 visitatori, 4.659 visualizzazioni; `object_daily` 576 righe, 4.660 visualizzazioni, 4.111 visitatori. Questi due devono comparire **identici** (righe `wps`) nella cattura. `entry_daily` (371), `referrer_daily` (186) e `dim_daily` (1.110) sono solo un riferimento: il ricalcolo del canale AI della 0.10.0 può averne cambiato il numero di righe. Per queste tre tabelle vale la cattura appena presa. Se `site_daily` o `object_daily` differiscono dallo snapshot già prima del deploy, ci si ferma e si avvisa Maurizio: il problema non è di questa iterazione.

### 10.2 Installazione, migrazione e colonna `bot`

```bash
INSTALL_UTC=$(date -u '+%F %T'); echo "$INSTALL_UTC"
$WP plugin install https://github.com/mavidasnc/wp-5w/releases/download/v0.11.0/wp-5w-0.11.0.zip --force
$WP plugin get wp-5w --field=version                  # 0.11.0
$WP eval 'Mavida\FiveW\Database\Schema::maybe_upgrade(); echo get_option( "wp5w_db_version" ), "\n";'   # 6
$WP db query "SHOW COLUMNS FROM maurizio_wp5w_hits LIKE 'bot'"          # varchar(20), NO, default ''
$WP db query "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'maurizio_wp5w_hits' AND ordinal_position = (SELECT ordinal_position + 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'maurizio_wp5w_hits' AND column_name = 'os')"   # bot
$WP db query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'maurizio_wp5w_hits' AND (column_name LIKE '%ip%' OR column_name LIKE '%agent%')"   # 0
$WP db query "SELECT COUNT(*) totale, SUM(bot <> '') segnalate FROM maurizio_wp5w_hits WHERE ts < '$INSTALL_UTC'"   # segnalate = 0
grep -c "wp5w" ~/logs/maurizio-debug.log 2>/dev/null || echo 0   # uguale a $LOG_WP5W
$WP cron event list --fields=hook,next_run_relative,recurrence | grep wp5w   # wp5w_etl_daily, wp5w_own_hourly, wp5w_geo_update: nessun evento nuovo
```

Se la versione non è `6`, si ripete il comando una volta, perché il lock della migrazione può essere occupato da una richiesta del sito. Se resta `5`, si legge l'errore nel log (`grep wp5w ~/logs/maurizio-debug.log | tail`) e si va al rollback (10.10).

### 10.3 Non regressione

Si ripetono le query di 10.1 e si confrontano con `~/backups/wp5w-nonreg-pre-0.11.0.txt`: tutto deve essere **identico**, comprese le righe `own` fino a ieri e il risultato di `OverviewQuery`. Le righe `own` di oggi possono cambiare, perché il passaggio orario lavora. Una differenza nelle righe `wps` o nei giorni `own` chiusi porta al rollback (10.10).

### 10.4 Cache di LiteSpeed e tracker

Il tracker è cambiato, quindi si svuota la cache (TTL pubblico di 7 giorni):

```bash
$WP litespeed-purge all
POST_URL=$($WP post list --post_type=post --post_status=publish --posts_per_page=1 --field=url)
curl -s "$POST_URL" -o /dev/null; curl -sI "$POST_URL" | grep -i x-litespeed-cache   # alla seconda richiesta: hit
curl -s "$POST_URL" | grep -o 'id="wp5w-hit"[^<]*'                                   # blocco presente nella pagina in cache
# LiteSpeed combina i JS: il tracker non ha un tag proprio. Si cerca il codice nuovo nei file combinati della pagina.
for src in $(curl -s "$POST_URL" | grep -o 'src="[^"]*\.js[^"]*"' | sed 's/^src="//; s/"$//' | sort -u); do
  case "$src" in /*) js="https://maurizio.mavida.com$src" ;; *) js="$src" ;; esac   # i percorsi relativi diventano assoluti
  curl -s "$js" | grep -q 'webdriver' && echo "$js" && curl -s "$js" | grep -o '.\{60\}webdriver.\{60\}'
done
# atteso: un file con webdriver seguito dall'impostazione del parametro w (non da un return)
```

### 10.5 Smoke test dell'endpoint

Sul blog si ricavano ID e firma:

```bash
ID=$($WP post list --post_type=post --post_status=publish --posts_per_page=1 --field=ID)
SIG=$($WP eval "echo Mavida\\FiveW\\Front\\Tracker::signature( 'post', $ID );")
echo "$ID $SIG"
$WP db query "SELECT reason, n FROM maurizio_wp5w_discards WHERE day = '$D'"   # valori prima delle prove
```

Dal PC locale, con Git Bash. Ogni prova ha uno user agent diverso, quindi un hash diverso:

```bash
export MSYS_NO_PATHCONV=1
H=https://maurizio.mavida.com/wp-json/wp5w/v1/hit
CHROME="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36"
MOBILE="Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36"
GPT="Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.0; +https://openai.com/gptbot)"
GOOGLE="Googlebot/2.1 (+http://www.google.com/bot.html)"
B="t=post&id=$ID&s=$SIG"
curl -s -o /dev/null -w "normale %{http_code}\n"   -A "$CHROME" -d "$B&u=/wp5w-smoke-test/" $H              # 204, bot ''
curl -s -o /dev/null -w "webdriver %{http_code}\n" -A "$MOBILE" -d "$B&u=/wp5w-smoke-test/&w=1" $H          # 204, bot 'webdriver'
curl -s -o /dev/null -w "w errato %{http_code}\n"  -A "$MOBILE Smoke" -d "$B&u=/wp5w-smoke-test/&w=yes" $H  # 204, bot ''
curl -s -o /dev/null -w "gptbot %{http_code}\n"    -A "$GPT" -d "$B&u=/wp5w-smoke-test/" $H                 # 204, bot 'gptbot'
curl -s -o /dev/null -w "googlebot %{http_code}\n" -A "$GOOGLE" -d "$B&u=/wp5w-smoke-test/" $H              # 204, bot 'bot'
curl -s -o /dev/null -w "ua corto %{http_code}\n"  -A "wp5w-short" -d "$B&u=/wp5w-smoke-test/" $H          # 204, bot 'ua-corto'
curl -s -o /dev/null -w "dnt %{http_code}\n"       -A "$CHROME DNT" -H "DNT: 1" -d "$B&u=/wp5w-smoke-test/" $H   # 204, nessuna riga, dnt +1
curl -s -o /dev/null -w "firma %{http_code}\n"     -A "$CHROME Sig" -d "t=post&id=$ID&s=0000&u=/wp5w-smoke-test/" $H   # 204, nessuna riga, signature +1
date -u '+%T'   # ora dell'ultima richiesta: il gate (10.7) parte almeno 60 secondi dopo
```

Sul blog:

```bash
$WP db query "SELECT id, object_type, object_id, uri, bot, device, browser, os, country FROM maurizio_wp5w_hits WHERE uri = '/wp5w-smoke-test/' ORDER BY id"
# 6 righe, nell'ordine: '', 'webdriver', '', 'gptbot', 'bot', 'ua-corto'
$WP db query "SELECT reason, n FROM maurizio_wp5w_discards WHERE day = '$D'"
# rispetto a prima: dnt +1, signature +1, nessun bot in più
$WP db query "SELECT DISTINCT bot FROM maurizio_wp5w_hits WHERE ts >= '$INSTALL_UTC'"
# solo '', ua-corto, webdriver o firme di BotFilter::SIGNATURES
```

**Presenza negli aggregati:**

```bash
$WP cron event run wp5w_own_hourly
$WP db query "SELECT COUNT(DISTINCT visitor) tutti, COUNT(DISTINCT IF(bot = '', visitor, NULL)) normali FROM maurizio_wp5w_hits WHERE day = '$D'"
$WP db query "SELECT visitors, views FROM maurizio_wp5w_site_daily WHERE origin = 'own' AND day = '$D'"
# visitors di site_daily = tutti (non normali): le visite segnalate sono comprese
$WP db query "SELECT views FROM maurizio_wp5w_object_daily WHERE origin = 'own' AND day = '$D' AND object_type = 'post' AND object_id = $ID"
$WP db query "SELECT COUNT(*) FROM maurizio_wp5w_hits WHERE day = '$D' AND object_type = 'post' AND object_id = $ID"
# stesse visualizzazioni
$WP db query "SELECT dim, SUM(visitors) FROM maurizio_wp5w_dim_daily WHERE origin = 'own' AND day = '$D' AND dim IN ('channel','source','country','device','browser','platform') GROUP BY dim"
# sei somme uguali tra loro e uguali a visitors di site_daily
```

Una differenza in questi tre confronti porta al rollback (10.10).

**Visita vera da Chrome**, se è disponibile claude-in-chrome, in una finestra anonima: si apre `$POST_URL` (`x-litespeed-cache: hit`), il beacon `POST /wp-json/wp5w/v1/hit` risponde `204`, e il suo corpo **non** contiene `w` (in Chrome normale `navigator.webdriver` è falso). La riga nuova ha `bot = ''`. Questa visita resta nei dati e va citata nella nota. Il ramo con `w=1` del tracker si prova solo in wp-env (8.2): qui lo prova il `curl`.

### 10.6 REST degli aggiornamenti e link

Dal PC locale. `WPAUTH` si legge dal file delle credenziali e non si stampa. Le risposte si salvano nello scratchpad e si leggono con Python:

```bash
WPAUTH="maurizio:<application password>"
A=https://maurizio.mavida.com/wp-json/wp5w/v1/admin
SP="<scratchpad>"
curl -s -u "$WPAUTH" -w "\nGET update %{http_code}\n" "$A/update" -o "$SP/upd-get.json"                       # 200
curl -s -u "$WPAUTH" -X POST -w "\ncheck %{http_code}\n" "$A/update/check" -o "$SP/upd-check.json"           # 200
curl -s -o /dev/null -w "GET update senza credenziali %{http_code}\n" "$A/update"                          # 401
curl -s -o /dev/null -w "install senza credenziali %{http_code}\n" -X POST "$A/update/install"             # 401
curl -s -o /dev/null -w "check senza credenziali %{http_code}\n" -X POST "$A/update/check"                 # 401
curl -s -u "$WPAUTH" "$A/status" -o "$SP/status.json" -w "status %{http_code}\n"                              # 200
curl -s -u "$WPAUTH" "$A/audience/dimensions?days=30" -o "$SP/dims.json" -w "dimensions %{http_code}\n"      # 200
```

Si controlla che:

- `upd-get.json` abbia `current` `0.11.0`, `latest` `0.11.0`, `update_available` falso e `releases_url` di GitHub;
- `upd-check.json` abbia `checked_at` entro 60 secondi da adesso;
- `status.json` non abbia `version` né `updates`;
- `dims.json` abbia `dims` con `country`, `device`, `browser` e `platform`.

**`POST /admin/update/install` con le credenziali non si esegue** (D13).

Sul blog:

```bash
$WP eval 'print_r( ( new Mavida\FiveW\Admin\AdminMenu() )->add_dashboard_link( array() ) );'   # Dashboard, poi Verifica aggiornamenti con page=wp5w&tab=status
grep -c "wp5w" ~/logs/maurizio-debug.log 2>/dev/null || echo 0   # ancora uguale a $LOG_WP5W
```

### 10.7 Gate dei tempi dell'endpoint

Questa sezione **sostituisce** la regola di 10.9 della fase 4: per questa iterazione, un gate fuori soglia **non** porta al rollback.

Si usa il metodo di [fase-1.md, 8.7](fase-1.md), dal PC locale, a partire da `scratchpad/gate.sh`. L'orario d'attesa, `ID` e `SIG` del file sono quelli della fase 4: si rigenerano con i valori di 10.5, e l'attesa si porta ad almeno 60 secondi dopo l'ultima richiesta di 10.5. Si fanno 7 coppie alternate: una visita valida all'articolo (`u=/wp5w-smoke-test/`, user agent `$CHROME`, senza `w`) e una `POST` a vuoto a `/wp-json/wp-statistics/v2/hit`. Si confrontano le mediane di `time_total`.

- **Esito:** la mediana di `hit` non deve superare di oltre il **20%** quella di `wps` (ultimo valore: +16,7%).
- **Se si supera il 20%:** si aspettano almeno 60 secondi e si ripete la misura. Se serve, dopo altri 60 secondi la si ripete una seconda volta: al massimo tre misure in tutto.
- **Se tutte e tre restano sopra il 20%:** ci si ferma. Lo stato del deploy è `blocked` e si avvisa Maurizio con le mediane. **Nessun rollback per questo solo motivo**, se tutti gli altri passi sono verdi: il rollback è per gli errori. I passi successivi (10.8 e 10.9) si fanno comunque, perché servono a ripulire le prove.
- **Verifica:** `SELECT COUNT(*) FROM maurizio_wp5w_hits WHERE uri = '/wp5w-smoke-test/'` dà 6 + 7 per ogni misura, e `SELECT n FROM maurizio_wp5w_discards WHERE day = '<D>' AND reason = 'rate'` non è aumentato durante il gate.

### 10.8 Limite di frequenza invariato

Almeno 60 secondi dopo il gate, con uno user agent proprio (quindi un hash diverso da quello del gate):

```bash
for i in $(seq 1 31); do
  curl -s -o /dev/null -A "$CHROME Rate" -d "$B&u=/wp5w-smoke-rate/" $H
done
```

Sul blog: `SELECT COUNT(*) FROM maurizio_wp5w_hits WHERE uri = '/wp5w-smoke-rate/'` dà **30**, e lo scarto `rate` di `$D` è aumentato di **1** rispetto al valore letto prima. Se le 31 richieste non stanno in un minuto, il conteggio è 31 e lo scarto non aumenta: in quel caso si ripete con richieste in parallelo (`&` e `wait`), oppure si annota l'esito nella nota senza bloccare.

### 10.9 Pulizia delle prove

```bash
$WP db query "DELETE FROM maurizio_wp5w_hits WHERE uri IN ('/wp5w-smoke-test/', '/wp5w-smoke-rate/')"
$WP cron event run wp5w_own_hourly
$WP db query "SELECT COUNT(DISTINCT visitor) FROM maurizio_wp5w_hits WHERE day = '$D'"
$WP db query "SELECT visitors FROM maurizio_wp5w_site_daily WHERE origin = 'own' AND day = '$D'"   # uguali
$WP db query "SELECT reason, n FROM maurizio_wp5w_discards WHERE day = '$D'"
```

Gli scarti delle prove (`dnt` +1, `signature` +1, `rate` +1) non si tolgono, come nelle fasi precedenti. Si scrivono nella nota con i valori prima e dopo, perché compariranno nel Confronto di quel giorno.

### 10.10 Rollback

Si fa se un passo bloccante tra 10.2 e 10.5 fallisce, o se 10.9 lascia gli aggregati incoerenti. Non si fa per il solo gate dei tempi (10.7).

```bash
$WP plugin install https://github.com/mavidasnc/wp-5w/releases/download/v0.10.0/wp-5w-0.10.0.zip --force
$WP plugin get wp-5w --field=version                  # 0.10.0
$WP option get wp5w_db_version                         # 5: la 0.10.0 riesegue install(); la colonna bot resta, inutilizzata, con valore predefinito ''
$WP litespeed-purge all                                # le pagine in cache hanno il tracker della 0.11.0
```

Poi si ripetono le query di 10.3 contro la cattura di 10.1. **Solo** se le righe `wps` risultano diverse, si ripristinano le tabelle dal dump:

```bash
export MYSQL_PWD="$($WP config get DB_PASSWORD)"
mysql -h "$($WP config get DB_HOST)" -u "$($WP config get DB_USER)" "$($WP config get DB_NAME)" < ~/backups/wp5w-pre-0.11.0-<STAMP>.sql
unset MYSQL_PWD
```

Il ripristino perde le righe di `wp5w_hits` arrivate dopo il backup, e va scritto nella nota. Le righe già segnalate dalla 0.11.0 restano nei dati, e la 0.10.0 le aggrega come le altre.

### 10.11 Dopo circa un'ora di traffico

```bash
$WP db query "SELECT COUNT(*) visite, COUNT(DISTINCT visitor) visitatori, SUM(bot <> '') segnalate, SUM(bot = 'webdriver') webdriver FROM maurizio_wp5w_hits WHERE ts >= '$INSTALL_UTC'"
$WP db query "SELECT COUNT(*) FROM maurizio_statistics_visitor WHERE first_view >= CONVERT_TZ('$INSTALL_UTC', '+00:00', @@session.time_zone)"
$WP db query "SELECT bot, COUNT(*) FROM maurizio_wp5w_hits WHERE ts >= '$INSTALL_UTC' GROUP BY bot"
$WP db query "SELECT reason, n FROM maurizio_wp5w_discards WHERE day = '$D'"   # nessun bot nuovo
```

- Se le visite sono 0 mentre WP Statistics ha registrato visitatori, si avvisa Maurizio (raccolta ferma dopo la migrazione).
- Un primo segnale sull'ipotesi: con circa 5 visitatori «automatici» all'ora in WP Statistics, dopo un'ora dovrebbero comparire alcune righe `webdriver`. Il giudizio vero arriva con la query di analisi (10.13), dopo qualche giorno.

### 10.12 Nota di consegna

La nota riporta:

- data e ora di installazione (`INSTALL_UTC`);
- gli esiti di 10.2, 10.3 e 10.4, con la riga del tracker combinato;
- le righe degli smoke test (10.5) con le etichette, e gli scarti prima e dopo;
- le mediane del gate (10.7) con la differenza percentuale e il numero di misure, e lo stato `blocked` se è il caso;
- l'esito di 10.8 e 10.11;
- il peso del tracker e dello zip;
- gli eventuali scostamenti da questa specifica, e in caso di rollback lo stato della colonna `bot`;
- il promemoria della query di analisi (10.13), da lanciare dal **terzo giorno pieno** dopo il deploy;
- il promemoria della decisione del 2026-10-28 sul passaggio alla raccolta propria (fase 2).

### 10.13 Query di analisi del traffico segnalato (dopo qualche giorno)

Si lancia dopo almeno 3 giorni pieni di raccolta con la 0.11.0. `FROM` è il primo giorno pieno, cioè quello dopo il deploy; `TO` è ieri:

```bash
FROM=<giorno dopo il deploy>; TO=$($WP eval 'echo wp_date( "Y-m-d", time() - DAY_IN_SECONDS );')
H=maurizio_wp5w_hits

# A. Il confronto che decide: per giorno, WP Statistics contro la raccolta propria in tre modi.
$WP db query "SELECT day, COUNT(DISTINCT visitor) own_tutti, COUNT(DISTINCT IF(bot = '', visitor, NULL)) own_normali, COUNT(DISTINCT IF(bot = 'webdriver', visitor, NULL)) own_webdriver, COUNT(DISTINCT IF(bot NOT IN ('', 'webdriver'), visitor, NULL)) own_altri FROM $H WHERE day BETWEEN '$FROM' AND '$TO' GROUP BY day"
$WP db query "SELECT last_counter day, COUNT(*) wps FROM maurizio_statistics_visitor WHERE last_counter BETWEEN '$FROM' AND '$TO' GROUP BY day"

# B. Per etichetta.
$WP db query "SELECT bot, COUNT(*) visite, COUNT(DISTINCT day, visitor) visitatori FROM $H WHERE day BETWEEN '$FROM' AND '$TO' GROUP BY bot ORDER BY visitatori DESC"

# C. Dispositivo, browser e sistema.
$WP db query "SELECT bot, device, browser, os, COUNT(DISTINCT day, visitor) visitatori FROM $H WHERE day BETWEEN '$FROM' AND '$TO' AND bot <> '' GROUP BY bot, device, browser, os ORDER BY visitatori DESC LIMIT 30"

# D. Paese.
$WP db query "SELECT bot, country, COUNT(DISTINCT day, visitor) visitatori FROM $H WHERE day BETWEEN '$FROM' AND '$TO' AND bot <> '' GROUP BY bot, country ORDER BY visitatori DESC LIMIT 30"

# E. Pagina d'ingresso e canale.
$WP db query "SELECT bot, uri, channel, COUNT(*) ingressi FROM $H WHERE day BETWEEN '$FROM' AND '$TO' AND bot <> '' AND is_entry = 1 GROUP BY bot, uri, channel ORDER BY ingressi DESC LIMIT 30"

# F. Ora del giorno (UTC).
$WP db query "SELECT bot, HOUR(ts) h_utc, COUNT(DISTINCT day, visitor) visitatori FROM $H WHERE day BETWEEN '$FROM' AND '$TO' AND bot <> '' GROUP BY bot, h_utc ORDER BY bot, h_utc"

# G. Visite per visitatore nel giorno (una sola visita = profilo dei 120 di WP Statistics).
$WP db query "SELECT bot, visite, COUNT(*) visitatori FROM ( SELECT bot, day, visitor, COUNT(*) visite FROM $H WHERE day BETWEEN '$FROM' AND '$TO' GROUP BY bot, day, visitor ) t GROUP BY bot, visite ORDER BY bot, visite"

# H. Per confronto, lo stesso profilo in WP Statistics (smartphone, direct, solo la home).
$WP db query "SELECT last_counter day, COUNT(*) FROM maurizio_statistics_visitor WHERE last_counter BETWEEN '$FROM' AND '$TO' AND device = 'smartphone' AND (referred = '' OR referred IS NULL) GROUP BY day"
```

Lettura dei risultati:

| Esito | Significato | Passo successivo |
|---|---|---|
| `own_webdriver` circa 100-130 al giorno, con il profilo di 1 (smartphone, Chrome Mobile, home, `direct`, una visita), e `own_tutti` vicino a `wps` | Ipotesi confermata: sono browser automatizzati che WP Statistics conta. | Box Canali nella Panoramica e dettaglio nella scheda Visitatori con la quota segnalata; decidere con Maurizio se escluderli dagli aggregati, ricalcolando dai dati grezzi. |
| `own_webdriver` vicino a 0 e la differenza con `wps` resta | Quei visitatori non eseguono il tracker (per esempio JS ritardato da LiteSpeed fino a un'interazione, beacon bloccato, o richieste che WP Statistics conta senza JavaScript). | Indagine separata: modalità di tracciamento di WP Statistics, impostazioni JS di LiteSpeed, log di accesso. |
| Molte righe con firme dichiarate (`bot`, `gptbot`, `headless`...) | Crawler che eseguono JavaScript. | Valutare se mostrarli a parte o escluderli, con lo stesso box. |
| `own_tutti` molto sopra `wps` | La raccolta propria conta crawler che WP Statistics scarta. | Normale dalla 0.11.0 (R4); va mostrato nel Confronto come quota segnalata. |

Il dettaglio della versione del browser non c'è, perché la raccolta propria non salva lo user agent.

### 10.14 Stato finale: cosa resta a mano a Maurizio

Questi controlli restano a Maurizio, perché richiedono una sessione di wp-admin (nessun agente ce l'ha) o una sua decisione:

1. **Scheda Visitatori:** i quattro box «Pubblico (paesi)», «Dispositivi», «Browser» e «Sistemi operativi» su una riga, con il cambio di periodo condiviso.
2. **Scheda Stato:** il box «Aggiornamenti» (versione installata 0.11.0, ultimo controllo, «Verifica aggiornamenti»). Poi il Confronto con il testo nuovo, la card «Database dei paesi» e le altre viste rimaste dalle fasi 3 e 4 (10.15 di [fase-4.md](fase-4.md)).
3. **Pagina Plugin:** il link «Verifica aggiornamenti» nella riga di wp-5w, senza «Check for updates».
4. **Prima installazione dall'admin:** alla prossima release (0.11.1 o 0.12.0), «Installa la versione X» dal box. È la prima prova reale del percorso (D13): si controlla che il plugin resti attivo, che la pagina si ricarichi con la versione nuova e che il log sia pulito. Se l'hosting chiedesse le credenziali FTP, l'errore compare nel box e si aggiorna da WP-CLI come oggi.
5. **Search Console:** il backfill va rilanciato ogni 1-2 settimane sugli ultimi 14 giorni, togliendo gli ultimi due giorni provvisori, finché non c'è una sincronizzazione automatica (comandi in `CLAUDE.md`, «Recupero dello storico di Search Console»).
6. **Analisi del traffico segnalato:** la query di 10.13, dal terzo giorno pieno dopo il deploy. Decide il passo successivo (box Canali e dettaglio nella scheda Visitatori).
7. **Decisione del 2026-10-28** sul passaggio alla raccolta propria: con la 0.11.0 i numeri propri comprendono il traffico segnalato, quindi il confronto va letto con la tabella di 10.13.

---

## 11. Fuori da questa iterazione

- Box Canali della Panoramica e dettaglio della scheda Visitatori con la quota del traffico segnalato: passo successivo, dopo l'analisi di 10.13.
- Esclusione del traffico segnalato dagli aggregati, o filtro nell'interfaccia.
- Nuove firme di bot (per esempio `googlebot` o `bingbot` per distinguerli da `bot`).
- Changelog nel box degli aggiornamenti (D10) e token per repository privati.
- Rimozione dell'opzione `external_updates-wp-5w` alla disinstallazione (D14): è un residuo della libreria presente dalla 0.1, segnalato e non corretto.
- Ottimizzazione dell'endpoint con `SHORTINIT` (rimandata dagli scostamenti della roadmap).
