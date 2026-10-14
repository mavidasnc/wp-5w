# wp-5w

Plugin WordPress (PHP 8.3, WPCS, namespace `Mavida\FiveW`, prefisso `wp5w`) che copia e aggrega in tabelle proprie i dati di WP Statistics e Rank Math Analytics e li mostra in una pagina React in wp-admin. Commenti, UI e documentazione in italiano. Modello di riferimento per tooling e struttura: `../wp-book-a-call`.

## Comandi

```bash
composer ci                      # PHPCS + PHPStan (level 6) + PHPUnit (test puri in tests/Unit)
npm run check                    # build + controllo dei file compilati
npm run release -- 0.2.0         # bump versione, commit, tag, push; GitHub Actions pubblica lo zip
```

### Test con wp-env (porte 8895/8896)

```bash
# Una tantum: WP Statistics e Rank Math in locale (gli zip URL in .wp-env.json non si estraggono con Node 26 su Windows).
mkdir -p .wp-env-plugins && for z in wp-statistics.14.16.15 seo-by-rank-math.1.0.280; do
  curl -sLo /tmp/$z.zip https://downloads.wordpress.org/plugin/$z.zip && unzip -qo /tmp/$z.zip -d .wp-env-plugins; done

npx wp-env start
npx wp-env run cli wp eval-file wp-content/plugins/wp-5w/tests/fixtures/seed.php     # dati sintetici
npx wp-env run cli wp eval-file wp-content/plugins/wp-5w/tests/fixtures/verify.php   # schema v4, ETL, raccolta propria, opzioni
```

### Recupero dello storico di Search Console

```bash
# In locale, con il token OAuth (scope webmasters.readonly) di maurizio.pelizzone@gmail.com:
python scripts/gsc-backfill.py --token "C:/Users/maurizio.MAVIDA/Claude/credenzials/google/maurizio.pelizzone@gmail.com/token-searchconsole.json"   --site https://maurizio.mavida.com/ --days 180 --out .wp-env-data/gsc-backfill.jsonl
scp .wp-env-data/gsc-backfill.jsonl mavida-blog:backups/
ssh mavida-blog 'cd ~/domains/mavida.com/public_html/maurizio && wp --url=https://maurizio.mavida.com 5w gsc-import ~/backups/gsc-backfill.jsonl'
```

Per provare sui dati reali: dump delle tabelle `maurizio_statistics_*` e `maurizio_rank_math_analytics_{gsc,objects}` dal blog, poi `sed 's/`maurizio_/`wp_/g'` in `.wp-env-data/` (gitignored), `wp db import` e `verify.php`.

## Struttura

- `src/Sources/`: `Source` (controllo dello schema, `replace_by_month`), `WpStatisticsSource`, `RankMathSource` e `OwnSource` (aggrega `wp5w_hits` in righe `origin = 'own'`, con `run_hourly()`).
- `src/Etl/`: `Importer` (lock, stato in `wp5w_etl_state`) e `Scheduler` (cron `wp5w_etl_daily` alle 4:00, `wp5w_own_hourly` ogni ora, `wp5w_etl_full` dopo l'attivazione).
- `src/Front/Tracker.php` (script e blocco JSON `#wp5w-hit` nel frontend), `src/REST/HitController.php` (`POST /wp5w/v1/hit`), `src/Settings.php` (opzioni `wp5w_settings`, `wp5w_own_since`) e `assets/tracker/tracker.js` (seconda entry di webpack, `build/tracker/tracker.js`, limite di 1.536 byte controllato da `check-build.js`).
- `src/Query/`: `CompareQuery` (sezione Confronto della scheda Stato, `GET /admin/compare`: l'unica che legge le due origini), `OverviewQuery` (Panoramica), `PostsQuery` (elenchi Articoli e dettaglio del modale), `SearchQuery` (opportunità e pagine della Panoramica sugli ultimi N giorni disponibili, query delle keyword), `TrendQuery` (trend del sito e dei contenuti; `AdminController` lo aggiunge alle risposte con `attach()`) e `AudienceQuery` (Visitatori) leggono solo le tabelle `wp5w_*`.
- `src/Support/`: `DateRange`, `Metrics`, `Series`, `Months`, `Trend`, `SchemaCheck`, `Origin`, `BotFilter`, `Channels`, `Visit` e `Comparison` (finestra, soglia, quote e classifica del confronto) sono classi pure senza guard ABSPATH, coperte da PHPUnit.
- `assets/admin/`: app React (`@wordpress/components` + Chart.js con react-chartjs-2). I box a riga intera usano `components/Card.jsx` (riducibile, stato in `localStorage` con chiave `wp5w-card-<id>`).

## Punti da ricordare

- **Mai cancellare dove la sorgente non ha più dati.** `replace_by_month` sostituisce solo l'intersezione con l'intervallo presente nella sorgente: le finestre mobili di WP Statistics e Rank Math (2 x 90 giorni nella versione free) non devono svuotare le tabelle `wp5w_*`.
- **Righe duplicate in `rank_math_analytics_gsc`:** si raggruppa per (giorno, pagina, query) con MAX. La colonna `created` è `ON UPDATE current_timestamp()`, quindi la tabella non va mai aggiornata.
- **`DATE(created)` dipende dal fuso della sessione MySQL.** Sul blog è +02:00, in wp-env UTC: un dump caricato in wp-env sposta i giorni GSC di uno.
- **`statistics_summary_totals` ha giorni a zero** (2017-2022 sul blog), che sono buchi di raccolta: non vanno importati.
- **`statistics_visitor.first_page` → `statistics_pages.page_id`:** verificato sul blog (10.614 su 10.616).
- **Visitatori sommati su più giorni:** una persona conta una volta per ogni giorno di visita (l'hash IP di WP Statistics cambia ogni giorno).
- **`statistics_visitor.referred` contiene solo l'host:** il referrer vale per la pagina d'ingresso.
- **Dettaglio:** `PostsQuery::detail()` accorcia il periodo al primo giorno con dati, così «dalla pubblicazione» su post vecchi non genera anni di zeri.
- **Origine delle righe GSC:** i giorni importati dall'API (`origin = 'api'`) hanno circa il doppio delle query di Rank Math; l'ETL di Rank Math li salta e cancella solo righe `rankmath` dei giorni che possiede (`replace_by_month` con il parametro `$delete`).
- **Collation diverse:** le tabelle di Rank Math sono `utf8mb4_general_ci`, quelle del plugin `utf8mb4_unicode_520_ci`. Niente JOIN su colonne di testo tra le due: si confronta in PHP o con gli hash MD5.
- **Trend:** finestre di settimane complete (lunedì-domenica): il traffico fino a ieri, Search Console fino all'ultimo giorno disponibile, quindi le due finestre possono differire. Formula, pesi e soglie sono in `Support\Trend` (coperta da `TrendTest`).
- **Confronti con il periodo precedente:** vanno mostrati solo se quel periodo è coperto dai dati (`SearchQuery` restituisce `comparable`), altrimenti escono variazioni del +9000%.
- **Regola dell'origine:** le cinque tabelle aggregate (`site_daily`, `object_daily`, `entry_daily`, `referrer_daily`, `dim_daily`) hanno `origin` (`wps` o `own`) nella chiave primaria. Ogni lettura in `src/Query/` aggiunge `Origin::where( Settings::own_since(), <alias> )` (anche nelle subquery e in `MIN(day)` e `coverage()`), ma non su `gsc_daily`: controllo con `grep -n "site_daily\|object_daily\|entry_daily\|referrer_daily\|dim_daily" src/Query`. **Unica eccezione:** `CompareQuery`, che legge di proposito `wps` e `own` insieme (è scritto in un commento della classe); il `grep` non deve trovare altre eccezioni. Le righe `wps` le scrive solo `WpStatisticsSource` e le `own` solo `OwnSource`, ognuna con `replace_by_month(..., " AND origin = '...'")`.
- **Finestra del Confronto:** il primo giorno `own` è sempre parziale e oggi è in corso, quindi si confrontano solo i giorni dal giorno dopo il primo `own`; ieri entra solo dopo l'ETL delle 4:00 (non oltre il giorno prima dell'ultimo ETL né oltre il suo `to`). Ogni giorno escluso è elencato con il motivo (`Support\Comparison::plan()`). Date calcolate in UTC su stringhe `Y-m-d`, così l'ora legale non salta né raddoppia giorni.
- **`statistics_visitor.UAString` è vuoto sul blog** (`store_ua` spento) e la raccolta propria non salva lo user agent: il confronto degli user agent non è possibile da nessuna delle due parti, si usano ingressi diretti sulla home, canali e scarti.
- **Migrazione dello schema:** `dbDelta` non cambia le chiavi primarie, quindi `Schema::install()` fa prima un `ALTER TABLE` esplicito sotto `GET_LOCK( 'wp5w_schema_upgrade', 0 )` e rilegge la versione dal database. Se il lock è occupato non tocca nulla, nemmeno `wp5w_db_version`.
- **L'hash del visitatore (`binary(16)`) passa sempre come esadecimale con `UNHEX(%s)`:** con byte grezzi `wpdb::query()` può togliere sequenze UTF-8 non valide e corrompere il valore.
- **Utente collegato letto dal cookie nell'endpoint:** `wp_validate_auth_cookie( $_COOKIE[ LOGGED_IN_COOKIE ], 'logged_in' )`, perché una REST senza `X-WP-Nonce` viene riportata all'utente 0 e le pagine in cache non hanno un nonce valido. Serve solo a scartare la visita.
- **`REMOTE_ADDR` e header `DNT` si leggono da `$_SERVER`,** mai da `X-Forwarded-For` (falsificabile) né da `$request->get_header()` (vuoto in `rest_do_request`).
- **Il blocco `#wp5w-hit` si stampa a mano,** non con `wp_print_inline_script_tag`: con temi senza supporto html5 per gli script WordPress avvolge il contenuto in CDATA e il JSON non è più valido.
- **Cache di LiteSpeed:** il TTL pubblico è di 7 giorni. Dopo il deploy, e quando cambiano `own_tracking` o `respect_dnt` (lo fa il salvataggio delle opzioni con `litespeed_purge_all`), le pagine in cache vanno svuotate; lo stesso se cambiano le salt di WordPress (la firma usa `wp_salt( 'auth' )`).
- **Chiave del giorno:** `wp5w_daily_salt` si rigenera alla prima visita del giorno con un `UPDATE` condizionato al vecchio valore, così due richieste simultanee non creano due chiavi (la prima creazione usa `INSERT IGNORE`, perché `add_option()` fa `ON DUPLICATE KEY UPDATE`). Il passaggio orario, la disattivazione e la disinstallazione la cancellano. Nessun transient in `wp_options` per le visite.
- **`navigator.webdriver` vale vero con Playwright e Puppeteer:** per provare il beacon in un browser automatico va sovrascritto con uno script di init e va impostato uno user agent da browser (altrimenti `BotFilter` scarta la visita).
- **Su Windows con Git Bash** usa `MSYS_NO_PATHCONV=1` per gli argomenti che iniziano con `/` (per esempio `wp rewrite structure`) e scrivi i JSON con backslash usando l'editor, non gli heredoc.
- **Blog di produzione** (`maurizio.mavida.com`): WP-CLI richiede `--url=https://maurizio.mavida.com`.
