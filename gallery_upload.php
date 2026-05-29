<?php
// gallery_upload.php – nahrát soubory do galerie (výběr složky)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$coachId = getCurrentCoachId();
$pdo     = getDB();

// Sportovci trenéra
$athletes = $pdo->prepare("SELECT id, first_name, last_name FROM athletes WHERE coach_id = ? ORDER BY first_name, last_name");
$athletes->execute([$coachId]);
$athletes = $athletes->fetchAll();

// Složky trenéra
$folders = $pdo->prepare("
    SELECT f.*, a.first_name, a.last_name
    FROM gallery_folders f
    LEFT JOIN athletes a ON a.id = f.athlete_id
    WHERE f.coach_id = ?
    ORDER BY f.folder_type ASC, a.first_name ASC, a.last_name ASC, f.name ASC
");
$folders->execute([$coachId]);
$folders = $folders->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/gallery_upload.php');
    }

    $description = trim($_POST['description'] ?? '');
    $visibility  = in_array($_POST['visibility'] ?? '', ['private', 'all_athletes', 'specific_athletes'])
                   ? $_POST['visibility'] : 'private';
    $specificIds = array_map('intval', array_filter($_POST['specific_athletes'] ?? []));
    $folderId    = $_POST['folder_id'] !== '' ? (int)$_POST['folder_id'] : null;

    // Ověř složku
    if ($folderId !== null) {
        $chk = $pdo->prepare("SELECT id FROM gallery_folders WHERE id = ? AND coach_id = ?");
        $chk->execute([$folderId, $coachId]);
        if (!$chk->fetch()) $folderId = null;
    }

    $allowed = ['jpg','jpeg','png','gif','webp','mp4','mov','avi','mkv','webm',
                'pdf','doc','docx','xls','xlsx','csv','txt','zip','rar','7z','ppt','pptx'];

    $uploadDir = __DIR__ . '/uploads/gallery/coach_' . $coachId . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $uploadedCount = 0;
    $files         = $_FILES['files'] ?? [];

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

        $mime  = mime_content_type($uploadDir . $newName) ?: '';
        $ftype = str_starts_with($mime, 'image/') ? 'image'
               : (str_starts_with($mime, 'video/') ? 'video' : 'document');

        $ins = $pdo->prepare("INSERT INTO gallery_files (coach_id, folder_id, file_path, original_name, file_size, file_type, mime_type, description, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$coachId, $folderId, $newName, $origName, $size, $ftype, $mime, $description ?: null, $visibility]);
        $newFileId = (int)$pdo->lastInsertId();

        if ($visibility === 'specific_athletes' && !empty($specificIds)) {
            $insVis = $pdo->prepare("INSERT IGNORE INTO gallery_file_athletes (file_id, athlete_id) VALUES (?, ?)");
            foreach ($specificIds as $aid) {
                $insVis->execute([$newFileId, $aid]);
            }
        }

        // Notifikace sportovcům
        if ($visibility === 'all_athletes') {
            foreach ($athletes as $a) {
                notifyAthleteGallery($a['id'], $coachId, $origName, $pdo);
            }
        } elseif ($visibility === 'specific_athletes' && !empty($specificIds)) {
            foreach ($specificIds as $aid) {
                notifyAthleteGallery($aid, $coachId, $origName, $pdo);
            }
        }

        $uploadedCount++;
    }

    if ($uploadedCount > 0) {
        flash('success', "Nahráno $uploadedCount soubor(ů)." . (!empty($errors) ? ' Některé se nezdařily.' : ''));
        redirect($folderId ? BASE_URL . '/gallery_folder.php?id=' . $folderId : BASE_URL . '/gallery.php');
    }
}

function notifyAthleteGallery(int $athleteId, int $coachId, string $fileName, PDO $pdo): void {
    try {
        $coach = $pdo->prepare("SELECT name, username FROM coaches WHERE id = ?");
        $coach->execute([$coachId]);
        $coach = $coach->fetch();
        $coachName = $coach ? ($coach['name'] ?: $coach['username']) : 'Trenér';
        $pdo->prepare("INSERT INTO athlete_notifications (athlete_id, subject, body) VALUES (?, ?, ?)")
            ->execute([$athleteId, 'Nový soubor v galerii',
                'Trenér ' . $coachName . ' sdílel soubor „' . mb_substr($fileName, 0, 100) . '".' ]);
    } catch (Throwable $e) {
        error_log('notifyAthleteGallery error: ' . $e->getMessage());
    }
}

renderHeader('Nahrát soubory do galerie');
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/gallery.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h2 class="mb-0"><i class="fas fa-cloud-upload-alt me-2 text-warning"></i>Nahrát soubory</h2>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

            <!-- Výběr souborů -->
            <div class="mb-3">
                <label class="form-label fw-semibold fs-6">Vybrat soubory</label>
                <input type="file" name="files[]" id="fileInput" class="form-control" multiple required
                       accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip,.rar,.7z,.ppt,.pptx">
                <div class="form-text">
                    Fotografie, videa, dokumenty — max 200 MB každý soubor.
                </div>
            </div>

            <!-- Zachytit z kamery -->
            <div class="d-flex gap-2 mb-4 flex-wrap">
                <label class="btn btn-outline-success" title="Vyfotit přímo z kamery">
                    <i class="fas fa-camera me-1"></i>Vyfotit
                    <input type="file" name="files[]" accept="image/*" capture="environment"
                           class="d-none" onchange="mergeFiles(this)">
                </label>
                <label class="btn btn-outline-danger" title="Natočit video přímo">
                    <i class="fas fa-video me-1"></i>Natočit video
                    <input type="file" name="files[]" accept="video/*" capture="environment"
                           class="d-none" onchange="mergeFiles(this)">
                </label>
            </div>

            <hr>

            <!-- Cílová složka -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Cílová složka</label>
                <select name="folder_id" class="form-select">
                    <option value="">— Bez složky —</option>
                    <?php foreach ($folders as $fld): ?>
                    <?php $fldName = $fld['folder_type'] === 'athlete'
                        ? ($fld['first_name'] . ' ' . $fld['last_name'])
                        : $fld['name']; ?>
                    <option value="<?= $fld['id'] ?>">
                        <?= $fld['folder_type'] === 'athlete' ? '👤 ' : '📁 ' ?><?= h($fldName) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Popis -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Popis <small class="text-muted">(volitelný)</small></label>
                <textarea name="description" class="form-control" rows="2" maxlength="1000"
                          placeholder="Krátký popis souborů…"></textarea>
            </div>

            <!-- Viditelnost -->
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

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-warning fw-bold px-4">
                    <i class="fas fa-upload me-1"></i>Nahrát
                </button>
                <a href="<?= BASE_URL ?>/gallery.php" class="btn btn-outline-secondary">Zrušit</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
document.getElementById('visSelect')?.addEventListener('change', function () {
    document.getElementById('specificAthletes')?.classList.toggle('d-none', this.value !== 'specific_athletes');
});
function mergeFiles(captureInput) {
    const main = document.getElementById('fileInput');
    const dt   = new DataTransfer();
    for (const file of (main.files || [])) dt.items.add(file);
    for (const file of (captureInput.files || [])) dt.items.add(file);
    main.files = dt.files;
    captureInput.value = '';
}
</script>

<?php renderFooter(); ?>
