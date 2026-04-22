# Architettura portale spese

## Componenti

- `frontend/public`: interfaccia SB Admin 2 (login, upload PDF, review, analytics).
- `backend/src/server.js`: API REST + static hosting frontend.
- `backend/src/extractor.js`: parsing PDF + estrazione strutturata (Ollama o fallback regex).
- `backend/src/db.js`: schema relazionale iniziale.

## Flusso PDF -> Dati

1. Utente autenticato carica PDF.
2. Backend salva file in `uploads/` e crea record `documents`.
3. Pipeline estrae testo PDF e tenta estrazione JSON con Ollama.
4. I record normalizzati finiscono in `expenses`.
5. Se confidenza bassa, `needs_review = 1` e la voce appare in UI review.

## Estensione MySQL

Lo schema è pensato per essere migrato su MySQL mantenendo:

- PK UUID su tutte le tabelle.
- FK tra `users`, `documents`, `expenses`.
- campi `needs_review`, `confidence` per qualità estrazione.
