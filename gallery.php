<?php
// gallery.php – hlavní galerie trenéra: přehled složek
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$coachId = getCurrentCoachId();
$pdo     = getDB();

// Zpracování POST akcí
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/gallery.php');
    }

    $action = $_POST['action'] ?? '';

    // Vytvoření nové složky
    if ($action === 'create_folder') {
        $name = trim($_POST['folder_name'] ?? '');
        if ($name === '') {
            flash('danger', 'Název složky nesmí být prázdný.');
        } else {
            $name = mb_substr($name, 0, 200, 'UTF-8');
            $pdo->prepare("INSERT INTO gallery_folders (coach_id, name, folder_type, sort_order) VALUES (?, ?, 'custom', 0)")
                ->execute([$coachId, $name]);
            flash('success', 'Složka „' . $name . '" byla vytvořena.');
        }
        redirect(BASE_URL . '/gallery.php');
    }

    // Přejmenování složky
    if ($action === 'rename_folder') {
        $folderId = intParam($_POST, 'folder_id');
        $name     = trim($_POST['folder_name'] ?? '');
        if ($name === '') {
            flash('danger', 'Název nesmí být prázdný.');
        } else {
            $name = mb_substr($name, 0, 200, 'UTF-8');
            $pdo->prepare("UPDATE gallery_folders SET name = ? WHERE id = ? AND coach_id = ? AND folder_type = 'custom'")
                ->execute([$name, $folderId, $coachId]);
            flash('success', 'Složka přejmenována.');
        }
        redirect(BASE_URL . '/gallery.php');
    }

    // Smazání složky (včetně souborů)
    if ($action === 'delete_folder') {
        $folderId = intParam($_POST, 'folder_id');
        $folder   = $pdo->prepare("SELECT * FROM gallery_folders WHERE id = ? AND coach_id = ? AND folder_type = 'custom'");
        $folder->execute([$folderId, $coachId]);
        $folder = $folder->fetch();
        if ($folder) {
            // Smazat fyzické soubory
            $files = $pdo->prepare("SELECT file_path FROM gallery_files WHERE folder_id = ? AND coach_id = ?");
            $files->execute([$folderId, $coachId]);
            foreach ($files->fetchAll() as $f) {
                $full = __DIR__ . '/uploads/gallery/' . $f['file_path'];
                if (file_exists($full)) @unlink($full);
            }
            $pdo->prepare("DELETE FROM gallery_folders WHERE id = ? AND coach_id = ?")->execute([$folderId, $coachId]);
            flash('success', 'Složka „' . $folder['name'] . '" a všechny soubory byly smazány.');
        }
        redirect(BASE_URL . '/gallery.php');
    }
}

// Načíst složky trenéra (vlastní + pro sportovce)
$folders = $pdo->prepare("
    SELECT f.*,
           a.first_name, a.last_name, a.photo AS athlete_photo,
           COUNT(gf.id) AS file_count
    FROM gallery_folders f
    LEFT JOIN athletes a ON a.id = f.athlete_id
    LEFT JOIN gallery_files gf ON gf.folder_id = f.id
    WHERE f.coach_id = ?
    GROUP BY f.id
    ORDER BY f.folder_type ASC, a.first_name ASC, a.last_name ASC, f.sort_order ASC, f.name ASC
");
$folders->execute([$coachId]);
$folders = $folders->fetchAll();

// Soubory bez složky
$unfiled = $pdo->prepare("SELECT COUNT(*) FROM gallery_files WHERE coach_id = ? AND folder_id IS NULL");
$unfiled->execute([$coachId]);
$unfiledCount = (int)$unfiled->fetchColumn();

// Soubory od admina pro tohoto trenéra
$adminFiles = $pdo->prepare("
    SELECT agf.*
    FROM admin_gallery_files agf
    WHERE agf.visibility = 'all_coaches'
       OR (agf.visibility = 'specific_coaches'
           AND EXISTS (SELECT 1 FROM admin_gallery_file_coaches agfc WHERE agfc.file_id = agf.id AND agfc.coach_id = ?))
    ORDER BY agf.created_at DESC
");
$adminFiles->execute([$coachId]);
$adminFiles = $adminFiles->fetchAll();

// Rozdělit složky
$athleteFolders = array_filter($folders, fn($f) => $f['folder_type'] === 'athlete');
$customFolders  = array_filter($folders, fn($f) => $f['folder_type'] === 'custom');

renderHeader('Galerie');
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-images me-2 text-warning"></i>Galerie</h2>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNewFolder">
            <i class="fas fa-folder-plus me-1"></i>Nová složka
        </button>
        <a href="<?= BASE_URL ?>/gallery_upload.php" class="btn btn-warning btn-sm fw-bold">
            <i class="fas fa-cloud-upload-alt me-1"></i>Nahrát soubory
        </a>
    </div>
</div>

<!-- Složky sportovců -->
<?php if (!empty($athleteFolders)): ?>
<h5 class="fw-bold text-muted mb-3"><i class="fas fa-users me-2"></i>Složky sportovců</h5>
<div class="row g-3 mb-4">
    <?php foreach ($athleteFolders as $f): ?>
    <div class="col-6 col-md-4 col-lg-3 col-xl-2">
        <a href="<?= BASE_URL ?>/gallery_folder.php?id=<?= $f['id'] ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 text-center p-3 gallery-folder-card athlete-folder">
                <?php if ($f['athlete_photo']): ?>
                <img src="<?= h(photoUrl($f['athlete_photo'], 'athletes')) ?>" alt=""
                     class="rounded-circle mb-2 mx-auto d-block"
                     style="width:52px;height:52px;object-fit:cover;border:2px solid #ffc107">
                <?php else: ?>
                <div class="folder-icon mb-2"><i class="fas fa-folder text-warning" style="font-size:2.5rem"></i></div>
                <?php endif; ?>
                <div class="fw-semibold small text-dark"><?= h($f['first_name'] . ' ' . $f['last_name']) ?></div>
                <div class="text-muted" style="font-size:.75rem"><?= $f['file_count'] ?> souborů</div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Vlastní složky -->
<?php if (!empty($customFolders) || $unfiledCount > 0): ?>
<h5 class="fw-bold text-muted mb-3"><i class="fas fa-folder-open me-2"></i>Moje složky</h5>
<div class="row g-3 mb-4">
    <?php if ($unfiledCount > 0): ?>
    <div class="col-6 col-md-4 col-lg-3 col-xl-2">
        <a href="<?= BASE_URL ?>/gallery_folder.php?unfiled=1" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 text-center p-3 gallery-folder-card">
                <div class="folder-icon mb-2"><i class="fas fa-folder-open text-secondary" style="font-size:2.5rem"></i></div>
                <div class="fw-semibold small text-dark">Bez složky</div>
                <div class="text-muted" style="font-size:.75rem"><?= $unfiledCount ?> souborů</div>
            </div>
        </a>
    </div>
    <?php endif; ?>

    <?php foreach ($customFolders as $f): ?>
    <div class="col-6 col-md-4 col-lg-3 col-xl-2">
        <div class="card border-0 shadow-sm h-100 text-center p-3 gallery-folder-card position-relative">
            <a href="<?= BASE_URL ?>/gallery_folder.php?id=<?= $f['id'] ?>" class="text-decoration-none stretched-link">
                <div class="folder-icon mb-2"><i class="fas fa-folder text-primary" style="font-size:2.5rem"></i></div>
                <div class="fw-semibold small text-dark"><?= h($f['name']) ?></div>
                <div class="text-muted" style="font-size:.75rem"><?= $f['file_count'] ?> souborů</div>
            </a>
            <div class="dropdown position-absolute top-0 end-0 mt-1 me-1" style="z-index:2">
                <button class="btn btn-sm btn-link text-muted p-0 px-1" data-bs-toggle="dropdown" onclick="event.preventDefault();event.stopPropagation()">
                    <i class="fas fa-ellipsis-v"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li>
                        <button class="dropdown-item" onclick="event.preventDefault();event.stopPropagation();openRenameModal(<?= $f['id'] ?>, <?= json_encode($f['name']) ?>)">
                            <i class="fas fa-pencil me-2 text-primary"></i>Přejmenovat
                        </button>
                    </li>
                    <li>
                        <button class="dropdown-item text-danger" onclick="event.preventDefault();event.stopPropagation();confirmDeleteFolder(<?= $f['id'] ?>, <?= json_encode($f['name']) ?>, <?= $f['file_count'] ?>)">
                            <i class="fas fa-trash me-2"></i>Smazat
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="alert alert-light border mb-4">
    <i class="fas fa-info-circle me-2 text-muted"></i>
    Zatím nemáte žádné vlastní složky. Vytvořte si je tlačítkem <strong>Nová složka</strong>.
</div>
<?php endif; ?>

<!-- Soubory od administrátora -->
<?php if (!empty($adminFiles)): ?>
<h5 class="fw-bold text-muted mb-3"><i class="fas fa-shield-alt me-2 text-info"></i>Od administrátora</h5>
<div class="row g-3">
    <?php foreach ($adminFiles as $f): ?>
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="flex-shrink-0 text-center" style="width:48px">
                    <?php
                    $ico = match($f['file_type']) {
                        'image'    => 'fa-image text-success',
                        'video'    => 'fa-video text-danger',
                        default    => 'fa-file-alt text-info',
                    };
                    ?>
                    <i class="fas <?= $ico ?>" style="font-size:2rem"></i>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold text-truncate"><?= h($f['original_name']) ?></div>
                    <?php if ($f['description']): ?>
                    <div class="text-muted small mt-1"><?= h(mb_strimwidth($f['description'], 0, 80, '…')) ?></div>
                    <?php endif; ?>
                    <div class="text-muted" style="font-size:.75rem"><?= date('d.m.Y', strtotime($f['created_at'])) ?></div>
                </div>
                <a href="<?= BASE_URL ?>/uploads/gallery/admin/<?= rawurlencode($f['file_path']) ?>"
                   target="_blank" class="btn btn-sm btn-outline-info flex-shrink-0" title="Otevřít">
                    <i class="fas fa-external-link-alt"></i>
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal: Nová složka -->
<div class="modal fade" id="modalNewFolder" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create_folder">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-folder-plus me-2"></i>Nová složka</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold">Název složky</label>
                <input type="text" name="folder_name" class="form-control" maxlength="200" required autofocus
                       placeholder="Např. Tréninková videa">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="submit" class="btn btn-primary">Vytvořit</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Přejmenovat složku -->
<div class="modal fade" id="modalRenameFolder" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="rename_folder">
            <input type="hidden" name="folder_id" id="renameFolderId">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-pencil me-2"></i>Přejmenovat složku</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold">Nový název</label>
                <input type="text" name="folder_name" id="renameFolderName" class="form-control" maxlength="200" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="submit" class="btn btn-primary">Uložit</button>
            </div>
        </form>
    </div>
</div>

<!-- Skrytý formulář: Smazat složku -->
<form method="post" id="deleteFolderForm" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="delete_folder">
    <input type="hidden" name="folder_id" id="deleteFolderId">
</form>

<style>
.gallery-folder-card {
    cursor: pointer;
    transition: transform .15s, box-shadow .15s;
}
.gallery-folder-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 .5rem 1rem rgba(0,0,0,.15) !important;
}
.athlete-folder { border-top: 3px solid #ffc107 !important; }
</style>

<script>
function openRenameModal(id, name) {
    document.getElementById('renameFolderId').value = id;
    document.getElementById('renameFolderName').value = name;
    new bootstrap.Modal(document.getElementById('modalRenameFolder')).show();
}
function confirmDeleteFolder(id, name, count) {
    let msg = 'Opravdu smazat složku „' + name + '"?';
    if (count > 0) msg += '\n\nPozor: bude smazáno ' + count + ' souborů!';
    if (confirm(msg)) {
        document.getElementById('deleteFolderId').value = id;
        document.getElementById('deleteFolderForm').submit();
    }
}
</script>

<?php renderFooter(); ?>
