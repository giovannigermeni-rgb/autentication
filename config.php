<?php
// ============================================================
//  config.php  –  Configurazione database e costanti
// ============================================================

define('DB_HOST',   'localhost');
define('DB_NAME',   'login_tracker');
define('DB_USER',   'root');       // ← cambia con il tuo utente MySQL
define('DB_PASS',   '');           // ← cambia con la tua password MySQL
define('DB_CHARSET','utf8mb4');

// Durata sessione in secondi (30 minuti)
define('SESSION_LIFETIME', 1800);

// Numero massimo di tentativi falliti prima del blocco temporaneo
define('MAX_TENTATIVI', 5);

// Blocco temporaneo in secondi (15 minuti)
define('BLOCCO_DURATA', 900);

// ─────────────────────────────────────────
//  Connessione PDO (singleton)
// ─────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=3307;dbname=%s;charset=%s;port=3307',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // In produzione non esporre i dettagli dell'errore
            error_log('DB connection error: ' . $e->getMessage());
            die('Errore di connessione al database. Riprova più tardi.');
        }
    }
    return $pdo;
}

function getTableColumns(PDO $db, string $table): array {
    $columns = [];

    foreach (getTableSchema($db, $table) as $column) {
        $columns[] = $column['Field'];
    }

    return $columns;
}

function getTableSchema(PDO $db, string $table): array {
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    $cache[$table] = $stmt->fetchAll();
    return $cache[$table];
}

function tableHasColumn(PDO $db, string $table, string $column): bool {
    return in_array($column, getTableColumns($db, $table), true);
}

function isAdminAccount(PDO $db, int $userId, ?string $username = null): bool {
    if (tableHasColumn($db, 'utenti', 'ruolo')) {
        $stmt = $db->prepare('SELECT ruolo FROM utenti WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $role = strtolower((string) $stmt->fetchColumn());
        return in_array($role, ['admin', 'administrator', 'amministratore'], true);
    }

    if ($username !== null && strtolower($username) === 'admin') {
        return true;
    }

    return $userId === 1;
}
