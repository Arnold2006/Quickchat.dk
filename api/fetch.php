<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
$viewer  = isset($_GET['viewer'])  ? trim($_GET['viewer'])  : '';

if (!$room_id) {
    echo json_encode(['messages' => []]);
    exit;
}

$stmt = db()->prepare("
    SELECT id, room_id, username, message, is_private, recipient, created_at
    FROM messages
    WHERE room_id = :room_id
      AND id > :last_id
      AND (
          is_private = 0
          OR username = '__system__'
          OR (is_private = 1 AND (username = :viewer OR recipient = :viewer2))
      )
    ORDER BY id ASC
    LIMIT 100
");
$stmt->execute([
    ':room_id' => $room_id,
    ':last_id' => $last_id,
    ':viewer'  => $viewer,
    ':viewer2' => $viewer,
]);
$messages = $stmt->fetchAll();

echo json_encode(['messages' => $messages]);
