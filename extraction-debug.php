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
  <title>Debug estrazione - Spese Condominiali</title>
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
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
          <h1 class="h3 mb-0 text-gray-800">Debug estrazione</h1>
          <a href="documents.php" class="btn btn-sm btn-outline-secondary">Torna all'archivio</a>
        </div>

        <div id="debugResult" class="small mb-3 text-muted"></div>

        <div class="card mb-4">
          <div class="card-header">Documento</div>
          <div class="card-body" id="debugDocumentMeta"></div>
        </div>

        <div class="card mb-4">
          <div class="card-header">Schema usato</div>
          <div class="card-body">
            <pre id="debugSchema" class="mb-0" style="white-space: pre-wrap;"></pre>
          </div>
        </div>

        <div class="card mb-4">
          <div class="card-header">Prime righe testo PDF (anteprima)</div>
          <div class="card-body">
            <pre id="debugTextPreview" class="mb-0" style="white-space: pre-wrap;"></pre>
          </div>
        </div>

        <div class="card mb-4">
          <div class="card-header">Debug parser tabellare</div>
          <div class="card-body">
            <div id="parserStats" class="small mb-3 text-muted"></div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <div class="font-weight-bold mb-1">Esempi righe accettate</div>
                <pre id="parserAccepted" class="mb-0" style="white-space: pre-wrap;"></pre>
              </div>
              <div class="col-md-6 mb-3">
                <div class="font-weight-bold mb-1">Esempi righe scartate (con motivo)</div>
                <pre id="parserRejected" class="mb-0" style="white-space: pre-wrap;"></pre>
              </div>
            </div>
            <div class="font-weight-bold mb-1">Voci estratte dal parser (anteprima)</div>
            <pre id="parserItemsPreview" class="mb-0" style="white-space: pre-wrap;"></pre>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="assets/js/api-client.js?v=20260422-1"></script>
<script src="assets/js/extraction-debug-page.js?v=20260422-1"></script>
</body>
</html>
