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
              <div class="col-md-5 mb-2">
                <div
                  id="pdfDropzone"
                  class="pdf-dropzone mb-2"
                  aria-label="Area drag and drop PDF"
                  role="button"
                  tabindex="0"
                >
                  <div class="font-weight-bold">Trascina qui il PDF</div>
                  <div class="small text-muted">oppure clicca qui per aprire il selettore file</div>
                </div>
                <input id="pdfFileInput" class="form-control" type="file" name="pdf" accept="application/pdf" required />
              </div>
              <div class="col-md-4 mb-2">
                <div class="small text-muted">Rilevamento automatico</div>
                <div class="small">Tipologia: <strong id="detectedDocType">-</strong></div>
                <div class="small">Anno: <strong id="detectedYear">-</strong></div>
                <div class="small mt-1">
                  Confidenza:
                  <span id="detectedConfidenceBadge" class="badge badge-secondary">N/D</span>
                  <button
                    type="button"
                    id="confidenceInfoIcon"
                    class="confidence-info-icon"
                    data-toggle="tooltip"
                    data-placement="top"
                    title="Seleziona un file per avviare il rilevamento automatico."
                    aria-label="Info confidenza rilevamento"
                  >
                    i
                  </button>
                </div>
              </div>

              <div class="col-md-12 mb-2">
                <div class="custom-control custom-switch">
                  <input type="checkbox" class="custom-control-input" id="overrideToggle" />
                  <label class="custom-control-label" for="overrideToggle">Override manuale tipologia/anno</label>
                </div>
              </div>

              <div class="col-md-3 mb-2">
                <select id="docTypeOverride" class="form-control" disabled>
                  <option value="bilancio">Bilancio</option>
                  <option value="consuntivo">Consuntivo</option>
                  <option value="fattura">Fattura</option>
                </select>
              </div>
              <div class="col-md-2 mb-2"><input id="yearOverride" class="form-control" type="number" placeholder="Anno" disabled /></div>
              <input type="hidden" name="docType" id="docTypeFinal" />
              <input type="hidden" name="year" id="yearFinal" />
              <div class="col-md-12 mb-2"><button class="btn btn-success">Upload</button></div>
              <div class="col-md-12">
                <a class="btn btn-link px-0" href="documents.php">Vai all'archivio documenti</a>
              </div>
            </form>
            <pre id="uploadResult" class="bg-light p-3 border rounded small"></pre>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/api-client.js"></script>
<script src="assets/js/upload-page.js"></script>
</body>
</html>
