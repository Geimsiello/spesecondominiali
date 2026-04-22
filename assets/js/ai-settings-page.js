// Azioni topbar.
document.getElementById("logoutBtn").addEventListener("click", doLogout);

// Popola il menu modello mantenendo il valore salvato.
function setModelOptions(models, selectedValue) {
  const select = document.getElementById("ollamaModelInput");
  select.innerHTML = "";
  if (!models.length) {
    const option = document.createElement("option");
    option.value = selectedValue || "";
    option.textContent = selectedValue || "Nessun modello disponibile";
    select.appendChild(option);
    return;
  }
  models.forEach((modelName) => {
    const option = document.createElement("option");
    option.value = modelName;
    option.textContent = modelName;
    if (selectedValue && modelName === selectedValue) {
      option.selected = true;
    }
    select.appendChild(option);
  });
  if (selectedValue && !models.includes(selectedValue)) {
    const customOption = document.createElement("option");
    customOption.value = selectedValue;
    customOption.textContent = `${selectedValue} (non presente in elenco)`;
    customOption.selected = true;
    select.appendChild(customOption);
  }
}

// Legge i modelli disponibili dall'endpoint Ollama configurato.
async function loadModels(selectedValue = "") {
  const payload = await api("ai_models_list", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      ollamaUrl: document.getElementById("ollamaUrlInput").value,
      ollamaApiKey: document.getElementById("ollamaApiKeyInput").value
    })
  });
  setModelOptions(payload.models || [], selectedValue);
  document.getElementById("aiSettingsResult").textContent = "Lista modelli caricata con successo.";
}

// Salva configurazione AI corrente.
document.getElementById("aiSettingsForm").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = new FormData(e.target);
  const payload = await api("ai_settings_save", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      ollamaUrl: form.get("ollamaUrl"),
      ollamaModel: form.get("ollamaModel"),
      ollamaApiKey: form.get("ollamaApiKey"),
      aiContextMode: form.get("aiContextMode")
    })
  });
  document.getElementById("aiSettingsResult").textContent = payload.message;
});

// Testa raggiungibilita endpoint AI.
document.getElementById("testAiBtn").addEventListener("click", async () => {
  try {
    const payload = await api("ai_settings_test", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        ollamaUrl: document.getElementById("ollamaUrlInput").value,
        ollamaApiKey: document.getElementById("ollamaApiKeyInput").value
      })
    });
    document.getElementById("aiSettingsResult").textContent = payload.message;
  } catch (error) {
    document.getElementById("aiSettingsResult").textContent = error.message;
  }
});

// Ricarica elenco modelli su richiesta utente.
document.getElementById("loadModelsBtn").addEventListener("click", async () => {
  try {
    const current = document.getElementById("ollamaModelInput").value || "";
    await loadModels(current);
  } catch (error) {
    document.getElementById("aiSettingsResult").textContent = error.message;
  }
});

// Inizializza form con valori da DB e lista modelli.
async function bootstrapAiSettings() {
  const payload = await api("ai_settings_get");
  document.getElementById("ollamaUrlInput").value = payload.ollamaUrl || "";
  document.getElementById("ollamaApiKeyInput").value = payload.ollamaApiKey || "";
  document.getElementById("aiContextModeInput").value = payload.aiContextMode || "compact";
  try {
    await loadModels(payload.ollamaModel || "");
  } catch {
    setModelOptions([], payload.ollamaModel || "");
  }
  document.getElementById("aiSettingsResult").textContent = payload.updatedAt
    ? `Ultimo aggiornamento: ${payload.updatedAt}`
    : "Impostazioni AI caricate.";
}

bootstrapAiSettings().catch((error) => {
  document.getElementById("aiSettingsResult").textContent = error.message;
});
