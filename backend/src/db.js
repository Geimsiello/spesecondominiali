import Database from "better-sqlite3";
import path from "node:path";
import fs from "node:fs";

const dataDir = path.resolve(process.cwd(), "backend", "data");
fs.mkdirSync(dataDir, { recursive: true });
const dbPath = path.join(dataDir, "spese.db");
const db = new Database(dbPath);

db.exec(`
CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  email TEXT UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,
  full_name TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS documents (
  id TEXT PRIMARY KEY,
  owner_id TEXT NOT NULL,
  scope TEXT NOT NULL,
  doc_type TEXT NOT NULL,
  year INTEGER NOT NULL,
  file_name TEXT NOT NULL,
  file_path TEXT NOT NULL,
  extraction_status TEXT NOT NULL DEFAULT 'pending',
  confidence REAL DEFAULT 0.0,
  needs_review INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(owner_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS expenses (
  id TEXT PRIMARY KEY,
  document_id TEXT NOT NULL,
  owner_id TEXT NOT NULL,
  scope TEXT NOT NULL,
  year INTEGER NOT NULL,
  category TEXT NOT NULL,
  supplier TEXT,
  description TEXT,
  amount REAL NOT NULL,
  invoice_number TEXT,
  expense_date TEXT,
  confidence REAL DEFAULT 0.0,
  needs_review INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(document_id) REFERENCES documents(id),
  FOREIGN KEY(owner_id) REFERENCES users(id)
);
`);

export default db;
