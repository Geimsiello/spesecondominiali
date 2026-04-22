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
  <title>Upload PDF - Spese Condominiali</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/startbootstrap-sb-admin-2@4.1.4/css/sb-admin-2.min.css" />
  <link rel="stylesheet" href="assets/css/app.css" />
</head>
<body id="page-top">
<div id="wrapper">
  <?php render_sidebar('upload'); ?>
  <div id="content-wrapper" class="d-flex flex-column">
    <div id="content">
      <?php render_topbar(current_user_name()); ?>
      <div class="container-fluid">
        <div class="card mb-4 page-card">
          <div class="card-header">Carica PDF</div>
          <div class="card-body">
            <form id="uploadForm" class="row" enctype="multipart/form-data">
              <div class="col-md-3 mb-2">
                <select name="scope" class="form-control">
                  <option value="condominiale">Condominiale</option>
                  <option value="personale">Personale</option>
                </select>
              </div>
              <div class="col-md-3 mb-2">
                <select name="docType" class="form-control">
                  <option value="bilancio">Bilancio</option>
                  <option value="consuntivo">Consuntivo</option>
                  <option value="fattura">Fattura</option>
                </select>
              </div>
              <div class="col-md-2 mb-2"><input class="form-control" name="year" type="number" required placeholder="Anno" /></div>
              <div class="col-md-4 mb-2"><input class="form-control" type="file" name="pdf" accept="application/pdf" required /></div>
              <div class="col-md-12 mb-2"><button class="btn btn-success">Upload</button></div>
            </form>
            <pre id="uploadResult" class="bg-light p-3 border rounded small"></pre>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="assets/js/api-client.js"></script>
<script src="assets/js/upload-page.js"></script>
</body>
</html>
