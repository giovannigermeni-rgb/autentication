<?php
// ============================================================
//  config.php  –  Configurazione database e costanti
// ============================================================

// Credenziali database.
define('DB_HOST',   'localhost');
define('DB_NAME',   'login_tracker');
define('DB_USER',   'root');
define('DB_PASS',   '');
define('DB_CHARSET','utf8mb4');

// Durata sessione in secondi
define('SESSION_LIFETIME', 1800);

// Numero massimo di tentativi falliti prima del blocco temporaneo
define('MAX_TENTATIVI', 5);

// Blocco temporaneo in secondi
define('BLOCCO_DURATA', 900);

// Geofence per la timbratura
// Enac: 45.44145203485805, 10.981640364986557
define('TIMBRATURA_CENTER_LAT', 45.44145203485805);
define('TIMBRATURA_CENTER_LNG', 10.981640364986557);
define('TIMBRATURA_RADIUS_METERS', 100);

// -----------------------------------------------------------------
// Sessione e sicurezza
// -----------------------------------------------------------------
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}

function requireAuthenticatedUser(): void {
    if (empty($_SESSION['utente_id'])) {
        header('Location: index.php');
        exit;
    }
}

function enforceSessionLifetime(): void {
    if (!isset($_SESSION['login_ora'])) {
        return;
    }

    if ((time() - (int) $_SESSION['login_ora']) > SESSION_LIFETIME) {
        session_destroy();
        header('Location: index.php?timeout=1');
        exit;
    }
}

function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

// -----------------------------------------------------------------
// Database
// -----------------------------------------------------------------
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

// -----------------------------------------------------------------
// Schema database
// -----------------------------------------------------------------
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

// -----------------------------------------------------------------
// Autorizzazione
// -----------------------------------------------------------------
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

// -----------------------------------------------------------------
// Geolocalizzazione timbratura
// -----------------------------------------------------------------
function getTimbraturaBoundingBox(): array {
    $latDelta = TIMBRATURA_RADIUS_METERS / 111320;
    $lngDelta = TIMBRATURA_RADIUS_METERS / (111320 * max(cos(deg2rad(TIMBRATURA_CENTER_LAT)), 0.00001));

    return [
        'lat_min' => TIMBRATURA_CENTER_LAT - $latDelta,
        'lat_max' => TIMBRATURA_CENTER_LAT + $latDelta,
        'lng_min' => TIMBRATURA_CENTER_LNG - $lngDelta,
        'lng_max' => TIMBRATURA_CENTER_LNG + $lngDelta,
    ];
}

function isWithinTimbraturaArea(float $latitude, float $longitude): bool {
    $bounds = getTimbraturaBoundingBox();

    return $latitude >= $bounds['lat_min']
        && $latitude <= $bounds['lat_max']
        && $longitude >= $bounds['lng_min']
        && $longitude <= $bounds['lng_max'];
}
