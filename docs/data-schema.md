# Schema dati JSON e DB

## JSON intermedio (output estrazione)

```json
{
  "summary": {
    "year": 2025,
    "docType": "fattura",
    "scope": "condominiale",
    "confidence": 0.82
  },
  "items": [
    {
      "category": "Manutenzione ascensore",
      "supplier": "Ditta Rossi SRL",
      "description": "Intervento trimestrale",
      "amount": 350.2,
      "invoiceNumber": "FAT-2025-001",
      "expenseDate": "2025-02-18",
      "confidence": 0.79
    }
  ]
}
```

## Mapping su DB relazionale

- `summary` -> tabella `documents`
  - `year` -> `documents.year`
  - `docType` -> `documents.doc_type`
  - `scope` -> `documents.scope`
  - `confidence` -> `documents.confidence`
- `items[]` -> tabella `expenses`
  - `category` -> `expenses.category`
  - `supplier` -> `expenses.supplier`
  - `description` -> `expenses.description`
  - `amount` -> `expenses.amount`
  - `invoiceNumber` -> `expenses.invoice_number`
  - `expenseDate` -> `expenses.expense_date`
  - `confidence` -> `expenses.confidence`
