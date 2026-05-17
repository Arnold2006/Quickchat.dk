<?php
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int {
        return strlen($string);
    }
}

// Local overrides (not committed to git)
$_config_local = realpath(__DIR__ . '/config-local.php');
if ($_config_local !== false && dirname($_config_local) === __DIR__) {
    require_once $_config_local;
}
unset($_config_local);

// Defaults – only applied when config-local.php does not define them
defined('DB_HOST')        || define('DB_HOST',        'localhost');
defined('DB_NAME')        || define('DB_NAME',        'quickchat');
defined('DB_USER')        || define('DB_USER',        'quickchat');
defined('DB_PASS')        || define('DB_PASS',        'changeme');
defined('MAX_USERS')      || define('MAX_USERS',      30);
defined('USER_TIMEOUT')   || define('USER_TIMEOUT',   45);
defined('ADMIN_PASSWORD') || define('ADMIN_PASSWORD', 'changeme');
defined('ADMIN_TOKEN')    || define('ADMIN_TOKEN',    'changeme');

function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    }
    return $pdo;
}

session_start();
