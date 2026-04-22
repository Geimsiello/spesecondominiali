import pdfParse from "pdf-parse";
import { z } from "zod";
import { extractWithOllama } from "./ollama.js";

const expenseSchema = z.object({
  category: z.string().min(1),
  supplier: z.string().nullable().optional(),
  description: z.string().nullable().optional(),
  amount: z.number(),
  invoiceNumber: z.string().nullable().optional(),
  expenseDate: z.string().nullable().optional(),
  confidence: z.number().min(0).max(1).default(0.5)
});

const extractionSchema = z.object({
  summary: z.object({
    year: z.number().int(),
    docType: z.string(),
    scope: z.string(),
    confidence: z.number().min(0).max(1).default(0.5)
  }),
  items: z.array(expenseSchema)
});

function fallbackExtraction(text, scope, docType, year) {
  const amountRegex = /(\d{1,3}(?:\.\d{3})*,\d{2})/g;
  const matches = [...text.matchAll(amountRegex)];
  const items = matches.slice(0, 20).map((m, idx) => ({
    category: "Da classificare",
    supplier: null,
    description: `Voce estratta automaticamente #${idx + 1}`,
    amount: Number(m[1].replace(/\./g, "").replace(",", ".")),
    invoiceNumber: null,
    expenseDate: null,
    confidence: 0.35
  }));

  return {
    summary: { year, docType, scope, confidence: 0.35 },
    items
  };
}

export async function extractPdfData(buffer, scope, docType, year) {
  const parsed = await pdfParse(buffer);
  const text = parsed.text || "";
  const ollamaResult = await extractWithOllama(text);
  const candidate = ollamaResult ?? fallbackExtraction(text, scope, docType, year);
  const validated = extractionSchema.safeParse(candidate);
  if (validated.success) {
    return validated.data;
  }
  return fallbackExtraction(text, scope, docType, year);
}
