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
  <title>Settings AI - Spese Condominiali</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/startbootstrap-sb-admin-2@4.1.4/css/sb-admin-2.min.css" />
  <link rel="stylesheet" href="assets/css/app.css" />
</head>
<body id="page-top">
<div id="wrapper">
  <?php render_sidebar('ai-settings'); ?>
  <div id="content-wrapper" class="d-flex flex-column">
    <div id="content">
      <?php render_topbar(current_user_name()); ?>
      <div class="container-fluid">
        <div class="card mb-4">
          <div class="card-header">Settings AI (Ollama)</div>
          <div class="card-body">
            <form id="aiSettingsForm" class="row">
              <div class="col-md-5 mb-2">
                <label class="small text-muted">URL Ollama</label>
                <input id="ollamaUrlInput" name="ollamaUrl" class="form-control" placeholder="http://127.0.0.1:11434" required />
              </div>
              <div class="col-md-3 mb-2">
                <label class="small text-muted">Modello</label>
                <select id="ollamaModelInput" name="ollamaModel" class="form-control" required>
                  <option value="">Seleziona modello</option>
                </select>
              </div>
              <div class="col-md-4 mb-2">
                <label class="small text-muted">API Key (opzionale)</label>
                <input id="ollamaApiKeyInput" name="ollamaApiKey" class="form-control" placeholder="Inserisci API key" />
              </div>
              <div class="col-md-3 mb-2">
                <button class="btn btn-primary btn-block">Salva impostazioni</button>
              </div>
              <div class="col-md-3 mb-2">
                <button type="button" id="testAiBtn" class="btn btn-info btn-block">Test connessione</button>
              </div>
              <div class="col-md-3 mb-2">
                <button type="button" id="loadModelsBtn" class="btn btn-secondary btn-block">Carica modelli</button>
              </div>
            </form>
            <div id="aiSettingsResult" class="small text-muted mt-2"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="assets/js/api-client.js"></script>
<script src="assets/js/ai-settings-page.js"></script>
</body>
</html>
