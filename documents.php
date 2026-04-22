<?php
declare(strict_types=1);
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
ensure_logged_in();
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Archivio documenti - Spese Condominiali</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/startbootstrap-sb-admin-2@4.1.4/css/sb-admin-2.min.css" />
  <link rel="stylesheet" href="assets/css/app.css" />
</head>
<body id="page-top">
<div id="wrapper">
  <?php render_sidebar('documents'); ?>
  <div id="content-wrapper" class="d-flex flex-column">
    <div id="content">
      <?php render_topbar(current_user_name()); ?>
      <div class="container-fluid">
        <div class="card mb-4">
          <div class="card-header">Archivio documenti caricati</div>
          <div class="card-body">
            <div class="form-row mb-3">
              <div class="col-md-3">
                <select id="scopeFilter" class="form-control">
                  <option value="">Tutti gli ambiti</option>
                  <option value="condominiale">Condominiale</option>
                  <option value="personale">Personale</option>
                </select>
              </div>
              <div class="col-md-3">
                <input id="yearFilter" type="number" class="form-control" placeholder="Anno" />
              </div>
              <div class="col-md-3">
                <button id="refreshDocumentsBtn" class="btn btn-info">Aggiorna archivio</button>
              </div>
            </div>
            <div id="documentsResult" class="small text-muted mb-3"></div>
            <div id="documentsActionResult" class="small mb-3"></div>
            <div id="documentsGroups"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="assets/js/api-client.js"></script>
<script src="assets/js/documents-page.js"></script>
</body>
</html>
