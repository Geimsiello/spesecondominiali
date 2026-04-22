<?php

declare(strict_types=1);

// Sidebar SB Admin 2 condivisa per tutte le pagine applicative.
function render_sidebar(string $activePage): void
{
    $items = [
        'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php'],
        'upload' => ['label' => 'Upload PDF', 'href' => 'upload.php'],
        'review' => ['label' => 'Revisione', 'href' => 'review.php'],
        'ai-settings' => ['label' => 'Settings AI', 'href' => 'ai-settings.php']
    ];
    echo '<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion">';
    echo '<a class="sidebar-brand d-flex align-items-center justify-content-center" href="dashboard.php"><div class="sidebar-brand-text mx-3">Spese Portal</div></a>';
    foreach ($items as $key => $item) {
        $activeClass = $key === $activePage ? ' active' : '';
        echo '<li class="nav-item' . $activeClass . '"><a class="nav-link" href="' . $item['href'] . '">' . $item['label'] . '</a></li>';
    }
    echo '</ul>';
}

// Topbar condivisa con utente corrente e azione logout.
function render_topbar(string $userName): void
{
    $safeName = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
    echo '<nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">';
    echo '<span class="mr-2 d-none d-lg-inline text-gray-600 small">' . $safeName . '</span>';
    echo '<button class="btn btn-sm btn-danger ml-auto" id="logoutBtn">Logout</button>';
    echo '</nav>';
}
