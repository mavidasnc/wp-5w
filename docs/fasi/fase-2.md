# Fase 2: confronto in parallelo con WP Statistics

Questa è la specifica di implementazione della fase 2 di [roadmap-sostituzione-wp-statistics.md](../roadmap-sostituzione-wp-statistics.md). Le sezioni «Decisioni già prese», «Principio di progetto», «Processo di sviluppo» e «Scostamenti» della roadmap valgono anche qui, e così le decisioni di [fase-1.md](fase-1.md). Se questo documento è più preciso, prevale.

- **Branch:** `fase-2-confronto`, creato da `main` (commit `c4a2c2e`).
- **Versione da rilasciare:** `0.8.0`. Lo schema del database resta alla versione `4`.
- **Ciclo:** Sonnet implementa su questo branch e apre la PR verso `main`, con la checklist dei criteri di accettazione della sezione 8. Segue la review di Opus 5.5, che rilancia `composer ci`, `npm run check` e `verify.php`. Sono ammessi al massimo 3 giri di correzioni. Poi squash merge, release, deploy secondo il runbook (sezione 9) e nota di consegna.
- **Obiettivo:** nella scheda Stato, una sezione «Confronto» che mostri per quanto i numeri della raccolta propria si discostano da quelli di WP Statistics, e dove nasce la differenza. Deve bastare per decidere, intorno al 2026-10-28, se passare alla fase 5, correggere i filtri o prolungare il confronto. Le altre schede non cambiano nessun numero, perché `wp5w_own_since` resta vuota.

---

## 1. Dati reali letti sul blog prima di scrivere la specifica

Letture fatte in sola lettura il 2026-10-14 tra le 18:44 e le 18:55 UTC, con `ssh mavida-blog` e `wp --url=https://maurizio.mavida.com db query`.

### 1.1 Raccolta propria dal deploy della 0.7.0 (18:40 UTC)

| Dato | Valore |
|---|---|
| `wp5w_hits` | 1 riga (2026-10-14 18:39:41 UTC, `/2026/10/13/plugin-prenotazioni-wordpress/`), 1 visitatore |
| `wp5w_discards` | `bot` 1, `user` 1 (entrambi dalle prove del deploy) |
| WP Statistics nello stesso intervallo | 1 visitatore nuovo (18:44:57 UTC, Chrome Mobile, Android, IT, diretto, 1 visualizzazione), che la raccolta propria non ha né registrato né scartato |
| Pagine in cache | home (`miss`), articolo (`hit`) e `/blog/`: tutte hanno il blocco `#wp5w-hit` e il tracker di WP Statistics |

**Controllo 8.9 della fase 1: dati insufficienti.** Un quarto d'ora di traffico, con una sola visita registrata, non dice nulla su `REMOTE_ADDR` né sullo scarto `rate`. Il controllo diventa il **primo passo del runbook** (9.1) e il **passo 0 dell'implementazione** (sezione 2). Se in uno dei due momenti mostra il problema, la correzione già descritta nell'Appendice A entra in questa fase.

Ci sono due cose da sapere prima di leggere i numeri:

- **WP Statistics non legge l'IP come la raccolta propria.** Ha `ip_method = sequential`, che prende `HTTP_X_FORWARDED_FOR` prima di `REMOTE_ADDR` (`class-wp-statistics-ip.php`, riga 32). La raccolta propria legge solo `REMOTE_ADDR` (D6 della fase 1). Per questo il fatto che WP Statistics veda 160 IP distinti al giorno **non prova** che `REMOTE_ADDR` sia l'IP del client. E se un client falsifica `X-Forwarded-For`, WP Statistics lo conta come un visitatore nuovo, mentre la raccolta propria no.
- **La visita delle 18:44:57 senza traccia propria** è un solo caso e non permette conclusioni. Il passo 9.1 confronta comunque visita per visita le ore successive al deploy, per capire se manca qualcosa in modo sistematico.

### 1.2 Il raddoppio dei visitatori di WP Statistics

I visitatori `wps` in `site_daily` passano da circa 55 al giorno (1-22 settembre) a circa 150 (dal 23 settembre in poi). Nello stesso momento le visualizzazioni per visitatore scendono da circa 1,6 a 1,1-1,3. Confrontando `statistics_visitor` tra il 1-22 settembre («prima») e il 23 settembre-13 ottobre («dopo»), le differenze si concentrano in un gruppo preciso:

| Caratteristica | Prima | Dopo |
|---|---|---|
| Canale `direct` | 643 | 2.743 |
| Canale `search` | 382 | 344 |
| Una sola visualizzazione (`hits = 1`) | 638 | 2.663 |
| Pagina d'ingresso `/` (home) | 490 | 2.479 |
| Chrome Mobile + Chrome Mobile iOS | 653 | 2.722 |
| Paesi: US / GB / NL / IN / FR | 153 / 12 / 10 / 8 / 10 | 1.156 / 226 / 214 / 163 / 149 |
| Italia | 511 | 614 |

Il gruppo «diretto, una sola visualizzazione, Chrome Mobile» vale 22-43 visitatori al giorno fino al 22 settembre e **100-125 al giorno dal 23 settembre**. Inoltre è **piatto sulle 24 ore**: dal 23 settembre al 13 ottobre conta 91-108 visitatori per ogni ora del giorno, mentre il traffico umano segue un ciclo giorno e notte. Sono quasi certamente visite automatiche, che eseguono JavaScript e girano su IP residenziali sparsi per il mondo. La ricerca da Google (il traffico che conta) è stabile.

**Scostamento dalla roadmap.** Il confronto degli user agent previsto dalla roadmap («gli user agent scartati contro quelli contati da WP Statistics») non si può fare da nessuna delle due parti. WP Statistics ha `store_ua` spento e `UAString` è vuoto in tutte le 4.355 righe da settembre, mentre la raccolta propria non salva lo user agent per scelta (privacy). La sezione 4 (R5) propone al suo posto un confronto basato su dati che entrambe le origini hanno già, senza salvare nulla di nuovo.

### 1.3 Contenuti e chiavi

La home ha gli stessi identificativi nelle due origini: `home` con ID 4 per `/`, e `home` con ID 4862 per `/blog/` (`page_on_front = 4`, `page_for_posts = 4862`). Il passo 9.6 lo verifica sul primo giorno completo per tutti i contenuti.

---

## 2. Passo 0 dell'implementazione: controllo di `REMOTE_ADDR`

Prima di scrivere codice, Sonnet esegue sul blog le query di 9.1 (sono di sola lettura) e applica la tabella di decisione di 9.1:

- **esito «ok» o «dati insufficienti»:** si implementa questa specifica senza l'Appendice A, e l'esito va scritto nella PR;
- **esito «proxy sospetto»:** ci si ferma e si avvisa Maurizio. La diagnosi di A.1 richiede un file temporaneo sul server, quindi il suo consenso, che il flusso automatico non può dare. Se la diagnosi conferma il proxy, l'Appendice A entra nella stessa PR, con i suoi test e controlli;
- **esito «raccolta ferma o parziale»:** ci si ferma e si avvisa Maurizio, perché il confronto non avrebbe senso finché il tracker non invia le visite (beacon, JS combinato da LiteSpeed, cache);
- **esito «sorgente pesante»:** non si corregge nulla, perché è un bot e non un proxy. Il dato si riporta nella PR e nella nota di consegna.

---

## 3. Decisioni prese in questa specifica

La roadmap lascia aperte alcune scelte. Ecco come le risolve questa specifica, con due righe di motivazione per ciascuna.

| # | Decisione | Motivazione |
|---|---|---|
| E1 | Nessuna tabella nuova, nessuna migrazione e nessuna scrittura nuova. Il confronto legge `site_daily`, `object_daily`, `entry_daily`, `dim_daily` e `wp5w_discards`. `HitController`, `Tracker` e `OwnSource` non cambiano, salvo l'Appendice A. | Gli aggregati hanno già entrambe le origini nella chiave primaria, e gli scarti sono già contati per giorno e motivo. Il requisito (d) vieta tabelle e scritture non necessarie. |
| E2 | Il confronto sta in una classe nuova, `Query\CompareQuery`, che legge **di proposito** le due origini e quindi non usa `Origin::where()`. È l'unica eccezione alla regola dell'origine, ed è scritta in `CLAUDE.md` e in un commento della classe. | Confrontare `wps` con `own` è il suo scopo. Tenerla separata lascia valido il `grep` di review per tutte le altre classi `Query`. |
| E3 | Le regole del confronto (finestra dei giorni, soglia, quote, classifica) stanno in una classe pura, `Support\Comparison`, coperta da PHPUnit. `CompareQuery` fa solo le letture SQL. | È lo schema già usato per `Trend`, `Origin` e `Visit`: la logica si prova senza database. |
| E4 | **Finestra:** dal giorno dopo il primo giorno con righe `own` (oggi 2026-10-15) fino a ieri, e comunque non oltre il giorno prima dell'ultimo ETL (`Importer::last_state()`, anche se ha avuto errori) né oltre il suo `to`; un ETL con errori si segnala con `etl_ok` falso, senza togliere giorni. Contano solo i giorni con una riga in `site_daily` per **entrambe** le origini (R1). | Il primo giorno `own` è sempre parziale (la raccolta parte a metà giornata) e oggi è in corso. La riga `wps` di ieri diventa completa solo con l'ETL delle 4:00. Così si confrontano giorni interi con interi, come chiede il requisito (b). |
| E5 | La copertura elenca **ogni giorno escluso con il motivo**: `partial`, `today`, `etl_pending`, `no_wps`, `no_own`. | Chi guarda deve capire subito perché ieri non c'è ancora (prima delle 4:00) o perché manca un giorno, senza leggere il codice. |
| E6 | **Differenza** = `(own − wps) / wps × 100`, arrotondata a una cifra con `Metrics::change( own, wps )`, e `null` se `wps` vale 0. Un giorno è «da spiegare» se la differenza dei visitatori o delle visualizzazioni supera il **15%** in valore assoluto. | La roadmap dice che una differenza stabile entro il 10-15% è normale. Si usa il limite alto per non segnalare come anomali i giorni tranquilli. |
| E7 | **Quota di uno scarto** = `n / ( visualizzazioni own + scarti totali ) × 100`, sugli stessi giorni completi. Non si divide mai per i visitatori. | Uno scarto è una richiesta, non un visitatore: il denominatore giusto sono tutte le richieste arrivate all'endpoint. |
| E8 | **Contenuti con la differenza più grande:** si sommano le visualizzazioni di `object_daily` per `(object_type, object_id)` sui giorni completi, si tengono i contenuti con almeno **10** visualizzazioni in una delle due origini e si ordinano per differenza **assoluta**, al massimo 10. | Ordinare per percentuale porterebbe in cima pagine con 1 visualizzazione contro 3. Le visualizzazioni sono una riga per richiesta in entrambe le raccolte; i visitatori per contenuto di WP Statistics passano invece dalle relazioni e sarebbero meno confrontabili. |
| E9 | **Gruppo sospetto:** per ogni giorno, gli ingressi diretti sulla home (`entry_daily` con `object_type = 'home'` e `channel = 'direct'`) nelle due origini, con la quota sui visitatori del giorno. | È il gruppo del raddoppio (1.2) e si ricava da aggregati che entrambe le origini hanno già. Non serve nessuno user agent (R5). |
| E10 | Il **profilo orario** non entra nell'interfaccia: è una query del runbook (9.8), una tantum. | `wp5w_hits.ts` è in UTC e il 2026-10-25 cambia l'ora legale dentro la finestra. Per vedere se un gruppo è piatto sulle 24 ore il fuso non conta, ma un grafico orario nell'interfaccia sarebbe fuorviante. |
| E11 | Rotta `GET /wp5w/v1/admin/compare`, senza parametri, con `check_admin_permission`: la finestra è calcolata dal server. | La finestra dipende da dati del server (primo giorno `own`, ultimo ETL), e non c'è nulla che l'utente debba scegliere. |
| E12 | Nella scheda Stato la sezione Confronto sta **in cima**, prima di «Sorgenti», e si vede finché c'è almeno un giorno con righe `own`. Se non ce ne sono, al suo posto compare una sola riga di testo. | Nelle due settimane del confronto è la ragione per aprire la scheda. Dopo la fase 5, senza più righe `wps` nuove, mostrerà «nessun giorno completo con entrambe le origini». |

---

## 4. Regole di business

### R1. Finestra e copertura (`Support\Comparison::plan`)

Firma:

```php
public static function plan( ?string $own_first_day, ?array $etl, string $today, array $wps_days, array $own_days ): array
```

- `$own_first_day` è `Settings::own_first_day()`.
- `$etl` vale `null`, oppure `array{ day: string, to: string }` con `day = wp_date( 'Y-m-d', $last['started'] )` e `to = $last['to']` di `Importer::last_state()`.
- `$wps_days` e `$own_days` sono gli elenchi dei giorni `Y-m-d` che hanno una riga `site_daily` per quell'origine, da `$own_first_day` in poi.

Risultato: `array{ from: ?string, to: ?string, days: list<string>, excluded: list<array{day: string, reason: string}> }`.

1. Se `$own_first_day` è `null`, si restituiscono `from` e `to` nulli, `days` ed `excluded` vuoti.
2. Si aggiunge l'esclusione `{ $own_first_day, 'partial' }`, a meno che `$own_first_day` non coincida con `$today`.
3. Si esclude `{ $today, 'today' }`.
4. `$last = min( ieri, $etl['day'] − 1 giorno, $etl['to'] )`. Senza `$etl`, `$last = null`.
5. `from = $own_first_day + 1 giorno`, `to = $last`. Se `$last` è `null` oppure `from > to`, la finestra è vuota (`from` e `to` nulli).
6. I giorni da `max( from, $last + 1 )` fino a ieri hanno l'esclusione `etl_pending` (se `$last` è `null`, tutti quelli da `from` a ieri).
7. Ogni giorno da `from` a `to` è **completo** se sta in entrambi gli elenchi. Altrimenti si esclude con `no_wps` (se manca `wps`, anche quando mancano tutte e due) oppure con `no_own`.
8. `days` è in ordine crescente; `excluded` è ordinato per giorno.

### R2. Righe del confronto (`CompareQuery::get`)

Le letture riguardano solo i giorni di `days` (`IN` con un segnaposto per giorno, `$wpdb->prepare`) e le tabelle `wp5w_*`, mai quelle di WP Statistics. L'endpoint restituisce:

```json
{
  "available": true,
  "threshold": 15,
  "coverage": {
    "from": "2026-10-15", "to": "2026-10-27", "days": 13,
    "own_first_day": "2026-10-14", "etl_day": "2026-10-28", "etl_ok": true,
    "excluded": [ { "day": "2026-10-14", "reason": "partial" }, { "day": "2026-10-28", "reason": "today" } ]
  },
  "days": [
    { "day": "2026-10-15",
      "wps": { "visitors": 150, "views": 200 }, "own": { "visitors": 60, "views": 110 },
      "diff": { "visitors": -60.0, "views": -45.0 }, "flagged": true,
      "home_direct": { "wps": 110, "own": 12 },
      "discards": { "bot": 3, "rate": 0 } }
  ],
  "totals": {
    "wps": { "visitors": 0, "views": 0 }, "own": { "visitors": 0, "views": 0 },
    "diff": { "visitors": null, "views": null },
    "views_per_visitor": { "wps": null, "own": null }
  },
  "home_direct": { "wps": 0, "own": 0, "diff": null, "share_wps": null, "share_own": null },
  "channels": [ { "channel": "direct", "wps": 0, "own": 0, "diff": null } ],
  "discards": { "total": 0, "reasons": { "bot": { "n": 0, "share": null } } },
  "top": [ { "type": "post", "id": 7785, "title": "…", "url": "…", "wps_views": 0, "own_views": 0, "diff_abs": 0, "diff": null } ]
}
```

- **`available`:** vale `false` se non ci sono righe `own` (`own_first_day` nullo). In quel caso la risposta contiene solo `available` e `coverage` con i campi nulli o vuoti. Se invece ci sono righe `own` ma `days` è vuoto, `available` vale `true` e tutti gli elenchi sono vuoti.
- **`days`:** visitatori e visualizzazioni per origine da `site_daily`, `diff` secondo E6, `flagged` secondo `Comparison::flagged()`, `home_direct` da `entry_daily` (somma di `visitors` con `object_type = 'home' AND channel = 'direct'`, per origine) e `discards` da `wp5w_discards` (solo i motivi presenti).
- **`totals`:** somme su `days`. `views_per_visitor` è uguale a `views / visitors` arrotondato a 2 cifre, oppure `null` se `visitors` vale 0.
- **`home_direct`:** somme su `days`. `share_*` è la quota sui visitatori dell'origine (una cifra decimale), oppure `null` se i visitatori sono 0.
- **`channels`:** `dim_daily` con `dim = 'channel'`, somma di `visitors` per `value` e per origine, ordinati per `max( wps, own )` decrescente e poi per nome. `diff` segue E6.
- **`discards`:** `Comparison::discard_shares( $per_reason, $own_views )` (E7). I motivi sono quelli di D23 della fase 1: `bot`, `user`, `dnt`, `rate`, `signature`, `ip`. Quelli senza scarti compaiono con `n = 0`.
- **`top`:** `Comparison::top_differences()` (E8). Titolo e URL si ottengono come in `OverviewQuery::top()`: con `get_the_title()` e `get_permalink()` se l'ID è maggiore di 0, altrimenti `home_url( uri )` (con `MIN(uri)` letto da `object_daily`).

### R3. Classe pura `Support\Comparison`

| Metodo | Regola |
|---|---|
| `plan(...)` | R1 |
| `THRESHOLD = 15.0` | E6 |
| `flagged( ?float $visitors_diff, ?float $views_diff ): bool` | Vero se una delle due differenze non nulle supera `THRESHOLD` in valore assoluto (esattamente 15,0 non è segnalato). |
| `discard_shares( array<string,int> $per_reason, int $own_views ): array{total:int, reasons: array<string, array{n:int, share:?float}>}` | Tutti i motivi di `REASONS`, in quest'ordine: `bot`, `user`, `dnt`, `rate`, `signature`, `ip`. Gli eventuali motivi sconosciuti vanno in coda. `share = round( n / ( own_views + total ) × 100, 1 )`, oppure `null` se il denominatore è 0. |
| `MIN_VIEWS = 10`, `TOP = 10` | E8 |
| `top_differences( array<int, array{type:string, id:int, uri:string, wps:int, own:int}> $rows ): array` | Tiene le righe con `max( wps, own ) >= MIN_VIEWS` e aggiunge `diff_abs = abs( own − wps )` e `diff = Metrics::change( own, wps )`. Ordina per `diff_abs` decrescente, poi per `type` e `id` crescenti (ordine stabile), e restituisce al massimo `TOP` righe. |

### R4. Interfaccia (`assets/admin/components/Compare.jsx`)

La sezione si mostra in `StatusPage.jsx` come primo elemento, prima della card «Sorgenti». Il componente carica da sé `/admin/compare`, mostra `Spinner` durante il caricamento e un `Notice` di errore, e usa `Card`, `formatDay` e `formatNumber` come le altre schede. Le card sono tre, tutte riducibili:

1. **«Confronto con WP Statistics»** (`id="status-compare"`):
   - Riga di copertura, sempre visibile, per esempio: «13 giorni completi confrontati, dal 15/10/2026 al 27/10/2026. Esclusi: 14/10 (primo giorno della raccolta propria, parziale); 28/10 (oggi, in corso).» Le etichette dei motivi sono: `partial` «primo giorno della raccolta propria, parziale»; `today` «oggi, in corso»; `etl_pending` «in attesa dell'import notturno delle 4:00»; `no_wps` «manca WP Statistics»; `no_own` «manca la raccolta propria». Se `etl_ok` è falso, si aggiunge «L'ultimo import ha avuto errori: vedi la card Import.»
   - Testo d'aiuto: «La raccolta propria conta un po' meno, perché esclude bot e amministratori. Una differenza stabile entro il 15% è normale; i giorni oltre la soglia sono evidenziati.»
   - Tabella dei giorni con le colonne Giorno, Visitatori WP Statistics, Visitatori propri, Differenza, Visualizzazioni WP Statistics, Visualizzazioni proprie, Differenza. Le righe con `flagged` hanno la classe `is-flagged`. Il `tfoot` contiene i totali e una riga «Visualizzazioni per visitatore».
   - Se `days` è vuoto, si mostra la sola riga di copertura con «Nessun giorno completo con entrambe le origini.»
2. **«Dove nasce la differenza»** (`id="status-compare-why"`):
   - **Ingressi diretti sulla home:** totali `wps` e `own`, con differenza e quota sui visitatori, e una mini-tabella per giorno. Testo d'aiuto: «Dal 23 settembre WP Statistics conta circa 110 visitatori in più al giorno: diretti, solo home, una visualizzazione, distribuiti sulle 24 ore. Se qui la raccolta propria ne conta molti meno, la differenza è traffico automatico che WP Statistics accetta.»
   - **Canali:** tabella con le colonne Canale, WP Statistics, Propri e Differenza.
   - **Scarti della raccolta propria:** tabella con le colonne Motivo, Richieste e Quota. Le etichette sono «Bot e crawler», «Utenti collegati», «Do Not Track», «Troppe visite al minuto», «Firma non valida» e «IP esclusi». Sotto, il totale e la nota «Quota sulle richieste arrivate all'endpoint (visite registrate più scarti)».
3. **«Contenuti con la differenza più grande»** (`id="status-compare-top"`): tabella con le colonne Contenuto (link), WP Statistics, Propri, Differenza assoluta e Differenza %, e una nota con la soglia minima di 10 visualizzazioni.

In `admin.scss` si aggiunge solo lo stile di `.is-flagged`, con lo sfondo giallo chiaro già usato per gli avvisi (o una variabile esistente). La differenza si mostra con il segno (`+4,2%`, `−37,5%`), oppure `–` se è nulla. Nessun grafico: con 2-4 settimane di dati bastano le tabelle.

### R5. Raddoppio di ottobre: confronto onesto senza user agent

Non si salva nessun dato nuovo. Il confronto mette accanto tre segnali che entrambe le origini hanno già (E9, E7) e uno solo della raccolta propria, che resta nel runbook (E10). Ecco cosa mostrerebbe ciascuna ipotesi:

| Ipotesi | Ingressi diretti sulla home | Scarti | Profilo orario di `wp5w_hits` (9.8) |
|---|---|---|---|
| **A.** Bot con JavaScript, che passa i filtri di entrambe le raccolte | `own` ≈ `wps` (circa 100-120 al giorno) | Nulla di anomalo | Una componente piatta di circa 4-5 visitatori l'ora, anche di notte |
| **B.** Bot che il filtro della raccolta propria riconosce (UA o `navigator.webdriver`) | `own` molto sotto `wps` | `bot` circa 100 al giorno (UA), oppure nessuno scarto, perché con `webdriver` il tracker non invia nulla | Nessuna componente piatta |
| **C.** Richieste che gonfiano solo WP Statistics (per esempio un `X-Forwarded-For` falso, che WP Statistics legge per primo) | `own` molto sotto `wps` | Possibile crescita di `rate` (stesso `REMOTE_ADDR` e stesso UA) | Pochi hash con molte visualizzazioni (9.1, query dei 5 hash più pesanti) |
| **D.** Visite che il tracker proprio non invia o perde (JS combinato da LiteSpeed, blocchi) | `own` sotto `wps` anche negli altri canali, non solo in `direct` | Nulla di anomalo | Profilo umano, ma ridotto anche su `search` |

B e D si distinguono guardando i canali: in B la differenza è tutta in `direct` sulla home, mentre in D compare anche in `search`. La specifica non sceglie un'ipotesi. La decisione si prende alla fine del confronto, sui dati (vedi le domande aperte).

---

## 5. File da creare o modificare

### Da creare

| File | Contenuto |
|---|---|
| `src/Support/Comparison.php` | Classe pura senza il guard ABSPATH, come le altre di `Support/`, con le costanti `THRESHOLD`, `MIN_VIEWS`, `TOP` e `REASONS`, e i metodi `plan()`, `flagged()`, `discard_shares()` e `top_differences()` (R1, R3). |
| `src/Query/CompareQuery.php` | `get(): array` (R2), con il guard ABSPATH e lo stesso `phpcs:disable` delle altre classi `Query`. Un commento in testa spiega che è l'unica classe che legge le due origini senza `Origin::where()` (E2). |
| `assets/admin/components/Compare.jsx` | Le tre card di R4. |
| `tests/Unit/ComparisonTest.php` | Sezione 6. |

### Da modificare

| File | Modifica |
|---|---|
| `src/REST/AdminController.php` | Nuovo parametro del costruttore, `CompareQuery $compare`, e rotta `GET /admin/compare`, con `check_admin_permission` e `respond()` (E11). |
| `src/Plugin.php` | Passa `new CompareQuery()` ad `AdminController`. |
| `assets/admin/pages/StatusPage.jsx` | Mostra `<Compare />` come primo elemento (E12). Il resto non cambia. |
| `assets/admin/styles/admin.scss` | Classe `.is-flagged` per le righe della tabella dei giorni. |
| `tests/fixtures/verify.php` | Nuova sezione 10 «Confronto», prima della pulizia finale, che diventa la sezione 11 (sezione 7). |
| `CLAUDE.md` | In «Struttura», `CompareQuery` e `Support\Comparison`. In «Punti da ricordare», l'eccezione alla regola dell'origine per `CompareQuery` (anche nella frase del `grep`), la regola della finestra (primo giorno `own` escluso, ieri solo dopo l'ETL) e il fatto che `statistics_visitor.UAString` è vuoto sul blog (`store_ua` spento). |
| `CHANGELOG.md` | Voce `0.8.0`. La versione la aggiorna `npm run release -- 0.8.0`, non a mano. |

Restano **invariati** `src/Database/Schema.php` (`DB_VERSION` resta `4`), `src/REST/HitController.php`, `src/Front/Tracker.php`, `assets/tracker/tracker.js`, `src/Sources/*`, `uninstall.php` e `src/Deactivator.php`: la fase non aggiunge tabelle, opzioni né cron. Fa eccezione l'Appendice A, che tocca solo `HitController`, `Visit` e i test.

---

## 6. Test PHPUnit da aggiungere (`tests/Unit/ComparisonTest.php`)

| Gruppo | Casi minimi |
|---|---|
| `plan()` | `own_first_day` nullo: tutto vuoto. Primo giorno = oggi: nessun `partial`, finestra vuota, esclusione `today`. Caso tipico (primo giorno 14/10, oggi 28/10, ETL del 28/10 con `to` = 28/10, entrambe le origini dal 14 al 28): `from` 15/10, `to` 27/10, 13 giorni, esclusi 14/10 `partial` e 28/10 `today`. ETL non ancora passato (ETL del 27/10, oggi 28/10): `to` 26/10, 27/10 `etl_pending`. ETL con `to` più vecchio (`to` = 20/10): i giorni dal 21/10 al 27/10 sono `etl_pending`. Senza ETL: finestra vuota, tutti i giorni da `from` a ieri `etl_pending`. Giorno senza `wps` e giorno senza `own` dentro la finestra: esclusi con `no_wps` e `no_own`, e assenti da `days`. Ordine crescente di `days` ed `excluded`. Passaggio all'ora legale (finestra a cavallo del 2026-10-25): nessun giorno saltato o doppio. |
| `flagged()` | (−15,0, null) dà falso; (−15,1, 0) vero; (null, 16) vero; (null, null) falso; (+20, −3) vero. |
| `discard_shares()` | Tutti i 6 motivi presenti e in ordine anche con un input vuoto. `bot` 5 e `rate` 1 con 94 visualizzazioni: `total` 6, quota di `bot` 5,0 e di `rate` 1,0. Denominatore 0: `share` nullo. Un motivo sconosciuto finisce in coda. |
| `top_differences()` | Una riga sotto `MIN_VIEWS` (9 contro 0) viene esclusa; 10 contro 0 viene tenuta. Ordine per differenza assoluta e non percentuale: (wps 200, own 150) precede (wps 12, own 1). Parità: ordine per tipo e ID. Al massimo 10 righe con 15 in ingresso. `diff` nullo con `wps` = 0. |

Se si applica l'Appendice A, si aggiungono anche i casi di A.3 a `VisitTest`.

`composer ci` (PHPCS, PHPStan livello 6 e PHPUnit) deve passare senza nuove esclusioni globali.

---

## 7. Controlli da aggiungere a `tests/fixtures/verify.php`

Nuova sezione **10. Confronto**, dopo il controllo del cron e prima della pulizia finale (che diventa la sezione 11). Si usano `$check`, `$rest_call` e `$admin_user` già presenti. `D` è il giorno di oggi (`wp_date`), `D-n` il giorno di n giorni prima.

1. **Preparazione.** Si salva `wp5w_etl_state` e si cancellano tutte le righe `own` delle cinque tabelle e tutto il contenuto di `wp5w_discards` (le visite simulate delle sezioni 5-9 non servono più). Poi si inseriscono a mano righe `own` note:
   - `site_daily`: `D-4` (1 visitatore, 1 visualizzazione), `D-3` (40 e 60), `D-2` (50 e 70), nessuna riga `own` in `D-1`;
   - `object_daily` su `D-3` e `D-2`: il primo articolo del seed con 30 visualizzazioni `own` in totale, e un contenuto con 5 visualizzazioni `own` e 0 `wps` (sotto la soglia);
   - `entry_daily`: `home`, ID 0, `direct`, 4 visitatori in `D-3`;
   - `dim_daily`: `channel` `direct` 30 e `search` 10 in `D-3`;
   - `wp5w_discards`: `bot` 100 in `D-4` (giorno parziale, da escludere), e in `D-3` `bot` 5 e `rate` 1.

   Le righe `wps` del seed per `D-4 ... D-1` devono esistere: se il seed non le ha, si inseriscono con valori noti. `wp5w_etl_state` si imposta con `started = time()` e `to = D`.
2. **Permessi:** `GET /wp5w/v1/admin/compare` senza utente risponde 401 o 403; con l'amministratore risponde 200, con l'header `Cache-Control: no-store, max-age=0`.
3. **Copertura:** `available` vero; `coverage.from` = `D-3`, `coverage.to` = `D-1`; `days` = `[D-3, D-2]`; `excluded` contiene `{D-4, partial}`, `{D-1, no_own}` e `{D, today}`.
4. **Giorni:** per `D-3` e `D-2`, `wps` coincide con la riga `wps` di `site_daily` e `own` con i valori inseriti; `diff.visitors` è uguale a `Metrics::change( own, wps )`; `flagged` è coerente con `Comparison::flagged()`.
5. **Totali:** `totals.own` vale 90 visitatori e 130 visualizzazioni (senza `D-4`).
6. **Scarti:** `discards.reasons.bot.n` vale 5, non 105; `rate.n` vale 1; la quota di `bot` è `round( 5 / ( 130 + 6 ) × 100, 1 )`.
7. **Gruppo sospetto e canali:** `home_direct.own` vale 4; `channels` contiene `direct` con `own = 30`.
8. **Classifica:** il primo articolo del seed è in `top` con `own_views = 30`; il contenuto con 5 visualizzazioni non c'è; `top` ha al massimo 10 righe ed è ordinata per `diff_abs` decrescente.
9. **ETL non ancora passato:** con `started` di ieri a mezzogiorno, `coverage.to` vale `D-2` e `D-1` compare in `excluded` come `etl_pending` (`no_own` non si controlla più, perché `D-1` è fuori finestra).
10. **Nessuna scrittura:** con `SAVEQUERIES` (come nella sezione 5), la `GET` non esegue nessuna `INSERT`, `UPDATE`, `DELETE` o `REPLACE`.
11. **Interfaccia invariata:** l'istantanea JSON della sezione 4 (con `wp5w_own_since` vuota) resta identica anche con queste righe `own`.
12. **Nessun dato own:** cancellate le righe `own`, la `GET` risponde con `available` falso.
13. **Ripristino:** si rimettono `wp5w_etl_state` e i dati come prima della sezione, poi segue la pulizia finale.

Se si applica l'Appendice A, si aggiungono anche i controlli di A.4.

---

## 8. Criteri di accettazione

Ogni criterio va nella checklist della PR, con l'esito.

1. Il passo 0 (sezione 2) è stato eseguito: la PR riporta i numeri delle query di 9.1 e l'esito della tabella di decisione. Con l'esito «proxy sospetto» o «raccolta ferma o parziale», Maurizio è stato avvisato prima di proseguire, e l'Appendice A è implementata con i suoi test se la diagnosi di A.1 ha confermato il proxy.
2. `composer ci` passa: PHPCS, PHPStan livello 6 e PHPUnit, compreso `ComparisonTest`.
3. `npm run check` passa (compreso il limite di 1.536 byte del tracker), e `git diff main -- assets/tracker webpack.config.js` è vuoto: il tracker non cambia.
4. `seed.php` seguito da `verify.php` termina con «Tutti i controlli superati», compresi tutti i controlli della sezione 7.
5. `GET /wp5w/v1/admin/compare` risponde 200 solo agli amministratori (401 o 403 agli altri), con `Cache-Control: no-store`, e con la struttura di R2.
6. La finestra comprende solo giorni completi con entrambe le origini: il primo giorno `own`, oggi e i giorni senza ETL o senza una delle due origini sono esclusi ed elencati con il motivo (`ComparisonTest` e verify, punti 3 e 9).
7. Differenze, soglia del 15%, quote degli scarti e classifica seguono E6-E8 (`ComparisonTest` e verify, punti 4-8).
8. La rotta non scrive nulla (verify, punto 10). La raccolta non cambia: `git diff main -- src/REST/HitController.php src/Front/Tracker.php assets/tracker src/Sources src/Database` è vuoto, salvo le modifiche di A.2 se l'Appendice A è stata applicata.
9. Nessuna tabella, opzione o cron nuovi, e `Schema::DB_VERSION` resta `'4'`.
10. Con `wp5w_own_since` vuota, le altre schede restituiscono gli stessi numeri (verify, punto 11; sul blog, passo 9.4).
11. `CompareQuery` è l'unica classe di `src/Query/` che legge le tabelle aggregate senza `Origin::where()`, e lo dice in un commento. Il `grep` di R1 della fase 1 non trova altre eccezioni.
12. In wp-env la scheda Stato mostra le tre card del Confronto con dati reali (dopo verify, prima della pulizia, oppure con righe inserite a mano), la riga di copertura con i giorni esclusi e le righe evidenziate. La PR contiene uno screenshot.
13. `CLAUDE.md` e `CHANGELOG.md` (0.8.0) sono aggiornati.
14. Dopo il deploy sul blog, tutti i passi del runbook sono verdi, comprese la prova REST con l'application password (9.5) e, se c'è una sessione di amministratore disponibile, quella nel browser (9.7).

---

## 9. Runbook di deploy

Blog: `ssh mavida-blog`, cartella `~/domains/mavida.com/public_html/maurizio`, prefisso delle tabelle `maurizio_`, plugin in `public/plugins`, log in `~/logs/maurizio-debug.log`. `exec()` è disabilitato, quindi `wp db export` e `wp db import` non funzionano: i backup si fanno con `mysqldump`. Per gli URI da Git Bash serve `MSYS_NO_PATHCONV=1`. In questa fase **non** si ripete il gate dei tempi dell'endpoint, perché l'endpoint non cambia.

```bash
cd ~/domains/mavida.com/public_html/maurizio
WP="wp --url=https://maurizio.mavida.com"
```

### 9.1 Controllo di `REMOTE_ADDR` e degli scarti (controllo 8.9 della fase 1, **primo passo**)

Si esegue prima di tutto, in sola lettura: è anche il passo 0 dell'implementazione. Il giorno `G` da usare è il **primo giorno completo** dopo il deploy della 0.7.0 (2026-10-15) se è già passato, altrimenti oggi dalle 18:40 UTC.

```bash
G=2026-10-15
# Raccolta propria del giorno: visitatori distinti, visualizzazioni, ingressi.
$WP db query "SELECT COUNT(DISTINCT visitor) own_visitors, COUNT(*) own_views, SUM(is_entry) own_entries FROM maurizio_wp5w_hits WHERE day = '$G'"
# WP Statistics nello stesso giorno (visitatori e visualizzazioni).
$WP db query "SELECT COUNT(*) wps_visitors FROM maurizio_statistics_visitor WHERE last_counter = '$G'"
$WP db query "SELECT COALESCE(SUM(count),0) wps_views FROM maurizio_statistics_pages WHERE date = '$G'"
# Scarti per motivo.
$WP db query "SELECT reason, n FROM maurizio_wp5w_discards WHERE day = '$G' ORDER BY n DESC"
# I 5 hash più pesanti (hash giornalieri: nessun dato personale in più).
$WP db query "SELECT HEX(LEFT(visitor,4)) h, COUNT(*) views, COUNT(DISTINCT object_type, object_id) contents, SUM(is_entry) entries, COUNT(DISTINCT referrer_host) referrers FROM maurizio_wp5w_hits WHERE day = '$G' GROUP BY visitor ORDER BY views DESC LIMIT 5"
# Ora per ora (UTC): WP Statistics (first_view è in ora locale, quindi si converte) contro la raccolta propria.
$WP db query "SELECT HOUR(CONVERT_TZ(first_view, @@session.time_zone, '+00:00')) h_utc, COUNT(*) wps FROM maurizio_statistics_visitor WHERE last_counter = '$G' GROUP BY h_utc"
$WP db query "SELECT HOUR(ts) h_utc, COUNT(DISTINCT visitor) own FROM maurizio_wp5w_hits WHERE day = '$G' GROUP BY h_utc"
```

Si calcolano `r_own = own_visitors / own_views` e `r_wps = wps_visitors / wps_views`, poi si applica la **tabella di decisione**, nell'ordine:

| Esito | Condizione | Azione |
|---|---|---|
| **Dati insufficienti** | `wps_views < 30`, oppure meno di 6 ore di traffico dal deploy | Si prosegue con il deploy e si ripete 9.1 il giorno dopo. Lo si scrive nella nota di consegna. |
| **Raccolta ferma o parziale** | `wps_views >= 100` e `own_views < 0,3 × wps_views` | Il tracker non invia (o perde) le visite: ci si ferma e si avvisa Maurizio. Si indagano il beacon da Chrome, il JS combinato da LiteSpeed e la cache; il confronto non ha senso finché non è risolto. |
| **Proxy sospetto** | `r_own < 0,5 × r_wps`, e tra i 5 hash più pesanti ce n'è almeno uno con 3 o più ingressi da 2 o più referrer diversi (più persone fuse in un hash) | `REMOTE_ADDR` probabilmente non è l'IP del client: con lo stesso UA, persone diverse finiscono nello stesso hash. Ci si ferma e si avvisa Maurizio, che autorizza la diagnosi di A.1. Se la conferma, si implementa l'Appendice A (anche dopo il deploy, come 0.8.1) e intanto si spegne la raccolta (`own_tracking` = falso). |
| **Sorgente pesante** | Un hash ha più di 30 visualizzazioni, oppure i 3 hash più pesanti sommano più del 50% di `own_views`, oppure la quota di `rate` supera il 5% di `own_views + scarti` | È un bot o uno scraper (di solito un solo ingresso e un solo referrer), non un proxy: nessuna correzione. Si riporta nella nota (è un dato per R5). |
| **Ok** | Nessuna delle condizioni precedenti | Si prosegue. |

Si guarda anche il confronto ora per ora. Se in molte ore la raccolta propria vale 0 mentre WP Statistics ha visitatori, va scritto nella nota (ipotesi D di R5): se i totali non arrivano all'esito «raccolta ferma o parziale» non blocca il deploy, ma è un dato importante per la decisione finale. La query con `CONVERT_TZ( first_view, @@session.time_zone, '+00:00' )` è stata provata sul blog il 2026-10-14 (sessione `SYSTEM`); se restituisse `NULL`, si usa `HOUR(first_view)` ricordando che è in ora locale.

### 9.2 Backup e cattura di non regressione

```bash
mkdir -p ~/backups
STAMP=$(date +%F-%H%M)
TABLES=$($WP db tables 'maurizio_wp5w_*' --all-tables --format=csv | tr ',' ' ')
# Credenziali da wp-config.php: la password va in MYSQL_PWD e non si stampa mai.
export MYSQL_PWD="$($WP config get DB_PASSWORD)"
mysqldump -h "$($WP config get DB_HOST)" -u "$($WP config get DB_USER)" --single-transaction "$($WP config get DB_NAME)" $TABLES > ~/backups/wp5w-pre-0.8.0-$STAMP.sql
unset MYSQL_PWD
ls -l ~/backups/wp5w-pre-0.8.0-$STAMP.sql            # non vuoto
$WP option list --search='wp5w_*' --format=json > ~/backups/wp5w-options-pre-0.8.0.json
$WP plugin get wp-5w --field=version                 # 0.7.0
LOG_WP5W=$(grep -c "wp5w" ~/logs/maurizio-debug.log); echo "$LOG_WP5W"   # righe wp5w nel log prima dell'installazione
{
  $WP db query "SELECT origin, COUNT(*), SUM(visitors), SUM(views) FROM maurizio_wp5w_site_daily WHERE day BETWEEN '2026-09-14' AND '2026-10-12' GROUP BY origin"
  $WP db query "SELECT origin, COUNT(*), SUM(views), SUM(visitors) FROM maurizio_wp5w_object_daily WHERE day BETWEEN '2026-09-14' AND '2026-10-12' GROUP BY origin"
  for t in entry_daily referrer_daily dim_daily; do
    $WP db query "SELECT '$t', origin, COUNT(*) FROM maurizio_wp5w_$t WHERE day BETWEEN '2026-09-14' AND '2026-10-12' GROUP BY origin"
  done
  $WP eval '$r = ( new Mavida\FiveW\Query\OverviewQuery() )->get( new Mavida\FiveW\Support\DateRange( "2026-09-14", "2026-10-12" ) ); echo $r["kpis"]["visitors"]["value"], " ", $r["kpis"]["views"]["value"], "\n";'
  $WP db query "SELECT COUNT(*) FROM maurizio_wp5w_hits"
} > ~/backups/wp5w-nonreg-pre-0.8.0.txt
cat ~/backups/wp5w-nonreg-pre-0.8.0.txt
```

Il confronto vincolante è con questa cattura, presa subito prima dell'installazione e non a cavallo delle 4:00. L'istantanea della fase 1 (`site_daily` 29 righe, 3.532 visitatori e 4.659 visualizzazioni; `object_daily` 576 righe, 4.660 visualizzazioni e 4.111 visitatori; `entry_daily` 371; `referrer_daily` 186; `dim_daily` 1.110, tutte `wps`) resta solo un riferimento, perché l'ETL notturno rielabora gli ultimi 7 giorni.

### 9.3 Installazione

```bash
INSTALL_UTC=$(date -u '+%F %T'); echo "$INSTALL_UTC"
$WP plugin install https://github.com/mavidasnc/wp-5w/releases/download/v0.8.0/wp-5w-0.8.0.zip --force
$WP plugin get wp-5w --field=version                  # 0.8.0
$WP option get wp5w_db_version                         # 4 (nessuna migrazione)
grep -c "wp5w" ~/logs/maurizio-debug.log               # uguale a $LOG_WP5W (stessa sessione SSH di 9.2)
$WP cron event list --fields=hook,recurrence | grep wp5w   # wp5w_etl_daily e wp5w_own_hourly, come prima
```

### 9.4 Non regressione

Si ripetono le query della cattura di 9.2 (tranne l'ultima, `COUNT(*)` di `wp5w_hits`, che cresce con il traffico). Le righe `wps` e il risultato di `OverviewQuery` devono essere **identici**. Le righe `own` possono essere aumentate solo per il giorno di oggi. Una differenza sulle righe `wps` porta al rollback (9.9).

### 9.5 Prova REST della sezione Confronto (application password)

Dal PC locale, con Git Bash. L'application password dell'utente `maurizio` è in `C:\Users\maurizio.MAVIDA\Claude\credenzials\maurizio-blog\wordpress.md`: si mette in una variabile e non si stampa.

```bash
WPAUTH="maurizio:<application password>"
URL=https://maurizio.mavida.com/wp-json/wp5w/v1/admin/compare
curl -s -o /dev/null -w "%{http_code}\n" "$URL"                       # 401 senza credenziali
curl -s -o /dev/null -D - -u "$WPAUTH" "$URL" | grep -i "^HTTP\|cache-control"            # 200, no-store
# Il body passa da stdin: niente file in /tmp (in Git Bash /tmp e il /tmp di Python sono cartelle diverse).
curl -s -u "$WPAUTH" "$URL" | python -c "import json,sys; d=json.load(sys.stdin); c=d['coverage']; print(d['available'], c['from'], c['to'], c['days'], c['excluded']); print(d['totals']); print(d['home_direct']); print(d['discards'])"
```

Cosa ci si aspetta:

- `available` vero, `own_first_day` = `2026-10-14`, `excluded` con `{2026-10-14, partial}` e con oggi (`today`);
- se si esegue prima delle 4:00 del 2026-10-16, `days` è vuoto e il 15/10 compare come `etl_pending`; dalle 4:00 del 16/10, `from` = `to` = `2026-10-15` e `days` = 1;
- i valori di `days` coincidono con quelli del database:

```bash
$WP db query "SELECT day, origin, visitors, views FROM maurizio_wp5w_site_daily WHERE day >= '2026-10-14' ORDER BY day, origin"
$WP db query "SELECT day, origin, SUM(visitors) FROM maurizio_wp5w_entry_daily WHERE object_type='home' AND channel='direct' AND day >= '2026-10-14' GROUP BY day, origin"
$WP db query "SELECT day, reason, n FROM maurizio_wp5w_discards ORDER BY day, reason"
```

- `GET /admin/status`, `/admin/overview?from=2026-09-14&to=2026-10-12` e `/admin/settings` rispondono 200 con la stessa autenticazione, e `overview` restituisce gli stessi totali dell'eval di 9.2.

### 9.6 Chiavi dei contenuti nelle due origini

Sul primo giorno completo (per esempio `G=2026-10-15`), si controlla che le due origini usino gli stessi `(object_type, object_id)`, così la classifica del Confronto misura la raccolta e non la classificazione:

```bash
$WP db query "SELECT origin, COUNT(*) FROM maurizio_wp5w_object_daily WHERE day = '$G' GROUP BY origin"
$WP db query "SELECT o.object_type, o.object_id, o.uri, o.views FROM maurizio_wp5w_object_daily o WHERE o.day = '$G' AND o.origin = 'own' AND NOT EXISTS (SELECT 1 FROM maurizio_wp5w_object_daily w WHERE w.day = o.day AND w.origin = 'wps' AND w.object_type = o.object_type AND w.object_id = o.object_id) ORDER BY o.views DESC LIMIT 10"
```

Le chiavi presenti solo in `own` con molte visualizzazioni (tipi diversi da quelli di WP Statistics, ID diversi per la home, eccetera) vanno riportate nella nota. Non bloccano il deploy.

### 9.7 Prova nel browser

Non c'è una sessione di amministratore nel browser automatico, e l'application password non apre wp-admin. Quindi:

1. **In wp-env** (sempre, prima della PR): screenshot della scheda Stato con le tre card del Confronto (criterio 12).
2. **Sul blog**, solo se Maurizio ha una sessione aperta in Chrome (claude-in-chrome, senza digitare password): si apre `https://maurizio.mavida.com/app/wp-admin/admin.php?page=wp5w&tab=status`, si controlla che le tre card ci siano, che la copertura elenchi i giorni esclusi e che la console non abbia errori. Se la sessione non c'è, si scrive «da provare da Maurizio» nella nota, senza bloccare il deploy: la REST di 9.5 copre i dati.

### 9.8 Analisi del raddoppio (una tantum, per la decisione finale)

Queste query non bloccano il deploy. Si eseguono quando ci sono almeno 3 giorni completi, e di nuovo alla fine del confronto, e i risultati vanno nella nota per la decisione del 2026-10-28.

```bash
FROM=2026-10-15; TO=$(date -d yesterday +%F)
# Gruppo sospetto in WP Statistics: diretto, una visualizzazione, Chrome Mobile, ingresso sulla home.
$WP db query "SELECT v.last_counter, COUNT(*) tot, SUM(v.source_channel='direct' AND v.hits=1 AND v.agent LIKE 'Chrome Mobile%' AND p.type='home') sospetti FROM maurizio_statistics_visitor v LEFT JOIN maurizio_statistics_pages p ON p.page_id = v.first_page WHERE v.last_counter BETWEEN '$FROM' AND '$TO' GROUP BY v.last_counter"
# Stesso gruppo visto dagli aggregati (è anche nella card «Dove nasce la differenza» del Confronto).
$WP db query "SELECT day, origin, SUM(visitors) FROM maurizio_wp5w_entry_daily WHERE object_type='home' AND channel='direct' AND day BETWEEN '$FROM' AND '$TO' GROUP BY day, origin"
# Profilo orario (UTC) della raccolta propria: visitatori con una sola visualizzazione, diretti, sulla home.
$WP db query "SELECT HOUR(h.ts) h_utc, COUNT(*) FROM maurizio_wp5w_hits h JOIN (SELECT day, visitor FROM maurizio_wp5w_hits WHERE day BETWEEN '$FROM' AND '$TO' GROUP BY day, visitor HAVING COUNT(*) = 1) s ON s.day = h.day AND s.visitor = h.visitor WHERE h.object_type='home' AND h.channel='direct' GROUP BY h_utc"
# Profilo orario di WP Statistics per lo stesso gruppo (first_view in ora locale: la piattezza non dipende dal fuso).
$WP db query "SELECT HOUR(first_view) h_locale, COUNT(*) FROM maurizio_statistics_visitor WHERE last_counter BETWEEN '$FROM' AND '$TO' AND source_channel='direct' AND hits=1 AND agent LIKE 'Chrome Mobile%' GROUP BY h_locale"
# Scarti per motivo nella finestra.
$WP db query "SELECT reason, SUM(n) FROM maurizio_wp5w_discards WHERE day BETWEEN '$FROM' AND '$TO' GROUP BY reason"
```

Si legge con la tabella di R5. Se una distribuzione per ora è piatta (scarto massimo tra le ore sotto il 30% della media), si tratta di traffico automatico.

### 9.9 Rollback

Si fa se un passo tra 9.3 e 9.5 fallisce. La 0.8.0 non cambia lo schema né scrive dati, quindi basta reinstallare la 0.7.0:

```bash
$WP plugin install https://github.com/mavidasnc/wp-5w/releases/download/v0.7.0/wp-5w-0.7.0.zip --force
$WP plugin get wp-5w --field=version                  # 0.7.0
$WP option get wp5w_db_version                         # 4
```

Poi si ripetono le query di 9.4. Se le righe `wps` risultano comunque diverse dalla cattura (cosa che la 0.8.0 non dovrebbe poter causare), si ripristinano le tabelle dal dump:

```bash
export MYSQL_PWD="$($WP config get DB_PASSWORD)"
mysql -h "$($WP config get DB_HOST)" -u "$($WP config get DB_USER)" "$($WP config get DB_NAME)" < ~/backups/wp5w-pre-0.8.0-<STAMP>.sql
unset MYSQL_PWD
```

Questo ripristino perde le righe di `wp5w_hits` arrivate dopo il backup. Lo si scrive nella nota.

### 9.10 Raccolta ancora attiva dopo l'installazione

Circa un'ora dopo 9.3:

```bash
$WP db query "SELECT COUNT(*) new_hits FROM maurizio_wp5w_hits WHERE ts >= '$INSTALL_UTC'"     # > 0 se c'è stato traffico
curl -s https://maurizio.mavida.com/ | grep -o 'id="wp5w-hit"[^<]*'                           # blocco presente
```

Il tracker non ha un tag script proprio, perché LiteSpeed combina i JS: si verifica il blocco `#wp5w-hit` e, da Chrome, che il beacon risponda `204`. Se `new_hits` è 0 mentre WP Statistics ha registrato visitatori nello stesso intervallo, si avvisa Maurizio. Non c'è rollback, perché la 0.8.0 non tocca la raccolta: è un problema da indagare a parte.

### 9.11 Nota di consegna

La nota riporta:

- data e ora di installazione (`INSTALL_UTC`);
- l'esito di 9.1 con i numeri e, se è stata applicata, l'Appendice A;
- l'esito di 9.4 e di 9.5, con `coverage` e i totali;
- le chiavi presenti solo in `own` (9.6);
- l'esito della prova nel browser (9.7);
- gli eventuali scostamenti da questa specifica;
- il promemoria della decisione del 2026-10-28, con le query di 9.8 da rieseguire.

---

## Appendice A. Correzione condizionata: IP del client dietro un proxy fidato

Si applica **solo** se il passo 0 o il passo 9.1 danno «proxy sospetto» e la diagnosi di A.1 lo conferma. Altrimenti non si implementa.

### A.1 Diagnosi

Con un file temporaneo, d'accordo con Maurizio perché è una scrittura sul server: `public/wp5w-ip-check.php` stampa `REMOTE_ADDR`, `HTTP_X_FORWARDED_FOR`, `HTTP_X_REAL_IP` e `HTTP_CF_CONNECTING_IP`, protetto da un token casuale nel nome o in un parametro. Lo si apre da due reti diverse (PC e telefono senza Wi-Fi) e lo si cancella subito dopo. Il proxy è confermato se `REMOTE_ADDR` è uguale nelle due prove (o appartiene allo stesso blocco del provider) e diverso dall'IP pubblico del client, mentre `X-Forwarded-For` contiene l'IP pubblico giusto.

### A.2 Correzione

- Costante facoltativa `WP5W_TRUSTED_PROXIES` in `wp-config.php`: un elenco di IP o CIDR separati da virgole, con gli indirizzi del proxy trovati in A.1. Non c'è un'interfaccia: si imposta una volta, via SSH. Una costante evita una nuova opzione e un campo nella scheda Opzioni per un caso che riguarda solo l'infrastruttura.
- Funzione pura `Visit::client_ip( string $remote_addr, string $forwarded_for, array $trusted ): string`:
  1. se `$trusted` è vuoto, oppure `$remote_addr` non rientra in `$trusted` (`Visit::ip_excluded()`), restituisce `$remote_addr`;
  2. altrimenti scorre `$forwarded_for`, separato da virgole, **da destra a sinistra**, salta gli indirizzi che rientrano in `$trusted` e restituisce il primo IP valido (`filter_var( ..., FILTER_VALIDATE_IP )`);
  3. se non ne trova, restituisce `$remote_addr`.
- `HitController::handle()` usa `Visit::client_ip()` al posto di `REMOTE_ADDR`, sia per l'hash sia per gli IP esclusi. `HTTP_X_FORWARDED_FOR` si legge da `$_SERVER`, come gli altri valori. Il resto dell'endpoint non cambia, e non ci sono scritture in più.
- `CLAUDE.md`: la regola «mai da `X-Forwarded-For`» diventa «`X-Forwarded-For` solo se `REMOTE_ADDR` è un proxy elencato in `WP5W_TRUSTED_PROXIES`».

### A.3 Test PHPUnit (`VisitTest`)

- Nessun proxy fidato, con `X-Forwarded-For` falsificato: si usa `REMOTE_ADDR`.
- `REMOTE_ADDR` non fidato, con `X-Forwarded-For`: si usa `REMOTE_ADDR`.
- `REMOTE_ADDR` fidato e `X-Forwarded-For` = `"203.0.113.5"`: si usa `203.0.113.5`.
- `REMOTE_ADDR` fidato e `X-Forwarded-For` = `"1.2.3.4, 203.0.113.5"`: si usa `203.0.113.5`, l'elemento più a destra non fidato, mentre `1.2.3.4` può essere falsificato.
- Catena di proxy fidati (`X-Forwarded-For` = `"203.0.113.5, 10.0.0.2"` con `10.0.0.0/8` fidato): si usa `203.0.113.5`.
- `X-Forwarded-For` vuoto o non valido (`"abc, ,"`): si usa `REMOTE_ADDR`.
- Caso IPv6.

### A.4 Controlli in `verify.php`

Si definisce `WP5W_TRUSTED_PROXIES = '192.0.2.1'` all'inizio dello script; gli altri casi usano `198.51.100.x`, quindi non cambiano. Poi:

- due visite da `REMOTE_ADDR = 192.0.2.1`, con `X-Forwarded-For` diversi e lo stesso UA, danno 2 visitatori distinti;
- una visita da `198.51.100.20` con `X-Forwarded-For` falsificato ha lo stesso hash di una senza header;
- una regola IP esclusa che corrisponde all'IP di `X-Forwarded-For` (con proxy fidato) dà lo scarto `ip`.

---

## 10. Fuori da questa fase

- Nuovi filtri antibot, per esempio contro il gruppo «diretto, home, una visualizzazione»: si decidono alla fine del confronto, sui dati (domande aperte).
- Grafici del confronto, esportazione e confronto per paese, dispositivo e browser: le dimensioni `own` arrivano con la fase 3.
- Profilo orario nell'interfaccia (E10).
- Impostazione di `wp5w_own_since` e pulizia di `wp5w_hits`: fase 5.
