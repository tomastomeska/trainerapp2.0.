<?php
// gallery_folder.php – obsah jedné složky nebo "bez složky"
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$coachId  = getCurrentCoachId();
$pdo      = getDB();
$folderId = intParam($_GET, 'id');
$unfiled  = isset($_GET['unfiled']);

$folder = null;
$folderName = 'Bez složky';
$isAthlete  = false;

if (!$unfiled) {
    $stmt = $pdo->prepare("SELECT f.*, a.first_name, a.last_name FROM gallery_folders f LEFT JOIN athletes a ON a.id = f.athlete_id WHERE f.id = ? AND f.coach_id = ?");
    $stmt->execute([$folderId, $coachId]);
    $folder = $stmt->fetch();
    if (!$folder) {
        flash('danger', 'Složka nebyla nalezena.');
        redirect(BASE_URL . '/gallery.php');
    }
    $folderName = $folder['folder_type'] === 'athlete'
        ? ($folder['first_name'] . ' ' . $folder['last_name'])
        : $folder['name'];
    $isAthlete = $folder['folder_type'] === 'athlete';
}

// Sportovci trenéra (pro nahrání a přiřazení viditelnosti)
$athletes = $pdo->prepare("SELECT id, first_name, last_name FROM athletes WHERE coach_id = ? ORDER BY first_name, last_name");
$athletes->execute([$coachId]);
$athletes = $athletes->fetchAll();

// Zpracování POST akcí
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect($_SERVER['REQUEST_URI']);
    }
    $action = $_POST['action'] ?? '';

    // Smazání souboru
    if ($action === 'delete_file') {
        $fileId = intParam($_POST, 'file_id');
        $f = $pdo->prepare("SELECT * FROM gallery_files WHERE id = ? AND coach_id = ?");
        $f->execute([$fileId, $coachId]);
        $f = $f->fetch();
        if ($f) {
            $full = __DIR__ . '/uploads/gallery/coach_' . $coachId . '/' . $f['file_path'];
            if (file_exists($full)) @unlink($full);
            $pdo->prepare("DELETE FROM gallery_files WHERE id = ? AND coach_id = ?")->execute([$fileId, $coachId]);
            flash('success', 'Soubor byl smazán.');
        }
        redirect($_SERVER['REQUEST_URI']);
    }

    // Nahrání souboru(ů)
    if ($action === 'upload') {
        $description = trim($_POST['description'] ?? '');
        $visibility  = in_array($_POST['visibility'] ?? '', ['private', 'all_athletes', 'specific_athletes'])
                       ? $_POST['visibility'] : 'private';
        $specificIds = array_map('intval', array_filter($_POST['specific_athletes'] ?? []));

        $allowed = ['jpg','jpeg','png','gif','webp','mp4','mov','avi','mkv','webm',
                    'pdf','doc','docx','xls','xlsx','csv','txt','zip','rar','7z','ppt','pptx'];

        $targetFolderId = $unfiled ? null : $folderId;
        $uploadDir      = __DIR__ . '/uploads/gallery/coach_' . $coachId . '/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $uploadedCount = 0;
        $errors        = [];
        $files         = $_FILES['files'] ?? [];

        // Normalize single/multiple
        if (!is_array($files['name'])) {
            $files = ['name' => [$files['name']], 'type' => [$files['type']],
                      'tmp_name' => [$files['tmp_name']], 'error' => [$files['error']], 'size' => [$files['size']]];
        }

        foreach ($files['name'] as $i => $origName) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK || !$origName) continue;
            $size = $files['size'][$i];
            if ($size > 200 * 1024 * 1024) { $errors[] = h($origName) . ': max 200 MB.'; continue; }
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) { $errors[] = h($origName) . ': typ .' . $ext . ' není povolen.'; continue; }

            $newName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($files['tmp_name'][$i], $uploadDir . $newName)) {
                $errors[] = h($origName) . ': nepodařilo se uložit.'; continue;
            }

            // Určení typu souboru
            $mime  = mime_content_type($uploadDir . $newName) ?: '';
            $ftype = str_starts_with($mime, 'image/') ? 'image'
                   : (str_starts_with($mime, 'video/') ? 'video' : 'document');

            $ins = $pdo->prepare("INSERT INTO gallery_files (coach_id, folder_id, file_path, original_name, file_size, file_type, mime_type, description, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([$coachId, $targetFolderId, $newName, $origName, $size, $ftype, $mime, $description ?: null, $visibility]);
            $newFileId = (int)$pdo->lastInsertId();

            if ($visibility === 'specific_athletes' && !empty($specificIds)) {
                $insVis = $pdo->prepare("INSERT IGNORE INTO gallery_file_athletes (file_id, athlete_id) VALUES (?, ?)");
                foreach ($specificIds as $aid) {
                    $insVis->execute([$newFileId, $aid]);
                }
            }
            $uploadedCount++;

            // Notifikace sportovcům
            if ($visibility === 'all_athletes') {
                foreach ($athletes as $a) {
                    notifyAthleteNewGalleryFile($a['id'], $coachId, $origName);
                }
            } elseif ($visibility === 'specific_athletes' && !empty($specificIds)) {
                foreach ($specificIds as $aid) {
                    notifyAthleteNewGalleryFile($aid, $coachId, $origName);
                }
            }
        }

        if ($uploadedCount > 0) flash('success', "Nahráno $uploadedCount soubor(ů)." . (!empty($errors) ? ' Některé selhaly.' : ''));
        if (!empty($errors) && $uploadedCount === 0) flash('danger', implode('<br>', $errors));
        redirect($_SERVER['REQUEST_URI']);
    }
}

// Načíst soubory v této složce
if ($unfiled) {
    $stmt = $pdo->prepare("SELECT * FROM gallery_files WHERE coach_id = ? AND folder_id IS NULL ORDER BY created_at DESC");
    $stmt->execute([$coachId]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM gallery_files WHERE coach_id = ? AND folder_id = ? ORDER BY created_at DESC");
    $stmt->execute([$coachId, $folderId]);
}
$files = $stmt->fetchAll();

renderHeader($folderName);

/**
 * Odešle in-app notifikaci sportovcovi o novém souboru v galerii.
 */
function notifyAthleteNewGalleryFile(int $athleteId, int $coachId, string $fileName): void
{
    try {
        $pdo  = getDB();
        $coach = $pdo->prepare("SELECT name, username FROM coaches WHERE id = ?");
        $coach->execute([$coachId]);
        $coach = $coach->fetch();
        $coachName = $coach ? ($coach['name'] ?: $coach['username']) : 'Trenér';

        $pdo->prepare("
            INSERT INTO athlete_notifications (athlete_id, subject, body)
            VALUES (?, ?, ?)
        ")->execute([
            $athleteId,
            'Nový soubor v galerii',
            'Trenér ' . $coachName . ' sdílel soubor „' . mb_substr($fileName, 0, 100) . '".'
        ]);
    } catch (Throwable $e) {
        // Chyba notifikace není kritická
        error_log('notifyAthleteNewGalleryFile error: ' . $e->getMessage());
    }
}
?>

<div class="d-flex align-items-center mb-4 gap-3 flex-wrap">
    <a href="<?= BASE_URL ?>/gallery.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h2 class="mb-0 flex-grow-1">
        <i class="fas <?= $isAthlete ? 'fa-user text-warning' : 'fa-folder-open text-primary' ?> me-2"></i>
        <?= h($folderName) ?>
    </h2>
    <button class="btn btn-warning btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalUpload">
        <i class="fas fa-cloud-upload-alt me-1"></i>Nahrát soubory
    </button>
</div>

<?php if (empty($files)): ?>
<div class="alert alert-light border text-center py-5">
    <i class="fas fa-folder-open fa-3x text-muted mb-3 d-block"></i>
    <p class="text-muted mb-2">Složka je prázdná.</p>
    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalUpload">
        <i class="fas fa-cloud-upload-alt me-1"></i>Nahrát první soubor
    </button>
</div>
<?php else: ?>

<!-- Přehled souborů -->
<div class="row g-3">
    <?php foreach ($files as $f): ?>
    <div class="col-6 col-md-4 col-lg-3 col-xl-2">
        <div class="card border-0 shadow-sm h-100 position-relative gallery-file-card">
            <?php
            $ico = match($f['file_type']) {
                'image'  => 'fa-image',
                'video'  => 'fa-video',
                default  => 'fa-file-alt',
            };
            $icoColor = match($f['file_type']) {
                'image'  => 'text-success',
                'video'  => 'text-danger',
                default  => 'text-info',
            };
            ?>
            <a href="<?= BASE_URL ?>/gallery_file_detail.php?id=<?= $f['id'] ?>"
               class="stretched-link text-decoration-none">
                <?php if ($f['file_type'] === 'image'): ?>
                <div class="gallery-thumb overflow-hidden" style="height:120px;background:#f8f9fa">
                    <img src="<?= BASE_URL ?>/uploads/gallery/coach_<?= $coachId ?>/<?= rawurlencode($f['file_path']) ?>"
                         alt="<?= h($f['original_name']) ?>"
                         style="width:100%;height:120px;object-fit:cover">
                </div>
                <?php else: ?>
                <div class="d-flex align-items-center justify-content-center" style="height:100px;background:#f8f9fa">
                    <i class="fas <?= $ico ?> <?= $icoColor ?>" style="font-size:2.5rem"></i>
                </div>
                <?php endif; ?>
                <div class="card-body p-2">
                    <div class="fw-semibold small text-dark text-truncate"><?= h($f['original_name']) ?></div>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <span class="badge <?= match($f['visibility']) {
                            'all_athletes'      => 'bg-success',
                            'specific_athletes' => 'bg-warning text-dark',
                            default             => 'bg-secondary',
                        } ?>" style="font-size:.65rem">
                            <?= match($f['visibility']) {
                                'all_athletes'      => 'Všichni sportovci',
                                'specific_athletes' => 'Vybraní',
                                default             => 'Soukromý',
                            } ?>
                        </span>
                        <span class="text-muted" style="font-size:.7rem"><?= date('d.m.Y', strtotime($f['created_at'])) ?></span>
                    </div>
                </div>
            </a>
            <!-- Rychlé smazání -->
            <form method="post" class="position-absolute top-0 end-0 m-1" style="z-index:2"
                  onsubmit="return confirm('Smazat soubor <?= h(addslashes($f['original_name'])) ?>?')">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="delete_file">
                <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                <button class="btn btn-sm btn-danger p-0" style="width:22px;height:22px;font-size:.65rem;line-height:1"
                        title="Smazat"><i class="fas fa-times"></i></button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal: Nahrát soubory -->
<div class="modal fade" id="modalUpload" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="post" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="upload">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cloud-upload-alt me-2"></i>Nahrát soubory do složky „<?= h($folderName) ?>"</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Výběr / zachycení souborů -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Soubory <small class="text-muted">(max 200 MB každý – obrázky, videa, dokumenty)</small></label>
                    <input type="file" name="files[]" id="fileInput" class="form-control" multiple
                           accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip,.rar,.7z,.ppt,.pptx">
                    <div class="form-text">
                        <i class="fas fa-camera me-1"></i>Na telefonech/tabletech lze soubory přímo vyfotit nebo natočit.
                    </div>
                </div>

                <!-- Zachytit z kamery (capture) -->
                <div class="d-flex gap-2 mb-3 flex-wrap">
                    <label class="btn btn-outline-success btn-sm" title="Vyfotit">
                        <i class="fas fa-camera me-1"></i>Vyfotit
                        <input type="file" name="files[]" accept="image/*" capture="environment" class="d-none" onchange="mergeFiles(this)">
                    </label>
                    <label class="btn btn-outline-danger btn-sm" title="Natočit video">
                        <i class="fas fa-video me-1"></i>Natočit video
                        <input type="file" name="files[]" accept="video/*" capture="environment" class="d-none" onchange="mergeFiles(this)">
                    </label>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Popis <small class="text-muted">(volitelný)</small></label>
                    <textarea name="description" class="form-control" rows="2" maxlength="1000"
                              placeholder="Krátký popis souborů…"></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Viditelnost</label>
                    <select name="visibility" class="form-select" id="visSelect">
                        <option value="private">🔒 Soukromý – pouze já</option>
                        <?php if (!empty($athletes)): ?>
                        <option value="all_athletes">👥 Sdílet se všemi mými sportovci</option>
                        <option value="specific_athletes">👤 Sdílet s vybranými sportovci</option>
                        <?php endif; ?>
                    </select>
                </div>

                <?php if (!empty($athletes)): ?>
                <div id="specificAthletes" class="mb-3 d-none">
                    <label class="form-label fw-semibold">Vyberte sportovce</label>
                    <div class="row g-2">
                        <?php foreach ($athletes as $a): ?>
                        <div class="col-sm-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="specific_athletes[]" value="<?= $a['id'] ?>"
                                       id="ath<?= $a['id'] ?>">
                                <label class="form-check-label" for="ath<?= $a['id'] ?>">
                                    <?= h($a['first_name'] . ' ' . $a['last_name']) ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="submit" class="btn btn-warning fw-bold">
                    <i class="fas fa-upload me-1"></i>Nahrát
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.gallery-file-card { transition: transform .15s, box-shadow .15s; }
.gallery-file-card:hover { transform: translateY(-2px); box-shadow: 0 .4rem .8rem rgba(0,0,0,.12) !important; }
</style>

<script>
// Zobrazit/skrýt výběr konkrétních sportovců
document.getElementById('visSelect')?.addEventListener('change', function () {
    document.getElementById('specificAthletes')?.classList.toggle('d-none', this.value !== 'specific_athletes');
});

// Merge souborů z capture vstupu do hlavního input
function mergeFiles(captureInput) {
    const main = document.getElementById('fileInput');
    const dt   = new DataTransfer();
    // Přidat stávající z hlavního inputu
    for (const file of (main.files || [])) dt.items.add(file);
    // Přidat nové z capture
    for (const file of (captureInput.files || [])) dt.items.add(file);
    main.files = dt.files;
    captureInput.value = '';
}
</script>

<?php renderFooter(); ?>
