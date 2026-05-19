<?php
require_once __DIR__ . '/config.php';

$sent  = false;
$error = '';
$nav_items = qc_nav_items();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name']    ?? '');
    $message = trim($_POST['message'] ?? '');

    if (mb_strlen($name) > 100) {
        $error = 'Navn må højst være 100 tegn.';
    } elseif ($message === '' || mb_strlen($message) > 2000) {
        $error = 'Beskeden skal udfyldes og må højst være 2000 tegn.';
    } else {
        db()->prepare(
            "INSERT INTO contact_messages (name, message) VALUES (:n, :m)"
        )->execute([':n' => $name, ':m' => $message]);
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(SITE_NAME) ?> – Skriv til Admin</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="page">
    <header class="site-header">
        <div class="header-inner">
            <a class="back-link" href="index.php">← Tilbage til forsiden</a>
            <h1 class="site-logo">💬 <?= htmlspecialchars(SITE_NAME) ?></h1>
        </div>
    </header>

    <?php if (!empty($nav_items)): ?>
    <nav class="site-nav">
        <div class="site-nav-inner">
            <?php foreach ($nav_items as $item): ?>
            <a class="site-nav-link" href="<?= htmlspecialchars($item['url'], ENT_QUOTES) ?>">
                <?= htmlspecialchars($item['label']) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </nav>
    <?php endif; ?>

    <main class="lobby">
        <p class="section-label">Skriv til Admin</p>

        <?php if ($sent): ?>
            <div class="contact-success">
                ✅ Din besked er afsendt. Vi vender tilbage hurtigst muligt.
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="error-msg contact-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="contact-card">
                <p class="contact-intro">
                    Har du spørgsmål, feedback eller en henvendelse til administratoren?
                    Udfyld formularen herunder – navn er valgfrit.
                </p>
                <form method="POST" class="contact-form">
                    <label for="contact-name">Navn <span class="field-optional">(valgfrit)</span></label>
                    <input
                        type="text"
                        id="contact-name"
                        name="name"
                        class="text-input"
                        placeholder="Dit navn eller kaldenavn…"
                        maxlength="100"
                        value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES) ?>">

                    <label for="contact-message">Besked <span class="field-required">*</span></label>
                    <textarea
                        id="contact-message"
                        name="message"
                        class="text-input contact-textarea"
                        rows="6"
                        placeholder="Skriv din besked her…"
                        maxlength="2000"
                        required><?= htmlspecialchars($_POST['message'] ?? '', ENT_QUOTES) ?></textarea>

                    <button type="submit" class="btn-primary">Send besked</button>
                </form>
            </div>
        <?php endif; ?>
    </main>

    <footer class="site-footer">
        <p>100 % anonymt &nbsp;·&nbsp; ingen registrering &nbsp;·&nbsp; ingen logning</p>
    </footer>
</div>
</body>
</html>
