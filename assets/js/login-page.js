// Wizard primo avvio: crea l'utente amministratore.
document.getElementById("setupForm").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = new FormData(e.target);
  try {
    const payload = await api("setup_admin", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        fullName: form.get("fullName"),
        email: form.get("email"),
        password: form.get("password")
      })
    });
    document.getElementById("setupResult").textContent = payload.message;
    document.getElementById("setupCard").style.display = "none";
    document.getElementById("loginCard").style.display = "block";
  } catch (error) {
    document.getElementById("setupResult").textContent = error.message;
  }
});

// Login utente e redirect alla dashboard.
document.getElementById("loginForm").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = new FormData(e.target);
  try {
    await api("login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        email: form.get("email"),
        password: form.get("password")
      })
    });
    window.location.href = "dashboard.php";
  } catch (error) {
    document.getElementById("loginResult").textContent = error.message;
  }
});

// Decide se mostrare o meno il setup iniziale.
async function bootstrapLoginPage() {
  const status = await api("setup_status");
  if (status.setupRequired) {
    document.getElementById("setupCard").style.display = "block";
  } else {
    document.getElementById("setupCard").style.display = "none";
  }
}

bootstrapLoginPage().catch((error) => {
  document.getElementById("loginResult").textContent = error.message;
});
