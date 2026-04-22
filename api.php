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

// Catalogo codici errore API per diagnosi rapida.
function error_catalog(): array
{
    return [
        'AUTH_REQUIRED' => 'Autenticazione richiesta',
        'SETUP_ALREADY_DONE' => 'Setup gia completato',
        'SETUP_INCOMPLETE' => 'Completa prima il setup iniziale',
        'INVALID_CREDENTIALS' => 'Credenziali non valide',
        'BAD_REQUEST' => 'Richiesta non valida',
        'MISSING_DOCUMENT_ID' => 'ID documento mancante',
        'DOCUMENT_NOT_FOUND' => 'Documento non trovato',
        'PDF_UPLOAD_MISSING' => 'PDF mancante',
        'PDF_UPLOAD_FAILED' => 'Upload fallito',
        'PDF_TEXT_UNREADABLE' => 'Impossibile estrarre testo leggibile dal PDF',
        'AI_UNAVAILABLE' => 'Servizio AI non disponibile',
        'AI_OUTPUT_INVALID' => 'Output AI non compatibile',
        'DRAFT_NOT_FOUND' => 'Bozza non trovata',
        'SERVER_ERROR' => 'Errore server'
    ];
}

// Risposta errore standardizzata con codice mappato.
function respond_error(string $code, int $status = 400, ?string $overrideMessage = null, array $meta = []): void
{
    $catalog = error_catalog();
    $message = $overrideMessage ?? ($catalog[$code] ?? 'Errore non classificato');
    $payload = [
        'error' => [
            'code' => $code,
            'message' => $message
        ]
    ];
    if ($meta) {
        $payload['error']['meta'] = $meta;
    }
    respond($payload, $status);
}

// Verifica sessione utente per endpoint protetti.
function require_auth(): string
{
    if (empty($_SESSION['user_id'])) {
        respond_error('AUTH_REQUIRED', 401);
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

// Normalizza la tipologia documento usando input utente o nome file.
function detect_doc_type(string $providedType, string $fileName): string
{
    $allowed = ['bilancio', 'consuntivo', 'fattura'];
    if (in_array($providedType, $allowed, true)) {
        return $providedType;
    }
    $lowerName = mb_strtolower($fileName);
    if (str_contains($lowerName, 'consuntivo')) {
        return 'consuntivo';
    }
    if (str_contains($lowerName, 'bilancio')) {
        return 'bilancio';
    }
    if (str_contains($lowerName, 'fattura')) {
        return 'fattura';
    }
    return 'fattura';
}

// Estrae l'anno dal nome file; fallback sull'anno corrente.
function detect_year(string $providedYear, string $fileName): int
{
    $provided = (int)$providedYear;
    if ($provided >= 1900 && $provided <= 2100) {
        return $provided;
    }
    if (preg_match('/(19\d{2}|20\d{2}|21\d{2})/', $fileName, $matches) === 1) {
        $detected = (int)$matches[1];
        if ($detected >= 1900 && $detected <= 2100) {
            return $detected;
        }
    }
    return (int)date('Y');
}

// Legge impostazioni AI correnti dal database.
function get_ai_settings(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT ollama_url, ollama_model, ollama_api_key, ai_context_mode FROM ai_settings WHERE id = 1');
    $row = $stmt->fetch();
    if (!$row) {
        return [
            'ollama_url' => 'http://127.0.0.1:11434',
            'ollama_model' => 'llama3.1',
            'ollama_api_key' => null,
            'ai_context_mode' => 'compact'
        ];
    }
    if (empty($row['ai_context_mode'])) {
        $row['ai_context_mode'] = 'compact';
    }
    return $row;
}

// Carica lo schema di estrazione in base al tipo documento.
function get_extraction_schema_for_doc_type(string $docType): array
{
    $safeType = preg_replace('/[^a-z0-9_-]/i', '', strtolower($docType)) ?? '';
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'schemas';
    $filePath = $baseDir . DIRECTORY_SEPARATOR . $safeType . '.json';

    if (!is_file($filePath)) {
        $filePath = $baseDir . DIRECTORY_SEPARATOR . 'default.json';
    }
    if (!is_file($filePath)) {
        return [
            'name' => 'default',
            'description' => 'Schema base per estrazione voci spesa',
            'outputSchema' => [
                'items' => [[
                    'category' => 'string',
                    'supplier' => 'string|null',
                    'description' => 'string|null',
                    'amount' => 0.0,
                    'invoiceNumber' => 'string|null',
                    'expenseDate' => 'YYYY-MM-DD|null',
                    'confidence' => 0.0
                ]]
            ],
            'rules' => [
                'Usa amount > 0',
                'confidence tra 0 e 1',
                'Restituisci SOLO JSON valido'
            ]
        ];
    }

    $decoded = json_decode((string)file_get_contents($filePath), true);
    if (is_array($decoded) && !empty($decoded)) {
        return $decoded;
    }
    // Se schema specifico non valido, prova fallback robusto su default.
    $defaultPath = $baseDir . DIRECTORY_SEPARATOR . 'default.json';
    if (is_file($defaultPath)) {
        $defaultDecoded = json_decode((string)file_get_contents($defaultPath), true);
        if (is_array($defaultDecoded) && !empty($defaultDecoded)) {
            return $defaultDecoded;
        }
    }
    return [
        'name' => 'default',
        'description' => 'Schema base per estrazione voci spesa',
        'outputSchema' => [
            'items' => [[
                'category' => 'string',
                'supplier' => 'string|null',
                'description' => 'string|null',
                'amount' => 0.0,
                'invoiceNumber' => 'string|null',
                'expenseDate' => 'YYYY-MM-DD|null',
                'confidence' => 0.0
            ]]
        ],
        'rules' => [
            'Usa amount > 0',
            'confidence tra 0 e 1',
            'Restituisci SOLO JSON valido'
        ]
    ];
}

// Decodifica una stringa letterale PDF: gestisce escape standard e ottali.
function decode_pdf_literal_string(string $pdfLiteral): string
{
    $text = '';
    $length = strlen($pdfLiteral);
    for ($i = 0; $i < $length; $i++) {
        $ch = $pdfLiteral[$i];
        if ($ch !== '\\') {
            $text .= $ch;
            continue;
        }
        $i++;
        if ($i >= $length) {
            break;
        }
        $esc = $pdfLiteral[$i];
        if ($esc === 'n') {
            $text .= "\n";
            continue;
        }
        if ($esc === 'r') {
            $text .= "\r";
            continue;
        }
        if ($esc === 't') {
            $text .= "\t";
            continue;
        }
        if ($esc === 'b') {
            $text .= "\x08";
            continue;
        }
        if ($esc === 'f') {
            $text .= "\x0c";
            continue;
        }
        if ($esc === '(' || $esc === ')' || $esc === '\\') {
            $text .= $esc;
            continue;
        }
        if ($esc >= '0' && $esc <= '7') {
            $oct = $esc;
            $max = min($i + 2, $length - 1);
            while ($i + 1 <= $max && $pdfLiteral[$i + 1] >= '0' && $pdfLiteral[$i + 1] <= '7') {
                $i++;
                $oct .= $pdfLiteral[$i];
            }
            $text .= chr(octdec($oct) & 0xff);
            continue;
        }
        $text .= $esc;
    }
    return $text;
}

// Decodifica stringa esadecimale PDF <...> in testo UTF-8 best effort.
function decode_pdf_hex_string(string $hex): string
{
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex) ?? '';
    if ($hex === '') {
        return '';
    }
    if ((strlen($hex) % 2) !== 0) {
        $hex .= '0';
    }
    $bin = @hex2bin($hex);
    if (!is_string($bin) || $bin === '') {
        return '';
    }

    // Gestione UTF-16BE con BOM comune nei PDF.
    if (str_starts_with($bin, "\xFE\xFF")) {
        $utf16 = substr($bin, 2);
        $converted = @iconv('UTF-16BE', 'UTF-8//IGNORE', $utf16);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
    }

    // Fallback: rimuove null byte e tiene byte stampabili.
    $clean = str_replace("\x00", '', $bin);
    return trim((string)(preg_replace('/[^\x20-\x7E\xC0-\xFF]/', ' ', $clean) ?? ''));
}

// Decodifica stream PDF compressi (Flate/zlib) in best effort.
function decode_pdf_stream_content(string $stream): string
{
    if ($stream === '') {
        return '';
    }
    $candidates = [$stream];

    $u = @gzuncompress($stream);
    if (is_string($u) && $u !== '') {
        $candidates[] = $u;
    }
    $i = @gzinflate($stream);
    if (is_string($i) && $i !== '') {
        $candidates[] = $i;
    }
    if (strlen($stream) > 2) {
        $i2 = @gzinflate(substr($stream, 2));
        if (is_string($i2) && $i2 !== '') {
            $candidates[] = $i2;
        }
    }

    usort($candidates, fn($a, $b) => strlen($b) <=> strlen($a));
    return (string)$candidates[0];
}

// Esegue un comando shell e restituisce stdout/stderr/exit code.
function run_shell_command_capture(string $command): array
{
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'stdout' => '', 'stderr' => 'proc_open non disponibile', 'exitCode' => 1];
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = @proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['ok' => false, 'stdout' => '', 'stderr' => 'Impossibile avviare processo', 'exitCode' => 1];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return ['ok' => $exitCode === 0, 'stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => $exitCode];
}

// Prova estrazione testo tramite tool PDF esterni (non-AI).
function extract_pdf_text_external_tools(string $filePath): string
{
    $safe = escapeshellarg($filePath);
    $commands = [
        "pdftotext -layout -enc UTF-8 -nopgbrk $safe -",
        "mutool draw -F txt -o - $safe"
    ];
    foreach ($commands as $cmd) {
        $result = run_shell_command_capture($cmd);
        if (!$result['ok']) {
            continue;
        }
        $out = trim((string)$result['stdout']);
        if ($out !== '') {
            return $out;
        }
    }
    return '';
}

// Estrae frammenti testuali da operatori PDF Tj/TJ.
function extract_pdf_text_tokens_from_stream(string $content): array
{
    $tokens = [];

    if (preg_match_all('/\((?:\\\\.|[^\\\\])*?\)\s*Tj/s', $content, $matches) > 0) {
        foreach ($matches[0] as $m) {
            if (preg_match('/^\((.*)\)\s*Tj$/s', trim($m), $inner) === 1) {
                $decoded = decode_pdf_literal_string((string)$inner[1]);
                $decoded = trim(preg_replace('/[ \t]+/', ' ', $decoded) ?? '');
                if ($decoded !== '') {
                    $tokens[] = $decoded;
                }
            }
        }
    }

    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $arrayMatches) > 0) {
        foreach ($arrayMatches[1] as $arrayPayload) {
            if (preg_match_all('/\((?:\\\\.|[^\\\\])*?\)/s', (string)$arrayPayload, $strMatches) > 0) {
                $parts = [];
                foreach ($strMatches[0] as $part) {
                    $inner = substr($part, 1, -1);
                    $decoded = decode_pdf_literal_string((string)$inner);
                    $decoded = trim(preg_replace('/[ \t]+/', ' ', $decoded) ?? '');
                    if ($decoded !== '') {
                        $parts[] = $decoded;
                    }
                }
                $joined = trim(implode(' ', $parts));
                if ($joined !== '') {
                    $tokens[] = $joined;
                }
            }
            if (preg_match_all('/<([0-9A-Fa-f\s]+)>/s', (string)$arrayPayload, $hexMatches) > 0) {
                $parts = [];
                foreach ($hexMatches[1] as $hexPart) {
                    $decoded = decode_pdf_hex_string((string)$hexPart);
                    $decoded = trim(preg_replace('/[ \t]+/', ' ', $decoded) ?? '');
                    if ($decoded !== '') {
                        $parts[] = $decoded;
                    }
                }
                $joined = trim(implode(' ', $parts));
                if ($joined !== '') {
                    $tokens[] = $joined;
                }
            }
        }
    }

    if (preg_match_all('/<([0-9A-Fa-f\s]+)>\s*Tj/s', $content, $hexTjMatches) > 0) {
        foreach ($hexTjMatches[1] as $hexRaw) {
            $decoded = decode_pdf_hex_string((string)$hexRaw);
            $decoded = trim(preg_replace('/[ \t]+/', ' ', $decoded) ?? '');
            if ($decoded !== '') {
                $tokens[] = $decoded;
            }
        }
    }

    return $tokens;
}

// Estrazione testuale interna PDF robusta (stream + chunk raw) senza dipendenze esterne.
function extract_pdf_text_internal_best_effort(string $filePath): string
{
    if (!is_file($filePath)) {
        return '';
    }
    $raw = (string)file_get_contents($filePath);
    if ($raw === '') {
        return '';
    }
    $collected = [];

    // 1) Parser stream PDF (piu affidabile su documenti testuali reali).
    if (preg_match_all('/stream[\r\n]+(.*?)endstream/s', $raw, $streams) > 0) {
        foreach ($streams[1] as $streamRaw) {
            $decodedStream = decode_pdf_stream_content((string)$streamRaw);
            if ($decodedStream === '') {
                continue;
            }
            $tokens = extract_pdf_text_tokens_from_stream($decodedStream);
            foreach ($tokens as $token) {
                $collected[] = $token;
            }
        }
    }

    // Se abbiamo trovato testo dai content stream, usiamo solo quello (evita rumore xref/obj).
    if ($collected) {
        $collected = array_values(array_unique($collected));
        return trim(implode("\n", $collected));
    }

    // 2) Fallback chunk raw printable (utile per PDF gia testuali non compressi).
    // Soglia ridotta per mantenere anche token brevi utili (es. "0,00", codici).
    preg_match_all('/[\x20-\x7E]{2,}/', $raw, $matches);
    $chunks = $matches[0] ?? [];
    foreach ($chunks as $chunk) {
        $line = trim(preg_replace('/[ \t]+/', ' ', (string)$chunk) ?? '');
        if ($line === '') {
            continue;
        }
        if (preg_match('/^(xref|trailer|startxref|%%EOF)$/i', $line) === 1) {
            continue;
        }
        if (preg_match('/^\d+\s+\d+\s+obj$/i', $line) === 1) {
            continue;
        }
        if (preg_match('/^\d{10}\s+\d{5}\s+[nf]$/i', $line) === 1) {
            continue;
        }
        if (str_starts_with($line, '<<') || str_starts_with($line, '/')) {
            continue;
        }
        if (preg_match('/^[0-9\s]+$/', $line) === 1 && strlen($line) > 8) {
            continue;
        }
        if ($line !== '') {
            $collected[] = $line;
        }
    }

    if (!$collected) {
        return '';
    }
    $collected = array_values(array_unique($collected));
    return trim(implode("\n", $collected));
}

// Estrazione testo PDF: prima tool esterni non-AI, poi fallback interno.
function extract_pdf_text_with_diagnostics(string $filePath): array
{
    $external = '';
    $externalChars = 0;
    $internal = '';
    $internalChars = 0;

    $external = extract_pdf_text_external_tools($filePath);
    if ($external !== '') {
        $external = str_replace("\r\n", "\n", $external);
        $external = str_replace("\r", "\n", $external);
        $externalChars = mb_strlen(trim($external));
    }

    $selectedText = '';
    $selectedSource = 'none';
    if ($externalChars > 30) {
        $selectedText = $external;
        $selectedSource = 'external';
    } else {
        $internal = extract_pdf_text_internal_best_effort($filePath);
        $internalChars = mb_strlen(trim($internal));
        if ($internalChars > 0) {
            $selectedText = $internal;
            $selectedSource = 'internal';
        }
    }

    return [
        'text' => $selectedText,
        'source' => $selectedSource,
        'externalChars' => $externalChars,
        'internalChars' => $internalChars
    ];
}

// Estrazione testo PDF: prima tool esterni non-AI, poi fallback interno.
function extract_pdf_text_for_ai(string $filePath): string
{
    $diag = extract_pdf_text_with_diagnostics($filePath);
    return (string)($diag['text'] ?? '');
}

// Esegue estrazione strutturata voci spesa tramite Ollama.
function build_ai_input_text(string $text, int $maxChars = 120000, string $contextMode = 'compact'): string
{
    $normalized = str_replace("\r\n", "\n", $text);
    $normalized = str_replace("\r", "\n", $normalized);
    $normalized = trim($normalized);
    if ($normalized === '') {
        return '';
    }
    if (mb_strlen($normalized) <= $maxChars) {
        return $normalized;
    }

    if ($contextMode === 'full') {
        return mb_substr($normalized, 0, $maxChars);
    }

    // Se il testo e molto lungo, conserva testa e coda (spesso intestazioni + tabelle finali).
    $head = mb_substr($normalized, 0, (int)floor($maxChars * 0.65));
    $tail = mb_substr($normalized, -1 * (int)floor($maxChars * 0.35));
    return $head . "\n\n[...TRONCATO...]\n\n" . $tail;
}

// Esegue estrazione strutturata voci spesa tramite Ollama.
function extract_expenses_with_ai(string $text, array $documentMeta, array $aiSettings): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'Estensione cURL non disponibile sul server', 'items' => []];
    }

    $url = rtrim((string)$aiSettings['ollama_url'], '/');
    if (!str_contains($url, '/api/')) {
        $url .= '/api/generate';
    }
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if (!empty($aiSettings['ollama_api_key'])) {
        $headers[] = 'Authorization: Bearer ' . $aiSettings['ollama_api_key'];
    }

    $docType = (string)($documentMeta['doc_type'] ?? 'default');
    $schemaConfig = get_extraction_schema_for_doc_type($docType);
    $schemaJson = json_encode($schemaConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($schemaJson === false) {
        $schemaJson = '{"name":"default"}';
    }
    $contextMode = (string)($aiSettings['ai_context_mode'] ?? 'compact');
    if (!in_array($contextMode, ['compact', 'full'], true)) {
        $contextMode = 'compact';
    }
    $aiText = build_ai_input_text($text, 120000, $contextMode);
    if ($aiText === '') {
        return ['ok' => false, 'message' => 'Testo vuoto per estrazione AI', 'items' => []];
    }

    $prompt = "Sei un estrattore contabile italiano specializzato in bilanci condominiali.\n"
        . "Segui ESATTAMENTE lo schema di estrazione del tipo documento.\n"
        . "Schema attivo:\n" . $schemaJson . "\n"
        . "Regole generali:\n"
        . "1) Restituisci SOLO JSON valido con root {\"items\": [...]}.\n"
        . "2) Interpreta importi italiani con virgola decimale (es. 12.540,00 = 12540.00).\n"
        . "3) confidence tra 0 e 1.\n"
        . "4) Non inventare dati non presenti nel testo.\n"
        . "Modalita contesto AI: {$contextMode}.\n"
        . "Metadati documento: tipo={$documentMeta['doc_type']}, anno={$documentMeta['year']}, ambito={$documentMeta['scope']}.\n"
        . "Lunghezza testo ricevuto: " . mb_strlen($aiText) . " caratteri.\n"
        . "Testo:\n" . $aiText;

    $payload = json_encode([
        'model' => (string)$aiSettings['ollama_model'],
        'prompt' => $prompt,
        'stream' => false,
        'format' => 'json'
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'message' => 'Errore chiamata AI: ' . $error, 'items' => []];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'message' => 'AI non raggiungibile (HTTP ' . $httpCode . ')', 'items' => []];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !isset($decoded['response'])) {
        return ['ok' => false, 'message' => 'Risposta AI non valida', 'items' => []];
    }
    $content = json_decode((string)$decoded['response'], true);
    if (!is_array($content) || !isset($content['items']) || !is_array($content['items'])) {
        return ['ok' => false, 'message' => 'Output AI non compatibile', 'items' => []];
    }

    $items = [];
    $isConsuntivo = $docType === 'consuntivo';
    $docYear = (int)($documentMeta['year'] ?? 0);
    $defaultExpenseDate = ($docYear >= 1900 && $docYear <= 2100) ? sprintf('%04d-01-01', $docYear) : null;
    foreach ($content['items'] as $item) {
        $confidence = (float)($item['confidence'] ?? 0.5);
        if ($isConsuntivo) {
            $budget = (float)($item['budgetAmount'] ?? 0);
            $actual = (float)($item['actualAmount'] ?? 0);
            if ($actual <= 0 && $budget <= 0) {
                continue;
            }
            if ($actual <= 0) {
                $actual = $budget;
            }
            $items[] = [
                'category' => (string)($item['category'] ?? 'Da classificare'),
                'supplier' => null,
                'description' => isset($item['description']) ? (string)$item['description'] : null,
                'budget_amount' => $budget > 0 ? $budget : null,
                'amount' => $actual,
                'invoice_number' => null,
                'expense_date' => isset($item['expenseDate']) ? (string)$item['expenseDate'] : $defaultExpenseDate,
                'confidence' => max(0.0, min(1.0, $confidence))
            ];
            continue;
        }

        $amount = (float)($item['amount'] ?? 0);
        if ($amount <= 0) {
            continue;
        }
        $items[] = [
            'category' => (string)($item['category'] ?? 'Da classificare'),
            'supplier' => isset($item['supplier']) ? (string)$item['supplier'] : null,
            'description' => isset($item['description']) ? (string)$item['description'] : null,
            'budget_amount' => null,
            'amount' => $amount,
            'invoice_number' => isset($item['invoiceNumber']) ? (string)$item['invoiceNumber'] : null,
            'expense_date' => isset($item['expenseDate']) ? (string)$item['expenseDate'] : null,
            'confidence' => max(0.0, min(1.0, $confidence))
        ];
    }

    return ['ok' => true, 'message' => 'Estrazione AI completata', 'items' => $items];
}

// Converte importi ITA (12.540,00) in float (12540.00).
function parse_italian_amount(string $value): float
{
    $normalized = str_replace('.', '', trim($value));
    $normalized = str_replace(',', '.', $normalized);
    return (float)$normalized;
}

// Wrapper regex safe: se preg_* fallisce, mantiene testo originale.
function safe_preg_replace(string $pattern, string $replacement, string $subject): string
{
    $result = preg_replace($pattern, $replacement, $subject);
    return is_string($result) ? $result : $subject;
}

// Normalizza testo per persistenza DB (UTF-8 valido, no caratteri di controllo).
function sanitize_text_for_db(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $text = trim($value);
    if ($text === '') {
        return null;
    }

    // Forza UTF-8 valido scartando byte non validi.
    if (function_exists('mb_convert_encoding')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    if (function_exists('iconv')) {
        $iconv = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (is_string($iconv) && $iconv !== '') {
            $text = $iconv;
        }
    }

    // Rimuove caratteri di controllo non stampabili, preservando tab/newline.
    $text = (string)preg_replace('/[^\P{C}\t\n\r]/u', '', $text);
    $text = trim($text);
    return $text === '' ? null : $text;
}

// Comprimi parole con lettere separate da spazi (es. "C o n d o m i n i o").
function collapse_spaced_letters(string $text): string
{
    $result = preg_replace_callback(
        '/\b(?:[A-Za-zÀ-ÖØ-öø-ÿ][ \t]+){2,}[A-Za-zÀ-ÖØ-öø-ÿ]\b/',
        static function (array $m): string {
            return str_replace(' ', '', (string)$m[0]);
        },
        $text
    );
    return is_string($result) ? $result : $text;
}

// Normalizza testo PDF con spazi "artificiali" dentro parole e numeri.
function normalize_pdf_text_for_table_parsing(string $text): string
{
    $original = $text;
    $text = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $text);
    $text = collapse_spaced_letters($text);
    // Unisce cifre separate da spazi (es. "1 2 5" -> "125").
    $text = safe_preg_replace('/(?<=\d)[ \t]+(?=\d)/', '', $text);
    // Unisce cifre separate da punti/virgole con spazi (es. "125 . 406 , 00" -> "125.406,00").
    $text = safe_preg_replace('/(?<=\d)[ \t]*([.,])[ \t]*(?=\d)/', '$1', $text);
    // Collassa spazi multipli rimanenti.
    $text = safe_preg_replace('/[ \t]+/', ' ', $text);
    $trimmed = trim($text);
    if ($trimmed === '' && trim($original) !== '') {
        return $original;
    }
    return $text;
}

// Check qualità identificazione per consuntivo (categoria+descrizione+preventivo/consuntivo).
function check_consuntivo_identification(array $items): array
{
    if (!$items) {
        return ['ok' => false, 'score' => 0.0, 'message' => 'Nessuna voce identificata'];
    }
    $validRows = 0;
    $withSection = 0;
    foreach ($items as $item) {
        $hasDesc = !empty(trim((string)($item['description'] ?? '')));
        $hasAmounts = ((float)($item['amount'] ?? 0) > 0) || ((float)($item['budget_amount'] ?? 0) > 0);
        if ($hasDesc && $hasAmounts) {
            $validRows++;
        }
        if (!empty(trim((string)($item['category'] ?? '')))) {
            $withSection++;
        }
    }
    $ratio = $validRows / max(1, count($items));
    $sectionRatio = $withSection / max(1, count($items));
    $score = (($ratio * 0.7) + ($sectionRatio * 0.3));
    $ok = $score >= 0.65 && $validRows >= 4;
    return [
        'ok' => $ok,
        'score' => round($score, 4),
        'message' => $ok
            ? 'Identificazione consuntivo coerente'
            : 'Identificazione parziale: verificare mappatura categoria/descrizione/importi'
    ];
}

// Analisi parser tabellare con diagnostica dettagliata.
function analyze_table_extraction(string $text, string $docType = '', ?int $documentYear = null): array
{
    $text = normalize_pdf_text_for_table_parsing($text);
    $lines = preg_split('/\r\n|\r|\n/', $text);
    if (!is_array($lines)) {
        $lines = [];
    }
    if (count($lines) === 1 && trim((string)$lines[0]) === '') {
        $lines = [];
    }
    $items = [];
    $seenCategories = [];
    $acceptedSamples = [];
    $rejectedSamples = [];
    $stats = [
        'textChars' => mb_strlen($text),
        'linesTotal' => count($lines),
        'linesEmpty' => 0,
        'singleLineMatches' => 0,
        'multiLineMatches' => 0,
        'heuristicMatches' => 0,
        'rejectedNoPattern' => 0,
        'rejectedSkipCategory' => 0,
        'rejectedZeroAmount' => 0,
        'rejectedDuplicate' => 0
    ];
    $amountOnlyPattern = '/^-?\d{1,3}(?:\.\d{3})*,\d{2}$/';
    $skipCategories = [
        'PREVENTIVO',
        'CONSUNTIVO',
        'DIFFERENZA',
        'BILANCIO COMPARATIVO',
        'CONDOMINIO',
        'AMMINISTRATORE'
    ];
    $isConsuntivo = strtolower($docType) === 'consuntivo';
    $consuntivoDate = ($documentYear !== null && $documentYear >= 1900 && $documentYear <= 2100)
        ? sprintf('%04d-01-01', $documentYear)
        : null;
    if ($isConsuntivo) {
        $currentSection = null;
        for ($i = 0; $i < count($lines); $i++) {
            $line = trim((string)$lines[$i]);
            if ($line === '') {
                $stats['linesEmpty']++;
                continue;
            }
            if (preg_match('/^\d+\s*-\s*(.+)$/u', $line, $sec) === 1) {
                $sectionRaw = trim((string)$sec[1]);
                // Rimuove eventuali importi in coda dalla riga sezione.
                $sectionRaw = trim((string)preg_replace('/\s+-?\d{1,3}(?:\.\d{3})*,\d{2}\s+-?\d{1,3}(?:\.\d{3})*,\d{2}(?:\s+-?\d{1,3}(?:\.\d{3})*,\d{2})?\s*$/u', '', $sectionRaw));
                $currentSection = $sectionRaw !== '' ? $sectionRaw : $currentSection;
                continue;
            }
            if (preg_match($amountOnlyPattern, $line) === 1) {
                continue;
            }
            $upper = mb_strtoupper($line);
            if (in_array($upper, $skipCategories, true) || str_contains($upper, 'PREVENTIVO') || str_contains($upper, 'CONSUNTIVO') || str_contains($upper, 'DIFFERENZA')) {
                continue;
            }

            $n1 = trim((string)($lines[$i + 1] ?? ''));
            $n2 = trim((string)($lines[$i + 2] ?? ''));
            $n3 = trim((string)($lines[$i + 3] ?? ''));
            if (preg_match($amountOnlyPattern, $n1) !== 1 || preg_match($amountOnlyPattern, $n2) !== 1) {
                $stats['rejectedNoPattern']++;
                if (count($rejectedSamples) < 12) {
                    $rejectedSamples[] = ['line' => $line, 'reason' => 'consuntivo_missing_amounts'];
                }
                continue;
            }
            $description = trim((string)preg_replace('/\s+/', ' ', $line));
            // Se la riga è corta (es. "EDIFICIO", "20%"), prova a unirla con la riga precedente descrittiva.
            if ((mb_strlen($description) <= 22 || preg_match('/^\d+%$/', $description) === 1) && $i > 0) {
                $prev = trim((string)$lines[$i - 1]);
                if ($prev !== '' && preg_match($amountOnlyPattern, $prev) !== 1 && preg_match('/^\d+\s*-\s*/u', $prev) !== 1) {
                    $prevNorm = trim((string)preg_replace('/\s+/', ' ', $prev));
                    $description = trim($prevNorm . ' ' . $description);
                }
            }
            // Esclude righe di totale non utili.
            $upperDesc = mb_strtoupper($description);
            if (str_starts_with($upperDesc, 'TOTALE')) {
                $stats['rejectedSkipCategory']++;
                continue;
            }
            $category = $currentSection ?? 'Da classificare';
            $key = $category . '|' . $description;
            if (isset($seenCategories[$key])) {
                $stats['rejectedDuplicate']++;
                continue;
            }
            $seenCategories[$key] = true;
            $preventivo = parse_italian_amount($n1);
            $consuntivo = parse_italian_amount($n2);
            $amount = $consuntivo !== 0.0 ? $consuntivo : $preventivo;
            if ($amount === 0.0) {
                $stats['rejectedZeroAmount']++;
                continue;
            }
            $items[] = [
                'category' => $category,
                'supplier' => null,
                'description' => $description,
                'budget_amount' => $preventivo,
                'amount' => $amount,
                'invoice_number' => null,
                'expense_date' => $consuntivoDate,
                'confidence' => 0.82
            ];
            $stats['multiLineMatches']++;
            if (count($acceptedSamples) < 12) {
                $acceptedSamples[] = [
                    'category' => $category,
                    'description' => $description,
                    'preventivo' => $preventivo,
                    'consuntivo' => $amount,
                    'mode' => 'consuntivo_multi_line'
                ];
            }
            $i += (preg_match($amountOnlyPattern, $n3) === 1) ? 3 : 2;
        }
        $identification = check_consuntivo_identification($items);
        return [
            'items' => $items,
            'debug' => [
                'stats' => $stats,
                'acceptedSamples' => $acceptedSamples,
                'rejectedSamples' => $rejectedSamples,
                'identification' => $identification
            ]
        ];
    }

    $linePattern = '/^\s*([A-ZÀ-ÖØ-Ýa-zà-öø-ý0-9\'\.\-\/\s]{3,}?)\s+(-?\d{1,3}(?:\.\d{3})*,\d{2})\s+(-?\d{1,3}(?:\.\d{3})*,\d{2})(?:\s+(-?\d{1,3}(?:\.\d{3})*,\d{2}))?\s*$/u';
    for ($i = 0; $i < count($lines); $i++) {
        $trimmed = trim((string)$lines[$i]);
        if ($trimmed === '') {
            $stats['linesEmpty']++;
            continue;
        }

        // Caso A: categoria e importi sulla stessa riga.
        if (preg_match($linePattern, $trimmed, $m) === 1) {
            $category = trim(preg_replace('/\s+/', ' ', $m[1]));
            if (mb_strlen($category) < 3) {
                $stats['rejectedNoPattern']++;
                continue;
            }
            if (in_array(mb_strtoupper($category), $skipCategories, true)) {
                $stats['rejectedSkipCategory']++;
                if (count($rejectedSamples) < 12) {
                    $rejectedSamples[] = ['line' => $trimmed, 'reason' => 'skip_category'];
                }
                continue;
            }
            $consuntivo = parse_italian_amount($m[3]);
            $amount = $consuntivo;
            if ($amount === 0.0) {
                $amount = parse_italian_amount($m[2]);
            }
            if ($amount === 0.0) {
                $stats['rejectedZeroAmount']++;
                continue;
            }
            if (isset($seenCategories[$category])) {
                $stats['rejectedDuplicate']++;
                continue;
            }
            $seenCategories[$category] = true;
            $stats['singleLineMatches']++;
            $items[] = [
                'category' => $category,
                'supplier' => null,
                'description' => null,
                'amount' => $amount,
                'invoice_number' => null,
                'expense_date' => null,
                'confidence' => 0.72
            ];
            if (count($acceptedSamples) < 12) {
                $acceptedSamples[] = ['category' => $category, 'amount' => $amount, 'mode' => 'single_line'];
            }
            continue;
        }

        // Caso B: categoria su una riga, importi nelle righe successive.
        if (preg_match($amountOnlyPattern, $trimmed) === 1) {
            continue;
        }

        $next1 = trim((string)($lines[$i + 1] ?? ''));
        $next2 = trim((string)($lines[$i + 2] ?? ''));
        $next3 = trim((string)($lines[$i + 3] ?? ''));
        if (preg_match($amountOnlyPattern, $next1) !== 1 || preg_match($amountOnlyPattern, $next2) !== 1) {
            $stats['rejectedNoPattern']++;
            if (count($rejectedSamples) < 12) {
                $rejectedSamples[] = ['line' => $trimmed, 'reason' => 'missing_following_amounts'];
            }
            continue;
        }

        $category = trim(preg_replace('/\s+/', ' ', $trimmed));
        if (mb_strlen($category) < 3) {
            $stats['rejectedNoPattern']++;
            continue;
        }
        if (in_array(mb_strtoupper($category), $skipCategories, true)) {
            $stats['rejectedSkipCategory']++;
            if (count($rejectedSamples) < 12) {
                $rejectedSamples[] = ['line' => $category, 'reason' => 'skip_category'];
            }
            continue;
        }

        $first = parse_italian_amount($next1);
        $second = parse_italian_amount($next2);
        $third = preg_match($amountOnlyPattern, $next3) === 1 ? parse_italian_amount($next3) : 0.0;

        // Preferisci il secondo importo (tipico consuntivo), fallback su primo/terzo.
        $amount = $second;
        if ($amount === 0.0) {
            $amount = $first;
        }
        if ($amount === 0.0 && $third !== 0.0) {
            $amount = abs($third);
        }
        if ($amount === 0.0) {
            $stats['rejectedZeroAmount']++;
            continue;
        }
        if (isset($seenCategories[$category])) {
            $stats['rejectedDuplicate']++;
            continue;
        }
        $seenCategories[$category] = true;
        $stats['multiLineMatches']++;
        $items[] = [
            'category' => $category,
            'supplier' => null,
            'description' => null,
            'amount' => $amount,
            'invoice_number' => null,
            'expense_date' => null,
            'confidence' => 0.72
        ];
        if (count($acceptedSamples) < 12) {
            $acceptedSamples[] = ['category' => $category, 'amount' => $amount, 'mode' => 'multi_line'];
        }

        // Avanza oltre i numeri gia consumati.
        $i += (preg_match($amountOnlyPattern, $next3) === 1) ? 3 : 2;
    }

    // Caso C: fallback euristico se non e stato agganciato alcun pattern rigido.
    if (count($items) === 0) {
        $lastCategoryCandidate = null;
        for ($i = 0; $i < count($lines); $i++) {
            $line = trim((string)$lines[$i]);
            if ($line === '') {
                continue;
            }
            $isAmount = preg_match($amountOnlyPattern, $line) === 1;
            if (!$isAmount) {
                $candidate = trim((string)preg_replace('/\s+/', ' ', $line));
                $upperCandidate = mb_strtoupper($candidate);
                $looksCategory = preg_match('/[A-Za-zÀ-ÖØ-öø-ÿ]/u', $candidate) === 1
                    && mb_strlen($candidate) >= 3
                    && mb_strlen($candidate) <= 80
                    && preg_match('/^(?:\d+\s*-\s*)?[A-Za-zÀ-ÖØ-öø-ÿ]/u', $candidate) === 1;
                if ($looksCategory && !in_array($upperCandidate, $skipCategories, true)) {
                    $lastCategoryCandidate = $candidate;
                }
                continue;
            }

            if ($lastCategoryCandidate === null) {
                continue;
            }
            $next = trim((string)($lines[$i + 1] ?? ''));
            if (preg_match($amountOnlyPattern, $next) !== 1) {
                continue;
            }

            $first = parse_italian_amount($line);
            $second = parse_italian_amount($next);
            $amount = $second !== 0.0 ? $second : $first;
            if ($amount === 0.0) {
                $stats['rejectedZeroAmount']++;
                continue;
            }
            if (isset($seenCategories[$lastCategoryCandidate])) {
                $stats['rejectedDuplicate']++;
                continue;
            }
            $seenCategories[$lastCategoryCandidate] = true;
            $stats['heuristicMatches']++;
            $items[] = [
                'category' => $lastCategoryCandidate,
                'supplier' => null,
                'description' => null,
                'amount' => $amount,
                'invoice_number' => null,
                'expense_date' => null,
                'confidence' => 0.64
            ];
            if (count($acceptedSamples) < 12) {
                $acceptedSamples[] = ['category' => $lastCategoryCandidate, 'amount' => $amount, 'mode' => 'heuristic'];
            }
            $i += 1;
        }
    }

    return [
        'items' => $items,
        'debug' => [
            'stats' => $stats,
            'acceptedSamples' => $acceptedSamples,
            'rejectedSamples' => $rejectedSamples
        ]
    ];
}

// Fallback deterministico per estrarre righe tabellari da testi bilancio/consuntivo.
function extract_expenses_from_table_text(string $text, string $docType = '', ?int $documentYear = null): array
{
    $parsed = analyze_table_extraction($text, $docType, $documentYear);
    return $parsed['items'] ?? [];
}

// Salva items estratti in una bozza da confermare prima del salvataggio definitivo.
function create_extraction_draft(PDO $pdo, string $documentId, string $ownerId, array $items): string
{
    $draftId = uuid_v4();
    $pdo->prepare('INSERT INTO extraction_drafts (id, document_id, owner_id, status) VALUES (?, ?, ?, ?)')
        ->execute([$draftId, $documentId, $ownerId, 'pending']);

    $insertDraftItem = $pdo->prepare("
        INSERT INTO extraction_draft_items (id, draft_id, category, supplier, description, budget_amount, amount, invoice_number, expense_date, confidence)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($items as $item) {
        $category = sanitize_text_for_db((string)($item['category'] ?? 'Da classificare')) ?? 'Da classificare';
        $supplier = sanitize_text_for_db(isset($item['supplier']) ? (string)$item['supplier'] : null);
        $description = sanitize_text_for_db(isset($item['description']) ? (string)$item['description'] : null);
        $invoiceNumber = sanitize_text_for_db(isset($item['invoice_number']) ? (string)$item['invoice_number'] : null);
        $expenseDate = sanitize_text_for_db(isset($item['expense_date']) ? (string)$item['expense_date'] : null);
        $budgetAmount = isset($item['budget_amount']) ? (float)$item['budget_amount'] : null;
        $insertDraftItem->execute([
            uuid_v4(),
            $draftId,
            $category,
            $supplier,
            $description,
            $budgetAmount,
            (float)($item['amount'] ?? 0),
            $invoiceNumber,
            $expenseDate,
            (float)($item['confidence'] ?? 0.3)
        ]);
    }
    return $draftId;
}

$action = $_GET['action'] ?? '';
$pdo = get_pdo();

try {
    // Catalogo codici errore disponibili.
    if ($action === 'error_codes') {
        respond(['codes' => error_catalog()]);
    }

    // Stato setup iniziale (wizard creazione admin).
    if ($action === 'setup_status') {
        respond(['setupRequired' => setup_required($pdo)]);
    }

    // Creazione primo amministratore.
    if ($action === 'setup_admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!setup_required($pdo)) {
            respond_error('SETUP_ALREADY_DONE', 409);
        }
        $body = json_input();
        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $fullName = trim((string)($body['fullName'] ?? ''));
        if ($email === '' || $password === '' || $fullName === '') {
            respond_error('BAD_REQUEST', 400, 'Dati admin incompleti');
        }
        if (strlen($password) < 8) {
            respond_error('BAD_REQUEST', 400, 'Password minima 8 caratteri');
        }
        $stmt = $pdo->prepare('INSERT INTO users (id, email, password_hash, full_name) VALUES (?, ?, ?, ?)');
        $stmt->execute([uuid_v4(), $email, password_hash($password, PASSWORD_DEFAULT), $fullName]);
        respond(['message' => 'Amministratore creato']);
    }

    // Login con sessione PHP.
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (setup_required($pdo)) {
            respond_error('SETUP_INCOMPLETE', 403);
        }
        $body = json_input();
        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $remember = (bool)($body['remember'] ?? false);
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            respond_error('INVALID_CREDENTIALS', 401);
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        // Estende la durata del cookie di sessione quando l'utente richiede "Ricordami".
        if ($remember) {
            $params = session_get_cookie_params();
            setcookie(session_name(), session_id(), [
                'expires' => time() + (60 * 60 * 24 * 30),
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?: '',
                'secure' => (bool)$params['secure'],
                'httponly' => (bool)$params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax'
            ]);
        }
        respond(['user' => ['id' => $user['id'], 'name' => $user['full_name'], 'email' => $user['email']]]);
    }

    // Logout e invalidazione sessione.
    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Invalida anche il cookie sessione lato browser.
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?: '',
                'secure' => (bool)$params['secure'],
                'httponly' => (bool)$params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax'
            ]);
        }
        session_destroy();
        respond(['message' => 'Logout effettuato']);
    }

    // Profilo utente corrente.
    if ($action === 'me') {
        $userId = require_auth();
        respond(['user' => ['id' => $userId, 'name' => $_SESSION['user_name'] ?? 'Utente']]);
    }

    // Upload PDF e creazione bozza di revisione prima del salvataggio definitivo.
    if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = require_auth();
        if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            respond_error('PDF_UPLOAD_MISSING', 400);
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
            respond_error('PDF_UPLOAD_FAILED', 500);
        }

        $scope = (string)($_POST['scope'] ?? 'condominiale');
        $docType = detect_doc_type((string)($_POST['docType'] ?? ''), $original);
        $year = detect_year((string)($_POST['year'] ?? ''), $original);

        $docId = uuid_v4();
        $stmt = $pdo->prepare("
            INSERT INTO documents (id, owner_id, scope, doc_type, year, file_name, file_path, extraction_status, confidence, needs_review)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', 0, 1)
        ");
        $stmt->execute([$docId, $userId, $scope, $docType, $year, $original, $targetPath]);

        $text = extract_pdf_text_for_ai($targetPath);
        if ($text !== '') {
            // Prima scelta: parser deterministico non-AI.
            $fallbackItems = extract_expenses_from_table_text($text, $docType, $year);
            if (!empty($fallbackItems)) {
                $aiResult = [
                    'ok' => true,
                    'message' => 'Estrazione tabellare non-AI completata',
                    'items' => $fallbackItems
                ];
            } else {
                // Fallback secondario: AI.
                $aiSettings = get_ai_settings($pdo);
                $aiResult = extract_expenses_with_ai($text, [
                    'doc_type' => $docType,
                    'year' => $year,
                    'scope' => $scope
                ], $aiSettings);
            }

            if ($aiResult['ok'] && !empty($aiResult['items'])) {
                $draftId = create_extraction_draft($pdo, $docId, $userId, $aiResult['items']);
                $sumConfidence = array_reduce($aiResult['items'], fn($carry, $i) => $carry + (float)$i['confidence'], 0.0);
                $avgConfidence = $sumConfidence / max(1, count($aiResult['items']));
                $needsReview = $avgConfidence < 0.7 ? 1 : 0;
                $pdo->prepare("UPDATE documents SET extraction_status = 'draft', confidence = ?, needs_review = ? WHERE id = ?")
                    ->execute([$avgConfidence, $needsReview, $docId]);
                respond([
                    'message' => 'Documento caricato. Revisa la bozza prima del salvataggio definitivo.',
                    'documentId' => $docId,
                    'draftId' => $draftId,
                    'reviewUrl' => 'extraction-review.php?draft_id=' . urlencode($draftId),
                    'extractedItems' => count($aiResult['items']),
                    'averageConfidence' => round($avgConfidence, 4),
                    'aiStatus' => 'ok'
                ]);
            }
        }

        // Fallback: nessuna estrazione valida trovata, crea bozza con voce placeholder.
        $fallbackItems = [[
            'category' => 'Da classificare',
            'supplier' => null,
            'description' => 'Voce creata da upload PDF, completare in revisione.',
            'budget_amount' => null,
            'amount' => 0.01,
            'invoice_number' => null,
            'expense_date' => null,
            'confidence' => 0.30
        ]];
        $draftId = create_extraction_draft($pdo, $docId, $userId, $fallbackItems);

        $pdo->prepare("UPDATE documents SET extraction_status = 'draft', confidence = 0.30, needs_review = 1 WHERE id = ?")
            ->execute([$docId]);

        respond([
            'message' => 'Documento caricato. AI non ha estratto voci affidabili: completa la bozza manualmente.',
            'documentId' => $docId,
            'draftId' => $draftId,
            'reviewUrl' => 'extraction-review.php?draft_id=' . urlencode($draftId),
            'extractedItems' => 1,
            'aiStatus' => 'fallback'
        ]);
    }

    // Archivio documenti suddiviso per tipologia.
    if ($action === 'documents_list') {
        $userId = require_auth();
        $scope = trim((string)($_GET['scope'] ?? ''));
        $yearRaw = trim((string)($_GET['year'] ?? ''));
        $params = [$userId];
        $where = 'WHERE owner_id = ?';
        if ($scope !== '') {
            $where .= ' AND scope = ?';
            $params[] = $scope;
        }
        if ($yearRaw !== '') {
            $where .= ' AND year = ?';
            $params[] = (int)$yearRaw;
        }

        $stmt = $pdo->prepare("
            SELECT id, file_name, doc_type, scope, year, extraction_status, confidence, needs_review, last_ai_reprocess_at, created_at
            FROM documents
            $where
            ORDER BY created_at DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $grouped = [
            'bilancio' => [],
            'consuntivo' => [],
            'fattura' => [],
            'altro' => []
        ];
        foreach ($rows as $row) {
            $type = (string)$row['doc_type'];
            if (!isset($grouped[$type])) {
                $type = 'altro';
            }
            $grouped[$type][] = $row;
        }
        respond(['groups' => $grouped, 'count' => count($rows)]);
    }

    // Eliminazione documento dal catalogo e dal filesystem.
    if ($action === 'documents_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = require_auth();
        $body = json_input();
        $documentId = trim((string)($body['id'] ?? ''));
        if ($documentId === '') {
            respond_error('MISSING_DOCUMENT_ID', 400);
        }

        $stmt = $pdo->prepare('SELECT id, file_path FROM documents WHERE id = ? AND owner_id = ? LIMIT 1');
        $stmt->execute([$documentId, $userId]);
        $document = $stmt->fetch();
        if (!$document) {
            respond_error('DOCUMENT_NOT_FOUND', 404);
        }

        $deleteStmt = $pdo->prepare('DELETE FROM documents WHERE id = ? AND owner_id = ?');
        $deleteStmt->execute([$documentId, $userId]);

        $filePath = (string)($document['file_path'] ?? '');
        if ($filePath !== '' && is_file($filePath)) {
            @unlink($filePath);
        }

        respond(['message' => 'Documento eliminato con successo']);
    }

    // Rianalizza con AI un documento gia caricato.
    if ($action === 'documents_ai_reprocess' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = require_auth();
        $body = json_input();
        $documentId = trim((string)($body['id'] ?? ''));
        if ($documentId === '') {
            respond_error('MISSING_DOCUMENT_ID', 400);
        }

        $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ? AND owner_id = ? LIMIT 1');
        $stmt->execute([$documentId, $userId]);
        $document = $stmt->fetch();
        if (!$document) {
            respond_error('DOCUMENT_NOT_FOUND', 404);
        }

        $pdo->prepare("UPDATE documents SET extraction_status = 'processing' WHERE id = ?")->execute([$documentId]);
        $text = extract_pdf_text_for_ai((string)$document['file_path']);
        if ($text === '') {
            $items = [[
                'category' => 'Da classificare',
                'supplier' => null,
                'description' => 'Nessun testo leggibile estratto dal PDF. Compila manualmente la bozza.',
                'budget_amount' => null,
                'amount' => 0.01,
                'invoice_number' => null,
                'expense_date' => null,
                'confidence' => 0.20
            ]];
            $pdo->prepare('DELETE FROM extraction_drafts WHERE document_id = ? AND owner_id = ?')->execute([$documentId, $userId]);
            $draftId = create_extraction_draft($pdo, $documentId, $userId, $items);
            $pdo->prepare("UPDATE documents SET extraction_status = 'draft', confidence = 0.20, needs_review = 1 WHERE id = ?")->execute([$documentId]);
            respond([
                'message' => 'Nessun testo leggibile dal PDF: bozza manuale creata.',
                'draftId' => $draftId,
                'reviewUrl' => 'extraction-review.php?draft_id=' . urlencode($draftId),
                'extractedItems' => 1,
                'averageConfidence' => 0.20,
                'needsReview' => true
            ]);
        }

        // Prima scelta: parser deterministico non-AI.
        $fallbackItems = extract_expenses_from_table_text($text, (string)$document['doc_type'], (int)$document['year']);
        if (!empty($fallbackItems)) {
            $aiResult = [
                'ok' => true,
                'message' => 'Estrazione tabellare non-AI completata',
                'items' => $fallbackItems
            ];
        } else {
            // Fallback secondario: AI.
            $aiSettings = get_ai_settings($pdo);
            $aiResult = extract_expenses_with_ai($text, $document, $aiSettings);
        }
        if (!$aiResult['ok']) {
            $aiResult = [
                'ok' => true,
                'message' => 'AI non disponibile: bozza manuale creata',
                'items' => [[
                    'category' => 'Da classificare',
                    'supplier' => null,
                    'description' => 'AI non disponibile o risposta non valida. Compila manualmente la bozza.',
                    'budget_amount' => null,
                    'amount' => 0.01,
                    'invoice_number' => null,
                    'expense_date' => null,
                    'confidence' => 0.20
                ]]
            ];
        }

        $items = $aiResult['items'];
        if (!$items) {
            $items[] = [
                'category' => 'Da classificare',
                'supplier' => null,
                'description' => 'AI non ha trovato voci affidabili, verifica manuale necessaria.',
                'budget_amount' => null,
                'amount' => 0.01,
                'invoice_number' => null,
                'expense_date' => null,
                'confidence' => 0.2
            ];
        }

        // Rimuove eventuali bozze pendenti sullo stesso documento prima di crearne una nuova.
        $pdo->prepare('DELETE FROM extraction_drafts WHERE document_id = ? AND owner_id = ?')->execute([$documentId, $userId]);
        $draftId = create_extraction_draft($pdo, $documentId, $userId, $items);
        $sumConfidence = array_reduce($items, fn($carry, $i) => $carry + (float)$i['confidence'], 0.0);
        $avgConfidence = $sumConfidence / max(1, count($items));
        $needsReview = $avgConfidence < 0.7 ? 1 : 0;
        $pdo->prepare("UPDATE documents SET extraction_status = 'draft', confidence = ?, needs_review = ? WHERE id = ?")
            ->execute([$avgConfidence, $needsReview, $documentId]);

        respond([
            'message' => 'Documento rianalizzato con AI. Revisa la bozza prima del salvataggio.',
            'draftId' => $draftId,
            'reviewUrl' => 'extraction-review.php?draft_id=' . urlencode($draftId),
            'extractedItems' => count($items),
            'averageConfidence' => round($avgConfidence, 4),
            'needsReview' => $needsReview === 1
        ]);
    }

    // Debug estrazione: mostra schema attivo e anteprima testo PDF.
    if ($action === 'extraction_debug_get') {
        $userId = require_auth();
        $documentId = trim((string)($_GET['id'] ?? ''));
        if ($documentId === '') {
            respond_error('MISSING_DOCUMENT_ID', 400);
        }
        $stmt = $pdo->prepare('SELECT id, file_name, doc_type, scope, year, extraction_status, confidence, file_path FROM documents WHERE id = ? AND owner_id = ? LIMIT 1');
        $stmt->execute([$documentId, $userId]);
        $document = $stmt->fetch();
        if (!$document) {
            respond_error('DOCUMENT_NOT_FOUND', 404);
        }

        $textDiag = extract_pdf_text_with_diagnostics((string)$document['file_path']);
        $text = (string)($textDiag['text'] ?? '');
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $previewLines = array_slice(array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== '')), 0, 40);
        $schema = get_extraction_schema_for_doc_type((string)$document['doc_type']);
        $parser = analyze_table_extraction($text, (string)$document['doc_type'], (int)$document['year']);
        $parserItems = $parser['items'] ?? [];
        $parserDebug = $parser['debug'] ?? [];

        respond([
            'document' => [
                'id' => $document['id'],
                'fileName' => $document['file_name'],
                'docType' => $document['doc_type'],
                'scope' => $document['scope'],
                'year' => (int)$document['year'],
                'extractionStatus' => $document['extraction_status'],
                'confidence' => (float)$document['confidence']
            ],
            'debug' => [
                'schema' => $schema,
                'textStats' => [
                    'chars' => mb_strlen($text),
                    'lines' => count($previewLines),
                    'hasText' => $text !== '',
                    'source' => (string)($textDiag['source'] ?? 'none'),
                    'externalChars' => (int)($textDiag['externalChars'] ?? 0),
                    'internalChars' => (int)($textDiag['internalChars'] ?? 0)
                ],
                'textPreview' => $previewLines,
                'parserStats' => $parserDebug['stats'] ?? [],
                'parserAcceptedSamples' => $parserDebug['acceptedSamples'] ?? [],
                'parserRejectedSamples' => $parserDebug['rejectedSamples'] ?? [],
                'identification' => $parserDebug['identification'] ?? null,
                'parserItemsPreview' => array_slice($parserItems, 0, 20),
                'parserItemsCount' => count($parserItems)
            ]
        ]);
    }

    // Legge bozza di estrazione per revisione utente.
    if ($action === 'draft_get') {
        $userId = require_auth();
        $draftId = trim((string)($_GET['id'] ?? ''));
        if ($draftId === '') {
            respond_error('BAD_REQUEST', 400, 'ID bozza mancante');
        }
        $stmt = $pdo->prepare("
            SELECT d.id, d.document_id, d.status, doc.file_name, doc.doc_type, doc.scope, doc.year, doc.file_path
            FROM extraction_drafts d
            JOIN documents doc ON doc.id = d.document_id
            WHERE d.id = ? AND d.owner_id = ?
            LIMIT 1
        ");
        $stmt->execute([$draftId, $userId]);
        $draft = $stmt->fetch();
        if (!$draft) {
            respond_error('DRAFT_NOT_FOUND', 404);
        }
        $itemStmt = $pdo->prepare("
            SELECT id, category, supplier, description, budget_amount, amount, invoice_number, expense_date, confidence
            FROM extraction_draft_items
            WHERE draft_id = ?
            ORDER BY created_at ASC
        ");
        $itemStmt->execute([$draftId]);
        $items = $itemStmt->fetchAll();
        if (!$items) {
            $items = [[
                'id' => '',
                'category' => 'Da classificare',
                'supplier' => null,
                'description' => 'Bozza vuota: nessuna riga estratta automaticamente.',
                'budget_amount' => null,
                'amount' => 0.01,
                'invoice_number' => null,
                'expense_date' => null,
                'confidence' => 0.2
            ]];
        }

        $debug = null;
        $filePath = (string)($draft['file_path'] ?? '');
        if ($filePath !== '' && is_file($filePath)) {
            $textDiag = extract_pdf_text_with_diagnostics($filePath);
            $text = (string)($textDiag['text'] ?? '');
            $parsed = analyze_table_extraction($text, (string)$draft['doc_type'], (int)$draft['year']);
            $previewLines = preg_split('/\r\n|\r|\n/', $text) ?: [];
            $previewLines = array_slice(array_values(array_filter(array_map('trim', $previewLines), fn($l) => $l !== '')), 0, 20);
            $debug = [
                'parserStats' => $parsed['debug']['stats'] ?? [],
                'parserAcceptedSamples' => $parsed['debug']['acceptedSamples'] ?? [],
                'parserRejectedSamples' => $parsed['debug']['rejectedSamples'] ?? [],
                'identification' => $parsed['debug']['identification'] ?? null,
                'parserItemsCount' => count($parsed['items'] ?? []),
                'debugUrl' => 'extraction-debug.php?id=' . urlencode((string)$draft['document_id']),
                'textSource' => (string)($textDiag['source'] ?? 'none'),
                'textChars' => mb_strlen($text),
                'externalChars' => (int)($textDiag['externalChars'] ?? 0),
                'internalChars' => (int)($textDiag['internalChars'] ?? 0),
                'textPreview' => $previewLines
            ];
        }

        respond(['draft' => $draft, 'items' => $items, 'debug' => $debug]);
    }

    // Conferma bozza: salva voci in expenses e chiude documento.
    if ($action === 'draft_confirm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = require_auth();
        $body = json_input();
        $draftId = trim((string)($body['id'] ?? ''));
        $items = $body['items'] ?? [];
        if ($draftId === '' || !is_array($items) || !$items) {
            respond_error('BAD_REQUEST', 400, 'Dati bozza non validi');
        }

        $stmt = $pdo->prepare("SELECT * FROM extraction_drafts WHERE id = ? AND owner_id = ? LIMIT 1");
        $stmt->execute([$draftId, $userId]);
        $draft = $stmt->fetch();
        if (!$draft) {
            respond_error('DRAFT_NOT_FOUND', 404);
        }

        $docStmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND owner_id = ? LIMIT 1");
        $docStmt->execute([(string)$draft['document_id'], $userId]);
        $document = $docStmt->fetch();
        if (!$document) {
            respond_error('DOCUMENT_NOT_FOUND', 404, 'Documento collegato non trovato');
        }

        $pdo->prepare('DELETE FROM expenses WHERE document_id = ? AND owner_id = ?')->execute([(string)$draft['document_id'], $userId]);
        $insertExpense = $pdo->prepare("
            INSERT INTO expenses (id, document_id, owner_id, scope, year, category, supplier, description, budget_amount, amount, invoice_number, expense_date, confidence, needs_review)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $sumConfidence = 0.0;
        $needsReview = 0;
        foreach ($items as $item) {
            $amount = (float)($item['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $confidence = (float)($item['confidence'] ?? 0.5);
            $category = sanitize_text_for_db((string)($item['category'] ?? 'Da classificare')) ?? 'Da classificare';
            $supplier = sanitize_text_for_db(isset($item['supplier']) ? (string)$item['supplier'] : null);
            $description = sanitize_text_for_db(isset($item['description']) ? (string)$item['description'] : null);
            $invoiceNumber = sanitize_text_for_db(isset($item['invoice_number']) ? (string)$item['invoice_number'] : null);
            $expenseDate = sanitize_text_for_db(isset($item['expense_date']) ? (string)$item['expense_date'] : null);
            $budgetAmount = isset($item['budget_amount']) ? (float)$item['budget_amount'] : null;
            $sumConfidence += $confidence;
            $itemNeedsReview = $confidence < 0.7 ? 1 : 0;
            if ($itemNeedsReview === 1) {
                $needsReview = 1;
            }
            $insertExpense->execute([
                uuid_v4(),
                (string)$draft['document_id'],
                $userId,
                (string)$document['scope'],
                (int)$document['year'],
                $category,
                $supplier,
                $description,
                $budgetAmount,
                $amount,
                $invoiceNumber,
                $expenseDate,
                $confidence,
                $itemNeedsReview
            ]);
        }

        $validCount = max(1, count($items));
        $avgConfidence = $sumConfidence / $validCount;
        $pdo->prepare("UPDATE documents SET extraction_status = 'done', confidence = ?, needs_review = ?, last_ai_reprocess_at = NOW() WHERE id = ?")
            ->execute([$avgConfidence, $needsReview, (string)$draft['document_id']]);
        $pdo->prepare("DELETE FROM extraction_drafts WHERE id = ? AND owner_id = ?")->execute([$draftId, $userId]);

        respond([
            'message' => 'Bozza confermata e voci salvate',
            'savedItems' => count($items),
            'averageConfidence' => round($avgConfidence, 4)
        ]);
    }

    // Annulla bozza senza salvare voci.
    if ($action === 'draft_cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = require_auth();
        $body = json_input();
        $draftId = trim((string)($body['id'] ?? ''));
        if ($draftId === '') {
            respond_error('BAD_REQUEST', 400, 'ID bozza mancante');
        }
        $stmt = $pdo->prepare("SELECT document_id FROM extraction_drafts WHERE id = ? AND owner_id = ? LIMIT 1");
        $stmt->execute([$draftId, $userId]);
        $draft = $stmt->fetch();
        if (!$draft) {
            respond_error('DRAFT_NOT_FOUND', 404);
        }
        $pdo->prepare("DELETE FROM extraction_drafts WHERE id = ? AND owner_id = ?")->execute([$draftId, $userId]);
        $pdo->prepare("UPDATE documents SET extraction_status = 'failed', needs_review = 1 WHERE id = ?")->execute([(string)$draft['document_id']]);
        respond(['message' => 'Bozza annullata']);
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
            respond_error('BAD_REQUEST', 400, 'ID mancante');
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
        $stmt = $pdo->query('SELECT ollama_url, ollama_model, ollama_api_key, ai_context_mode, updated_at FROM ai_settings WHERE id = 1');
        $row = $stmt->fetch();
        if (!$row) {
            respond([
                'ollamaUrl' => 'http://127.0.0.1:11434',
                'ollamaModel' => 'llama3.1',
                'ollamaApiKey' => '',
                'aiContextMode' => 'compact',
                'updatedAt' => null
            ]);
        }
        respond([
            'ollamaUrl' => $row['ollama_url'],
            'ollamaModel' => $row['ollama_model'],
            'ollamaApiKey' => $row['ollama_api_key'] ?? '',
            'aiContextMode' => $row['ai_context_mode'] ?? 'compact',
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
        $aiContextMode = trim((string)($body['aiContextMode'] ?? 'compact'));
        if (!in_array($aiContextMode, ['compact', 'full'], true)) {
            $aiContextMode = 'compact';
        }
        if ($ollamaUrl === '' || $ollamaModel === '') {
            respond_error('BAD_REQUEST', 400, 'Compila URL e modello Ollama');
        }
        $stmt = $pdo->prepare('
            INSERT INTO ai_settings (id, ollama_url, ollama_model, ollama_api_key, ai_context_mode)
            VALUES (1, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                ollama_url = VALUES(ollama_url),
                ollama_model = VALUES(ollama_model),
                ollama_api_key = VALUES(ollama_api_key),
                ai_context_mode = VALUES(ai_context_mode)
        ');
        $stmt->execute([$ollamaUrl, $ollamaModel, $ollamaApiKey === '' ? null : $ollamaApiKey, $aiContextMode]);
        respond(['message' => 'Impostazioni AI salvate']);
    }

    // Test connessione endpoint AI configurato.
    if ($action === 'ai_settings_test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_auth();
        $body = json_input();
        $ollamaUrl = trim((string)($body['ollamaUrl'] ?? ''));
        $ollamaApiKey = trim((string)($body['ollamaApiKey'] ?? ''));
        if ($ollamaUrl === '') {
            respond_error('BAD_REQUEST', 400, 'URL Ollama mancante');
        }
        $test = call_ollama_test($ollamaUrl, $ollamaApiKey === '' ? null : $ollamaApiKey);
        if (!$test['ok']) {
            respond_error('AI_UNAVAILABLE', 400, (string)$test['message']);
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
            respond_error('BAD_REQUEST', 400, 'URL Ollama mancante');
        }
        $result = fetch_ollama_models($ollamaUrl, $ollamaApiKey === '' ? null : $ollamaApiKey);
        if (!$result['ok']) {
            respond_error('AI_UNAVAILABLE', 400, (string)$result['message']);
        }
        respond(['models' => $result['models']]);
    }

    // Fallback endpoint non definito.
    respond_error('BAD_REQUEST', 404, 'Endpoint non trovato');
} catch (Throwable $e) {
    // Gestione errori non previsti lato server.
    respond_error('SERVER_ERROR', 500, 'Errore server: ' . $e->getMessage());
}
