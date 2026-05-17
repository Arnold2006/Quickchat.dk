<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$room_id   = isset($_POST['room_id'])   ? (int)$_POST['room_id']       : 0;
$username  = isset($_POST['username'])  ? trim($_POST['username'])      : '';
$message   = isset($_POST['message'])   ? trim($_POST['message'])       : '';
$recipient = isset($_POST['recipient']) ? trim($_POST['recipient'])     : '';

if (!$room_id || !$username || !$message) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

// Sanitize lengths
$username  = mb_substr($username,  0, 50);
$message   = mb_substr($message,   0, 1000);
$recipient = mb_substr($recipient, 0, 50);

$is_private = ($recipient !== '') ? 1 : 0;

$stmt = db()->prepare("
    INSERT INTO messages (room_id, username, message, is_private, recipient)
    VALUES (:room_id, :username, :message, :is_private, :recipient)
");
$stmt->execute([
    ':room_id'    => $room_id,
    ':username'   => $username,
    ':message'    => $message,
    ':is_private' => $is_private,
    ':recipient'  => $is_private ? $recipient : null,
]);

echo json_encode(['success' => true, 'id' => (int)db()->lastInsertId()]);
