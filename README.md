# wp-5w

Dashboard di analytics in wp-admin che incrocia i dati già raccolti da **WP Statistics** (traffico) e da **Rank Math Analytics** (Google Search Console), senza aggiungere tracciamento, cookie o servizi esterni.

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
  - **Visitatori:** ultimi 7 giorni e ultimi 6 mesi per mese (totali a barre e medie al giorno a linee), provenienze e keyword degli ultimi 7 giorni.
  - I box a riga intera si riducono con un toggle, che resta memorizzato nel browser.
  - **Stato:** sorgenti, copertura delle tabelle, ultimo import, avvio manuale.
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
| `wp5w_gsc_daily` | Click, impression e posizione per giorno, pagina e query; `origin` rankmath o api | `rank_math_analytics_gsc` oppure l'API di Search Console (backfill), collegata ai contenuti tramite `rank_math_analytics_objects` o `url_to_postid()` |

La posizione media si calcola sempre come `SUM(pos_x_impr) / SUM(impressions)`, cioè pesata sulle impression come in Search Console.

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

Le tabelle `wp5w_*` sono spesso l'unica copia dello storico, quindi la disinstallazione **non le cancella**. Per eliminarle, definisci `WP5W_DELETE_DATA` a `true` in `wp-config.php` prima di disinstallare.

## Sviluppo

```bash
composer install
npm install --ignore-scripts
npm run build          # build dell'admin React
composer ci            # PHPCS, PHPStan, PHPUnit
npm run check          # build + controllo dei file compilati
```

Il flusso dei test con wp-env è in [CLAUDE.md](CLAUDE.md). L'analisi che ha portato al progetto è in [docs/](docs/).
