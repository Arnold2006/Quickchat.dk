<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;

if (!$room_id) {
    echo json_encode([]);
    exit;
}

$stmt = db()->prepare("
    SELECT username
    FROM room_users
    WHERE room_id = :room_id
      AND last_seen >= DATE_SUB(NOW(), INTERVAL :timeout SECOND)
    ORDER BY username ASC
");
$stmt->execute([':room_id' => $room_id, ':timeout' => USER_TIMEOUT]);
$users = $stmt->fetchAll();

echo json_encode($users);
