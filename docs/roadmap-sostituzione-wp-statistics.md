# Roadmap: raccolta propria e sostituzione di WP Statistics

L'obiettivo è che wp-5w raccolga da solo le visite e possa sostituire WP Statistics, senza cambiare l'interfaccia. Oggi wp-5w legge le tabelle di WP Statistics e le riassume nelle tabelle `wp5w_*`. La raccolta propria scriverà negli stessi aggregati, quindi schede, trend e dettaglio continueranno a funzionare.

Documento del 2026-10-14. Le fasi si possono implementare una alla volta: ognuna si chiude con una release verificata sul blog.

## Decisioni già prese

- **Utenti collegati:** di default si escludono solo gli amministratori. Una scheda Opzioni permette di scegliere tra «escludi amministratori», «escludi tutti gli utenti collegati» e «non escludere nessuno».
- **Periodo in parallelo:** 2 settimane con WP Statistics attivo. Poi si guardano i risultati e si decide se passare alla raccolta propria o prolungare il confronto.
- **Privacy:** nessun cookie, nessun `localStorage` e nessun IP salvato. Un visitatore unico si riconosce da un hash di IP, user agent e una chiave che cambia ogni giorno, come in WP Statistics. Si rispetta il segnale Do Not Track (DNT).
- **Tracciamento via JavaScript:** è obbligatorio, perché LiteSpeed serve le pagine dalla cache e il PHP non viene eseguito (`x-litespeed-cache: hit`).
- **Database dei paesi:** il lettore usa il formato `.mmdb`, comune a DB-IP e MaxMind, quindi la sorgente si può cambiare in seguito. Si parte con DB-IP Lite Country, che si scarica senza account (vedi fase 3).

## Principio di progetto: un'origine per giorno

Per due settimane le due raccolte convivono, quindi le tabelle aggregate devono distinguere da dove arriva ogni riga:

- `site_daily`, `object_daily`, `entry_daily`, `referrer_daily` e `dim_daily` ricevono una colonna `origin`, con valore `wps` (importato da WP Statistics) oppure `own` (raccolta propria);
- l'opzione `wp5w_own_since` (una data, vuota finché non si passa) dice da quale giorno le interrogazioni usano `own`; prima di quel giorno usano `wps`;
- passare alla raccolta propria vuol dire impostare la data; tornare indietro vuol dire svuotarla. Lo storico di WP Statistics resta intatto.

Lo schema è lo stesso già usato per Search Console (`gsc_daily.origin`, con valori `rankmath` e `api`).

---

## Processo di sviluppo

Ogni fase segue lo stesso ciclo, orchestrato da un Workflow di Claude Code (uno per fase, con le fasi 1-4 in sequenza automatica).

1. **Branch separato per fase** (`fase-N-...`), creato da `main` aggiornato, con la specifica in `docs/fasi/fase-N.md`.
2. **Pull request al termine**, aperta da Sonnet con la checklist dei criteri di accettazione e l'esito dei controlli.
3. **Review di Opus 5.5**, che rilancia da sé `composer ci`, `npm run check` e `verify.php` e pubblica il verdetto come commento della PR (`approved` oppure `changes_requested`, con la gravità di ogni finding).
4. **Esito:**
   - approvata, senza finding bloccanti o importanti e con la CI verde: squash merge, release, deploy sul blog con smoke test e passaggio automatico alla fase successiva;
   - modifiche richieste: le fa Sonnet partendo dal commento di review, poi nuova review. Al massimo 3 giri, poi il lavoro si ferma e si avvisa Maurizio, senza deploy.
5. **Deploy sul blog** con backup delle tabelle `wp5w_*`, installazione dello zip della release, upgrade dello schema da WP-CLI, verifica delle chiavi, smoke test e rollback automatico se qualcosa fallisce.
6. **Comunicazione tra le sessioni:** specifica nel repo, commenti della PR e nota di consegna (`handoff`) passata alla fase successiva.

### Scostamenti rispetto al testo delle fasi (decisi il 2026-10-14)

- **Tempo dell'endpoint:** sul blog ogni `POST` REST costa circa 0,41-0,45 s (rete e TLS circa 0,19 s, bootstrap di WordPress il resto), quindi il limite di 50 ms non è realistico. Il gate è relativo: la mediana di `/wp5w/v1/hit` non deve superare di oltre il 20% quella di una `POST` a vuoto all'endpoint di WP Statistics (`GET /wp-json/` non va bene come riferimento, perché LiteSpeed la serve dalla cache). Ottimizzazione con `SHORTINIT`: valutata il 2026-10-14, rimandata finché il traffico non la giustifica.
- **Prova della cache LiteSpeed:** si fa sul blog dopo il deploy (pagina con `x-litespeed-cache: hit`, beacon `204` da Chrome, riga in `wp5w_hits`), perché wp-env gira su Apache.
- **Regola dell'origine nelle query:** entra già nella fase 1, in tutte le classi `Query`, altrimenti le righe `own` raddoppierebbero i numeri. Con `wp5w_own_since` vuota le query leggono solo `wps`.
- **Migrazione dello schema alla versione 4:** `ALTER TABLE` esplicito e idempotente sulle chiavi primarie (`dbDelta` non le cambia), con lock contro le esecuzioni concorrenti.
- **Aggregazione oraria:** lock separato da quello dell'ETL.
- **Privacy nella fase 4:** ricerche interne e pagine 404 solo come aggregati giornalieri, senza legame con il visitatore.
- **Bot segnalati, non scartati (deciso il 2026-10-15, versione 0.11.0):** i bot dichiarati, gli user agent vuoti o troppo corti e i browser con `navigator.webdriver` non si scartano più. La visita si registra e la colonna `wp5w_hits.bot` dice perché è sospetta (`webdriver`, `ua-corto` o la firma del bot), senza salvare IP né user agent. Le visite segnalate entrano negli aggregati, così il Confronto con WP Statistics resta omogeneo; lo scarto `bot` resta solo nei dati storici. Do Not Track, firma, IP esclusi, utenti collegati e limite di frequenza non cambiano. Dettagli in [fasi/fase-extra1.md](fasi/fase-extra1.md).

---

## Fase 1: raccolta propria e scheda Opzioni (dimensione L)

**Obiettivo:** registrare visite e visitatori in modo indipendente, senza ancora mostrarli nell'interfaccia.

### Tracker
- **Script `assets/tracker/tracker.js`**, sotto 1,5 KB e senza dipendenze, caricato nel frontend in `defer`.
  - Invia la visita con `navigator.sendBeacon`, oppure con `fetch` e `keepalive` come ripiego.
  - Non parte se DNT è attivo, se la pagina è un'anteprima o il Customizer, o se l'utente è escluso dalle Opzioni.
  - Dati inviati: tipo e ID del contenuto, URI, referrer, parametri UTM dell'URL e una firma.
- **Firma del contenuto.** Il PHP calcola una firma HMAC di tipo e ID del contenuto con `wp_salt()` e la stampa nella pagina; il server rifiuta le visite con firma errata. La firma resta valida anche nelle pagine in cache, perché non scade: per lo stesso motivo non si usa un nonce, che invece scadrebbe.
- **Utenti collegati.** Con la cache, il PHP non sa sempre chi sta guardando. Il controllo si fa sull'endpoint: è una richiesta REST con il cookie di login, quindi `is_user_logged_in()` e il ruolo sono disponibili. Il tracker non esegue nessun controllo lato client.

### Endpoint e tabella grezza
- **Rotta pubblica `POST /wp5w/v1/hit`**: deve rispondere con `204` ed essere molto leggera, perché viene chiamata a ogni visita.
- **Tabella `wp5w_hits`**: `id`, `ts`, `day`, `visitor` (hash binario di 16 byte), `object_type`, `object_id`, `uri`, `referrer_host`, `channel`, `source`, `utm_source`, `utm_medium`, `utm_campaign`, `is_entry`, `country`, `device`, `browser`, `os`.
  - Nella fase 1 `country`, `device`, `browser` e `os` restano vuoti.
- **Hash del visitatore:** `md5( ip . user_agent . chiave_del_giorno )`.
  - La chiave del giorno si rigenera a mezzanotte (opzione `wp5w_daily_salt`) e quella vecchia si cancella.
  - In questo modo nemmeno il plugin può collegare le visite di giorni diversi.
- **Ingresso:** è la prima visita del visitatore nel giorno, oppure una visita con un referrer esterno.

### Esclusioni e bot
- **User agent di bot e crawler:** classe pura `Support\BotFilter` con un elenco di firme e test PHPUnit. Si escludono anche gli user agent vuoti o troppo corti.
- **Browser automatici:** il tracker non invia la visita se `navigator.webdriver` è vero.
- **Limite di frequenza:** oltre N visite al minuto dallo stesso hash, le successive si scartano (contatore in un transient).
- **IP esclusi:** elenco nelle Opzioni, che accetta anche intervalli CIDR.
- **Contatori degli scarti per motivo** (bot, utente collegato, DNT, frequenza, firma), salvati per giorno: servono nel confronto della fase 2.

### Aggregazione
- Il job notturno esistente, più un passaggio orario sul giorno in corso, trasforma `wp5w_hits` nelle righe `origin = 'own'` delle tabelle `wp5w_*`.
- Le regole di conteggio sono quelle già usate per WP Statistics: un visitatore conta una volta per contenuto e per giorno.
- Le classificazioni di canale e sorgente vanno in una classe pura `Support\Channels`, riusata in fase 4.

### Scheda Opzioni (nuova)
- **Raccolta propria:** attiva o disattiva (di default attiva).
- **Utenti collegati:** escludi amministratori (predefinito), escludi tutti gli utenti collegati, non escludere nessuno.
- **IP esclusi**, uno per riga.
- **Rispetta Do Not Track:** attivo per default.
- **Conservazione delle visite grezze:** in giorni, 30 per default; gli aggregati restano per sempre.
- **Origine dei dati:** data di passaggio alla raccolta propria (`wp5w_own_since`). Il campo resta vuoto fino alla fase 5.
- Le opzioni si salvano con `register_setting` e passano dalla REST (`/admin/settings`), con lo stesso schema delle altre rotte.

### Schema e test
- Lo schema del database passa alla versione 4: tabella `wp5w_hits` e colonna `origin` nelle cinque tabelle aggregate (valore predefinito `wps`, quindi i dati esistenti non cambiano).
- L'ETL di WP Statistics cancella e riscrive solo le righe `wps`, come fa già Rank Math con `gsc_daily`.
- PHPUnit per `BotFilter`, `Channels`, l'hash del visitatore e la regola degli utenti esclusi.
- `verify.php`: si simulano visite all'endpoint (valide, di bot, con firma errata, da amministratore) e si controllano scarti e aggregati.

### Verifica
- In wp-env con LiteSpeed attivo, una pagina servita dalla cache registra comunque la visita.
- Una visita da amministratore viene scartata e conteggiata tra gli scarti; con l'opzione «non escludere nessuno» viene registrata.
- Il tempo di risposta dell'endpoint sul blog resta sotto i 50 ms.

## Fase 2: confronto in parallelo per 2 settimane (dimensione S)

**Obiettivo:** capire se i numeri della raccolta propria sono affidabili prima di spegnere WP Statistics.

- **Sezione «Confronto» nella scheda Stato.** Per ogni giorno mostra visitatori e visualizzazioni di WP Statistics e della raccolta propria, con la differenza percentuale. Sotto ci sono gli scarti per motivo e i 10 contenuti con la differenza più grande.
- **Cosa aspettarsi:** la raccolta propria conterà un po' meno, perché filtra bot e amministratori. Una differenza stabile entro il 10-15% è normale; picchi isolati vanno spiegati.
- **Il raddoppio dei visitatori di ottobre:** si verifica se si tratta di bot, confrontando gli user agent scartati con quelli contati da WP Statistics. Il risultato influisce anche sul trend.
- **Al termine (circa 2 settimane dopo il rilascio della fase 1):** decisione insieme sui dati. Si passa alla fase 5, si correggono i filtri o si prolunga il confronto.

## Fase 3: paese, dispositivo, browser e sistema operativo (dimensione M)

**Obiettivo:** avere le dimensioni del pubblico anche con la raccolta propria, e mostrare quelle già importate da WP Statistics, che sono in `dim_daily` ma oggi non compaiono nell'interfaccia.

- **Paese:**
  - lettore `.mmdb` con `maxmind-db/reader`, puro PHP e senza estensioni;
  - va verificato che non entri in conflitto con la copia inclusa in WP Statistics, eventualmente isolandola con un prefisso (`php-scoper` o Strauss);
  - il file sta in `wp-content/uploads/wp5w/`, protetto da `.htaccess`, e un cron lo scarica ogni mese con un controllo di integrità;
  - nella scheda Stato si vede la data del database;
  - l'attribuzione «IP Geolocation by DB-IP», con il link, è richiesta dalla licenza CC BY 4.0 e va nella pagina del plugin.
- **Dispositivo, browser e sistema:** classe pura `Support\UserAgent` con regole essenziali (desktop, mobile e tablet; i 6-8 browser principali; Windows, macOS, iOS, Android, Linux), coperta da test.
  - Si evita `matomo/device-detector`, che è preciso ma pesante per un endpoint chiamato a ogni visita.
- **Il paese si calcola nell'endpoint, l'IP no.** L'IP serve solo in quel momento e non viene mai salvato.
- **Interfaccia:** nella scheda Visitatori, un box «Pubblico» con paesi, dispositivi, browser e sistemi del periodo, con tabelle compatte e barre come in `ChannelList`. I dati vengono dalle righe `wps` o `own` secondo la regola del giorno.

## Fase 4: canali, tempo reale e dati operativi (dimensione M)

- **UTM:** campagne con sorgente e mezzo, in un box nella scheda Visitatori.
  - Gli aggregati vanno in `dim_daily`, con le dimensioni `utm_campaign` e `utm_source`.
- **Canale «AI»:** chatgpt.com, perplexity.ai, gemini.google.com, copilot.microsoft.com, claude.ai e simili, in un elenco aggiornabile in `Support\Channels`. Serve per capire il traffico dai motori AI.
- **Etichette leggibili per i referrer delle app**, per esempio `android-app://com.linkedin.android/` diventa «LinkedIn (app)».
- **Online adesso:** visitatori distinti negli ultimi 5 minuti in `wp5w_hits`, con i contenuti che stanno guardando, aggiornati ogni 30 secondi solo mentre la scheda è visibile.
- **Pagine 404:** URI e referrer delle visite a pagine inesistenti. È l'elenco dei link rotti da correggere, compresi quelli delle immagini storiche del blog.
- **Ricerche interne:** termini cercati sul sito (`?s=`) con il numero di ricerche e quante non hanno trovato risultati.
- **Visualizzazioni nell'admin:**
  - colonna «Visite (30 g)» nell'elenco di articoli e pagine;
  - il dato nella barra di amministrazione quando si guarda un contenuto;
  - entrambi con un clic che apre il modale di dettaglio.

## Fase 5: passaggio e disattivazione di WP Statistics (dimensione S)

- Si imposta `wp5w_own_since` al primo giorno completo di raccolta propria verificato: da quel giorno tutte le schede usano `own`.
- Si disattiva WP Statistics **senza eliminarlo**, così le sue tabelle restano come archivio. L'ETL di WP Statistics salta già da solo quando il plugin non è attivo.
- **Testo per la privacy policy** con `wp_add_privacy_policy_content`: cosa si raccoglie, perché non ci sono cookie, per quanto si conservano i dati grezzi e chi fornisce il database dei paesi.
- **Pulizia automatica** di `wp5w_hits` oltre i giorni di conservazione impostati.
- Dopo almeno un mese stabile, se lo vuoi, si possono eliminare WP Statistics e le sue tabelle, previo dump in `~/backups/`.

## Fase 6: opzionali, in ordine di utilità

1. **Eventi e obiettivi:** click sui link esterni e sui download, e obiettivi configurabili, per esempio una prenotazione di wp-book-a-call o un acquisto di wp-paid-attachments.
   - Ne nasce il **funnel delle paywall**: visita all'allegato, click su «acquista», pagamento (`wppa_payments`).
2. **Report via email** settimanale o mensile, con Resend o tramite n8n: trend del sito, contenuti in salita e in calo, nuove opportunità su Google.
3. **Avvisi di anomalie:** picchi o crolli rispetto alla media delle stesse giornate delle 4 settimane precedenti, oppure un trend sotto una soglia.
4. **Statistiche per categoria, tag e autore**, aggregando i dati dei contenuti.
5. **Ora del giorno:** distribuzione oraria, oppure una griglia giorno della settimana × ora.
6. **Tempo di lettura e profondità di scorrimento**, inviati quando si lascia la pagina (`visibilitychange`). Aumentano il peso dello script, quindi solo se servono davvero.
7. **Note sul grafico dell'andamento:** pubblicazioni, aggiornamenti di articoli, modifiche al sito.
8. **Mappa dei paesi.**
9. **Esportazione CSV e invio all'hub Mavida:** è il primo passo verso la SPA con più siti.

## Da non fare

- Un registro dei singoli visitatori con IP, percorso o elenco degli utenti online identificabili: è rischioso per la privacy e non serve per decidere.
- Salvare l'IP, anche solo troncato o con un hash permanente: basta l'hash giornaliero.

## Riepilogo

| Fase | Contenuto | Dimensione | Dipende da |
|---|---|---|---|
| 1 | Tracker, endpoint, esclusioni, aggregazione, Opzioni | L | — |
| 2 | Confronto per 2 settimane | S | 1 |
| 3 | Paese, dispositivi, browser e sistemi | M | 1 |
| 4 | UTM, canale AI, online adesso, 404, ricerche interne, visite nell'admin | M | 1 |
| 5 | Passaggio e disattivazione di WP Statistics | S | 2 (e di preferenza 3) |
| 6 | Opzionali | — | 5 |

Le fasi 3 e 4 si possono sviluppare mentre è in corso il confronto della fase 2.
