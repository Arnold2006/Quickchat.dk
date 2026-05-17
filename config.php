<?php
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int {
        return strlen($string);
    }
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'quickchat');
define('DB_USER', 'quickchat');
define('DB_PASS', 'NY46ZRTR90wwZZ');
define('MAX_USERS', 30);
define('USER_TIMEOUT', 45);
define('ADMIN_PASSWORD', 'NY46ZRTR90wwZZ');
define('ADMIN_TOKEN', 'hemmelig_admin_url_token');

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
