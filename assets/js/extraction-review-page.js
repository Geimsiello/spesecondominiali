document.getElementById("logoutBtn").addEventListener("click", doLogout);

let currentDraftItems = [];
let currentDocType = "";

function updateSummary(items) {
  const rowCount = items.length;
  const totalAmount = items.reduce((sum, item) => sum + Number(item.amount || 0), 0);
  const avgConfidence =
    rowCount === 0
      ? 0
      : items.reduce((sum, item) => sum + Number(item.confidence || 0), 0) / rowCount;

  document.getElementById("summaryRowCount").textContent = String(rowCount);
  document.getElementById("summaryTotalAmount").textContent = totalAmount.toFixed(2);
  document.getElementById("summaryAvgConfidence").textContent = avgConfidence.toFixed(2);

  const avgCard = document.getElementById("summaryAvgCard");
  const avgTitle = document.getElementById("summaryAvgTitle");
  avgCard.classList.remove("border-left-info", "border-left-success", "border-left-warning", "border-left-danger");
  avgTitle.classList.remove("text-info", "text-success", "text-warning", "text-danger");

  if (avgConfidence >= 0.8) {
    avgCard.classList.add("border-left-success");
    avgTitle.classList.add("text-success");
  } else if (avgConfidence >= 0.5) {
    avgCard.classList.add("border-left-warning");
    avgTitle.classList.add("text-warning");
  } else {
    avgCard.classList.add("border-left-danger");
    avgTitle.classList.add("text-danger");
  }
}

function renderDraftHead() {
  const head = document.getElementById("draftItemsHead");
  if (!head) return;
  if (currentDocType === "consuntivo") {
    head.innerHTML = `
      <tr>
        <th>Categoria</th>
        <th>Descrizione</th>
        <th>Preventivo</th>
        <th>Consuntivo</th>
        <th>Data (anno)</th>
        <th>Confidenza</th>
      </tr>
    `;
    return;
  }
  head.innerHTML = `
    <tr>
      <th>Categoria</th>
      <th>Fornitore</th>
      <th>Descrizione</th>
      <th>Importo</th>
      <th>Fattura</th>
      <th>Data</th>
      <th>Confidenza</th>
    </tr>
  `;
}

function renderDraftItems(items) {
  const body = document.getElementById("draftItemsBody");
  body.innerHTML = "";
  renderDraftHead();
  items.forEach((item, index) => {
    const row = document.createElement("tr");
    if (currentDocType === "consuntivo") {
      row.innerHTML = `
        <td><input class="form-control form-control-sm" data-field="category" data-index="${index}" value="${item.category || ""}"></td>
        <td><input class="form-control form-control-sm" data-field="description" data-index="${index}" value="${item.description || ""}"></td>
        <td><input class="form-control form-control-sm" data-field="budget_amount" data-index="${index}" type="number" step="0.01" value="${item.budget_amount || 0}"></td>
        <td><input class="form-control form-control-sm" data-field="amount" data-index="${index}" type="number" step="0.01" value="${item.amount || 0}"></td>
        <td><input class="form-control form-control-sm" data-field="expense_date" data-index="${index}" value="${item.expense_date || ""}" placeholder="YYYY-01-01"></td>
        <td><input class="form-control form-control-sm" data-field="confidence" data-index="${index}" type="number" step="0.01" min="0" max="1" value="${item.confidence || 0.5}"></td>
      `;
      body.appendChild(row);
      return;
    }
    row.innerHTML = `
      <td><input class="form-control form-control-sm" data-field="category" data-index="${index}" value="${item.category || ""}"></td>
      <td><input class="form-control form-control-sm" data-field="supplier" data-index="${index}" value="${item.supplier || ""}"></td>
      <td><input class="form-control form-control-sm" data-field="description" data-index="${index}" value="${item.description || ""}"></td>
      <td><input class="form-control form-control-sm" data-field="amount" data-index="${index}" type="number" step="0.01" value="${item.amount || 0}"></td>
      <td><input class="form-control form-control-sm" data-field="invoice_number" data-index="${index}" value="${item.invoice_number || ""}"></td>
      <td><input class="form-control form-control-sm" data-field="expense_date" data-index="${index}" value="${item.expense_date || ""}" placeholder="YYYY-MM-DD"></td>
      <td><input class="form-control form-control-sm" data-field="confidence" data-index="${index}" type="number" step="0.01" min="0" max="1" value="${item.confidence || 0.5}"></td>
    `;
    body.appendChild(row);
  });
  updateSummary(items);
}

function collectDraftItemsFromForm() {
  const inputs = document.querySelectorAll("#draftItemsBody input[data-field]");
  const result = [...currentDraftItems].map((item) => ({ ...item }));
  inputs.forEach((input) => {
    const index = Number(input.getAttribute("data-index"));
    const field = input.getAttribute("data-field");
    if (field === "amount" || field === "confidence" || field === "budget_amount") {
      result[index][field] = Number(input.value || 0);
    } else {
      result[index][field] = input.value;
    }
  });
  updateSummary(result);
  return result;
}

function renderReviewDebug(debug) {
  const box = document.getElementById("reviewDebugBox");
  const statsEl = document.getElementById("reviewDebugStats");
  const rejectedEl = document.getElementById("reviewDebugRejected");
  const linkEl = document.getElementById("reviewDebugLink");
  if (!box || !statsEl || !rejectedEl || !linkEl) return;
  if (!debug) {
    box.classList.add("d-none");
    return;
  }
  const stats = debug.parserStats || {};
  const itemsCount = Number(debug.parserItemsCount || 0);
  const identification = debug.identification || null;
  statsEl.textContent = [
    `Sorgente testo: ${debug.textSource || "none"}`,
    `Caratteri testo: ${debug.textChars ?? 0}`,
    `Caratteri external: ${debug.externalChars ?? 0}`,
    `Caratteri internal: ${debug.internalChars ?? 0}`,
    `Caratteri parser: ${stats.textChars ?? 0}`,
    `Righe totali: ${stats.linesTotal ?? 0}`,
    `Righe vuote: ${stats.linesEmpty ?? 0}`,
    `Voci parser: ${itemsCount}`,
    `Match singola riga: ${stats.singleLineMatches ?? 0}`,
    `Match multi-riga: ${stats.multiLineMatches ?? 0}`,
    `Match euristico: ${stats.heuristicMatches ?? 0}`,
    `Scarti no-pattern: ${stats.rejectedNoPattern ?? 0}`,
    `Scarti categoria: ${stats.rejectedSkipCategory ?? 0}`,
    `Scarti importo zero: ${stats.rejectedZeroAmount ?? 0}`
  ].join(" | ");
  if (identification) {
    statsEl.textContent += ` | Check identificazione: ${identification.ok ? "OK" : "KO"} (${identification.score}) - ${identification.message}`;
  }
  const rejected = debug.parserRejectedSamples || [];
  rejectedEl.textContent = rejected.length
    ? `Righe scartate (sample):\n${JSON.stringify(rejected, null, 2)}`
    : "Nessuna riga scartata registrata.";
  if (debug.textPreview && debug.textPreview.length) {
    rejectedEl.textContent += `\n\nAnteprima testo (20 righe):\n${debug.textPreview.join("\n")}`;
  }
  if (debug.debugUrl) {
    linkEl.href = debug.debugUrl;
    linkEl.classList.remove("d-none");
  } else {
    linkEl.classList.add("d-none");
  }
  box.classList.remove("d-none");
}

async function loadDraft() {
  const draftId = document.getElementById("draftId").value;
  if (!draftId) {
    throw new Error("ID bozza mancante nell'URL");
  }
  const response = await fetch(`api.php?action=draft_get&id=${encodeURIComponent(draftId)}`);
  const payload = await response.json();
  if (!response.ok) {
    const message = (typeof normalizeApiError === "function")
      ? normalizeApiError(payload, "Errore caricamento bozza")
      : (payload.error || "Errore caricamento bozza");
    throw new Error(message);
  }
  currentDraftItems = payload.items || [];
  currentDocType = payload.draft.doc_type || "";
  renderDraftItems(currentDraftItems);
  renderReviewDebug(payload.debug || null);
  document.getElementById("draftInfo").textContent =
    `Documento: ${payload.draft.file_name} | Tipo: ${payload.draft.doc_type} | Anno: ${payload.draft.year}`;
}

document.addEventListener("input", (event) => {
  if (event.target && event.target.matches("#draftItemsBody input[data-field]")) {
    collectDraftItemsFromForm();
  }
});

document.getElementById("confirmDraftBtn").addEventListener("click", async () => {
  const draftId = document.getElementById("draftId").value;
  const items = collectDraftItemsFromForm();
  try {
    const payload = await api("draft_confirm", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: draftId, items })
    });
    document.getElementById("draftActionResult").className = "small text-success mb-3";
    document.getElementById("draftActionResult").textContent = payload.message;
    setTimeout(() => {
      window.location.href = "documents.php";
    }, 800);
  } catch (error) {
    document.getElementById("draftActionResult").className = "small text-danger mb-3";
    document.getElementById("draftActionResult").textContent = error.message;
  }
});

document.getElementById("cancelDraftBtn").addEventListener("click", async () => {
  const draftId = document.getElementById("draftId").value;
  const confirmed = window.confirm("Annullare la bozza? Le voci estratte non verranno salvate.");
  if (!confirmed) return;
  try {
    await api("draft_cancel", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: draftId })
    });
    window.location.href = "documents.php";
  } catch (error) {
    document.getElementById("draftActionResult").className = "small text-danger mb-3";
    document.getElementById("draftActionResult").textContent = error.message;
  }
});

loadDraft().catch((error) => {
  document.getElementById("draftActionResult").className = "small text-danger mb-3";
  document.getElementById("draftActionResult").textContent = error.message;
});
