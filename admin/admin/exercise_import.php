<?php
// admin/exercise_import.php – import globálních cviků z CSV
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();

$preview = [];
$errors  = [];
$imported = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/exercise_import.php');
    }

    $action = $_POST['action'] ?? '';

    // Krok 2: skutečný import
    if ($action === 'import' && !empty($_POST['names'])) {
        $stmtCheck  = $pdo->prepare('SELECT id FROM exercises WHERE name = ? AND is_global = 1');
        $stmtInsert = $pdo->prepare('INSERT INTO exercises (coach_id, name, is_global) VALUES (NULL, ?, 1)');
        foreach ($_POST['names'] as $rawName) {
            $name = trim($rawName);
            if ($name === '') continue;
            $stmtCheck->execute([$name]);
            if ($stmtCheck->fetch()) continue; // přeskočit duplicity
            $stmtInsert->execute([$name]);
            $imported++;
        }
        flash('success', "Importováno $imported nových cviků (duplicity přeskočeny).");
        redirect(BASE_URL . '/admin/exercises.php');
    }

    // Krok 1: náhled CSV
    if ($action === 'preview') {
        if (empty($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Vyberte platný CSV soubor.';
        } else {
            $handle = fopen($_FILES['csv']['tmp_name'], 'r');
            if (!$handle) {
                $errors[] = 'Soubor nelze otevřít.';
            } else {
                // Přeskočit BOM
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }
                $headerRow = fgetcsv($handle, 0, ';');
                // Zjisti index sloupce "name"
                $nameIdx = false;
                if ($headerRow) {
                    foreach ($headerRow as $k => $v) {
                        if (strtolower(trim($v)) === 'name') {
                            $nameIdx = $k;
                            break;
                        }
                    }
                }
                if ($nameIdx === false) {
                    $errors[] = 'CSV neobsahuje sloupec "name". Ujistěte se, že první řádek je hlavička s id;name;photo.';
                } else {
                    $stmtCheck = $pdo->prepare('SELECT id FROM exercises WHERE name = ? AND is_global = 1');
                    while (($row = fgetcsv($handle, 0, ';')) !== false) {
                        $name = trim($row[$nameIdx] ?? '');
                        if ($name === '') continue;
                        $stmtCheck->execute([$name]);
                        $exists = (bool)$stmtCheck->fetch();
                        $preview[] = ['name' => $name, 'exists' => $exists];
                    }
                    if (empty($preview)) {
                        $errors[] = 'CSV soubor neobsahuje žádné záznamy.';
                    }
                }
                fclose($handle);
            }
        }
    }
}

renderAdminHeader('Import globálních cviků');
?>

<div class="mb-4">
    <h4 class="fw-bold"><i class="fas fa-file-import me-2" style="color:#7c3aed"></i>Import globálních cviků</h4>
    <p class="text-muted small mb-0">
        Nahraje CSV soubor (oddělený středníkem) s globálními cviky.
        Hlavička musí obsahovat sloupec <code>name</code>.
        Duplicity (stejný název) jsou automaticky přeskočeny.
    </p>
</div>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>

<?php if (empty($preview)): ?>
<!-- Formulář pro nahrání CSV -->
<div class="card border-0 shadow-sm" style="max-width:600px">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="preview">
            <div class="mb-3">
                <label class="form-label fw-semibold">CSV soubor</label>
                <input type="file" name="csv" class="form-control" accept=".csv,text/csv" required>
                <div class="form-text">Formát: <code>id;name;photo</code> (hlavička povinná, id a photo se ignorují)</div>
            </div>
            <button type="submit" class="btn" style="background:#7c3aed;color:#fff">
                <i class="fas fa-search me-1"></i>Zobrazit náhled
            </button>
            <a href="<?= BASE_URL ?>/admin/exercises.php" class="btn btn-outline-secondary ms-2">Zpět</a>
        </form>
    </div>
</div>

<?php else: ?>
<!-- Náhled importu -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header fw-semibold" style="background:#312e81;color:#fff">
        Náhled – <?= count($preview) ?> záznamů
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Název cviku</th>
                    <th>Stav</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($preview as $p): ?>
                <tr class="<?= $p['exists'] ? 'table-warning' : '' ?>">
                    <td><?= h($p['name']) ?></td>
                    <td>
                        <?php if ($p['exists']): ?>
                        <span class="badge bg-warning text-dark"><i class="fas fa-skip-forward me-1"></i>přeskočit (duplicita)</span>
                        <?php else: ?>
                        <span class="badge bg-success"><i class="fas fa-plus me-1"></i>importovat</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$newCount = count(array_filter($preview, fn($p) => !$p['exists']));
?>
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="import">
    <?php foreach ($preview as $p): ?>
    <?php if (!$p['exists']): ?>
    <input type="hidden" name="names[]" value="<?= h($p['name']) ?>">
    <?php endif; ?>
    <?php endforeach; ?>
    <div class="d-flex gap-2 align-items-center">
        <?php if ($newCount > 0): ?>
        <button type="submit" class="btn" style="background:#7c3aed;color:#fff">
            <i class="fas fa-file-import me-1"></i>Importovat <?= $newCount ?> cviků
        </button>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/admin/exercise_import.php" class="btn btn-outline-secondary">Nahrát jiný soubor</a>
        <a href="<?= BASE_URL ?>/admin/exercises.php" class="btn btn-link text-muted">Zrušit</a>
    </div>
</form>
<?php endif; ?>

<?php renderAdminFooter(); ?>
