# Changelog

Tutte le modifiche rilevanti del plugin. Formato basato su [Keep a Changelog](https://keepachangelog.com/it/1.1.0/), versioni [SemVer](https://semver.org/lang/it/).

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
