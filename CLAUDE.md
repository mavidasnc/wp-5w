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
npx wp-env run cli wp eval-file wp-content/plugins/wp-5w/tests/fixtures/verify.php   # 11 controlli
```

Per provare sui dati reali: dump delle tabelle `maurizio_statistics_*` e `maurizio_rank_math_analytics_{gsc,objects}` dal blog, poi `sed 's/`maurizio_/`wp_/g'` in `.wp-env-data/` (gitignored), `wp db import` e `verify.php`.

## Struttura

- `src/Sources/`: `Source` (controllo dello schema, `replace_by_month`), `WpStatisticsSource`, `RankMathSource`.
- `src/Etl/`: `Importer` (lock, stato in `wp5w_etl_state`) e `Scheduler` (cron `wp5w_etl_daily` alle 4:00, `wp5w_etl_full` dopo l'attivazione).
- `src/Query/OverviewQuery.php`: legge solo le tabelle `wp5w_*`.
- `src/Support/`: `DateRange`, `Metrics` e `SchemaCheck` sono classi pure senza guard ABSPATH, coperte da PHPUnit.
- `assets/admin/`: app React (`@wordpress/components` + Chart.js con react-chartjs-2).

## Punti da ricordare

- **Mai cancellare dove la sorgente non ha più dati.** `replace_by_month` sostituisce solo l'intersezione con l'intervallo presente nella sorgente: le finestre mobili di WP Statistics e Rank Math (2 x 90 giorni nella versione free) non devono svuotare le tabelle `wp5w_*`.
- **Righe duplicate in `rank_math_analytics_gsc`:** si raggruppa per (giorno, pagina, query) con MAX. La colonna `created` è `ON UPDATE current_timestamp()`, quindi la tabella non va mai aggiornata.
- **`DATE(created)` dipende dal fuso della sessione MySQL.** Sul blog è +02:00, in wp-env UTC: un dump caricato in wp-env sposta i giorni GSC di uno.
- **`statistics_summary_totals` ha giorni a zero** (2017-2022 sul blog), che sono buchi di raccolta: non vanno importati.
- **`statistics_visitor.first_page` → `statistics_pages.page_id`:** verificato sul blog (10.614 su 10.616).
- **Su Windows con Git Bash** usa `MSYS_NO_PATHCONV=1` per gli argomenti che iniziano con `/` (per esempio `wp rewrite structure`) e scrivi i JSON con backslash usando l'editor, non gli heredoc.
- **Blog di produzione** (`maurizio.mavida.com`): WP-CLI richiede `--url=https://maurizio.mavida.com`.
