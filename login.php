<?php
declare(strict_types=1);
session_start();
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login - Spese Condominiali</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/startbootstrap-sb-admin-2@4.1.4/css/sb-admin-2.min.css" />
  <link rel="stylesheet" href="assets/css/app.css" />
</head>
<body class="bg-gradient-primary">
<div class="container">
  <div class="row justify-content-center">
    <div class="col-xl-8 col-lg-10 col-md-9">
      <div class="card o-hidden border-0 shadow-lg my-5">
        <div class="card-body p-5">
          <div class="text-center mb-4">
            <h1 class="h4 text-gray-900">Spese Condominiali</h1>
            <p class="small text-muted mb-0">Accesso al portale SB Admin 2</p>
          </div>

          <div id="setupCard" style="display:none">
            <h2 class="h5 text-gray-900">Setup iniziale amministratore</h2>
            <form id="setupForm" class="user mt-3" method="post" autocomplete="on">
              <div class="form-group">
                <input class="form-control form-control-user" name="fullName" autocomplete="name" placeholder="Nome completo admin" required />
              </div>
              <div class="form-group">
                <input class="form-control form-control-user" type="email" name="email" autocomplete="email" placeholder="Email admin" required />
              </div>
              <div class="form-group">
                <input class="form-control form-control-user" type="password" name="password" autocomplete="new-password" minlength="8" placeholder="Password min 8 caratteri" required />
              </div>
              <button class="btn btn-primary btn-user btn-block">Crea admin</button>
            </form>
            <div id="setupResult" class="small text-muted mt-2"></div>
            <hr />
          </div>

          <div id="loginCard">
            <form id="loginForm" class="user" method="post" autocomplete="on">
              <div class="form-group">
                <input class="form-control form-control-user" type="email" name="email" autocomplete="username" placeholder="Email" required />
              </div>
              <div class="form-group">
                <input class="form-control form-control-user" type="password" name="password" autocomplete="current-password" placeholder="Password" required />
              </div>
              <div class="form-group">
                <div class="custom-control custom-checkbox small">
                  <input class="custom-control-input" id="rememberMe" type="checkbox" name="remember" value="1" />
                  <label class="custom-control-label" for="rememberMe">Ricordami</label>
                </div>
              </div>
              <button class="btn btn-primary btn-user btn-block">Accedi</button>
            </form>
            <div id="loginResult" class="small text-danger mt-2"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="assets/js/api-client.js"></script>
<script src="assets/js/login-page.js"></script>
</body>
</html>
