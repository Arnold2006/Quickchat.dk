<?php
require_once __DIR__ . '/config.php';

$room_id  = isset($_GET['room_id'])  ? (int)$_GET['room_id']    : 0;
$username = isset($_GET['username']) ? trim($_GET['username'])   : '';

// Grundlæggende validering
if (!$room_id || mb_strlen($username) < 2 || mb_strlen($username) > MAX_USERNAME_LEN) {
    header('Location: index.php');
    exit;
}

// APCu skal være tilgængeligt
if (!apcu_ok()) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="da"><head><meta charset="UTF-8">'
        . '<title>Fejl</title><link rel="stylesheet" href="css/style.css"></head><body>'
        . '<div class="error-page"><h1>⚠ APCu ikke aktiveret</h1>'
        . '<p>QuickChat.dk kræver PHP-udvidelsen <strong>APCu</strong>.<br>'
        . 'Kontakt serveradministratoren for at aktivere den.</p></div></body></html>';
    exit;
}

$username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');

// Tjek at rummet eksisterer
$stmt = db()->prepare("
    SELECT r.id, r.name, c.id AS cat_id, c.name AS cat_name
    FROM rooms r
    JOIN categories c ON r.category_id = c.id
    WHERE r.id = :id
");
$stmt->execute([':id' => $room_id]);
$room = $stmt->fetch();
if (!$room) {
    header('Location: index.php');
    exit;
}
$room_name = htmlspecialchars($room['name'], ENT_QUOTES, 'UTF-8');

// Generer unik session-token
$token = bin2hex(random_bytes(16));

// Tjek om brugernavn er ledigt
if (!qc_username_available($room_id, $username, $token)) {
    // Vis fejl i dette vindue (brugeren åbnede et nyt vindue fra category.php)
    echo '<!DOCTYPE html><html lang="da"><head><meta charset="UTF-8">'
        . '<title>Brugernavn optaget</title><link rel="stylesheet" href="css/style.css"></head><body>'
        . '<div class="error-page"><h1>Brugernavn optaget</h1>'
        . '<p>"' . $username . '" er allerede i brug i dette rum. Vælg et andet navn.</p>'
        . '<button class="btn-secondary" onclick="window.close()">Luk</button></div></body></html>';
    exit;
}

// Tjek om rummet er fuldt
$others = array_filter(
    qc_active_usernames($room_id),
    fn($u) => strtolower($u) !== strtolower($username)
);
if (count($others) >= MAX_USERS) {
    echo '<!DOCTYPE html><html lang="da"><head><meta charset="UTF-8">'
        . '<title>Rummet er fuldt</title><link rel="stylesheet" href="css/style.css"></head><body>'
        . '<div class="error-page"><h1>Rummet er fuldt</h1>'
        . '<p>Maksimalt ' . MAX_USERS . ' brugere tilladt. Prøv igen om lidt.</p>'
        . '<button class="btn-secondary" onclick="window.close()">Luk</button></div></body></html>';
    exit;
}

// Registrér bruger og tilføj system-besked
qc_touch_user($room_id, $username, $token);
qc_add_message($room_id, '__system__', $username . ' er trådt ind i rummet');
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(SITE_NAME) ?> – <?= $room_name ?></title>
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
            <span class="self-label">Du: <strong><?= $username ?></strong></span>
            <span class="room-badge" id="room-badge">0/<?= MAX_USERS ?></span>
        </div>
    </div>

    <!-- Beskeder + bruger-sidebar -->
    <div class="chat-main">
        <div class="messages-area" id="messages-area">
            <div id="messages-list"></div>
        </div>

        <div class="users-sidebar">
            <h3>Online</h3>
            <ul id="users-list"></ul>
        </div>
    </div>

    <!-- Inputfelt -->
    <div class="chat-input-area" id="chat-input-area">
        <div class="input-row">
            <label for="recipient-select">Tale i Rødt:</label>
            <select id="recipient-select" class="select-input">
                <option value="">— Alle (offentlig) —</option>
            </select>
            <button class="btn-refresh" onclick="fetchUsers()" title="Opdater brugerliste">
                ↻ Opdater liste
            </button>
        </div>
        <!-- Billedforhåndsvisning (vises kun når et billede er droppet) -->
        <div id="img-preview-area" class="img-preview-area" style="display:none;">
            <img id="img-preview-thumb" src="" alt="Forhåndsvisning">
            <button class="btn-img-cancel" onclick="cancelImage()" title="Fjern billede">✕</button>
        </div>
        <div class="input-row input-send-row">
            <input
                type="text"
                id="message-input"
                class="text-input message-input"
                placeholder="Skriv en besked… (eller træk et billede hertil)"
                maxlength="<?= MAX_MESSAGE_LEN ?>"
                autocomplete="off">
            <button class="btn-send" onclick="sendMessage()">Send</button>
        </div>
    </div>

</div><!-- /.chat-container -->

<script>
const ROOM_ID  = <?= $room_id ?>;
const USERNAME = <?= json_encode($username) ?>;
const TOKEN    = <?= json_encode($token) ?>;
const MAX_U    = <?= MAX_USERS ?>;

let lastId = 0;

// ── Hjælpefunktioner ────────────────────────────────────────────────────────

function esc(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function fmtTime(ts) {
    const d  = new Date(ts * 1000);
    const hh = String(d.getHours()).padStart(2,'0');
    const mm = String(d.getMinutes()).padStart(2,'0');
    return hh + ':' + mm;
}

// ── Billede-hjælper ──────────────────────────────────────────────────────────

const IMG_RE = /^\[IMG\](data:image\/(?:jpeg|png|gif|webp);base64,[A-Za-z0-9+/=]+)\[\/IMG\]$/;

function isImageMsg(text) { return IMG_RE.test(text); }

function buildImageEl(dataUrl) {
    const wrap = document.createElement('span');
    wrap.className = 'msg-img-wrap';

    const img = document.createElement('img');
    img.src       = dataUrl;
    img.className = 'msg-img';
    img.alt       = 'Billede';

    const btn = document.createElement('button');
    btn.className   = 'btn-gem';
    btn.textContent = '💾 Gem';
    btn.title       = 'Gem billede til din disk';
    btn.addEventListener('click', () => {
        const ext = dataUrl.startsWith('data:image/png') ? 'png'
                  : dataUrl.startsWith('data:image/gif') ? 'gif'
                  : dataUrl.startsWith('data:image/webp') ? 'webp'
                  : 'jpg';
        const a   = document.createElement('a');
        a.href     = dataUrl;
        a.download = 'billede.' + ext;
        a.click();
    });

    wrap.appendChild(img);
    wrap.appendChild(btn);
    return wrap;
}

// ── Rendering ────────────────────────────────────────────────────────────────

function renderMsg(msg) {
    const d = document.createElement('div');
    if (msg.username === '__system__') {
        d.className = 'msg msg-system';
        d.innerHTML = '<span>' + esc(msg.message) + '</span>';
    } else if (msg.is_private) {
        d.className = 'msg msg-private';
        const toLabel = msg.recipient ? ' → ' + esc(msg.recipient) : '';
        d.innerHTML =
            '<span class="msg-time">' + fmtTime(msg.ts) + '</span> ' +
            '<span class="msg-author">' + esc(msg.username) + toLabel + ':</span> ';
        if (isImageMsg(msg.message)) {
            d.appendChild(buildImageEl(msg.message.match(IMG_RE)[1]));
        } else {
            const t = document.createElement('span');
            t.className   = 'msg-text';
            t.textContent = msg.message;
            d.appendChild(t);
        }
    } else {
        d.className = 'msg msg-public';
        d.innerHTML =
            '<span class="msg-time">' + fmtTime(msg.ts) + '</span> ' +
            '<span class="msg-author">' + esc(msg.username) + ':</span> ';
        if (isImageMsg(msg.message)) {
            d.appendChild(buildImageEl(msg.message.match(IMG_RE)[1]));
        } else {
            const t = document.createElement('span');
            t.className   = 'msg-text';
            t.textContent = msg.message;
            d.appendChild(t);
        }
    }
    return d;
}

// ── Fetch beskeder ───────────────────────────────────────────────────────────

function fetchMessages() {
    fetch('api/fetch.php?room_id=' + ROOM_ID
          + '&last_id=' + lastId
          + '&viewer='  + encodeURIComponent(USERNAME))
        .then(r => r.json())
        .then(data => {
            if (!data.messages || !data.messages.length) return;
            const list   = document.getElementById('messages-list');
            const area   = document.getElementById('messages-area');
            const bottom = area.scrollHeight - area.scrollTop - area.clientHeight < 80;
            data.messages.forEach(msg => {
                list.appendChild(renderMsg(msg));
                if (msg.id > lastId) lastId = msg.id;
            });
            if (bottom) area.scrollTop = area.scrollHeight;
        })
        .catch(() => {});
}

// ── Fetch brugere ────────────────────────────────────────────────────────────

function fetchUsers() {
    fetch('api/users.php?room_id=' + ROOM_ID)
        .then(r => r.json())
        .then(users => {
            const list    = document.getElementById('users-list');
            const select  = document.getElementById('recipient-select');
            const badge   = document.getElementById('room-badge');
            const current = select.value;

            badge.textContent = users.length + '/' + MAX_U;

            list.innerHTML = '';
            users.forEach(u => {
                const li = document.createElement('li');
                li.className  = (u === USERNAME) ? 'user-self' : 'user-other';
                li.textContent = u + (u === USERNAME ? ' (dig)' : '');
                list.appendChild(li);
            });

            select.innerHTML = '<option value="">— Alle (offentlig) —</option>';
            users.forEach(u => {
                if (u === USERNAME) return;
                const opt = document.createElement('option');
                opt.value     = u;
                opt.textContent = u;
                if (u === current) opt.selected = true;
                select.appendChild(opt);
            });
        })
        .catch(() => {});
}

// ── Send besked ──────────────────────────────────────────────────────────────

let pendingImage = null; // base64 data-URL eller null

function sendMessage() {
    const input     = document.getElementById('message-input');
    const recipient = document.getElementById('recipient-select').value;
    const text      = input.value.trim();

    if (!pendingImage && !text) return;

    function doSend(msg) {
        const fd = new FormData();
        fd.append('room_id',   ROOM_ID);
        fd.append('username',  USERNAME);
        fd.append('message',   msg);
        fd.append('recipient', recipient);
        fd.append('token',     TOKEN);
        return fetch('api/send.php', { method: 'POST', body: fd })
            .then(r => { if (r.ok) fetchMessages(); })
            .catch(() => {});
    }

    // Send eventuel tekst først, derefter billede
    const jobs = [];
    if (text)         { input.value = ''; updatePrivateStyle(); jobs.push(() => doSend(text)); }
    if (pendingImage) { cancelImage(); jobs.push(() => doSend('[IMG]' + pendingImage + '[/IMG]')); }
    jobs.reduce((p, fn) => p.then(fn), Promise.resolve());
}

// ── Heartbeat ────────────────────────────────────────────────────────────────

function heartbeat() {
    fetch('api/heartbeat.php?room_id=' + ROOM_ID
          + '&username=' + encodeURIComponent(USERNAME)
          + '&token='    + TOKEN)
        .catch(() => {});
}

// ── Forlad rum ───────────────────────────────────────────────────────────────

function leaveRoom() {
    navigator.sendBeacon(
        'api/leave.php?room_id=' + ROOM_ID
        + '&username=' + encodeURIComponent(USERNAME)
        + '&token='    + TOKEN
    );
}

// ── "Tale i Rødt"-styling ────────────────────────────────────────────────────

function updatePrivateStyle() {
    const input     = document.getElementById('message-input');
    const recipient = document.getElementById('recipient-select').value;
    if (recipient) {
        input.classList.add('private-mode');
        input.placeholder = 'Privat besked til ' + recipient + '…';
    } else {
        input.classList.remove('private-mode');
        input.placeholder = 'Skriv en besked…';
    }
}

// ── Billede Drag & Drop ──────────────────────────────────────────────────────

const MAX_IMG_BYTES = <?= MAX_IMAGE_SIZE ?>;

function cancelImage() {
    pendingImage = null;
    const area  = document.getElementById('img-preview-area');
    const thumb = document.getElementById('img-preview-thumb');
    area.style.display  = 'none';
    thumb.src           = '';
}

function handleImageFile(file) {
    if (!file || !file.type.startsWith('image/')) return;
    if (file.size > MAX_IMG_BYTES) {
        alert('Billedet er for stort. Maksimum er ca. 375 KB.');
        return;
    }
    const reader = new FileReader();
    reader.onload = e => {
        pendingImage = e.target.result;
        const thumb = document.getElementById('img-preview-thumb');
        const area  = document.getElementById('img-preview-area');
        thumb.src          = pendingImage;
        area.style.display = 'flex';
    };
    reader.readAsDataURL(file);
}

(function setupDragDrop() {
    const zone = document.getElementById('chat-input-area');
    zone.addEventListener('dragover', e => {
        const hasImage = Array.from(e.dataTransfer.items || []).some(
            i => i.kind === 'file' && i.type.startsWith('image/')
        );
        if (!hasImage) return;
        e.preventDefault();
        zone.classList.add('drag-over');
    });
    zone.addEventListener('dragleave', e => {
        if (!zone.contains(e.relatedTarget)) zone.classList.remove('drag-over');
    });
    zone.addEventListener('drop', e => {
        zone.classList.remove('drag-over');
        const file = Array.from(e.dataTransfer.files || []).find(
            f => f.type.startsWith('image/')
        );
        if (!file) return;
        e.preventDefault();
        handleImageFile(file);
    });
})();

// ── Events ───────────────────────────────────────────────────────────────────

document.getElementById('message-input').addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});

document.getElementById('recipient-select').addEventListener('change', updatePrivateStyle);

window.addEventListener('beforeunload', leaveRoom);

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') { heartbeat(); fetchUsers(); }
});

// ── Start ────────────────────────────────────────────────────────────────────

fetchMessages();
fetchUsers();
setInterval(() => { fetchMessages(); fetchUsers(); }, 2000);
setInterval(heartbeat, 15000);
</script>
</body>
</html>
