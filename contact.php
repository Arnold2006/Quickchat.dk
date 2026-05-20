<?php
require_once __DIR__ . '/config.php';

$sent  = false;
$error = '';
$nav_items = qc_nav_items();
$page_title = 'Skriv til Admin';

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
require __DIR__ . '/includes/header.php';
?>
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
<?php require __DIR__ . '/includes/footer.php'; ?>
