// Client HTTP condiviso: centralizza serializzazione errori API.
function normalizeApiError(payload, fallbackMessage = "Errore API") {
  if (!payload || typeof payload !== "object") return fallbackMessage;
  if (typeof payload.error === "string") return payload.error;
  if (payload.error && typeof payload.error === "object") {
    const code = payload.error.code ? `[${payload.error.code}] ` : "";
    const message = payload.error.message || fallbackMessage;
    return `${code}${message}`;
  }
  return fallbackMessage;
}

let globalLoaderCount = 0;

function ensureGlobalLoader() {
  let loader = document.getElementById("globalProcessingLoader");
  if (loader) return loader;

  loader = document.createElement("div");
  loader.id = "globalProcessingLoader";
  loader.className = "global-processing-loader d-none";
  loader.innerHTML = `
    <div class="global-processing-loader__backdrop"></div>
    <div class="global-processing-loader__content card shadow">
      <div class="card-body d-flex align-items-center">
        <div class="spinner-border text-primary mr-3" role="status" aria-hidden="true"></div>
        <div>
          <div class="font-weight-bold">Analisi in corso...</div>
          <div id="globalProcessingLoaderMessage" class="small text-muted">Attendere, elaborazione documento AI.</div>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(loader);
  return loader;
}

function showGlobalLoader(message = "Attendere, elaborazione documento AI.") {
  const loader = ensureGlobalLoader();
  globalLoaderCount += 1;
  const messageEl = document.getElementById("globalProcessingLoaderMessage");
  if (messageEl) {
    messageEl.textContent = message;
  }
  loader.classList.remove("d-none");
}

function hideGlobalLoader() {
  const loader = document.getElementById("globalProcessingLoader");
  if (!loader) return;
  globalLoaderCount = Math.max(0, globalLoaderCount - 1);
  if (globalLoaderCount === 0) {
    loader.classList.add("d-none");
  }
}

async function api(action, options = {}) {
  const response = await fetch(`api.php?action=${encodeURIComponent(action)}`, options);
  const payload = await response.json();
  if (!response.ok) {
    throw new Error(normalizeApiError(payload));
  }
  return payload;
}

// Logout centralizzato usato da tutte le pagine protette.
async function doLogout() {
  await api("logout", { method: "POST" });
  window.location.href = "login.php";
}
