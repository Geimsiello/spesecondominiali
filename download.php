<?php

declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/lib/db.php';

ensure_logged_in();
$userId = (string)($_SESSION['user_id'] ?? '');
$documentId = trim((string)($_GET['id'] ?? ''));

if ($documentId === '') {
    http_response_code(400);
    echo 'ID documento mancante';
    exit;
}

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT file_name, file_path FROM documents WHERE id = ? AND owner_id = ? LIMIT 1');
$stmt->execute([$documentId, $userId]);
$document = $stmt->fetch();

if (!$document) {
    http_response_code(404);
    echo 'Documento non trovato';
    exit;
}

$filePath = (string)$document['file_path'];
if (!is_file($filePath)) {
    http_response_code(404);
    echo 'File non disponibile sul server';
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename((string)$document['file_name']) . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
