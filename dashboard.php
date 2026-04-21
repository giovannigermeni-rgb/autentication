<?php
// ============================================================
//  dashboard.php  –  Dashboard utente + dashboard amministratore
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

$alert = ['type' => '', 'message' => ''];

// ─────────────────────────────────────────
//  Dashboard amministratore
// ─────────────────────────────────────────
if ($isAdmin) {
    $userColumns = getTableColumns($db, 'utenti');
    $hasActiveColumn = in_array('attivo', $userColumns, true);
    $hasRoleColumn = in_array('ruolo', $userColumns, true);
    $hasEmailColumn = in_array('email', $userColumns, true);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action'])) {
        if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            exit('Richiesta non valida.');
        }

        $action = $_POST['admin_action'];

        if ($action === 'create_user') {
            $newName = trim($_POST['nome'] ?? '');
            $newUsername = trim($_POST['username'] ?? '');
            $newEmail = trim($_POST['email'] ?? '');
            $newPassword = $_POST['password'] ?? '';
            $newRole = trim($_POST['ruolo'] ?? 'utente');
            $newActive = isset($_POST['attivo']) ? 1 : 0;

            if ($newName === '' || $newUsername === '' || $newPassword === '' || ($hasEmailColumn && $newEmail === '')) {
                $alert = [
                    'type' => 'error',
                    'message' => 'Compila nome, username, email e password per creare un nuovo utente.'
                ];
            } elseif ($hasEmailColumn && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $alert = [
                    'type' => 'error',
                    'message' => 'Inserisci un indirizzo email valido.'
                ];
            } else {
                $stmt = $db->prepare('SELECT COUNT(*) FROM utenti WHERE username = ?');
                $stmt->execute([$newUsername]);
                $exists = (int) $stmt->fetchColumn() > 0;

                if ($exists) {
                    $alert = [
                        'type' => 'error',
                        'message' => 'Esiste gia un utente con questo username.'
                    ];
                } elseif ($hasEmailColumn) {
                    $stmt = $db->prepare('SELECT COUNT(*) FROM utenti WHERE email = ?');
                    $stmt->execute([$newEmail]);
                    $emailExists = (int) $stmt->fetchColumn() > 0;

                    if ($emailExists) {
                        $alert = [
                            'type' => 'error',
                            'message' => 'Esiste gia un utente con questa email.'
                        ];
                    }
                }

                if ($alert['message'] === '') {
                    $supportedInsertFields = ['username', 'password', 'nome'];
                    if ($hasEmailColumn) {
                        $supportedInsertFields[] = 'email';
                    }
                    if ($hasActiveColumn) {
                        $supportedInsertFields[] = 'attivo';
                    }
                    if ($hasRoleColumn) {
                        $supportedInsertFields[] = 'ruolo';
                    }

                    $unsupportedRequiredFields = [];
                    foreach (getTableSchema($db, 'utenti') as $column) {
                        $field = (string) $column['Field'];
                        $isRequired = ($column['Null'] ?? 'YES') === 'NO'
                            && $column['Default'] === null
                            && stripos((string) ($column['Extra'] ?? ''), 'auto_increment') === false;

                        if ($isRequired && !in_array($field, $supportedInsertFields, true) && $field !== 'id') {
                            $unsupportedRequiredFields[] = $field;
                        }
                    }

                    if ($unsupportedRequiredFields !== []) {
                        $alert = [
                            'type' => 'error',
                            'message' => 'Impossibile creare l utente: la tabella richiede anche questi campi: '
                                . implode(', ', $unsupportedRequiredFields) . '.'
                        ];
                    } else {
                        $fields = ['username', 'password', 'nome'];
                        $placeholders = ['?', '?', '?'];
                        $values = [$newUsername, password_hash($newPassword, PASSWORD_DEFAULT), $newName];

                        if ($hasEmailColumn) {
                            $fields[] = 'email';
                            $placeholders[] = '?';
                            $values[] = $newEmail;
                        }

                        if ($hasActiveColumn) {
                            $fields[] = 'attivo';
                            $placeholders[] = '?';
                            $values[] = $newActive;
                        }

                        if ($hasRoleColumn) {
                            $fields[] = 'ruolo';
                            $placeholders[] = '?';
                            $values[] = in_array($newRole, ['admin', 'utente'], true) ? $newRole : 'utente';
                        }

                        $sql = sprintf(
                            'INSERT INTO utenti (%s) VALUES (%s)',
                            implode(', ', $fields),
                            implode(', ', $placeholders)
                        );

                        try {
                            $stmt = $db->prepare($sql);
                            $stmt->execute($values);
                        } catch (Throwable $e) {
                            error_log('Create user failed: ' . $e->getMessage());
                            $alert = [
                                'type' => 'error',
                                'message' => 'Creazione utente non riuscita. Controlla i vincoli della tabella utenti.'
                            ];
                        }

                        if ($alert['message'] === '') {
                            header('Location: dashboard.php?created=1');
                            exit;
                        }
                    }
                }
            }
        }

        if ($action === 'update_user') {
            $submittedUsers = $_POST['users'] ?? null;

            if (!is_array($submittedUsers) || $submittedUsers === []) {
                $alert = [
                    'type' => 'error',
                    'message' => 'Nessuna modifica da salvare.'
                ];
            } else {
                $userIds = array_keys($submittedUsers);
                $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
                $stmt = $db->prepare(
                    'SELECT id, username, nome'
                    . ($hasEmailColumn ? ', email' : '')
                    . ($hasRoleColumn ? ', ruolo' : '')
                    . ($hasActiveColumn ? ', attivo' : '')
                    . ' FROM utenti WHERE id IN (' . $placeholders . ')'
                );
                $stmt->execute($userIds);
                $existingUsers = [];

                foreach ($stmt->fetchAll() as $row) {
                    $existingUsers[(int) $row['id']] = $row;
                }

                foreach ($submittedUsers as $rawUserId => $payload) {
                    $editUserId = (int) $rawUserId;

                    if (!isset($existingUsers[$editUserId])) {
                        $alert = [
                            'type' => 'error',
                            'message' => 'Uno degli utenti selezionati non esiste piu.'
                        ];
                        break;
                    }

                    $updates = [];
                    $values = [];

                    if ($hasRoleColumn) {
                        $updatedRole = trim($payload['ruolo'] ?? 'utente');
                        $updatedRole = in_array($updatedRole, ['admin', 'utente'], true) ? $updatedRole : 'utente';

                        if ($editUserId === $utente_id && $updatedRole !== 'admin') {
                            $alert = [
                                'type' => 'error',
                                'message' => 'Non puoi rimuovere il ruolo admin dal tuo account mentre sei collegato.'
                            ];
                            break;
                        }

                        if ($updatedRole !== (string) ($existingUsers[$editUserId]['ruolo'] ?? '')) {
                            $updates[] = 'ruolo = ?';
                            $values[] = $updatedRole;
                        }
                    }

                    if ($hasActiveColumn) {
                        $updatedActive = (($payload['attivo'] ?? '0') === '1') ? 1 : 0;

                        if ($editUserId === $utente_id && $updatedActive !== 1) {
                            $alert = [
                                'type' => 'error',
                                'message' => 'Non puoi disattivare l account con cui sei collegato.'
                            ];
                            break;
                        }

                        if ($updatedActive !== (int) ($existingUsers[$editUserId]['attivo'] ?? 0)) {
                            $updates[] = 'attivo = ?';
                            $values[] = $updatedActive;
                        }
                    }

                    if (!empty($updates)) {
                        $values[] = $editUserId;
                        $stmt = $db->prepare(
                            'UPDATE utenti SET ' . implode(', ', $updates) . ' WHERE id = ?'
                        );
                        $stmt->execute($values);
                    }
                }

                if ($alert['message'] === '') {
                    $_SESSION['is_admin'] = true;
                    header('Location: dashboard.php?updated=1');
                    exit;
                }
            }
        }

        if ($action === 'delete_user') {
            $deleteUserId = (int) ($_POST['delete_user_id'] ?? 0);

            if ($deleteUserId <= 0) {
                $alert = [
                    'type' => 'error',
                    'message' => 'Utente non valido.'
                ];
            } elseif ($deleteUserId === $utente_id) {
                $alert = [
                    'type' => 'error',
                    'message' => 'Non puoi eliminare l account con cui sei collegato.'
                ];
            } else {
                $stmt = $db->prepare('SELECT COUNT(*) FROM utenti WHERE id = ?');
                $stmt->execute([$deleteUserId]);

                if ((int) $stmt->fetchColumn() === 0) {
                    $alert = [
                        'type' => 'error',
                        'message' => 'L utente selezionato non esiste piu.'
                    ];
                } else {
                    $db->beginTransaction();

                    try {
                        $db->prepare('DELETE FROM log_accessi WHERE utente_id = ?')->execute([$deleteUserId]);
                        $db->prepare('DELETE FROM timbrature WHERE utente_id = ?')->execute([$deleteUserId]);
                        $db->prepare('DELETE FROM utenti WHERE id = ?')->execute([$deleteUserId]);
                        $db->commit();
                    } catch (Throwable $e) {
                        $db->rollBack();
                        throw $e;
                    }

                    header('Location: dashboard.php?deleted=1');
                    exit;
                }
            }
        }
    }

    if (isset($_GET['created'])) {
        $alert = [
            'type' => 'success',
            'message' => 'Nuovo utente creato correttamente.'
        ];
    }

    if (isset($_GET['deleted'])) {
        $alert = [
            'type' => 'success',
            'message' => 'Utente eliminato correttamente.'
        ];
    }

    if (isset($_GET['updated'])) {
        $alert = [
            'type' => 'success',
            'message' => 'Ruolo o stato utente aggiornati correttamente.'
        ];
    }

    $selectFields = ['id', 'username', 'nome'];
    if ($hasEmailColumn) {
        $selectFields[] = 'email';
    }
    if ($hasActiveColumn) {
        $selectFields[] = 'attivo';
    }
    if ($hasRoleColumn) {
        $selectFields[] = 'ruolo';
    }

    $stmt = $db->query(
        'SELECT ' . implode(', ', $selectFields) . ' FROM utenti ORDER BY id DESC'
    );
    $users = $stmt->fetchAll();

    $totalUsers = count($users);
    $activeUsers = 0;
    $adminUsers = 0;

    foreach ($users as $user) {
        if (!$hasActiveColumn || !empty($user['attivo'])) {
            $activeUsers++;
        }

        if (($hasRoleColumn && strtolower((string) ($user['ruolo'] ?? '')) === 'admin')
            || (!$hasRoleColumn && strtolower((string) $user['username']) === 'admin')) {
            $adminUsers++;
        }
    }

    $iniziale = mb_strtoupper(mb_substr($nome, 0, 1));
    ?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Admin — Login Tracker</title>
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
      <span class="brand-sub">admin</span>
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
    <div>
      <h1 class="page-title">Gestione <em>utenti</em></h1>
      <p class="page-intro">Consulta tutti gli account, crea nuovi accessi e rimuovi quelli non piu necessari.</p>
    </div>
    <div class="page-date">
      <?= date('l') ?><br>
      <?= date('d.m.Y') ?>
    </div>
  </div>

  <?php if ($alert['message'] !== ''): ?>
  <div class="alert-banner <?= $alert['type'] === 'success' ? 'success' : 'error' ?> fade-up">
    <?= htmlspecialchars($alert['message']) ?>
  </div>
  <?php endif; ?>

  <section class="fade-up">
    <div class="section-label">Panoramica</div>
    <div class="stats-row">
      <div class="stat">
        <span class="stat-lbl">Utenti totali</span>
        <span class="stat-num c-cobalt"><?= $totalUsers ?></span>
      </div>
      <div class="stat">
        <span class="stat-lbl">Utenti attivi</span>
        <span class="stat-num c-leaf"><?= $activeUsers ?></span>
      </div>
      <div class="stat">
        <span class="stat-lbl">Amministratori</span>
        <span class="stat-num c-rust"><?= $adminUsers ?></span>
      </div>
      <div class="stat">
        <span class="stat-lbl">Utente corrente</span>
        <span class="stat-num small"><?= htmlspecialchars($username ?? '') ?><br><?= htmlspecialchars($nome) ?></span>
      </div>
    </div>
  </section>

  <section class="fade-up admin-grid">
    <div>
      <div class="section-label">Nuovo utente</div>
      <div class="admin-card">
        <form method="POST" class="admin-form" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="admin_action" value="create_user">

          <div class="field">
            <label for="nome">Nome completo</label>
            <input
              type="text"
              id="nome"
              name="nome"
              value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>"
              placeholder="es. Mario Rossi"
              required
            >
          </div>

          <div class="field">
            <label for="username">Username</label>
            <input
              type="text"
              id="username"
              name="username"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
              placeholder="es. mario.rossi"
              required
            >
          </div>

          <div class="field">
            <label for="password">Password iniziale</label>
            <input
              type="password"
              id="password"
              name="password"
              placeholder="Inserisci una password sicura"
              required
            >
          </div>

          <?php if ($hasEmailColumn): ?>
          <div class="field">
            <label for="email">Email</label>
            <input
              type="email"
              id="email"
              name="email"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
              placeholder="es. nome@azienda.it"
              required
            >
          </div>
          <?php endif; ?>

          <?php if ($hasRoleColumn): ?>
          <div class="field">
            <label for="ruolo">Ruolo</label>
            <select id="ruolo" name="ruolo">
              <option value="utente" <?= ($_POST['ruolo'] ?? 'utente') === 'utente' ? 'selected' : '' ?>>Utente</option>
              <option value="admin" <?= ($_POST['ruolo'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>
          </div>
          <?php endif; ?>

          <?php if ($hasActiveColumn): ?>
          <label class="check-row">
            <input type="checkbox" name="attivo" value="1" <?= !isset($_POST['admin_action']) || isset($_POST['attivo']) ? 'checked' : '' ?>>
            <span>Account attivo al momento della creazione</span>
          </label>
          <?php endif; ?>

          <button type="submit" class="btn-stamp entrata admin-submit">Crea utente</button>
        </form>
      </div>
    </div>

    <div>
      <div class="section-label">Indicazioni</div>
      <div class="admin-card admin-note">
        <h2>Operazioni disponibili</h2>
        <p>Da questa dashboard puoi visualizzare l elenco completo degli utenti registrati, aggiungerne di nuovi e rimuovere quelli non piu necessari.</p>
        <p>Per sicurezza, l account con cui stai lavorando non puo essere eliminato dalla tabella.</p>
      </div>
    </div>
  </section>

  <section class="fade-up">
    <div class="section-label">Utenti registrati</div>
    <form method="POST" class="table-actions">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="admin_action" value="update_user">
      <button type="submit" class="btn-inline-save table-save-btn">Salva modifiche tabella</button>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Nome</th>
              <th>Username</th>
              <?php if ($hasEmailColumn): ?><th>Email</th><?php endif; ?>
              <?php if ($hasRoleColumn): ?><th>Ruolo</th><?php endif; ?>
              <?php if ($hasActiveColumn): ?><th>Stato</th><?php endif; ?>
              <th>Azioni</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $user): ?>
            <tr>
              <td class="td-mono"><?= (int) $user['id'] ?></td>
              <td class="td-mono"><?= htmlspecialchars($user['nome']) ?></td>
              <td class="td-mono"><?= htmlspecialchars($user['username']) ?></td>
              <?php if ($hasEmailColumn): ?>
              <td class="td-mono"><?= htmlspecialchars((string) ($user['email'] ?? '')) ?></td>
              <?php endif; ?>
              <?php if ($hasRoleColumn): ?>
              <td>
                  <select name="users[<?= (int) $user['id'] ?>][ruolo]" class="table-select">
                    <option value="utente" <?= ($user['ruolo'] ?? 'utente') === 'utente' ? 'selected' : '' ?>>Utente</option>
                    <option value="admin" <?= ($user['ruolo'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                  </select>
              </td>
              <?php endif; ?>
              <?php if ($hasActiveColumn): ?>
              <td>
                  <input type="hidden" name="users[<?= (int) $user['id'] ?>][attivo]" value="0">
                  <label class="table-check">
                    <input type="checkbox" name="users[<?= (int) $user['id'] ?>][attivo]" value="1" <?= !empty($user['attivo']) ? 'checked' : '' ?>>
                    <span class="pill <?= !empty($user['attivo']) ? 'ok' : 'ko' ?>">
                      <?= !empty($user['attivo']) ? 'Attivo' : 'Disattivo' ?>
                    </span>
                  </label>
              </td>
              <?php endif; ?>
              <td>
                <div class="action-stack">
                  <?php if ((int) $user['id'] === $utente_id): ?>
                  <span class="td-dash">Account corrente</span>
                  <?php else: ?>
                  <button
                    type="submit"
                    form="delete-user-<?= (int) $user['id'] ?>"
                    class="btn-inline-danger"
                    onclick="return confirm('Vuoi davvero eliminare questo utente?');"
                  >Elimina</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </form>
    <?php foreach ($users as $user): ?>
      <?php if ((int) $user['id'] !== $utente_id): ?>
      <form method="POST" id="delete-user-<?= (int) $user['id'] ?>" class="hidden-delete-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="admin_action" value="delete_user">
        <input type="hidden" name="delete_user_id" value="<?= (int) $user['id'] ?>">
      </form>
      <?php endif; ?>
    <?php endforeach; ?>
  </section>
</main>
</body>
</html>
<?php
    exit;
}

// ─────────────────────────────────────────
//  Dashboard utente standard
// ─────────────────────────────────────────
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
      <span class="td-mono"><?= htmlspecialchars($nome) ?></span>
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
            <span class="btn-icon"></span> Timbra entrata
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
