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
  - **Panoramica:** KPI con confronto rispetto al periodo precedente, andamento di traffico e Search Console, canali, contenuti più visti con click, impression, CTR e posizione media pesata.
  - **Stato:** sorgenti, copertura delle tabelle, ultimo import, avvio manuale.
- **Comandi WP-CLI:** `wp 5w status` e `wp 5w etl [--full] [--from=] [--to=]`.
- **Aggiornamenti automatici** dalle release di GitHub.

## Tabelle

| Tabella | Contenuto | Sorgente |
|---|---|---|
| `wp5w_site_daily` | Visitatori e visualizzazioni del sito per giorno | `statistics_summary_totals`, completato con il dettaglio per i giorni non ancora riassunti |
| `wp5w_object_daily` | Visualizzazioni per contenuto (post, pagina, allegato, home…) | `statistics_pages` |
| `wp5w_entry_daily` | Visitatori entrati da un contenuto, per canale | `statistics_visitor.first_page` → `statistics_pages` |
| `wp5w_dim_daily` | Visitatori per paese, device, browser, sistema, canale e sorgente | `statistics_visitor` |
| `wp5w_gsc_daily` | Click, impression e posizione per giorno, pagina e query | `rank_math_analytics_gsc`, collegata ai contenuti tramite `rank_math_analytics_objects` o `url_to_postid()` |

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
