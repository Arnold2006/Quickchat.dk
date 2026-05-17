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

// Slet bruger fra room_users, men kun hvis last_seen ikke er blevet opdateret
// inden for de sidste 3 sekunder (forhindrer race condition ved side-genindlæsning,
// hvor chat.php kan nå at genregistrere brugeren inden beacon ankommer).
$stmt = db()->prepare("
    DELETE FROM room_users
    WHERE room_id = :room_id
      AND session_id = :session_id
      AND last_seen < DATE_SUB(NOW(), INTERVAL 3 SECOND)
");
$stmt->execute([':room_id' => $room_id, ':session_id' => $session_id]);

// Indsæt kun system-besked hvis brugeren rent faktisk blev slettet
if ($stmt->rowCount() > 0) {
    $stmt2 = db()->prepare("
        INSERT INTO messages (room_id, username, message, is_private)
        VALUES (:room_id, '__system__', :message, 0)
    ");
    $stmt2->execute([
        ':room_id' => $room_id,
        ':message' => $username . ' har forladt rummet',
    ]);
}

echo json_encode(['success' => true]);
