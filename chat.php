<?php
require_once __DIR__ . '/config.php';

$room_id   = isset($_GET['room_id'])   ? (int)$_GET['room_id']            : 0;
$username  = isset($_GET['username'])  ? trim($_GET['username'])           : '';
$room_name = isset($_GET['room_name']) ? trim($_GET['room_name'])          : '';

// Valider input
if (!$room_id || !$username || mb_strlen($username) < 2 || mb_strlen($username) > 50) {
    header('Location: index.php');
    exit;
}

$username  = htmlspecialchars($username,  ENT_QUOTES, 'UTF-8');
$room_name = htmlspecialchars($room_name, ENT_QUOTES, 'UTF-8');

// Tjek at rummet eksisterer
$stmt = db()->prepare("SELECT id, name FROM rooms WHERE id = :id");
$stmt->execute([':id' => $room_id]);
$room = $stmt->fetch();
if (!$room) {
    header('Location: index.php');
    exit;
}
$room_name = htmlspecialchars($room['name'], ENT_QUOTES, 'UTF-8');

// Tjek max brugere (ekskl. den samme session)
$session_id = session_id();
$stmt = db()->prepare("
    SELECT COUNT(*) FROM room_users
    WHERE room_id = :room_id
      AND session_id != :session_id
      AND last_seen >= DATE_SUB(NOW(), INTERVAL :timeout SECOND)
");
$stmt->execute([':room_id' => $room_id, ':session_id' => $session_id, ':timeout' => USER_TIMEOUT]);
$count = (int)$stmt->fetchColumn();

if ($count >= MAX_USERS) {
    echo '<!DOCTYPE html><html lang="da"><head><meta charset="UTF-8"><title>Fuldt</title><link rel="stylesheet" href="css/style.css"></head><body>';
    echo '<div style="display:flex;align-items:center;justify-content:center;height:100vh;">';
    echo '<div class="modal"><h2>Rummet er fuldt</h2><p>Max ' . MAX_USERS . ' brugere er nået. Prøv igen senere.</p>';
    echo '<button class="btn-secondary" onclick="window.close()">Luk</button></div></div></body></html>';
    exit;
}

// Tjek duplikat brugernavn (anden session)
$stmt = db()->prepare("
    SELECT COUNT(*) FROM room_users
    WHERE room_id = :room_id
      AND LOWER(username) = LOWER(:username)
      AND session_id != :session_id
      AND last_seen >= DATE_SUB(NOW(), INTERVAL :timeout SECOND)
");
$stmt->execute([':room_id' => $room_id, ':username' => $username, ':session_id' => $session_id, ':timeout' => USER_TIMEOUT]);
if ((int)$stmt->fetchColumn() > 0) {
    header('Location: index.php?error=username_taken');
    exit;
}

// Registrer/opdater bruger i rum
$stmt = db()->prepare("
    INSERT INTO room_users (room_id, username, session_id, last_seen)
    VALUES (:room_id, :username, :session_id, NOW())
    ON DUPLICATE KEY UPDATE username = :username, last_seen = NOW()
");
$stmt->execute([':room_id' => $room_id, ':username' => $username, ':session_id' => $session_id]);

// Indsæt system-besked om at bruger er trådt ind
$stmt = db()->prepare("
    INSERT INTO messages (room_id, username, message, is_private)
    VALUES (:room_id, '__system__', :message, 0)
");
$stmt->execute([':room_id' => $room_id, ':message' => $username . ' er trådt ind i rummet']);
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuickChat.dk – <?= $room_name ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="chat-body">

<div class="chat-container">
    <!-- Header -->
    <div class="chat-header">
        <div class="chat-header-left">
            <h1>💬 <?= $room_name ?></h1>
        </div>
        <div class="chat-header-right">
            <span class="current-user">Du er: <strong><?= $username ?></strong></span>
        </div>
    </div>

    <!-- Main area: messages + user list -->
    <div class="chat-main">
        <div class="messages-area" id="messages-area">
            <div id="messages-list"></div>
        </div>

        <div class="users-sidebar">
            <h3>Online (0/<?= MAX_USERS ?>)</h3>
            <ul id="users-list"></ul>
        </div>
    </div>

    <!-- Input area -->
    <div class="chat-input-area">
        <div class="input-row">
            <label for="recipient-select" class="recipient-label">Tal i Rødt med:</label>
            <select id="recipient-select" class="recipient-select">
                <option value="">-- Alle (offentlig) --</option>
            </select>
        </div>
        <div class="input-row input-message-row">
            <input type="text" id="message-input" class="message-input" placeholder="Skriv en besked..." maxlength="1000" autocomplete="off">
            <button class="btn-send" id="send-btn" onclick="sendMessage()">Send</button>
        </div>
    </div>
</div>

<script>
const ROOM_ID   = <?= $room_id ?>;
const USERNAME  = <?= json_encode($username) ?>;
let lastId = 0;
let pollTimer = null;
let heartbeatTimer = null;

// ---- Rendering ----

function escapeHtml(text) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(text));
    return d.innerHTML;
}

function renderMessage(msg) {
    const div = document.createElement('div');
    const time = new Date(msg.created_at.replace(' ', 'T')).toLocaleTimeString('da-DK', {hour:'2-digit', minute:'2-digit'});

    if (msg.username === '__system__') {
        div.className = 'msg msg-system';
        div.innerHTML = '<span class="msg-text">' + escapeHtml(msg.message) + '</span>';
    } else if (msg.is_private == 1) {
        div.className = 'msg msg-private';
        const label = msg.recipient ? ' → ' + escapeHtml(msg.recipient) : '';
        div.innerHTML =
            '<span class="msg-time">' + time + '</span> ' +
            '<span class="msg-author">' + escapeHtml(msg.username) + label + ':</span> ' +
            '<span class="msg-text">' + escapeHtml(msg.message) + '</span>';
    } else {
        div.className = 'msg msg-public';
        div.innerHTML =
            '<span class="msg-time">' + time + '</span> ' +
            '<span class="msg-author">' + escapeHtml(msg.username) + ':</span> ' +
            '<span class="msg-text">' + escapeHtml(msg.message) + '</span>';
    }
    return div;
}

// ---- Polling ----

function fetchMessages() {
    fetch('api/fetch.php?room_id=' + ROOM_ID + '&last_id=' + lastId + '&viewer=' + encodeURIComponent(USERNAME))
        .then(r => r.json())
        .then(data => {
            if (data.messages && data.messages.length > 0) {
                const container = document.getElementById('messages-list');
                const area = document.getElementById('messages-area');
                const atBottom = area.scrollHeight - area.scrollTop - area.clientHeight < 60;
                data.messages.forEach(msg => {
                    container.appendChild(renderMessage(msg));
                    if (msg.id > lastId) lastId = msg.id;
                });
                if (atBottom) area.scrollTop = area.scrollHeight;
            }
        })
        .catch(() => {});
}

function fetchUsers() {
    fetch('api/users.php?room_id=' + ROOM_ID)
        .then(r => r.json())
        .then(users => {
            const list = document.getElementById('users-list');
            const select = document.getElementById('recipient-select');
            const currentRecipient = select.value;

            // Update sidebar header
            document.querySelector('.users-sidebar h3').textContent =
                'Online (' + users.length + '/<?= MAX_USERS ?>)';

            // Update users list
            list.innerHTML = '';
            users.forEach(u => {
                const li = document.createElement('li');
                li.className = u.username === USERNAME ? 'user-self' : 'user-other';
                li.textContent = u.username + (u.username === USERNAME ? ' (dig)' : '');
                list.appendChild(li);
            });

            // Update dropdown (keep current selection if possible)
            select.innerHTML = '<option value="">-- Alle (offentlig) --</option>';
            users.forEach(u => {
                if (u.username !== USERNAME) {
                    const opt = document.createElement('option');
                    opt.value = u.username;
                    opt.textContent = u.username;
                    if (u.username === currentRecipient) opt.selected = true;
                    select.appendChild(opt);
                }
            });
        })
        .catch(() => {});
}

function poll() {
    fetchMessages();
    fetchUsers();
}

// ---- Send ----

function sendMessage() {
    const input = document.getElementById('message-input');
    const recipient = document.getElementById('recipient-select').value;
    const message = input.value.trim();

    if (!message) return;

    const formData = new FormData();
    formData.append('room_id', ROOM_ID);
    formData.append('username', USERNAME);
    formData.append('message', message);
    formData.append('recipient', recipient);

    fetch('api/send.php', { method: 'POST', body: formData })
        .then(() => {
            input.value = '';
            fetchMessages();
        })
        .catch(() => {});
}

// ---- Heartbeat ----

function heartbeat() {
    fetch('api/heartbeat.php?room_id=' + ROOM_ID + '&username=' + encodeURIComponent(USERNAME))
        .catch(() => {});
}

// ---- Leave ----

function leaveRoom() {
    navigator.sendBeacon('api/leave.php?room_id=' + ROOM_ID + '&username=' + encodeURIComponent(USERNAME));
}

// ---- Event listeners ----

document.getElementById('message-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
});

window.addEventListener('beforeunload', leaveRoom);

// ---- Start ----

poll();
pollTimer      = setInterval(poll, 2000);
heartbeatTimer = setInterval(heartbeat, 15000);
</script>
</body>
</html>
