<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;

if (!$room_id || !apcu_ok()) {
    echo json_encode([]);
    exit;
}

echo json_encode(qc_active_usernames($room_id));
