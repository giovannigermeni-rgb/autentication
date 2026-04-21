<?php
// ============================================================
//  index.php  –  Pagina di login
// ============================================================
require_once 'config.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();

if (!empty($_SESSION['utente_id'])) {
    header('Location: dashboard.php');
    exit;
}

$errore  = '';
$successo = '';

// ─────────────────────────────────────────
//  Gestione POST
// ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $errore = 'Inserisci username e password.';
    } else {

        $db = getDB();

        $stmt = $db->prepare(
            "SELECT COUNT(*) AS cnt
               FROM log_accessi
              WHERE username_tentato = ?
                AND esito = 'fallito'
                AND data_ora >= DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $stmt->execute([$username, BLOCCO_DURATA]);
        $tentativi_recenti = (int) $stmt->fetchColumn();

        if ($tentativi_recenti >= MAX_TENTATIVI) {
            $errore = 'Troppi tentativi falliti. Riprova tra 15 minuti.';
            $db->prepare(
                "INSERT INTO log_accessi (username_tentato, esito, user_agent)
                 VALUES (?, 'fallito', ?)"
            )->execute([$username, $_SERVER['HTTP_USER_AGENT'] ?? null]);

        } else {
            $stmt = $db->prepare(
                "SELECT id, username, password, nome, attivo
                   FROM utenti
                  WHERE username = ?
                  LIMIT 1"
            );
            $stmt->execute([$username]);
            $utente = $stmt->fetch();

            if ($utente && $utente['attivo'] && password_verify($password, $utente['password'])) {

                session_regenerate_id(true);
                $_SESSION['utente_id'] = $utente['id'];
                $_SESSION['username']  = $utente['username'];
                $_SESSION['nome']      = $utente['nome'];
                $_SESSION['login_ora'] = time();
                $_SESSION['is_admin']  = isAdminAccount($db, (int) $utente['id'], $utente['username']);

                $db->prepare(
                    "INSERT INTO log_accessi (utente_id, username_tentato, esito, user_agent)
                     VALUES (?, ?, 'successo', ?)"
                )->execute([$utente['id'], $username, $_SERVER['HTTP_USER_AGENT'] ?? null]);

                header('Location: dashboard.php');
                exit;

            } else {
                $errore = 'Username o password errati.';

                $utente_id = $utente ? $utente['id'] : null;
                $db->prepare(
                    "INSERT INTO log_accessi (utente_id, username_tentato, esito, user_agent)
                     VALUES (?, ?, 'fallito', ?)"
                )->execute([$utente_id, $username, $_SERVER['HTTP_USER_AGENT'] ?? null]);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Accesso — Login Tracker</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/index.css">
</head>
<body>

  <div class="login-wrapper">

    <div class="login-brand">
      <span class="brand-name">LoginTracker</span>
      <span class="brand-sub">v2</span>
    </div>

    <div class="login-card">
      <div>
        <h1>Bentornato</h1>
        <p class="subtitle">Inserisci le tue credenziali per accedere</p>
      </div>

      <?php if ($errore): ?>
        <div class="alert alert-error">
          <span class="alert-icon">✕</span>
          <?= htmlspecialchars($errore) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="" novalidate>
        <div class="fields">

          <div class="field">
            <label for="username">Username</label>
            <input
              type="text"
              id="username"
              name="username"
              autocomplete="username"
              spellcheck="false"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
              placeholder="es. mario.rossi"
              required
            >
          </div>

          <div class="field">
            <label for="password">
              Password
              <button type="button" class="toggle-pw" aria-label="Mostra/nascondi password"
                onclick="togglePassword()">mostra</button>
            </label>
            <input
              type="password"
              id="password"
              name="password"
              autocomplete="current-password"
              placeholder="••••••••"
              required
            >
          </div>

        </div>

        <button type="submit" class="btn-primary">Accedi</button>
      </form>
    </div>

  </div>

  <script>
    function togglePassword() {
      const inp = document.getElementById('password');
      const btn = document.querySelector('.toggle-pw');
      if (inp.type === 'password') {
        inp.type = 'text';
        btn.textContent = 'nascondi';
      } else {
        inp.type = 'password';
        btn.textContent = 'mostra';
      }
    }
  </script>

</body>
</html>
