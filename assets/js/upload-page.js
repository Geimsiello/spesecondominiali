// Azioni topbar.
document.getElementById("logoutBtn").addEventListener("click", doLogout);

// Upload documento PDF con metadati di classificazione.
document.getElementById("uploadForm").addEventListener("submit", async (e) => {
  e.preventDefault();
  try {
    const payload = await api("upload", { method: "POST", body: new FormData(e.target) });
    document.getElementById("uploadResult").textContent = JSON.stringify(payload, null, 2);
  } catch (error) {
    document.getElementById("uploadResult").textContent = error.message;
  }
});
