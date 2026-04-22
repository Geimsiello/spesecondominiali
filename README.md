# Spese Condominiali Portal (Full PHP)

Portale SB Admin 2 pubblicabile su hosting PHP senza Node.js.

## Stack

- Frontend: `index.php` (template SB Admin 2 via CDN)
- Backend: `api.php` (PHP puro con sessioni)
- Database: MySQL remoto (OVH)

## Struttura progetto (codice pulito)

- `assets/css/`: stili condivisi
- `assets/js/`: funzioni API comuni + script specifici per pagina
- `includes/`: helper PHP riutilizzabili (auth/layout)
- pagine separate: `login.php`, `dashboard.php`, `upload.php`, `review.php`, `ai-settings.php`
- archivio documenti: `documents.php` (listing catalogato per tipologia)

## Configurazione database

Modifica il file `config.php` e inserisci la password

## Pubblicazione

1. Carica tutti i file sul dominio.
2. Verifica che PHP abbia estensioni `pdo` e `pdo_mysql`.
3. Apri il dominio su `login.php` (oppure su root, che reindirizza a `dashboard.php`).
4. Dopo login si entra nel portale protetto con pagine separate.
5. Al primo avvio il sistema chiede creazione admin dalla pagina login.

`.htaccess` e gia incluso per fallback a `index.php`.

## Primo avvio

Se il DB non contiene utenti, compare la schermata di setup admin con:

- nome completo
- email
- password (minimo 8 caratteri)

Completato il setup, puoi effettuare login dalla pagina dedicata `login.php`.

Nel form di login sono attivi gli attributi autocomplete consigliati per i password manager (`username` e `current-password`) e l'opzione `Ricordami`, che mantiene la sessione utente fino a 30 giorni.

## Pagine portale (SB Admin 2)

- `dashboard.php`
- `upload.php`
- `review.php`
- `ai-settings.php`
- `documents.php`
- `extraction-review.php`
- `extraction-debug.php`

Ogni voce menu ha una pagina dedicata (non tab nella stessa pagina).
Durante le operazioni lunghe di analisi AI (upload e rianalisi da archivio) viene mostrato un loader flottante globale con stato "Analisi in corso".

## Endpoint API (via `api.php?action=...`)

- `setup_status`
- `error_codes`
- `setup_admin` (POST)
- `login` (POST)
- `logout` (POST)
- `me`
- `upload` (POST, PDF)
- `review_items`
- `review_update` (POST)
- `documents_list`
- `documents_delete` (POST)
- `documents_ai_reprocess` (POST)
- `draft_get`
- `draft_confirm` (POST)
- `draft_cancel` (POST)
- `analytics_summary`
- `ai_settings_get`
- `ai_settings_save` (POST)
- `ai_settings_test` (POST)
- `ai_models_list` (POST)

## Error codes API

Le risposte errore sono standardizzate con struttura:

`{"error":{"code":"ERROR_CODE","message":"Messaggio leggibile","meta":{...opzionale}}}`

Puoi ottenere il catalogo completo da `api.php?action=error_codes`.

## Note upload PDF

In modalità PHP pura senza dipendenze native di parsing PDF, l'upload crea una voce da revisionare.
Puoi estendere in seguito `api.php` per collegare OCR/parser esterno o pipeline AI.
I PDF caricati vengono salvati sul server e resi disponibili in archivio con categorizzazione per tipologia.
Dall'archivio documenti è possibile anche eliminare un documento (catalogo + file sul server).
Dall'archivio documenti è disponibile anche il pulsante "Rivedi con AI" per rianalizzare file gia caricati e passare dalla pagina intermedia di bozza prima del salvataggio.
Nell'archivio documenti è presente la colonna "Ultima rianalisi AI" per tracciare quando l'analisi è stata rilanciata.
All'upload viene tentata subito l'analisi AI e il risultato viene inviato in una pagina intermedia di revisione bozza prima del salvataggio definitivo.
L'estrazione AI e stata indirizzata esplicitamente ai prospetti di bilancio/consuntivo (voci tabellari con preventivo/consuntivo/differenza) e interpreta importi in formato italiano (`1.234,56`).
Se il modello non produce risultati utili, e attivo un fallback deterministico che prova a estrarre righe tabellari direttamente dal testo PDF.
Il parser PDF best effort ora privilegia i content stream (`Tj/TJ`, anche in formato esadecimale) e filtra il rumore tecnico (`xref`, `obj`, trailer) per evitare anteprime inutili.
L'estrazione delle voci privilegia ora il parser tabellare non-AI; la chiamata AI viene usata solo come fallback secondario.
Quando disponibili nel server, vengono tentati anche tool esterni non-AI (`pdftotext`, `mutool`) per ottenere testo piu pulito dal PDF.
La normalizzazione del testo preserva gli a-capo delle righe tabellari (fondamentale per il parsing multi-riga).
In upload sono attivi:

- selezione PDF da file input e drag & drop nella pagina upload
- rilevamento automatico `docType` dal nome file
- rilevamento automatico anno dal nome file
- override manuale opzionale per forzare tipologia/anno

## Schemi JSON per estrazione AI

Sono disponibili schemi dedicati in `schemas/`:

- `schemas/bilancio.json`
- `schemas/consuntivo.json`
- `schemas/fattura.json`
- `schemas/default.json` (fallback)

`api.php` seleziona automaticamente lo schema in base al `doc_type` del documento e lo include nel prompt AI.
Questo migliora coerenza dell'output e riduce i casi in cui la revisione risulta vuota.
Per i documenti `consuntivo` lo schema e ora focalizzato sui soli campi richiesti:

- categoria (macro sezione)
- descrizione (voce)
- valore preventivo
- valore consuntivo
- data (derivata dall'anno documento, default `YYYY-01-01`)

Nella revisione `consuntivo` non vengono richiesti supplier/fattura.

Per diagnosi rapida e disponibile la pagina `extraction-debug.php?id=<document_id>` (link diretto dall'archivio documenti), che mostra:

- schema JSON applicato al documento
- metadati e statistiche di testo estratto
- prime righe del testo leggibile dal PDF
- badge automatico qualita input AI (`Buona`/`Media`/`Scarsa`) con motivazione sintetica
- debug parser tabellare (match/scarti con motivazioni + anteprima voci estratte dal parser)
In `extraction-review.php` viene inoltre mostrato un box di debug sintetico del parser (con link al debug completo) per capire subito perche la bozza e vuota.
Il parser include anche un fallback euristico quando i pattern tabellari rigidi non vengono rilevati.
La preview debug mostra solo le prime 40 righe per leggibilita, ma la chiamata AI usa una porzione molto piu ampia del testo estratto (fino a 120k caratteri, con head+tail se oltre soglia).
Il debug revisione mostra anche la sorgente testo usata (`external` o `internal`) e il numero di caratteri disponibili per diagnosi immediata.
I testi estratti vengono sanitizzati in UTF-8 prima del salvataggio su DB per evitare errori MySQL su byte non validi provenienti dai PDF.
Per il `consuntivo` e presente anche un check di corretta identificazione (score + esito) nel debug revisione.

In caso di rianalisi AI non riuscita o testo non leggibile, il sistema crea comunque una bozza manuale (`draft`) invece di lasciare il documento in stato `failed`.

## Settings AI in dashboard

Nel menu SB Admin 2 e presente la sezione `Settings AI` per impostare:

- URL Ollama (es. `http://127.0.0.1:11434`)
- modello Ollama (menu a tendina con modelli disponibili)
- API key Ollama (opzionale)
- modalita contesto AI (`Compatta` o `Completa`)

I dati vengono salvati nel DB nella tabella `ai_settings`. Sono disponibili anche:

- pulsante di test connessione
- caricamento dinamico lista modelli dall'endpoint Ollama (`/api/tags`)
