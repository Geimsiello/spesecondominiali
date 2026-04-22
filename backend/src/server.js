import "dotenv/config";
import express from "express";
import cors from "cors";
import path from "node:path";
import fs from "node:fs";
import multer from "multer";
import bcrypt from "bcryptjs";
import { randomUUID } from "node:crypto";
import db from "./db.js";
import { authRequired, signToken } from "./auth.js";
import { extractPdfData } from "./extractor.js";
import { dbConfig, validateDbConfig } from "./db-config.js";

const app = express();
const port = Number(process.env.PORT || 3000);
const uploadDir = path.resolve(process.cwd(), "uploads");
fs.mkdirSync(uploadDir, { recursive: true });

app.use(cors());
app.use(express.json());
app.use("/uploads", express.static(uploadDir));
app.use("/", express.static(path.resolve(process.cwd(), "frontend", "public")));

const storage = multer.diskStorage({
  destination: (_, __, cb) => cb(null, uploadDir),
  filename: (_, file, cb) => cb(null, `${Date.now()}-${file.originalname}`)
});
const upload = multer({ storage });

app.get("/api/setup/status", (_, res) => {
  const userCount = db.prepare("SELECT COUNT(*) AS count FROM users").get().count;
  res.json({ setupRequired: userCount === 0 });
});

app.post("/api/setup/admin", (req, res) => {
  const userCount = db.prepare("SELECT COUNT(*) AS count FROM users").get().count;
  if (userCount > 0) {
    return res.status(409).json({ error: "Setup gia completato" });
  }

  const { email, password, fullName } = req.body;
  if (!email || !password || !fullName) {
    return res.status(400).json({ error: "Dati admin incompleti" });
  }
  if (String(password).length < 8) {
    return res.status(400).json({ error: "La password deve avere almeno 8 caratteri" });
  }

  const existing = db.prepare("SELECT id FROM users WHERE email = ?").get(email);
  if (existing) {
    return res.status(409).json({ error: "Email gia in uso" });
  }

  db.prepare(
    "INSERT INTO users (id, email, password_hash, full_name) VALUES (?, ?, ?, ?)"
  ).run(randomUUID(), email, bcrypt.hashSync(password, 10), fullName);

  return res.status(201).json({ message: "Amministratore creato. Effettua il login." });
});

app.post("/api/auth/login", (req, res) => {
  const userCount = db.prepare("SELECT COUNT(*) AS count FROM users").get().count;
  if (userCount === 0) {
    return res.status(403).json({ error: "Setup iniziale richiesto. Crea prima l'admin." });
  }
  const { email, password } = req.body;
  const user = db.prepare("SELECT * FROM users WHERE email = ?").get(email);
  if (!user || !bcrypt.compareSync(password, user.password_hash)) {
    return res.status(401).json({ error: "Credenziali non valide" });
  }
  const token = signToken({ id: user.id, email: user.email, name: user.full_name });
  return res.json({ token, user: { id: user.id, email: user.email, name: user.full_name } });
});

app.get("/api/auth/me", authRequired, (req, res) => {
  res.json({ user: req.user });
});

app.post("/api/documents/upload", authRequired, upload.single("pdf"), async (req, res) => {
  const { scope = "condominiale", docType = "fattura", year } = req.body;
  if (!req.file) {
    return res.status(400).json({ error: "PDF mancante" });
  }
  const parsedYear = Number(year);
  if (Number.isNaN(parsedYear)) {
    return res.status(400).json({ error: "Anno non valido" });
  }

  const documentId = randomUUID();
  db.prepare(
    `INSERT INTO documents (id, owner_id, scope, doc_type, year, file_name, file_path, extraction_status)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'processing')`
  ).run(
    documentId,
    req.user.id,
    scope,
    docType,
    parsedYear,
    req.file.originalname,
    req.file.path
  );

  const buffer = fs.readFileSync(req.file.path);
  const extraction = await extractPdfData(buffer, scope, docType, parsedYear);
  const avgConfidence =
    extraction.items.length === 0
      ? extraction.summary.confidence
      : extraction.items.reduce((acc, item) => acc + item.confidence, 0) / extraction.items.length;

  const insertExpense = db.prepare(
    `INSERT INTO expenses
      (id, document_id, owner_id, scope, year, category, supplier, description, amount, invoice_number, expense_date, confidence, needs_review)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`
  );

  const tx = db.transaction((items) => {
    for (const item of items) {
      insertExpense.run(
        randomUUID(),
        documentId,
        req.user.id,
        scope,
        parsedYear,
        item.category,
        item.supplier ?? null,
        item.description ?? null,
        item.amount,
        item.invoiceNumber ?? null,
        item.expenseDate ?? null,
        item.confidence,
        item.confidence < 0.5 ? 1 : 0
      );
    }
  });
  tx(extraction.items);

  db.prepare(
    `UPDATE documents
     SET extraction_status = 'done', confidence = ?, needs_review = ?
     WHERE id = ?`
  ).run(avgConfidence, avgConfidence < 0.5 ? 1 : 0, documentId);

  return res.json({
    message: "Documento caricato ed elaborato",
    documentId,
    extractedItems: extraction.items.length,
    averageConfidence: avgConfidence
  });
});

app.get("/api/review/items", authRequired, (req, res) => {
  const rows = db
    .prepare(
      `SELECT e.*, d.file_name FROM expenses e
       JOIN documents d ON d.id = e.document_id
       WHERE e.owner_id = ? AND e.needs_review = 1
       ORDER BY e.created_at DESC`
    )
    .all(req.user.id);
  res.json({ items: rows });
});

app.patch("/api/review/items/:id", authRequired, (req, res) => {
  const { category, supplier, description, amount, invoiceNumber, expenseDate } = req.body;
  const existing = db
    .prepare("SELECT * FROM expenses WHERE id = ? AND owner_id = ?")
    .get(req.params.id, req.user.id);
  if (!existing) {
    return res.status(404).json({ error: "Voce non trovata" });
  }
  db.prepare(
    `UPDATE expenses
     SET category = ?, supplier = ?, description = ?, amount = ?, invoice_number = ?, expense_date = ?, confidence = 1.0, needs_review = 0
     WHERE id = ?`
  ).run(
    category ?? existing.category,
    supplier ?? existing.supplier,
    description ?? existing.description,
    amount ?? existing.amount,
    invoiceNumber ?? existing.invoice_number,
    expenseDate ?? existing.expense_date,
    req.params.id
  );
  res.json({ message: "Voce aggiornata" });
});

app.get("/api/analytics/summary", authRequired, (req, res) => {
  const { year, scope = "condominiale" } = req.query;
  const filters = [req.user.id, scope];
  let where = "WHERE owner_id = ? AND scope = ?";
  if (year) {
    where += " AND year = ?";
    filters.push(Number(year));
  }

  const totals = db
    .prepare(`SELECT year, ROUND(SUM(amount), 2) AS total FROM expenses ${where} GROUP BY year ORDER BY year`)
    .all(...filters);
  const categories = db
    .prepare(
      `SELECT category, ROUND(SUM(amount), 2) AS total
       FROM expenses ${where}
       GROUP BY category ORDER BY total DESC LIMIT 10`
    )
    .all(...filters);
  const suppliers = db
    .prepare(
      `SELECT COALESCE(supplier, 'N/D') AS supplier, ROUND(SUM(amount), 2) AS total
       FROM expenses ${where}
       GROUP BY supplier ORDER BY total DESC LIMIT 10`
    )
    .all(...filters);

  res.json({ totals, categories, suppliers });
});

app.get("*", (_, res) => {
  res.sendFile(path.resolve(process.cwd(), "frontend", "public", "index.html"));
});

app.listen(port, () => {
  const dbCheck = validateDbConfig();
  if (!dbCheck.valid) {
    console.warn(dbCheck.message);
  }
  console.log(
    `DB target configurato: ${dbConfig.user}@${dbConfig.host}:${dbConfig.port}/${dbConfig.name}`
  );
  console.log(`Server attivo su http://localhost:${port}`);
});
