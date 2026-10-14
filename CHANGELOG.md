# Changelog

Tutte le modifiche rilevanti del plugin. Formato basato su [Keep a Changelog](https://keepachangelog.com/it/1.1.0/), versioni [SemVer](https://semver.org/lang/it/).

## [0.1.0] — 2026-10-14

### Added
- Tabelle aggregate `wp5w_site_daily`, `wp5w_object_daily`, `wp5w_entry_daily`, `wp5w_dim_daily` e `wp5w_gsc_daily`.
- Sorgenti WP Statistics 14.x e Rank Math Analytics, con controllo dello schema prima di ogni import.
- ETL idempotente a blocchi mensili. Conserva i giorni già eliminati dalle sorgenti e deduplica le righe di Search Console.
- Cron giornaliero alle 4:00 (ultimi 7 giorni) e import completo dopo l'attivazione.
- Pagina **5W** in wp-admin, con le schede Panoramica (KPI, andamento, canali, contenuti più visti) e Stato.
- Comandi WP-CLI `wp 5w status` e `wp 5w etl`.
- Aggiornamenti automatici dalle release di GitHub.
