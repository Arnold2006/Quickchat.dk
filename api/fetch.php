<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
$viewer  = isset($_GET['viewer'])  ? trim($_GET['viewer'])  : '';

if (!$room_id || !apcu_ok()) {
    echo json_encode(['messages' => []]);
    exit;
}

$all    = qc_get_messages($room_id);
$result = [];

foreach ($all as $msg) {
    if ($msg['id'] <= $last_id) continue;

    // Private beskeder vises kun til afsender og modtager
    if ($msg['is_private'] && $msg['username'] !== '__system__') {
        if ($msg['username'] !== $viewer && $msg['recipient'] !== $viewer) continue;
    }

    $result[] = $msg;
}

echo json_encode(['messages' => $result]);
