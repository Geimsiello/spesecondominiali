// Azioni topbar.
document.getElementById("logoutBtn").addEventListener("click", doLogout);

// Recupera le voci marcate per revisione manuale.
async function loadReviewItems() {
  const payload = await api("review_items");
  const container = document.getElementById("reviewItems");
  container.innerHTML = "";
  if (!payload.items.length) {
    container.innerHTML = "<p>Nessuna voce da revisionare.</p>";
    return;
  }
  payload.items.forEach((item) => {
    const card = document.createElement("div");
    card.className = "card mb-2";
    card.innerHTML = `<div class="card-body"><div><strong>${item.file_name}</strong> - € ${item.amount}</div><div class="small text-muted mb-2">${item.description || ""}</div><button class="btn btn-sm btn-primary">Segna come rivista</button></div>`;
    card.querySelector("button").addEventListener("click", async () => {
      await api("review_update", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: item.id, category: item.category, amount: item.amount })
      });
      card.remove();
    });
    container.appendChild(card);
  });
}

// Ricarica manuale elenco.
document.getElementById("loadReviewBtn").addEventListener("click", async () => {
  try {
    await loadReviewItems();
  } catch (error) {
    document.getElementById("reviewItems").textContent = error.message;
  }
});

loadReviewItems().catch((error) => {
  document.getElementById("reviewItems").textContent = error.message;
});
