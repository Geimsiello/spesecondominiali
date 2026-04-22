import axios from "axios";

const OLLAMA_URL = process.env.OLLAMA_URL || "http://127.0.0.1:11434/api/generate";
const OLLAMA_MODEL = process.env.OLLAMA_MODEL || "llama3.1";

export async function extractWithOllama(rawText) {
  const prompt = `
Sei un estrattore dati per spese condominiali.
Restituisci SOLO JSON valido con questo schema:
{
  "summary": {
    "year": 2025,
    "docType": "bilancio|consuntivo|fattura",
    "scope": "condominiale|personale",
    "confidence": 0.0
  },
  "items": [
    {
      "category": "string",
      "supplier": "string",
      "description": "string",
      "amount": 0.0,
      "invoiceNumber": "string",
      "expenseDate": "YYYY-MM-DD",
      "confidence": 0.0
    }
  ]
}
Se non sai un campo, usa null o valore prudente. Testo PDF:
${rawText.slice(0, 12000)}
`;

  try {
    const response = await axios.post(OLLAMA_URL, {
      model: OLLAMA_MODEL,
      prompt,
      stream: false,
      format: "json"
    });
    const payload = response.data?.response || "{}";
    return JSON.parse(payload);
  } catch {
    return null;
  }
}
