<?php
// gallery_file_detail.php – detail a nastavení souboru trenéra
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$coachId = getCurrentCoachId();
$pdo     = getDB();

$fileId = intParam($_GET, 'id');
$stmt   = $pdo->prepare("
    SELECT gf.*, fld.name AS folder_name, fld.folder_type, fld.id AS folder_real_id,
           a.first_name AS athlete_first, a.last_name AS athlete_last
    FROM gallery_files gf
    LEFT JOIN gallery_folders fld ON fld.id = gf.folder_id
    LEFT JOIN athletes a ON a.id = fld.athlete_id
    WHERE gf.id = ? AND gf.coach_id = ?
");
$stmt->execute([$fileId, $coachId]);
$file = $stmt->fetch();

if (!$file) {
    flash('danger', 'Soubor nebyl nalezen.');
    redirect(BASE_URL . '/gallery.php');
}

// Sportovci trenéra
$athletes = $pdo->prepare("SELECT id, first_name, last_name FROM athletes WHERE coach_id = ? ORDER BY first_name, last_name");
$athletes->execute([$coachId]);
$athletes = $athletes->fetchAll();

// Aktuálně přiřazení sportovci ke konkrétní viditelnosti
$visAthletes = $pdo->prepare("SELECT athlete_id FROM gallery_file_athletes WHERE file_id = ?");
$visAthletes->execute([$fileId]);
$visAthleteIds = array_column($visAthletes->fetchAll(), 'athlete_id');

// Složky pro přesun
$allFolders = $pdo->prepare("SELECT f.*, a.first_name, a.last_name FROM gallery_folders f LEFT JOIN athletes a ON a.id = f.athlete_id WHERE f.coach_id = ? ORDER BY f.folder_type, f.name");
$allFolders->execute([$coachId]);
$allFolders = $allFolders->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect($_SERVER['REQUEST_URI']);
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $description = trim($_POST['description'] ?? '');
        $visibility  = in_array($_POST['visibility'] ?? '', ['private','all_athletes','specific_athletes'])
                       ? $_POST['visibility'] : 'private';
        $specificIds = array_map('intval', array_filter($_POST['specific_athletes'] ?? []));
        $newFolderId = $_POST['folder_id'] !== '' ? (int)$_POST['folder_id'] : null;

        // Ověř, že složka patří trenérovi
        if ($newFolderId !== null) {
            $chk = $pdo->prepare("SELECT id FROM gallery_folders WHERE id = ? AND coach_id = ?");
            $chk->execute([$newFolderId, $coachId]);
            if (!$chk->fetch()) $newFolderId = null;
        }

        $pdo->prepare("UPDATE gallery_files SET description = ?, visibility = ?, folder_id = ? WHERE id = ? AND coach_id = ?")
            ->execute([$description ?: null, $visibility, $newFolderId, $fileId, $coachId]);

        // Aktualizace viditelnosti pro konkrétní sportovce
        $pdo->prepare("DELETE FROM gallery_file_athletes WHERE file_id = ?")->execute([$fileId]);
        if ($visibility === 'specific_athletes' && !empty($specificIds)) {
            $insVis = $pdo->prepare("INSERT IGNORE INTO gallery_file_athletes (file_id, athlete_id) VALUES (?, ?)");
            foreach ($specificIds as $aid) {
                $insVis->execute([$fileId, $aid]);
            }
        }

        flash('success', 'Nastavení souboru bylo uloženo.');
        redirect($_SERVER['REQUEST_URI']);
    }
}

// Název složky pro breadcrumb
$breadcrumbFolder = null;
if ($file['folder_real_id']) {
    $breadcrumbFolder = $file['folder_type'] === 'athlete'
        ? ($file['athlete_first'] . ' ' . $file['athlete_last'])
        : $file['folder_name'];
}

// Cesta k souboru pro zobrazení
$fileSrc = BASE_URL . '/uploads/gallery/coach_' . $coachId . '/' . rawurlencode($file['file_path']);

renderHeader(h($file['original_name']));
?>

<div class="d-flex align-items-center mb-4 gap-3 flex-wrap">
    <a href="<?= $file['folder_real_id']
        ? BASE_URL . '/gallery_folder.php?id=' . $file['folder_real_id']
        : BASE_URL . '/gallery_folder.php?unfiled=1' ?>"
       class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <nav aria-label="breadcrumb" class="flex-grow-1">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/gallery.php">Galerie</a></li>
            <?php if ($breadcrumbFolder): ?>
            <li class="breadcrumb-item">
                <a href="<?= BASE_URL ?>/gallery_folder.php?id=<?= $file['folder_real_id'] ?>">
                    <?= h($breadcrumbFolder) ?>
                </a>
            </li>
            <?php endif; ?>
            <li class="breadcrumb-item active"><?= h(mb_strimwidth($file['original_name'], 0, 40, '…')) ?></li>
        </ol>
    </nav>
</div>

<div class="row g-4">
    <!-- Náhled souboru -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center p-4">
                <?php if ($file['file_type'] === 'image'): ?>
                <img src="<?= $fileSrc ?>" alt="<?= h($file['original_name']) ?>"
                     class="img-fluid rounded" style="max-height:500px;cursor:pointer"
                     onclick="window.open('<?= $fileSrc ?>', '_blank')">
                <?php elseif ($file['file_type'] === 'video'): ?>
                <video controls class="w-100 rounded" style="max-height:500px">
                    <source src="<?= $fileSrc ?>" type="<?= h($file['mime_type'] ?: 'video/mp4') ?>">
                    Váš prohlížeč nepodporuje přehrávání videa.
                </video>
                <?php else: ?>
                <div class="py-5">
                    <i class="fas fa-file-alt text-info fa-5x mb-3 d-block"></i>
                    <a href="<?= $fileSrc ?>" target="_blank" class="btn btn-outline-info btn-lg">
                        <i class="fas fa-download me-2"></i>Otevřít / stáhnout
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-footer text-muted small d-flex justify-content-between flex-wrap gap-1">
                <span><i class="fas fa-file me-1"></i><?= h($file['original_name']) ?></span>
                <span><?= round($file['file_size'] / 1024, 1) ?> KB</span>
                <span><?= date('d.m.Y H:i', strtotime($file['created_at'])) ?></span>
            </div>
        </div>
    </div>

    <!-- Nastavení souboru -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold">
                <i class="fas fa-cog me-2"></i>Nastavení souboru
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="update">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Popis</label>
                        <textarea name="description" class="form-control" rows="3" maxlength="1000"><?= h($file['description'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Složka</label>
                        <select name="folder_id" class="form-select">
                            <option value="">— Bez složky —</option>
                            <?php foreach ($allFolders as $fld): ?>
                            <?php $fldName = $fld['folder_type'] === 'athlete'
                                ? ($fld['first_name'] . ' ' . $fld['last_name'])
                                : $fld['name']; ?>
                            <option value="<?= $fld['id'] ?>" <?= $file['folder_real_id'] == $fld['id'] ? 'selected' : '' ?>>
                                <?= $fld['folder_type'] === 'athlete' ? '👤 ' : '📁 ' ?><?= h($fldName) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Viditelnost</label>
                        <select name="visibility" class="form-select" id="visSelect">
                            <option value="private" <?= $file['visibility'] === 'private' ? 'selected' : '' ?>>
                                🔒 Soukromý – pouze já
                            </option>
                            <?php if (!empty($athletes)): ?>
                            <option value="all_athletes" <?= $file['visibility'] === 'all_athletes' ? 'selected' : '' ?>>
                                👥 Sdílet se všemi mými sportovci
                            </option>
                            <option value="specific_athletes" <?= $file['visibility'] === 'specific_athletes' ? 'selected' : '' ?>>
                                👤 Sdílet s vybranými sportovci
                            </option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <?php if (!empty($athletes)): ?>
                    <div id="specificAthletes" class="mb-3 <?= $file['visibility'] === 'specific_athletes' ? '' : 'd-none' ?>">
                        <label class="form-label fw-semibold">Vybraní sportovci</label>
                        <div class="row g-2">
                            <?php foreach ($athletes as $a): ?>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           name="specific_athletes[]" value="<?= $a['id'] ?>"
                                           id="ath<?= $a['id'] ?>"
                                           <?= in_array($a['id'], $visAthleteIds) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="ath<?= $a['id'] ?>">
                                        <?= h($a['first_name'] . ' ' . $a['last_name']) ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save me-1"></i>Uložit nastavení
                    </button>
                </form>
            </div>
        </div>

        <!-- Přímý odkaz -->
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body d-flex gap-2">
                <a href="<?= $fileSrc ?>" target="_blank" class="btn btn-outline-secondary flex-grow-1">
                    <i class="fas fa-external-link-alt me-1"></i>Otevřít v novém okně
                </a>
                <a href="<?= $fileSrc ?>" download="<?= h($file['original_name']) ?>" class="btn btn-outline-success flex-grow-1">
                    <i class="fas fa-download me-1"></i>Stáhnout
                </a>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('visSelect')?.addEventListener('change', function () {
    document.getElementById('specificAthletes')?.classList.toggle('d-none', this.value !== 'specific_athletes');
});
</script>

<?php renderFooter(); ?>
