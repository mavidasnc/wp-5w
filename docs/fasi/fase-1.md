# Fase 1: raccolta propria e scheda Opzioni

Specifica di implementazione della fase 1 di [roadmap-sostituzione-wp-statistics.md](../roadmap-sostituzione-wp-statistics.md). Le sezioni «Decisioni già prese», «Principio di progetto» e «Scostamenti» della roadmap valgono qui senza ripeterle; dove questo documento è più preciso, vale questo documento.

- **Branch:** `fase-1-raccolta-propria`, creato da `main` (commit `c4c0607`).
- **Versione da rilasciare:** `0.7.0`, con lo schema del database alla versione `4`.
- **Ciclo:** implementazione di Sonnet su questo branch, poi PR verso `main` con la checklist dei criteri di accettazione numerati qui sotto; review di Opus 5.5, che rilancia `composer ci`, `npm run check` e `verify.php`; al massimo 3 giri di correzioni; squash merge, release, deploy con il runbook in fondo e nota di consegna per la fase 2.
- **Obiettivo:** registrare visite e visitatori in modo indipendente da WP Statistics, scrivendo righe `origin = 'own'` negli aggregati, **senza che cambi nessun numero dell'interfaccia** finché `wp5w_own_since` è vuota.

---

## 1. Decisioni prese in questa specifica

Dove la roadmap lascia scelte aperte, si decide così (due righe di motivazione ciascuna).

| # | Decisione | Motivazione |
|---|---|---|
| D1 | L'endpoint riconosce l'utente collegato leggendo da sé il cookie: `wp_validate_auth_cookie( $_COOKIE[ LOGGED_IN_COOKIE ] ?? '', 'logged_in' )`, poi `user_can( $id, 'manage_options' )`. | Una richiesta REST senza `X-WP-Nonce` viene riportata all'utente 0 da `rest_cookie_check_errors()`, e il beacon da una pagina in cache non può avere un nonce valido: `is_user_logged_in()` sarebbe sempre falso. La lettura serve solo a scartare la visita, quindi non c'è rischio CSRF. |
| D2 | Il tracker legge i propri dati da un blocco `<script type="application/json" id="wp5w-hit">` stampato in `wp_footer`, non da JavaScript in linea. | Sul blog LiteSpeed ha `optm-js_defer = 1` e `optm-js_min = 1`: un blocco JSON non viene eseguito, quindi l'ottimizzazione non lo tocca e l'ordine di esecuzione non conta. |
| D3 | Il tracker si compila con `wp-scripts`, come seconda entry di `webpack.config.js` (`tracker/tracker`), e finisce in `build/tracker/tracker.js`. | `build-zip.js` impacchetta solo `src`, `build`, `vendor` e `languages`: un file servito da `assets/` non arriverebbe sul blog. `check-build.js` ne controlla la presenza e il peso. |
| D4 | Il beacon si invia come `URLSearchParams` (`application/x-www-form-urlencoded`). | È un tipo «semplice» per `sendBeacon`, la REST di WordPress lo legge già nei parametri del body e non serve nessun parsing JSON. |
| D5 | DNT: con l'opzione «Rispetta DNT» attiva e `navigator.doNotTrack === '1'` (o `window.doNotTrack === '1'`) il tracker invia solo `dnt=1`, senza contenuto, URI, referrer né UTM; il server conta lo scarto `dnt`. Il server tratta allo stesso modo l'header `DNT: 1`. | La roadmap chiede di contare gli scarti per DNT, e senza una richiesta non si potrebbero contare. Non si salva nulla della visita, nemmeno l'hash: resta solo un contatore giornaliero. |
| D6 | L'hash del visitatore usa `$_SERVER['REMOTE_ADDR']`, senza leggere `X-Forwarded-For`. | Sul blog non ci sono header di CDN (`server: LiteSpeed`, nessun `x-hcdn`), quindi `REMOTE_ADDR` è l'IP del client; gli header inoltrati si possono falsificare e permetterebbero di aggirare il limite di frequenza. Il runbook verifica che sul blog l'ipotesi regga. |
| D7 | L'hash si salva come `binary(16)` e passa sempre nelle query come esadecimale con `UNHEX(%s)`. | Con byte binari in una query, `wpdb::query()` può rimuovere le sequenze UTF-8 non valide (`strip_invalid_text_from_query`) e corrompere il valore; l'esadecimale è ASCII. |
| D8 | La chiave del giorno sta nell'opzione `wp5w_daily_salt` (autoload) come stringa `Y-m-d|<64 caratteri esadecimali>` e si rigenera alla prima visita del giorno con un `UPDATE` condizionato (vedi R6). | Senza object cache, l'opzione caricata con `alloptions` non costa query in più. L'`UPDATE ... WHERE option_value = <vecchio>` evita che due richieste simultanee creino due chiavi diverse, cosa che conterebbe due volte lo stesso visitatore. |
| D9 | Il limite di frequenza è di **30 visite al minuto** per hash e si conta con una sola `SELECT` sull'indice `(visitor, ts)`, che restituisce anche il numero di visite del giorno (serve per l'ingresso). | Una persona che sfoglia in fretta le immagini di un articolo resta sotto il limite. Con una sola query si fanno due controlli e non si scrive in `wp_options` (niente transient). |
| D10 | `ts` è in UTC (`gmdate( 'Y-m-d H:i:s' )`), mentre `day` è il giorno del sito (`wp_date( 'Y-m-d' )`, fuso `Europe/Rome`). Tutte le date si calcolano in PHP, mai con `NOW()` o `CURDATE()`. | `ts` serve per le finestre di pochi minuti (frequenza, e «online adesso» nella fase 4) e in UTC non ha ambiguità con l'ora legale; `day` deve coincidere con i giorni di WP Statistics. |
| D11 | L'aggregazione è la sorgente `Sources\OwnSource`, aggiunta alle sorgenti dell'`Importer`, e passa da `replace_by_month()` con `$delete = " AND origin = 'own'"`. Ha un lock MySQL proprio (`GET_LOCK( 'wp5w_own_aggregate', 30 )`), preso sia dall'ETL notturno sia dal passaggio orario. | Così l'ETL notturno, il pulsante di Stato e `wp 5w etl` la includono senza codice in più, e vale la stessa regola «mai cancellare dove la sorgente non ha più dati». Il lock è separato da quello dell'ETL (scostamento della roadmap), ma è unico per i due percorsi che scrivono righe `own`, che quindi non possono sovrapporsi. |
| D12 | Il passaggio orario (`wp5w_own_hourly`) rielabora **ieri e oggi** e non scrive `wp5w_etl_state`. Gli errori vanno in `error_log`. | Ieri si chiude entro un'ora dalla mezzanotte, senza aspettare le 4:00. Se aggiornasse lo stato dell'ETL, nella scheda Stato «Ultimo import» cambierebbe ogni ora e nasconderebbe l'esito dell'ETL notturno. |
| D13 | Nella fase 1 le righe `own` di `dim_daily` hanno solo le dimensioni `channel` e `source`. Paese, dispositivo, browser e sistema arrivano con la fase 3. | Le colonne corrispondenti di `wp5w_hits` restano vuote (roadmap). Scrivere `(n/d)` per tutte gonfierebbe la tabella senza informazione. |
| D14 | `coverage()` (in `OverviewQuery`, usata dalla scheda Stato e da `wp 5w status`) applica la regola dell'origine alle cinque tabelle aggregate, ma non a `gsc_daily`. | La scheda Stato deve descrivere i dati che l'interfaccia mostra davvero. Il confronto tra `wps` e `own` è il compito della fase 2. |
| D15 | La regola dell'origine si scrive in SQL con la data **letterale**, dopo averla validata con `DateRange::is_valid()`: `Origin::where( $own_since, $alias )`. | Le query esistenti preparano argomenti posizionali; un frammento con altri `%s` costringerebbe a riordinare ogni `prepare()`. Una data `Y-m-d` validata non può contenere apici, e se non è valida si ricade su `origin = 'wps'`. |
| D16 | I tipi di contenuto riproducono `WP_Statistics\Pages::get_page_type()` (14.16): `home`, `post`, `page`, `post_type_<tipo>` (anche `post_type_attachment`), `archive`, `category`, `post_tag`, `tax_<tassonomia>`, `author`, `search`, `404`. L'ID è `get_queried_object_id()`, mentre per `search` vale 0. | `OverviewQuery::TOP_TYPES`, `TrendQuery::POST_TYPES`, `PostsQuery::object_type()` e il confronto della fase 2 si basano su questi valori. |
| D17 | Canali e sorgenti usano i valori di WP Statistics visti sul blog: canali `direct`, `search`, `social`, `referral`, `email`, `unassigned`; sorgenti `Direct`, `Google`, `Bing`, `DuckDuckGo`, `Yahoo`, `Brave`, `ChatGPT`, `Ecosia`, `Qwant`, `Yandex`, `Baidu`, `Facebook`, `LinkedIn`, `Instagram`, eccetera. Nella fase 1 `chatgpt.com` resta in `search`, come in WP Statistics; il canale «AI» arriva con la fase 4. | Il confronto della fase 2 deve misurare la raccolta, non le differenze di classificazione. |
| D18 | Si conserva solo l'host del referrer: minuscolo, senza `www.`, vuoto se è il sito stesso. L'URI è il percorso relativo alla home, senza query string, al massimo 190 caratteri. | È quello che salva WP Statistics (`referred` contiene solo l'host). Le query string possono contenere dati personali; le UTM si leggono a parte. |
| D19 | La conservazione delle visite grezze si **salva** nella fase 1 ma non si applica: la pulizia automatica è nella fase 5, come da roadmap. Il campo lo dice nel testo d'aiuto. | Nelle 2 settimane del confronto `wp5w_hits` cresce di poche migliaia di righe. Vedi le domande aperte. |
| D20 | `wp5w_own_since` è modificabile nella scheda Opzioni, ma il server accetta solo una data vuota oppure valida, non futura e non precedente al primo giorno con righe `own` in `site_daily`. | Così il passaggio della fase 5 non richiede codice nuovo e la validazione impedisce di puntare a giorni senza dati. Finché non ci sono righe `own`, l'unico valore accettato è quello vuoto. |
| D21 | Quando cambiano «Raccolta propria» o «Rispetta DNT», il salvataggio delle opzioni chiama `do_action( 'litespeed_purge_all' )`. | I due valori finiscono nell'HTML in cache (il blocco JSON c'è o non c'è, `dnt` vale vero o falso), e il TTL pubblico del blog è di 7 giorni. |
| D22 | La firma è `substr( hash_hmac( 'sha256', "{$type}|{$id}", wp_salt( 'auth' ) ), 0, 32 )` e si confronta con `hash_equals()`. | Non scade, quindi resta valida nelle pagine in cache. Copre tipo e ID, che sono le chiavi degli aggregati; l'URI no, perché la stessa pagina può avere più URI. Se cambiano le salt di WordPress, le pagine in cache vanno svuotate (runbook). |
| D23 | Gli scarti per IP escluso hanno un motivo proprio, `ip`, oltre a `bot`, `user`, `dnt`, `rate` e `signature` della roadmap. Un payload malformato (tipo o ID mancanti) conta come `signature`. | Nel confronto della fase 2 bisogna distinguere i propri IP esclusi dal traffico che si filtra. |

---

## 2. File da creare o modificare

### Da creare

| File | Contenuto |
|---|---|
| `src/Settings.php` | Classe `Mavida\FiveW\Settings` (con il guard ABSPATH): costanti `OPTION = 'wp5w_settings'` e `OWN_SINCE = 'wp5w_own_since'`, `defaults()`, `get()` (impostazioni unite ai default), `own_since()`, `register()` (che chiama `register_setting( 'wp5w', 'wp5w_settings', ... )` su `init`, con `type` `object`, `default`, `sanitize_callback` e `show_in_rest => false`) e `sanitize( array ): array`. |
| `src/Support/Origin.php` | Classe pura: `TABLES` (le cinque tabelle con `origin`) e `where( string $own_since, string $alias = '' ): string` (vedi R1). |
| `src/Support/BotFilter.php` | Classe pura: `is_bot( string $user_agent ): bool` con un elenco di firme (vedi R4). |
| `src/Support/Channels.php` | Classe pura: `host( string $referrer, string $site_host ): string` e `classify( string $host, string $utm_source, string $utm_medium ): array{channel:string,source:string}` (vedi R8). |
| `src/Support/Visit.php` | Classe pura con le regole della singola visita: `visitor_hash( string $ip, string $ua, string $key ): string` (16 byte), `signature( string $type, int $id, string $key ): string`, `check_signature( string $type, int $id, string $signature, string $key ): bool`, `user_excluded( string $mode, bool $logged_in, bool $is_admin ): bool`, `is_valid_ip_rule( string $rule ): bool`, `ip_excluded( string $ip, array $rules ): bool` (IPv4 e IPv6, singolo o CIDR) e `is_entry( int $hits_today, string $referrer_host ): bool`. |
| `src/Front/Tracker.php` | Carica `build/tracker/tracker.js` (`wp_enqueue_script` con `strategy => 'defer'` e `in_footer => true`) e stampa il blocco JSON `#wp5w-hit` in `wp_footer`. Contiene `current_object(): array{type:string,id:int}` (D16) e `signature( string $type, int $id ): string`, che usa `Visit::signature()` con `wp_salt( 'auth' )`. |
| `src/REST/HitController.php` | Rotta pubblica `POST /wp5w/v1/hit` (vedi R3). Estende `RestController`. |
| `src/Sources/OwnSource.php` | Sorgente `own` che legge `wp5w_hits` (vedi R9). Ha anche `run_hourly()`, il punto di ingresso del cron orario. |
| `assets/tracker/tracker.js` | Script senza dipendenze, sotto i 1.536 byte una volta compilato (vedi R2). |
| `assets/admin/pages/OptionsPage.jsx` | Scheda «Opzioni» (vedi R11). |
| `tests/Unit/OriginTest.php`, `BotFilterTest.php`, `ChannelsTest.php`, `VisitTest.php` | Test PHPUnit (sezione 5). |

### Da modificare

| File | Modifica |
|---|---|
| `src/Database/Schema.php` | `DB_VERSION = '4'`; colonna `origin` e nuove chiavi primarie nelle cinque tabelle; tabelle `wp5w_hits` e `wp5w_discards`; costante `RAW_TABLES = array( 'hits', 'discards' )`; migrazione esplicita prima di `dbDelta` (sezione 3). `TABLES` non cambia, perché la usa la scheda Stato. |
| `src/Sources/WpStatisticsSource.php` | Scrive e cancella solo righe `origin = 'wps'` (R10). |
| `src/Query/OverviewQuery.php` | Regola dell'origine in `totals()`, `series()`, `channels()`, `top()` (anche nelle due subquery su `entry_daily`, alias `e`) e `coverage()` (D14). |
| `src/Query/PostsQuery.php` | Regola dell'origine in `top()`, `detail()` (`object_daily`, `entry_daily`, `referrer_daily`), `daily()`, `totals()` e `first_day()`. |
| `src/Query/AudienceQuery.php` | Regola dell'origine sulle due letture di `site_daily` e in `referrers()`. |
| `src/Query/TrendQuery.php` | Regola dell'origine in `site()` e in `objects()` (solo su `object_daily`). |
| `src/Etl/Scheduler.php` | Hook `OWN_HOURLY_HOOK = 'wp5w_own_hourly'`, pianificato `hourly` in `register()` e collegato a `OwnSource::run_hourly()`. |
| `src/Plugin.php` | Aggiunge `OwnSource` alle sorgenti di `importer()`; registra `Settings`, `Tracker` (solo nel frontend), `HitController` e le rotte delle opzioni. |
| `src/REST/AdminController.php` | Rotte `GET` e `POST /admin/settings` (R11). Il costruttore non cambia. |
| `src/Deactivator.php`, `uninstall.php` | Tolgono anche `wp5w_own_hourly`. `uninstall.php`, con `WP5W_DELETE_DATA`, elimina anche `wp5w_hits` e `wp5w_discards` e le opzioni `wp5w_settings`, `wp5w_own_since` e `wp5w_daily_salt`. |
| `assets/admin/index.jsx` | Scheda «Opzioni» tra «Visitatori» e «Stato» (`?tab=options`). Il sottotitolo dell'header resta invariato. |
| `webpack.config.js` | Entry `'tracker/tracker': './assets/tracker/tracker.js'`. |
| `scripts/check-build.js` | Controlla che `build/tracker/tracker.js` esista, non sia vuoto e pesi meno di 1.536 byte, e che `src/Front/Tracker.php` lo agganci. |
| `tests/fixtures/verify.php` | Nuovi controlli (sezione 6); i controlli esistenti filtrano `origin = 'wps'`. |
| `CLAUDE.md` | Struttura (nuove classi, tracker, hook orario) e «Punti da ricordare»: regola dell'origine, `UNHEX`, cookie letto nell'endpoint, purge di LiteSpeed. |
| `CHANGELOG.md` | Voce `0.7.0`. La versione la aggiorna `npm run release -- 0.7.0` al momento del rilascio, non a mano. |

---

## 3. Schema del database e migrazione alla versione 4

### Tabelle aggregate

In tutte e cinque le tabelle si aggiunge `origin varchar(8) NOT NULL DEFAULT 'wps'` (valori ammessi: `wps`, `own`), in coda alle colonne, e diventa l'ultima colonna della chiave primaria:

| Tabella | Chiave primaria v3 | Chiave primaria v4 |
|---|---|---|
| `site_daily` | `(day)` | `(day, origin)` |
| `object_daily` | `(day, object_type, object_id)` | `(day, object_type, object_id, origin)` |
| `entry_daily` | `(day, object_type, object_id, channel)` | `(day, object_type, object_id, channel, origin)` |
| `referrer_daily` | `(day, object_type, object_id, referrer)` | `(day, object_type, object_id, referrer, origin)` |
| `dim_daily` | `(day, dim, value)` | `(day, dim, value, origin)` |

Gli indici secondari (`object`) restano uguali. `gsc_daily` non cambia: la sua colonna `origin` (`rankmath`/`api`) ha un altro significato e la regola dell'origine non la riguarda.

### Nuove tabelle

```sql
CREATE TABLE {prefix}wp5w_hits (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ts datetime NOT NULL,                       -- UTC
  day date NOT NULL,                          -- giorno del sito (Europe/Rome)
  visitor binary(16) NOT NULL,                -- md5 grezzo di ip . ua . chiave del giorno
  object_type varchar(40) NOT NULL,
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  uri varchar(190) NOT NULL DEFAULT '',
  referrer_host varchar(190) NOT NULL DEFAULT '',
  channel varchar(50) NOT NULL DEFAULT '',
  source varchar(100) NOT NULL DEFAULT '',
  utm_source varchar(100) NOT NULL DEFAULT '',
  utm_medium varchar(100) NOT NULL DEFAULT '',
  utm_campaign varchar(100) NOT NULL DEFAULT '',
  is_entry tinyint(1) unsigned NOT NULL DEFAULT 0,
  country char(2) NOT NULL DEFAULT '',
  device varchar(20) NOT NULL DEFAULT '',
  browser varchar(40) NOT NULL DEFAULT '',
  os varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY visitor_ts (visitor,ts),
  KEY day (day)
) {charset};

CREATE TABLE {prefix}wp5w_discards (
  day date NOT NULL,
  reason varchar(20) NOT NULL,                -- bot, user, dnt, rate, signature, ip
  n int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,reason)
) {charset};
```

Non c'è nessuna colonna con l'IP, intero o troncato, né con lo user agent.

### Migrazione (`Schema::install()`)

`dbDelta()` non modifica le chiavi primarie: con la nuova definizione aggiungerebbe la colonna e poi fallirebbe con «Multiple primary key defined». Per questo la migrazione esplicita viene **prima** di `dbDelta()`:

1. `SELECT GET_LOCK( 'wp5w_schema_upgrade', 0 )`. Se non restituisce `1`, un'altra richiesta sta migrando: si esce senza toccare nulla, nemmeno `wp5w_db_version`.
2. Si rilegge `wp5w_db_version` direttamente dal database (`SELECT option_value FROM {options} WHERE option_name = 'wp5w_db_version'`), perché `alloptions` può essere vecchio. Se vale già `DB_VERSION`, si rilascia il lock e si esce.
3. Per ciascuna delle cinque tabelle, **se esiste** (`SHOW TABLES LIKE`):
   - se manca la colonna `origin` (`SHOW COLUMNS ... LIKE 'origin'`), si aggiunge la clausola `ADD COLUMN origin varchar(8) NOT NULL DEFAULT 'wps'`;
   - se le colonne di `SHOW KEYS FROM {t} WHERE Key_name = 'PRIMARY'` (ordinate per `Seq_in_index`) sono diverse da quelle della v4, si aggiungono le clausole `DROP PRIMARY KEY, ADD PRIMARY KEY (...)`;
   - se ci sono clausole, si esegue **un solo** `ALTER TABLE {t} <clausole>`, che è atomico. Se fallisce: `error_log( 'wp5w: ...' . $wpdb->last_error )`, `RELEASE_LOCK` e uscita senza aggiornare la versione (si riprova alla richiesta successiva).
4. `dbDelta()` con le definizioni finali: crea `wp5w_hits` e `wp5w_discards` e non fa nulla sulle tabelle già migrate.
5. `update_option( 'wp5w_db_version', '4' )`, poi `RELEASE_LOCK` (in un `finally`).

Su un'installazione nuova le tabelle non esistono, il punto 3 non fa nulla e `dbDelta()` le crea già in v4. Rieseguire `install()` su uno schema v4 non esegue nessun `ALTER`.

### Opzioni

| Opzione | Autoload | Contenuto |
|---|---|---|
| `wp5w_settings` | sì | `own_tracking` (bool, `true`), `exclude_users` (`admins`, `logged_in` o `none`; `admins`), `excluded_ips` (array di stringhe, vuoto), `respect_dnt` (bool, `true`), `retention_days` (int da 7 a 365, `30`) |
| `wp5w_own_since` | sì | `''` oppure `Y-m-d` |
| `wp5w_daily_salt` | sì | `Y-m-d|<hex64>` |

---

## 4. Regole di business

### R1. Regola dell'origine (`Support\Origin`)

- `Origin::where( '', 'o' )` restituisce `o.origin = 'wps'`.
- `Origin::where( '2026-11-01', 'o' )` restituisce `( ( o.origin = 'wps' AND o.day < '2026-11-01' ) OR ( o.origin = 'own' AND o.day >= '2026-11-01' ) )`.
- Una data non valida per `DateRange::is_valid()` (anche `"2026-13-01"` o `"x' OR 1=1 --"`) equivale alla stringa vuota.
- Senza alias le colonne non hanno prefisso.
- **Tutte** le letture delle cinque tabelle aggregate in `src/Query/` aggiungono `AND ` . `Origin::where( Settings::own_since(), <alias> )`, comprese le subquery correlate e le query senza intervallo (`MIN(day)`, `coverage()`). Non si applica a `gsc_daily`.
- Controllo da fare in review: `grep -n "site_daily\|object_daily\|entry_daily\|referrer_daily\|dim_daily" src/Query` non deve trovare letture prive di `Origin::where`.

### R2. Tracker (`assets/tracker/tracker.js`)

1. Legge `#wp5w-hit`. Se il blocco manca o non è JSON valido, esce. Campi: `url` (endpoint REST), `type`, `id`, `sig` e `dnt` (bool, l'opzione «Rispetta DNT»).
2. Se `navigator.webdriver` è vero, esce senza inviare nulla.
3. Se `dnt` è vero e `navigator.doNotTrack === '1' || window.doNotTrack === '1'`, invia solo `dnt=1` ed esce.
4. Altrimenti invia `t`, `id`, `s`, `u` (`location.pathname`), `r` (`document.referrer`) e, se presenti nell'URL, `us`, `um` e `uc` (`utm_source`, `utm_medium` e `utm_campaign`).
5. Invio: `navigator.sendBeacon( url, params )`; se manca o restituisce `false`, `fetch( url, { method: 'POST', body: params, keepalive: true, credentials: 'same-origin' } )`, ignorando gli errori.
6. Nessun cookie, nessun `localStorage` e nessun controllo sull'utente collegato. Non si usano `@wordpress/*` né altri import.

`Front\Tracker` stampa script e blocco solo se `own_tracking` è attivo, nel frontend, e non se `is_preview()`, `is_customize_preview()`, `is_feed()`, `is_robots()`, `is_trackback()` o `is_embed()`.

### R3. Endpoint `POST /wp5w/v1/hit` (`REST\HitController`)

`permission_callback` è `__return_true` e la rotta non dichiara `args`: i parametri si leggono e si validano a mano, per restare leggeri. Risponde **sempre** `204` senza body, anche per gli scarti e gli errori interni, che finiscono in `error_log` solo con `WP_DEBUG`. I passi seguono quest'ordine e si fermano al primo scarto:

1. Se `get_option( 'wp5w_db_version' ) !== Schema::DB_VERSION` (mai un valore letterale: le fasi successive alzeranno la versione) oppure `own_tracking` è falso, si esce senza scrivere nulla (succede anche durante la migrazione).
2. **DNT:** se `respect_dnt` è attivo e il parametro `dnt` vale `1` oppure l'header `DNT` vale `1`, scarto `dnt`. L'header si legge da `$_SERVER['HTTP_DNT']`, come `REMOTE_ADDR` e lo user agent, e non con `$request->get_header()`: `rest_do_request()` in verify non riempie gli header della richiesta. Se `respect_dnt` è spento e arriva solo `dnt=1`, si esce senza scrivere nulla (pagina in cache con l'opzione vecchia).
3. **Bot:** se `BotFilter::is_bot( $_SERVER['HTTP_USER_AGENT'] ?? '' )`, scarto `bot`.
4. **Firma:** `t` deve corrispondere a `^[a-z0-9_]{1,40}$` e `id` deve essere un intero ≥ 0; poi `Visit::check_signature()`. Se uno dei controlli fallisce, scarto `signature`.
5. **IP esclusi:** se `Visit::ip_excluded( REMOTE_ADDR, excluded_ips )`, scarto `ip`.
6. **Utente collegato:** come in D1, poi `Visit::user_excluded( exclude_users, $user_id > 0, user_can( $user_id, 'manage_options' ) )`. Se è escluso, scarto `user`.
7. **Chiave del giorno e hash:** vedi R6. `visitor = Visit::visitor_hash( REMOTE_ADDR, UA, chiave )`.
8. **Frequenza e visite del giorno:** `SELECT COUNT(*) AS today, COALESCE(SUM(ts >= %s), 0) AS recent FROM wp5w_hits WHERE visitor = UNHEX(%s)`, con `ts` limite pari ad adesso (UTC) meno 60 secondi. Se `recent >= 30`, scarto `rate`.
9. **Classificazione:** `host = Channels::host( r, host di home_url() )`, poi `Channels::classify( host, us, um )`, poi `is_entry = Visit::is_entry( today, host )`.
10. **Scrittura:** una `INSERT` in `wp5w_hits` con `UNHEX(%s)` per il visitore; URI e UTM sanificati con `sanitize_text_field()` e troncati alla lunghezza della colonna.

Gli scarti si registrano con `INSERT INTO wp5w_discards (day, reason, n) VALUES (%s, %s, 1) ON DUPLICATE KEY UPDATE n = n + 1`.

**Scritture per visita registrata:** una `SELECT` e una `INSERT`. Nessun `update_option`, `set_transient` o `set_site_transient`, a parte la rotazione giornaliera della chiave (R6). La rotta passa da `RestController::no_cache` come le altre.

### R4. Bot (`Support\BotFilter`)

`is_bot()` è vero se lo user agent, senza spazi iniziali e finali, è più corto di 16 caratteri, oppure se contiene (senza distinguere maiuscole e minuscole) una di queste firme: `bot`, `crawl`, `spider`, `slurp`, `facebookexternalhit`, `headless`, `lighthouse`, `pagespeed`, `gtmetrix`, `pingdom`, `uptime`, `monitor`, `preview`, `curl/`, `wget/`, `python-`, `go-http-client`, `java/`, `okhttp`, `axios`, `node-fetch`, `httpclient`, `scrapy`, `phantomjs`, `puppeteer`, `playwright`, `selenium`, `ahrefs`, `semrush`, `mj12`, `petalbot`, `bytespider`, `gptbot`, `ccbot`, `claudebot`, `perplexity`, `amazonbot`, `meta-externalagent`, `whatsapp`, `embedly`. L'elenco è una costante della classe, facile da estendere.

### R5. Utenti esclusi (`Visit::user_excluded`)

| Modalità | Anonimo | Collegato, non admin | Amministratore |
|---|---|---|---|
| `admins` (predefinita) | registra | registra | scarta |
| `logged_in` | registra | scarta | scarta |
| `none` | registra | registra | registra |

Per «amministratore» si intende `user_can( $id, 'manage_options' )`, lo stesso criterio delle rotte admin.

### R6. Chiave del giorno

1. Si legge `get_option( 'wp5w_daily_salt', '' )`. Se inizia con `wp_date( 'Y-m-d' ) . '|'`, si usa la parte dopo `|`.
2. Altrimenti `$new = $today . '|' . bin2hex( random_bytes( 32 ) )`:
   - se l'opzione non esiste, `add_option( 'wp5w_daily_salt', $new, '', true )`;
   - se esiste, `UPDATE {options} SET option_value = %s WHERE option_name = 'wp5w_daily_salt' AND option_value = %s` (il vecchio valore letto);
   - se `add_option` o l'`UPDATE` non hanno avuto effetto, un'altra richiesta ha vinto: si rilegge il valore con una `SELECT` diretta e si usa quello;
   - si invalidano `wp_cache_delete( 'alloptions', 'options' )` e `wp_cache_delete( 'wp5w_daily_salt', 'options' )`.
3. La chiave del giorno prima viene sovrascritta e non resta da nessuna parte.

### R7. Ingresso (`Visit::is_entry`)

`is_entry` è vero se il visitatore non ha altre visite nel giorno (`today === 0`) oppure se `referrer_host` non è vuoto. `Channels::host()` restituisce già una stringa vuota per i referrer interni.

### R8. Canali (`Support\Channels`)

`host( $referrer, $site_host )` restituisce l'host di `wp_parse_url` / `parse_url` in minuscolo, senza `www.` iniziale. Restituisce una stringa vuota se il referrer è vuoto o non analizzabile, oppure se l'host coincide con `$site_host` (anch'esso senza `www.`). `android-app://com.linkedin.android/` dà `com.linkedin.android`.

`classify( $host, $utm_source, $utm_medium )` prova le regole in quest'ordine:

1. `utm_medium` vale `email` o `newsletter` (senza distinguere maiuscole e minuscole): `email`, con sorgente `utm_source` oppure `Email`.
2. L'host è un motore di ricerca: `search`, con il nome. Gli host riconosciuti sono `google.<tld>` (anche `google.co.uk` e `google.com.br`, ma non `mail.google.com` né `docs.google.com`), `bing.com`, `duckduckgo.com`, `search.yahoo.com` e `yahoo.com`, `search.brave.com`, `chatgpt.com`, `ecosia.org`, `qwant.com`, `yandex.<tld>` e `baidu.com`.
3. L'host è un social: `social`, con il nome. Facebook: `facebook.com`, `m.facebook.com`, `l.facebook.com`, `lm.facebook.com`. LinkedIn: `linkedin.com`, `lnkd.in`, `com.linkedin.android`. Instagram: `instagram.com`, `l.instagram.com`. Twitter: `t.co`, `x.com`, `twitter.com`. Reddit, Pinterest, YouTube.
4. L'host non è vuoto: `referral`, con sorgente uguale all'host.
5. L'host è vuoto ma c'è `utm_source`: `unassigned`, con sorgente `utm_source`.
6. Negli altri casi: `direct`, con sorgente `Direct`.

Prima di scrivere le costanti, Sonnet confronta i nomi delle sorgenti con `SELECT DISTINCT source_channel, source_name FROM wp_statistics_visitor`, sul dump o con la tabella di D17.

### R9. Aggregazione (`Sources\OwnSource`)

- `id()` è `own`, `label()` è «Raccolta propria» e `version()` restituisce `WP5W_VERSION` se `own_tracking` è attivo, altrimenti `null`. `required()` richiede `wp5w_hits` con le colonne usate (`table()` aggiunge `$wpdb->prefix`, quindi il nome breve è `wp5w_hits`).
- `import( DateRange )`:
  - prende `GET_LOCK( 'wp5w_own_aggregate', 30 )`; se non ci riesce lancia `RuntimeException( 'Aggregazione della raccolta propria già in corso' )`;
  - restituisce le righe con chiavi prefissate (`own:site_daily`, eccetera), perché `Importer::run()` unisce gli array con `array_merge` e le chiavi senza prefisso sovrascriverebbero quelle di WP Statistics;
  - rilascia il lock in un `finally`.
- Intervallo disponibile: `data_range( 'wp5w_hits', 'day' )`. I giorni che non sono più in `wp5w_hits` non si toccano.
- Ogni tabella passa da `replace_by_month( ..., " AND origin = 'own'" )`. La condizione è letterale, senza segnaposto, perché `month_args()` conta i `%s` a coppie. Tutte le `SELECT` scrivono `'own'` nella colonna `origin`:

| Tabella | Regola |
|---|---|
| `site_daily` | `visitors = COUNT(DISTINCT visitor)`, `views = COUNT(*)`, per `day` |
| `object_daily` | per `day, object_type, object_id`: `uri = MIN(uri)`, `views = COUNT(*)`, `visitors = COUNT(DISTINCT visitor)` |
| `entry_daily` | solo `is_entry = 1`, per `day, object_type, object_id, COALESCE(NULLIF(channel, ''), 'unassigned')`: `COUNT(DISTINCT visitor)` |
| `referrer_daily` | solo `is_entry = 1 AND referrer_host <> ''`, per `day, object_type, object_id, referrer_host`: `MAX(channel)`, `MAX(source)`, `COUNT(DISTINCT visitor)` |
| `dim_daily` | un visitatore per giorno, con la prima visita del giorno (`MIN(id)` per `day, visitor`): `channel` (valore `channel`) e `source` (valore `COALESCE(NULLIF(source, ''), '(n/d)')`), entrambi con `COUNT(*)`, uniti con `UNION ALL` |

- `run_hourly()` aggrega da ieri a oggi (`wp_date`), cattura le eccezioni e le scrive con `error_log`. Non tocca `wp5w_etl_state`.
- L'ETL notturno esistente (`Importer` con `DAILY_DAYS = 7`) la include perché è tra le sorgenti, quindi chiude ieri e corregge gli ultimi 7 giorni.

### R10. WP Statistics scrive solo righe `wps`

- `import_site()`: le tre `INSERT ... ON DUPLICATE KEY UPDATE` elencano `origin` tra le colonne, con il valore `'wps'` nella `SELECT`. La chiave duplicata è quindi `(day, 'wps')` e le righe `own` non vengono mai toccate.
- `import_objects()`, `import_entries()`, `import_referrers()` e `import_dimensions()`: `replace_by_month( ..., " AND origin = 'wps'" )`, con `origin` nelle colonne e `'wps'` nella `SELECT`.
- `RankMathSource` e `GscFileImport` non cambiano.

### R11. Opzioni

- `GET /wp5w/v1/admin/settings` restituisce `{ own_tracking, exclude_users, excluded_ips, respect_dnt, retention_days, own_since, own_first_day }`, dove `own_first_day` è il primo giorno con righe `own` in `site_daily`, oppure `null`. Il client lo usa per il testo d'aiuto e come `min` della data.
- `POST /wp5w/v1/admin/settings` ha `args` con tipi, `enum` per `exclude_users`, `minimum` e `maximum` per `retention_days`, e `validate_callback` per:
  - `excluded_ips`: un array di stringhe; ogni elemento deve superare `Visit::is_valid_ip_rule()`, altrimenti la risposta è `400` con l'elenco delle righe non valide;
  - `own_since`: vedi D20.

  Salva con `update_option( Settings::OPTION, ... )`, che passa dal `sanitize_callback` di `register_setting`, e con `update_option( Settings::OWN_SINCE, ... )`. Esegue D21 e restituisce le impostazioni come la `GET`. Entrambe le rotte usano `check_admin_permission`.
- Scheda «Opzioni» (`OptionsPage.jsx`), con i componenti di `@wordpress/components` e la grafica delle altre schede (`Card`). Una card per gruppo:
  - **Raccolta propria:** `ToggleControl`.
  - **Utenti collegati:** `RadioControl` con «Escludi gli amministratori», «Escludi tutti gli utenti collegati» e «Non escludere nessuno».
  - **IP esclusi:** `TextareaControl`, una regola per riga; l'aiuto cita CIDR (`192.168.1.0/24`).
  - **Rispetta Do Not Track:** `ToggleControl`.
  - **Conservazione delle visite grezze:** numero, in giorni; l'aiuto dice che la pulizia automatica arriverà con il passaggio alla raccolta propria.
  - **Origine dei dati:** data, vuota. L'aiuto spiega che finché è vuota tutte le schede usano WP Statistics.
  - Pulsante «Salva», e `Notice` per l'esito e per gli errori `400`.

### R12. Cron

| Hook | Ricorrenza | Azione |
|---|---|---|
| `wp5w_etl_daily` (esiste già) | ogni giorno alle 4:00 | `Importer` con le tre sorgenti, ultimi 7 giorni |
| `wp5w_own_hourly` (nuovo) | `hourly` | `OwnSource::run_hourly()` |

Tutti e due si pianificano in `Scheduler::register()` se mancano, e si tolgono in `Deactivator` e in `uninstall.php`.

---

## 5. Test PHPUnit da aggiungere (`tests/Unit`)

| Test | Casi minimi |
|---|---|
| `OriginTest` | stringa vuota → `origin = 'wps'`; alias `o` → `o.origin = 'wps'`; data valida → frammento con `<` e `>=` e la data letterale; `2026-02-30`, `2026-13-01` e `x' OR 1=1 --` → solo `wps`, senza la stringa ricevuta nel risultato |
| `BotFilterTest` | `Googlebot/2.1`, `Mozilla/5.0 (compatible; bingbot/2.0; ...)`, `curl/8.4.0`, `python-requests/2.31`, `HeadlessChrome/120`, `GPTBot/1.0`, `facebookexternalhit/1.1`, `''`, `'Mozilla/5.0'` → bot; Chrome 129 su Windows, Safari su iPhone, Firefox su Android, Edge → non bot |
| `ChannelsTest` | `host()`: referrer vuoto, `https://www.Google.it/` → `google.it`, referrer interno → vuoto, `android-app://com.linkedin.android/` → `com.linkedin.android`, `not a url` → vuoto. `classify()`: vuoto → `direct`/`Direct`; `google.com` e `google.co.uk` → `search`/`Google`; `mail.google.com` → `referral`; `bing.com`, `duckduckgo.com`, `chatgpt.com` → `search`; `lm.facebook.com` → `social`/`Facebook`; `lnkd.in` e `com.linkedin.android` → `social`/`LinkedIn`; `example.org` → `referral`/`example.org`; `utm_medium=email` con referrer Google → `email`; host vuoto con `utm_source=newsletter-ott` → `unassigned` |
| `VisitTest` | `visitor_hash`: 16 byte, deterministico, cambia se cambiano IP, UA o chiave. `signature`/`check_signature`: valida → vero; tipo, ID o chiave diversi → falso; firma vuota → falso. `user_excluded`: le 9 combinazioni di R5. `is_valid_ip_rule`: `203.0.113.7`, `203.0.113.0/24`, `2001:db8::1`, `2001:db8::/64` → vero; `203.0.113.0/33`, `999.1.1.1`, `abc`, `''` → falso. `ip_excluded`: IP singolo, `/24` dentro e fuori, `/32`, `/0`, IPv6 `/64` dentro e fuori, IPv4 contro una regola IPv6 → falso, regole non valide ignorate. `is_entry`: `(0, '')` → vero, `(3, '')` → falso, `(3, 'google.com')` → vero |

`composer ci` (PHPCS, PHPStan livello 6 e PHPUnit) deve passare senza nuove esclusioni globali. Le `phpcs:ignore` puntuali vanno motivate, come nel codice esistente.

---

## 6. Controlli da aggiungere a `tests/fixtures/verify.php`

`verify.php` resta un unico script e usa la closure `$check` che c'è già. Ordine:

1. **Schema v4:** `wp5w_db_version` vale `Schema::DB_VERSION` (`4`); per le cinque tabelle, `SHOW KEYS ... PRIMARY` corrisponde alla tabella di sezione 3; `wp5w_hits` e `wp5w_discards` esistono; `wp5w_hits` non ha colonne il cui nome contiene `ip` o `agent`.
2. **Migrazione da v3:** si eliminano le cinque tabelle e si ricreano con le definizioni v3 (copiate nel test), con una riga ciascuna; poi `update_option( 'wp5w_db_version', '3' )`.
   - **Concorrenza:** una seconda connessione `mysqli` (`DB_HOST`, `DB_USER`, `DB_PASSWORD` e `DB_NAME`) prende `GET_LOCK( 'wp5w_schema_upgrade', 0 )`. Con il lock occupato, `Schema::maybe_upgrade()` lascia la versione a `3` e le chiavi come prima.
   - **Migrazione:** si rilascia il lock e si chiama di nuovo `maybe_upgrade()`. La versione diventa `4`, le chiavi sono quelle della v4 e le righe esistenti hanno `origin = 'wps'`.
   - **Idempotenza:** `Schema::install()` rieseguito lascia `$wpdb->last_error` vuoto e `SHOW CREATE TABLE` identico per le cinque tabelle.
3. **Controlli esistenti:** si svuotano anche `wp5w_hits` e `wp5w_discards`. Tutte le somme sulle tabelle aggregate filtrano `origin = 'wps'`. L'istantanea `CHECKSUM TABLE` dell'idempotenza resta com'è.
4. **Istantanea dell'interfaccia (prima):** con `wp5w_own_since` vuota si salva il `wp_json_encode()` dei dati delle schede:
   - `OverviewQuery::get()` e `top()` (con `all` e `post`), sugli ultimi 30 giorni;
   - `PostsQuery::latest()`, `top( 7 )`, `top( 30 )` e `detail()` del primo articolo del seed, sugli ultimi 60 giorni;
   - `AudienceQuery::get()`;
   - `TrendQuery::site()` e `objects()` sugli articoli del seed;
   - `OverviewQuery::coverage()`.
5. **Visite simulate** con `rest_do_request()` su `POST /wp5w/v1/hit`, con `$_SERVER['REMOTE_ADDR']` e `$_SERVER['HTTP_USER_AGENT']` impostati prima di ogni richiesta (IP diversi tra i casi, così il limite di frequenza non scatta per sbaglio). La firma si ottiene da `Tracker::signature()`. Ogni risposta deve avere stato `204`.
   - Visita valida, con UA Chrome e referrer `https://www.google.com/`: una riga, `is_entry = 1`, canale `search`, sorgente `Google`.
   - Seconda visita dello stesso visitatore su un altro articolo, con referrer interno: `is_entry = 0`, `referrer_host` vuoto.
   - UA `Googlebot` e UA vuoto: nessuna riga, `bot` sale di 2.
   - Firma errata e `t` non valido: `signature` sale di 2.
   - IP `203.0.113.9` con la regola `203.0.113.0/24`: scarto `ip`.
   - Amministratore con modalità `admins`: `$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $admin_id, time() + HOUR_IN_SECONDS, 'logged_in' )` e `wp_set_current_user( 0 )`, per riprodurre la REST senza nonce. Risultato: scarto `user` e nessuna riga.
   - Stesso amministratore con modalità `none`: la visita viene registrata.
   - Un abbonato (subscriber) creato dal test, con la modalità `logged_in`, viene scartato; con la modalità `admins` viene registrato. Alla fine si toglie il cookie.
   - `dnt=1` con `respect_dnt` attivo: scarto `dnt`. Header `DNT: 1` (`$_SERVER['HTTP_DNT']`) con payload completo: scarto `dnt`. Con `respect_dnt` spento, la stessa visita viene registrata.
   - Con `own_tracking` spento: `204`, nessuna riga e nessuno scarto.
   - **Frequenza:** 31 visite dallo stesso IP e UA. Risultato: 30 righe e lo scarto `rate` sale di 1.
   - **Chiave del giorno:** dopo le visite, `wp5w_daily_salt` inizia con la data di oggi. Si imposta a mano una chiave di ieri; la visita successiva la rigenera, e la vecchia non è più presente.
   - **Opzioni non toccate:** nessuna riga `_transient_wp5w%` o `_transient_timeout_wp5w%` in `wp_options`.
6. **Aggregazione:** `( new OwnSource() )->run_hourly()`, poi, per il giorno di oggi e `origin = 'own'`:
   - in `site_daily`, `visitors` vale `COUNT(DISTINCT visitor)` e `views` vale `COUNT(*)` di `wp5w_hits`;
   - la somma di `object_daily.views` vale `COUNT(*)`;
   - la somma di `entry_daily.visitors` vale `COUNT(DISTINCT day, object_type, object_id, channel, visitor)` delle righe con `is_entry = 1`;
   - la somma di `dim_daily.visitors` con `dim = 'channel'` è uguale a `site_daily.visitors`;
   - un secondo `run_hourly()` lascia invariato `CHECKSUM TABLE` delle cinque tabelle.
7. **Non regressione:** con righe `own` presenti e `wp5w_own_since` vuota, ogni JSON del punto 4 è **identico** a prima.
8. **Passaggio simulato:** con `update_option( 'wp5w_own_since', <oggi> )`, `OverviewQuery::get()` sugli ultimi 7 giorni ha `kpis.visitors.value` uguale alla somma delle righe `wps` prima di oggi più la riga `own` di oggi. `POST /admin/settings` con una data futura risponde `400`. Poi si rimette `wp5w_own_since` vuota.
9. **ETL di WP Statistics:** un nuovo import completo lascia invariati numero e somme delle righe `own` in tutte e cinque le tabelle.
10. **Pulizia finale:** si ripristinano i valori predefiniti delle impostazioni, si svuotano `wp5w_hits` e `wp5w_discards`, si cancellano le righe `own` e l'utente creato.

---

## 7. Criteri di accettazione

Ciascuno deve comparire nella checklist della PR con l'esito.

1. `composer ci` passa: PHPCS, PHPStan livello 6 e PHPUnit, con i quattro nuovi test.
2. `npm run check` passa; `build/tracker/tracker.js` esiste e pesa meno di 1.536 byte.
3. `npx wp-env run cli wp eval-file .../seed.php` seguito da `verify.php` termina con «Tutti i controlli superati», compresi tutti i controlli della sezione 6.
4. Su un'installazione con lo schema v3 e dati, il primo caricamento del plugin porta `wp5w_db_version` a `Schema::DB_VERSION` (`4`), mette le chiavi primarie della sezione 3 e assegna `origin = 'wps'` a tutte le righe esistenti; rieseguire `Schema::install()` non esegue `ALTER` (verify, punto 2).
5. Con il lock `wp5w_schema_upgrade` occupato, la migrazione non parte e la versione resta `3` (verify, punto 2).
6. Con `wp5w_own_since` vuota e righe `own` presenti, le risposte di Panoramica, classifica, Articoli, dettaglio, Visitatori, trend e coverage sono identiche a quelle senza righe `own` (verify, punto 7).
7. Con `wp5w_own_since` impostata, le query usano `wps` prima della data e `own` dalla data in poi (verify, punto 8 e `OriginTest`).
8. L'ETL di WP Statistics non cancella e non modifica righe `own` (verify, punto 9), e l'aggregazione non tocca le righe `wps` (`replace_by_month` con `origin = 'own'`).
9. Ogni lettura delle cinque tabelle aggregate in `src/Query/` passa da `Origin::where()`. Il reviewer lo verifica con il `grep` di R1.
10. `POST /wp5w/v1/hit` risponde `204` in tutti i casi della sezione 6, punto 5, e scrive righe o scarti come descritto lì.
11. Una visita da amministratore, con il solo cookie di login e senza nonce, viene scartata come `user` nella modalità predefinita e registrata con «non escludere nessuno».
12. Per una visita registrata l'endpoint esegue una `SELECT` e una `INSERT` sulle tabelle del plugin e non scrive in `wp_options`, tranne la rotazione giornaliera della chiave. Il reviewer lo verifica nel codice di `HitController`; verify controlla l'assenza di transient.
13. `wp5w_hits` non contiene IP né user agent, in nessuna forma; la chiave del giorno precedente non resta salvata.
14. Il tracker non usa cookie né `localStorage`, non parte con `navigator.webdriver`, e con DNT (opzione attiva) invia solo `dnt=1`.
15. Nel frontend il blocco `#wp5w-hit` e lo script in `defer` ci sono quando la raccolta è attiva, e mancano in anteprima, nel Customizer, nei feed e quando la raccolta è disattivata. Si verifica in wp-env con `curl` sulla home e su un articolo.
16. La scheda Opzioni carica e salva tutti i campi. IP non validi o una data `own_since` non ammessa restituiscono `400` con un messaggio chiaro, e i valori salvati sopravvivono al ricaricamento della pagina.
17. Il cron `wp5w_own_hourly` è pianificato dopo l'attivazione e tolto alla disattivazione; `wp 5w etl` e il pulsante «Aggiorna gli ultimi 7 giorni» aggregano anche la raccolta propria, e lo stato mostra le righe `own:*` senza sovrascrivere quelle di WP Statistics.
18. La scheda Stato elenca la sorgente «Raccolta propria» e, per il resto, mostra gli stessi numeri della versione 0.6.0 sugli stessi dati.
19. `CHANGELOG.md` (0.7.0) e `CLAUDE.md` sono aggiornati; `uninstall.php` e `Deactivator` coprono le nuove tabelle, opzioni e hook.
20. Dopo il deploy sul blog, tutti i passi del runbook qui sotto sono verdi, compreso il gate dei tempi: la mediana dell'endpoint non supera di oltre il 20% quella di una `POST` a vuoto all'endpoint di WP Statistics (stesso bootstrap REST, non in cache).

---

## 8. Runbook di deploy

Blog: `ssh mavida-blog`, cartella `~/domains/mavida.com/public_html/maurizio`, prefisso delle tabelle `maurizio_`. Tutti i comandi WP-CLI usano `--url=https://maurizio.mavida.com`, qui abbreviato in `$WP`:

```bash
cd ~/domains/mavida.com/public_html/maurizio
WP="wp --url=https://maurizio.mavida.com"
```

### 8.1 Riferimento pre-deploy

Istantanea presa sul blog prima della fase 1, con la versione 0.6.0 e lo schema v3. L'intervallo **2026-09-14 → 2026-10-12** è stato ricavato confrontando i totali con il blog (29 righe in `site_daily`).

| Tabella | Righe | Totali |
|---|---|---|
| `site_daily` | 29 | visitatori 3.532, visualizzazioni 4.659 |
| `object_daily` | 576 | visualizzazioni 4.660, visitatori 4.111 |
| `entry_daily` | 371 | |
| `referrer_daily` | 186 | |
| `dim_daily` | 1.110 | |

### 8.2 Backup

```bash
mkdir -p ~/backups
TABLES=$($WP db tables 'maurizio_wp5w_*' --all-tables --format=csv)
$WP db export ~/backups/wp5w-pre-0.7.0-$(date +%F-%H%M).sql --tables="$TABLES"
$WP option list --search='wp5w_*' --format=json > ~/backups/wp5w-options-pre-0.7.0.json
$WP plugin get wp-5w --field=version   # deve essere 0.6.0
# Cattura di non regressione subito prima dell'installazione (stesse query di 8.4, senza filtro sull'origine).
{
  $WP db query "SELECT COUNT(*), SUM(visitors), SUM(views) FROM maurizio_wp5w_site_daily WHERE day BETWEEN '2026-09-14' AND '2026-10-12'"
  $WP db query "SELECT COUNT(*), SUM(views), SUM(visitors) FROM maurizio_wp5w_object_daily WHERE day BETWEEN '2026-09-14' AND '2026-10-12'"
  for t in entry_daily referrer_daily dim_daily; do
    $WP db query "SELECT '$t', COUNT(*) FROM maurizio_wp5w_$t WHERE day BETWEEN '2026-09-14' AND '2026-10-12'"
  done
} > ~/backups/wp5w-nonreg-pre-0.7.0.txt
cat ~/backups/wp5w-nonreg-pre-0.7.0.txt
```

Tra la cattura e l'installazione non deve girare l'ETL: si evita di farle a cavallo delle 4:00.

### 8.3 Installazione e migrazione

```bash
$WP plugin install https://github.com/mavidasnc/wp-5w/releases/download/v0.7.0/wp-5w-0.7.0.zip --force
$WP plugin get wp-5w --field=version                       # 0.7.0
$WP eval 'Mavida\FiveW\Database\Schema::maybe_upgrade(); echo get_option("wp5w_db_version"), "\n";'   # 4
for t in site_daily object_daily entry_daily referrer_daily dim_daily; do
  $WP db query "SELECT '$t' t, GROUP_CONCAT(Column_name ORDER BY Seq_in_index) pk FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'maurizio_wp5w_$t' AND index_name = 'PRIMARY'"
done                                                        # ogni chiave termina con ,origin
$WP db query "SHOW CREATE TABLE maurizio_wp5w_hits\G"
$WP db query "SHOW CREATE TABLE maurizio_wp5w_discards\G"
for t in site_daily object_daily entry_daily referrer_daily dim_daily; do
  $WP db query "SELECT '$t' t, origin, COUNT(*) FROM maurizio_wp5w_$t GROUP BY origin"
done                                                        # solo wps, con i conteggi di prima
```

Se la versione non è `4`, si legge l'errore nel log di PHP (`grep wp5w ~/domains/mavida.com/public_html/maurizio/wp-content/debug.log` o il log degli errori di Hostinger) e si va al rollback.

### 8.4 Non regressione

```bash
$WP db query "SELECT COUNT(*), SUM(visitors), SUM(views) FROM maurizio_wp5w_site_daily WHERE origin='wps' AND day BETWEEN '2026-09-14' AND '2026-10-12'"
# atteso: come nella cattura di 8.2 (riferimento in 8.1: 29, 3532, 4659)
$WP db query "SELECT COUNT(*), SUM(views), SUM(visitors) FROM maurizio_wp5w_object_daily WHERE origin='wps' AND day BETWEEN '2026-09-14' AND '2026-10-12'"
# atteso: come nella cattura di 8.2 (riferimento: 576, 4660, 4111)
for t in entry_daily referrer_daily dim_daily; do
  $WP db query "SELECT '$t', COUNT(*) FROM maurizio_wp5w_$t WHERE origin='wps' AND day BETWEEN '2026-09-14' AND '2026-10-12'"
done
# atteso: come nella cattura di 8.2 (riferimento: 371, 186, 1110)
$WP eval '$r = ( new Mavida\FiveW\Query\OverviewQuery() )->get( new Mavida\FiveW\Support\DateRange( "2026-09-14", "2026-10-12" ) ); echo $r["kpis"]["visitors"]["value"], " ", $r["kpis"]["views"]["value"], "\n";'
# atteso: visitatori e visualizzazioni di site_daily nella cattura di 8.2 (passa dalla regola dell'origine sul database di produzione)
$WP option get wp5w_own_since    # vuota oppure assente
```

Il confronto vincolante è con la cattura `~/backups/wp5w-nonreg-pre-0.7.0.txt` di 8.2, fatta subito prima dell'installazione: i valori devono essere **identici**. La tabella di 8.1 serve solo da riferimento, perché l'ETL notturno rielabora gli ultimi 7 giorni e può aver cambiato di poco la fine dell'intervallo. Una differenza rispetto alla cattura porta al rollback.

### 8.5 Cron e cache

```bash
$WP cron event list --fields=hook,next_run_relative,recurrence | grep wp5w   # wp5w_etl_daily (daily) e wp5w_own_hourly (hourly)
$WP litespeed-purge all                                                      # TTL pubblico di 7 giorni: senza purge il tracker non compare
POST_URL=$($WP post list --post_type=post --post_status=publish --posts_per_page=1 --field=url)
curl -s "$POST_URL" -o /dev/null; curl -sI "$POST_URL" | grep -i x-litespeed-cache   # alla seconda richiesta: hit
curl -s "$POST_URL" | grep -o 'id="wp5w-hit"[^<]*\|build/tracker/tracker[^"]*'      # blocco JSON e script presenti nella pagina in cache
```

### 8.6 Smoke test da Chrome

1. **Visitatore anonimo** (finestra in incognito): si apre `$POST_URL`. Nella scheda Network dell'articolo l'header è `x-litespeed-cache: hit`, e `POST /wp-json/wp5w/v1/hit` risponde `204`. Poi:
   ```bash
   $WP db query "SELECT id, ts, day, object_type, object_id, uri, referrer_host, channel, source, is_entry FROM maurizio_wp5w_hits ORDER BY id DESC LIMIT 3"
   ```
   C'è una riga con il tipo `post`, l'ID e l'URI dell'articolo, e `is_entry = 1`.
2. **Amministratore collegato** (finestra normale): si apre lo stesso articolo. Il beacon risponde `204`, nessuna riga nuova in `wp5w_hits`, e `SELECT * FROM maurizio_wp5w_discards WHERE day = CURDATE()` mostra `user` aumentato di 1.
3. **Bot:**
   ```bash
   ID=$($WP post list --post_type=post --post_status=publish --posts_per_page=1 --field=ID)
   SIG=$($WP eval "echo Mavida\\FiveW\\Front\\Tracker::signature( 'post', $ID );")
   echo "$ID $SIG"   # servono anche in 8.7, dove si eseguono dal PC locale
   curl -s -o /dev/null -w "%{http_code}\n" -A "Googlebot/2.1 (+http://www.google.com/bot.html)" -d "t=post&id=$ID&s=$SIG&u=/wp5w-smoke-test/" https://maurizio.mavida.com/wp-json/wp5w/v1/hit
   ```
   Risponde `204`, nessuna riga, `bot` aumentato di 1.
4. **Scheda Opzioni:** in wp-admin, 5W, Opzioni, ci sono i valori predefiniti; «Salva» senza modifiche dà l'avviso di successo.

### 8.7 Gate dei tempi (scostamento della roadmap, riferimento corretto il 2026-10-14)

Il primo riferimento, `GET /wp-json/`, era sbagliato: viene servita dalla cache di LiteSpeed (circa 0,12-0,18 s), mentre ogni `POST` REST non è cacheabile e costa il bootstrap completo di WordPress (circa 0,41-0,45 s sul blog). Il riferimento equivalente è una `POST` a vuoto all'endpoint di WP Statistics, che viene rifiutata subito e non scrive nulla.

Dal PC locale, 7 coppie alternate. Ogni coppia è una visita valida (UA di un browser, firma valida, URI marcato) e una `POST` a vuoto a WP Statistics. Si confrontano le mediane di `time_total`:

```bash
UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36"
for i in 1 2 3 4 5 6 7; do
  curl -s -o /dev/null -w "hit %{time_total}
" -A "$UA" -d "t=post&id=<ID>&s=<SIG>&u=/wp5w-smoke-test/" https://maurizio.mavida.com/wp-json/wp5w/v1/hit
  curl -s -o /dev/null -w "wps %{time_total}
" -A "$UA" -X POST -H 'Content-Type: application/json' -d '{}' https://maurizio.mavida.com/wp-json/wp-statistics/v2/hit
done
```

- **Esito:** la mediana di `hit` non deve superare di oltre il 20% quella di `wps`. Le 7 visite restano sotto il limite di frequenza, quindi si misura il percorso completo, con `SELECT` e `INSERT`.
- **Da riportare, senza gate:** la mediana di un file statico (per esempio `/wp-includes/images/blank.gif`, circa 0,19 s: è il costo di rete e TLS) e la mediana di `GET /wp-json/`. La differenza tra `hit` e il file statico è il costo del bootstrap di WordPress, su cui il plugin non può intervenire senza `SHORTINIT` (vedi la valutazione in `CHANGELOG.md` o nella PR #1).
- **Verifica:** `SELECT COUNT(*) FROM maurizio_wp5w_hits WHERE uri = '/wp5w-smoke-test/'` deve dare 7, cioè sono state registrate davvero.
- **Pulizia:** `DELETE FROM maurizio_wp5w_hits WHERE uri = '/wp5w-smoke-test/'`.

### 8.8 Aggregazione

```bash
$WP cron event run wp5w_own_hourly
$WP db query "SELECT origin, visitors, views FROM maurizio_wp5w_site_daily WHERE day = CURDATE()"   # una riga wps (se WP Statistics ha già dati di oggi) e una own
# Si ripete l'eval di 8.4: i numeri non cambiano.
```

### 8.9 Controllo di `REMOTE_ADDR` (dopo circa un'ora di traffico)

```bash
$WP db query "SELECT COUNT(DISTINCT visitor) own_visitors, COUNT(*) own_views FROM maurizio_wp5w_hits WHERE day = CURDATE()"
$WP db query "SELECT COUNT(*) wps_visitors FROM maurizio_statistics_visitor WHERE last_counter = CURDATE()"
$WP db query "SELECT reason, n FROM maurizio_wp5w_discards WHERE day = CURDATE()"
```

Se `own_visitors` resta a 1-2 mentre WP Statistics ne conta decine, oppure se lo scarto `rate` cresce in fretta, davanti al sito c'è un proxy e `REMOTE_ADDR` non è l'IP del client. In quel caso si spegne la raccolta (`own_tracking` = falso dalla scheda Opzioni) e si avvisa Maurizio, senza rollback. È normale che i numeri della raccolta propria siano un po' più bassi (bot e amministratori esclusi).

### 8.10 Rollback

Si fa se un passo da 8.3 a 8.8 fallisce. La versione 0.6.0 non filtra per origine: con righe `own` presenti, i numeri raddoppierebbero.

```bash
$WP plugin install https://github.com/mavidasnc/wp-5w/releases/download/v0.6.0/wp-5w-0.6.0.zip --force
$WP db import ~/backups/wp5w-pre-0.7.0-<data>.sql          # ricrea le sei tabelle v3 con i dati di prima
$WP db query "DROP TABLE IF EXISTS maurizio_wp5w_hits, maurizio_wp5w_discards"
$WP option update wp5w_db_version 3
$WP option delete wp5w_settings wp5w_own_since wp5w_daily_salt
$WP cron event delete wp5w_own_hourly
$WP litespeed-purge all                                    # toglie il tracker dalle pagine in cache
```

Poi si ripetono le query della cattura di 8.2, che devono dare gli stessi valori.

### 8.11 Nota di consegna per la fase 2

La nota (`handoff`) riporta:

- la data e l'ora del deploy, cioè l'inizio delle 2 settimane in parallelo;
- l'esito di 8.7, con le due mediane;
- i conteggi di 8.9;
- gli scarti per motivo del primo giorno;
- gli eventuali scostamenti da questa specifica.

---

## 9. Fuori da questa fase

- Interfaccia del confronto e righe `own` visibili nelle schede: fase 2.
- Paese, dispositivo, browser, sistema e lettore `.mmdb`: fase 3.
- Canale AI, box UTM, «online adesso», 404 e ricerche interne: fase 4.
- Applicazione della conservazione (pulizia di `wp5w_hits`), testo per la privacy policy e impostazione effettiva di `wp5w_own_since`: fase 5.
