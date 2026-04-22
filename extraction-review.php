<?php
declare(strict_types=1);
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
ensure_logged_in();
$draftId = trim((string)($_GET['draft_id'] ?? ''));
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Revisione estrazione - Spese Condominiali</title>
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
        <div class="card mb-4">
          <div class="card-header">Revisione estrazione AI prima del salvataggio</div>
          <div class="card-body">
            <input type="hidden" id="draftId" value="<?php echo htmlspecialchars($draftId, ENT_QUOTES, 'UTF-8'); ?>" />
            <div id="draftInfo" class="small text-muted mb-3"></div>
            <div id="reviewDebugBox" class="alert alert-light border small mb-3 d-none">
              <div class="d-flex align-items-center justify-content-between">
                <strong>Debug parser revisione</strong>
                <a id="reviewDebugLink" href="#" target="_blank" class="btn btn-sm btn-outline-secondary">Apri debug completo</a>
              </div>
              <div id="reviewDebugStats" class="mt-2 text-muted"></div>
              <pre id="reviewDebugRejected" class="mt-2 mb-0" style="white-space: pre-wrap;"></pre>
            </div>
            <div class="row mb-3">
              <div class="col-md-4">
                <div class="card border-left-primary shadow-sm h-100">
                  <div class="card-body py-2">
                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Numero righe</div>
                    <div id="summaryRowCount" class="h5 mb-0 font-weight-bold text-gray-800">0</div>
                  </div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="card border-left-success shadow-sm h-100">
                  <div class="card-body py-2">
                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Totale importi</div>
                    <div id="summaryTotalAmount" class="h5 mb-0 font-weight-bold text-gray-800">0.00</div>
                  </div>
                </div>
              </div>
              <div class="col-md-4">
                <div id="summaryAvgCard" class="card border-left-info shadow-sm h-100">
                  <div class="card-body py-2">
                    <div id="summaryAvgTitle" class="text-xs font-weight-bold text-info text-uppercase mb-1">Media confidenza</div>
                    <div id="summaryAvgConfidence" class="h5 mb-0 font-weight-bold text-gray-800">0.00</div>
                  </div>
                </div>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table table-sm table-bordered">
                <thead id="draftItemsHead"></thead>
                <tbody id="draftItemsBody"></tbody>
              </table>
            </div>
            <div id="draftActionResult" class="small mb-3"></div>
            <button id="confirmDraftBtn" class="btn btn-success">Conferma e salva</button>
            <button id="cancelDraftBtn" class="btn btn-outline-danger ml-2">Annulla bozza</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="assets/js/api-client.js?v=20260422-3"></script>
<script src="assets/js/extraction-review-page.js?v=20260422-3"></script>
</body>
</html>
