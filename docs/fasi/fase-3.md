# Fase 3: paese, dispositivo, browser e sistema operativo

Questa è la specifica di implementazione della fase 3 di [roadmap-sostituzione-wp-statistics.md](../roadmap-sostituzione-wp-statistics.md). Le sezioni «Decisioni già prese», «Principio di progetto», «Processo di sviluppo» e «Scostamenti» della roadmap valgono anche qui, e così le decisioni di [fase-1.md](fase-1.md) e [fase-2.md](fase-2.md). Se questo documento è più preciso, prevale.

- **Branch:** `fase-3-pubblico`, creato da `main` alla release `v0.8.0` (commit `86c3764`).
- **Versione da rilasciare:** `0.9.0`. Lo schema del database **resta alla versione `4`**: le colonne `country`, `device`, `browser` e `os` di `wp5w_hits` esistono già dalla fase 1 (vuote fino a oggi).
- **Ciclo:** Sonnet implementa su questo branch e apre la PR verso `main`, con la checklist dei criteri di accettazione della sezione 8. Segue la review di Opus 5.5, che rilancia `composer ci`, `npm run check` e `verify.php`. Sono ammessi al massimo 3 giri di correzioni. Poi squash merge, release, deploy secondo il runbook (sezione 9) e nota di consegna.
- **Obiettivo:** la raccolta propria registra paese, dispositivo, browser e sistema operativo di ogni visita (senza salvare IP né user agent) e li aggrega in `dim_daily` con gli stessi nomi e valori dell'ETL di WP Statistics. La scheda Visitatori mostra da subito un box «Pubblico» con i dati `wps` già importati, e passerà ai dati `own` da sola quando `wp5w_own_since` verrà impostata (fase 5).

---

## 1. Dati reali letti prima di scrivere la specifica

Letture fatte in sola lettura il 2026-10-14 tra le 19:35 e le 19:45 UTC, con `ssh mavida-blog` e `wp --url=https://maurizio.mavida.com`, e in locale nella copia di WP Statistics di `.wp-env-plugins`.

### 1.1 Dimensioni di WP Statistics già in `dim_daily`

Le righe `wps` di `dim_daily` vanno dal 2026-04-17 (inizio della finestra di `statistics_visitor`) al 2026-10-14. Per ogni dimensione la somma dei visitatori è identica (10.692): ogni visitatore del giorno compare una volta in ogni dimensione. Il nome della dimensione del sistema operativo è **`platform`**, non `os` (`WpStatisticsSource::DIMENSIONS`). I valori vengono da device-detector (usato da WP Statistics), quindi hanno nomi precisi che la raccolta propria deve riprodurre:

| Dimensione | Valori (visitatori dal 2026-07-01, in ordine) |
|---|---|
| `country` | Codici ISO a 2 lettere maiuscole (IT 2.546, US 1.835, JP 291, GB 291, NL 248, DE 215...). L'unico valore diverso è `(n/d)` (4 visitatori, dal `COALESCE` dell'ETL). |
| `device` | `smartphone` 5.582, `desktop` 1.900, `tablet` 24, `phablet` 21, `(n/d)` 2, `portable media player` 2, `tv` 2 |
| `browser` | `Chrome Mobile` 3.057, `Chrome Mobile iOS` 1.641, `Chrome` 1.625, `Mobile Safari` 623, `Samsung Browser` 124, `Microsoft Edge` 102, `Firefox` 89, `Safari` 88, `Google Search App` 48, `Facebook` 32, `Firefox Mobile` 20, `Chrome Webview` 11, `Yandex Browser` 10, `Opera Mobile` 9, `Opera` 8, `Brave` 7, `LinkedIn` 7, poi valori sotto 6 (tra cui `Firefox Mobile iOS` 4, `Instagram` 3, `UNK` 2) |
| `platform` | `Android` 3.153, `iOS` 2.469, `Windows` 1.494, `GNU/Linux` 207, `Mac` 187, `iPadOS` 7, `Chrome OS` 6, `Ubuntu` 6, `Tizen` 2, `UNK` 2 |

Due conseguenze: il dispositivo «mobile» si chiama `smartphone`, macOS si chiama `Mac` e Linux `GNU/Linux`; e un valore sconosciuto può arrivare come `(n/d)` (campo vuoto) oppure come `UNK` (codice di device-detector).

Le righe `own` di `dim_daily` oggi sono solo `channel` e `source` (1 visitatore il 2026-10-14).

### 1.2 WP Statistics e la libreria MaxMind (requisito a)

- **WP Statistics 14.16.15** (`.wp-env-plugins`) e **14.16.16** (blog) includono una copia di `maxmind-db/reader`, ma **con prefisso**: `namespace WP_Statistics\Dependencies\MaxMind\Db` (1 file) e `WP_Statistics\Dependencies\MaxMind\Db\Reader` (4 file), insieme a `WP_Statistics\Dependencies\GeoIp2` e a device-detector in `WP_Statistics\Dependencies\DeviceDetector`.
- L'unico namespace **senza prefisso** è `MaxMind\Exception` (16 file di `maxmind/web-service-common`, nella cartella `src/Dependencies/MaxMind/WebService`), che `maxmind-db/reader` non dichiara né usa.
- Nessun altro plugin o tema del blog dichiara `namespace MaxMind\Db` (ricerca in `public/plugins` e `public/themes`), e l'estensione C `maxminddb` non è caricata (`php -m`). `class_exists( 'MaxMind\Db\Reader', false )` dopo il bootstrap è falso.
- **Conclusione:** `maxmind-db/reader` senza prefisso non entra in conflitto con WP Statistics, quindi non servono né Strauss né php-scoper (decisione F1).

### 1.3 DB-IP Lite Country

- URL mensile: `https://download.db-ip.com/free/dbip-country-lite-YYYY-MM.mmdb.gz`. Il file di ottobre è stato pubblicato il 2026-10-01 alle 06:29 UTC (`last-modified`), quello di settembre il 2026-09-01 alle 06:27 UTC: nelle prime ore del mese il file nuovo non c'è ancora.
- Dimensioni: 4.123.384 byte compressi, 8.348.509 byte decompressi (ISIZE del gzip uguale ai byte letti).
- Metadati: `database_type = DBIP-Country-Lite`, `ip_version = 6`, `record_size = 24`, 1.383.101 nodi, `build_epoch` = 2026-10-01 01:36:12 UTC.
- Struttura del record compatibile con GeoIP2 Country: `country.iso_code` (per esempio `8.8.8.8` → `US`, `151.38.1.1` → `IT`, `2a00:1450:4002::1` → `IT`). Gli IP privati, di loopback e di documentazione (`10.0.0.1`, `127.0.0.1`, `::1`, `198.51.100.1`) non hanno record (`null`).
- Tempo misurato in locale (Windows, PHP 8.3, file su disco): apertura del lettore più una ricerca **circa 1 ms** di media su 200 prove; decompressione in streaming con `gzopen`/`gzread` in 0,04 s e 4,5 MB di memoria di picco.

### 1.4 Ambiente del blog

PHP 8.3.30 con `zlib`, `curl` e `bcmath`; `exec`, `system`, `passthru`, `shell_exec`, `popen` e `dl` sono disabilitati. `WP_MEMORY_LIMIT` è 40M. WP-Cron è attivo (nessun `DISABLE_WP_CRON`). La cartella degli upload è `/home/mavida/domains/mavida.com/public_html/maurizio/public/uploads`, con URL `https://maurizio.mavida.com/public/uploads`. WP Statistics ha `geoip` attivo con MaxMind GeoLite2 (file in `public/uploads/wp-statistics/`), `store_ua` spento e `ip_method = sequential`.

---

## 2. Passo 0 dell'implementazione: controlli ancora aperti della fase 2

Il paese si calcola da `REMOTE_ADDR`. Se davanti al sito ci fosse un proxy, tutte le visite proprie avrebbero il paese del proxy: il controllo 9.1 della fase 2 qui è ancora più importante.

Prima di scrivere codice, se la data è il **2026-10-15 o successiva**, Sonnet esegue sul blog i controlli di 9.0 (sono di sola lettura) e applica la tabella di decisione di 9.1 della fase 2:

- **«ok», «dati insufficienti» o «sorgente pesante»:** si implementa questa specifica e l'esito va scritto nella PR;
- **«proxy sospetto» o «raccolta ferma o parziale»:** ci si ferma e si avvisa Maurizio, senza scrivere codice. La fase riprende solo dopo la sua decisione (per il proxy, la diagnosi e l'Appendice A della fase 2).

Se la data è ancora il 2026-10-14, il passo 0 si salta e i controlli si fanno all'inizio del deploy (9.0).

---

## 3. Decisioni prese in questa specifica

La roadmap lascia aperte alcune scelte. Ecco come le risolve questa specifica, con due righe di motivazione per ciascuna.

| # | Decisione | Motivazione |
|---|---|---|
| F1 | Si usa `maxmind-db/reader` (`^1.14`, Apache-2.0) da Composer, **senza prefisso**. Niente Strauss, php-scoper né lettore scritto in casa. | WP Statistics usa una copia con prefisso (1.2), quindi non c'è conflitto. Un prefisso aggiungerebbe un passo di build in CI e nello zip, e un lettore in casa (decoder, puntatori, metadati) sarebbe codice in più da provare senza un vantaggio. |
| F2 | Se un altro plugin caricasse prima una sua copia senza prefisso di `MaxMind\Db\Reader`, si userebbe quella: l'API usata (`new Reader( $file )`, `get()`, `metadata()`, `close()`) è stabile dalla 1.0. Il runbook verifica da quale file arriva la classe. | Si evita una dipendenza dal caso raro senza codice difensivo. Il controllo nel runbook basta per accorgersene. |
| F3 | Nello zip si tolgono `vendor/maxmind-db/reader/ext/` (sorgenti C), i file `.md`, `.toml`, `mise.lock` e `package.xml`; si tengono `src/`, `autoload.php`, `composer.json` e `LICENSE`. Limite dello zip: **440.000 byte** (la 0.8.0 pesa 399.189 byte). | Il pacchetto pesa 172 KB, di cui la parte utile è circa 30 KB. La licenza Apache va distribuita con il codice. |
| F4 | Il database si chiama `dbip-country-lite.mmdb` e sta in `wp-content/uploads/wp5w/` (da `wp_upload_dir( null, false )['basedir']`), con `.htaccess` (`Require all denied` e `Deny from all`) e `index.php` vuoto. | Il percorso viene dalla roadmap. I dati di DB-IP Lite sono pubblici, quindi `.htaccess` serve per ordine e non per segretezza: su Nginx sarebbe ignorato, e va bene così. |
| F5 | **Aggiornamento:** un evento cron giornaliero, `wp5w_geo_update`, pianificato da `Scheduler::register()` se manca (primo avvio 5 minuti dopo). Se il file installato è del mese corrente (UTC) non fa nessuna richiesta di rete. | `wp plugin install --force` non esegue l'hook di attivazione, quindi la pianificazione deve stare dove già si pianificano gli altri eventi. Un controllo al giorno copre il ritardo di pubblicazione del primo giorno del mese senza un evento mensile, che WP-Cron non ha. |
| F6 | **Mesi tentati:** prima quello corrente, poi il precedente, ma solo se è più recente di quello installato (o se non c'è nessun file). Un `404` del mese corrente con il precedente già installato è «non ancora pubblicato» e **non** è un errore. | Rispetta il requisito (b) e non mostra errori falsi nei primi giorni del mese. |
| F7 | **Integrità e formato** prima della sostituzione: download in un file temporaneo (`download_url()`), decompressione in streaming con `gzopen`/`gzread` (il CRC del gzip lo controlla zlib), byte scritti uguali all'ISIZE del gzip, dimensione tra 1 MB e 64 MB, metadati validi (tipo con `Country`, `ip_version` 6, `build_epoch` negli ultimi 100 giorni) e una ricerca di prova (`8.8.8.8`) con un codice di 2 lettere. Poi `rename()` sul file definitivo, nella stessa cartella. | DB-IP non pubblica un checksum leggibile senza la pagina HTML. CRC, ISIZE e la lettura dei metadati (che stanno in fondo al file) bastano a scartare file troncati o corrotti. `rename()` nella stessa cartella è atomico, mentre `WP_Filesystem::move()` cancella prima il file vecchio. |
| F8 | Solo funzioni PHP e WordPress: `download_url()`, `gzopen()`, `gzread()`, `fopen()`, `fwrite()`, `rename()`, `wp_delete_file()`, `wp_mkdir_p()`. Nessun `exec`, `shell_exec`, `system`, `proc_open`, `popen` o `passthru`. | Sul blog `exec()` è disabilitato. Lo streaming tiene la memoria bassa anche con `WP_MEMORY_LIMIT` a 40M. |
| F9 | **Lock** con `GET_LOCK( 'wp5w_geo_update', 0 )`; nessun transient. | È lo schema già usato per la migrazione e l'aggregazione, e `verify.php` fallisce se trova un transient `wp5w`. |
| F10 | Lo stato dell'ultimo aggiornamento va nell'opzione `wp5w_geo` (non autoload): `month`, `built`, `size`, `updated`, `checked`, `error`. **L'endpoint non la legge mai:** controlla solo che il file esista. | Nessuna query in più nell'endpoint, e nessun peso in più in `alloptions`. |
| F11 | **Endpoint:** paese e user agent si calcolano **dopo l'ultimo scarto** (dopo il limite di frequenza), solo per le visite registrate. Nessuna query in più al database. Qualunque errore del lettore dà paese vuoto. | Gli scarti restano veloci come prima, e il paese non può mai bloccare una visita. |
| F12 | `Support\UserAgent` restituisce **i nomi di device-detector** visti sul blog (1.1): `smartphone`, `desktop`, `tablet`; `Mac`, `GNU/Linux`, `iPadOS`, `Chrome OS`; `Chrome Mobile`, `Chrome Mobile iOS`, `Mobile Safari` e così via. Uno user agent non riconosciuto dà stringa vuota. | Il requisito (d) chiede confronti omogenei. «Mobile» della roadmap diventa `smartphone`, che è il valore di WP Statistics. |
| F13 | Il valore sconosciuto è `(n/d)` in `dim_daily`, con lo stesso `COALESCE(NULLIF(...))` dell'ETL. In lettura, `Support\Dimensions` unisce `''`, `(n/d)` e `UNK` in `(n/d)`. | Due righe «non riconosciuto» nello stesso elenco confonderebbero, e `UNK` è solo un codice interno di device-detector. |
| F14 | Le dimensioni proprie si aggregano dalla **prima visita del giorno** di ogni visitatore (la stessa subquery già usata per `channel` e `source`) e stanno nella stessa `UNION` di `replace_by_month()`. | La somma dei visitatori è uguale per tutte le dimensioni e uguale ai visitatori `own` di `site_daily` (l'invariante di 1.1), e ogni mese si sostituisce in una sola transazione. |
| F15 | **Box «Pubblico»:** rotta propria `GET /admin/audience/dimensions?days=N`, con `days` tra 7, 30 e 90 (predefinito 30), periodo degli ultimi N giorni compreso oggi, come «Ultimi 7 giorni». Al massimo 10 valori per dimensione più una riga «Altri». | La scheda Visitatori non ha un selettore di periodo; un selettore compatto, come in «Articoli più visti», basta. Una rotta separata non tocca la risposta di `/admin/audience`. |
| F16 | **Attribuzione** «IP Geolocation by DB-IP», con link a `https://db-ip.com`, in un piede dell'app (visibile in tutte le schede) e nella card «Database dei paesi» della scheda Stato. | La licenza CC BY 4.0 chiede l'attribuzione dove si usano i dati; il piede la rende sempre visibile senza dipendere da quale origine mostra il box. |
| F17 | **Il Confronto per paese e dispositivo (requisito g) si rimanda.** Al suo posto c'è una query una tantum nel runbook (9.11). | Le dimensioni `own` dei giorni fino al deploy sono quasi tutte `(n/d)`, quindi servirebbe una finestra diversa da quella del Confronto (R1 della fase 2): non è una modifica semplice. |
| F18 | Disinstallazione: l'evento cron, l'opzione `wp5w_geo` e la cartella `uploads/wp5w/` si tolgono **sempre**, anche senza `WP5W_DELETE_DATA`. La disattivazione toglie solo l'evento. | Il database dei paesi è una cache che si riscarica, non uno storico. |

---

## 4. Regole di business

### R1. User agent (`Support\UserAgent`, classe pura)

```php
public static function parse( string $ua ): array // array{device: string, browser: string, os: string}
```

Le regole si applicano nell'ordine; per ogni campo vince la prima che corrisponde. Le espressioni sono indicative: i test di sezione 6 sono il contratto.

**Dispositivo (`device`):**

1. `tablet` se c'è `iPad`, oppure `Tablet` (Firefox per tablet Android), oppure `Android` **senza** `Mobile`;
2. `smartphone` se c'è `iPhone` o `iPod`, oppure `Android` con `Mobile`;
3. `desktop` se c'è `Windows NT`, `Macintosh`, `X11` o `CrOS`;
4. altrimenti `''`.

**Sistema (`os`, scritto in `dim_daily` come `platform`):**

1. `Windows` se c'è `Windows`;
2. `iPadOS` se c'è `iPad`;
3. `iOS` se c'è `iPhone` o `iPod`;
4. `Android` se c'è `Android`;
5. `Chrome OS` se c'è `CrOS`;
6. `Mac` se c'è `Macintosh` o `Mac OS X` (dopo iOS, perché anche gli user agent di iPhone contengono `like Mac OS X`);
7. `Ubuntu` se c'è `Ubuntu`;
8. `GNU/Linux` se c'è `Linux` o `X11`;
9. altrimenti `''`.

**Browser (`browser`):**

1. `Facebook` se c'è `FBAN`, `FBAV` o `FB_IAB`;
2. `Google Search App` se c'è `GSA/`;
3. `Microsoft Edge` se c'è `Edg/`, `EdgA/` o `EdgiOS/`;
4. `Opera Mobile` se c'è `OPR/` con `Mobile`, `Opera` se c'è `OPR/` senza;
5. `Samsung Browser` se c'è `SamsungBrowser/`;
6. `Firefox Mobile iOS` se c'è `FxiOS/`;
7. `Firefox Mobile` se c'è `Firefox/` con `Android`, `Firefox` se c'è `Firefox/` senza;
8. `Chrome Mobile iOS` se c'è `CriOS/`;
9. `Chrome Webview` se c'è `; wv)` insieme a `Chrome/`;
10. `Chrome Mobile` se c'è `Chrome/` (o `Chromium/`) con `Mobile`, `Chrome` se c'è senza;
11. `Mobile Safari` se c'è `Version/` e `Safari/` con `iPhone`, `iPad` o `iPod`, `Safari` se ci sono senza;
12. altrimenti `''`.

**Limiti noti, accettati:** gli iPad con iPadOS 13 e successivi inviano lo user agent di un Mac, quindi contano come `desktop`, `Mac` e `Safari` (device-detector senza client hints fa lo stesso). Brave, Vivaldi e i browser che non si dichiarano risultano `Chrome`; Yandex, Instagram, LinkedIn e gli altri browser minori risultano `Chrome`, `Chrome Mobile`, `Mobile Safari` o `''`. Lo user agent non viene mai salvato: si salvano solo i tre valori.

### R2. Paese (`Support\GeoDb`, classe pura, e `Geo\CountryDatabase`)

**`Support\GeoDb`** (pura, coperta da `GeoDbTest`):

| Elemento | Regola |
|---|---|
| `URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.mmdb.gz'` e `url( string $month ): string` | `$month` in formato `Y-m`. |
| `candidates( ?string $installed, string $today_utc ): list<string>` | `current` = `Y-m` di `$today_utc`; `previous` = mese prima (anche a cavallo dell'anno: `2027-01` dà `2026-12`). Se `$installed === current` restituisce `[]`. Altrimenti `[ current ]`, più `previous` se `$installed` è `null` oppure è minore di `previous`. |
| `country( mixed $record ): string` | Legge `$record['country']['iso_code']`; restituisce il valore in maiuscolo solo se corrisponde a `/^[A-Z]{2}$/` e non è `ZZ`, altrimenti `''` (anche con `null`, un tipo diverso da array o chiavi mancanti). |
| `MIN_SIZE = 1048576`, `MAX_SIZE = 67108864` e `size_ok( int $bytes ): bool` | Vero tra i due limiti compresi. |
| `isize_ok( int $written, string $tail ): bool` | `$tail` sono gli ultimi 4 byte del file `.gz`. Vero se `strlen( $tail ) === 4` e `unpack( 'V', $tail )[1] === $written % 2**32`. |
| `MAX_AGE_DAYS = 100` e `metadata_ok( string $type, int $ip_version, int $build_epoch, int $now ): bool` | Vero se `$type` contiene `Country` (senza distinzione di maiuscole: vale anche per `GeoLite2-Country`, così la sorgente si può cambiare), `$ip_version === 6` e `$now − 100 giorni <= $build_epoch <= $now + 2 giorni`. |

**`Geo\CountryDatabase`** (WordPress, con il guard ABSPATH, tutti metodi statici):

| Elemento | Regola |
|---|---|
| `OPTION = 'wp5w_geo'`, `FILE = 'dbip-country-lite.mmdb'`, `LOCK = 'wp5w_geo_update'`, `PROBE_IP = '8.8.8.8'` | |
| `dir(): string` e `path(): string` | `wp_upload_dir( null, false )['basedir'] . '/wp5w'` (il secondo argomento evita la creazione della cartella del mese a ogni chiamata) e `dir() . '/' . FILE`. |
| `lookup( string $ip ): string` | Se il file non è leggibile restituisce `''`. Altrimenti apre il lettore una sola volta per richiesta (proprietà statica, che `update()` azzera dopo una sostituzione riuscita), chiama `get( $ip )` e restituisce `GeoDb::country()`. Qualunque `\Throwable` (IP non valido, file corrotto) dà `''` e, solo con `WP_DEBUG`, una riga in `error_log`. Nessuna lettura di opzioni e nessuna query. |
| `update(): array{ok: bool, month: ?string, message: string}` | R3. |
| `status(): array` | Per la scheda Stato: `available` (file leggibile), `month`, `built`, `size`, `updated`, `checked`, `error` dall'opzione, e `next` da `wp_next_scheduled( Scheduler::GEO_HOOK )`. |

### R3. Download e sostituzione (`CountryDatabase::update`)

1. **Lock** `GET_LOCK( 'wp5w_geo_update', 0 )`. Se è occupato restituisce `ok` falso con «aggiornamento già in corso» e non tocca l'opzione.
2. **Mese installato:** il `month` dell'opzione se il file esiste ed è leggibile, altrimenti `null`.
3. **Mesi da provare:** `GeoDb::candidates( $installed, gmdate( 'Y-m-d' ) )`. Se l'elenco è vuoto si aggiorna solo `checked` e si restituisce `ok` vero («già aggiornato»), senza nessuna richiesta di rete.
4. **Cartella:** `wp_mkdir_p( dir() )`; se mancano, si scrivono `.htaccess` e `index.php` (testi in R7). Se la cartella non è scrivibile: errore.
5. Per ogni mese, nell'ordine:
   1. `require_once ABSPATH . 'wp-admin/includes/file.php'` (serve nel cron) e `$gz = download_url( GeoDb::url( $month ), 300 )`. Con un `WP_Error`: se `get_error_data()['code'] === 404` si annota «non ancora pubblicato» e si passa al mese successivo; con qualunque altro errore (anche 403, 429 o 503) si annota il messaggio come errore vero e si passa al mese successivo. **Attenzione:** `download_url()` usa il codice `http_404` per **ogni** risposta diversa da 200 (`wp-admin/includes/file.php`), quindi il codice dell'errore non basta: conta solo lo stato HTTP in `get_error_data()['code']`.
   2. Decompressione in streaming da `$gz` a `dir() . '/' . FILE . '.tmp'`, a blocchi di 64 KB. Un `gzread()` che restituisce `false` è un errore.
   3. Controlli, in quest'ordine: `GeoDb::isize_ok()` (gli ultimi 4 byte di `$gz`, letti con `fopen`/`fseek`/`fread`), `GeoDb::size_ok()`, apertura con `new Reader( $tmp )`, `GeoDb::metadata_ok()` con `databaseType`, `ipVersion` e `buildEpoch`, e `GeoDb::country( $reader->get( PROBE_IP ) ) !== ''`. Poi `$reader->close()`.
   4. Se un controllo fallisce: si cancella il temporaneo, si annota il motivo e si passa al mese successivo.
   5. Se tutto va bene: `rename( $tmp, path() )`. Se `rename()` fallisce è un errore e il temporaneo si cancella.
   6. Si salva l'opzione (`update_option( OPTION, $value, false )`) con `month`, `built` (`buildEpoch`), `size`, `updated` = `checked` = `time()` ed `error` vuoto, e si restituisce `ok` vero.
   7. In ogni caso (blocco `finally`) si cancella `$gz` con `wp_delete_file()`.
6. **Nessun mese riuscito:** il file esistente resta com'è. Si aggiorna `checked`. Si scrive `error` solo se c'è stato un errore vero (rete, gzip, formato, scrittura), oppure se non c'è nessun file installato e tutti i mesi hanno dato `404`. Un `404` del mese corrente con il precedente installato **non** è un errore (F6). Il valore restituito ha `ok` falso solo con un errore vero.
7. Il lock si rilascia sempre (`finally`).

Le chiamate a `fopen`, `fwrite`, `fclose`, `gzopen`, `gzread` e `rename` hanno un `phpcs:ignore` puntuale con la motivazione («streaming senza WP_Filesystem» o «sostituzione atomica»). Non si aggiungono esclusioni globali.

### R4. Endpoint `POST /wp5w/v1/hit`

In `HitController::handle()` cambia solo il passo 9, dopo il limite di frequenza:

```php
// Passo 9: referrer, canale, sorgente, ingresso, paese e user agent (solo per le visite che si registrano).
$agent   = UserAgent::parse( $ua );
$country = CountryDatabase::lookup( $ip );
```

e la `INSERT` del passo 10 aggiunge le colonne `country`, `device`, `browser` e `os` (troncate a 2, 20, 40 e 40 caratteri, come nello schema). Tutto il resto resta com'è: ordine degli scarti, una `SELECT` e una `INSERT` su `wp5w_hits`, nessuna scrittura in `wp_options` e risposta `204`. IP e user agent non vengono salvati. Il commento di testa della classe e quello di `Schema` sulle colonne «vuote fino alla fase 3» si aggiornano.

### R5. Aggregazione (`OwnSource`)

- `required()` aggiunge `country`, `device`, `browser` e `os` alle colonne richieste di `wp5w_hits`.
- La `SELECT` di `dim_daily` aggiunge alla stessa `UNION ALL` quattro parti con la stessa subquery `$first` (prima visita del giorno di ogni visitatore):

```sql
SELECT h.day, 'country', COALESCE(NULLIF(h.country, ''), '(n/d)'), COUNT(*), 'own' FROM {hits} h JOIN ( {first} ) f ON f.id = h.id GROUP BY h.day, 3
-- e così per 'device' (h.device), 'browser' (h.browser) e 'platform' (h.os)
```

- Il commento «Dimensioni (nella fase 1 solo canale e sorgente)» si aggiorna. `replace_by_month()` assegna da sé le coppie di segnaposto.
- **Invariante:** per ogni giorno, `SUM(visitors)` delle righe `own` è uguale per `channel`, `source`, `country`, `device`, `browser` e `platform`, e uguale ai visitatori `own` di `site_daily`.
- Le visite registrate prima del deploy hanno le quattro colonne vuote, quindi i loro giorni avranno solo `(n/d)`. È atteso e non si corregge (IP e user agent non sono salvati).

### R6. Dati del box «Pubblico»

**`Support\Dimensions`** (pura, coperta da `DimensionsTest`):

```php
public const DIMS    = array( 'country', 'device', 'browser', 'platform' );
public const UNKNOWN = '(n/d)';
public const LIMIT   = 10;
public static function top( array $values, int $limit = self::LIMIT ): array // array{total: int, rows: list<array{value: string, visitors: int}>, other: int}
```

- `$values` è `array<string, int>` (valore → visitatori).
- `''`, `(n/d)` e `UNK` si sommano in `(n/d)`.
- L'ordine è per visitatori decrescenti, poi per valore crescente **in ordine binario** (`strcmp`), così coincide con `ORDER BY ..., BINARY value` in SQL e non dipende dalla collation `utf8mb4_unicode_520_ci`.
- `rows` contiene al massimo `$limit` righe, `other` è la somma delle righe escluse e `total` la somma di tutte.

**`AudienceQuery::dimensions( DateRange $range ): array`:**

- una sola `SELECT dim, value, SUM(visitors) FROM dim_daily WHERE day BETWEEN %s AND %s AND dim IN (...) AND {Origin::where( Settings::own_since() )} GROUP BY dim, value`;
- `first_day` = `MIN(day)` di `dim_daily` per le stesse dimensioni, **con la stessa regola dell'origine**;
- il risultato:

```json
{
  "range": { "from": "2026-09-16", "to": "2026-10-15" },
  "first_day": "2026-04-17",
  "dims": {
    "country":  { "total": 4210, "rows": [ { "value": "IT", "visitors": 1200 } ], "other": 310 },
    "device":   { "total": 4210, "rows": [], "other": 0 },
    "browser":  { "total": 4210, "rows": [], "other": 0 },
    "platform": { "total": 4210, "rows": [], "other": 0 }
  }
}
```

Le quattro chiavi di `dims` ci sono sempre, anche vuote (`total` 0).

**Rotta** `GET /wp5w/v1/admin/audience/dimensions`, con `check_admin_permission`, `respond()` (quindi `Cache-Control: no-store`) e l'argomento `days` (`integer`, `enum` 7, 30, 90, predefinito 30). Il periodo è `DateRange::last_days( $days, wp_date( 'Y-m-d' ) )`.

`AudienceQuery` resta nel `grep` della regola dell'origine: entrambe le letture nuove hanno `Origin::where()`. `CompareQuery` resta l'unica eccezione.

### R7. Interfaccia

**Box «Pubblico»** (`assets/admin/components/AudienceDimensions.jsx`), nella scheda Visitatori subito dopo le due colonne «Ultimi 7 giorni» e «Ultimi 6 mesi», prima di «Provenienza»:

- `Card` con `id="audience-dimensions"`, titolo «Pubblico» con le date del periodo, e nelle `actions` tre pulsanti compatti in `wp5w-switch` («7 giorni», «30 giorni», «90 giorni», predefinito 30), come in «Articoli più visti». Il componente carica da sé la rotta, con `Spinner` e `Notice` di errore.
- Dentro, in una griglia di due colonne (`wp5w-columns`), quattro elenchi con un titolo `h3`: «Paesi», «Dispositivi», «Browser» e «Sistemi operativi». Ogni elenco ha una riga per valore, con nome, visitatori e quota, e la barra proporzionale con le classi di `ChannelList` (`wp5w-channels`, `__row`, `__value`, `__bar`). La quota si calcola su `total`, e in fondo c'è la riga «Altri» se `other` è maggiore di 0. `ChannelList.jsx` non cambia.
- **Etichette:** paese con `new Intl.DisplayNames( [ 'it' ], { type: 'region' } ).of( code )` seguito dal codice (per esempio «Italia (IT)»), e il codice da solo se `Intl` non lo conosce. Dispositivi: `smartphone` «Smartphone», `desktop` «Desktop», `tablet` «Tablet», `phablet` «Phablet», `tv` «TV», gli altri come sono. Sistemi: `Mac` «macOS», `GNU/Linux` «Linux», gli altri come sono. Browser come sono. `(n/d)` è «Non riconosciuto» in tutti gli elenchi.
- **Note sotto il box:** «Visitatori sommati sui giorni del periodo: una persona conta una volta per ogni giorno di visita.» Se `first_day` è successivo a `range.from`: «Dati disponibili dal {first_day}.» Se `total` è 0 in tutte le dimensioni: «Nessun dato nel periodo.»

**Card «Database dei paesi»** nella scheda Stato (`id="status-geo"`), dopo «Dati importati» e prima di «Import». Legge `geo` da `/admin/status`:

- con il file: «DB-IP Lite Country di {mese anno}, compilato il {built}, {size in MB}. Installato il {updated}.»;
- senza file: «Non ancora scaricato: il paese delle visite resta vuoto finché non arriva il primo aggiornamento.»;
- sempre: «Ultimo controllo: {checked}. Prossimo: {next}.», e l'errore, se c'è, in un `Notice` di tipo `warning` senza chiusura;
- in fondo l'attribuzione: `<a href="https://db-ip.com">IP Geolocation by DB-IP</a>` (licenza CC BY 4.0).

`AdminController::status()` aggiunge la chiave `geo` con `CountryDatabase::status()`. Nessun pulsante «aggiorna ora»: nel runbook si usa WP-CLI.

**Piede dell'app** (`assets/admin/index.jsx`): sotto `TabPanel`, un paragrafo `wp5w-note wp5w-admin__credits` con il link «IP Geolocation by DB-IP» (`https://db-ip.com`, `target="_blank"` e `rel="noopener noreferrer"`).

**Testi fissi in PHP:**

- `.htaccess`: `# wp-5w: accesso diretto negato al database dei paesi.`, poi `<IfModule mod_authz_core.c>` `Require all denied` `</IfModule>` e `<IfModule !mod_authz_core.c>` `Order deny,allow` `Deny from all` `</IfModule>`, una direttiva per riga;
- `index.php`: `<?php // Silence is golden.`

### R8. Pianificazione, disattivazione e disinstallazione

- `Scheduler`: costante `GEO_HOOK = 'wp5w_geo_update'`; in `register()`, `add_action( self::GEO_HOOK, static fn() => CountryDatabase::update() )` e, se manca, `wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', self::GEO_HOOK )`.
- `Deactivator`: `wp_clear_scheduled_hook( Scheduler::GEO_HOOK )`.
- `uninstall.php`, **prima** del controllo di `WP5W_DELETE_DATA`: `wp_clear_scheduled_hook( 'wp5w_geo_update' )`, `delete_option( 'wp5w_geo' )`, cancellazione di `dbip-country-lite.mmdb`, `dbip-country-lite.mmdb.tmp`, `.htaccess` e `index.php` in `uploads/wp5w/` con `wp_delete_file()`, poi `rmdir()` della cartella (con `phpcs:ignore` motivato; se non è vuota resta).

---

## 5. File da creare o modificare

### Da creare

| File | Contenuto |
|---|---|
| `src/Support/UserAgent.php` | R1. Classe pura senza il guard ABSPATH. |
| `src/Support/GeoDb.php` | R2, parte pura. |
| `src/Support/Dimensions.php` | R6, parte pura. |
| `src/Geo/CountryDatabase.php` | R2 e R3, con il guard ABSPATH. |
| `assets/admin/components/AudienceDimensions.jsx` | Box «Pubblico» (R7). |
| `tests/Unit/UserAgentTest.php`, `tests/Unit/GeoDbTest.php`, `tests/Unit/DimensionsTest.php` | Sezione 6. |

### Da modificare

| File | Modifica |
|---|---|
| `composer.json`, `composer.lock` | `"maxmind-db/reader": "^1.14"` in `require` (F1). |
| `scripts/build-zip.js` | Esclusioni di F3 per `vendor/maxmind-db/reader` (per esempio con `archive.glob` e `ignore`). Il resto di `vendor` non cambia. |
| `src/REST/HitController.php` | R4. |
| `src/Sources/OwnSource.php` | R5. |
| `src/Query/AudienceQuery.php` | Metodo `dimensions()` (R6). |
| `src/REST/AdminController.php` | Rotta `GET /admin/audience/dimensions` e chiave `geo` in `status()`. |
| `src/Etl/Scheduler.php`, `src/Deactivator.php`, `uninstall.php` | R8. |
| `src/Database/Schema.php` | Solo il commento di `wp5w_hits` («vuote fino alla fase 3» diventa la descrizione dei valori). `DB_VERSION` resta `'4'`. |
| `assets/admin/pages/AudiencePage.jsx` | Inserisce `<AudienceDimensions />` (R7). |
| `assets/admin/pages/StatusPage.jsx` | Card «Database dei paesi» (R7). |
| `assets/admin/index.jsx` | Piede con l'attribuzione (F16). |
| `assets/admin/styles/admin.scss` | Solo se serve, per esempio la spaziatura dei titoli `h3` del box e del piede. |
| `tests/fixtures/verify.php` | Sezione 4 (istantanea) e nuova sezione 11, con la pulizia che diventa la 12 (sezione 7). |
| `CLAUDE.md` | In «Struttura»: `Support\UserAgent`, `GeoDb`, `Dimensions`, `Geo\CountryDatabase` e l'evento `wp5w_geo_update`. In «Punti da ricordare»: la dimensione si chiama `platform` (la colonna di `wp5w_hits` `os`); i valori sono quelli di device-detector (`smartphone`, `Mac`, `GNU/Linux`...); il valore sconosciuto è `(n/d)`; WP Statistics usa MaxMind con prefisso (`WP_Statistics\Dependencies\MaxMind\Db`), il plugin senza; il database dei paesi si scarica solo con funzioni PHP (sul blog `exec()` è disabilitato); la disinstallazione cancella sempre `uploads/wp5w/`. |
| `README.md` | Sezione breve «Database dei paesi»: sorgente, licenza CC BY 4.0, attribuzione, percorso e aggiornamento giornaliero. |
| `CHANGELOG.md` | Voce `0.9.0`. La versione la aggiorna `npm run release -- 0.9.0`, non a mano. |

Restano **invariati** `assets/tracker/tracker.js`, `webpack.config.js`, `src/Front/Tracker.php`, `src/Sources/WpStatisticsSource.php`, `src/Query/CompareQuery.php`, `src/Support/Comparison.php`, `assets/admin/components/Compare.jsx` e `assets/admin/components/ChannelList.jsx`. Il tracker non cambia, quindi dopo il deploy non serve svuotare la cache di LiteSpeed.

---

## 6. Test PHPUnit da aggiungere

### `UserAgentTest` (data provider con user agent reali)

| # | User agent | device | browser | os |
|---|---|---|---|---|
| 1 | `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36` | desktop | Chrome | Windows |
| 2 | come 1, con ` Edg/129.0.0.0` in fondo | desktop | Microsoft Edge | Windows |
| 3 | `Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0` | desktop | Firefox | Windows |
| 4 | `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 OPR/114.0.0.0` | desktop | Opera | Windows |
| 5 | `Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36` | desktop | Chrome | Windows |
| 6 | `Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15` | desktop | Safari | Mac |
| 7 | `Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36` | desktop | Chrome | Mac |
| 8 | `Mozilla/5.0 (Macintosh; Intel Mac OS X 14.7; rv:131.0) Gecko/20100101 Firefox/131.0` | desktop | Firefox | Mac |
| 9 | `Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36` | desktop | Chrome | GNU/Linux |
| 10 | `Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0` | desktop | Firefox | Ubuntu |
| 11 | `Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36` | desktop | Chrome | Chrome OS |
| 12 | `Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36` | smartphone | Chrome Mobile | Android |
| 13 | `Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36` | smartphone | Samsung Browser | Android |
| 14 | `Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0` | smartphone | Firefox Mobile | Android |
| 15 | come 12, con ` EdgA/129.0.0.0` in fondo | smartphone | Microsoft Edge | Android |
| 16 | come 12, con ` OPR/85.0.0.0` in fondo | smartphone | Opera Mobile | Android |
| 17 | `Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A.240905.003; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36` | smartphone | Chrome Webview | Android |
| 18 | come 17, con ` [FB_IAB/FB4A;FBAV/480.0.0.47.109;]` in fondo | smartphone | Facebook | Android |
| 19 | `Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36` | tablet | Chrome | Android |
| 20 | `Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Safari/537.36` | tablet | Samsung Browser | Android |
| 21 | `Mozilla/5.0 (Android 14; Tablet; rv:131.0) Gecko/131.0 Firefox/131.0` | tablet | Firefox Mobile | Android |
| 22 | `Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1` | smartphone | Mobile Safari | iOS |
| 23 | `Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0.6668.69 Mobile/15E148 Safari/604.1` | smartphone | Chrome Mobile iOS | iOS |
| 24 | `Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/131.0 Mobile/15E148 Safari/605.1.15` | smartphone | Firefox Mobile iOS | iOS |
| 25 | `Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 EdgiOS/129.0.2792.84 Mobile/15E148 Safari/605.1.15` | smartphone | Microsoft Edge | iOS |
| 26 | `Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) GSA/337.0.672716006 Mobile/15E148 Safari/604.1` | smartphone | Google Search App | iOS |
| 27 | `Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/480.0.0.40.109;FBBV/650000000;FBDV/iPhone15,2;FBMD/iPhone;FBSN/iOS;FBSV/17.6;FBSS/3;FBCR/;FBID/phone;FBLC/it_IT;FBOP/5]` | smartphone | Facebook | iOS |
| 28 | `Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1` | tablet | Mobile Safari | iPadOS |
| 29 | iPad con iPadOS 13+ (limite noto di R1): uguale a 6 ma con `Version/17.6` | desktop | Safari | Mac |
| 30 | `Mozilla/5.0 (Nintendo Switch; WifiWebAuthApplet) AppleWebKit/606.4 (KHTML, like Gecko) NF/6.0.1.15.4 NintendoBrowser/5.1.0.20393` | `''` | `''` | `''` |
| 31 | `''` (vuoto) | `''` | `''` | `''` |

Un test in più controlla che ogni valore restituito in tutto il data provider stia nell'elenco dei valori di WP Statistics di 1.1 (oppure sia `''`), e che le lunghezze rispettino lo schema (20, 40 e 40 caratteri).

### `GeoDbTest`

| Gruppo | Casi minimi |
|---|---|
| `url()` | `2026-10` dà `https://download.db-ip.com/free/dbip-country-lite-2026-10.mmdb.gz`. |
| `candidates()` | Nessun file (`null`) il 2026-10-14: `['2026-10', '2026-09']`. Installato `2026-10`: `[]`. Installato `2026-09`: `['2026-10']`. Installato `2026-07`: `['2026-10', '2026-09']`. Cambio d'anno (2027-01-01, nessun file): `['2027-01', '2026-12']`. |
| `country()` | Record di DB-IP con `US`: `US`. `it` minuscolo: `IT`. `ZZ`: `''`. `USA` o `1A`: `''`. `null`, stringa, array senza `country` o senza `iso_code`: `''`. |
| `size_ok()` | 1.048.575 falso, 1.048.576 vero, 8.348.509 vero, 67.108.864 vero, 67.108.865 falso. |
| `isize_ok()` | `pack( 'V', 8348509 )` con 8.348.509: vero; con 8.348.508: falso; coda di 3 byte: falso; valore oltre 2^32 (`2**32 + 5` contro `pack( 'V', 5 )`): vero. |
| `metadata_ok()` | `DBIP-Country-Lite`, 6, epoch di 13 giorni prima: vero. `GeoLite2-Country`: vero. `DBIP-City-Lite`: falso. `ip_version` 4: falso. Epoch di 101 giorni prima: falso. Epoch di 3 giorni nel futuro: falso. |

### `DimensionsTest`

- `''` 2, `(n/d)` 3 e `UNK` 1 danno una sola riga `(n/d)` con 6.
- Ordine per visitatori decrescenti, a parità per valore: (`US` 5, `IT` 5, `DE` 7) dà `DE`, `IT`, `US`.
- Ordine binario a parità: (`Opera` 2, `opera mini` 2, `Opera Mobile` 2) dà `Opera`, `Opera Mobile`, `opera mini` (le maiuscole prima).
- 15 valori con limite 10: 10 righe, `other` uguale alla somma delle 5 escluse e `total` uguale alla somma di tutte.
- Ingresso vuoto: `total` 0, `rows` vuoto, `other` 0.

`composer ci` (PHPCS, PHPStan livello 6 e PHPUnit) deve passare senza nuove esclusioni globali.

---

## 7. Controlli da aggiungere a `tests/fixtures/verify.php`

### Sezione 4 (istantanea dell'interfaccia)

`$ui_snapshot` aggiunge la voce `'pubblico'` con `wp_json_encode( $audience->dimensions( $r30 ) )`. Così le sezioni 4 e 7 provano che, con righe `own` presenti e `wp5w_own_since` vuota, il box non cambia.

### Nuova sezione 11. «Pubblico, paese e user agent»

Si mette dopo la sezione 10 (Confronto), e la pulizia finale diventa la sezione 12. Si usano `$check`, `$send_hit`, `$payload`, `$rest_call` e `$admin_user` già presenti. All'inizio si salvano l'opzione `wp5w_geo` e l'impronta MD5 del file del database, se c'è.

1. **Box con i dati `wps`** (`wp5w_own_since` vuota):
   - `GET /admin/audience/dimensions?days=30` senza utente: 401 o 403; con l'amministratore: 200 e `Cache-Control: no-store, max-age=0`; con `days=15`: 400;
   - per ognuna delle quattro dimensioni, `total` è uguale a `SELECT SUM(visitors) FROM dim_daily WHERE origin = 'wps' AND dim = ... AND day BETWEEN from AND to` (con `from` e `to` presi dalla risposta), e `rows` contiene gli stessi valori e numeri della `SELECT ... GROUP BY value ORDER BY SUM(visitors) DESC, BINARY value LIMIT 10`;
   - con i dati del seed: `country` contiene `IT` e `US`, `device` solo `smartphone`, `browser` solo `Chrome` e `platform` solo `Android`.
2. **Download reale.** Se il file c'è già ed è del mese corrente lo si tiene, altrimenti `CountryDatabase::update()` deve restituire `ok` vero. Si verifica poi che:
   - il file esista e superi 1 MB;
   - l'opzione abbia `month` uguale al mese corrente o a quello precedente, e `error` vuoto;
   - `.htaccess` contenga `Require all denied` e `index.php` esista;
   - se `month` è il mese corrente, un secondo `update()` non fa nessuna richiesta HTTP (filtro `pre_http_request` che conta le chiamate: 0).
3. **Ricerche:** `lookup( '8.8.8.8' )` dà `US`, `lookup( '10.0.0.1' )` dà `''`, `lookup( 'non-un-ip' )` dà `''`, `lookup( '2a00:1450:4002::1' )` dà `IT`.
4. **Errori di download simulati.** Nei casi 1-4, 6 e 7 si forza `month` dell'opzione al mese precedente, così `update()` prova solo il mese corrente; nel caso 5 lo si forza a due mesi prima, così prova il corrente e poi il precedente. Un filtro `pre_http_request` restituisce le risposte, e per i casi con un corpo **scrive il corpo in `$args['filename']`**, altrimenti `download_url()` troverebbe un file vuoto:
   1. `404` per tutti i mesi: `ok` vero, il file è identico (MD5), `month` non cambia, `error` vuoto (F6), e in `uploads/wp5w/` non restano file `.tmp`;
   2. corpo che non è un gzip: `ok` falso, il file è identico, `error` non vuoto, nessun `.tmp`;
   3. gzip dei primi 2 MB del database (troncato): `ok` falso, il file è identico;
   4. gzip valido di un file di 2 MB di byte casuali: `ok` falso (metadati non trovati), il file è identico;
   5. mese corrente `404` e mese precedente uguale al gzip del database installato: `ok` vero e `month` uguale al mese precedente (ripiego);
   6. mese corrente `503`: `ok` falso, `error` non vuoto (non è «non ancora pubblicato»), il file è identico;
   7. gzip reale del database tagliato a metà (il guasto di rete più realistico, che esercita il ramo di `gzread()` e l'ISIZE): `ok` falso, il file è identico, nessun `.tmp`.

   Alla fine si tolgono il filtro e si rimettono l'opzione salvata e il file originale.
5. **Lock:** con `GET_LOCK( 'wp5w_geo_update', 0 )` preso da una **seconda connessione** (`new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST )`: nella stessa sessione MySQL il lock è rientrante e non bloccherebbe), `update()` restituisce `ok` falso con «già in corso» e l'opzione non cambia. Poi si rilascia il lock e si chiude la connessione.
6. **Database assente:** si rinomina il file. `lookup( '8.8.8.8' )` dà `''`; una visita valida riceve `204` ed è registrata con `country` vuoto, senza warning PHP (stesso gestore di errori del controllo dei parametri array); `status()['available']` è falso. Poi si rimette il file.
7. **Visite con paese e user agent:**
   - da `8.8.8.8`, con lo user agent Chrome di Windows (UA 1 di sezione 6): riga con `country = 'US'`, `device = 'desktop'`, `browser = 'Chrome'` e `os = 'Windows'`;
   - da `151.38.1.1`, con l'iPhone Safari (UA 22): `IT`, `smartphone`, `Mobile Safari` e `iOS`;
   - da `198.51.100.30`, con lo user agent Nintendo (UA 30): `country`, `device`, `browser` e `os` vuoti, ed è registrata;
   - un bot (Googlebot) da `8.8.8.8` resta uno scarto `bot`, senza riga.
8. **Nessuna query in più:** dopo una visita di riscaldamento (chiave del giorno già valida, database presente), si cattura con il filtro `query` una visita registrata da `8.8.8.8`. Il **numero totale** di query catturate è uguale al numero di query su `wp5w_hits`, cioè 2 (una `SELECT` e una `INSERT`), e nessuna contiene `wp5w_geo`. Il confronto tra due visite con e senza database non basterebbe, perché `CountryDatabase::path()` (e quindi `wp_upload_dir()`) gira in entrambe. Se WordPress aggiungesse una query fissa estranea al plugin, l'implementatore la misura una volta in 0.8.0 (stesso controllo su `main`) e la registra in `verify.php` come base, con un commento.
9. **Privacy:** nessuna colonna di `wp5w_hits` contiene `8.8.8.8`, `151.38.1.1` o una parte dello user agent (per esempio `Mozilla`).
10. **Aggregazione:** `( new OwnSource() )->run_hourly()`. Poi, per oggi:
    - ci sono righe `own` per `country`, `device`, `browser` e `platform`;
    - `SUM(visitors)` di ognuna è uguale ai visitatori `own` di `site_daily` e a quella di `channel` (invariante R5);
    - `country` ha `US` e `IT` almeno a 1 e `(n/d)` per le visite senza paese;
    - `platform` contiene `Windows` e `iOS`, e nessuna riga `own` ha `dim = 'os'`;
    - le righe `wps` hanno lo stesso checksum di prima di `run_hourly()`.
11. **Regola dell'origine nel box:** con `wp5w_own_since` vuota, `dimensions()` coincide con il punto 1 (le righe `own` non entrano). Con `wp5w_own_since` = oggi, `total` di `country` diventa la somma delle righe `wps` prima di oggi più le righe `own` di oggi, e `first_day` non cambia. Poi si rimette la data vuota.
12. **Stato:** `GET /admin/status` contiene `geo.available` vero, `geo.month` non vuoto e `geo.built` maggiore di 0.
13. **Cron:** `wp_next_scheduled( 'wp5w_geo_update' )` non è falso, e la ricorrenza è `daily`.
14. **Ripristino:** opzione `wp5w_geo` e file come all'inizio della sezione. Il database dei paesi **non** si cancella nella pulizia finale, così le esecuzioni successive non lo riscaricano.

La sezione 12 (pulizia finale) resta quella di oggi.

---

## 8. Criteri di accettazione

Ogni criterio va nella checklist della PR, con l'esito.

1. Il passo 0 (sezione 2) è stato eseguito se la data lo permette, e la PR riporta i numeri di 9.0 con l'esito della tabella di decisione. Con «proxy sospetto» o «raccolta ferma o parziale» il lavoro si è fermato e Maurizio è stato avvisato.
2. `composer ci` passa: PHPCS, PHPStan livello 6 e PHPUnit, compresi `UserAgentTest` (almeno i 31 casi di sezione 6), `GeoDbTest` e `DimensionsTest`. Nessuna nuova esclusione globale, e solo `phpcs:ignore` puntuali e motivati.
3. `npm run check` passa, e `git diff main -- assets/tracker webpack.config.js src/Front/Tracker.php` è vuoto.
4. `seed.php` seguito da `verify.php` termina con «Tutti i controlli superati», comprese la voce `pubblico` dell'istantanea e tutta la sezione 11.
5. `Support\UserAgent` restituisce solo i nomi di WP Statistics di 1.1 (`smartphone`, `desktop`, `tablet`; `Mac`, `GNU/Linux`, `iPadOS`...) oppure `''`.
6. Una visita registrata salva `country`, `device`, `browser` e `os`. L'endpoint non fa **nessuna query in più**: una visita registrata esegue solo la `SELECT` e la `INSERT` su `wp5w_hits` (verify, punto 8), gli scarti seguono lo stesso ordine di prima, e né l'IP né lo user agent sono salvati (verify, punto 9).
7. Il database dei paesi si scarica solo con funzioni PHP e WordPress: `grep -nE "\b(exec|shell_exec|system|proc_open|popen|passthru)\s*\(" src uninstall.php` non trova nulla. Il download prova il mese corrente e poi il precedente (F6), controlla integrità e formato (F7) e sostituisce il file con `rename()`. Solo uno stato HTTP 404 vale «non ancora pubblicato»; ogni altro errore (503, gzip troncato, formato) è un errore vero e lascia intatto il file esistente (verify, punto 4).
8. Senza database il paese è vuoto, la visita è registrata e non ci sono warning; la scheda Stato lo dice (verify, punto 6).
9. `dim_daily` riceve righe `own` per `country`, `device`, `browser` e `platform` con l'invariante di R5. Le righe `wps` non cambiano (verify, punto 10).
10. `GET /admin/audience/dimensions` risponde 200 solo agli amministratori, con `no-store`, `days` limitato a 7, 30 o 90, e numeri uguali alle `SELECT` dirette su `dim_daily` (verify, punto 1). Tutte le letture usano `Origin::where()`, e il `grep` della regola dell'origine trova come unica eccezione `CompareQuery`.
11. In wp-env la scheda Visitatori mostra il box «Pubblico» con i quattro elenchi e le barre, e i pulsanti del periodo funzionano. La scheda Stato mostra la card «Database dei paesi» con la data del database. Il piede con «IP Geolocation by DB-IP» si vede in tutte le schede. La PR contiene uno screenshot del box e uno della card.
12. Con `wp5w_own_since` vuota le altre schede restituiscono gli stessi numeri (verify, sezioni 4 e 7; sul blog, passo 9.5).
13. `Schema::DB_VERSION` resta `'4'`, e non ci sono tabelle nuove. Le sole aggiunte sono l'opzione `wp5w_geo`, l'evento `wp5w_geo_update` e la cartella `uploads/wp5w/`, che la disattivazione (evento) e la disinstallazione (tutto, R8) tolgono.
14. `maxmind-db/reader` `^1.14` è in `composer.json` e `composer.lock`. Lo zip della release pesa **al massimo 440.000 byte**, non contiene `vendor/maxmind-db/reader/ext/` e contiene `vendor/maxmind-db/reader/LICENSE`.
15. `CLAUDE.md`, `README.md` (database dei paesi e attribuzione) e `CHANGELOG.md` (0.9.0) sono aggiornati.
16. Dopo il deploy sul blog tutti i passi bloccanti del runbook sono verdi, compreso il gate dei tempi (9.8) con il limite del +20% e con il paese valorizzato nelle visite di prova.

---

## 9. Runbook di deploy

Blog: `ssh mavida-blog`, cartella `~/domains/mavida.com/public_html/maurizio`, prefisso delle tabelle `maurizio_`, plugin in `public/plugins`, upload in `public/uploads` (URL `https://maurizio.mavida.com/public/uploads`), admin su `/app/wp-admin`. `exec()` è disabilitato: niente `wp db export` e `wp db import`, i backup si fanno con `mysqldump`. Il file `~/logs/maurizio-debug.log` non esiste finché non c'è un errore. `wp5w_hits.ts` è in UTC. Per gli argomenti che iniziano con `/` in Git Bash serve `MSYS_NO_PATHCONV=1`. L'application password dell'utente `maurizio` è in `C:\Users\maurizio.MAVIDA\Claude\credenzials\maurizio-blog\wordpress.md`: il file contiene un esempio `curl` con la password in chiaro, quindi la si legge in una variabile e **non si stampa mai**.

```bash
cd ~/domains/mavida.com/public_html/maurizio
WP="wp --url=https://maurizio.mavida.com"
```

### 9.0 Controlli ancora aperti della fase 2 (**per primi**)

Si fanno **prima di ogni altro passo** se la data del deploy è il **2026-10-15 o successiva**; se è ancora il 2026-10-14 si saltano e restano nella nota di consegna. Il giorno `G` è **ieri** se oggi è il 2026-10-16 o dopo, oppure **oggi** se è il 2026-10-15 (giorno parziale: vale la regola «meno di 6 ore di traffico» della tabella).

1. **9.1 della fase 2 (`REMOTE_ADDR` e scarti):** le query di [fase-2.md, 9.1](fase-2.md) con quel `G`, e la sua tabella di decisione:
   - **«proxy sospetto»:** **il deploy non procede.** Ci si ferma e si avvisa Maurizio. Con un proxy, ogni visita propria avrebbe il paese del proxy;
   - **«raccolta ferma o parziale»:** il deploy non procede e si avvisa Maurizio, come previsto dalla fase 2;
   - **«dati insufficienti», «sorgente pesante» o «ok»:** si prosegue e si riportano i numeri nella nota.
2. **9.6 della fase 2 (chiavi dei contenuti):** le due query di [fase-2.md, 9.6](fase-2.md) su `G`. Non blocca: le chiavi presenti solo in `own` vanno nella nota.
3. **9.8 della fase 2 (raddoppio):** solo se ci sono almeno 3 giorni completi dal 2026-10-15, cioè dal 2026-10-18 in poi. Si eseguono le query di [fase-2.md, 9.8](fase-2.md) con `FROM=2026-10-15` e `TO` = ieri. Non blocca: i risultati vanno nella nota per la decisione del 2026-10-28.
4. **9.10 della fase 2 (raccolta attiva dopo la 0.8.0):**

   ```bash
   $WP db query "SELECT COUNT(*) new_hits, MIN(ts), MAX(ts) FROM maurizio_wp5w_hits WHERE ts >= '2026-10-14 19:31:00'"
   $WP db query "SELECT COUNT(*) wps_visitors FROM maurizio_statistics_visitor WHERE last_counter >= '2026-10-14'"
   curl -s https://maurizio.mavida.com/ | grep -o 'id="wp5w-hit"[^<]*'
   ```

   Se `new_hits` è 0 mentre WP Statistics ha visitatori, è «raccolta ferma»: il deploy non procede e si avvisa Maurizio.

### 9.1 Backup e cattura di non regressione

```bash
mkdir -p ~/backups
STAMP=$(date +%F-%H%M)
TABLES=$($WP db tables 'maurizio_wp5w_*' --all-tables --format=csv | tr ',' ' ')
# Credenziali da wp-config.php: la password va in MYSQL_PWD e non si stampa mai.
export MYSQL_PWD="$($WP config get DB_PASSWORD)"
mysqldump -h "$($WP config get DB_HOST)" -u "$($WP config get DB_USER)" --single-transaction "$($WP config get DB_NAME)" $TABLES > ~/backups/wp5w-pre-0.9.0-$STAMP.sql
unset MYSQL_PWD
ls -l ~/backups/wp5w-pre-0.9.0-$STAMP.sql            # non vuoto
$WP option list --search='wp5w_*' --format=json > ~/backups/wp5w-options-pre-0.9.0.json
$WP plugin get wp-5w --field=version                 # 0.8.0
LOG_WP5W=$(grep -c "wp5w" ~/logs/maurizio-debug.log 2>/dev/null || echo 0); echo "$LOG_WP5W"
WPS_MAX_ID=$($WP db query "SELECT MAX(ID) FROM maurizio_statistics_visitor" --skip-column-names); echo "$WPS_MAX_ID"
{
  $WP db query "SELECT origin, COUNT(*), SUM(visitors), SUM(views) FROM maurizio_wp5w_site_daily WHERE day BETWEEN '2026-09-14' AND '2026-10-12' GROUP BY origin"
  $WP db query "SELECT origin, COUNT(*), SUM(views), SUM(visitors) FROM maurizio_wp5w_object_daily WHERE day BETWEEN '2026-09-14' AND '2026-10-12' GROUP BY origin"
  for t in entry_daily referrer_daily dim_daily; do
    $WP db query "SELECT '$t', origin, COUNT(*), SUM(visitors) FROM maurizio_wp5w_$t WHERE day BETWEEN '2026-09-14' AND '2026-10-12' GROUP BY origin"
  done
  $WP db query "SELECT dim, value, SUM(visitors) FROM maurizio_wp5w_dim_daily WHERE origin = 'wps' AND day BETWEEN '2026-09-14' AND '2026-10-12' AND dim IN ('country','device','browser','platform') GROUP BY dim, value ORDER BY dim, 3 DESC, value"
  $WP eval '$r = ( new Mavida\FiveW\Query\OverviewQuery() )->get( new Mavida\FiveW\Support\DateRange( "2026-09-14", "2026-10-12" ) ); echo $r["kpis"]["visitors"]["value"], " ", $r["kpis"]["views"]["value"], "\n";'
} > ~/backups/wp5w-nonreg-pre-0.9.0.txt
cat ~/backups/wp5w-nonreg-pre-0.9.0.txt
```

Il confronto vincolante è con questa cattura, presa subito prima dell'installazione e non a cavallo delle 4:00. Lo snapshot di riferimento della fase 1 (`scratchpad/snapshot-pre-fase-1.txt`: `site_daily` 29 righe, 3.532 visitatori e 4.659 visualizzazioni; `object_daily` 576 righe; `entry_daily` 371; `referrer_daily` 186; `dim_daily` 1.110) resta solo un riferimento, perché l'ETL notturno rielabora gli ultimi 7 giorni.

### 9.2 Installazione

```bash
INSTALL_UTC=$(date -u '+%F %T'); echo "$INSTALL_UTC"
$WP plugin install https://github.com/mavidasnc/wp-5w/releases/download/v0.9.0/wp-5w-0.9.0.zip --force
$WP plugin get wp-5w --field=version                  # 0.9.0
$WP option get wp5w_db_version                         # 4 (nessuna migrazione)
grep -c "wp5w" ~/logs/maurizio-debug.log 2>/dev/null || echo 0   # uguale a $LOG_WP5W
$WP cron event list --fields=hook,next_run_relative,recurrence | grep wp5w   # wp5w_etl_daily, wp5w_own_hourly e wp5w_geo_update (1 day)
```

Se `wp5w_geo_update` non compare subito, lo pianifica la prima richiesta a WordPress (`Scheduler::register()`); si ripete il comando dopo una visita qualsiasi.

### 9.3 Libreria MaxMind

```bash
$WP eval 'echo ( new ReflectionClass( "MaxMind\\Db\\Reader" ) )->getFileName(), "\n";'
# atteso: .../public/plugins/wp-5w/vendor/maxmind-db/reader/src/MaxMind/Db/Reader.php
$WP eval 'echo class_exists( "WP_Statistics\\Dependencies\\MaxMind\\Db\\Reader" ) ? "wps ok" : "wps assente", "\n";'   # wps ok
ls public/plugins/wp-5w/vendor/maxmind-db/reader            # niente ext/
```

Se la classe arriva da un altro plugin si scrive nella nota (F2), ma non blocca, purché 9.4 funzioni.

### 9.4 Primo download del database dei paesi

```bash
$WP cron event run wp5w_geo_update
$WP option get wp5w_geo --format=json                 # month = mese corrente (o precedente nelle prime ore del 1°), error vuoto
ls -la public/uploads/wp5w/                           # dbip-country-lite.mmdb (circa 8 MB), .htaccess, index.php, nessun .tmp
$WP eval 'echo Mavida\FiveW\Geo\CountryDatabase::lookup( "8.8.8.8" ), " ", Mavida\FiveW\Geo\CountryDatabase::lookup( "151.38.1.1" ), "\n";'   # US IT
curl -s -o /dev/null -w "%{http_code}\n" https://maurizio.mavida.com/public/uploads/wp5w/dbip-country-lite.mmdb   # 403
curl -s -o /dev/null -w "%{http_code}\n" https://maurizio.mavida.com/public/uploads/wp5w/                          # 403 o 200 con corpo vuoto
```

Se il download fallisce, l'errore è in `wp5w_geo`. Si riprova una volta. Se fallisce ancora, si va al rollback (9.13): senza database il gate di 9.8 non misurerebbe la ricerca del paese. Se `.mmdb` risponde `200`, LiteSpeed non applica `.htaccess`: si avvisa Maurizio (non blocca, perché i dati di DB-IP Lite sono pubblici).

### 9.5 Non regressione

Si ripetono le query della cattura di 9.1 e si confrontano con `~/backups/wp5w-nonreg-pre-0.9.0.txt`. Le righe `wps` (comprese le dimensioni) e il risultato di `OverviewQuery` devono essere **identici**. Le righe `own` possono essere cambiate solo per oggi e ieri (passaggio orario). Una differenza sulle righe `wps` porta al rollback (9.13).

### 9.6 Smoke test dell'endpoint

Dal PC locale, con Git Bash (`ID` e `SIG` si ricavano come in [fase-1.md, 8.6](fase-1.md)):

```bash
UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36"
curl -s -o /dev/null -w "%{http_code}\n" -A "$UA" -d "t=post&id=$ID&s=$SIG&u=/wp5w-smoke-test/" https://maurizio.mavida.com/wp-json/wp5w/v1/hit   # 204
```

Sul blog:

```bash
$WP db query "SELECT id, ts, object_type, object_id, uri, country, device, browser, os FROM maurizio_wp5w_hits WHERE uri = '/wp5w-smoke-test/' ORDER BY id DESC LIMIT 1"
# country = paese dell'IP pubblico del PC (IT), device desktop, browser Chrome, os Windows
```

Poi, se è disponibile claude-in-chrome, si apre un articolo in una finestra anonima, si controlla `x-litespeed-cache: hit` e il beacon `POST /wp-json/wp5w/v1/hit` con risposta `204`, e la nuova riga in `wp5w_hits` ha paese, dispositivo, browser e sistema valorizzati. Il tracker non ha un tag script proprio, perché LiteSpeed combina i JS: si cerca il blocco `#wp5w-hit`.

### 9.7 Aggregazione e invariante

```bash
$WP cron event run wp5w_own_hourly
D=$(date +%F)
$WP db query "SELECT dim, SUM(visitors) FROM maurizio_wp5w_dim_daily WHERE origin = 'own' AND day = '$D' GROUP BY dim"
$WP db query "SELECT visitors FROM maurizio_wp5w_site_daily WHERE origin = 'own' AND day = '$D'"
# le sei dimensioni hanno la stessa somma, uguale ai visitatori own del giorno
$WP db query "SELECT dim, value, visitors FROM maurizio_wp5w_dim_daily WHERE origin = 'own' AND day = '$D' AND dim IN ('device','browser','platform') ORDER BY dim, visitors DESC"
# solo valori dei nomi di WP Statistics (smartphone, desktop, tablet; Chrome Mobile, Mobile Safari...; Android, iOS, Windows, Mac, GNU/Linux...) oppure (n/d)
```

Le visite di oggi registrate prima di `INSTALL_UTC` contano come `(n/d)`. Una somma diversa tra le dimensioni porta al rollback.

### 9.8 Gate dei tempi dell'endpoint (da ripetere: la fase 3 cambia il percorso di `/hit`)

Lo stesso metodo di [fase-1.md, 8.7](fase-1.md), **dopo** 9.4 (con il database dei paesi installato). Dal PC locale si fanno 7 coppie alternate: una visita valida a `/wp5w/v1/hit` con lo user agent di Chrome e `u=/wp5w-smoke-test/`, e una `POST` a vuoto a `/wp-json/wp-statistics/v2/hit`. Si confrontano le mediane di `time_total`.

- **Esito:** la mediana di `hit` non deve superare di oltre il **20%** quella di `wps`. Nella fase 1 la differenza era +9,4%.
- **Verifica che il gate abbia misurato la ricerca del paese:** `SELECT COUNT(*), SUM(country <> '') FROM maurizio_wp5w_hits WHERE uri = '/wp5w-smoke-test/'` deve dare 8 e 8 (le 7 visite del gate più quella di 9.6).
- **Se si supera il 20%:** si ripete una volta con 15 coppie, per escludere il rumore di rete. Se si supera ancora, si va al rollback (9.13) e si avvisa Maurizio.
- **Pulizia:** `DELETE FROM maurizio_wp5w_hits WHERE uri = '/wp5w-smoke-test/'`, poi `$WP cron event run wp5w_own_hourly` per riallineare gli aggregati di oggi.

### 9.9 Box «Pubblico» contro `dim_daily` (application password)

Dal PC locale, con Git Bash:

```bash
WPAUTH="maurizio:<application password>"   # letta dal file delle credenziali, mai stampata
for N in 7 30 90; do
  curl -s -u "$WPAUTH" "https://maurizio.mavida.com/wp-json/wp5w/v1/admin/audience/dimensions?days=$N" | python -c "import json,sys; d=json.load(sys.stdin); print(d['range'], d['first_day']); [print(k, v['total'], v['other'], [(r['value'], r['visitors']) for r in v['rows']]) for k, v in d['dims'].items()]"
done
curl -s -o /dev/null -w "%{http_code}\n" "https://maurizio.mavida.com/wp-json/wp5w/v1/admin/audience/dimensions?days=30"   # 401 senza credenziali
```

Sul blog, per ogni `N`, con `FROM` e `TO` **presi dal `range` della risposta** (non ricalcolati):

```bash
$WP db query "SELECT dim, SUM(visitors) FROM maurizio_wp5w_dim_daily WHERE origin = 'wps' AND day BETWEEN '$FROM' AND '$TO' AND dim IN ('country','device','browser','platform') GROUP BY dim"
$WP db query "SELECT dim, IF(value IN ('', 'UNK'), '(n/d)', value) v, SUM(visitors) n FROM maurizio_wp5w_dim_daily WHERE origin = 'wps' AND day BETWEEN '$FROM' AND '$TO' AND dim = 'country' GROUP BY dim, v ORDER BY n DESC, BINARY v LIMIT 10"
# e la stessa per device, browser e platform
```

I `total` e le prime 10 righe di ogni dimensione devono essere **identici** alla risposta. Si confronta con `dim_daily` e non con `site_daily`, perché i visitatori `wps` di `site_daily` vengono da `statistics_summary_totals`. Una differenza è un errore di `AudienceQuery`: porta al rollback.

Si controlla anche che `GET /admin/status` contenga `geo` con `available` vero e `month`, e che `/admin/compare`, `/admin/audience` e `/admin/overview?from=2026-09-14&to=2026-10-12` rispondano 200 con gli stessi totali di 9.1.

### 9.10 WP Statistics continua a funzionare

Dopo circa un'ora di traffico:

```bash
$WP db query "SELECT COUNT(*) nuovi, SUM(location IS NULL OR location IN ('', '000')) senza_paese FROM maurizio_statistics_visitor WHERE ID > $WPS_MAX_ID"
```

`nuovi` è maggiore di 0 se c'è stato traffico, e `senza_paese` resta vicino a 0: la libreria con prefisso di WP Statistics non è disturbata. Se `nuovi` è 0 mentre `wp5w_hits` ha righe nuove, si avvisa Maurizio.

### 9.11 Segnale di proxy e distribuzione delle due origini (non blocca)

Dopo circa un'ora di traffico:

```bash
$WP db query "SELECT country, COUNT(DISTINCT day, visitor) n FROM maurizio_wp5w_hits WHERE ts >= '$INSTALL_UTC' GROUP BY country ORDER BY n DESC LIMIT 5"
$WP db query "SELECT location, COUNT(*) n FROM maurizio_statistics_visitor WHERE ID > $WPS_MAX_ID GROUP BY location ORDER BY n DESC LIMIT 5"
```

Se nella raccolta propria **un solo paese supera l'80%** dei visitatori (con almeno 20 visitatori), mentre in WP Statistics lo stesso paese sta sotto il 50%, è un indizio di proxy (tutti i visitatori con il paese dello stesso indirizzo): si avvisa Maurizio, senza rollback.

Per la decisione del 2026-10-28 (al posto del Confronto per paese e dispositivo, F17), dal giorno dopo l'installazione:

```bash
FROM=<giorno dopo l'installazione>; TO=$(date -d yesterday +%F)
$WP db query "SELECT dim, value, SUM(IF(origin='wps', visitors, 0)) wps, SUM(IF(origin='own', visitors, 0)) own FROM maurizio_wp5w_dim_daily WHERE dim IN ('country','device','browser','platform') AND day BETWEEN '$FROM' AND '$TO' GROUP BY dim, value ORDER BY dim, wps DESC"
```

I risultati vanno nella nota. Paesi diversi per pochi punti sono attesi, perché WP Statistics usa MaxMind GeoLite2 e la raccolta propria DB-IP.

### 9.12 Prova nel browser

Non c'è una sessione di amministratore nel browser automatico, e l'application password non apre wp-admin.

1. **In wp-env** (sempre, prima della PR): screenshot del box «Pubblico» e della card «Database dei paesi» (criterio 11).
2. **Sul blog**, solo se Maurizio ha una sessione aperta in Chrome (claude-in-chrome, senza digitare password): si aprono `https://maurizio.mavida.com/app/wp-admin/admin.php?page=wp5w&tab=audience` e `&tab=status`, si controllano il box, la card e il piede con l'attribuzione, e che la console sia senza errori. Se la sessione non c'è, si scrive «da provare da Maurizio» nella nota, senza bloccare il deploy: la REST di 9.9 copre i dati.

### 9.13 Rollback

Si fa se un passo bloccante tra 9.2 e 9.9 fallisce. La 0.9.0 non cambia lo schema, quindi basta reinstallare la 0.8.0 e togliere ciò che la 0.9.0 ha aggiunto:

```bash
$WP plugin install https://github.com/mavidasnc/wp-5w/releases/download/v0.8.0/wp-5w-0.8.0.zip --force
$WP plugin get wp-5w --field=version                  # 0.8.0
$WP option get wp5w_db_version                         # 4
$WP cron event delete wp5w_geo_update                  # evento orfano: la 0.8.0 non lo conosce
$WP option delete wp5w_geo
rm -f public/uploads/wp5w/dbip-country-lite.mmdb public/uploads/wp5w/dbip-country-lite.mmdb.tmp public/uploads/wp5w/.htaccess public/uploads/wp5w/index.php && rmdir public/uploads/wp5w
$WP cron event run wp5w_own_hourly                     # gli aggregati own di oggi e ieri tornano senza le dimensioni nuove
```

Le righe di `wp5w_hits` scritte dalla 0.9.0 hanno colonne che la 0.8.0 ignora, quindi restano. Poi si ripetono le query di 9.5. Solo se le righe `wps` risultano diverse dalla cattura, si ripristinano le tabelle dal dump:

```bash
export MYSQL_PWD="$($WP config get DB_PASSWORD)"
mysql -h "$($WP config get DB_HOST)" -u "$($WP config get DB_USER)" "$($WP config get DB_NAME)" < ~/backups/wp5w-pre-0.9.0-<STAMP>.sql
unset MYSQL_PWD
```

Questo ripristino perde le righe di `wp5w_hits` arrivate dopo il backup. Lo si scrive nella nota.

### 9.14 Raccolta ancora attiva dopo l'installazione

Circa un'ora dopo 9.2:

```bash
$WP db query "SELECT COUNT(*) new_hits, SUM(country <> '') con_paese, SUM(device <> '') con_device FROM maurizio_wp5w_hits WHERE ts >= '$INSTALL_UTC'"
curl -s https://maurizio.mavida.com/ | grep -o 'id="wp5w-hit"[^<]*'
```

`new_hits` è maggiore di 0 se c'è stato traffico, e `con_paese` e `con_device` sono quasi uguali a `new_hits` (le differenze sono IP o user agent non riconosciuti). Se `new_hits` è 0 mentre WP Statistics ha registrato visitatori (9.10), si avvisa Maurizio.

### 9.15 Nota di consegna

La nota riporta:

- data e ora di installazione (`INSTALL_UTC`);
- gli esiti di 9.0, con i numeri (o «saltati, ancora da fare» se il deploy è avvenuto il 2026-10-14);
- l'esito di 9.3 (da quale file arriva `MaxMind\Db\Reader`) e di 9.4 (`wp5w_geo`, risposta `403`);
- l'esito di 9.5, 9.7 e 9.9;
- le due mediane del gate (9.8) e la differenza percentuale;
- gli esiti di 9.10, 9.11 e 9.14, e quello della prova nel browser (9.12);
- gli eventuali scostamenti da questa specifica;
- il promemoria della decisione del 2026-10-28, con le query di 9.11 e di 9.8 della fase 2 da rieseguire.

---

## 10. Fuori da questa fase

- Confronto per paese, dispositivo e browser nella sezione Confronto (F17): dopo la decisione del 2026-10-28, se servirà ancora.
- Finding minori della review della fase 2 (canale `(n/d)` contro `unassigned` nel Confronto, tipi `tax_*` in `CompareQuery`, `phpcs:disable` più largo, testi fissi in `Compare.jsx`): candidati a una 0.8.1 o alla fase 4, non qui.
- Città e regione, mappa dei paesi (fase 6), riconoscimento di altri browser e dispositivi (TV, console, `phablet`).
- Pulsante «aggiorna ora» del database dei paesi e scelta della sorgente (DB-IP o MaxMind con licenza) dalle Opzioni.
- Ottimizzazione dell'endpoint con `SHORTINIT` (rimandata dagli scostamenti della roadmap).
- Impostazione di `wp5w_own_since`, testo per la privacy policy (che nominerà DB-IP) e pulizia di `wp5w_hits`: fase 5.
