<?php
require_once __DIR__ . '/../config.php';

// Lag 1: skjult URL-token
$token = $_GET['token'] ?? '';
if ($token !== ADMIN_TOKEN) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}

// Lag 2: password-login
$error        = '';
$loginNeeded  = !($_SESSION['admin_auth'] ?? false);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'login') {
        if (($_POST['password'] ?? '') === ADMIN_PASSWORD) {
            $_SESSION['admin_auth'] = true;
            $loginNeeded = false;
        } else {
            $error = 'Forkert adgangskode.';
        }
    } elseif (!$loginNeeded) {
        $msg = '';
        switch ($_POST['action']) {

            case 'create_category':
                $name = trim($_POST['cat_name'] ?? '');
                $desc = trim($_POST['cat_desc'] ?? '');
                $icon = trim($_POST['cat_icon'] ?? '💬');
                if ($name !== '' && mb_strlen($name) <= 100) {
                    $s = db()->prepare(
                        "INSERT INTO categories (name, description, icon) VALUES (:n,:d,:i)"
                    );
                    $s->execute([':n' => $name, ':d' => $desc, ':i' => $icon]);
                    $msg = 'Kategori "' . htmlspecialchars($name) . '" oprettet.';
                } else {
                    $msg = 'Ugyldigt kategorinavn.';
                }
                break;

            case 'delete_category':
                $id = (int)($_POST['cat_id'] ?? 0);
                if ($id) {
                    db()->prepare("DELETE FROM categories WHERE id = :id")->execute([':id' => $id]);
                    $msg = 'Kategori slettet.';
                }
                break;

            case 'create_room':
                $name   = trim($_POST['room_name'] ?? '');
                $cat_id = (int)($_POST['cat_id'] ?? 0);
                if ($name !== '' && $cat_id && mb_strlen($name) <= 100) {
                    $s = db()->prepare(
                        "INSERT INTO rooms (category_id, name) VALUES (:c,:n)"
                    );
                    $s->execute([':c' => $cat_id, ':n' => $name]);
                    $msg = 'Rum "' . htmlspecialchars($name) . '" oprettet.';
                } else {
                    $msg = 'Ugyldigt rumnavn eller manglende kategori.';
                }
                break;

            case 'delete_room':
                $id = (int)($_POST['room_id'] ?? 0);
                if ($id) {
                    db()->prepare("DELETE FROM rooms WHERE id = :id")->execute([':id' => $id]);
                    $msg = 'Rum slettet.';
                }
                break;

            case 'save_front_page_text':
                $text = $_POST['front_page_text'] ?? '';
                $s = db()->prepare(
                    "INSERT INTO site_config (`key`, `value`) VALUES ('front_page_text', :v)
                     ON DUPLICATE KEY UPDATE `value` = :v"
                );
                $s->execute([':v' => $text]);
                $msg = 'Forsidetekst gemt.';
                break;

            case 'logout':
                $_SESSION['admin_auth'] = false;
                $loginNeeded = true;
                break;
        }
    }
}

// Data til admin-panel
$categories   = [];
$rooms_by_cat = [];
$front_page_text = '';
if (!$loginNeeded) {
    $categories = db()->query(
        "SELECT * FROM categories ORDER BY sort_order, name"
    )->fetchAll();

    $all_rooms = db()->query("
        SELECT r.*, c.name AS cat_name
        FROM rooms r
        JOIN categories c ON r.category_id = c.id
        ORDER BY c.sort_order, r.sort_order, r.name
    ")->fetchAll();

    foreach ($all_rooms as $r) {
        $rooms_by_cat[(int)$r['category_id']][] = $r;
    }

    $row = db()->query("SELECT `value` FROM site_config WHERE `key` = 'front_page_text'")->fetch();
    $front_page_text = $row ? $row['value'] : '';
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – <?= htmlspecialchars(SITE_NAME) ?></title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="admin-body">

<?php if ($loginNeeded): ?>

<div class="admin-login-wrap">
    <div class="admin-login-box">
        <h1>🔒 Admin Login</h1>
        <?php if ($error): ?>
            <div class="error-msg" style="display:block;margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="login">
            <input
                type="password"
                name="password"
                class="text-input"
                placeholder="Adgangskode…"
                autofocus
                required>
            <button type="submit" class="btn-primary" style="margin-top:1rem;width:100%;">
                Log ind
            </button>
        </form>
    </div>
</div>

<?php else: ?>

<div class="admin-container">
    <header class="admin-header">
        <h1>⚙️ Admin – <?= htmlspecialchars(SITE_NAME) ?></h1>
        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" style="display:inline;">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="btn-secondary">Log ud</button>
        </form>
    </header>

    <?php if (!empty($msg)): ?>
        <div class="admin-msg"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- ── Forsidetekst ─────────────────────────────────────────── -->
    <section class="admin-section">
        <h2>Forsidetekst</h2>
        <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:.75rem;">
            Denne tekst vises på forsiden over kategorierne. Lad feltet være tomt for at skjule den.
        </p>
        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" class="admin-form" style="flex-direction:column;align-items:stretch;">
            <input type="hidden" name="action" value="save_front_page_text">
            <textarea name="front_page_text" class="text-input" rows="5"
                      placeholder="Skriv en velkomsttekst til forsiden…"
                      maxlength="2000"
                      style="resize:vertical;"><?= htmlspecialchars($front_page_text) ?></textarea>
            <button type="submit" class="btn-primary" style="align-self:flex-start;margin-top:.5rem;">Gem tekst</button>
        </form>
    </section>

    <!-- ── Kategorier ────────────────────────────────────────── -->
    <section class="admin-section">
        <h2>Kategorier</h2>

        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" class="admin-form">
            <input type="hidden" name="action" value="create_category">
            <input type="text"  name="cat_name" class="text-input" placeholder="Navn…"        maxlength="100" required>
            <input type="text"  name="cat_desc" class="text-input" placeholder="Beskrivelse…" maxlength="255">
            <input type="hidden" name="cat_icon" id="cat_icon_value" value="💬">
            <button type="button" class="icon-picker-btn" id="openIconPicker"
                    aria-haspopup="dialog" aria-controls="iconPickerModal">
                <span class="picker-preview" id="iconPickerPreview">💬</span>
                <span class="picker-label">Vælg ikon ▾</span>
            </button>
            <button type="submit" class="btn-primary">Opret kategori</button>
        </form>

        <!-- Icon picker modal -->
        <div class="icon-modal-overlay" id="iconPickerModal" role="dialog" aria-modal="true" aria-label="Vælg ikon">
            <div class="icon-modal">
                <div class="icon-modal-header">
                    <h3>Vælg et ikon</h3>
                    <button class="icon-modal-close" id="closeIconPicker" aria-label="Luk">✕</button>
                </div>

                <p class="icon-group-label">Generel chat</p>
                <div class="icon-grid">
                    <?php foreach (['💬','🗨️','💭','🗣️','👥','🌐','🏠','📣','🔊','🤝','🙋','📢'] as $ic): ?>
                    <button type="button" class="icon-option" data-icon="<?= htmlspecialchars($ic, ENT_QUOTES) ?>"><?= $ic ?></button>
                    <?php endforeach; ?>
                </div>

                <p class="icon-group-label">Kontakt &amp; Dating</p>
                <div class="icon-grid">
                    <?php foreach (['❤️','💕','💝','💖','💌','🌹','😍','🥰','😘','💑','👫','🫶','💏','🕊️'] as $ic): ?>
                    <button type="button" class="icon-option" data-icon="<?= htmlspecialchars($ic, ENT_QUOTES) ?>"><?= $ic ?></button>
                    <?php endforeach; ?>
                </div>

                <p class="icon-group-label">Voksenchat / Sex chat</p>
                <div class="icon-grid">
                    <?php foreach (['💋','❤️‍🔥','🌶️','😈','👄','🫦','🌙','🍒','🎭','💃'] as $ic): ?>
                    <button type="button" class="icon-option" data-icon="<?= htmlspecialchars($ic, ENT_QUOTES) ?>"><?= $ic ?></button>
                    <?php endforeach; ?>
                </div>

                <p class="icon-group-label">Aldersbestemt / Begrænset adgang</p>
                <div class="icon-grid">
                    <?php foreach (['🔞','⚠️','🚫','🔐','🔒','18️⃣','🎰','🃏','🍺','🎲','🛑','🔑'] as $ic): ?>
                    <button type="button" class="icon-option" data-icon="<?= htmlspecialchars($ic, ENT_QUOTES) ?>"><?= $ic ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <table class="admin-table">
            <thead>
                <tr><th>ID</th><th>Ikon</th><th>Navn</th><th>Beskrivelse</th><th>Rum</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($categories)): ?>
                <tr><td colspan="6" class="td-empty">Ingen kategorier.</td></tr>
            <?php else: foreach ($categories as $cat): ?>
                <tr>
                    <td><?= (int)$cat['id'] ?></td>
                    <td><?= htmlspecialchars($cat['icon']) ?></td>
                    <td><?= htmlspecialchars($cat['name']) ?></td>
                    <td><?= htmlspecialchars($cat['description']) ?></td>
                    <td><?= count($rooms_by_cat[(int)$cat['id']] ?? []) ?></td>
                    <td>
                        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>"
                              onsubmit="return confirm('Slet kategorien og alle dens rum?')">
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="cat_id" value="<?= (int)$cat['id'] ?>">
                            <button type="submit" class="btn-danger">Slet</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </section>

    <!-- ── Chatrum ───────────────────────────────────────────── -->
    <section class="admin-section">
        <h2>Chatrum</h2>

        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" class="admin-form">
            <input type="hidden" name="action" value="create_room">
            <select name="cat_id" class="select-input" required>
                <option value="">Vælg kategori…</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>">
                        <?= htmlspecialchars($cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="room_name" class="text-input" placeholder="Rumnavn…" maxlength="100" required>
            <button type="submit" class="btn-primary">Opret rum</button>
        </form>

        <?php foreach ($categories as $cat): ?>
        <h3 class="admin-cat-label">
            <?= htmlspecialchars($cat['icon']) ?> <?= htmlspecialchars($cat['name']) ?>
        </h3>
        <table class="admin-table">
            <thead>
                <tr><th>ID</th><th>Navn</th><th>Online</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($rooms_by_cat[(int)$cat['id']])): ?>
                <tr><td colspan="4" class="td-empty">Ingen rum i denne kategori.</td></tr>
            <?php else: foreach ($rooms_by_cat[(int)$cat['id']] as $room): ?>
                <tr>
                    <td><?= (int)$room['id'] ?></td>
                    <td><?= htmlspecialchars($room['name']) ?></td>
                    <td><?= apcu_ok() ? qc_user_count((int)$room['id']) : '?' ?>/<?= MAX_USERS ?></td>
                    <td>
                        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>"
                              onsubmit="return confirm('Slet rummet?')">
                            <input type="hidden" name="action" value="delete_room">
                            <input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>">
                            <button type="submit" class="btn-danger">Slet</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php endforeach; ?>
    </section>

</div><!-- /.admin-container -->

<?php endif; ?>
<script>
(function () {
    var overlay  = document.getElementById('iconPickerModal');
    if (!overlay) return;
    var preview  = document.getElementById('iconPickerPreview');
    var hidden   = document.getElementById('cat_icon_value');
    var openBtn  = document.getElementById('openIconPicker');
    var closeBtn = document.getElementById('closeIconPicker');

    function openModal() {
        overlay.classList.add('open');
        // Mark the currently selected icon
        overlay.querySelectorAll('.icon-option').forEach(function (btn) {
            btn.classList.toggle('active', btn.dataset.icon === hidden.value);
        });
        var first = overlay.querySelector('.icon-option.active') || overlay.querySelector('.icon-option');
        if (first) first.focus();
    }

    function closeModal() {
        overlay.classList.remove('open');
        openBtn.focus();
    }

    openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);

    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) closeModal();
    });

    overlay.querySelectorAll('.icon-option').forEach(function (btn) {
        btn.addEventListener('click', function () {
            hidden.value  = btn.dataset.icon;
            preview.textContent = btn.dataset.icon;
            closeModal();
        });
    });
}());
</script>
</body>
</html>
