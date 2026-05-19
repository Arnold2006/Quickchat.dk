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
                    db()->beginTransaction();
                    $maxOrd = db()->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM rooms WHERE category_id = :c FOR UPDATE");
                    $maxOrd->execute([':c' => $cat_id]);
                    $nextOrd = (int)$maxOrd->fetchColumn() + 1;
                    $s = db()->prepare(
                        "INSERT INTO rooms (category_id, name, sort_order) VALUES (:c,:n,:o)"
                    );
                    $s->execute([':c' => $cat_id, ':n' => $name, ':o' => $nextOrd]);
                    db()->commit();
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

            case 'move_category':
                $id  = (int)($_POST['cat_id']    ?? 0);
                $dir = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';
                if ($id) {
                    $row = db()->prepare("SELECT sort_order FROM categories WHERE id = :id");
                    $row->execute([':id' => $id]);
                    $cur = $row->fetchColumn();
                    if ($cur !== false) {
                        $cur = (int)$cur;
                        if ($dir === 'up') {
                            $neighbour = db()->prepare(
                                "SELECT id, sort_order FROM categories WHERE sort_order < :s ORDER BY sort_order DESC LIMIT 1"
                            );
                        } else {
                            $neighbour = db()->prepare(
                                "SELECT id, sort_order FROM categories WHERE sort_order > :s ORDER BY sort_order ASC LIMIT 1"
                            );
                        }
                        $neighbour->execute([':s' => $cur]);
                        $nb = $neighbour->fetch();
                        if ($nb) {
                            db()->beginTransaction();
                            db()->prepare("UPDATE categories SET sort_order = :s WHERE id = :id")
                                ->execute([':s' => $nb['sort_order'], ':id' => $id]);
                            db()->prepare("UPDATE categories SET sort_order = :s WHERE id = :id")
                                ->execute([':s' => $cur, ':id' => $nb['id']]);
                            db()->commit();
                        }
                    }
                }
                break;

            case 'move_room':
                $id     = (int)($_POST['room_id']   ?? 0);
                $cat_id = (int)($_POST['cat_id']    ?? 0);
                $dir    = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';
                if ($id && $cat_id) {
                    $row = db()->prepare("SELECT sort_order FROM rooms WHERE id = :id AND category_id = :c");
                    $row->execute([':id' => $id, ':c' => $cat_id]);
                    $cur = $row->fetchColumn();
                    if ($cur !== false) {
                        $cur = (int)$cur;
                        if ($dir === 'up') {
                            $neighbour = db()->prepare(
                                "SELECT id, sort_order FROM rooms WHERE category_id = :c AND sort_order < :s ORDER BY sort_order DESC LIMIT 1"
                            );
                        } else {
                            $neighbour = db()->prepare(
                                "SELECT id, sort_order FROM rooms WHERE category_id = :c AND sort_order > :s ORDER BY sort_order ASC LIMIT 1"
                            );
                        }
                        $neighbour->execute([':c' => $cat_id, ':s' => $cur]);
                        $nb = $neighbour->fetch();
                        if ($nb) {
                            db()->beginTransaction();
                            db()->prepare("UPDATE rooms SET sort_order = :s WHERE id = :id")
                                ->execute([':s' => $nb['sort_order'], ':id' => $id]);
                            db()->prepare("UPDATE rooms SET sort_order = :s WHERE id = :id")
                                ->execute([':s' => $cur, ':id' => $nb['id']]);
                            db()->commit();
                        }
                    }
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

            case 'mark_read':
                $id = (int)($_POST['msg_id'] ?? 0);
                if ($id) {
                    db()->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = :id")
                        ->execute([':id' => $id]);
                    $msg = 'Besked markeret som læst.';
                }
                break;

            case 'mark_all_read':
                db()->exec("UPDATE contact_messages SET is_read = 1 WHERE is_read = 0");
                $msg = 'Alle beskeder markeret som læst.';
                break;

            case 'delete_contact_msg':
                $id = (int)($_POST['msg_id'] ?? 0);
                if ($id) {
                    db()->prepare("DELETE FROM contact_messages WHERE id = :id")
                        ->execute([':id' => $id]);
                    $msg = 'Besked slettet.';
                }
                break;

            case 'create_nav_item':
                $label = trim($_POST['nav_label'] ?? '');
                $url   = trim($_POST['nav_url']   ?? '');
                if ($label !== '' && $url !== '' && mb_strlen($label) <= 100 && mb_strlen($url) <= 500) {
                    $maxOrd = (int)db()->query("SELECT COALESCE(MAX(sort_order), 0) FROM nav_items")->fetchColumn();
                    db()->prepare(
                        "INSERT INTO nav_items (label, url, sort_order) VALUES (:l, :u, :o)"
                    )->execute([':l' => $label, ':u' => $url, ':o' => $maxOrd + 1]);
                    $msg = 'Menupunkt "' . htmlspecialchars($label) . '" oprettet.';
                } else {
                    $msg = 'Ugyldigt menupunkt – tjek navn og URL.';
                }
                break;

            case 'delete_nav_item':
                $id = (int)($_POST['nav_id'] ?? 0);
                if ($id) {
                    db()->prepare("DELETE FROM nav_items WHERE id = :id")->execute([':id' => $id]);
                    $msg = 'Menupunkt slettet.';
                }
                break;

            case 'move_nav_item':
                $id  = (int)($_POST['nav_id']    ?? 0);
                $dir = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';
                if ($id) {
                    $row = db()->prepare("SELECT sort_order FROM nav_items WHERE id = :id");
                    $row->execute([':id' => $id]);
                    $cur = $row->fetchColumn();
                    if ($cur !== false) {
                        $cur = (int)$cur;
                        if ($dir === 'up') {
                            $nb = db()->prepare(
                                "SELECT id, sort_order FROM nav_items WHERE sort_order < :s ORDER BY sort_order DESC LIMIT 1"
                            );
                        } else {
                            $nb = db()->prepare(
                                "SELECT id, sort_order FROM nav_items WHERE sort_order > :s ORDER BY sort_order ASC LIMIT 1"
                            );
                        }
                        $nb->execute([':s' => $cur]);
                        $nbRow = $nb->fetch();
                        if ($nbRow) {
                            db()->beginTransaction();
                            db()->prepare("UPDATE nav_items SET sort_order = :s WHERE id = :id")
                                ->execute([':s' => $nbRow['sort_order'], ':id' => $id]);
                            db()->prepare("UPDATE nav_items SET sort_order = :s WHERE id = :id")
                                ->execute([':s' => $cur, ':id' => $nbRow['id']]);
                            db()->commit();
                        }
                    }
                }
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
$contact_messages = [];
$unread_count = 0;
$nav_items    = [];
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

    $contact_messages = db()->query(
        "SELECT * FROM contact_messages ORDER BY created_at DESC"
    )->fetchAll();

    $unread_count = (int)db()->query(
        "SELECT COUNT(*) FROM contact_messages WHERE is_read = 0"
    )->fetchColumn();

    $nav_items = qc_nav_items();
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
        <h1>⚙️ Admin – <?= htmlspecialchars(SITE_NAME) ?>
            <?php if ($unread_count > 0): ?>
                <span class="admin-notif-badge"><?= $unread_count ?></span>
            <?php endif; ?>
        </h1>
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

    <!-- ── Navigationsmenu ───────────────────────────────────────── -->
    <section class="admin-section">
        <h2>Navigationsmenu</h2>
        <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:.75rem;">
            Disse links vises i menubjælken over velkomstteksten på alle sider.
        </p>

        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" class="admin-form">
            <input type="hidden" name="action" value="create_nav_item">
            <input type="text" name="nav_label" class="text-input" placeholder="Titel (fx ✉️ Skriv til Admin)" maxlength="100" required>
            <input type="text" name="nav_url"   class="text-input" placeholder="URL (fx contact.php)"          maxlength="500" required>
            <button type="submit" class="btn-primary">Tilføj menupunkt</button>
        </form>

        <table class="admin-table">
            <thead>
                <tr><th>ID</th><th>Titel</th><th>URL</th><th>Rækkefølge</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($nav_items)): ?>
                <tr><td colspan="5" class="td-empty">Ingen menupunkter endnu.</td></tr>
            <?php else: foreach ($nav_items as $ni => $item): ?>
                <tr>
                    <td><?= (int)$item['id'] ?></td>
                    <td><?= htmlspecialchars($item['label']) ?></td>
                    <td><a href="../<?= htmlspecialchars($item['url'], ENT_QUOTES) ?>" target="_blank" style="color:var(--accent);"><?= htmlspecialchars($item['url']) ?></a></td>
                    <td class="sort-btns">
                        <?php if ($ni > 0): ?>
                        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" style="display:inline;">
                            <input type="hidden" name="action"    value="move_nav_item">
                            <input type="hidden" name="nav_id"    value="<?= (int)$item['id'] ?>">
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="btn-move" title="Flyt op">▲</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($ni < count($nav_items) - 1): ?>
                        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" style="display:inline;">
                            <input type="hidden" name="action"    value="move_nav_item">
                            <input type="hidden" name="nav_id"    value="<?= (int)$item['id'] ?>">
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="btn-move" title="Flyt ned">▼</button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>"
                              onsubmit="return confirm('Slet dette menupunkt?')">
                            <input type="hidden" name="action" value="delete_nav_item">
                            <input type="hidden" name="nav_id" value="<?= (int)$item['id'] ?>">
                            <button type="submit" class="btn-danger">Slet</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
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
                <tr><th>ID</th><th>Ikon</th><th>Navn</th><th>Beskrivelse</th><th>Rum</th><th>Rækkefølge</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($categories)): ?>
                <tr><td colspan="7" class="td-empty">Ingen kategorier.</td></tr>
            <?php else: foreach ($categories as $i => $cat): ?>
                <tr>
                    <td><?= (int)$cat['id'] ?></td>
                    <td><?= htmlspecialchars($cat['icon']) ?></td>
                    <td><?= htmlspecialchars($cat['name']) ?></td>
                    <td><?= htmlspecialchars($cat['description']) ?></td>
                    <td><?= count($rooms_by_cat[(int)$cat['id']] ?? []) ?></td>
                    <td class="sort-btns">
                        <?php if ($i > 0): ?>
                        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" style="display:inline;">
                            <input type="hidden" name="action"    value="move_category">
                            <input type="hidden" name="cat_id"    value="<?= (int)$cat['id'] ?>">
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="btn-move" title="Flyt op">▲</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($i < count($categories) - 1): ?>
                        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" style="display:inline;">
                            <input type="hidden" name="action"    value="move_category">
                            <input type="hidden" name="cat_id"    value="<?= (int)$cat['id'] ?>">
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="btn-move" title="Flyt ned">▼</button>
                        </form>
                        <?php endif; ?>
                    </td>
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
                <tr><th>ID</th><th>Navn</th><th>Online</th><th>Rækkefølge</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($rooms_by_cat[(int)$cat['id']])): ?>
                <tr><td colspan="5" class="td-empty">Ingen rum i denne kategori.</td></tr>
            <?php else: $cat_rooms = $rooms_by_cat[(int)$cat['id']]; foreach ($cat_rooms as $ri => $room): ?>
                <tr>
                    <td><?= (int)$room['id'] ?></td>
                    <td><?= htmlspecialchars($room['name']) ?></td>
                    <td><?= apcu_ok() ? qc_user_count((int)$room['id']) : '?' ?>/<?= MAX_USERS ?></td>
                    <td class="sort-btns">
                        <?php if ($ri > 0): ?>
                        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" style="display:inline;">
                            <input type="hidden" name="action"    value="move_room">
                            <input type="hidden" name="room_id"   value="<?= (int)$room['id'] ?>">
                            <input type="hidden" name="cat_id"    value="<?= (int)$cat['id'] ?>">
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="btn-move" title="Flyt op">▲</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($ri < count($cat_rooms) - 1): ?>
                        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" style="display:inline;">
                            <input type="hidden" name="action"    value="move_room">
                            <input type="hidden" name="room_id"   value="<?= (int)$room['id'] ?>">
                            <input type="hidden" name="cat_id"    value="<?= (int)$cat['id'] ?>">
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="btn-move" title="Flyt ned">▼</button>
                        </form>
                        <?php endif; ?>
                    </td>
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

    <!-- ── Beskeder fra brugere ─────────────────────────────────── -->
    <section class="admin-section">
        <div class="admin-section-header">
            <h2>
                ✉️ Beskeder fra brugere
                <?php if ($unread_count > 0): ?>
                    <span class="admin-notif-badge"><?= $unread_count ?> ulæst<?= $unread_count !== 1 ? 'e' : '' ?></span>
                <?php endif; ?>
            </h2>
            <?php if ($unread_count > 0): ?>
            <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" style="display:inline;">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn-secondary btn-sm">Markér alle som læst</button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (empty($contact_messages)): ?>
            <p class="td-empty contact-empty-state">Ingen beskeder endnu.</p>
        <?php else: ?>
        <table class="admin-table contact-msg-table">
            <thead>
                <tr><th>Dato</th><th>Navn</th><th>Besked</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($contact_messages as $cm): ?>
                <tr class="<?= $cm['is_read'] ? 'msg-read' : 'msg-unread' ?>">
                    <td class="msg-date"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($cm['created_at']))) ?></td>
                    <td><?php if ($cm['name'] !== ''): ?><?= htmlspecialchars($cm['name']) ?><?php else: ?><em class="text-muted">Anonym</em><?php endif; ?></td>
                    <td class="msg-body"><?= nl2br(htmlspecialchars($cm['message'])) ?></td>
                    <td>
                        <?php if ($cm['is_read']): ?>
                            <span class="badge-read">Læst</span>
                        <?php else: ?>
                            <span class="badge-unread">Ny</span>
                        <?php endif; ?>
                    </td>
                    <td class="msg-actions">
                        <?php if (!$cm['is_read']): ?>
                        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" style="display:inline;">
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="msg_id" value="<?= (int)$cm['id'] ?>">
                            <button type="submit" class="btn-secondary btn-sm">Markér læst</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" action="?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>"
                              onsubmit="return confirm('Slet denne besked?')" style="display:inline;">
                            <input type="hidden" name="action" value="delete_contact_msg">
                            <input type="hidden" name="msg_id" value="<?= (int)$cm['id'] ?>">
                            <button type="submit" class="btn-danger btn-sm">Slet</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
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
