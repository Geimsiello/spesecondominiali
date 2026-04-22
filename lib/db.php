<?php

declare(strict_types=1);

// Crea/riusa la connessione PDO e garantisce lo schema applicativo.
function get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/../config.php';
    $db = $config['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);

    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    ensure_schema($pdo);
    return $pdo;
}

// Migrazioni base: tabelle applicative e seed configurazione AI iniziale.
function ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id CHAR(36) PRIMARY KEY,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(190) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS documents (
            id CHAR(36) PRIMARY KEY,
            owner_id CHAR(36) NOT NULL,
            scope VARCHAR(50) NOT NULL,
            doc_type VARCHAR(50) NOT NULL,
            year INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            extraction_status VARCHAR(50) NOT NULL DEFAULT 'pending',
            confidence DECIMAL(5,4) NOT NULL DEFAULT 0,
            needs_review TINYINT(1) NOT NULL DEFAULT 0,
            last_ai_reprocess_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_docs_owner (owner_id),
            CONSTRAINT fk_docs_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    $docColumnStmt = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'documents'
          AND column_name = 'last_ai_reprocess_at'
    ");
    $docColumnExists = (int)$docColumnStmt->fetchColumn() > 0;
    if (!$docColumnExists) {
        $pdo->exec("ALTER TABLE documents ADD COLUMN last_ai_reprocess_at TIMESTAMP NULL DEFAULT NULL");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS expenses (
            id CHAR(36) PRIMARY KEY,
            document_id CHAR(36) NOT NULL,
            owner_id CHAR(36) NOT NULL,
            scope VARCHAR(50) NOT NULL,
            year INT NOT NULL,
            category VARCHAR(190) NOT NULL,
            supplier VARCHAR(190) NULL,
            description TEXT NULL,
            budget_amount DECIMAL(12,2) NULL,
            amount DECIMAL(12,2) NOT NULL,
            invoice_number VARCHAR(100) NULL,
            expense_date DATE NULL,
            confidence DECIMAL(5,4) NOT NULL DEFAULT 0,
            needs_review TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_expenses_owner (owner_id),
            INDEX idx_expenses_year (year),
            CONSTRAINT fk_expenses_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
            CONSTRAINT fk_expenses_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS extraction_drafts (
            id CHAR(36) PRIMARY KEY,
            document_id CHAR(36) NOT NULL,
            owner_id CHAR(36) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_drafts_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
            CONSTRAINT fk_drafts_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS extraction_draft_items (
            id CHAR(36) PRIMARY KEY,
            draft_id CHAR(36) NOT NULL,
            category VARCHAR(190) NOT NULL,
            supplier VARCHAR(190) NULL,
            description TEXT NULL,
            budget_amount DECIMAL(12,2) NULL,
            amount DECIMAL(12,2) NOT NULL,
            invoice_number VARCHAR(100) NULL,
            expense_date DATE NULL,
            confidence DECIMAL(5,4) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_draft_items_draft FOREIGN KEY (draft_id) REFERENCES extraction_drafts(id) ON DELETE CASCADE
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ai_settings (
            id TINYINT PRIMARY KEY,
            ollama_url VARCHAR(255) NOT NULL,
            ollama_model VARCHAR(120) NOT NULL,
            ollama_api_key VARCHAR(255) NULL,
            ai_context_mode VARCHAR(20) NOT NULL DEFAULT 'compact',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );
    ");

    $columnStmt = $pdo->query("
        SELECT COUNT(*) 
        FROM information_schema.columns 
        WHERE table_schema = DATABASE() 
          AND table_name = 'ai_settings' 
          AND column_name = 'ollama_api_key'
    ");
    $columnExists = (int)$columnStmt->fetchColumn() > 0;
    if (!$columnExists) {
        $pdo->exec("ALTER TABLE ai_settings ADD COLUMN ollama_api_key VARCHAR(255) NULL");
    }

    $modeColumnStmt = $pdo->query("
        SELECT COUNT(*) 
        FROM information_schema.columns 
        WHERE table_schema = DATABASE() 
          AND table_name = 'ai_settings' 
          AND column_name = 'ai_context_mode'
    ");
    $modeColumnExists = (int)$modeColumnStmt->fetchColumn() > 0;
    if (!$modeColumnExists) {
        $pdo->exec("ALTER TABLE ai_settings ADD COLUMN ai_context_mode VARCHAR(20) NOT NULL DEFAULT 'compact'");
    }

    $expenseBudgetStmt = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'expenses'
          AND column_name = 'budget_amount'
    ");
    $expenseBudgetExists = (int)$expenseBudgetStmt->fetchColumn() > 0;
    if (!$expenseBudgetExists) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN budget_amount DECIMAL(12,2) NULL AFTER description");
    }

    $draftBudgetStmt = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'extraction_draft_items'
          AND column_name = 'budget_amount'
    ");
    $draftBudgetExists = (int)$draftBudgetStmt->fetchColumn() > 0;
    if (!$draftBudgetExists) {
        $pdo->exec("ALTER TABLE extraction_draft_items ADD COLUMN budget_amount DECIMAL(12,2) NULL AFTER description");
    }

    $count = (int)$pdo->query('SELECT COUNT(*) FROM ai_settings')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO ai_settings (id, ollama_url, ollama_model, ollama_api_key, ai_context_mode) VALUES (1, ?, ?, ?, ?)');
        $stmt->execute(['http://127.0.0.1:11434', 'llama3.1', null, 'compact']);
    }
}

// UUID v4 usato come chiave primaria per entita applicative.
function uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
