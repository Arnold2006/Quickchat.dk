<?php
require_once __DIR__ . '/config.php';

// Hent alle kategorier med antal rum
$stmt = db()->query("
    SELECT c.id, c.name, c.description, c.icon,
           COUNT(r.id) AS room_count
    FROM categories c
    LEFT JOIN rooms r ON c.id = r.category_id
    GROUP BY c.id, c.name, c.description, c.icon, c.sort_order
    ORDER BY c.sort_order, c.name
");
$categories = $stmt->fetchAll();

$nav_items = qc_nav_items();

// Tæl online-brugere pr. kategori via APCu
if (apcu_ok()) {
    $all_rooms = db()->query("SELECT id, category_id FROM rooms")->fetchAll();
    $cat_online = [];
    foreach ($all_rooms as $r) {
        $cat_online[$r['category_id']] = ($cat_online[$r['category_id']] ?? 0)
                                         + qc_user_count((int)$r['id']);
    }
} else {
    $cat_online = [];
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(SITE_NAME) ?> – Vælg kategori</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="page">
    <header class="site-header">
        <div class="header-inner">
            <h1 class="site-logo">💬 <?= htmlspecialchars(SITE_NAME) ?></h1>
            <p class="site-tagline">Anonym chat – ingen registrering nødvendig</p>
        </div>
    </header>

    <main class="lobby">
        <?php if (!empty($nav_items)): ?>
        <nav class="site-nav">
            <div class="site-nav-inner">
                <?php foreach ($nav_items as $item): ?>
                <a class="site-nav-link" href="<?= htmlspecialchars($item['url'], ENT_QUOTES) ?>"
                   <?= (int)$item['open_new_tab'] ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                    <?= htmlspecialchars($item['label']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </nav>
        <?php endif; ?>
        <?php if (FRONT_PAGE_TEXT !== ''): ?>
        <div class="front-page-text"><?= nl2br(htmlspecialchars(FRONT_PAGE_TEXT)) ?></div>
        <?php endif; ?>
        <p class="section-label">Vælg en kategori</p>
        <div class="category-grid">
            <?php foreach ($categories as $cat): ?>
            <a class="category-card" href="category.php?id=<?= (int)$cat['id'] ?>">
                <div class="cat-icon"><?= htmlspecialchars($cat['icon']) ?></div>
                <div class="cat-body">
                    <h2><?= htmlspecialchars($cat['name']) ?></h2>
                    <p><?= htmlspecialchars($cat['description']) ?></p>
                </div>
                <div class="cat-meta">
                    <span><?= (int)$cat['room_count'] ?> rum</span>
                    <span class="dot-green"><?= (int)($cat_online[$cat['id']] ?? 0) ?> online</span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </main>

    <footer class="site-footer">
        <p>100 % anonymt &nbsp;·&nbsp; ingen registrering &nbsp;·&nbsp; ingen logning</p>
    </footer>
</div>

<!-- Del-modal -->
<div class="modal-overlay" id="shareModal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="shareModalTitle">
    <div class="modal share-modal">
        <div class="share-modal-icon">🚀</div>
        <h2 id="shareModalTitle">Hjælp os med at vokse!</h2>
        <p><?= htmlspecialchars(SITE_NAME) ?> er 100 % anonymt og gratis – men vi har brug for <strong>din</strong> hjælp til at sprede ordet, så der kommer flere at chatte med.</p>
        <p class="share-modal-sub">Del siden med dine venner og giv dem en god chatoplevelse 💬</p>
        <div class="share-buttons">
            <a class="share-btn share-btn--whatsapp" id="shareWhatsApp" href="#" target="_blank" rel="noopener noreferrer">
                <span class="share-btn-icon">📱</span> WhatsApp
            </a>
            <a class="share-btn share-btn--facebook" id="shareFacebook" href="#" target="_blank" rel="noopener noreferrer">
                <span class="share-btn-icon">📘</span> Facebook
            </a>
            <button class="share-btn share-btn--copy" id="shareCopy" type="button">
                <span class="share-btn-icon">🔗</span> <span id="shareCopyLabel">Kopiér link</span>
            </button>
        </div>
        <button class="btn-secondary share-modal-close" id="shareModalClose" type="button">Luk</button>
    </div>
</div>

<script>
(function () {
    var STORAGE_KEY = 'qc_share_modal_seen';
    var siteUrl = <?= json_encode((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'quickchat.dk') . '/') ?>;
    var siteText = <?= json_encode('Prøv ' . SITE_NAME . ' – anonym chat uden registrering!') ?>;

    var seen = false;
    try { seen = !!localStorage.getItem(STORAGE_KEY); } catch (e) {}

    if (!seen) {
        var modal = document.getElementById('shareModal');
        modal.style.display = 'flex';
        try { localStorage.setItem(STORAGE_KEY, '1'); } catch (e) {}

        document.getElementById('shareWhatsApp').href =
            'https://wa.me/?text=' + encodeURIComponent(siteText + ' ' + siteUrl);
        document.getElementById('shareFacebook').href =
            'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(siteUrl);

        document.getElementById('shareCopy').addEventListener('click', function () {
            navigator.clipboard.writeText(siteUrl).then(function () {
                document.getElementById('shareCopyLabel').textContent = '✓ Kopieret!';
                setTimeout(function () {
                    document.getElementById('shareCopyLabel').textContent = 'Kopiér link';
                }, 2000);
            }).catch(function () {
                document.getElementById('shareCopyLabel').textContent = '⚠ Kopiering fejlede';
                setTimeout(function () {
                    document.getElementById('shareCopyLabel').textContent = 'Kopiér link';
                }, 2500);
            });
        });

        document.getElementById('shareModalClose').addEventListener('click', function () {
            modal.style.display = 'none';
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) modal.style.display = 'none';
        });
    }
}());
</script>
</body>
</html>
