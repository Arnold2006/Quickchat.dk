<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

$room_id  = isset($_GET['room_id'])  ? (int)$_GET['room_id']   : 0;
$username = isset($_GET['username']) ? trim($_GET['username'])  : '';

if (!$room_id || !$username) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$session_id = session_id();

$stmt = db()->prepare("
    UPDATE room_users
    SET last_seen = NOW()
    WHERE room_id = :room_id
      AND session_id = :session_id
");
$stmt->execute([':room_id' => $room_id, ':session_id' => $session_id]);

echo json_encode(['success' => true]);
