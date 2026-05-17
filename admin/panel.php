<?php
require_once __DIR__ . '/../config.php';

// Lag 1: URL token beskyttelse
$token = isset($_GET['token']) ? $_GET['token'] : '';
if ($token !== ADMIN_TOKEN) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}

// Lag 2: Password login
$error = '';
$loginRequired = !isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'login') {
        $password = $_POST['password'] ?? '';
        if ($password === ADMIN_PASSWORD) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_token'] = $token;
            $loginRequired = false;
        } else {
            $error = 'Forkert adgangskode.';
        }
    }
}

// Håndter POST-handlinger (kræver login)
$message = '';
if (!$loginRequired && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_room') {
            $name = trim($_POST['room_name'] ?? '');
            if ($name && mb_strlen($name) <= 100) {
                $stmt = db()->prepare("INSERT INTO rooms (name) VALUES (:name)");
                $stmt->execute([':name' => $name]);
                $message = 'Chatrum "' . htmlspecialchars($name) . '" oprettet.';
            } else {
                $message = 'Ugyldigt rumnavn (max 100 tegn).';
            }
        } elseif ($_POST['action'] === 'delete_room') {
            $del_id = (int)($_POST['room_id'] ?? 0);
            if ($del_id) {
                $stmt = db()->prepare("DELETE FROM rooms WHERE id = :id");
                $stmt->execute([':id' => $del_id]);
                $message = 'Chatrum slettet.';
            }
        } elseif ($_POST['action'] === 'logout') {
            $_SESSION['admin_logged_in'] = false;
            $loginRequired = true;
        }
    }
}

// Hent rum-data
$rooms = [];
if (!$loginRequired) {
    $stmt = db()->prepare("
        SELECT r.id, r.name,
               COUNT(DISTINCT CASE WHEN ru.last_seen >= DATE_SUB(NOW(), INTERVAL :timeout SECOND) THEN ru.session_id END) AS online_users,
               COUNT(DISTINCT m.id) AS message_count
        FROM rooms r
        LEFT JOIN room_users ru ON r.id = ru.room_id
        LEFT JOIN messages m ON r.id = m.room_id AND m.username != '__system__'
        GROUP BY r.id, r.name
        ORDER BY r.name
    ");
    $stmt->execute([':timeout' => USER_TIMEOUT]);
    $rooms = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuickChat Admin</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="admin-body">

<?php if ($loginRequired): ?>
<!-- Login-formular -->
<div style="display:flex;align-items:center;justify-content:center;min-height:100vh;">
    <div class="admin-login-box">
        <h1>🔒 Admin Login</h1>
        <?php if ($error): ?>
            <div class="error-msg" style="display:block;margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="login">
            <input type="password" name="password" class="username-input" placeholder="Adgangskode..." autofocus required>
            <br><br>
            <button type="submit" class="btn-primary" style="width:100%;">Log ind</button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- Admin panel -->
<div class="admin-container">
    <header class="admin-header">
        <h1>⚙️ QuickChat Admin</h1>
        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" style="display:inline;">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="btn-secondary">Log ud</button>
        </form>
    </header>

    <?php if ($message): ?>
        <div class="admin-message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Opret nyt rum -->
    <section class="admin-section">
        <h2>Opret nyt chatrum</h2>
        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" class="create-room-form">
            <input type="hidden" name="action" value="create_room">
            <input type="text" name="room_name" class="username-input" placeholder="Rumnavnet..." maxlength="100" required>
            <button type="submit" class="btn-primary">Opret rum</button>
        </form>
    </section>

    <!-- Rum-oversigt -->
    <section class="admin-section">
        <h2>Chatrum oversigt</h2>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Navn</th>
                    <th>Online brugere</th>
                    <th>Beskeder</th>
                    <th>Handling</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rooms)): ?>
                    <tr><td colspan="5" style="text-align:center;">Ingen chatrum oprettet.</td></tr>
                <?php else: ?>
                    <?php foreach ($rooms as $room): ?>
                    <tr>
                        <td><?= (int)$room['id'] ?></td>
                        <td><?= htmlspecialchars($room['name']) ?></td>
                        <td><?= (int)$room['online_users'] ?>/<?= MAX_USERS ?></td>
                        <td><?= (int)$room['message_count'] ?></td>
                        <td>
                            <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>"
                                  onsubmit="return confirm('Er du sikker på at du vil slette rummet \'<?= htmlspecialchars($room['name'], ENT_QUOTES) ?>\'? Alle beskeder vil også blive slettet.')">
                                <input type="hidden" name="action" value="delete_room">
                                <input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>">
                                <button type="submit" class="btn-danger">Slet</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<?php endif; ?>
</body>
</html>
