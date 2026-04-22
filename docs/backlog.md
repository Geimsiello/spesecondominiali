# Backlog user stories e task

## Blocco 1 - Spese condominiali

### User stories

1. [DONE] Come utente autenticato voglio vedere KPI annuali condominiali. (Issue `#5`)
2. [IN PROGRESS] Come utente voglio caricare PDF bilanci/consuntivi/fatture con estrazione strutturata reale. (Issue `#3`)
3. [DONE] Come utente voglio correggere estrazioni con bassa confidenza. (Issue `#4`)
4. [DONE] Come utente voglio accedere tramite login page separata con accesso protetto al portale. (Issue `#2`)
5. [DONE] Come admin voglio configurare endpoint/modello/API key AI e testare la connessione. (implementato, da aprire issue dedicata se vuoi tracciamento formale)

### Task tecnici

- [DONE] API login e protezione route.
- [DONE] Upload PDF con metadata `scope`, `docType`, `year`.
- [IN PROGRESS] Estrazione Ollama end-to-end del contenuto PDF (attualmente placeholder review).
- [DONE] Persistenza `documents` + `expenses`.
- [DONE] Dashboard con filtri anno/scope e riepiloghi.
- [DONE] Schermata review con update voce.
- [DONE] Settings AI con test connessione e lista modelli dinamica.
- [TODO] Hardening sicurezza auth (CSRF/rate-limit/login policy).

### Stato Epic

- Epic Blocco 1 (`#1`): **IN PROGRESS**
- US completate: `#2`, `#4`, `#5`
- US aperta: `#3`

## Blocco 2 - Spese personali

- Epic `#6`: **OPEN**
- Riutilizzo pipeline PDF con categorie personali.
- Dashboard dedicata personale.
- Insight mensili e annuali.

## Blocco 3 - Analisi trasversali

- Epic `#7`: **OPEN**
- Confronto condominiale vs personale.
- KPI scostamento anno su anno.
- Top fornitori e top categorie aggregate.

## Tracciamento GitHub Project

Inserire ogni user story come issue, con task tecnici come sotto-task, e collegare al Project 3 in colonne:

- Todo
- In Progress
- In Review
- Done
