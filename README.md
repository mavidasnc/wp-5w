# wp-5w

Dashboard di analytics in wp-admin che incrocia i dati già raccolti da **WP Statistics** (traffico) e da **Rank Math Analytics** (Google Search Console), senza servizi esterni. Dalla 0.7.0 raccoglie anche le visite da sé, senza cookie e senza salvare IP, in parallelo a WP Statistics (vedi [la roadmap](docs/roadmap-sostituzione-wp-statistics.md)): finché non si imposta la data di passaggio, l'interfaccia mostra solo i dati di WP Statistics.

Il nome richiama le 5W (who, what, when, where, why): il valore sta nel collegare le fonti per singolo contenuto, cioè quante visite riceve un articolo, da dove arrivano e con quali ricerche su Google.

## Funzionalità

- **Tabelle proprie e compatte** (`wp5w_*`), una riga per giorno e dimensione, senza dati personali.
- **ETL notturno idempotente**, che rielabora gli ultimi 7 giorni per coprire il ritardo di Search Console.
- **Import completo** all'attivazione e su richiesta.
- **Conservazione dello storico:** i giorni che WP Statistics e Rank Math cancellano con le loro finestre mobili restano nelle tabelle wp-5w.
- **Deduplica** delle righe di Search Console, che Rank Math può duplicare quando l'import viene rilanciato.
- **Controllo dello schema delle sorgenti:** se un aggiornamento cambia le tabelle, la sorgente viene saltata e il motivo compare nella pagina Stato.
- **Pagina 5W in wp-admin:**
  - **Panoramica:** KPI con confronto rispetto al periodo precedente, andamento di traffico e Search Console, canali, contenuti più visti con click, impression, CTR e posizione media pesata, opportunità di Search Console in un'unica tabella (quasi in prima pagina, poco cliccate) e pagine su Google, ciascuna con il proprio periodo (ultimi 7, 28, 90 o 180 giorni disponibili).
  - **Trend:** punteggio da -100 a +100 sulle ultime 4 settimane complete contro le 4 precedenti (visitatori 50%, visibilità su Google 25%, posizione media 25%), per il sito nella Panoramica e per ogni contenuto nelle tabelle.
  - **Articoli:** ultimi 10 pubblicati e 10 più visti (settimana o mese), con il grafico delle visite degli ultimi 7 giorni.
  - **Dettaglio in un modale** (clic sul titolo): KPI, andamento, ricerche su Google, canali e siti di provenienza, con periodo selezionabile.
  - **Visitatori:** ultimi 7 giorni e ultimi 6 mesi per mese (totali a barre e medie al giorno a linee), box «Pubblico» (paesi, dispositivi, browser e sistemi operativi degli ultimi 7, 30 o 90 giorni), provenienze e keyword degli ultimi 7 giorni.
  - I box a riga intera si riducono con un toggle, che resta memorizzato nel browser.
  - **Stato:** confronto della raccolta propria con WP Statistics (giorni completi, differenze, scarti, contenuti), sorgenti, copertura delle tabelle, database dei paesi, ultimo import, avvio manuale.
- **Raccolta propria (dalla 0.7.0):**
  - **Tracker** di meno di 1,5 KB, in `defer`, che funziona anche con le pagine in cache: invia la visita a `POST /wp5w/v1/hit` con `sendBeacon`. Nessun cookie, nessun `localStorage`, nessun IP o user agent salvato (il visitatore è un hash che cambia ogni giorno).
  - **Esclusioni:** bot e crawler, browser automatici, Do Not Track, amministratori (o tutti gli utenti collegati), IP e intervalli CIDR, oltre 30 visite al minuto per visitatore. Gli scarti si contano per giorno e motivo.
  - **Scheda Opzioni** per raccolta, utenti, IP, DNT, conservazione e data di passaggio (`wp5w_own_since`).
  - Le righe si distinguono con la colonna `origin` (`wps` o `own`) nelle tabelle aggregate.
  - **Paese, dispositivo, browser e sistema operativo (dalla 0.9.0):** il paese si ricava dall'IP con il database DB-IP Lite Country (l'IP non si salva); dispositivo, browser e sistema dallo user agent con regole essenziali, con gli stessi nomi di WP Statistics (`smartphone`, `Mac`, `GNU/Linux`...). Lo user agent non si salva.
- **Comandi WP-CLI:** `wp 5w status`, `wp 5w etl [--full] [--from=] [--to=]` e `wp 5w gsc-import <file.jsonl>`.
- **Recupero dello storico di Search Console** dall'API (fino a 16 mesi) con `scripts/gsc-backfill.py`: le credenziali Google restano sul computer, sul server arriva solo il file dei dati.
- **Aggiornamenti automatici** dalle release di GitHub.

## Tabelle

| Tabella | Contenuto | Sorgente |
|---|---|---|
| `wp5w_site_daily` | Visitatori e visualizzazioni del sito per giorno | `statistics_summary_totals`, completato con il dettaglio per i giorni non ancora riassunti |
| `wp5w_object_daily` | Visualizzazioni e visitatori unici per contenuto (post, pagina, allegato, home…) | `statistics_pages` e `statistics_visitor_relationships` |
| `wp5w_entry_daily` | Visitatori entrati da un contenuto, per canale | `statistics_visitor.first_page` → `statistics_pages` |
| `wp5w_referrer_daily` | Siti di provenienza (host) di chi entra da un contenuto | `statistics_visitor.referred` |
| `wp5w_dim_daily` | Visitatori per paese, device, browser, sistema, canale e sorgente | `statistics_visitor` |
| `wp5w_hits` | Visite grezze della raccolta propria (hash del visitatore, contenuto, URI, referrer, canale, UTM, ingresso, paese, dispositivo, browser e sistema): niente IP né user agent | Tracker del plugin |
| `wp5w_discards` | Visite scartate per giorno e motivo (`bot`, `user`, `dnt`, `rate`, `signature`, `ip`) | Endpoint `/hit` |
| `wp5w_gsc_daily` | Click, impression e posizione per giorno, pagina e query; `origin` rankmath o api | `rank_math_analytics_gsc` oppure l'API di Search Console (backfill), collegata ai contenuti tramite `rank_math_analytics_objects` o `url_to_postid()` |

Le cinque tabelle del traffico hanno la colonna `origin`: `wps` per le righe importate da WP Statistics, `own` per quelle della raccolta propria. Le interrogazioni usano `wps` prima di `wp5w_own_since` e `own` da quel giorno in poi (data vuota: solo `wps`).

La posizione media si calcola sempre come `SUM(pos_x_impr) / SUM(impressions)`, cioè pesata sulle impression come in Search Console.

## Database dei paesi

Il paese delle visite proprie si calcola con **DB-IP Lite Country**, un file `.mmdb` (lettore `maxmind-db/reader`, puro PHP) che il plugin scarica da solo:

- **Percorso:** `wp-content/uploads/wp5w/dbip-country-lite.mmdb`, con `.htaccess` e `index.php` di protezione.
- **Aggiornamento:** un controllo al giorno (`wp5w_geo_update`), che scarica il file del mese solo se manca o è di un mese precedente (prima il mese corrente, poi il precedente). Il file si controlla (integrità del gzip, dimensione, metadati, ricerca di prova) e si sostituisce in modo atomico; se qualcosa va storto resta quello vecchio. Lo stato è nella scheda Stato.
- **Licenza e attribuzione:** i dati sono [IP Geolocation by DB-IP](https://db-ip.com), con licenza [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/): il link è in fondo alla pagina 5W e nella card «Database dei paesi».
- **Il file si cancella sempre** alla disinstallazione (è una cache che si riscarica, non uno storico).

## Requisiti

- WordPress 6.5 o superiore, PHP 8.3 o superiore.
- WP Statistics 14.x e/o Rank Math con il modulo Analytics collegato a Search Console. Funziona anche con una sola delle due sorgenti.

## Installazione

Scarica lo zip dall'ultima [release](https://github.com/mavidasnc/wp-5w/releases) e caricalo da **Plugin › Aggiungi nuovo**, oppure:

```bash
wp plugin install https://github.com/mavidasnc/wp-5w/releases/latest/download/wp-5w-X.Y.Z.zip --activate
wp 5w etl --full
```

All'attivazione l'import completo parte da solo dopo un minuto; con WP-CLI lo si lancia subito.

## Disinstallazione

Le tabelle `wp5w_*` sono spesso l'unica copia dello storico, quindi la disinstallazione **non le cancella**. Per eliminarle (comprese `wp5w_hits` e `wp5w_discards`) e togliere le opzioni `wp5w_settings` e `wp5w_own_since`, definisci `WP5W_DELETE_DATA` a `true` in `wp-config.php` prima di disinstallare. L'opzione `wp5w_daily_salt` e il database dei paesi (opzione `wp5w_geo`, cartella `uploads/wp5w/`) si tolgono sempre.

## Sviluppo

```bash
composer install
npm install --ignore-scripts
npm run build          # build dell'admin React
composer ci            # PHPCS, PHPStan, PHPUnit
npm run check          # build + controllo dei file compilati
```

Il flusso dei test con wp-env è in [CLAUDE.md](CLAUDE.md). L'analisi che ha portato al progetto è in [docs/](docs/).
