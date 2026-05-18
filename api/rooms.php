<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

$cat_id = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;

if ($cat_id) {
    $stmt = db()->prepare("
        SELECT id, name FROM rooms WHERE category_id = :cid ORDER BY sort_order, name
    ");
    $stmt->execute([':cid' => $cat_id]);
} else {
    $stmt = db()->query("SELECT id, name FROM rooms ORDER BY sort_order, name");
}

$rooms = $stmt->fetchAll();

foreach ($rooms as &$room) {
    $room['online_users'] = apcu_ok() ? qc_user_count((int)$room['id']) : 0;
}
unset($room);

echo json_encode($rooms);
