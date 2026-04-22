// Azioni topbar.
document.getElementById("logoutBtn").addEventListener("click", doLogout);

function setActionMessage(message, isError = false) {
  const el = document.getElementById("documentsActionResult");
  el.className = isError ? "small text-danger mb-3" : "small text-success mb-3";
  el.textContent = message;
}

function renderGroup(title, docs) {
  const wrapper = document.createElement("div");
  wrapper.className = "card mb-3";
  wrapper.innerHTML = `<div class="card-header">${title} (${docs.length})</div>`;
  const body = document.createElement("div");
  body.className = "card-body";

  if (!docs.length) {
    body.innerHTML = "<p class='mb-0'>Nessun documento in questa tipologia.</p>";
    wrapper.appendChild(body);
    return wrapper;
  }

  const table = document.createElement("table");
  table.className = "table table-sm table-striped";
  table.innerHTML = `
    <thead>
      <tr>
        <th>File</th>
        <th>Ambito</th>
        <th>Anno</th>
        <th>Stato</th>
        <th>Ultima rianalisi AI</th>
        <th>Review</th>
        <th>Azioni</th>
      </tr>
    </thead>
    <tbody></tbody>
  `;
  const tbody = table.querySelector("tbody");
  docs.forEach((doc) => {
    const tr = document.createElement("tr");
    const statusLabel = `${doc.extraction_status} (${Number(doc.confidence || 0).toFixed(2)})`;
    const lastAiReprocess = doc.last_ai_reprocess_at ? doc.last_ai_reprocess_at : "-";
    tr.innerHTML = `
      <td>${doc.file_name}</td>
      <td>${doc.scope}</td>
      <td>${doc.year}</td>
      <td>${statusLabel}</td>
      <td>${lastAiReprocess}</td>
      <td>${doc.needs_review ? "Da rivedere" : "OK"}</td>
      <td>
        <a class="btn btn-sm btn-outline-primary" target="_blank" href="download.php?id=${doc.id}">Apri</a>
        <a class="btn btn-sm btn-outline-secondary ml-1" href="extraction-debug.php?id=${doc.id}">Debug estrazione</a>
        <button class="btn btn-sm btn-outline-info ml-1 ai-review-btn" data-id="${doc.id}" data-name="${doc.file_name}">Rivedi con AI</button>
        <button class="btn btn-sm btn-outline-danger ml-1 delete-doc-btn" data-id="${doc.id}" data-name="${doc.file_name}">Elimina</button>
      </td>
    `;
    tbody.appendChild(tr);
  });
  body.appendChild(table);
  wrapper.appendChild(body);
  return wrapper;
}

async function loadDocuments() {
  const scope = document.getElementById("scopeFilter").value;
  const year = document.getElementById("yearFilter").value;
  const query = new URLSearchParams();
  if (scope) query.set("scope", scope);
  if (year) query.set("year", year);

  const response = await fetch(`api.php?action=documents_list&${query.toString()}`);
  const payload = await response.json();
  if (!response.ok) {
    const message = (typeof normalizeApiError === "function")
      ? normalizeApiError(payload, "Errore caricamento archivio")
      : (payload.error || "Errore caricamento archivio");
    throw new Error(message);
  }

  const groupsRoot = document.getElementById("documentsGroups");
  groupsRoot.innerHTML = "";
  groupsRoot.appendChild(renderGroup("Bilanci", payload.groups.bilancio || []));
  groupsRoot.appendChild(renderGroup("Consuntivi", payload.groups.consuntivo || []));
  groupsRoot.appendChild(renderGroup("Fatture", payload.groups.fattura || []));
  groupsRoot.appendChild(renderGroup("Altro", payload.groups.altro || []));
  document.getElementById("documentsResult").textContent = `Documenti trovati: ${payload.count}`;

  groupsRoot.querySelectorAll(".delete-doc-btn").forEach((button) => {
    button.addEventListener("click", async () => {
      const documentId = button.getAttribute("data-id");
      const fileName = button.getAttribute("data-name") || "questo documento";
      const confirmed = window.confirm(`Confermi eliminazione di "${fileName}"? Questa azione non è reversibile.`);
      if (!confirmed) return;
      try {
        await api("documents_delete", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ id: documentId })
        });
        setActionMessage(`Documento "${fileName}" eliminato.`);
        await loadDocuments();
      } catch (error) {
        setActionMessage(error.message, true);
      }
    });
  });

  groupsRoot.querySelectorAll(".ai-review-btn").forEach((button) => {
    button.addEventListener("click", async () => {
      const documentId = button.getAttribute("data-id");
      const fileName = button.getAttribute("data-name") || "questo documento";
      const confirmed = window.confirm(`Rianalizzare con AI il documento "${fileName}"? Le voci attuali verranno aggiornate.`);
      if (!confirmed) return;
      const previousLabel = button.textContent;
      button.disabled = true;
      button.textContent = "Analisi...";
      showGlobalLoader("Rianalisi AI del documento in corso...");
      try {
        const result = await api("documents_ai_reprocess", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ id: documentId })
        });
        setActionMessage(`${result.message} (${result.extractedItems} voci)`);
        if (result.reviewUrl) {
          window.location.href = result.reviewUrl;
          return;
        }
        await loadDocuments();
      } catch (error) {
        setActionMessage(error.message, true);
      } finally {
        hideGlobalLoader();
        button.disabled = false;
        button.textContent = previousLabel;
      }
    });
  });
}

document.getElementById("refreshDocumentsBtn").addEventListener("click", async () => {
  try {
    await loadDocuments();
  } catch (error) {
    document.getElementById("documentsResult").textContent = error.message;
  }
});

loadDocuments().catch((error) => {
  document.getElementById("documentsResult").textContent = error.message;
});
