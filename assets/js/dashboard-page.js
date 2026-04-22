// Azioni topbar.
document.getElementById("logoutBtn").addEventListener("click", doLogout);

// Carica KPI annuali filtrati per scope/anno.
document.getElementById("refreshAnalyticsBtn").addEventListener("click", async () => {
  const year = document.getElementById("yearFilter").value;
  const scope = document.getElementById("scopeFilter").value;
  const query = new URLSearchParams({ scope });
  if (year) query.set("year", year);
  const response = await fetch(`api.php?action=analytics_summary&${query.toString()}`);
  const payload = await response.json();
  if (!response.ok) {
    document.getElementById("analyticsOutput").textContent = payload.error || "Errore API";
    return;
  }
  document.getElementById("analyticsOutput").textContent = JSON.stringify(payload, null, 2);
});

document.getElementById("refreshAnalyticsBtn").click();
