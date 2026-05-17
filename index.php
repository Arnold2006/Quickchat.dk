<?php
require_once __DIR__ . '/config.php';

// Hent alle rum med brugerantal
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
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuickChat.dk – Vælg chatrum</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="lobby-container">
    <header class="lobby-header">
        <h1>💬 QuickChat.dk</h1>
        <p class="tagline">Anonym chat – ingen registrering nødvendig</p>
    </header>

    <div class="rooms-grid" id="rooms-grid">
        <?php foreach ($rooms as $room): ?>
        <div class="room-card" data-room-id="<?= (int)$room['id'] ?>" data-room-name="<?= htmlspecialchars($room['name'], ENT_QUOTES) ?>">
            <div class="room-info">
                <h2><?= htmlspecialchars($room['name']) ?></h2>
                <span class="user-count">
                    <span class="count-number" id="count-<?= (int)$room['id'] ?>"><?= (int)$room['online_users'] ?></span>/<?= MAX_USERS ?> brugere online
                </span>
            </div>
            <button class="btn-enter" onclick="openJoinModal(<?= (int)$room['id'] ?>, '<?= htmlspecialchars($room['name'], ENT_QUOTES) ?>')">
                Gå ind →
            </button>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Join modal -->
<div id="join-modal" class="modal-overlay" style="display:none;">
    <div class="modal">
        <h2>Gå ind i <span id="modal-room-name"></span></h2>
        <p>Vælg et brugernavn (anonymt – ingen registrering):</p>
        <input type="text" id="username-input" class="username-input" placeholder="Dit brugernavn..." maxlength="50" autofocus>
        <div id="username-error" class="error-msg" style="display:none;"></div>
        <div class="modal-buttons">
            <button class="btn-primary" onclick="joinRoom()">Gå ind</button>
            <button class="btn-secondary" onclick="closeModal()">Annuller</button>
        </div>
    </div>
</div>

<script>
let currentRoomId = null;
let currentRoomName = null;

function openJoinModal(roomId, roomName) {
    currentRoomId = roomId;
    currentRoomName = roomName;
    document.getElementById('modal-room-name').textContent = roomName;
    document.getElementById('username-input').value = '';
    document.getElementById('username-error').style.display = 'none';
    document.getElementById('join-modal').style.display = 'flex';
    setTimeout(() => document.getElementById('username-input').focus(), 100);
}

function closeModal() {
    document.getElementById('join-modal').style.display = 'none';
    currentRoomId = null;
    currentRoomName = null;
}

function joinRoom() {
    const username = document.getElementById('username-input').value.trim();
    const errorEl = document.getElementById('username-error');

    if (!username) {
        errorEl.textContent = 'Du skal angive et brugernavn.';
        errorEl.style.display = 'block';
        return;
    }
    if (username.length < 2) {
        errorEl.textContent = 'Brugernavnet skal være mindst 2 tegn.';
        errorEl.style.display = 'block';
        return;
    }

    // Tjek om brugernavn allerede er i brug
    fetch('api/users.php?room_id=' + currentRoomId)
        .then(r => r.json())
        .then(users => {
            const taken = users.some(u => u.username.toLowerCase() === username.toLowerCase());
            if (taken) {
                errorEl.textContent = 'Dette brugernavn er allerede i brug i dette rum. Vælg et andet.';
                errorEl.style.display = 'block';
                return;
            }
            const url = 'chat.php?room_id=' + currentRoomId
                      + '&username=' + encodeURIComponent(username)
                      + '&room_name=' + encodeURIComponent(currentRoomName);
            window.open(url, '_blank', 'width=1000,height=700,resizable=yes,scrollbars=yes,noopener,noreferrer');
            closeModal();
        })
        .catch(() => {
            errorEl.textContent = 'Kunne ikke oprette forbindelse til serveren. Prøv igen.';
            errorEl.style.display = 'block';
        });
}

// Tillad Enter-tast i brugernavnsfeltet
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('username-input').addEventListener('keydown', e => {
        if (e.key === 'Enter') joinRoom();
        if (e.key === 'Escape') closeModal();
    });
});

// Luk modal ved klik udenfor
document.getElementById('join-modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Auto-opdater brugerantal hvert 10 sekunder
function refreshUserCounts() {
    fetch('api/rooms.php')
        .then(r => r.json())
        .then(rooms => {
            rooms.forEach(room => {
                const el = document.getElementById('count-' + room.id);
                if (el) el.textContent = room.online_users;
            });
        })
        .catch(() => {});
}

setInterval(refreshUserCounts, 10000);
</script>
</body>
</html>
