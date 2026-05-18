<?php
// ---------------------------------------------------------------------------
// config.php – Databaseforbindelse, site-konfiguration og APCu-funktioner
//
// Opret config-local.php (ikke committet) for at override DB-indstillinger:
//   define('DB_HOST', '...');
//   define('DB_PASS', '...');
//   define('ADMIN_PASSWORD', '...');
//   define('ADMIN_TOKEN', '...');
// ---------------------------------------------------------------------------

// Lokale overrides (ikke i git)
if (($local = realpath(__DIR__ . '/config-local.php')) !== false
        && dirname($local) === __DIR__) {
    require_once $local;
}

// Databasekonstanter
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_NAME') || define('DB_NAME', 'quickchat');
defined('DB_USER') || define('DB_USER', 'quickchat');
defined('DB_PASS') || define('DB_PASS', 'changeme');

// Admin – SKAL overrides i config-local.php på produktionsserver
defined('ADMIN_PASSWORD') || define('ADMIN_PASSWORD', 'changeme');
defined('ADMIN_TOKEN')    || define('ADMIN_TOKEN',    'changeme-token');

// ---------------------------------------------------------------------------
// Databaseforbindelse (lazy singleton)
// ---------------------------------------------------------------------------
function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $pdo;
}

// ---------------------------------------------------------------------------
// Site-konfiguration fra database (cached som PHP-konstanter)
// ---------------------------------------------------------------------------
if (!defined('MAX_USERS')) {
    try {
        $cfg = db()
            ->query("SELECT `key`, `value` FROM site_config")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception) {
        $cfg = [];
    }
    define('MAX_USERS',    (int)($cfg['max_users']    ?? 20));
    define('MAX_MESSAGES', (int)($cfg['max_messages'] ?? 30));
    define('USER_TIMEOUT', (int)($cfg['user_timeout'] ?? 90));
    define('SITE_NAME',         ($cfg['site_name']    ?? 'QuickChat.dk'));
    unset($cfg);
}

// ---------------------------------------------------------------------------
// APCu-tjek
// ---------------------------------------------------------------------------
function apcu_ok(): bool
{
    return function_exists('apcu_fetch') && apcu_enabled();
}

// ---------------------------------------------------------------------------
// Interne nøgler
// ---------------------------------------------------------------------------
function _qc_mkey(int $room_id): string { return 'qc_m' . $room_id; }
function _qc_ukey(int $room_id): string { return 'qc_u' . $room_id; }

// ---------------------------------------------------------------------------
// Besked-funktioner  (APCu)
// ---------------------------------------------------------------------------

/** Næste unikke besked-ID (atomisk increment). */
function qc_next_id(): int
{
    $id = apcu_inc('qc_ctr', 1, $ok);
    if (!$ok) {
        apcu_store('qc_ctr', 1);
        $id = 1;
    }
    return (int)$id;
}

/** Hent alle lagrede beskeder for et rum. */
function qc_get_messages(int $room_id): array
{
    $data = apcu_fetch(_qc_mkey($room_id), $ok);
    return ($ok && is_array($data)) ? $data : [];
}

/**
 * Tilføj en besked og behold kun de seneste MAX_MESSAGES.
 * Returnerer besked-ID'et.
 */
function qc_add_message(
    int     $room_id,
    string  $username,
    string  $message,
    int     $is_private = 0,
    ?string $recipient  = null
): int {
    $id   = qc_next_id();
    $msg  = [
        'id'         => $id,
        'username'   => $username,
        'message'    => $message,
        'is_private' => $is_private,
        'recipient'  => $recipient,
        'ts'         => time(),
    ];
    $key  = _qc_mkey($room_id);
    $msgs = apcu_fetch($key, $ok);
    if (!$ok || !is_array($msgs)) $msgs = [];
    $msgs[] = $msg;
    if (count($msgs) > MAX_MESSAGES) {
        $msgs = array_slice($msgs, -MAX_MESSAGES);
    }
    apcu_store($key, $msgs);
    return $id;
}

// ---------------------------------------------------------------------------
// Bruger-funktioner  (APCu)
// ---------------------------------------------------------------------------

/**
 * Råt bruger-array: [ 'Alice' => ['token' => '…', 'ts' => 1234567890], … ]
 */
function qc_get_users_raw(int $room_id): array
{
    $data = apcu_fetch(_qc_ukey($room_id), $ok);
    return ($ok && is_array($data)) ? $data : [];
}

/**
 * Find brugerens nøgle i arrayet (case-insensitivt).
 * Returnerer den eksakte nøgle eller null.
 */
function qc_find_user(array $users, string $username): ?string
{
    $lower = strtolower($username);
    foreach ($users as $name => $_) {
        if (strtolower($name) === $lower) return $name;
    }
    return null;
}

/**
 * Returnerer aktive brugernavne (sorteret).
 * @return string[]
 */
function qc_active_usernames(int $room_id): array
{
    $users  = qc_get_users_raw($room_id);
    $cutoff = time() - USER_TIMEOUT;
    $active = [];
    foreach ($users as $name => $data) {
        if (($data['ts'] ?? 0) >= $cutoff) $active[] = $name;
    }
    sort($active);
    return $active;
}

/** Tæl aktive brugere i et rum. */
function qc_user_count(int $room_id): int
{
    return count(qc_active_usernames($room_id));
}

/**
 * Tjek om et brugernavn er ledigt.
 * Ledigt hvis: ikke fundet, timed-out, eller samme token (genopkobling).
 */
function qc_username_available(int $room_id, string $username, string $token): bool
{
    $users  = qc_get_users_raw($room_id);
    $key    = qc_find_user($users, $username);
    if ($key === null) return true;
    $data   = $users[$key];
    $cutoff = time() - USER_TIMEOUT;
    return ($data['ts'] < $cutoff) || ($data['token'] === $token);
}

/** Registrér eller opdater en bruger (heartbeat). */
function qc_touch_user(int $room_id, string $username, string $token): void
{
    $key   = _qc_ukey($room_id);
    $users = qc_get_users_raw($room_id);
    // Fjern evt. gammel post med andet casing
    $existing = qc_find_user($users, $username);
    if ($existing !== null && $existing !== $username) {
        unset($users[$existing]);
    }
    $users[$username] = ['token' => $token, 'ts' => time()];
    apcu_store($key, $users);
}

/**
 * Fjern en bruger (kun hvis token matcher).
 * Returnerer true hvis brugeren faktisk blev fjernet.
 */
function qc_remove_user(int $room_id, string $username, string $token): bool
{
    $key   = _qc_ukey($room_id);
    $users = qc_get_users_raw($room_id);
    $existing = qc_find_user($users, $username);
    if ($existing === null) return false;
    if ($users[$existing]['token'] !== $token) return false;
    unset($users[$existing]);
    apcu_store($key, $users);
    return true;
}

session_start();
