<?php
// ============================================================
//  dashboard.php  –  Dashboard utente
// ============================================================
require_once 'config.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();

if (empty($_SESSION['utente_id'])) {
    header('Location: index.php');
    exit;
}

if (isset($_SESSION['login_ora']) && (time() - $_SESSION['login_ora']) > SESSION_LIFETIME) {
    session_destroy();
    header('Location: index.php?timeout=1');
    exit;
}

$db        = getDB();
$utente_id = (int) $_SESSION['utente_id'];
$nome      = $_SESSION['nome'];
$username  = $_SESSION['username'] ?? null;
$isAdmin   = $_SESSION['is_admin'] ?? isAdminAccount($db, $utente_id, $username);
$_SESSION['is_admin'] = $isAdmin;

if ($isAdmin) {
    header('Location: admin.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function formatDurata(int $secondi): string {
    $h = intdiv($secondi, 3600);
    $m = intdiv($secondi % 3600, 60);
    $s = $secondi % 60;
    if ($h > 0) return sprintf('%dh %02dm %02ds', $h, $m, $s);
    if ($m > 0) return sprintf('%dm %02ds', $m, $s);
    return sprintf('%ds', $s);
}

$timbratureIsSessionBased = tableHasColumn($db, 'timbrature', 'entrata_il');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione'])) {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Richiesta non valida.');
    }

    $azione = $_POST['azione'] === 'entrata' ? 'entrata' : 'uscita';

    if ($timbratureIsSessionBased) {
        if ($azione === 'entrata') {
            $stmtCheck = $db->prepare(
                "SELECT id
                   FROM timbrature
                  WHERE utente_id = ? AND uscita_il IS NULL
                  ORDER BY entrata_il DESC
                  LIMIT 1"
            );
            $stmtCheck->execute([$utente_id]);
            $openSession = $stmtCheck->fetch();

            if ($openSession) {
                header('Location: dashboard.php');
                exit;
            }

            $stmt = $db->prepare(
                "INSERT INTO timbrature (utente_id, entrata_il)
                 VALUES (?, NOW())"
            );
            $stmt->execute([$utente_id]);
        } else {
            $stmtOpen = $db->prepare(
                "SELECT id, TIMESTAMPDIFF(SECOND, entrata_il, NOW()) AS durata_sec
                   FROM timbrature
                  WHERE utente_id = ? AND uscita_il IS NULL
                  ORDER BY entrata_il DESC
                  LIMIT 1"
            );
            $stmtOpen->execute([$utente_id]);
            $openSession = $stmtOpen->fetch();

            if (!$openSession) {
                header('Location: dashboard.php');
                exit;
            }

            $stmt = $db->prepare(
                "UPDATE timbrature
                    SET uscita_il = NOW(),
                        durata = ?
                  WHERE id = ?"
            );
            $stmt->execute([max(0, (int) $openSession['durata_sec']), (int) $openSession['id']]);
        }
    } else {
        $durata = null;

        if ($azione === 'uscita') {
            $stmtLastEntry = $db->prepare(
                "SELECT TIMESTAMPDIFF(SECOND, data_ora, NOW()) AS durata_sec
                   FROM timbrature
                  WHERE utente_id = ? AND tipo = 'entrata'
                  ORDER BY data_ora DESC
                  LIMIT 1"
            );
            $stmtLastEntry->execute([$utente_id]);
            $lastEntry = $stmtLastEntry->fetch();

            if ($lastEntry) {
                $durata = max(0, (int) $lastEntry['durata_sec']);
            }
        } else {
            $stmtCheck = $db->prepare(
                "SELECT tipo FROM timbrature
                  WHERE utente_id = ?
                  ORDER BY data_ora DESC LIMIT 1"
            );
            $stmtCheck->execute([$utente_id]);
            $last = $stmtCheck->fetch();
            if ($last && $last['tipo'] === 'entrata') {
                header('Location: dashboard.php');
                exit;
            }
        }

        $stmt = $db->prepare(
            "INSERT INTO timbrature (utente_id, tipo, data_ora, durata)
             VALUES (?, ?, NOW(), ?)"
        );
        $stmt->execute([$utente_id, $azione, $durata]);
    }

    header('Location: dashboard.php');
    exit;
}

$stmt = $db->prepare(
    "SELECT esito, data_ora, user_agent
       FROM log_accessi
      WHERE utente_id = ?
      ORDER BY data_ora DESC
      LIMIT 100"
);
$stmt->execute([$utente_id]);
$accessi = $stmt->fetchAll();

$stmt2 = $db->prepare(
    "SELECT
         COUNT(*) AS totale,
         SUM(esito = 'successo') AS successi,
         SUM(esito = 'fallito') AS falliti,
         MAX(CASE WHEN esito = 'successo' THEN data_ora END) AS ultimo_accesso
       FROM log_accessi
      WHERE utente_id = ?"
);
$stmt2->execute([$utente_id]);
$stats = $stmt2->fetch();

if ($timbratureIsSessionBased) {
    $stmt3 = $db->prepare(
        "SELECT entrata_il, uscita_il, durata
           FROM timbrature
          WHERE utente_id = ?
          ORDER BY entrata_il DESC
          LIMIT 1"
    );
    $stmt3->execute([$utente_id]);
    $ultima = $stmt3->fetch();

    $dentro = $ultima && empty($ultima['uscita_il']);

    $stmt4 = $db->prepare(
        "SELECT entrata_il, uscita_il, durata
           FROM timbrature
          WHERE utente_id = ?
          ORDER BY entrata_il DESC
          LIMIT 50"
    );
    $stmt4->execute([$utente_id]);
    $timbrature = $stmt4->fetchAll();
} else {
    $stmt3 = $db->prepare(
        "SELECT tipo, data_ora, durata
           FROM timbrature
          WHERE utente_id = ?
          ORDER BY data_ora DESC
          LIMIT 1"
    );
    $stmt3->execute([$utente_id]);
    $ultima = $stmt3->fetch();

    $dentro = $ultima && $ultima['tipo'] === 'entrata';

    $stmt4 = $db->prepare(
        "SELECT tipo, data_ora, durata
           FROM timbrature
          WHERE utente_id = ?
          ORDER BY data_ora DESC
          LIMIT 50"
    );
    $stmt4->execute([$utente_id]);
    $timbrature = $stmt4->fetchAll();
}

$iniziale = mb_strtoupper(mb_substr($nome, 0, 1));
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Login Tracker</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:ital,wght@0,300;0,400;0,500;1,400&family=Instrument+Serif:ital@0;1&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/dashboard.css">
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <a href="dashboard.php" class="brand">
      <span class="brand-word">LoginTracker</span>
      <span class="brand-sub">v2</span>
    </a>
    <div class="topbar-right">
      <div class="avatar"><?= htmlspecialchars($iniziale) ?></div>
      <span class="topbar-name"><?= htmlspecialchars($nome) ?></span>
      <a href="logout.php" class="btn-exit">Esci</a>
    </div>
  </div>
</header>

<main class="wrap">
  <div class="page-header fade-up">
    <h1 class="page-title">Ciao, <em><?= htmlspecialchars($nome) ?></em></h1>
    <div class="page-date">
      <?= date('l') ?><br>
      <?= date('d.m.Y') ?>
    </div>
  </div>

  <section class="fade-up">
    <div class="section-label">Timbratura</div>
    <div class="badging">
      <div class="badging-left">
        <div class="badging-status">
          <span class="pulse-dot <?= $dentro ? 'in' : 'out' ?>"></span>
          <span class="badging-text">
            <?= $dentro ? 'Sei in sede' : 'Sei fuori sede' ?>
          </span>
        </div>

        <?php if ($ultima): ?>
        <div class="badging-meta">
          <?php if ($timbratureIsSessionBased): ?>
            Entrata il <?= date('d/m/Y', strtotime($ultima['entrata_il'])) ?>
            alle <?= date('H:i:s', strtotime($ultima['entrata_il'])) ?>
            <?php if (!empty($ultima['uscita_il'])): ?>
            <span class="sep">·</span>
            Uscita alle <?= date('H:i:s', strtotime($ultima['uscita_il'])) ?>
            <?php endif; ?>
            <?php if (!empty($ultima['durata'])): ?>
            <span class="sep">·</span>
            Durata <?= formatDurata((int) $ultima['durata']) ?>
            <?php endif; ?>
          <?php else: ?>
            Ultima timbratura:
            <strong><?= ucfirst($ultima['tipo']) ?></strong>
            il <?= date('d/m/Y', strtotime($ultima['data_ora'])) ?>
            alle <?= date('H:i:s', strtotime($ultima['data_ora'])) ?>
            <?php if ($ultima['tipo'] === 'uscita' && $ultima['durata']): ?>
            <span class="sep">·</span>
            Durata <?= formatDurata((int) $ultima['durata']) ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="badging-meta">Nessuna timbratura registrata.</div>
        <?php endif; ?>
      </div>

      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <?php if ($dentro): ?>
          <input type="hidden" name="azione" value="uscita">
          <button type="submit" class="btn-stamp uscita">
            <span class="btn-icon">↓</span> Timbra uscita
          </button>
        <?php else: ?>
          <input type="hidden" name="azione" value="entrata">
          <button type="submit" class="btn-stamp entrata">
            <span class="btn-icon">↑</span> Timbra entrata
          </button>
        <?php endif; ?>
      </form>
    </div>
  </section>

  <?php if (!empty($timbrature)): ?>
  <section class="fade-up">
    <div class="section-label">Sessioni di timbrature</div>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Data</th>
            <th>Entrata</th>
            <th>Uscita</th>
            <th>Durata sessione</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($timbrature as $i => $row): ?>
          <tr>
            <td class="td-num"><?= $i + 1 ?></td>
            <?php if ($timbratureIsSessionBased): ?>
            <td class="td-mono"><?= date('d/m/Y', strtotime($row['entrata_il'])) ?></td>
            <td class="td-mono"><?= date('H:i:s', strtotime($row['entrata_il'])) ?></td>
            <td>
              <?php if (!empty($row['uscita_il'])): ?>
                <span class="td-mono"><?= date('H:i:s', strtotime($row['uscita_il'])) ?></span>
              <?php else: ?>
                <span class="pill in">In corso</span>
              <?php endif; ?>
            </td>
            <?php else: ?>
            <td class="td-mono"><?= date('d/m/Y', strtotime($row['data_ora'])) ?></td>
            <td>
              <?php if ($row['tipo'] === 'entrata'): ?>
                <span class="td-mono"><?= date('H:i:s', strtotime($row['data_ora'])) ?></span>
              <?php else: ?>
                <span class="td-dash">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($row['tipo'] === 'uscita'): ?>
                <span class="td-mono"><?= date('H:i:s', strtotime($row['data_ora'])) ?></span>
              <?php else: ?>
                <span class="td-dash">—</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <td>
              <?php if (!empty($row['durata'])): ?>
                <span class="td-mono"><?= formatDurata((int) $row['durata']) ?></span>
              <?php else: ?>
                <span class="td-dash">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>
</main>
</body>
</html>
