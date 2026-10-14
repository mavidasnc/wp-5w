# Fase 4: canali, tempo reale e dati operativi

Questa è la specifica di implementazione della fase 4 di [roadmap-sostituzione-wp-statistics.md](../roadmap-sostituzione-wp-statistics.md). Valgono anche qui le sezioni «Decisioni già prese», «Principio di progetto», «Processo di sviluppo» e «Scostamenti» della roadmap, insieme alle decisioni di [fase-1.md](fase-1.md), [fase-2.md](fase-2.md) e [fase-3.md](fase-3.md). Dove questo documento è più preciso, prevale.

- **Branch:** `fase-4-canali-tempo-reale`, creato da `main` alla release `v0.9.0` (commit `a94a446`).
- **Versione da rilasciare:** `0.10.0`, la prima con il minore a due cifre. `npm run release -- 0.10.0` accetta la versione esplicita, e il controllo degli aggiornamenti (plugin-update-checker) usa `version_compare`, quindi `0.10.0` risulta più recente di `0.9.0`. Lo schema del database **passa alla versione `5`**, con due tabelle nuove e piccole (sezione 5).
- **Ciclo:** Sonnet implementa su questo branch e apre la PR verso `main`, con la checklist dei criteri di accettazione della sezione 9. Segue la review di Opus 5.5, che rilancia `composer ci`, `npm run check` e `verify.php`. Sono ammessi al massimo 3 giri di correzioni. Poi squash merge, release, deploy secondo il runbook (sezione 10) e nota di consegna.
- **Obiettivo:** campagne UTM, canale «AI» coerente tra le due origini, etichette leggibili per i referrer delle app, «Online adesso», pagine 404, ricerche interne e visite nell'elenco dei contenuti e nella barra di amministrazione. In più, i quattro finding minori ancora aperti della review della fase 2.

---

## 1. Dati reali letti prima di scrivere la specifica

Le letture sono state fatte in sola lettura il 2026-10-14, con `ssh mavida-blog` e `wp --url=https://maurizio.mavida.com`, con `curl` anonimi senza JavaScript (che quindi non registrano visite) e in locale sulla copia di WP Statistics 14.16.15 in `.wp-env-plugins`.

### 1.1 Assistenti AI in WP Statistics

`statistics_visitor` copre i giorni dal 2026-04-17 al 2026-10-14, con 10.707 visitatori. Quelli che arrivano da un assistente AI sono 8:

| `source_channel` | `source_name` | `referred` | Visitatori |
|---|---|---|---|
| `search` | `ChatGPT` | `chatgpt.com` | 4 |
| `search` | `ChatGPT` | (vuoto) | 2 |
| `search` | `Perplexity` | `perplexity.ai` | 1 |
| `NULL` | `NULL` | `claude.ai` | 1 |

- **Come classifica WP Statistics** (`ReferralsParser::parse()`): confronta il dominio del referrer **e** i parametri `utm_source`, `source` e `ref` dell'URL della pagina con i domini di `assets/json/source-channels.json`, e il parametro vince sul referrer. I 2 visitatori `ChatGPT` senza referrer arrivano quindi da `utm_source=chatgpt.com`, che ChatGPT aggiunge ai link citati.
- **Gli unici assistenti nell'elenco di WP Statistics** sono `chatgpt.com` (ChatGPT) e `perplexity.ai` (Perplexity), entrambi nel canale `search`. `claude.ai`, `gemini.google.com` e `copilot.microsoft.com` non sono nell'elenco e finiscono con il canale `NULL`, cioè `(n/d)` in `dim_daily`.
- **Canali sul blog:** `direct` 6.439, `search` 3.122, `social` 844, `NULL` 298, `video` 3, `referral` 1. WP Statistics usa `NULL` per tutti i referrer che non conosce, e quasi mai `referral`.

### 1.2 Referrer delle app

Su `statistics_visitor` le app Android arrivano con l'URL completo nel campo `referred`, perché `Url::getDomain()` non riconosce lo schema `android-app`:

| `referred` | `source_channel` | Visitatori |
|---|---|---|
| `android-app://com.linkedin.android/` | `NULL` | 42 |
| `android-app://com.google.android.googlequicksearchbox/` | `NULL` | 7 |
| `android-app://com.slack/` | `NULL` | 1 |
| `android-app://m.facebook.com/` | `social` (Facebook) | 1 |

La raccolta propria salva invece l'host calcolato da `parse_url()` (`Channels::host()`), quindi la stessa app compare come `com.linkedin.android`. LinkedIn è già nell'elenco `SOCIAL` di `Channels` (`com.linkedin.android`), quindi nella raccolta propria è `social`, mentre in WP Statistics è `(n/d)`. È una differenza che esiste già e che il Confronto continuerà a mostrare (domanda aperta 3).

### 1.3 Pagine 404, ricerche e cache

- **Blocco firmato.** Una pagina inesistente (`/questa-pagina-non-esiste-wp5w/`) risponde `404`, con `x-litespeed-cache: miss` e `cache-control: no-store`. Il blocco `#wp5w-hit` c'è già, con `"type":"404","id":0` e una firma. Le ricerche `?s=wordpress` e `?s=zzqqxxnessunrisultato` rispondono `200` con `x-litespeed-cache-control: no-cache`, e il blocco ha `"type":"search","id":0` e la stessa firma in entrambi i casi. LiteSpeed quindi non mette in cache né le 404 né le ricerche, ma la specifica non ne dipende, perché i dati della pagina stanno nel blocco.
- **Il tracker registra già queste visite** in `wp5w_hits`, come tipo `404` o `search` con ID 0. L'URI è `location.pathname`, quindi per una ricerca è `/` e il termine cercato non arriva mai al server.
- **WP Statistics nella sua finestra** ha 103 visualizzazioni di 404 su 65 URI e 9 ricerche su 6 URI. Le 404 più viste sono `/2005/10/27/la-shell-di-windows-concatenazione-reindirizzamento/` (19), un URI con `:%3E%3Cb%3EGiorgia` in coda (14) e `/z0f76a1d14fd21a8fb5fd0d03e0fdc3d3cedae52f` (11).
- **Immagini mancanti.** `https://maurizio.mavida.com/public/uploads/2005/01/nonesiste.jpg` riceve un `404` statico dal server (`content-type: text/html`, senza il blocco `#wp5w-hit`), e `/wp-content/uploads/...` risponde `301`. Le richieste di immagini rotte non passano da WordPress e non eseguono JavaScript, quindi **non** si possono registrare con il tracker (domanda aperta 1).

### 1.4 Raccolta propria, UTM e tracker

- **`wp5w_hits`** contiene 12 righe, dal 2026-10-14 18:39:41 al 20:53:52 UTC (404 1, home 3, post 7, `post_type_attachment` 1) e nessuna visita con parametri UTM. Le colonne `utm_source`, `utm_medium` e `utm_campaign` si riempiono dalla fase 1, quindi le campagne si possono ricalcolare da subito per tutti i giorni `own`.
- **Tracker compilato:** `build/tracker/tracker.js` pesa **669 byte** (427 compresso con gzip). Il limite di `scripts/check-build.js` è 1.536 byte.
- **Zip della release 0.9.0:** 430.489 byte (il limite della fase 3 era 440.000).
- `show_on_front` vale `page`, e `innodb_default_row_format` vale `dynamic`, quindi le chiavi composte fino a 3.072 byte sono ammesse.

---

## 2. Passo 0 dell'implementazione: controlli ancora aperti

Prima di scrivere codice, se la data è il **2026-10-15 o successiva**, Sonnet esegue sul blog i controlli di sola lettura di 10.0 (punti 1, 2 e 4) e applica la tabella di decisione di [fase-2.md, 9.1](fase-2.md):

- **«ok», «dati insufficienti» o «sorgente pesante»:** si implementa questa specifica e si riporta l'esito nella PR;
- **«proxy sospetto» o «raccolta ferma o parziale»:** ci si ferma e si avvisa Maurizio, senza scrivere codice. La fase riprende solo dopo la sua decisione.

Se la data è ancora il 2026-10-14, il passo 0 si salta e i controlli si fanno all'inizio del deploy (10.0).

---

## 3. Decisioni prese in questa specifica

La roadmap lascia aperte alcune scelte. Per ciascuna, la decisione presa qui e due righe di motivazione.

| # | Decisione | Motivazione |
|---|---|---|
| F1 | **Canale `ai`, con la stessa regola applicata in aggregazione alle due origini.** Il canale `ai` non si assegna nell'endpoint e non si calcola in lettura: lo producono `OwnSource` e `WpStatisticsSource` quando scrivono `entry_daily`, `referrer_daily` e `dim_daily`, con un frammento SQL costruito da `Support\Channels` a partire da un solo elenco. `wp5w_hits` e `statistics_visitor` restano come sono. | Le righe `channel` di `dim_daily` non hanno la sorgente, quindi in lettura non si saprebbe da quale canale togliere i visitatori AI. Con la stessa regola sui due lati, il Confronto mette `ai` contro `ai`, e un nuovo ETL ricalcola anche lo storico di entrambe le origini (e lo ripristina in caso di rollback). |
| F2 | **Elenco `Channels::AI`:** nome => domini, con corrispondenza esatta o di sottodominio e senza `www.`. La regola guarda tre cose: l'host del referrer, il parametro `utm_source` (solo raccolta propria, confronto esatto come in WP Statistics) e `source_name` (solo WP Statistics, per i nomi che conosce già). Il canale `ai` ha la precedenza su tutti gli altri. | Con domini semplici, PHP e SQL si comportano allo stesso modo. Senza `utm_source` i 2 visitatori `ChatGPT` senza referrer (1.1) finirebbero in `ai` da una parte e in `unassigned` dall'altra. |
| F3 | `Channels::classify()` e l'elenco `SEARCH` **non cambiano** (ChatGPT resta un motore di ricerca nei dati grezzi). | `wp5w_hits` resta con la stessa classificazione di WP Statistics, che è la base del Confronto della fase 2. Il canale `ai` è una regola sugli aggregati, quindi non serve riscrivere visite già salvate. |
| F4 | **Etichette delle app solo in presentazione:** una classe pura `Support\Referrers::label()` riconosce le due forme (`android-app://com.linkedin.android/` e `com.linkedin.android`). Le query degli elenchi aggiungono il campo `label`, e le righe non si uniscono. | Il requisito chiede di non riscrivere i dati. Le due forme convivono solo nei periodi a cavallo di `wp5w_own_since` (fase 5), con due righe che hanno la stessa etichetta. Unirle richiederebbe di sommare `contents`, che non si può fare. |
| F5 | **UTM:** tre dimensioni `own` in `dim_daily`: `utm_campaign`, `utm_source` e **anche `utm_medium`**. Ogni riga conta i visitatori distinti del giorno che hanno quel valore, e le visite senza UTM non generano righe (niente `(n/d)`). | La roadmap chiede «campagne con sorgente e mezzo», e `utm_medium` è già in `wp5w_hits`: costa una parte in più nella stessa `UNION`. Una riga `(n/d)` coprirebbe quasi tutti i visitatori e schiaccerebbe le barre. |
| F6 | **Le dimensioni `utm_*` non entrano nell'invariante** «stessa somma di visitatori per ogni dimensione» (fase 3, R5), che resta valido per `channel`, `source`, `country`, `device`, `browser` e `platform`. | Un visitatore può avere zero o più valori UTM nello stesso giorno. Ogni controllo dell'invariante (verify e runbook) elenca le dimensioni in modo esplicito. |
| F7 | **Box «Campagne»:** `AudienceQuery::campaigns()` legge **solo** le righe `own` delle dimensioni `utm_*`, senza `Origin::where()`. È la seconda eccezione alla regola dell'origine, limitata a quelle dimensioni e al primo giorno `own`, ed è scritta nel commento del metodo e in `CLAUDE.md`. | WP Statistics non ha UTM, quindi la regola wps/own mostrerebbe un box vuoto fino alla fase 5. Il primo giorno di dati è il primo giorno `own` di `dim_daily` (`dim = 'channel'`), non il primo giorno con UTM: un box vuoto vuol dire «nessuna campagna», non «nessun dato». |
| F8 | **«Online adesso»** in cima alla scheda Visitatori: visitatori distinti con almeno una visita negli ultimi 5 minuti, ognuno contato **una volta, sul contenuto della sua ultima visita**. Filtro su `ts` in UTC e su `day IN (oggi, ieri)`, così si usa l'indice `day` e la finestra regge anche a mezzanotte. Nessuna modifica allo schema. | La somma dei contenuti è uguale ai visitatori, ed è il dato «chi sta guardando cosa». `wp5w_hits` ha già `KEY day`, e una `ts` senza indice si filtra sulle poche righe di oggi e ieri. |
| F9 | **Il flag 404 è il tipo `404` già presente nel blocco firmato.** `Tracker::current_object()` lo produce oggi (verificato sul blog, 1.3), ed è coperto dalla firma HMAC. Non si aggiunge un campo, e per le 404 il tracker non cambia. | Un campo in più sarebbe ridondante e non firmato. Il blocco è nella pagina, quindi vale anche se una 404 finisse in cache (oggi il blog le serve con `no-store`, 1.3). |
| F10 | **Pagine 404 in una tabella nuova, `wp5w_notfound_daily`** (giorno, URI, host del referrer, visite, ultima visita), scritta dall'endpoint con un solo `INSERT ... ON DUPLICATE KEY UPDATE`. Non si usa `dim_daily`. | `dim_daily` non ha colonne per il referrer e l'ultima visita, e le sue righe `own` vengono cancellate e riscritte ogni ora da `replace_by_month()` partendo da `wp5w_hits`. Una tabella con 5 colonne è la soluzione più semplice, ed è la stessa tecnica di `wp5w_discards`. |
| F11 | **Ricerche interne in una tabella nuova, `wp5w_search_daily`** (giorno, termine, ricerche, senza risultati), **senza nessun orario**. | Il termine non è in `wp5w_hits` e non ci deve entrare. Senza un orario, una riga di ricerca non si può collegare a una visita di `wp5w_hits`. |
| F12 | **`q` (termine) e `n` (numero di risultati) non sono coperti dalla firma.** Il server li accetta solo con un tipo `search` firmato, dopo tutti gli scarti, e li normalizza e filtra. | Chiunque può ottenere una firma valida per qualunque termine aprendo `/?s=termine` (le ricerche non sono in cache), quindi firmare il termine non fermerebbe un attacco voluto. Le difese reali sono gli scarti (bot, frequenza), la normalizzazione e l'escape di React nell'admin. |
| F13 | **Normalizzazione e filtro della privacy in una classe pura, `Support\SearchTerm`**, usata sia da `Tracker` (stampa) sia dall'endpoint, che è quello che decide. Un termine che sembra un'email o un numero lungo si conta come `(filtrato)`. Si conta solo la prima pagina dei risultati (`! is_paged()`), e i termini vuoti si ignorano. | Il dato del client non è affidabile, quindi decide l'endpoint. Contare `(filtrato)` tiene corretti i totali delle ricerche senza salvare il dato personale, e con le pagine successive i totali si gonfierebbero. |
| F14 | **Query dell'endpoint:** una visita normale ne fa ancora **2** (la `SELECT` e la `INSERT` su `wp5w_hits`), una 404 o una ricerca valida **3** (più un `INSERT ... ON DUPLICATE KEY UPDATE`). Una ricerca con il termine vuoto ne fa 2. | Il costo in più riguarda solo 404 e ricerche (meno dell'1% delle visite, 1.3). Il gate dei tempi misura la visita normale, come nella fase 1. |
| F15 | **Referrer delle 404:** host calcolato con `Channels::host( $r, '' )`, **compreso il sito stesso** (per esempio `maurizio.mavida.com`), che nell'admin si legge «Link interno». Solo l'host, mai il percorso. | Il requisito chiede l'host. Distinguere i link interni rotti da quelli esterni è il dato che serve per correggerli, e `Channels::host()` con il sito vuoto lo dà senza codice nuovo. |
| F16 | **I box «Pagine non trovate» e «Ricerche interne» vanno nella scheda Articoli**, dopo «Articoli più visti», con i periodi 7, 30 e 90 giorni. | Parlano di contenuti (quelli che mancano e quelli che si cercano), e la scheda Articoli ha solo due box, mentre Visitatori ne ha già sei. |
| F17 | **«Visite (30 g)» sono visualizzazioni** (`object_daily.views`) degli ultimi 30 giorni, compreso oggi, con la regola dell'origine. | È lo stesso numero della colonna di WP Statistics e il primo numero di «Articoli più visti». Il titolo della colonna lo spiega con un `title`. |
| F18 | **Colonna solo per chi ha `manage_options`** (la capacità della pagina del plugin), negli elenchi di articoli e pagine. È **ordinabile**: un filtro `posts_clauses`, attivo solo con `orderby=wp5w_views`, aggiunge un `LEFT JOIN` su una tabella derivata raggruppata. | Il modale si apre solo da quella pagina. L'ordinamento costa una tabella derivata su 30 giorni di `object_daily` (qualche migliaio di righe), quindi è semplice. |
| F19 | **Barra di amministrazione:** solo nel frontend, sugli articoli e le pagine singoli, per chi ha `manage_options`, con una query. | Le pagine viste da un amministratore collegato non arrivano dalla cache pubblica di LiteSpeed, quindi il PHP gira e la query riguarda solo gli amministratori. |
| F20 | **Apertura del modale dall'URL:** `admin.php?page=wp5w&tab=posts&detail=<ID>`. `AdminMenu` legge `detail` e, se è un articolo o una pagina leggibile, aggiunge `wp5wAdmin.detail = { id, title, date }`. `PostsPage` apre il modale con il periodo di 30 giorni e alla chiusura toglie `detail` dall'URL con `history.replaceState`. | La scheda iniziale si sceglie già con `?tab=`, e il modale ha bisogno di `id`, `title` e `date`, che il PHP conosce senza una chiamata REST in più. Il nome `detail` evita `post`, che WordPress e altri plugin leggono in `admin.php`. |
| F21 | **Schema alla versione 5.** Le due tabelle nuove le crea `dbDelta`, senza migrazione delle chiavi. Restano fuori da `Schema::TABLES` (la scheda Stato non cambia) e da `Origin::TABLES`. | Sono tabelle nuove e con una sola origine. `migrate_primary_keys()` è già idempotente. |
| F22 | **Le tabelle 404 e ricerche si conservano per sempre**, come gli aggregati. La disinstallazione le elimina solo con `WP5W_DELETE_DATA`. | Sono piccole (righe per giorno e valore). L'eventuale pulizia si decide con la conservazione di `wp5w_hits` nella fase 5. |
| F23 | **Il tetto del tracker resta di 1.536 byte.** Il tracker passa da 669 byte a circa 720 (solo l'invio di `q` e `n`). | Il margine resta ampio, quindi non serve un tetto nuovo. |
| F24 | **Limite dello zip: 460.000 byte** (la 0.9.0 pesa 430.489). | Il codice nuovo (circa 10 classi e componenti) aggiunge 10-20 KB all'app compilata. |
| F25 | **Scostamento da R5: le 404 salvano l'URI codificato (%XX), non quello di `relative_uri()`.** `count_not_found()` parte dallo stesso percorso (`relative_path()`: senza query string, frammento e sottocartella della home) e tiene solo i caratteri di un percorso RFC 3986, compreso `%`, al massimo 190 caratteri (un `%XX` tagliato dal limite si toglie), oppure `/` se non resta nulla. `relative_uri()` non cambia, quindi `wp5w_hits.uri` resta com'è. | `relative_uri()` passa da `sanitize_text_field()`, che toglie ogni ottetto `%XX`: una 404 su `/citt%C3%A0/post%E2%80%9D/` finiva in `/citt/post/`, URI diversi si fondevano e il link dell'admin poteva puntare a una pagina esistente. L'URI è nella chiave primaria di una tabella conservata per sempre (F22), quindi il dato perso non si recupera. La sicurezza resta all'escape di React e al prefisso di `home_url()`. |
| F26 | **Scostamento da R6: il termine `q` arriva a `SearchTerm::store()` grezzo** (solo troncato a 200 caratteri con `mb_substr`), senza `sanitize_text_field()`. | Il filtro toglieva `%XX` e le parti tra `<` e `>` (`50%ab` diventava `50`, `<div>` non si contava). `SearchTerm` toglie già i caratteri di controllo e l'admin fa l'escape con React. |

---

## 4. Regole di business

### R1. Canale `ai` (`Support\Channels`, `OwnSource`, `WpStatisticsSource`)

**Elenco** (in `Channels`, pubblico, aggiornabile; i test di sezione 7 sono il contratto):

```php
/**
 * Assistenti AI: nome => domini (corrispondenza esatta o sottodominio, senza "www.").
 * I nomi ChatGPT e Perplexity sono gli stessi di WP Statistics (source_name).
 */
public const AI = array(
	'ChatGPT'    => array( 'chatgpt.com', 'chat.openai.com', 'com.openai.chatgpt' ),
	'Perplexity' => array( 'perplexity.ai', 'ai.perplexity.app.android' ),
	'Gemini'     => array( 'gemini.google.com', 'bard.google.com', 'com.google.android.apps.bard' ),
	'Copilot'    => array( 'copilot.microsoft.com', 'com.microsoft.copilot' ),
	'Claude'     => array( 'claude.ai', 'com.anthropic.claude' ),
	'DeepSeek'   => array( 'chat.deepseek.com' ),
	'Mistral'    => array( 'chat.mistral.ai' ),
	'Grok'       => array( 'grok.com' ),
	'Meta AI'    => array( 'meta.ai' ),
);
```

I nomi dei pacchetti Android (`com.openai.chatgpt`...) servono per i referrer `android-app://`.

**Frammento SQL** (metodi statici puri, coperti da `ChannelsTest`):

```php
public static function host_sql( string $column ): string
// TRIM(LEADING 'www.' FROM TRIM(TRAILING '/' FROM REPLACE(LOWER(TRIM({column})), 'android-app://', '')))

public static function ai_sql( string $host, string $utm_source = '', string $name = '' ): string
// CASE WHEN <condizione ChatGPT> THEN 'ChatGPT' WHEN ... ELSE '' END
```

- La condizione di un assistente è l'`OR` di tre parti, e una parte si omette se l'argomento è vuoto:
  - `{host} = 'd' OR {host} LIKE '%.d'`, per ogni dominio `d`;
  - `TRIM(LEADING 'www.' FROM LOWER(TRIM({utm_source}))) = 'd'`, per ogni dominio (confronto esatto, come `checkDomain()` di WP Statistics);
  - `{name} = 'Nome'`.
- I valori letterali vengono **solo** dalla costante. Un test controlla che ogni dominio corrisponda a `/^[a-z0-9.-]+$/` e ogni nome a `/^[A-Za-z0-9 .]+$/` (niente apici, `%` o `_`), così il frammento non si può rompere né iniettare.
- Gli assistenti si provano nell'ordine dell'elenco.

**Applicazione**, con `$ai` uguale al `CASE` del frammento:

| Sorgente | `$ai` |
|---|---|
| `OwnSource` | `Channels::ai_sql( 'h.referrer_host', 'h.utm_source' )` (oppure senza alias dove la query non lo usa). `referrer_host` è già un host normalizzato da `Channels::host()`. |
| `WpStatisticsSource` | `Channels::ai_sql( Channels::host_sql( 'v.referred' ), '', 'v.source_name' )`. |

In entrambe le sorgenti, ovunque si scrivono il canale o la sorgente:

- **canale:** `IF( {$ai} <> '', 'ai', <espressione di oggi> )`;
- **sorgente:** `IF( {$ai} <> '', {$ai}, <espressione di oggi> )`.

Le espressioni di oggi sono `COALESCE(NULLIF(..., ''), 'unassigned')` in `entry_daily`, `MAX(channel)` e `MAX(source)` in `referrer_daily` (diventano `MAX(IF(...))`), e le parti `channel` e `source` della `UNION` di `dim_daily`, con `COALESCE(NULLIF(..., ''), '(n/d)')`. In `WpStatisticsSource::DIMENSIONS` le voci `channel` e `source` diventano espressioni oppure si trattano a parte nel ciclo: la scelta è dell'implementatore, ma il risultato deve essere questo.

**Cosa non cambia:** `site_daily` e `object_daily`, `Channels::classify()`, l'elenco `SEARCH`, `wp5w_hits.channel` e `wp5w_hits.source` (che restano quelli di WP Statistics) e l'endpoint. Le somme dei visitatori per giorno (e per dimensione) restano identiche: si spostano solo valori di canale e sorgente.

**Effetti attesi sul blog:** 8 visitatori `wps` passano a `ai` nella finestra di WP Statistics (7 da `search`, 1 da `(n/d)`), con le sorgenti `ChatGPT` 6, `Perplexity` 1 e `Claude` 1. «Ingressi da ricerca» (dettaglio e opportunità della Panoramica) cala dei soli ingressi da ChatGPT e Perplexity.

**Limite noto, accettato:** una visita da un assistente che WP Statistics non conosce, senza referrer e solo con `utm_source` (per esempio `utm_source=claude.ai`), sarà `ai` nella raccolta propria e `(n/d)` in WP Statistics, perché `statistics_visitor` non salva i parametri UTM.

**Interfaccia:** `ChannelList.jsx` aggiunge l'etichetta `ai: 'Assistenti AI'` ed esporta `LABELS` (R10).

### R2. Etichette dei referrer delle app (`Support\Referrers`, classe pura)

```php
public static function label( string $referrer ): string
```

1. Si normalizza: minuscolo, senza spazi, senza `android-app://` iniziale e senza `/` finale. Chiamiamo `p` il risultato.
2. Se `p` è nell'elenco `APPS`, l'etichetta è «Nome (app)»:

   | Pacchetto | Nome |
   |---|---|
   | `com.linkedin.android` | LinkedIn |
   | `com.google.android.googlequicksearchbox` | Google |
   | `com.google.android.gm` | Gmail |
   | `com.slack` | Slack |
   | `com.facebook.katana` | Facebook |
   | `com.facebook.orca` | Messenger |
   | `com.instagram.android` | Instagram |
   | `com.whatsapp` | WhatsApp |
   | `org.telegram.messenger` | Telegram |
   | `com.twitter.android` | X |
   | `com.reddit.frontpage` | Reddit |
   | `com.microsoft.teams` | Teams |
   | `com.openai.chatgpt` | ChatGPT |

3. **Ripiego:** se `p` inizia con `com.`, `org.` o `net.` (un nome di pacchetto: un host pubblico non comincia con un dominio di primo livello), l'etichetta è «`p` (app)».
4. Altrimenti si restituisce `p` se il referrer iniziava con `android-app://` (per esempio `android-app://m.facebook.com/` diventa `m.facebook.com`, che è un host web e non un pacchetto), oppure il referrer così com'è.

**Dove si usa:** `AudienceQuery::referrers()` e i referrer di `PostsQuery::detail()` aggiungono `'label' => Referrers::label( $referrer )` a ogni riga, e lo stesso fa `OperationsQuery::not_found()` (R5). Nelle tabelle React si mostra `row.label`, con `row.referrer` nel `title`. I dati non cambiano.

### R3. Campagne UTM

**Aggregazione (`OwnSource`):** la `SELECT` di `dim_daily` aggiunge alla stessa `UNION ALL` tre parti, **senza** la subquery `$first`:

```sql
SELECT day, 'utm_campaign', LEFT(utm_campaign, 100), COUNT(DISTINCT visitor), 'own' FROM {hits} WHERE utm_campaign <> '' AND day BETWEEN %s AND %s GROUP BY day, 3
-- e così per 'utm_source' (utm_source) e 'utm_medium' (utm_medium)
```

- `required()` aggiunge `utm_source`, `utm_medium` e `utm_campaign`.
- La collation `utf8mb4_unicode_520_ci` raggruppa `LinkedIn` e `linkedin` nella stessa riga, sia nella `GROUP BY` sia nella chiave primaria, quindi non ci sono duplicati.
- Le righe `wps` non hanno mai dimensioni `utm_*`.

**Lettura (`AudienceQuery::campaigns( DateRange $range ): array`):**

```php
// ECCEZIONE ALLA REGOLA DELL'ORIGINE (come CompareQuery): WP Statistics non salva i parametri UTM, quindi le
// dimensioni utm_* esistono solo nelle righe own e si leggono sempre, qualunque sia wp5w_own_since.
```

- una `SELECT dim, value, SUM(visitors) FROM dim_daily WHERE origin = 'own' AND dim IN ('utm_campaign','utm_source','utm_medium') AND day BETWEEN %s AND %s GROUP BY dim, value`;
- `first_day` = `SELECT MIN(day) FROM dim_daily WHERE origin = 'own' AND dim = 'channel'` (il primo giorno della raccolta propria);
- risultato con la stessa forma del box «Pubblico», `Dimensions::top()` compreso:

```json
{ "range": { "from": "...", "to": "..." }, "first_day": "2026-10-14",
  "dims": { "utm_campaign": { "total": 0, "rows": [], "other": 0 }, "utm_source": { ... }, "utm_medium": { ... } } }
```

**Rotta:** `GET /wp5w/v1/admin/audience/campaigns`, con `check_admin_permission`, `respond()` e l'argomento `days` (`enum` 7, 30, 90, predefinito 30), come `/admin/audience/dimensions`.

**Interfaccia:** `assets/admin/components/Campaigns.jsx`, nella scheda Visitatori subito dopo «Pubblico». È una `Card` con `id="audience-campaigns"`, titolo «Campagne» con le date, gli stessi tre pulsanti di periodo e tre elenchi con barre: «Campagne», «Sorgenti» e «Mezzi». Si può estrarre l'elenco con le barre da `AudienceDimensions.jsx` in un componente comune, se evita di duplicare più di circa 30 righe. Le note sotto il box:

- sempre: «Dati della sola raccolta propria, disponibili dal {first_day}: WP Statistics non salva i parametri UTM. Un visitatore conta una volta per ogni valore e per ogni giorno; chi arriva senza parametri UTM non compare.»;
- con `first_day` nullo: «La raccolta propria non ha ancora dati.»;
- con `total` a 0 nelle tre dimensioni: «Nessuna visita con parametri UTM nel periodo.»

### R4. Online adesso

**`Query\OnlineQuery::get(): array`** (classe nuova, legge solo `wp5w_hits`, che è sempre `own`):

```php
$now    = time();
$cutoff = gmdate( 'Y-m-d H:i:s', $now - 5 * MINUTE_IN_SECONDS );
$days   = array( wp_date( 'Y-m-d', $now ), wp_date( 'Y-m-d', $now - DAY_IN_SECONDS ) );
```

```sql
SELECT h.object_type, h.object_id, MIN(h.uri) AS uri, COUNT(*) AS visitors
FROM {hits} h
JOIN ( SELECT MAX(id) AS id FROM {hits} WHERE day IN (%s, %s) AND ts >= %s GROUP BY visitor ) l ON l.id = h.id
GROUP BY h.object_type, h.object_id
ORDER BY visitors DESC, h.object_type, h.object_id
```

Risposta (`GET /wp5w/v1/admin/online`, senza argomenti, con `check_admin_permission` e `respond()`, quindi `no-store`):

```json
{ "minutes": 5, "visitors": 3, "tracking": true, "at": "22:41:05",
  "contents": [ { "type": "post", "id": 123, "title": "...", "url": "https://...", "visitors": 2 } ],
  "other": 0 }
```

- `visitors` è la somma di tutti i gruppi; `contents` sono i primi 10 e `other` la somma degli altri.
- `tracking` è `Settings::get()['own_tracking']`, e `at` è `wp_date( 'H:i:s' )`.
- **Titolo:** se `ObjectType::is_post( $type, $id )` (R10), si usano `get_the_title()` e `get_permalink()`; altrimenti si usano l'URI e `home_url( $uri )`, con un nome fisso per `home` con ID 0 («Home»), `404` («Pagina non trovata») e `search` («Ricerca»).
- **Nessun identificativo:** la risposta non contiene hash, orari delle singole visite, paesi, referrer né altri dati per visitatore.

**Interfaccia:** `assets/admin/components/OnlineNow.jsx`, primo elemento della scheda Visitatori, in una `Card` con `id="audience-online"` e titolo «Online adesso» (con «aggiornato alle {at}»):

- il numero grande dei visitatori, poi l'elenco dei contenuti (titolo con link e visitatori) e «Altri: N» se `other` è maggiore di 0;
- **polling:** si carica al montaggio, poi ogni 30 secondi con `setInterval`, **solo mentre `document.visibilityState === 'visible'`**. Con l'evento `visibilitychange` si ferma l'intervallo quando la scheda del browser è nascosta, e quando torna visibile si ricarica subito e si riparte. Allo smontaggio si tolgono intervallo e listener. Un errore mostra un `Notice` e non ferma il polling;
- note: «Visitatori con almeno una visita negli ultimi 5 minuti, ognuno sul contenuto che ha aperto per ultimo. Gli utenti esclusi dalle Opzioni non compaiono.» Con `tracking` falso: «La raccolta propria è spenta nelle Opzioni.»

### R5. Pagine 404

**Endpoint (`HitController::handle()`):** dopo la `INSERT` del passo 10, un passo 11:

```php
// Passo 11: aggregati giornalieri di 404 e ricerche (solo per le visite registrate, senza legame con il visitatore).
if ( '404' === $type ) {
	// INSERT INTO {notfound} (day, uri, referrer, hits, last_seen) VALUES (%s, %s, %s, 1, %s)
	// ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen)
}
```

- `day` = `wp_date( 'Y-m-d' )`; `uri` = lo stesso `relative_uri()` della visita (percorso senza query string né frammento, al massimo 190 caratteri); `referrer` = `substr( Channels::host( $r, '' ), 0, 190 )` (F15); `last_seen` = `gmdate( 'Y-m-d H:i:s' )` (UTC).
- **Niente visitatore:** né hash né ID di `wp5w_hits`.
- La visita in `wp5w_hits` resta com'è (tipo `404`, ID 0), quindi anche il conteggio delle visualizzazioni in `object_daily`.

**Lettura (`Query\OperationsQuery::not_found( DateRange $range ): array`):**

```sql
SELECT uri, referrer, SUM(hits) AS hits, MAX(last_seen) AS last_seen FROM {notfound}
WHERE day BETWEEN %s AND %s GROUP BY uri, referrer ORDER BY hits DESC, last_seen DESC LIMIT 100
```

più i totali `SUM(hits)` e `COUNT(DISTINCT uri)` del periodo. Ogni riga contiene `uri`, `url` (`home_url( $uri )`), `referrer`, `label` (`Referrers::label()`, oppure `''` se il referrer è vuoto), `internal` (vero se il referrer è l'host del sito senza `www.`), `hits` e `last_seen` (convertito con `get_date_from_gmt( ..., 'Y-m-d H:i' )`).

**Rotta:** `GET /wp5w/v1/admin/notfound?days=7|30|90` (predefinito 30), come le altre.

**Interfaccia:** `assets/admin/components/NotFound.jsx`, nella scheda Articoli, in una `Card` con `id="posts-notfound"`, titolo «Pagine non trovate» con le date e i pulsanti del periodo:

- tabella `widefat striped wp5w-table` con le colonne «Indirizzo» (link all'URL, con l'URI decodificato tramite `decodeURI` in un `try`), «Provenienza» («Link interno» se `internal`, «Diretto o sconosciuto» se il referrer è vuoto, altrimenti `label`), «Visite» e «Ultima»; `ShowMore` a 15 righe;
- note: «Visite a indirizzi inesistenti registrate dal tracker. Le immagini mancanti non compaiono: il server risponde senza passare da WordPress.»

### R6. Ricerche interne

**Classe pura `Support\SearchTerm`:**

| Elemento | Regola |
|---|---|
| `MAX = 60` | Lunghezza massima in caratteri. |
| `FILTERED = '(filtrato)'` | Valore salvato al posto di un termine privato. |
| `normalize( string $raw ): string` | Toglie i caratteri di controllo, sostituisce ogni sequenza di spazi (`\s`, Unicode) con uno spazio, fa `trim`, `mb_strtolower`, `mb_substr( 0, 60 )` e di nuovo `trim`. |
| `is_private( string $term ): bool` | Vero se contiene un'email (`/[^\s@]+@[^\s@]+\.[^\s@]+/u`) oppure almeno 9 cifre consecutive, anche separate da un solo spazio, punto, trattino o barra (`/\d(?:[\s.\/-]?\d){8,}/u`): telefoni, IBAN, carte, partite IVA. |
| `store( string $raw ): string` | `normalize()`, poi `''` se vuoto, `FILTERED` se `is_private()` è vero, altrimenti il termine normalizzato. |

**Tracker PHP (`Tracker::print_data()`):** se `is_search() && ! is_paged()` e `SearchTerm::normalize( get_search_query( false ) )` non è vuoto, il blocco aggiunge `q` (il termine normalizzato, già troncato) e `n` (`(int) $GLOBALS['wp_query']->found_posts`). Firma, tipo e ID non cambiano. Il JSON passa già dai flag `JSON_HEX_*`.

**Tracker JS:** nel ramo senza DNT, dopo gli UTM:

```js
// 5) Ricerca interna: termine e numero di risultati (solo nella prima pagina dei risultati).
if ( d.q ) {
	p.set( 'q', d.q );
	p.set( 'n', d.n );
}
```

**Endpoint, passo 11 (continua):**

```php
} elseif ( 'search' === $type ) {
	$term = SearchTerm::store( $this->text( $request->get_param( 'q' ), 200 ) );
	if ( '' !== $term ) {
		$n = $this->param( $request, 'n' );
		// INSERT INTO {search} (day, term, searches, empty) VALUES (%s, %s, 1, %d)
		// ON DUPLICATE KEY UPDATE searches = searches + 1, empty = empty + VALUES(empty)
		// empty = 1 solo se $n === '0'; un n mancante o non numerico (1 !== preg_match( '/^\d{1,7}$/', $n )) conta come ricerca con risultati sconosciuti, cioè empty = 0.
	}
}
```

- `q` si legge con `text()`, che scarta gli array senza warning.
- Con un tipo diverso da `search`, `q` e `n` si ignorano.

**Lettura (`OperationsQuery::searches( DateRange $range ): array`):** `SELECT term, SUM(searches), SUM(empty) ... GROUP BY term ORDER BY 2 DESC, term LIMIT 100`, più i totali delle ricerche e di quelle senza risultati. **Rotta:** `GET /wp5w/v1/admin/searches?days=7|30|90`.

**Interfaccia:** `assets/admin/components/SiteSearches.jsx`, nella scheda Articoli dopo «Pagine non trovate», con `id="posts-searches"` e titolo «Ricerche interne»:

- in alto i totali, nella forma «N ricerche, di cui M senza risultati»;
- tabella con «Termine» (`(filtrato)` diventa «Termine nascosto (email o numero lungo)»), «Ricerche» e «Senza risultati» (evidenziato se uguale a «Ricerche»);
- nota: «Termini normalizzati (minuscole, al massimo 60 caratteri). Si conta solo la prima pagina dei risultati.»

### R7. Visite nell'elenco dei contenuti e nella barra di amministrazione

**`PostsQuery`** (così le letture restano sotto il `grep` della regola dell'origine):

```php
public function views_by_ids( string $type, array $ids, DateRange $range ): array // array<int,int>
// Una sola SELECT object_id, SUM(views) ... WHERE object_type = %s AND object_id IN (%d,...) AND day BETWEEN %s AND %s AND {Origin::where()} GROUP BY object_id
public function views_order_clauses( string $type, DateRange $range ): array // array{join: string, field: string}
// LEFT JOIN ( SELECT object_id, SUM(views) AS wp5w_views FROM {object_daily} WHERE object_type = %s AND day BETWEEN %s AND %s AND {origin} GROUP BY object_id ) wp5w ON wp5w.object_id = {$wpdb->posts}.ID
```

Il periodo è `DateRange::last_days( 30, wp_date( 'Y-m-d' ) )`, e il tipo è `PostsQuery::object_type( $post_type )` (`post` o `page`). Con una lista di ID vuota non si fa nessuna query.

**Classe `Admin\PostViews`** (con il guard ABSPATH), registrata in `Plugin::init()`:

- **Colonna:** solo se `current_user_can( 'manage_options' )`, con i filtri `manage_post_posts_columns` e `manage_page_posts_columns` (chiave `wp5w_views`, intestazione «Visite (30 g)» e un `title` che dice «Visualizzazioni degli ultimi 30 giorni. Clic per il dettaglio.»).
- **Una sola query per pagina:** alla prima chiamata di `manage_{post,page}_posts_custom_column`, si raccolgono gli ID di `$GLOBALS['wp_query']->posts` e si chiama `views_by_ids()` una volta, salvando il risultato in una proprietà. Le righe successive leggono la proprietà.
- **Cella:** un link `admin.php?page=wp5w&tab=posts&detail=<ID>` con il numero formattato (`number_format_i18n`), oppure «0».
- **Ordinamento:** `manage_edit-post_sortable_columns` e `manage_edit-page_sortable_columns` aggiungono `wp5w_views`. Il filtro `posts_clauses` agisce solo se `is_admin()`, `$query->is_main_query()`, il `post_type` è `post` o `page`, `orderby` vale `wp5w_views` e l'utente ha `manage_options`. Aggiunge il `join` di `views_order_clauses()` e sostituisce `orderby` con `COALESCE(wp5w.wp5w_views, 0) {ASC|DESC}, {$wpdb->posts}.ID {ASC|DESC}`. L'ordine si legge con `get( 'order' )` e solo `ASC` o `DESC` sono ammessi.
- **Barra:** `admin_bar_menu` (priorità 90). Si attiva nel frontend (`! is_admin()`) con `is_singular( array( 'post', 'page' ) )` e `manage_options`. Il nodo ha `id` `wp5w-views`, titolo «5W: N visite (30 g)» e lo stesso link della colonna, e costa una sola chiamata a `views_by_ids()` con un ID.

**Limite noto:** la pagina impostata come home ha le visite sotto il tipo `home`, quindi nella colonna mostra solo quelle registrate come `page`. Il modale di dettaglio fa lo stesso oggi.

### R8. Apertura del modale dall'URL

- **`AdminMenu::enqueue_assets()`:** `$detail = absint( $_GET['detail'] ?? 0 )` (con un `phpcs:ignore` puntuale: è un parametro di navigazione in sola lettura, e la pagina richiede già `manage_options`). Se il post esiste, è di tipo `post` o `page` e `current_user_can( 'read_post', $detail )`, si aggiunge a `wp5wAdmin` la chiave `detail` con `id`, `title` (`html_entity_decode( get_the_title() )`) e `date` (`get_the_date( 'Y-m-d' )`). Altrimenti la chiave non c'è.
- **`PostsPage.jsx`:** lo stato iniziale di `detail` è `window.wp5wAdmin.detail ? { post: window.wp5wAdmin.detail, period: '30' } : null`. Alla chiusura, se l'URL contiene `detail`, lo si toglie con `history.replaceState`, così ricaricando la pagina il modale non si riapre.
- La scheda iniziale arriva già da `?tab=posts` (`index.jsx`).

### R9. Tracker

- Cambia solo l'invio di `q` e `n` (R6). Prima della PR si misura il peso compilato: oggi è 669 byte, quello nuovo va riportato nella PR e deve restare sotto 1.536 (F23).
- Il tracker cambia, quindi dopo il deploy va svuotata la cache di LiteSpeed (10.5).

### R10. Finding minori della review della fase 2

1. **Canale non assegnato in due righe.** In `CompareQuery::channels()`, al passo 1, `(n/d)` diventa `unassigned` prima di raggruppare (le due origini si sommano nella stessa riga). `ChannelList.jsx` esporta `LABELS` (con `ai`), e `Compare.jsx` mostra `LABELS[ row.channel ] ?? row.channel`.
2. **Tipi `tax_*`.** Una classe pura nuova, `Support\ObjectType`, con `is_post( string $type, int $id ): bool`, vero se `$id > 0` e il tipo è `post`, `page`, `home` o inizia con `post_type_`. La usano `CompareQuery::top()` (al posto di `NON_POST_TYPES`, che si toglie) e `OnlineQuery`.
3. **`phpcs:disable` troppo largo.** Il `phpcs:disable` di `CompareQuery` torna uguale a quello delle altre classi `Query` (`DirectDatabaseQuery`, `InterpolatedNotPrepared`, `NotPrepared`). Sulle `prepare()` con `in_days()` si mette un `phpcs:ignore` puntuale con la motivazione («segnaposto generati per ogni giorno»), e `UnfinishedPrepare` solo dove PHPCS lo segnala davvero.
4. **Testi fissi in `Compare.jsx`.** Soglia e minimo arrivano dalla risposta (`threshold` c'è già; `CompareQuery::get()` aggiunge `'min_views' => Comparison::MIN_VIEWS`). Le frasi si costruiscono con `sprintf()` e `_n()` di `@wordpress/i18n`, compresa la riga di copertura («%d giorno completo confrontato, dal %s al %s.»).

### R11. Disinstallazione, disattivazione e rollback

- `uninstall.php` aggiunge `notfound_daily` e `search_daily` all'elenco del `DROP TABLE`, che resta sotto `WP5W_DELETE_DATA`.
- La disattivazione non cambia (nessun evento cron nuovo).
- **Rollback alla 0.9.0:** la 0.9.0 vede `wp5w_db_version = 5`, diverso dal suo `4`. `install()` non trova chiavi da migrare, `dbDelta` non tocca nulla e la versione torna a `4`. Le due tabelle nuove restano senza essere usate e senza fare danni. Il runbook (10.12) le tiene, perché una 0.10.x corretta le riusa, e le cita nella nota.

---

## 5. Schema del database e migrazione alla versione 5

`Schema::DB_VERSION` diventa `'5'`. `create_tables()` aggiunge:

```sql
-- Pagine non trovate per giorno, URI e host del referrer: nessun visitatore, solo conteggi (fase 4).
CREATE TABLE {prefix}wp5w_notfound_daily (
	day date NOT NULL,
	uri varchar(190) NOT NULL,
	referrer varchar(190) NOT NULL DEFAULT '',
	hits int(10) unsigned NOT NULL DEFAULT 0,
	last_seen datetime NOT NULL,
	PRIMARY KEY  (day,uri,referrer)
) {charset};

-- Ricerche interne per giorno e termine normalizzato: nessun orario e nessun visitatore (fase 4).
CREATE TABLE {prefix}wp5w_search_daily (
	day date NOT NULL,
	term varchar(60) NOT NULL,
	searches int(10) unsigned NOT NULL DEFAULT 0,
	empty int(10) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (day,term)
) {charset};
```

- `last_seen` è in UTC, come `wp5w_hits.ts`.
- La chiave di `notfound_daily` pesa al massimo 3 + 760 + 760 byte, sotto il limite di 3.072 di InnoDB con `ROW_FORMAT=DYNAMIC` (1.4).
- **Migrazione:** da `4` a `5` non serve un `ALTER`. `install()` (stesso lock, stessa rilettura della versione) chiama `migrate_primary_keys()` (che non fa nulla) e `create_tables()` (che crea le due tabelle), poi salva `5`. Da `3` (le installazioni molto vecchie) si arriva a `5` in un passaggio solo.
- **Finché la migrazione non è avvenuta, l'endpoint ignora le visite** (passo 1, versione diversa). La migrazione parte con la prima richiesta dopo l'installazione, e il runbook la forza subito (10.2).
- Il commento di testa di `Schema` («niente IP, hash o dati personali») resta vero anche per le due tabelle.

---

## 6. File da creare o modificare

### Da creare

| File | Contenuto |
|---|---|
| `src/Support/Referrers.php` | R2. Classe pura senza guard ABSPATH. |
| `src/Support/SearchTerm.php` | R6, parte pura. |
| `src/Support/ObjectType.php` | R10.2. |
| `src/Query/OnlineQuery.php` | R4. |
| `src/Query/OperationsQuery.php` | `not_found()` (R5) e `searches()` (R6). |
| `src/Admin/PostViews.php` | R7: colonna, ordinamento e barra di amministrazione. |
| `assets/admin/components/OnlineNow.jsx` | R4. |
| `assets/admin/components/Campaigns.jsx` | R3. |
| `assets/admin/components/NotFound.jsx` | R5. |
| `assets/admin/components/SiteSearches.jsx` | R6. |
| `tests/Unit/ReferrersTest.php`, `tests/Unit/SearchTermTest.php`, `tests/Unit/ObjectTypeTest.php` | Sezione 7. |

### Da modificare

| File | Modifica |
|---|---|
| `src/Support/Channels.php` | Costante `AI`, `host_sql()` e `ai_sql()` (R1). `classify()` e `SEARCH` non cambiano. |
| `src/Sources/OwnSource.php` | Canale e sorgente con `ai` (R1), dimensioni `utm_*` e colonne UTM in `required()` (R3). |
| `src/Sources/WpStatisticsSource.php` | Canale e sorgente con `ai` in `entry_daily`, `referrer_daily` e `dim_daily` (R1). |
| `src/Database/Schema.php` | `DB_VERSION = '5'` e le due tabelle (sezione 5). |
| `src/REST/HitController.php` | Passo 11 (R5 e R6) e il commento di testa (le query per tipo di visita, F14). |
| `src/Front/Tracker.php` | `q` e `n` nel blocco (R6). |
| `assets/tracker/tracker.js` | Invio di `q` e `n` (R6). |
| `src/Query/AudienceQuery.php` | `campaigns()` (R3) e `label` nei referrer (R2). |
| `src/Query/PostsQuery.php` | `views_by_ids()` e `views_order_clauses()` (R7), e `label` nei referrer del dettaglio (R2). |
| `src/Query/CompareQuery.php` | R10.1, R10.2 e R10.3, più `min_views` nella risposta (R10.4). |
| `src/REST/AdminController.php` | Rotte `GET /admin/online`, `/admin/audience/campaigns`, `/admin/notfound` e `/admin/searches`. Il costruttore riceve `OnlineQuery` e `OperationsQuery`. |
| `src/Plugin.php` | Costruzione delle query nuove e `( new PostViews( ... ) )->register()`. |
| `src/Admin/AdminMenu.php` | `wp5wAdmin.detail` (R8). |
| `uninstall.php` | R11. |
| `assets/admin/components/ChannelList.jsx` | `export const LABELS`, con `ai`. |
| `assets/admin/components/Compare.jsx` | R10.1 e R10.4. |
| `assets/admin/pages/AudiencePage.jsx` | `<OnlineNow />` in cima, `<Campaigns />` dopo `<AudienceDimensions />`, e `label` nella tabella «Provenienza». |
| `assets/admin/pages/PostsPage.jsx` | Modale da URL (R8), `<NotFound />` e `<SiteSearches />`. |
| `assets/admin/components/PostDetailModal.jsx` | `label` nei referrer del dettaglio. |
| `assets/admin/styles/admin.scss` | Solo se serve (numero grande di «Online adesso»). |
| `tests/Unit/ChannelsTest.php` | Sezione 7. |
| `tests/fixtures/seed.php` | Righe AI e app in `statistics_visitor` (sezione 8). |
| `tests/fixtures/verify.php` | Sezione 8. |
| `CLAUDE.md` | In «Struttura»: `Referrers`, `SearchTerm`, `ObjectType`, `OnlineQuery`, `OperationsQuery`, `Admin\PostViews`, le due tabelle e lo schema v5. In «Punti da ricordare»: il canale `ai` si calcola in aggregazione con `Channels::ai_sql()` sulle due origini, e i dati grezzi restano con i canali di WP Statistics; le dimensioni `utm_*` sono solo `own` e fuori dall'invariante; la regola dell'origine ha **due** eccezioni, `CompareQuery` e `AudienceQuery::campaigns()` (solo `utm_*` e primo giorno `own`), e il comando `grep` va aggiornato; il tipo `404` del blocco firmato è il flag delle pagine non trovate; `q` e `n` non sono firmati e li decide `SearchTerm` nell'endpoint; le immagini mancanti non passano dal tracker; dopo un cambio della regola AI serve un ETL dalla prima data di `statistics_visitor`. |
| `README.md` | Breve sezione sulle funzioni nuove: «Online adesso», campagne, 404, ricerche e colonna «Visite (30 g)». |
| `CHANGELOG.md` | Voce `0.10.0`. La versione la scrive `npm run release -- 0.10.0`. |

Restano **invariati** `Support\Origin`, `Support\Dimensions`, `Support\Comparison`, `Geo\CountryDatabase`, `Etl\Scheduler`, `Deactivator.php` e `scripts/check-build.js` (il tetto resta 1.536).

---

## 7. Test PHPUnit da aggiungere

### `ChannelsTest` (in aggiunta ai casi esistenti, che non cambiano)

- **`AI`:** ogni dominio corrisponde a `/^[a-z0-9.-]+$/`, ogni nome a `/^[A-Za-z0-9 .]+$/`, e nessun dominio compare due volte.
- **`host_sql( 'v.referred' )`** è uguale alla stringa attesa di R1.
- **`ai_sql()`:**
  - con i tre argomenti contiene, per ogni assistente, `THEN '<nome>'` una volta e ogni dominio due volte nella forma `= '<d>'` (host e `utm_source`) e una nella forma `LIKE '%.<d>'`;
  - con `utm_source` vuoto non contiene `utm`, e con `name` vuoto non contiene `= 'ChatGPT'` fuori dai `THEN`;
  - finisce con `ELSE '' END`;
  - ogni valore letterale tra apici del frammento viene dalla costante `AI` (estratto con una regex e confrontato).
- **`classify()` non cambia:** `chatgpt.com` dà ancora `search`/`ChatGPT`, e `claude.ai` dà `referral`/`claude.ai`.

La prova del comportamento in SQL è in `verify.php` (sezione 8, punto 2).

### `ReferrersTest`

| Ingresso | Etichetta |
|---|---|
| `android-app://com.linkedin.android/` | `LinkedIn (app)` |
| `com.linkedin.android` | `LinkedIn (app)` |
| `ANDROID-APP://com.google.android.googlequicksearchbox/` | `Google (app)` |
| `android-app://com.slack/` | `Slack (app)` |
| `com.openai.chatgpt` | `ChatGPT (app)` |
| `android-app://com.example.reader/` | `com.example.reader (app)` |
| `org.example.app` | `org.example.app (app)` |
| `android-app://m.facebook.com/` | `m.facebook.com` |
| `google.com` | `google.com` |
| `lm.facebook.com` | `lm.facebook.com` |
| `''` | `''` |

### `SearchTermTest`

- `normalize()`: `'  WordPress   Plugin '` dà `'wordpress plugin'`; tabulazioni e a capo diventano uno spazio; `'ÀÈÌ'` dà `'àèì'`; 100 caratteri danno 60 (multibyte compresi); i caratteri di controllo si tolgono.
- `is_private()`: `mario.rossi@example.com` e `scrivi a a@b.it` sono veri; `333 1234567`, `+39 333-123-4567`, `IT60X0542811101000000123456` e `4111 1111 1111 1111` sono veri; `2005 2006`, `iphone 15`, `calendario 2006`, `php 8.3` e `@mavida` sono falsi.
- `store()`: un termine vuoto o di soli spazi dà `''`; un'email dà `(filtrato)`; `'  Topolinux '` dà `'topolinux'`.

### `ObjectTypeTest`

`post`, `page` e `home` con ID 5 sono veri; `post_type_attachment` con ID 5 è vero; `home` con ID 0 è falso; `category`, `post_tag`, `tax_genere`, `author`, `archive`, `404`, `search` e `loginpage` con ID 5 sono falsi.

`composer ci` (PHPCS, PHPStan livello 6 e PHPUnit) deve passare senza nuove esclusioni globali.

---

## 8. Controlli in `tests/fixtures/seed.php` e `tests/fixtures/verify.php`

### `seed.php`

Su un giorno di dettaglio (per esempio 10 giorni fa), con il primo articolo come pagina d'ingresso, si aggiungono 6 visitatori a `statistics_visitor` (con le relazioni e la pagina coerenti con il resto del seed):

| `referred` | `source_channel` | `source_name` |
|---|---|---|
| `chatgpt.com` | `search` | `ChatGPT` |
| (vuoto) | `search` | `ChatGPT` |
| `perplexity.ai` | `search` | `Perplexity` |
| `claude.ai` | `NULL` | `NULL` |
| `android-app://com.openai.chatgpt/` | `NULL` | `NULL` |
| `android-app://com.linkedin.android/` | `NULL` | `NULL` |

I controlli esistenti che usano numeri scritti a mano vanno adeguati, se ce ne sono. Quelli che calcolano l'atteso dalle tabelle sorgente non cambiano.

### Aggiornamenti delle sezioni esistenti

- **Sezione 1:** versione `5`, e le tabelle `notfound_daily` e `search_daily` esistono con le chiavi primarie di sezione 5.
- **Sezione 2** (migrazione da v3): alla fine la versione è `5` e le due tabelle esistono. Si aggiunge il caso **da v4 a v5**: con lo schema v4 completo, senza le due tabelle e con la versione `4`, `install()` crea le due tabelle, non esegue nessun `ALTER` e salva `5`. Rieseguito, non cambia `SHOW CREATE TABLE`.
- **Sezioni 3 e 9:** le somme per dimensione delle righe `wps` restano uguali a `statistics_visitor` (il canale `ai` sposta valori, non visitatori).
- **Sezione 4** (istantanea): non si aggiungono le campagne, che di proposito leggono righe `own`. «Online adesso», 404 e ricerche leggono tabelle senza origine e restano fuori anche loro.
- **Sezioni 6 e 11:** ogni controllo dell'invariante delle dimensioni elenca le dimensioni in modo esplicito e **esclude** `utm_*` (F6).
- **Sezione 10** (Confronto): `(n/d)` lato `wps` e `unassigned` lato `own` danno una sola riga `unassigned` (R10.1); la risposta contiene `min_views` uguale a 10.

### Nuova sezione 12. «Fase 4»

Va dopo la sezione 11, e la pulizia finale diventa la sezione 13. Si usano `$check`, `$send_hit`, `$payload`, `$rest_call` e `$admin_user`.

1. **Canale `ai` sulle righe `wps`** (dopo l'import del seed):
   - nel giorno del seed, `dim_daily` `wps` con `dim = 'channel'` ha `ai` = 5; `dim = 'source'` ha `ChatGPT` = 3 (referrer, `source_name` senza referrer e pacchetto Android), `Perplexity` = 1 e `Claude` = 1;
   - la riga `android-app://com.linkedin.android/` resta `(n/d)`;
   - `SUM(visitors)` di `channel` e di `source` di quel giorno è uguale a `COUNT(*)` di `statistics_visitor`;
   - `entry_daily` ha 5 visitatori `ai` sul primo articolo; `referrer_daily` ha `claude.ai` con il canale `ai` e la sorgente `Claude`.
2. **Canale `ai` sulle righe `own`:** visite valide con `r=https://claude.ai/chat/x`; con `r` vuoto e `us=chatgpt.com`; con `r=https://www.perplexity.ai/search`; con `r=android-app://com.openai.chatgpt/`; e con `r=https://www.google.com/` (IP diversi, così sono 5 visitatori). Poi:
   - in `wp5w_hits` i canali grezzi **non cambiano**: `referral`, `unassigned`, `referral`, `referral` e `search`;
   - dopo `run_hourly()`, `dim_daily` `own` di oggi ha `ai` = 4 e `search` almeno 1, con le sorgenti `Claude`, `ChatGPT` (2) e `Perplexity`; `entry_daily` e `referrer_daily` hanno righe `ai`;
   - l'invariante delle sei dimensioni (senza `utm_*`) è rispettato.
3. **Confronto:** se nella finestra cadono giorni con righe `ai` in entrambe le origini (simulandoli come nella sezione 10), `channels` contiene una riga `ai` con `wps` e `own` valorizzati.
4. **Etichette:** in `AudienceQuery::get()['referrers']['rows']` e nei referrer del dettaglio, la riga `android-app://com.linkedin.android/` ha `label` uguale a `LinkedIn (app)`. Una visita `own` con `r=android-app://com.linkedin.android/` produce `referrer_daily.referrer = 'com.linkedin.android'`, anche lui con `label` `LinkedIn (app)`.
5. **UTM:**
   - tre visite di due visitatori con `uc=autunno`, `us=linkedin`, `um=social`, e una senza UTM; poi `run_hourly()`;
   - `dim_daily` `own` di oggi ha `utm_campaign/autunno` = 2, `utm_source/linkedin` = 2 e `utm_medium/social` = 2; nessuna riga `utm_*` ha `(n/d)` o un valore vuoto; nessuna riga `wps` ha `dim LIKE 'utm%'`;
   - `GET /admin/audience/campaigns?days=30` risponde 401 o 403 senza utente, 200 con l'amministratore (`no-store`) e 400 con `days=15`;
   - `campaigns()` dà gli stessi numeri sia con `wp5w_own_since` vuota sia con la data di oggi, e `first_day` è uguale a `MIN(day)` delle righe `own` di `channel`.
6. **Online adesso:** si svuota `wp5w_hits` e si inseriscono direttamente:
   - il visitatore A con due visite (post 1 sette minuti fa, post 2 un minuto fa);
   - il visitatore B sul post 2, adesso;
   - il visitatore C sulla home (ID 0) con `day` = ieri e `ts` = 60 secondi fa (a cavallo della mezzanotte);
   - il visitatore D sul post 1, sei minuti fa (fuori dalla finestra).

   `OnlineQuery::get()` dà `visitors` = 3, il post 2 con 2 visitatori e la home con 1 e il titolo «Home», e il post 1 non c'è. Il JSON della risposta non contiene l'esadecimale di nessun hash, né le chiavi `visitor` o `ts`. La rotta risponde 401 o 403 senza utente e 200 (`no-store`) con l'amministratore.
7. **404:**
   - una visita `t=404&id=0` con la firma valida, `u=/manca/` e `r=https://www.esterno.it/pagina?x=1` dà una riga (oggi, `/manca/`, `esterno.it`, 1);
   - la seconda visita uguale porta `hits` a 2 e `last_seen` maggiore o uguale;
   - con `r=` l'URL di un articolo del sito, il referrer è l'host del sito;
   - un 404 da Googlebot non cambia la tabella (scarto `bot`), e nemmeno una visita a un articolo;
   - **URI codificato (F25):** `u=/citt%C3%A0/post%E2%80%9D/` salva `/citt%C3%A0/post%E2%80%9D/` e non `/citt/post/`; `u=/pagina%20con%20spazi/?x=1#y` salva `/pagina%20con%20spazi/`; `u=/<b>x"y/` salva `/bxy/`;
   - con il filtro `query`, una 404 registrata fa esattamente 3 query: la `SELECT` e la `INSERT` su `wp5w_hits`, poi la `INSERT ... ON DUPLICATE KEY UPDATE` su `notfound_daily`;
   - `OperationsQuery::not_found()` restituisce le righe con `internal` e `label` corretti, e la rotta ha gli stessi permessi e lo stesso controllo di `days` delle altre.
8. **Ricerche:**
   - `t=search&id=0` con `q='  WordPress   Plugin '&n=5` dà `wordpress plugin` con `searches` 1 ed `empty` 0;
   - con `n=0`, `empty` sale a 1;
   - `q=mario.rossi@example.com` e `q=333 1234567` vanno in `(filtrato)` (2 ricerche);
   - un `q` di 100 caratteri dà un termine di 60;
   - `q=50%ab` e `q=<div>` si contano così come sono (F26);
   - con `q` vuoto o di soli spazi non si scrive nessuna riga, e la visita fa 2 query;
   - `q` con `t=post` e un `q[]=x` (array) non scrivono righe e non danno warning (stesso gestore di errori dei parametri array);
   - una ricerca registrata fa 3 query;
   - `SHOW COLUMNS` di `search_daily` dà esattamente `day`, `term`, `searches`, `empty`, e quello di `notfound_daily` `day`, `uri`, `referrer`, `hits`, `last_seen` (nessuna colonna del visitatore).
9. **Blocco del tracker in una ricerca:** con una query principale di ricerca simulata (`$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 's' => 'Calendario' ) )`), l'output di `print_data()`, catturato con un buffer, contiene `"type":"search"`, `"q":"calendario"` e `"n":` seguito dal numero dei risultati. Con `paged` = 2 non contiene `"q"`, e con un articolo singolo nemmeno.
10. **Visita normale:** una visita registrata a un articolo fa ancora **2** query (stesso metodo della sezione 11, punto 8).
11. **Visite nell'admin:**
    - `views_by_ids( 'post', $seed_posts, 30 giorni )` coincide, per ogni ID, con `SUM(views)` di `object_daily` `wps` sugli stessi giorni;
    - con `$GLOBALS['wp_query']->posts` impostato sui post del seed e l'amministratore, il rendering della colonna per tutte le righe (con `ob_start()`) esegue **una sola** query su `object_daily` (filtro `query`) e stampa link con `detail=<ID>`;
    - **ordinamento:** in `wp eval-file` `is_admin()` è falso e una `WP_Query` nuova non è la query principale, quindi la guardia del filtro di `PostViews` non scatterebbe. Si prova il costruttore delle clausole: un filtro `posts_clauses` di prova (aggiunto e poi tolto in verify) applica `views_order_clauses( 'post', 30 giorni )` a una `WP_Query` di `post`, e gli ID tornano ordinati come le somme, in modo decrescente e crescente. La guardia si prova a parte chiamando il callback di `PostViews` con una query che non è la principale: le clausole non cambiano;
    - un utente senza `manage_options` non riceve la colonna;
    - nella barra di amministrazione (`WP_Admin_Bar` con `admin_bar_menu` lanciato su un articolo singolo simulato) c'è il nodo `wp5w-views` con il numero e il link `detail`.
12. **Modale da URL:** con `$_GET['detail']` = un articolo del seed, i dati localizzati da `AdminMenu` (letti con `wp_scripts()->get_data( 'wp5w-admin', 'data' )` dopo `enqueue_assets( 'toplevel_page_wp5w' )`, che richiede `build/admin/index.asset.php`, presente in wp-env dopo `npm run build`) contengono `detail` con `id`, `title` e `date`. Con un ID inesistente, o con un ID di un tipo diverso da `post` e `page`, `detail` non c'è.

### Sezione 13 (pulizia finale)

Oltre a quella di oggi: `TRUNCATE` di `notfound_daily` e `search_daily`, e cancellazione dei visitatori aggiunti dal seed (oppure si lascia che il seed li ricrei: il seed fa `TRUNCATE` delle tabelle di WP Statistics).

---

## 9. Criteri di accettazione

Ogni criterio va nella checklist della PR, con l'esito.

1. Il passo 0 (sezione 2) è stato eseguito se la data lo permette, e la PR riporta i numeri con l'esito della tabella di decisione. Con «proxy sospetto» o «raccolta ferma o parziale» il lavoro si è fermato e Maurizio è stato avvisato.
2. `composer ci` passa: PHPCS, PHPStan livello 6 e PHPUnit, compresi `ReferrersTest`, `SearchTermTest`, `ObjectTypeTest` e i casi nuovi di `ChannelsTest`. Nessuna nuova esclusione globale, solo `phpcs:ignore` puntuali e motivati. Il `phpcs:disable` di `CompareQuery` è uguale a quello delle altre classi `Query` (R10.3).
3. `npm run check` passa. La PR riporta il peso di `build/tracker/tracker.js` prima (669 byte) e dopo, e il nuovo è sotto 1.536 byte.
4. `seed.php` seguito da `verify.php` termina con «Tutti i controlli superati», comprese le sezioni aggiornate (1, 2, 3, 6, 9, 10, 11) e tutta la nuova sezione 12.
5. **Canale `ai`:** le due origini lo calcolano in aggregazione con lo stesso `Channels::ai_sql()` costruito da `Channels::AI` (verify 12.1 e 12.2). `wp5w_hits`, `Channels::classify()` e `SEARCH` non cambiano. Le somme dei visitatori per giorno e dimensione delle righe `wps` restano uguali a `statistics_visitor`.
6. **Etichette:** le due forme dei referrer delle app hanno la stessa etichetta, e i dati delle tabelle non cambiano (verify 12.4).
7. **UTM:** `dim_daily` riceve righe `own` per `utm_campaign`, `utm_source` e `utm_medium`, senza `(n/d)`. Il box «Campagne» legge solo righe `own` di quelle dimensioni e dichiara il primo giorno `own` (verify 12.5).
8. **Regola dell'origine:** `grep -n "site_daily\|object_daily\|entry_daily\|referrer_daily\|dim_daily" src/Query` trova `Origin::where()` in ogni lettura tranne `CompareQuery` e `AudienceQuery::campaigns()`, che hanno il commento «ECCEZIONE ALLA REGOLA DELL'ORIGINE». `CLAUDE.md` descrive le due eccezioni.
9. **Online adesso:** la rotta conta i visitatori distinti degli ultimi 5 minuti, anche a cavallo della mezzanotte, e non espone identificativi (verify 12.6). Il componente aggiorna ogni 30 secondi solo con la scheda visibile: in wp-env, la scheda Network di Chrome mostra le chiamate ogni 30 secondi e nessuna chiamata mentre la scheda del browser è nascosta. La PR lo descrive.
10. **404 e ricerche:** solo aggregati giornalieri, scritti dopo tutti gli scarti, senza colonne del visitatore, e senza orario per le ricerche. Termini normalizzati, privati filtrati, e solo la prima pagina dei risultati. Le query sono 2 per una visita normale e 3 per una 404 o una ricerca (verify 12.7-12.10).
11. **Admin:** la colonna «Visite (30 g)» compare in Articoli e Pagine per gli amministratori, con una sola query per pagina, ed è ordinabile. La barra di amministrazione mostra il dato sugli articoli e le pagine. Entrambi aprono il modale con `?page=wp5w&tab=posts&detail=<ID>` (verify 12.11 e 12.12).
12. **Finding della fase 2:** una sola riga «Non assegnato» nel Confronto, con etichette italiane (compresa «Assistenti AI»); `ObjectType::is_post()` al posto di `NON_POST_TYPES`; `phpcs:disable` ristretto; soglia e minimo dalla risposta, con `_n()` e `sprintf()` in `Compare.jsx`.
13. **Schema:** `Schema::DB_VERSION` vale `'5'`, con le due tabelle di sezione 5. La migrazione da v4 non esegue `ALTER`, e `uninstall.php` elimina le due tabelle con `WP5W_DELETE_DATA`.
14. **Interfaccia in wp-env:** la PR contiene gli screenshot di «Online adesso» (con almeno una visita), «Campagne», «Pagine non trovate», «Ricerche interne», della colonna «Visite (30 g)» nell'elenco degli articoli, del modale aperto da `?detail=` e della tabella dei canali del Confronto.
15. Con `wp5w_own_since` vuota, le altre schede restituiscono gli stessi numeri (verify, sezioni 4 e 7), a parte lo spostamento dei canali verso `ai` previsto da R1.
16. Lo zip della release pesa **al massimo 460.000 byte**.
17. `CLAUDE.md`, `README.md` e `CHANGELOG.md` (0.10.0) sono aggiornati.
18. Dopo il deploy sul blog, tutti i passi bloccanti del runbook sono verdi, compreso il gate dei tempi (10.9) con il limite del +20%.

---

## 10. Runbook di deploy

Blog: `ssh mavida-blog`, cartella `~/domains/mavida.com/public_html/maurizio`, prefisso delle tabelle `maurizio_`, plugin in `public/plugins`, admin su `/app/wp-admin`, log in `/home/mavida/logs/maurizio-debug.log`. `exec()` è disabilitato: niente `wp db export` né `wp db import`, i backup si fanno con `mysqldump` e `MYSQL_PWD` preso da `wp-config.php`, senza stamparlo. `wp5w_hits.ts` e `wp5w_notfound_daily.last_seen` sono in UTC. In Git Bash serve `MSYS_NO_PATHCONV=1` per gli argomenti che iniziano con `/`, compresi i `curl` con `u=/...`. L'application password dell'utente `maurizio` è in `C:\Users\maurizio.MAVIDA\Claude\credenzials\maurizio-blog\wordpress.md`: il file contiene password in chiaro, quindi la si legge in una variabile e **non si stampa mai**. Python su Windows non legge i file in `/tmp` di Git Bash: per i file temporanei si usa lo scratchpad.

```bash
cd ~/domains/mavida.com/public_html/maurizio
WP="wp --url=https://maurizio.mavida.com"
```

### 10.0 Controlli ancora aperti delle fasi precedenti (**per primi**)

Si fanno **prima di ogni altro passo** se la data del deploy è il **2026-10-15 o successiva**. Il giorno `G` è **ieri** dal 2026-10-16 in poi, oppure **oggi** se è il 2026-10-15 (giorno parziale: vale la riga «meno di 6 ore di traffico» della tabella di decisione).

1. **9.1 della fase 2 (`REMOTE_ADDR` e scarti):** le query di [fase-2.md, 9.1](fase-2.md) con quel `G`, e la sua tabella di decisione:
   - **«proxy sospetto»:** **il deploy NON procede.** Ci si ferma e si avvisa Maurizio (diagnosi e Appendice A della fase 2). Con un proxy, anche «Online adesso» fonderebbe visitatori diversi;
   - **«raccolta ferma o parziale»:** il deploy non procede e si avvisa Maurizio;
   - **«dati insufficienti», «sorgente pesante» o «ok»:** si prosegue e si riportano i numeri nella nota.
2. **9.6 della fase 2 (chiavi dei contenuti):** le due query su `G`. Non blocca: le chiavi presenti solo in `own` vanno nella nota.
3. **9.8 della fase 2 (raddoppio):** solo dal 2026-10-18, con `FROM=2026-10-15` e `TO` = ieri. Non blocca: i risultati vanno nella nota per la decisione del 2026-10-28.
4. **9.10 della fase 2 (raccolta attiva):** le due parti usano **la stessa finestra**, il giorno `G` (le visite proprie tra le 20:50 e le 20:53 UTC del 2026-10-14 non devono bastare a dare un esito positivo):

   ```bash
   G=2026-10-15   # lo stesso G del punto 1
   $WP db query "SELECT COUNT(*) new_hits, MIN(ts), MAX(ts) FROM maurizio_wp5w_hits WHERE day = '$G'"
   $WP db query "SELECT COUNT(*) wps_visitors FROM maurizio_statistics_visitor WHERE last_counter = '$G'"
   # Ora per ora (UTC), come in 9.1 della fase 2: le ore a 0 da una parte sola si vedono subito.
   $WP db query "SELECT HOUR(ts) h_utc, COUNT(DISTINCT visitor) own FROM maurizio_wp5w_hits WHERE day = '$G' GROUP BY h_utc"
   $WP db query "SELECT HOUR(CONVERT_TZ(first_view, @@session.time_zone, '+00:00')) h_utc, COUNT(*) wps FROM maurizio_statistics_visitor WHERE last_counter = '$G' GROUP BY h_utc"
   curl -s https://maurizio.mavida.com/ | grep -o 'id="wp5w-hit"[^<]*'
   ```

   Se `new_hits` è 0 mentre WP Statistics ha visitatori, è «raccolta ferma»: il deploy non procede e si avvisa Maurizio.
5. **9.10, 9.11 e 9.14 della fase 3** (si possono fare anche il 2026-10-14, se è passata almeno un'ora dall'installazione della 0.9.0 alle 20:50 UTC). Non bloccano, ma vanno nella nota:

   ```bash
   # 9.14: raccolta e dimensioni valorizzate dopo la 0.9.0.
   $WP db query "SELECT COUNT(*) new_hits, SUM(country <> '') con_paese, SUM(device <> '') con_device FROM maurizio_wp5w_hits WHERE ts >= '2026-10-14 20:50:00'"
   # 9.10: WP Statistics continua a registrare il paese (first_view è in ora locale, +02:00).
   $WP db query "SELECT COUNT(*) nuovi, SUM(location IS NULL OR location IN ('', '000')) senza_paese FROM maurizio_statistics_visitor WHERE first_view >= '2026-10-14 22:50:00'"
   # 9.11: segnale di proxy sul paese (un paese oltre l'80% con almeno 20 visitatori, contro meno del 50% in WP Statistics).
   $WP db query "SELECT country, COUNT(DISTINCT day, visitor) n FROM maurizio_wp5w_hits WHERE ts >= '2026-10-14 20:50:00' GROUP BY country ORDER BY n DESC LIMIT 5"
   $WP db query "SELECT location, COUNT(*) n FROM maurizio_statistics_visitor WHERE first_view >= '2026-10-14 22:50:00' GROUP BY location ORDER BY n DESC LIMIT 5"
   ```

   Se `new_hits` è 0 mentre WP Statistics ha visitatori, il deploy non procede (raccolta ferma). Il segnale sul paese di 9.11 resta **solo un avviso**, come deciso nella fase 3: si avvisa Maurizio e si prosegue. WP Statistics accetta circa 110 visitatori automatici al giorno (il raddoppio in esame), che ne spostano la distribuzione dei paesi, quindi da solo non prova un proxy. Bloccano il deploy soltanto «proxy sospetto» e «raccolta ferma o parziale» del punto 1, e la raccolta ferma di questo punto e del punto 4.
6. **Log:** `grep -n "wp5w\|wp_delete_file" ~/logs/maurizio-debug.log | tail -20`. Si annota se le 2 righe informative di `wp_delete_file()` per `open_basedir` (pulizia dei temporanei del database dei paesi) si sono ripetute con il cron `wp5w_geo_update`. Non blocca.

Se la data è ancora il 2026-10-14, i punti 1-4 si saltano e restano nella nota come «ancora da fare».

### 10.1 Backup, cattura di non regressione e previsione del canale AI

```bash
mkdir -p ~/backups
STAMP=$(date +%F-%H%M)
TABLES=$($WP db tables 'maurizio_wp5w_*' --all-tables --format=csv | tr ',' ' ')
# Credenziali da wp-config.php: la password va in MYSQL_PWD e non si stampa mai.
export MYSQL_PWD="$($WP config get DB_PASSWORD)"
mysqldump -h "$($WP config get DB_HOST)" -u "$($WP config get DB_USER)" --single-transaction "$($WP config get DB_NAME)" $TABLES > ~/backups/wp5w-pre-0.10.0-$STAMP.sql
unset MYSQL_PWD
ls -l ~/backups/wp5w-pre-0.10.0-$STAMP.sql            # non vuoto
$WP option list --search='wp5w_*' --format=json > ~/backups/wp5w-options-pre-0.10.0.json
$WP plugin get wp-5w --field=version                  # 0.9.0
LOG_WP5W=$(grep -c "wp5w" ~/logs/maurizio-debug.log 2>/dev/null || echo 0); echo "$LOG_WP5W"
WPS_FROM=$($WP db query "SELECT MIN(last_counter) FROM maurizio_statistics_visitor" --skip-column-names); echo "$WPS_FROM"   # 2026-04-17 o successivo
{
  $WP db query "SELECT origin, COUNT(*), SUM(visitors), SUM(views) FROM maurizio_wp5w_site_daily WHERE day BETWEEN '2026-09-14' AND '2026-10-12' GROUP BY origin"
  $WP db query "SELECT origin, COUNT(*), SUM(views), SUM(visitors) FROM maurizio_wp5w_object_daily WHERE day BETWEEN '2026-09-14' AND '2026-10-12' GROUP BY origin"
  # Somme per giorno: non devono cambiare (il canale ai sposta valori, non visitatori).
  $WP db query "SELECT 'entry', day, SUM(visitors) FROM maurizio_wp5w_entry_daily WHERE origin = 'wps' AND day >= '$WPS_FROM' GROUP BY day"
  $WP db query "SELECT 'referrer', day, SUM(visitors) FROM maurizio_wp5w_referrer_daily WHERE origin = 'wps' AND day >= '$WPS_FROM' GROUP BY day"
  $WP db query "SELECT 'dim', day, dim, SUM(visitors) FROM maurizio_wp5w_dim_daily WHERE origin = 'wps' AND day >= '$WPS_FROM' GROUP BY day, dim"
  # Dimensioni diverse da canale e sorgente: devono restare identiche valore per valore.
  $WP db query "SELECT dim, value, SUM(visitors) FROM maurizio_wp5w_dim_daily WHERE origin = 'wps' AND day >= '$WPS_FROM' AND dim IN ('country','device','browser','platform') GROUP BY dim, value ORDER BY dim, value"
} > ~/backups/wp5w-nonreg-pre-0.10.0.txt
# Canali e sorgenti prima (servono per il confronto atteso di 10.4).
$WP db query "SELECT dim, value, SUM(visitors) FROM maurizio_wp5w_dim_daily WHERE origin = 'wps' AND day >= '$WPS_FROM' AND dim IN ('channel','source') GROUP BY dim, value ORDER BY dim, value" > ~/backups/wp5w-channels-pre-0.10.0.txt
$WP eval '$r = ( new Mavida\FiveW\Query\OverviewQuery() )->get( new Mavida\FiveW\Support\DateRange( "2026-09-14", "2026-10-12" ) ); echo $r["kpis"]["visitors"]["value"], " ", $r["kpis"]["views"]["value"], "\n";' >> ~/backups/wp5w-nonreg-pre-0.10.0.txt
```

Il confronto vincolante è con questa cattura, presa subito prima dell'installazione e lontano dalle 4:00. Lo snapshot della fase 1 (`scratchpad/snapshot-pre-fase-1.txt`: `site_daily` 29 righe, 3.532 visitatori e 4.659 visualizzazioni; `object_daily` 576 righe; `entry_daily` 371; `referrer_daily` 186; `dim_daily` 1.110) resta solo un riferimento, perché l'ETL notturno rielabora gli ultimi 7 giorni.

### 10.2 Installazione e migrazione

```bash
INSTALL_UTC=$(date -u '+%F %T'); echo "$INSTALL_UTC"
$WP plugin install https://github.com/mavidasnc/wp-5w/releases/download/v0.10.0/wp-5w-0.10.0.zip --force
$WP plugin get wp-5w --field=version                  # 0.10.0
$WP option get wp5w_db_version                         # 5 (questo comando fa partire la migrazione: senza, l'endpoint ignora le visite)
$WP db query "SHOW TABLES LIKE 'maurizio_wp5w_%_daily'"                       # comprende notfound_daily e search_daily
$WP db query "SHOW KEYS FROM maurizio_wp5w_notfound_daily WHERE Key_name = 'PRIMARY'"   # day, uri, referrer
$WP db query "SHOW KEYS FROM maurizio_wp5w_search_daily WHERE Key_name = 'PRIMARY'"     # day, term
grep -c "wp5w" ~/logs/maurizio-debug.log 2>/dev/null || echo 0   # uguale a $LOG_WP5W
$WP cron event list --fields=hook,next_run_relative,recurrence | grep wp5w   # wp5w_etl_daily, wp5w_own_hourly, wp5w_geo_update: nessun evento nuovo
```

Se `wp5w_db_version` non è `5`, si ripete il comando una volta (il lock della migrazione può essere occupato da una richiesta del sito). Se resta `4`, si va al rollback (10.12).

### 10.3 Ricalcolo del canale AI sulle due origini

Prima la previsione, calcolata con lo **stesso** frammento SQL del codice installato:

```bash
$WP eval '
global $wpdb;
$ai = Mavida\FiveW\Support\Channels::ai_sql( Mavida\FiveW\Support\Channels::host_sql( "referred" ), "", "source_name" );
foreach ( $wpdb->get_results( "SELECT COALESCE(source_channel, \"(n/d)\") ch, {$ai} AS ai, COUNT(*) n FROM {$wpdb->prefix}statistics_visitor WHERE {$ai} <> \"\" GROUP BY ch, ai" ) as $r ) { echo "$r->ch $r->ai $r->n\n"; }'
# Atteso sul blog (1.1): search ChatGPT 6, search Perplexity 1, (n/d) Claude 1, più le visite AI arrivate dopo il 2026-10-14.
```

Poi l'ETL della finestra di WP Statistics. Comprende anche la raccolta propria, quindi ricalcola le campagne di tutti i giorni `own` dal 2026-10-14:

```bash
$WP 5w etl --from="$WPS_FROM"
$WP option get wp5w_etl_state --format=json | grep -o '"from":"[^"]*","to":"[^"]*"\|"ok":[a-z]*' | head -2   # from = $WPS_FROM, to = oggi, ok = true
```

Se l'ETL fallisce (`ok` falso), si va al rollback (10.12).

### 10.4 Non regressione

Si ripetono le query della cattura di 10.1 e si confrontano con `~/backups/wp5w-nonreg-pre-0.10.0.txt`:

- **identici:** `site_daily`, `object_daily`, le somme per giorno di `entry_daily`, `referrer_daily` e `dim_daily` (per ogni `dim` diverso da `utm_*`), le dimensioni `country`, `device`, `browser` e `platform` valore per valore, e il risultato di `OverviewQuery`;
- **canali e sorgenti** (da confrontare con `wp5w-channels-pre-0.10.0.txt`): il valore `ai` del canale è uguale alla somma della previsione di 10.3; `search` e `(n/d)` calano esattamente dei visitatori previsti per ciascuno; le sorgenti `ChatGPT` e `Perplexity` non cambiano; compare `Claude` (o gli altri nomi previsti) e cala `(n/d)` della stessa quantità; nessun altro valore cambia;
- le righe `own` possono essere cambiate solo per `ai` e per le nuove dimensioni `utm_*`.

Qualunque altra differenza nelle righe `wps` porta al rollback (10.12).

### 10.5 Cache di LiteSpeed

Il tracker è cambiato, quindi si svuota la cache (TTL pubblico di 7 giorni):

```bash
$WP litespeed-purge all
POST_URL=$($WP post list --post_type=post --post_status=publish --posts_per_page=1 --field=url)
curl -s "$POST_URL" -o /dev/null; curl -sI "$POST_URL" | grep -i x-litespeed-cache   # alla seconda richiesta: hit
curl -s "$POST_URL" | grep -o 'id="wp5w-hit"[^<]*'                                   # blocco presente nella pagina in cache
curl -s "https://maurizio.mavida.com/?s=wordpress" | grep -o 'id="wp5w-hit"[^<]*'     # "type":"search" con "q":"wordpress" e "n":
curl -s "https://maurizio.mavida.com/?s=wordpress&paged=2" | grep -o 'id="wp5w-hit"[^<]*'   # senza "q" (se c'è una seconda pagina di risultati)
```

### 10.6 Smoke test dell'endpoint

Sul blog si ricavano gli ID e le firme. `curl` senza JavaScript non registra visite, quindi le richieste di 10.5 non sporcano i dati:

```bash
ID=$($WP post list --post_type=post --post_status=publish --posts_per_page=1 --field=ID)
SIG=$($WP eval "echo Mavida\\FiveW\\Front\\Tracker::signature( 'post', $ID );")
SIG404=$($WP eval "echo Mavida\\FiveW\\Front\\Tracker::signature( '404', 0 );")
SIGS=$($WP eval "echo Mavida\\FiveW\\Front\\Tracker::signature( 'search', 0 );")
echo "$ID $SIG $SIG404 $SIGS"
```

Dal PC locale, con Git Bash:

```bash
UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36"
H=https://maurizio.mavida.com/wp-json/wp5w/v1/hit
MSYS_NO_PATHCONV=1 curl -s -o /dev/null -w "%{http_code}\n" -A "$UA" -d "t=post&id=$ID&s=$SIG&u=/wp5w-smoke-test/" $H                                  # 204
MSYS_NO_PATHCONV=1 curl -s -o /dev/null -w "%{http_code}\n" -A "$UA" -d "t=404&id=0&s=$SIG404&u=/wp5w-smoke-404/&r=https://www.example.org/x" $H          # 204
MSYS_NO_PATHCONV=1 curl -s -o /dev/null -w "%{http_code}\n" -A "$UA" -d "t=search&id=0&s=$SIGS&u=/wp5w-smoke-test/&q=wp5w%20smoke%20test&n=0" $H         # 204
```

Sul blog:

```bash
$WP db query "SELECT object_type, uri, country, device FROM maurizio_wp5w_hits WHERE uri IN ('/wp5w-smoke-test/', '/wp5w-smoke-404/') ORDER BY id"   # post, 404, search
$WP db query "SELECT * FROM maurizio_wp5w_notfound_daily WHERE uri = '/wp5w-smoke-404/'"         # 1 riga, referrer example.org, hits 1, last_seen in UTC
$WP db query "SELECT * FROM maurizio_wp5w_search_daily WHERE term = 'wp5w smoke test'"            # searches 1, empty 1
```

Poi, se è disponibile claude-in-chrome, in una finestra anonima:

- si apre un articolo (`x-litespeed-cache: hit`) e si controlla il beacon `POST /wp-json/wp5w/v1/hit` con risposta `204`;
- si apre `/?s=calendario`: il corpo del beacon contiene `t=search`, `q=calendario` e `n=`;
- si apre un indirizzo inesistente: il beacon contiene `t=404`, e dopo la visita c'è una riga nuova in `wp5w_notfound_daily`.

Il tracker non ha un tag script proprio, perché LiteSpeed combina i JS: si cerca il blocco `#wp5w-hit`. Queste visite vere restano nei dati, e le si scrive nella nota.

### 10.7 Aggregazione, invariante e campagne

```bash
$WP cron event run wp5w_own_hourly
D=$(date +%F)
$WP db query "SELECT dim, SUM(visitors) FROM maurizio_wp5w_dim_daily WHERE origin = 'own' AND day = '$D' AND dim IN ('channel','source','country','device','browser','platform') GROUP BY dim"
$WP db query "SELECT visitors FROM maurizio_wp5w_site_daily WHERE origin = 'own' AND day = '$D'"
# le sei dimensioni hanno la stessa somma, uguale ai visitatori own del giorno (le utm_* sono escluse di proposito)
$WP db query "SELECT dim, value, SUM(visitors) FROM maurizio_wp5w_dim_daily WHERE origin = 'own' AND dim LIKE 'utm%' GROUP BY dim, value"
$WP db query "SELECT utm_campaign, utm_source, utm_medium, COUNT(DISTINCT day, visitor) FROM maurizio_wp5w_hits WHERE utm_campaign <> '' OR utm_source <> '' OR utm_medium <> '' GROUP BY 1, 2, 3"
# i valori utm_* aggregati corrispondono alle visite con UTM di wp5w_hits (oggi forse nessuna: va bene)
```

Una somma diversa tra le sei dimensioni porta al rollback.

### 10.8 Visite nell'admin (lato server)

L'application password non apre wp-admin, quindi qui si provano solo i dati. La vista va nello «Stato finale».

```bash
IDS=$($WP post list --post_type=post --post_status=publish --posts_per_page=5 --field=ID | tr '\n' ',' | sed 's/,$//')
$WP eval "\$q = new Mavida\FiveW\Query\PostsQuery(); print_r( \$q->views_by_ids( 'post', array( $IDS ), Mavida\FiveW\Support\DateRange::last_days( 30, wp_date( 'Y-m-d' ) ) ) );"
$WP db query "SELECT object_id, SUM(views) FROM maurizio_wp5w_object_daily WHERE object_type = 'post' AND object_id IN ($IDS) AND origin = 'wps' AND day BETWEEN DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND CURDATE() GROUP BY object_id"
# stessi numeri (con wp5w_own_since vuota)
```

### 10.9 Gate dei tempi dell'endpoint (da ripetere: la fase 4 cambia il percorso di `/hit`)

**Limite di frequenza:** l'endpoint scarta oltre 30 visite al minuto dallo stesso hash, e tutte le richieste di prova hanno lo stesso IP e lo stesso user agent. Una visita scartata segue un percorso più corto, che falserebbe i tempi. Quindi si aspettano **almeno 60 secondi** dopo 10.6 prima di iniziare, e altri 60 secondi prima di un'eventuale ripetizione.

Si usa lo stesso metodo di [fase-1.md, 8.7](fase-1.md), dal PC locale: 7 coppie alternate, ciascuna con una visita valida all'articolo (`u=/wp5w-smoke-test/`) e una `POST` a vuoto a `/wp-json/wp-statistics/v2/hit`. Si confrontano le mediane di `time_total`.

- **Esito:** la mediana di `hit` non deve superare di oltre il **20%** quella di `wps`. Nella fase 1 la differenza era +9,4%, nella fase 3 +12,3% (0,420 s contro 0,374 s).
- **Da riportare, senza gate:** 7 visite di ricerca (`t=search`, `q=wp5w gate`, `n=1`), alternate con altrettante `POST` a vuoto a WP Statistics, con la loro mediana. Fanno una query in più. Se superano il +30%, lo si scrive nella nota e si avvisa Maurizio, senza rollback.
- **Verifica:** `SELECT COUNT(*) FROM maurizio_wp5w_hits WHERE uri = '/wp5w-smoke-test/'` dà 16 (1 + 1 di 10.6, più 7 + 7 del gate), e `SELECT searches FROM maurizio_wp5w_search_daily WHERE term = 'wp5w gate'` dà 7.
- **Se si supera il 20%:** dopo 60 secondi si ripete una volta con 15 coppie di sole visite all'articolo (15 visite in circa 15 secondi, sotto il limite), per escludere il rumore di rete. Il conteggio atteso diventa 31, e `SELECT COUNT(*) FROM maurizio_wp5w_discards WHERE day = CURDATE() AND reason = 'rate'` non deve essere aumentato durante le prove. Se si supera ancora il 20%, si va al rollback (10.12) e si avvisa Maurizio.
- **Pulizia:**

  ```bash
  $WP db query "DELETE FROM maurizio_wp5w_hits WHERE uri IN ('/wp5w-smoke-test/', '/wp5w-smoke-404/')"
  $WP db query "DELETE FROM maurizio_wp5w_notfound_daily WHERE uri = '/wp5w-smoke-404/'"
  $WP db query "DELETE FROM maurizio_wp5w_search_daily WHERE term IN ('wp5w smoke test', 'wp5w gate')"
  $WP cron event run wp5w_own_hourly
  ```

### 10.10 REST con l'application password

Dal PC locale, con Git Bash. `WPAUTH` si legge dal file delle credenziali e non si stampa. Le risposte si salvano nello scratchpad e si leggono con Python:

```bash
WPAUTH="maurizio:<application password>"
B=https://maurizio.mavida.com/wp-json/wp5w/v1/admin
for P in online "audience/campaigns?days=30" "notfound?days=30" "searches?days=30" audience compare "overview?from=2026-09-14&to=2026-10-12"; do
  curl -s -o /dev/null -w "$P %{http_code}\n" -u "$WPAUTH" "$B/$P"                 # 200
done
curl -s -o /dev/null -w "%{http_code}\n" "$B/online"                                 # 401 senza credenziali
curl -s -u "$WPAUTH" "$B/audience/campaigns?days=15" -o /dev/null -w "%{http_code}\n" # 400
```

Si controlla che:

- `online` abbia `minutes` 5, `tracking` vero, e nessuna chiave `visitor` o `ts`, né stringhe esadecimali di 32 caratteri;
- `audience/campaigns` abbia `first_day` uguale al primo giorno `own` (2026-10-14) e numeri uguali a `SELECT dim, value, SUM(visitors) FROM maurizio_wp5w_dim_daily WHERE origin = 'own' AND dim LIKE 'utm%' AND day BETWEEN <from> AND <to> GROUP BY dim, value`, con `from` e `to` presi dalla risposta;
- `audience` abbia nei referrer `label` uguale a `LinkedIn (app)` per `android-app://com.linkedin.android/`, se la riga è negli ultimi 7 giorni;
- `compare` non abbia una riga `(n/d)` nei canali, abbia `min_views` 10 e, se nella finestra ci sono visite AI, una riga `ai`;
- `overview` abbia gli stessi totali di 10.1, e nei canali la voce `ai` se il periodo contiene visite AI;
- `notfound` e `searches` rispondano con le righe del giorno, se ci sono state visite vere.

### 10.11 Prova nel browser

Non c'è una sessione di amministratore nel browser automatico, e l'application password non apre wp-admin.

1. **In wp-env** (sempre, prima della PR): gli screenshot del criterio 14 e la prova del polling (criterio 9).
2. **Sul blog**, solo se Maurizio ha una sessione aperta in Chrome (claude-in-chrome, senza digitare password): si aprono `https://maurizio.mavida.com/app/wp-admin/admin.php?page=wp5w&tab=audience` (Online adesso e Campagne), `&tab=posts` (404 e ricerche), `&tab=posts&detail=<ID>` (modale), `/app/wp-admin/edit.php` (colonna e ordinamento) e un articolo nel frontend (barra di amministrazione). Si controlla anche che la console sia senza errori. Se la sessione non c'è, va tutto nello «Stato finale», senza bloccare il deploy.

### 10.12 Rollback

Si fa se un passo bloccante tra 10.2 e 10.9 fallisce.

```bash
$WP plugin install https://github.com/mavidasnc/wp-5w/releases/download/v0.9.0/wp-5w-0.9.0.zip --force
$WP plugin get wp-5w --field=version                  # 0.9.0
$WP option get wp5w_db_version                         # 4 (la 0.9.0 riporta la versione al suo valore; le due tabelle nuove restano, non usate)
$WP 5w etl --from="$WPS_FROM"                          # canali e sorgenti tornano quelli della 0.9.0 sulle due origini; replace_by_month riscrive le righe own, quindi spariscono anche le utm_*
$WP litespeed-purge all                                # le pagine in cache hanno il tracker della 0.10.0
```

Poi si ripetono le query di 10.4 contro la cattura di 10.1: questa volta **tutto** deve essere identico, compresi canali e sorgenti. Solo se le righe `wps` risultano diverse, si ripristinano le tabelle dal dump:

```bash
export MYSQL_PWD="$($WP config get DB_PASSWORD)"
mysql -h "$($WP config get DB_HOST)" -u "$($WP config get DB_USER)" "$($WP config get DB_NAME)" < ~/backups/wp5w-pre-0.10.0-<STAMP>.sql
unset MYSQL_PWD
```

Questo ripristino perde le righe di `wp5w_hits` arrivate dopo il backup, e va scritto nella nota. Le tabelle `maurizio_wp5w_notfound_daily` e `maurizio_wp5w_search_daily` restano, e la nota le elenca: una 0.10.x corretta le riusa, e si eliminano a mano solo se la fase 4 viene abbandonata.

### 10.13 Dopo circa un'ora di traffico

```bash
$WP db query "SELECT COUNT(*) new_hits, SUM(object_type = '404') n404, SUM(object_type = 'search') nsearch FROM maurizio_wp5w_hits WHERE ts >= '$INSTALL_UTC'"
$WP db query "SELECT COALESCE(SUM(hits), 0) FROM maurizio_wp5w_notfound_daily WHERE last_seen >= '$INSTALL_UTC'"   # uguale a n404 (le visite di prova sono già state cancellate)
$WP db query "SELECT day, term, searches, empty FROM maurizio_wp5w_search_daily ORDER BY day DESC, searches DESC LIMIT 10"
$WP db query "SELECT COUNT(*) FROM maurizio_statistics_visitor WHERE first_view >= CONVERT_TZ('$INSTALL_UTC', '+00:00', @@session.time_zone)"
```

- `new_hits` è maggiore di 0 se c'è stato traffico. Se è 0 mentre WP Statistics ha registrato visitatori, si avvisa Maurizio (raccolta ferma dopo la migrazione).
- Le 404 aggregate devono essere quante quelle in `wp5w_hits`.
- La somma di `searches` dalla data di installazione è **al massimo** `nsearch`: le pagine successive dei risultati e i termini vuoti non si contano.

### 10.14 Nota di consegna

La nota riporta:

- data e ora di installazione (`INSTALL_UTC`);
- gli esiti di 10.0 con i numeri (o «saltati, ancora da fare» se il deploy è avvenuto il 2026-10-14), comprese le righe del log su `open_basedir`;
- la previsione e l'esito del canale AI (10.3 e 10.4);
- gli esiti di 10.2, 10.5, 10.6, 10.7, 10.8 e 10.10;
- le due mediane del gate (10.9) con la differenza percentuale, e la mediana informativa delle ricerche;
- l'esito di 10.13 e quello della prova nel browser (10.11);
- il peso del tracker e dello zip;
- gli eventuali scostamenti da questa specifica, e in caso di rollback le tabelle rimaste;
- il promemoria della decisione del 2026-10-28, con le query di [fase-2.md, 9.8](fase-2.md) e di [fase-3.md, 9.11](fase-3.md) da rieseguire.

### 10.15 Stato finale: controlli ancora da fare a mano da Maurizio

Questi controlli restano a Maurizio dopo il deploy, perché richiedono una sessione di wp-admin o una sua decisione:

1. **Scheda Visitatori:** «Online adesso» mostra i visitatori e si aggiorna ogni 30 secondi (nella scheda Network di DevTools, una chiamata `online` ogni 30 secondi, che si fermano passando a un'altra scheda del browser e ripartono tornando). Poi i box «Campagne» (con la nota «disponibili dal 14/10/2026») e «Pubblico» (fase 3, mai visto da UI), e le etichette «LinkedIn (app)» nella tabella «Provenienza».
2. **Scheda Articoli:** «Pagine non trovate» (con «Link interno» per i link rotti del sito) e «Ricerche interne», con i tre periodi.
3. **Modale da URL:** `admin.php?page=wp5w&tab=posts&detail=<ID>` apre il dettaglio, e ricaricando la pagina dopo la chiusura non si riapre.
4. **Elenchi di articoli e pagine:** la colonna «Visite (30 g)», l'ordinamento crescente e decrescente, e il clic che apre il modale.
5. **Barra di amministrazione** su un articolo nel frontend: «5W: N visite (30 g)», con il clic che apre il modale.
6. **Scheda Stato:** nel Confronto una sola riga «Non assegnato», le etichette italiane dei canali (compresa «Assistenti AI») e i testi con soglia e minimo presi dai dati. Poi la card «Database dei paesi» (fase 3, mai vista da UI).
7. **Panoramica:** la voce «Assistenti AI» nei canali, e lo spostamento di pochi visitatori da «Motori di ricerca» (domanda aperta 2).
8. **Console del browser** senza errori in tutte le schede.
9. **Controlli rimandati:** quelli di 10.0 se il deploy è avvenuto il 2026-10-14, e la decisione del 2026-10-28 sul passaggio alla raccolta propria.
10. **Rollback:** solo se è avvenuto, decidere se eliminare le due tabelle rimaste.

---

## 11. Fuori da questa fase

- Registrazione lato server delle immagini mancanti (richieste statiche che non passano da WordPress): vedi la domanda aperta 1.
- Unione delle due forme dei referrer delle app in una sola riga, e riclassificazione di `com.linkedin.android` (social nella raccolta propria, `(n/d)` in WP Statistics).
- Percorso della pagina di provenienza per i link interni rotti (oggi solo l'host).
- Filtro di altri dati personali nei termini di ricerca (per esempio il codice fiscale), oltre a email e numeri lunghi.
- Pulizia degli aggregati di 404 e ricerche, conservazione di `wp5w_hits`, testo per la privacy policy (che dovrà citare ricerche e 404) e impostazione di `wp5w_own_since`: fase 5.
- Ottimizzazione dell'endpoint con `SHORTINIT` (rimandata dagli scostamenti della roadmap).
- Screenshot della fase 2 da rigenerare (finding 5 della review, facoltativo).

---

## 12. Domande aperte per Maurizio

Non bloccano l'implementazione: la specifica applica la scelta indicata tra parentesi finché Maurizio non decide diversamente.

1. **Immagini storiche rotte.** Le richieste di immagini mancanti ricevono un `404` statico dal server, senza passare da WordPress, quindi il tracker non le vede e non compariranno tra le «Pagine non trovate» (1.3). Serve una soluzione lato server in una fase successiva (per esempio dai log di accesso, oppure facendo passare a WordPress i `404` di `uploads`)? (Per ora: no, e il box lo dichiara.)
2. **Canale AI anche sullo storico.** Con l'ETL di 10.3, 7 visitatori di WP Statistics (ChatGPT 6, Perplexity 1) passano da «Motori di ricerca» ad «Assistenti AI», e 1 (Claude) da «Non assegnato». Cambiano di poco i canali della Panoramica e «Ingressi da ricerca» del dettaglio e delle opportunità. Va bene? (Per ora: sì, per avere lo stesso canale su tutto lo storico.)
3. **App di LinkedIn.** I 42 visitatori dell'app (180 giorni) sono `social` nella raccolta propria e `(n/d)` in WP Statistics, e la differenza resta visibile nel Confronto. Si allinea la raccolta propria a WP Statistics, o si tiene `social`, che è più corretto? (Per ora: si tiene `social`, e le etichette cambiano solo in presentazione.)
4. **Ricerche con pochissimo traffico.** Sul blog ci sono circa 9 ricerche in 180 giorni. In un giorno con una sola ricerca, la riga di `wp5w_search_daily` e la visita di tipo `search` in `wp5w_hits` (con l'hash del giorno, conservata 30 giorni) permettono di collegare il termine al percorso di quel visitatore in quel giorno, anche senza IP e senza orario. È accettabile, oppure le ricerche vanno aggregate per settimana o con una soglia minima prima di mostrarle? (Per ora: aggregati giornalieri, come chiede la roadmap.)
