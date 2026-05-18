<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

$room_id  = isset($_GET['room_id'])  ? (int)$_GET['room_id']   : 0;
$username = isset($_GET['username']) ? trim($_GET['username'])  : '';
$token    = isset($_GET['token'])    ? trim($_GET['token'])     : '';

if (!$room_id || !$username || !$token || !apcu_ok()) {
    echo json_encode(['ok' => false]);
    exit;
}

$removed = qc_remove_user($room_id, $username, $token);
if ($removed) {
    qc_add_message($room_id, '__system__', $username . ' har forladt rummet');
}

echo json_encode(['ok' => true, 'removed' => $removed]);
