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

### Recupero dello storico di Search Console

```bash
# In locale, con il token OAuth (scope webmasters.readonly) di maurizio.pelizzone@gmail.com:
python scripts/gsc-backfill.py --token "C:/Users/maurizio.MAVIDA/Claude/credenzials/google/maurizio.pelizzone@gmail.com/token-searchconsole.json"   --site https://maurizio.mavida.com/ --days 180 --out .wp-env-data/gsc-backfill.jsonl
scp .wp-env-data/gsc-backfill.jsonl mavida-blog:backups/
ssh mavida-blog 'cd ~/domains/mavida.com/public_html/maurizio && wp --url=https://maurizio.mavida.com 5w gsc-import ~/backups/gsc-backfill.jsonl'
```

Per provare sui dati reali: dump delle tabelle `maurizio_statistics_*` e `maurizio_rank_math_analytics_{gsc,objects}` dal blog, poi `sed 's/`maurizio_/`wp_/g'` in `.wp-env-data/` (gitignored), `wp db import` e `verify.php`.

## Struttura

- `src/Sources/`: `Source` (controllo dello schema, `replace_by_month`), `WpStatisticsSource`, `RankMathSource`.
- `src/Etl/`: `Importer` (lock, stato in `wp5w_etl_state`) e `Scheduler` (cron `wp5w_etl_daily` alle 4:00, `wp5w_etl_full` dopo l'attivazione).
- `src/Query/`: `OverviewQuery` (Panoramica) e `PostsQuery` (elenchi Articoli e dettaglio del modale) leggono solo le tabelle `wp5w_*`.
- `src/Support/`: `DateRange`, `Metrics`, `Series` e `SchemaCheck` sono classi pure senza guard ABSPATH, coperte da PHPUnit.
- `assets/admin/`: app React (`@wordpress/components` + Chart.js con react-chartjs-2).

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
- **Confronti con il periodo precedente:** vanno mostrati solo se quel periodo è coperto dai dati (`SearchQuery` restituisce `comparable`), altrimenti escono variazioni del +9000%.
- **Su Windows con Git Bash** usa `MSYS_NO_PATHCONV=1` per gli argomenti che iniziano con `/` (per esempio `wp rewrite structure`) e scrivi i JSON con backslash usando l'editor, non gli heredoc.
- **Blog di produzione** (`maurizio.mavida.com`): WP-CLI richiede `--url=https://maurizio.mavida.com`.
