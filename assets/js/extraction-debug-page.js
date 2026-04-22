// Azioni topbar.
document.getElementById("logoutBtn").addEventListener("click", doLogout);

function getDocumentIdFromQuery() {
  const params = new URLSearchParams(window.location.search);
  return params.get("id") || "";
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function evaluateTextQuality(textPreview, stats) {
  if (!stats.hasText || stats.chars < 80 || stats.lines === 0) {
    return {
      label: "Scarsa",
      badgeClass: "badge badge-danger",
      reason: "Nessun testo utile: probabile PDF scansione o parsing insufficiente."
    };
  }

  const joined = (textPreview || []).join(" ");
  const numericMatches = joined.match(/-?\d{1,3}(?:\.\d{3})*,\d{2}/g) || [];
  const tableLikeDensity = numericMatches.length / Math.max(1, stats.lines);

  if (stats.chars >= 400 && stats.lines >= 10 && tableLikeDensity >= 0.5) {
    return {
      label: "Buona",
      badgeClass: "badge badge-success",
      reason: "Testo ricco e con pattern numerici tabellari: estrazione AI favorita."
    };
  }

  return {
    label: "Media",
    badgeClass: "badge badge-warning",
    reason: "Testo parziale o poco strutturato: potrebbe servire revisione manuale."
  };
}

function renderDocumentMeta(documentData, stats, textPreview) {
  const el = document.getElementById("debugDocumentMeta");
  const quality = evaluateTextQuality(textPreview || [], stats);
  el.innerHTML = `
    <div><strong>File:</strong> ${documentData.fileName}</div>
    <div><strong>Tipo:</strong> ${documentData.docType}</div>
    <div><strong>Ambito:</strong> ${documentData.scope}</div>
    <div><strong>Anno:</strong> ${documentData.year}</div>
    <div><strong>Stato estrazione:</strong> ${documentData.extractionStatus}</div>
    <div><strong>Confidenza media:</strong> ${Number(documentData.confidence || 0).toFixed(2)}</div>
    <div>
      <strong>Qualità input AI:</strong>
      <span class="${quality.badgeClass}">${quality.label}</span>
      <span class="small text-muted ml-2">${escapeHtml(quality.reason)}</span>
    </div>
    <hr />
    <div><strong>Testo leggibile:</strong> ${stats.hasText ? "Sì" : "No"}</div>
    <div><strong>Caratteri estratti:</strong> ${stats.chars}</div>
    <div><strong>Righe anteprima:</strong> ${stats.lines}</div>
  `;
}

function renderParserDebug(debug) {
  const stats = debug.parserStats || {};
  const accepted = debug.parserAcceptedSamples || [];
  const rejected = debug.parserRejectedSamples || [];
  const itemsPreview = debug.parserItemsPreview || [];
  const itemsCount = Number(debug.parserItemsCount || 0);

  const statsEl = document.getElementById("parserStats");
  const acceptedEl = document.getElementById("parserAccepted");
  const rejectedEl = document.getElementById("parserRejected");
  const itemsEl = document.getElementById("parserItemsPreview");
  if (!statsEl || !acceptedEl || !rejectedEl || !itemsEl) {
    return;
  }

  statsEl.textContent = [
    `Righe totali: ${stats.linesTotal ?? 0}`,
    `Righe vuote: ${stats.linesEmpty ?? 0}`,
    `Match singola riga: ${stats.singleLineMatches ?? 0}`,
    `Match multi-riga: ${stats.multiLineMatches ?? 0}`,
    `Scarti no-pattern: ${stats.rejectedNoPattern ?? 0}`,
    `Scarti categoria: ${stats.rejectedSkipCategory ?? 0}`,
    `Scarti importo zero: ${stats.rejectedZeroAmount ?? 0}`,
    `Scarti duplicato: ${stats.rejectedDuplicate ?? 0}`,
    `Voci finali parser: ${itemsCount}`
  ].join(" | ");

  acceptedEl.textContent =
    accepted.length ? JSON.stringify(accepted, null, 2) : "Nessuna riga accettata.";
  rejectedEl.textContent =
    rejected.length ? JSON.stringify(rejected, null, 2) : "Nessuna riga scartata registrata.";
  itemsEl.textContent =
    itemsPreview.length ? JSON.stringify(itemsPreview, null, 2) : "Nessuna voce estratta dal parser.";
}

async function loadExtractionDebug() {
  const documentId = getDocumentIdFromQuery();
  if (!documentId) {
    throw new Error("ID documento mancante nella URL.");
  }

  const response = await fetch(`api.php?action=extraction_debug_get&id=${encodeURIComponent(documentId)}`);
  const payload = await response.json();
  if (!response.ok) {
    const message = (typeof normalizeApiError === "function")
      ? normalizeApiError(payload, "Errore caricamento debug")
      : (payload.error || "Errore caricamento debug");
    throw new Error(message);
  }
  const textPreview = payload.debug.textPreview || [];
  renderDocumentMeta(payload.document, payload.debug.textStats, textPreview);
  document.getElementById("debugSchema").textContent = JSON.stringify(payload.debug.schema, null, 2);
  document.getElementById("debugTextPreview").textContent = textPreview.join("\n") || "Nessun testo leggibile trovato nel PDF.";
  renderParserDebug(payload.debug || {});
  document.getElementById("debugResult").textContent = "Debug caricato: verifica schema e righe per capire rapidamente eventuali problemi di estrazione.";
}

loadExtractionDebug().catch((error) => {
  document.getElementById("debugResult").className = "small mb-3 text-danger";
  document.getElementById("debugResult").textContent = error.message;
});
