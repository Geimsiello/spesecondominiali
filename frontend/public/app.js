const tokenKey = "spese_token";

function getToken() {
  return localStorage.getItem(tokenKey);
}

async function api(path, options = {}) {
  const headers = options.headers || {};
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  const response = await fetch(path, { ...options, headers });
  const payload = await response.json();
  if (!response.ok) throw new Error(payload.error || "Errore API");
  return payload;
}

async function login(email, password) {
  const response = await fetch("/api/auth/login", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password })
  });
  const payload = await response.json();
  if (!response.ok) throw new Error(payload.error || "Login fallito");
  localStorage.setItem(tokenKey, payload.token);
  document.getElementById("userName").textContent = payload.user.name;
  document.getElementById("loginCard").style.display = "none";
  document.getElementById("appPanels").style.display = "block";
}

async function createAdmin(fullName, email, password) {
  const response = await fetch("/api/setup/admin", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ fullName, email, password })
  });
  const payload = await response.json();
  if (!response.ok) throw new Error(payload.error || "Creazione admin fallita");
  return payload;
}

function renderReviewItems(items) {
  const container = document.getElementById("reviewItems");
  container.innerHTML = "";
  if (items.length === 0) {
    container.innerHTML = "<p>Nessuna voce da revisionare.</p>";
    return;
  }
  items.forEach((item) => {
    const card = document.createElement("div");
    card.className = "card mb-2";
    card.innerHTML = `
      <div class="card-body">
        <div><strong>${item.file_name}</strong> - € ${item.amount}</div>
        <div class="small text-muted mb-2">Categoria: ${item.category} | Fornitore: ${item.supplier || "N/D"}</div>
        <button class="btn btn-sm btn-primary">Segna come rivista</button>
      </div>
    `;
    card.querySelector("button").addEventListener("click", async () => {
      await api(`/api/review/items/${item.id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ category: item.category })
      });
      card.remove();
    });
    container.appendChild(card);
  });
}

document.getElementById("loginForm").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = new FormData(event.target);
  try {
    await login(form.get("email"), form.get("password"));
    document.getElementById("refreshAnalyticsBtn").click();
  } catch (error) {
    alert(error.message);
  }
});

document.getElementById("setupForm").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = new FormData(event.target);
  try {
    const payload = await createAdmin(
      form.get("fullName"),
      form.get("email"),
      form.get("password")
    );
    document.getElementById("setupResult").textContent = payload.message;
    document.getElementById("setupCard").style.display = "none";
    document.getElementById("loginCard").style.display = "block";
  } catch (error) {
    alert(error.message);
  }
});

document.getElementById("logoutBtn").addEventListener("click", () => {
  localStorage.removeItem(tokenKey);
  location.reload();
});

document.getElementById("uploadForm").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = new FormData(event.target);
  try {
    const payload = await api("/api/documents/upload", {
      method: "POST",
      body: form
    });
    document.getElementById("uploadResult").textContent = JSON.stringify(payload, null, 2);
  } catch (error) {
    alert(error.message);
  }
});

document.getElementById("refreshAnalyticsBtn").addEventListener("click", async () => {
  const year = document.getElementById("yearFilter").value;
  const scope = document.getElementById("scopeFilter").value;
  const query = new URLSearchParams();
  if (year) query.set("year", year);
  query.set("scope", scope);
  try {
    const payload = await api(`/api/analytics/summary?${query.toString()}`);
    document.getElementById("analyticsOutput").textContent = JSON.stringify(payload, null, 2);
  } catch (error) {
    document.getElementById("analyticsOutput").textContent = error.message;
  }
});

document.getElementById("loadReviewBtn").addEventListener("click", async () => {
  try {
    const payload = await api("/api/review/items");
    renderReviewItems(payload.items);
  } catch (error) {
    alert(error.message);
  }
});

async function bootstrapSession() {
  try {
    const status = await fetch("/api/setup/status").then((res) => res.json());
    if (status.setupRequired) {
      document.getElementById("setupCard").style.display = "block";
      document.getElementById("loginCard").style.display = "none";
      document.getElementById("appPanels").style.display = "none";
      return;
    }
    document.getElementById("setupCard").style.display = "none";
    document.getElementById("loginCard").style.display = "block";
  } catch {
    document.getElementById("setupCard").style.display = "none";
    document.getElementById("loginCard").style.display = "block";
  }

  if (!getToken()) return;
  try {
    const me = await api("/api/auth/me");
    document.getElementById("userName").textContent = me.user.name;
    document.getElementById("loginCard").style.display = "none";
    document.getElementById("appPanels").style.display = "block";
    document.getElementById("refreshAnalyticsBtn").click();
  } catch {
    localStorage.removeItem(tokenKey);
  }
}

bootstrapSession();
