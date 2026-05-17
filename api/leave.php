<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

$room_id  = isset($_GET['room_id'])  ? (int)$_GET['room_id']   : 0;
$username = isset($_GET['username']) ? trim($_GET['username'])  : '';

if (!$room_id || !$username) {
    echo json_encode(['success' => false]);
    exit;
}

$session_id = session_id();

// Indsæt system besked om at bruger har forladt rummet
$stmt = db()->prepare("
    INSERT INTO messages (room_id, username, message, is_private)
    VALUES (:room_id, '__system__', :message, 0)
");
$stmt->execute([
    ':room_id' => $room_id,
    ':message' => $username . ' har forladt rummet',
]);

// Slet bruger fra room_users
$stmt = db()->prepare("
    DELETE FROM room_users
    WHERE room_id = :room_id AND session_id = :session_id
");
$stmt->execute([':room_id' => $room_id, ':session_id' => $session_id]);

echo json_encode(['success' => true]);
