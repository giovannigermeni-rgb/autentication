<?php
// ============================================================
//  dashboard.php  –  Area utente + storico accessi + timbratura
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

// ─────────────────────────────────────────
//  CSRF token
// ─────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ─────────────────────────────────────────
//  Helper: formatta durata in secondi → Xh Ym Zs
// ─────────────────────────────────────────
function formatDurata(int $secondi): string {
    $h = intdiv($secondi, 3600);
    $m = intdiv($secondi % 3600, 60);
    $s = $secondi % 60;
    if ($h > 0) return sprintf('%dh %02dm %02ds', $h, $m, $s);
    if ($m > 0) return sprintf('%dm %02ds', $m, $s);
    return sprintf('%ds', $s);
}

// ─────────────────────────────────────────
//  Gestione POST: registra una timbratura
// ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione'])) {

    // CSRF check
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Richiesta non valida.');
    }

    $azione = $_POST['azione'] === 'entrata' ? 'entrata' : 'uscita';
    $durata = null;

    if ($azione === 'uscita') {
        // ── FIX TIMEZONE ──────────────────────────────────────────
        //  Usando TIMESTAMPDIFF interamente in MySQL si evita lo scarto
        //  di +2h che si ottiene con time() [UTC] - strtotime() [locale].
        // ──────────────────────────────────────────────────────────
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
        // Evita doppie entrate consecutive
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

    header('Location: dashboard.php');
    exit;
}

// ─────────────────────────────────────────
//  Storico accessi
// ─────────────────────────────────────────
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
         COUNT(*)                                          AS totale,
         SUM(esito = 'successo')                           AS successi,
         SUM(esito = 'fallito')                            AS falliti,
         MAX(CASE WHEN esito = 'successo' THEN data_ora END) AS ultimo_accesso
       FROM log_accessi
      WHERE utente_id = ?"
);
$stmt2->execute([$utente_id]);
$stats = $stmt2->fetch();

// ─────────────────────────────────────────
//  Ultima timbratura
// ─────────────────────────────────────────
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

// ─────────────────────────────────────────
//  Storico timbrature
// ─────────────────────────────────────────
$stmt4 = $db->prepare(
    "SELECT tipo, data_ora, durata
       FROM timbrature
      WHERE utente_id = ?
      ORDER BY data_ora DESC
      LIMIT 50"
);
$stmt4->execute([$utente_id]);
$timbrature = $stmt4->fetchAll();

$iniziale = mb_strtoupper(mb_substr($nome, 0, 1));

$giorni = [
    'Sunday'    => 'Domenica', 'Monday'  => 'Lunedì',
    'Tuesday'   => 'Martedì',  'Wednesday'=> 'Mercoledì',
    'Thursday'  => 'Giovedì',  'Friday'  => 'Venerdì',
    'Saturday'  => 'Sabato'
];
$oggi = $giorni[date('l')] . ', ' . date('d/m/Y');
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

<!-- ══ TOPBAR ══ -->
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

<!-- ══ MAIN ══ -->
<main class="wrap">

  <!-- Intestazione -->
  <div class="page-header fade-up">
    <h1 class="page-title">Ciao, <em><?= htmlspecialchars($nome) ?></em></h1>
    <div class="page-date">
      <?= date('l') ?><br>
      <?= date('d.m.Y') ?>
    </div>
  </div>

  <!-- ── STATISTICHE ACCESSI ── -->
  <?php if ($stats): ?>
  <section class="fade-up">
    <div class="section-label">Statistiche accessi</div>
    <div class="stats-row">
      <div class="stat">
        <span class="stat-lbl">Totale accessi</span>
        <span class="stat-num c-cobalt"><?= (int)$stats['totale'] ?></span>
      </div>
      <div class="stat">
        <span class="stat-lbl">Riusciti</span>
        <span class="stat-num c-leaf"><?= (int)$stats['successi'] ?></span>
      </div>
      <div class="stat">
        <span class="stat-lbl">Falliti</span>
        <span class="stat-num c-rust"><?= (int)$stats['falliti'] ?></span>
      </div>
      <div class="stat">
        <span class="stat-lbl">Ultimo accesso</span>
        <span class="stat-num small">
          <?= $stats['ultimo_accesso']
              ? date('d/m/Y', strtotime($stats['ultimo_accesso'])).'<br>'.date('H:i', strtotime($stats['ultimo_accesso']))
              : '—' ?>
        </span>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- ── TIMBRATURA ── -->
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
          Ultima timbratura:
          <strong><?= ucfirst($ultima['tipo']) ?></strong>
          il <?= date('d/m/Y', strtotime($ultima['data_ora'])) ?>
          alle <?= date('H:i:s', strtotime($ultima['data_ora'])) ?>
          <?php if ($ultima['tipo'] === 'uscita' && $ultima['durata']): ?>
          <span class="sep">·</span>
          Durata <?= formatDurata((int)$ultima['durata']) ?>
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

  <!-- ── STORICO TIMBRATURE ── -->
  <?php if (!empty($timbrature)): ?>
  <section class="fade-up">
    <div class="section-label">Storico timbrature</div>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Data / Ora</th>
            <th>Tipo</th>
            <th>Durata sessione</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($timbrature as $i => $row): ?>
          <tr>
            <td class="td-num"><?= $i + 1 ?></td>
            <td class="td-mono"><?= date('d/m/Y H:i:s', strtotime($row['data_ora'])) ?></td>
            <td>
              <?php if ($row['tipo'] === 'entrata'): ?>
                <span class="pill in">↑ Entrata</span>
              <?php else: ?>
                <span class="pill out">↓ Uscita</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($row['tipo'] === 'uscita' && $row['durata']): ?>
                <span class="td-mono"><?= formatDurata((int)$row['durata']) ?></span>
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

  <!-- ── LOG ACCESSI ── -->
  <?php if (!empty($accessi)): ?>
  <section class="fade-up">
    <div class="section-label">Log accessi</div>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Data / Ora</th>
            <th>Esito</th>
            <th>User Agent</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($accessi as $i => $row): ?>
          <tr>
            <td class="td-num"><?= $i + 1 ?></td>
            <td class="td-mono"><?= date('d/m/Y H:i:s', strtotime($row['data_ora'])) ?></td>
            <td>
              <?php if ($row['esito'] === 'successo'): ?>
                <span class="pill ok">✓ OK</span>
              <?php else: ?>
                <span class="pill ko">✗ Fallito</span>
              <?php endif; ?>
            </td>
            <td class="td-ua"><?= htmlspecialchars($row['user_agent'] ?? '') ?></td>
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