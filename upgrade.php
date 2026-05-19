<?php
/**
 * upgrade.php — Database migrations-runner
 *
 * Anvender eventuelle afventende SQL-migreringsfiler fra database/migrations/.
 * Migreringer køres i filnavnsrækkefølge, og hver enkelt registreres i tabellen
 * `db_migrations`, så den aldrig anvendes to gange.
 *
 * Kør scriptet via CLI efter deployment (deploy.sh gør dette automatisk),
 * eller åbn det i en browser med det hemmelige admin-token som parameter:
 *   https://ditsite/upgrade.php?token=ADMIN_TOKEN
 *
 * Slet eller begræns adgangen til denne fil, når alle migreringer er anvendt.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$isCli = (php_sapi_name() === 'cli');

// Webadgang kræver det hemmelige admin-token
if (!$isCli) {
    $token = $_GET['token'] ?? '';
    if ($token !== ADMIN_TOKEN) {
        http_response_code(403);
        die('Adgang nægtet. Angiv det korrekte admin-token som ?token= parameter.');
    }
}

$results = [];

/**
 * Returnerer MySQL-fejlkoden fra en Throwable, eller 0 hvis den ikke er tilgængelig.
 */
function mysql_error_code(\Throwable $ex): int
{
    return ($ex instanceof \PDOException) ? (int)($ex->errorInfo[1] ?? 0) : 0;
}

// Sørg for at migrations-sporingstabellen eksisterer
db()->exec("
    CREATE TABLE IF NOT EXISTS `db_migrations` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `migration`  VARCHAR(255) NOT NULL UNIQUE,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$migrationsDir = __DIR__ . '/database/migrations';
$files = is_dir($migrationsDir) ? (glob($migrationsDir . '/*.sql') ?: []) : [];
sort($files);

// CLI: kør alle afventende migreringer automatisk og afslut.
if ($isCli) {
    $anyPending = false;
    foreach ($files as $file) {
        $name    = basename($file);
        $already = db()->prepare('SELECT COUNT(*) FROM db_migrations WHERE migration = ?');
        $already->execute([$name]);
        if ((int)$already->fetchColumn() > 0) {
            $deleted = @unlink($file);
            echo "  [spring over]  {$name}" . ($deleted ? ' (fil slettet)' : '') . PHP_EOL;
            continue;
        }

        $anyPending = true;
        echo "  [anvend] {$name} ... ";
        try {
            $sql = file_get_contents($file);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                try {
                    db()->exec($stmt);
                } catch (\Throwable $stmtEx) {
                    // 1050: tabel eksisterer allerede (CREATE TABLE)
                    // 1060: duplikeret kolonnenavn (ALTER TABLE ADD COLUMN)
                    // 1091: kolonne/index kan ikke droppes — findes ikke (ALTER TABLE DROP)
                    if (!in_array(mysql_error_code($stmtEx), [1050, 1060, 1091], true)) {
                        throw $stmtEx;
                    }
                }
            }
            $ins = db()->prepare('INSERT INTO db_migrations (migration) VALUES (?)');
            $ins->execute([$name]);
        } catch (\Throwable $ex) {
            echo "FEJL: " . $ex->getMessage() . PHP_EOL;
            exit(1);
        }

        $deleted = @unlink($file);
        echo "OK" . ($deleted ? ' (fil slettet)' : '') . PHP_EOL;
    }

    if (!$anyPending) {
        echo "  Alle migreringer er allerede anvendt." . PHP_EOL;
    }
    exit(0);
}

// Web: POST anvender migreringer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($files as $file) {
        $name = basename($file);

        $already = db()->prepare('SELECT COUNT(*) FROM db_migrations WHERE migration = ?');
        $already->execute([$name]);
        if ((int)$already->fetchColumn() > 0) {
            $deleted = @unlink($file);
            $results[] = [
                'name'   => $name,
                'status' => 'sprunget over',
                'msg'    => $deleted ? 'Allerede anvendt — fil slettet' : 'Allerede anvendt (fil kunne ikke slettes)',
            ];
            continue;
        }

        try {
            $sql = file_get_contents($file);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                try {
                    db()->exec($stmt);
                } catch (\Throwable $stmtEx) {
                    // 1050: tabel eksisterer allerede (CREATE TABLE)
                    // 1060: duplikeret kolonnenavn (ALTER TABLE ADD COLUMN)
                    // 1091: kolonne/index kan ikke droppes — findes ikke (ALTER TABLE DROP)
                    if (!in_array(mysql_error_code($stmtEx), [1050, 1060, 1091], true)) {
                        throw $stmtEx;
                    }
                }
            }
            $ins = db()->prepare('INSERT INTO db_migrations (migration) VALUES (?)');
            $ins->execute([$name]);
        } catch (\Throwable $ex) {
            $results[] = ['name' => $name, 'status' => 'fejl', 'msg' => $ex->getMessage()];
            break;
        }

        $deleted = @unlink($file);
        $results[] = [
            'name'   => $name,
            'status' => 'ok',
            'msg'    => $deleted ? 'Anvendt og fil slettet' : 'Anvendt (fil kunne ikke slettes)',
        ];
    }
}

// Tæl afventende migreringer til visning
$pendingCount = 0;
foreach ($files as $file) {
    $chk = db()->prepare('SELECT COUNT(*) FROM db_migrations WHERE migration = ?');
    $chk->execute([basename($file)]);
    if ((int)$chk->fetchColumn() === 0) {
        $pendingCount++;
    }
}

$tokenParam = '?token=' . urlencode($_GET['token'] ?? '');
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Opgradering — <?= htmlspecialchars(SITE_NAME, ENT_QUOTES) ?></title>
    <style>
        body { font-family: sans-serif; max-width: 640px; margin: 3rem auto; padding: 0 1rem; }
        h1 { font-size: 1.4rem; }
        .ok  { color: green; }
        .err { color: red; }
        .btn { padding: .5rem 1.2rem; font-size: 1rem; cursor: pointer; }
    </style>
</head>
<body>
<h1><?= htmlspecialchars(SITE_NAME, ENT_QUOTES) ?> — Database Opgradering</h1>

<?php if ($results): ?>
<h2>Resultat</h2>
<ul>
<?php foreach ($results as $r): ?>
    <li class="<?= $r['status'] === 'fejl' ? 'err' : 'ok' ?>">
        <strong><?= htmlspecialchars($r['name'], ENT_QUOTES) ?></strong>:
        <?= htmlspecialchars($r['msg'], ENT_QUOTES) ?>
    </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($pendingCount > 0 && !$results): ?>
<p><?= (int)$pendingCount ?> afventende migrering(er) fundet.</p>
<?php elseif ($pendingCount === 0 && !$results): ?>
<p>Alle migreringer er opdaterede. Ingen handling nødvendig.</p>
<?php endif; ?>

<?php if ($pendingCount > 0): ?>
<form method="POST" action="upgrade.php<?= htmlspecialchars($tokenParam, ENT_QUOTES) ?>">
    <button type="submit" class="btn">Anvend migreringer</button>
</form>
<?php endif; ?>

<p><a href="index.php">← Tilbage til forsiden</a></p>
</body>
</html>
