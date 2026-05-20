<?php
$nav_items = $nav_items ?? qc_nav_items();
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(SITE_NAME) ?> – <?= $page_title ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="page">
    <header class="site-header">
        <div class="header-inner">
            <h1 class="site-logo">💬 <?= htmlspecialchars(SITE_NAME) ?></h1>
            <?php if (isset($page_subtitle) && $page_subtitle !== ''): ?>
            <p class="site-tagline"><?= $page_subtitle ?></p>
            <?php endif; ?>
        </div>
    </header>

    <main class="lobby">
        <?php if (!empty($nav_items)): ?>
        <nav class="site-nav">
            <div class="site-nav-inner">
                <?php foreach ($nav_items as $item):
                    $u = trim($item['url']);
                    $is_home = ($u === '' || $u === '/' || preg_match('/^\.?\/?index\.php(\?.*)?$/i', $u));
                ?>
                <a class="site-nav-link" href="<?= htmlspecialchars($item['url'], ENT_QUOTES) ?>"
                   <?= (int)$item['open_new_tab'] ? 'target="_blank" rel="noopener noreferrer"' : '' ?>
                   <?= $is_home ? 'data-home-link="1"' : '' ?>
                >
                    <?= htmlspecialchars($item['label']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </nav>
        <?php endif; ?>
