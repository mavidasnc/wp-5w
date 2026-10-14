# Changelog

Tutte le modifiche rilevanti del plugin. Formato basato su [Keep a Changelog](https://keepachangelog.com/it/1.1.0/), versioni [SemVer](https://semver.org/lang/it/).

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
