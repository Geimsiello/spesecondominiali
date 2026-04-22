// Client HTTP condiviso: centralizza serializzazione errori API.
async function api(action, options = {}) {
  const response = await fetch(`api.php?action=${encodeURIComponent(action)}`, options);
  const payload = await response.json();
  if (!response.ok) {
    throw new Error(payload.error || "Errore API");
  }
  return payload;
}

// Logout centralizzato usato da tutte le pagine protette.
async function doLogout() {
  await api("logout", { method: "POST" });
  window.location.href = "login.php";
}
