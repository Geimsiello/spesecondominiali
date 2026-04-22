<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/lib/db.php';

// Legge il body JSON delle richieste API.
function json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

// Standardizza la risposta JSON e termina la richiesta.
function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Verifica sessione utente per endpoint protetti.
function require_auth(): string
{
    if (empty($_SESSION['user_id'])) {
        respond(['error' => 'Autenticazione richiesta'], 401);
    }
    return (string)$_SESSION['user_id'];
}

// Setup iniziale richiesto finche non esiste almeno un utente.
function setup_required(PDO $pdo): bool
{
    return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
}

// Test rapido raggiungibilita endpoint Ollama.
function call_ollama_test(string $baseUrl, ?string $apiKey): array
{
    $url = rtrim($baseUrl, '/') . '/api/tags';
    $headers = ['Accept: application/json'];
    if (!empty($apiKey)) {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            return ['ok' => false, 'message' => 'Errore connessione: ' . $error];
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['ok' => true, 'message' => 'Connessione riuscita'];
        }
        return ['ok' => false, 'message' => 'Risposta HTTP non valida: ' . $httpCode];
    }

    return ['ok' => false, 'message' => 'Estensione cURL non disponibile sul server'];
}

// Recupera e normalizza i modelli disponibili da Ollama.
function fetch_ollama_models(string $baseUrl, ?string $apiKey): array
{
    $url = rtrim($baseUrl, '/') . '/api/tags';
    $headers = ['Accept: application/json'];
    if (!empty($apiKey)) {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'Estensione cURL non disponibile sul server', 'models' => []];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'message' => 'Errore connessione: ' . $error, 'models' => []];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'message' => 'Risposta HTTP non valida: ' . $httpCode, 'models' => []];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !isset($decoded['models']) || !is_array($decoded['models'])) {
        return ['ok' => false, 'message' => 'Formato risposta modelli non valido', 'models' => []];
    }

    $models = [];
    foreach ($decoded['models'] as $model) {
        $name = trim((string)($model['name'] ?? ''));
        if ($name !== '') {
            $models[] = $name;
        }
    }
    $models = array_values(array_unique($models));
    sort($models);

    return ['ok' => true, 'message' => 'Modelli caricati', 'models' => $models];
}

$action = $_GET['action'] ?? '';
$pdo = get_pdo();

try {
    // Stato setup iniziale (wizard creazione admin).
    if ($action === 'setup_status') {
        respond(['setupRequired' => setup_required($pdo)]);
    }

    // Creazione primo amministratore.
    if ($action === 'setup_admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!setup_required($pdo)) {
            respond(['error' => 'Setup gia completato'], 409);
        }
        $body = json_input();
        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $fullName = trim((string)($body['fullName'] ?? ''));
        if ($email === '' || $password === '' || $fullName === '') {
            respond(['error' => 'Dati admin incompleti'], 400);
        }
        if (strlen($password) < 8) {
            respond(['error' => 'Password minima 8 caratteri'], 400);
        }
        $stmt = $pdo->prepare('INSERT INTO users (id, email, password_hash, full_name) VALUES (?, ?, ?, ?)');
        $stmt->execute([uuid_v4(), $email, password_hash($password, PASSWORD_DEFAULT), $fullName]);
        respond(['message' => 'Amministratore creato']);
    }

    // Login con sessione PHP.
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (setup_required($pdo)) {
            respond(['error' => 'Completa prima il setup iniziale'], 403);
        }
        $body = json_input();
        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            respond(['error' => 'Credenziali non valide'], 401);
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        respond(['user' => ['id' => $user['id'], 'name' => $user['full_name'], 'email' => $user['email']]]);
    }

    // Logout e invalidazione sessione.
    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        session_destroy();
        respond(['message' => 'Logout effettuato']);
    }

    // Profilo utente corrente.
    if ($action === 'me') {
        $userId = require_auth();
        respond(['user' => ['id' => $userId, 'name' => $_SESSION['user_name'] ?? 'Utente']]);
    }

    // Upload PDF e registrazione documento/spesa placeholder.
    if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = require_auth();
        if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            respond(['error' => 'PDF mancante'], 400);
        }
        $config = require __DIR__ . '/config.php';
        $uploadDir = $config['app']['upload_dir'];
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }
        $original = basename((string)$_FILES['pdf']['name']);
        $targetName = time() . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);
        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetName;
        if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $targetPath)) {
            respond(['error' => 'Upload fallito'], 500);
        }

        $scope = (string)($_POST['scope'] ?? 'condominiale');
        $docType = (string)($_POST['docType'] ?? 'fattura');
        $year = (int)($_POST['year'] ?? date('Y'));

        $docId = uuid_v4();
        $stmt = $pdo->prepare("
            INSERT INTO documents (id, owner_id, scope, doc_type, year, file_name, file_path, extraction_status, confidence, needs_review)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'done', 0.30, 1)
        ");
        $stmt->execute([$docId, $userId, $scope, $docType, $year, $original, $targetPath]);

        // In ambiente PHP puro senza dipendenze native, viene creata una voce placeholder da revisionare.
        $expenseStmt = $pdo->prepare("
            INSERT INTO expenses (id, document_id, owner_id, scope, year, category, supplier, description, amount, invoice_number, expense_date, confidence, needs_review)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $expenseStmt->execute([
            uuid_v4(),
            $docId,
            $userId,
            $scope,
            $year,
            'Da classificare',
            null,
            'Voce creata da upload PDF, completare in revisione.',
            0,
            null,
            null,
            0.30,
            1
        ]);

        respond(['message' => 'Documento caricato. Voce in revisione creata.', 'documentId' => $docId]);
    }

    // Elenco voci da revisionare.
    if ($action === 'review_items') {
        $userId = require_auth();
        $stmt = $pdo->prepare("
            SELECT e.*, d.file_name
            FROM expenses e
            JOIN documents d ON d.id = e.document_id
            WHERE e.owner_id = ? AND e.needs_review = 1
            ORDER BY e.created_at DESC
        ");
        $stmt->execute([$userId]);
        respond(['items' => $stmt->fetchAll()]);
    }

    // Aggiornamento manuale voce in revisione.
    if ($action === 'review_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = require_auth();
        $body = json_input();
        $id = (string)($body['id'] ?? '');
        if ($id === '') {
            respond(['error' => 'ID mancante'], 400);
        }
        $stmt = $pdo->prepare("
            UPDATE expenses
            SET category = ?, supplier = ?, description = ?, amount = ?, invoice_number = ?, expense_date = ?, confidence = 1.0, needs_review = 0
            WHERE id = ? AND owner_id = ?
        ");
        $stmt->execute([
            (string)($body['category'] ?? 'Da classificare'),
            $body['supplier'] ?? null,
            $body['description'] ?? null,
            (float)($body['amount'] ?? 0),
            $body['invoiceNumber'] ?? null,
            $body['expenseDate'] ?? null,
            $id,
            $userId
        ]);
        respond(['message' => 'Voce aggiornata']);
    }

    // KPI sintetici per dashboard.
    if ($action === 'analytics_summary') {
        $userId = require_auth();
        $scope = (string)($_GET['scope'] ?? 'condominiale');
        $year = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : null;

        $params = [$userId, $scope];
        $where = 'WHERE owner_id = ? AND scope = ?';
        if ($year !== null) {
            $where .= ' AND year = ?';
            $params[] = $year;
        }

        $totalsStmt = $pdo->prepare("SELECT year, ROUND(SUM(amount),2) AS total FROM expenses $where GROUP BY year ORDER BY year");
        $totalsStmt->execute($params);
        $categoriesStmt = $pdo->prepare("SELECT category, ROUND(SUM(amount),2) AS total FROM expenses $where GROUP BY category ORDER BY total DESC LIMIT 10");
        $categoriesStmt->execute($params);
        $suppliersStmt = $pdo->prepare("SELECT COALESCE(supplier,'N/D') AS supplier, ROUND(SUM(amount),2) AS total FROM expenses $where GROUP BY supplier ORDER BY total DESC LIMIT 10");
        $suppliersStmt->execute($params);

        respond([
            'totals' => $totalsStmt->fetchAll(),
            'categories' => $categoriesStmt->fetchAll(),
            'suppliers' => $suppliersStmt->fetchAll()
        ]);
    }

    // Lettura impostazioni AI correnti.
    if ($action === 'ai_settings_get') {
        require_auth();
        $stmt = $pdo->query('SELECT ollama_url, ollama_model, ollama_api_key, updated_at FROM ai_settings WHERE id = 1');
        $row = $stmt->fetch();
        if (!$row) {
            respond([
                'ollamaUrl' => 'http://127.0.0.1:11434',
                'ollamaModel' => 'llama3.1',
                'ollamaApiKey' => '',
                'updatedAt' => null
            ]);
        }
        respond([
            'ollamaUrl' => $row['ollama_url'],
            'ollamaModel' => $row['ollama_model'],
            'ollamaApiKey' => $row['ollama_api_key'] ?? '',
            'updatedAt' => $row['updated_at']
        ]);
    }

    // Salvataggio impostazioni AI.
    if ($action === 'ai_settings_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_auth();
        $body = json_input();
        $ollamaUrl = trim((string)($body['ollamaUrl'] ?? ''));
        $ollamaModel = trim((string)($body['ollamaModel'] ?? ''));
        $ollamaApiKey = trim((string)($body['ollamaApiKey'] ?? ''));
        if ($ollamaUrl === '' || $ollamaModel === '') {
            respond(['error' => 'Compila URL e modello Ollama'], 400);
        }
        $stmt = $pdo->prepare('
            INSERT INTO ai_settings (id, ollama_url, ollama_model, ollama_api_key)
            VALUES (1, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                ollama_url = VALUES(ollama_url),
                ollama_model = VALUES(ollama_model),
                ollama_api_key = VALUES(ollama_api_key)
        ');
        $stmt->execute([$ollamaUrl, $ollamaModel, $ollamaApiKey === '' ? null : $ollamaApiKey]);
        respond(['message' => 'Impostazioni AI salvate']);
    }

    // Test connessione endpoint AI configurato.
    if ($action === 'ai_settings_test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_auth();
        $body = json_input();
        $ollamaUrl = trim((string)($body['ollamaUrl'] ?? ''));
        $ollamaApiKey = trim((string)($body['ollamaApiKey'] ?? ''));
        if ($ollamaUrl === '') {
            respond(['error' => 'URL Ollama mancante'], 400);
        }
        $test = call_ollama_test($ollamaUrl, $ollamaApiKey === '' ? null : $ollamaApiKey);
        if (!$test['ok']) {
            respond(['error' => $test['message']], 400);
        }
        respond(['message' => $test['message']]);
    }

    // Elenco modelli disponibili da endpoint Ollama.
    if ($action === 'ai_models_list' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_auth();
        $body = json_input();
        $ollamaUrl = trim((string)($body['ollamaUrl'] ?? ''));
        $ollamaApiKey = trim((string)($body['ollamaApiKey'] ?? ''));
        if ($ollamaUrl === '') {
            respond(['error' => 'URL Ollama mancante'], 400);
        }
        $result = fetch_ollama_models($ollamaUrl, $ollamaApiKey === '' ? null : $ollamaApiKey);
        if (!$result['ok']) {
            respond(['error' => $result['message']], 400);
        }
        respond(['models' => $result['models']]);
    }

    // Fallback endpoint non definito.
    respond(['error' => 'Endpoint non trovato'], 404);
} catch (Throwable $e) {
    // Gestione errori non previsti lato server.
    respond(['error' => 'Errore server: ' . $e->getMessage()], 500);
}
