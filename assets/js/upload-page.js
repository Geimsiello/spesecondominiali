// Azioni topbar.
document.getElementById("logoutBtn").addEventListener("click", doLogout);

function initBootstrapTooltips() {
  if (window.jQuery && window.jQuery.fn && window.jQuery.fn.tooltip) {
    window.jQuery('[data-toggle="tooltip"]').tooltip();
  }
}

function updateConfidenceTooltip(text) {
  const icon = document.getElementById("confidenceInfoIcon");
  icon.setAttribute("title", text);
  icon.setAttribute("data-original-title", text);
  if (window.jQuery && window.jQuery.fn && window.jQuery.fn.tooltip) {
    const $icon = window.jQuery(icon);
    $icon.tooltip("dispose");
    $icon.tooltip();
  }
}

function detectDocTypeFromFilename(filename) {
  const lower = filename.toLowerCase();
  if (lower.includes("consuntivo")) return { value: "consuntivo", detected: true };
  if (lower.includes("bilancio")) return { value: "bilancio", detected: true };
  if (lower.includes("fattura")) return { value: "fattura", detected: true };
  return { value: "fattura", detected: false };
}

function detectYearFromFilename(filename) {
  const match = filename.match(/(19\d{2}|20\d{2}|21\d{2})/);
  if (!match) return { value: new Date().getFullYear(), detected: false };
  return { value: Number(match[1]), detected: true };
}

function renderDetectionConfidence(docTypeDetected, yearDetected) {
  const badge = document.getElementById("detectedConfidenceBadge");
  const reasons = [];
  if (!docTypeDetected) reasons.push("tipologia non trovata nel nome file");
  if (!yearDetected) reasons.push("anno non trovato nel nome file");

  if (docTypeDetected && yearDetected) {
    badge.className = "badge badge-success";
    badge.textContent = "Alta";
    updateConfidenceTooltip("Rilevati automaticamente sia tipologia che anno dal nome file.");
    return;
  }
  badge.className = "badge badge-warning";
  badge.textContent = "Bassa";
  updateConfidenceTooltip(`Confidenza bassa: ${reasons.join("; ")}.`);
}

function applyDetectedMetadata() {
  const fileInput = document.getElementById("pdfFileInput");
  const file = fileInput.files && fileInput.files[0];
  if (!file) {
    document.getElementById("detectedDocType").textContent = "-";
    document.getElementById("detectedYear").textContent = "-";
    const badge = document.getElementById("detectedConfidenceBadge");
    badge.className = "badge badge-secondary";
    badge.textContent = "N/D";
    updateConfidenceTooltip("Seleziona un file per avviare il rilevamento automatico.");
    return;
  }

  const detectedDocType = detectDocTypeFromFilename(file.name);
  const detectedYear = detectYearFromFilename(file.name);
  document.getElementById("detectedDocType").textContent = detectedDocType.value;
  document.getElementById("detectedYear").textContent = String(detectedYear.value);
  document.getElementById("docTypeOverride").value = detectedDocType.value;
  document.getElementById("yearOverride").value = detectedYear.value;
  renderDetectionConfidence(detectedDocType.detected, detectedYear.detected);
}

function syncFinalMetadata() {
  const overrideEnabled = document.getElementById("overrideToggle").checked;
  const finalDocType = overrideEnabled
    ? document.getElementById("docTypeOverride").value
    : document.getElementById("detectedDocType").textContent;
  const finalYear = overrideEnabled
    ? document.getElementById("yearOverride").value
    : document.getElementById("detectedYear").textContent;

  document.getElementById("docTypeFinal").value = finalDocType && finalDocType !== "-" ? finalDocType : "";
  document.getElementById("yearFinal").value = finalYear && finalYear !== "-" ? finalYear : "";
}

const pdfFileInput = document.getElementById("pdfFileInput");
const pdfDropzone = document.getElementById("pdfDropzone");

function refreshMetadataFromSelection() {
  applyDetectedMetadata();
  syncFinalMetadata();
}

// Imposta i file rilasciati nella dropzone dentro l'input nativo,
// cosi il submit continua a usare lo stesso flusso FormData esistente.
function assignDroppedFile(fileList) {
  if (!fileList || !fileList.length) return;
  const firstFile = fileList[0];
  const fileName = firstFile && typeof firstFile.name === "string" ? firstFile.name.toLowerCase() : "";
  const isPdf = !!firstFile && (firstFile.type === "application/pdf" || fileName.endsWith(".pdf"));
  if (!isPdf) {
    document.getElementById("uploadResult").textContent = "Formato non valido: trascina un file PDF.";
    return;
  }
  const transfer = new DataTransfer();
  transfer.items.add(firstFile);
  pdfFileInput.files = transfer.files;
  document.getElementById("uploadResult").textContent = `PDF selezionato: ${firstFile.name}`;
  refreshMetadataFromSelection();
}

// Gestisce il drag & drop mantenendo feedback visivo chiaro in fase di trascinamento.
function initPdfDragAndDrop() {
  if (!pdfDropzone) return;

  // Abilita apertura rapida del file picker con click o tastiera sulla dropzone.
  pdfDropzone.addEventListener("click", () => {
    pdfFileInput.click();
  });
  pdfDropzone.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") return;
    event.preventDefault();
    pdfFileInput.click();
  });

  ["dragenter", "dragover"].forEach((eventName) => {
    pdfDropzone.addEventListener(eventName, (event) => {
      event.preventDefault();
      event.stopPropagation();
      pdfDropzone.classList.add("pdf-dropzone--active");
    });
  });

  ["dragleave", "drop"].forEach((eventName) => {
    pdfDropzone.addEventListener(eventName, (event) => {
      event.preventDefault();
      event.stopPropagation();
      pdfDropzone.classList.remove("pdf-dropzone--active");
    });
  });

  pdfDropzone.addEventListener("drop", (event) => {
    assignDroppedFile(event.dataTransfer ? event.dataTransfer.files : null);
  });
}

pdfFileInput.addEventListener("change", refreshMetadataFromSelection);

document.getElementById("overrideToggle").addEventListener("change", (event) => {
  const enabled = event.target.checked;
  document.getElementById("docTypeOverride").disabled = !enabled;
  document.getElementById("yearOverride").disabled = !enabled;
  syncFinalMetadata();
});

document.getElementById("docTypeOverride").addEventListener("change", syncFinalMetadata);
document.getElementById("yearOverride").addEventListener("input", syncFinalMetadata);

initBootstrapTooltips();
initPdfDragAndDrop();

// Upload documento PDF con metadati di classificazione.
document.getElementById("uploadForm").addEventListener("submit", async (e) => {
  e.preventDefault();
  const submitBtn = e.target.querySelector("button[type='submit'], button.btn-success");
  if (submitBtn) submitBtn.disabled = true;
  showGlobalLoader("Upload e analisi AI in corso...");
  try {
    syncFinalMetadata();
    const payload = await api("upload", { method: "POST", body: new FormData(e.target) });
    document.getElementById("uploadResult").textContent = JSON.stringify(payload, null, 2);
    if (payload.reviewUrl) {
      window.location.href = payload.reviewUrl;
    }
  } catch (error) {
    document.getElementById("uploadResult").textContent = error.message;
  } finally {
    hideGlobalLoader();
    if (submitBtn) submitBtn.disabled = false;
  }
});
