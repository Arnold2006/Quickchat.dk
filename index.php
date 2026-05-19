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
        <p><a class="footer-link" href="contact.php">✉️ Skriv til Admin</a></p>
    </footer>
</div>
</body>
</html>
