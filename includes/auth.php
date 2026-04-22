<?php

declare(strict_types=1);

// Blocca l'accesso alle pagine protette e reindirizza al login.
function ensure_logged_in(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

// Restituisce il nome utente da mostrare nella topbar.
function current_user_name(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return (string)($_SESSION['user_name'] ?? 'Utente');
}
