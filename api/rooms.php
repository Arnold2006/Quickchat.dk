<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

$stmt = db()->prepare("
    SELECT r.id, r.name,
           COUNT(CASE WHEN ru.last_seen >= DATE_SUB(NOW(), INTERVAL :timeout SECOND) THEN 1 END) AS online_users
    FROM rooms r
    LEFT JOIN room_users ru ON r.id = ru.room_id
    GROUP BY r.id, r.name
    ORDER BY r.name
");
$stmt->execute([':timeout' => USER_TIMEOUT]);
$rooms = $stmt->fetchAll();

echo json_encode($rooms);
