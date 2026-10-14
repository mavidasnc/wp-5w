# wp-5w: fattibilità di una dashboard locale sui dati esistenti

Analisi del 2026-10-14. Si valuta un'alternativa all'architettura centralizzata del [analisi iniziale](analisi-iniziale.md): un plugin WordPress che **non raccoglie dati propri**, legge le tabelle già popolate da **WP Statistics** (traffico) e da **Rank Math Analytics** (Search Console), le aggrega con logiche diverse e le presenta in una dashboard dentro wp-admin.

Tutti i numeri sono stati verificati sul database di produzione di `maurizio.mavida.com` (prefisso `maurizio_`).

---

## 1. Esito in breve

**È fattibile, ma non come semplice lettura delle tabelle.** Entrambe le fonti sono **finestre mobili** che cancellano i dati vecchi:

| Fonte | Finestra | Cosa si perde |
|---|---|---|
| WP Statistics | Era 180 giorni ("Automatically Aggregate Old Data"). **Dal 2026-10-14 è impostata a "forever"** (`schedule_dbmaint_days` = 0, cron di pulizia rimosso) | Niente, da ora in poi. Il dettaglio prima del 17/04 era già stato aggregato |
| Rank Math Analytics (free) | Importa gli ultimi 90 giorni (`console_caching_control`, al massimo 90 nella versione free) e **cancella le righe più vecchie del doppio, cioè 180 giorni** (`DB::delete_data_log`) | Le righe GSC più vecchie di 180 giorni |

Prima della modifica, il 14/10 la prima data di dettaglio di WP Statistics era già passata dal 16/04 al 17/04: ogni giorno costava un giorno di storico per articolo.

WP Statistics ora conserva tutto, ma Rank Math continua a cancellare dopo 180 giorni. Quindi il cuore del plugin deve essere un **ETL notturno** che copia e aggrega i dati in tabelle proprie e compatte. La dashboard legge solo da quelle. Le tabelle degli altri plugin sono una sorgente da cui attingere, non il database della dashboard.

Il 2026-10-14 ho salvato un dump delle tabelle WP Statistics in `~/backups/wp-statistics.20261014.sql.gz` (0,9 MB).

---

## 2. Dati disponibili oggi

### WP Statistics 14.16.15

| Tabella | Contenuto | Copertura | Note |
|---|---|---|---|
| `statistics_summary_totals` | Visitatori e pageview **del sito**, per giorno | **Dal 2023** (2016-2022 tutto a zero) | L'unico storico lungo: trend del sito su 3 anni e mezzo |
| `statistics_pages` | Pageview per URI e giorno, con `id` del post e `type` | Dal 2026-04-17 (finestra di 180 gg) | Collegamento diretto a `post_id` |
| `statistics_visitor` | Un visitatore per giorno: sorgente (`source_channel`, `source_name`, `referred`), paese, città, device, browser, `first_page` / `last_page` | Dal 2026-04-17 | IP anonimizzati e con hash (`#hash#…`): privacy ok |
| `statistics_visitor_relationships` | Percorso visitatore → pagine, con orario | Dal 2026-04-17 | Permette pagine di ingresso e percorsi |
| `statistics_historical` | Totali per URI **senza data** (residuo delle pulizie) | Cumulativo | Utile solo come "totale di sempre" per articolo |
| `statistics_events` | Eventi personalizzati | Vuota | |

Volumi: circa 27 MB in tutto, 58 visitatori e 98 pageview al giorno in media, 10.603 visitatori in 6 mesi.

Sorgenti negli ultimi 6 mesi: direct 6.354, search 3.114 (Google 2.889, Bing 83, DuckDuckGo 66, Brave 38), social 838 (Facebook 727, LinkedIn 80).

Pageview per tipo: post 6.171, home 4.931, **attachment 3.774 (circa 21%)**, page 1.868, tag 508, category 241, 404 101.

Le visite agli allegati sono quasi tutte sulle **pagine paywall di `wp-paid-attachments`**, in particolare le immagini del calendario Giorgia Palmas. Gli allegati non protetti vengono già reindirizzati al post genitore con un 301. Non sono rumore: sono il contenuto commerciale del blog.

### Rank Math 1.0.280 (free), modulo Analytics

| Tabella | Contenuto | Note |
|---|---|---|
| `rank_math_analytics_gsc` | Per giorno: `page` (percorso relativo), `query`, `clicks`, `impressions`, `position`, `ctr` | 106 giorni importati (17/04-02/05 e 01/07-11/10), 76 pagine, 884 query |
| `rank_math_analytics_objects` | `page` → `object_id`, tipo, titolo, punteggio SEO, indicizzabilità | È la tabella ponte tra URL GSC e `post_id` |
| `rank_math_internal_meta` | Link interni in entrata e in uscita per post | Utile per un "perché" in più: articoli orfani |

**Problemi rilevati:**
- **Righe triplicate, risolto il 2026-10-14:** c'erano 12.592 righe ma solo 4.884 terne uniche `(giorno, pagina, query)`, perché l'import era stato lanciato più volte, anche dalla mia esecuzione manuale della coda. Ho cancellato le 7.708 copie in eccesso, tenendo l'id più basso. Il backup è in `~/backups/rank_math_analytics_gsc.20261014.sql.gz`. I click reali sono 1.170, non 3.230. L'ETL deve comunque deduplicare, nel caso l'import venga rilanciato.
- **Buco dal 03/05 al 30/06:** non è recuperabile da Rank Math. Le righe di aprile e maggio sono residui che la pulizia a 180 giorni eliminerà nei prossimi giorni, quindi vanno salvate nello snapshot subito.
- **La colonna `created` è `ON UPDATE current_timestamp()`:** non bisogna mai fare UPDATE su quelle righe, altrimenti la data cambia. Le si legge e basta, oppure si cancellano i duplicati.

---

## 3. Cosa può mostrare la dashboard

Le 5W applicate ai dati che abbiamo:

| W | Domanda | Fonte |
|---|---|---|
| **What** | Quali articoli leggono, quanto, con quale trend (crescita, calo, evergreen) | `statistics_pages` → snapshot giornaliero per `post_id` |
| **When** | Andamento del sito dal 2023, stagionalità, giorno della settimana, effetto di una pubblicazione nei giorni successivi | `summary_totals` + `post_date` dei post |
| **Where** | Paesi e città, device e browser | `statistics_visitor` |
| **Who / from where** | Sorgenti: Google, social, direct, referral, per articolo | `statistics_visitor.first_page` + `source_*` |
| **Why** | Con quali query si arriva, posizione media, CTR e opportunità | `rank_math_analytics_gsc` deduplicata, collegata a `post_id` tramite `rank_math_analytics_objects` |

**Viste proposte:**
1. **Panoramica:** KPI del periodo con confronto rispetto al periodo precedente, trend del sito dal 2023, top articoli, mix delle sorgenti.
2. **Articoli:** tabella ordinabile con pageview, variazione, visite da ricerca, click e impression GSC, CTR, posizione media pesata, età dell'articolo e link interni in entrata.
3. **Dettaglio articolo** (anche come metabox nell'editor): trend giornaliero, sorgenti, query GSC con posizione e CTR, pagine di provenienza e di uscita.
4. **Opportunità:**
   - molte impression e CTR basso, quindi titolo o meta da rivedere;
   - posizione tra 5 e 15, quindi un articolo da rinforzare;
   - articoli in calo (content decay);
   - articoli senza link interni in entrata.
5. **Categorie e tag:** aggregazione per tassonomia, per capire quali argomenti rendono.

**Regole di calcolo da rispettare:**
- La **posizione media** va pesata sulle impression, non calcolata con una media semplice.
- **Sommare le righe per query sottostima i totali per pagina**, perché GSC omette le query anonimizzate. Se in futuro si interroga direttamente l'API, i totali per pagina vanno presi con la sola dimensione `page`.
- I dati GSC arrivano con 2 o 3 giorni di ritardo: gli ultimi giorni vanno mostrati come incompleti.
- I **click GSC e i visitatori Google di WP Statistics sono misure diverse**. WP Statistics deduplica per hash IP al giorno e filtra in modo diverso bot e JS. Il loro rapporto va mostrato come indicatore, non come quadratura.
- L'hash IP cambia ogni giorno, quindi non si possono misurare i visitatori di ritorno.
- Gli **attachment protetti** (paywall) vanno trattati come contenuti a sé, con una vista dedicata: visite alla paywall, provenienza e, se `wp-paid-attachments` le registra, conversioni. Gli allegati non protetti fanno redirect, quindi non generano visite.

---

## 4. Architettura del plugin

```
wp-5w/
├── wp-5w.php                    bootstrap, attivazione e creazione tabelle
├── src/
│   ├── Sources/
│   │   ├── WpStatisticsSource.php   adapter con controllo di versione e schema
│   │   └── RankMathSource.php       adapter: dedup, mapping page → post_id
│   ├── Etl/DailySnapshot.php        ETL notturno idempotente (rielabora gli ultimi N giorni)
│   ├── Rest/                        endpoint /wp-5w/v1/* (manage_options)
│   └── Admin/                       pagina React e metabox
└── assets/src/                      React con @wordpress/scripts e @wordpress/components
```

**Tabelle proprie** (compatte, aggregate, nessun dato personale):

| Tabella | Chiave | Colonne |
|---|---|---|
| `5w_post_daily` | `date, post_id` | views, visitors, entries, visite per canale (search, social, direct, referral) |
| `5w_site_daily` | `date` | visitors, views, visite per canale |
| `5w_gsc_daily` | `date, post_id, query_hash` | query, clicks, impressions, position pesata |
| `5w_dim_daily` | `date, dim, value` | paese, device, browser, sorgente (aggregati) |

Stima: poche decine di MB in 10 anni, molto meno dei 27 MB attuali di WP Statistics per 6 mesi.

**ETL:**
- Gira ogni notte e **rielabora gli ultimi 7 giorni** con `INSERT … ON DUPLICATE KEY UPDATE`. È idempotente e copre il ritardo di GSC e i dati tardivi.
- Al primo avvio importa tutto lo storico disponibile, cioè `summary_totals` dal 2023, i 180 giorni di dettaglio e i 106 giorni GSC.
- WP-Cron dipende dal traffico, quindi conviene un **cron reale dell'hosting** che chiama `wp cron event run --due-now`.

**Adapter versionati:** sono tabelle interne di plugin che si aggiornano spesso. Ogni adapter dichiara le versioni e lo schema supportati e verifica le colonne. Se lo schema è sconosciuto si ferma in modo pulito e lo segnala in dashboard, senza mai produrre dati sbagliati. Prima di iniziare va controllato il changelog di WP Statistics per eventuali migrazioni dello schema annunciate.

**Rapporto con l'opzione hub:** le tabelle `5w_*` sono già il formato da inviare all'hub. Si può partire in locale e aggiungere in seguito un job che le spedisce all'hub, quindi le due strade non si escludono.

---

## 5. Confronto con l'opzione hub del README

| | Dashboard locale (questo documento) | Collector centralizzato sull'hub |
|---|---|---|
| Tempo per vedere i primi dati | Giorni: i dati ci sono già | Settimane, e lo storico parte da zero (salvo import) |
| Nuovo tracciamento | Nessuno | Beacon JS, endpoint, gestione bot |
| Dipendenze | WP Statistics e Rank Math (schemi interni) | Nessun plugin di terzi |
| GSC | Mediata da Rank Math (90 gg, free) | API diretta (16 mesi), service account unico |
| Multi-sito | No, salvo invio successivo all'hub | Sì |
| Peso sul sito | WP Statistics resta attivo (circa 55 MB all'anno) | Il database del sito si alleggerisce |
| Rischio principale | Un aggiornamento dei plugin cambia lo schema | L'hub va giù e si perdono le pageview |

**Raccomandazione:** partire con la dashboard locale, che sfrutta subito 6 mesi di dettaglio, 3 anni di totali e i dati GSC. Le tabelle `5w_*` vanno progettate fin da ora come feed per l'hub. L'opzione hub resta il passo successivo, quando servirà il multi-sito.

---

## 6. Piano a fasi

1. ~~Fermare la perdita di dati di WP Statistics~~: fatto il 2026-10-14 → verifica nei prossimi giorni: `MIN(date)` di `statistics_pages` resta 2026-04-17.
2. **ETL e tabelle proprie**, con import iniziale → verifica: i totali di `5w_site_daily` coincidono con `summary_totals`; le righe di `5w_gsc_daily` coincidono con le terne uniche di Rank Math.
3. **Endpoint REST e pagina Panoramica** → verifica: i KPI coincidono con quelli di WP Statistics nello stesso periodo.
4. **Articoli, Dettaglio e metabox.**
5. **Opportunità e Categorie.**
6. (Facoltativo) **Backfill GSC via API** per colmare il buco 03/05-30/06 e risalire a 16 mesi, con credenziali proprie.
7. (Facoltativo) **Invio dei dati all'hub.**

---

## 7. Miglioramenti proposti

1. ~~Redirect degli allegati~~: proposta ritirata. Le pagine allegato visitate sono le paywall di `wp-paid-attachments`, e gli allegati non protetti fanno già redirect.
2. ~~Deduplicare la tabella GSC di Rank Math~~: fatto il 2026-10-14.
2b. **Funnel della paywall** (nuova proposta): incrociare le visite alle pagine paywall con le sorgenti, le query GSC dell'articolo genitore e gli acquisti di `wp-paid-attachments`. È il "why" che rende di più.
3. **Cron reale** al posto di WP-Cron per l'ETL, per Rank Math e per WP Statistics.
4. **Backfill GSC dall'API** (16 mesi): è anche il primo mattone dell'opzione hub.
5. **Eventi:** WP Statistics ha la tabella `events` vuota. Si potrebbero tracciare i click sulle CTA, i download di `wp-paid-attachments` e le prenotazioni di `wp-book-a-call`, per un "why" orientato alle conversioni.

## 8. Decisioni (2026-10-14)

- **Retention di WP Statistics:** impostata a "forever".
- **Allegati:** nessun redirect, le paywall restano.
- **Duplicati GSC:** ripuliti.
- **Ambito:** POC, solo una pagina in wp-admin.
- **Interfaccia:** base con `@wordpress/components`; grafici interattivi in React (gli script di `@wordpress/scripts` sono già React), con una libreria leggera.
- **Rank Math oltre i 90 giorni:** non si forza. Il limite free è applicato dal filtro `rank_math/analytics/max_days_allowed`. Tecnicamente un mu-plugin potrebbe alzarlo, ma significherebbe aggirare il limite della versione free, ed è fragile rispetto agli aggiornamenti. Lo snapshot di wp-5w conserva comunque tutto, e per lo storico vero la strada pulita è l'API GSC (16 mesi).
