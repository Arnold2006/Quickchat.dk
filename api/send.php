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
$token     = isset($_POST['token'])     ? trim($_POST['token'])         : '';

if (!$room_id || !$username || !$message || !$token || !apcu_ok()) {
    http_response_code(400);
    echo json_encode(['error' => 'Manglende felter']);
    exit;
}

// Validér token – brugeren skal være aktiv med dette token
$users = qc_get_users_raw($room_id);
$key   = qc_find_user($users, $username);
if ($key === null || $users[$key]['token'] !== $token) {
    http_response_code(403);
    echo json_encode(['error' => 'Uautoriseret']);
    exit;
}

$username  = mb_substr($username,  0, MAX_USERNAME_LEN);
$recipient = mb_substr($recipient, 0, MAX_USERNAME_LEN);

// Billedbeskeder indeholder base64-data og må ikke trunkeres med MAX_MESSAGE_LEN.
// Format: [IMG]data:image/<type>;base64,<data>[/IMG]
$is_image = (bool) preg_match(
    '/^\[IMG\]data:image\/(jpeg|png|gif|webp);base64,[A-Za-z0-9+\/=]+\[\/IMG\]$/',
    $message
);
if ($is_image) {
    if (strlen($message) > MAX_IMAGE_SIZE + 30) { // 30 bytes for [IMG]…[/IMG] tags
        http_response_code(413);
        echo json_encode(['error' => 'Billede er for stort (max ~375 KB)']);
        exit;
    }
} else {
    $message = mb_substr($message, 0, MAX_MESSAGE_LEN);
}

$is_private = ($recipient !== '') ? 1 : 0;

$id = qc_add_message(
    $room_id,
    $username,
    $message,
    $is_private,
    $is_private ? $recipient : null
);

echo json_encode(['ok' => true, 'id' => $id]);
