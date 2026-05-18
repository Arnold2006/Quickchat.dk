<?php
require_once __DIR__ . '/config.php';

$cat_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$cat_id) { header('Location: index.php'); exit; }

$stmt = db()->prepare("SELECT id, name, description, icon FROM categories WHERE id = :id");
$stmt->execute([':id' => $cat_id]);
$category = $stmt->fetch();
if (!$category) { header('Location: index.php'); exit; }

$stmt = db()->prepare("
    SELECT id, name
    FROM rooms
    WHERE category_id = :cid
    ORDER BY sort_order, name
");
$stmt->execute([':cid' => $cat_id]);
$rooms = $stmt->fetchAll();

foreach ($rooms as &$room) {
    $room['online_users'] = apcu_ok() ? qc_user_count((int)$room['id']) : 0;
}
unset($room);
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(SITE_NAME) ?> – <?= htmlspecialchars($category['name']) ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="page">
    <header class="site-header">
        <div class="header-inner">
            <a href="index.php" class="back-link">← Alle kategorier</a>
            <h1 class="site-logo">
                <?= htmlspecialchars($category['icon']) ?>
                <?= htmlspecialchars($category['name']) ?>
            </h1>
            <p class="site-tagline"><?= htmlspecialchars($category['description']) ?></p>
        </div>
    </header>

    <main class="lobby">
        <p class="section-label">Vælg et chatrum</p>
        <div class="rooms-grid">
            <?php foreach ($rooms as $room): ?>
            <div class="room-card">
                <div class="room-info">
                    <h3><?= htmlspecialchars($room['name']) ?></h3>
                    <span class="user-count">
                        <span id="uc-<?= (int)$room['id'] ?>"><?= (int)$room['online_users'] ?></span>/<?= MAX_USERS ?>
                    </span>
                </div>
                <button
                    class="btn-enter"
                    data-room-id="<?= (int)$room['id'] ?>"
                    data-room-name="<?= htmlspecialchars($room['name'], ENT_QUOTES) ?>">
                    Gå ind →
                </button>
            </div>
            <?php endforeach; ?>
        </div>
    </main>

    <footer class="site-footer">
        <p>100 % anonymt &nbsp;·&nbsp; ingen registrering &nbsp;·&nbsp; ingen logning</p>
    </footer>
</div>

<!-- Brugernavn-modal -->
<div id="join-modal" class="modal-overlay" style="display:none;">
    <div class="modal">
        <h2>Gå ind i <span id="modal-room-name"></span></h2>
        <p>Vælg et brugernavn – ingen registrering kræves:</p>
        <input
            type="text"
            id="username-input"
            class="text-input"
            placeholder="Dit brugernavn…"
            maxlength="<?= MAX_USERNAME_LEN ?>"
            autocomplete="off">
        <div id="username-error" class="error-msg" style="display:none;"></div>
        <div class="modal-buttons">
            <button class="btn-primary" onclick="joinRoom()">Gå ind</button>
            <button class="btn-secondary" onclick="closeModal()">Annuller</button>
        </div>
    </div>
</div>

<script>
let activeRoomId   = null;
let activeRoomName = null;

function openModal(roomId, roomName) {
    activeRoomId   = roomId;
    activeRoomName = roomName;
    document.getElementById('modal-room-name').textContent  = roomName;
    document.getElementById('username-input').value          = '';
    document.getElementById('username-error').style.display = 'none';
    document.getElementById('join-modal').style.display     = 'flex';
    setTimeout(() => document.getElementById('username-input').focus(), 80);
}

function closeModal() {
    document.getElementById('join-modal').style.display = 'none';
    activeRoomId = null;
}

function joinRoom() {
    const username = document.getElementById('username-input').value.trim();
    const errorEl  = document.getElementById('username-error');
    errorEl.style.display = 'none';

    if (username.length < 2) {
        errorEl.textContent   = 'Brugernavnet skal være mindst 2 tegn.';
        errorEl.style.display = 'block';
        return;
    }
    if (username.length > <?= MAX_USERNAME_LEN ?>) {
        errorEl.textContent   = 'Brugernavnet må maks. være <?= MAX_USERNAME_LEN ?> tegn.';
        errorEl.style.display = 'block';
        return;
    }

    const url = 'chat.php?room_id=' + activeRoomId
              + '&username=' + encodeURIComponent(username);

    closeModal();
    window.location.href = url;
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('username-input').addEventListener('keydown', e => {
        if (e.key === 'Enter')  joinRoom();
        if (e.key === 'Escape') closeModal();
    });

    document.querySelectorAll('.btn-enter').forEach(btn => {
        btn.addEventListener('click', () => {
            openModal(
                parseInt(btn.dataset.roomId,  10),
                btn.dataset.roomName
            );
        });
    });
});

document.getElementById('join-modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Opdater brugertal hvert 5. sekund
function refreshCounts() {
    fetch('api/rooms.php?cat_id=<?= (int)$cat_id ?>')
        .then(r => r.json())
        .then(list => list.forEach(r => {
            const el = document.getElementById('uc-' + r.id);
            if (el) el.textContent = r.online_users;
        }))
        .catch(() => {});
}
setInterval(refreshCounts, 5000);
</script>
</body>
</html>
