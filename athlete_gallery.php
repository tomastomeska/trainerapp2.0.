<?php
// athlete_gallery.php – galerie pro přihlášeného sportovce
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireAthleteLogin();
$athleteId = getCurrentAthleteId();
$pdo       = getDB();

// Sportovec a jeho trenér
$athlete = $pdo->prepare("SELECT a.*, c.id AS coach_id, c.name AS coach_name, c.username AS coach_username FROM athletes a JOIN coaches c ON c.id = a.coach_id WHERE a.id = ?");
$athlete->execute([$athleteId]);
$athlete = $athlete->fetch();
if (!$athlete) { redirect(BASE_URL . '/athlete_dashboard.php'); }

$coachId = (int)$athlete['coach_id'];

// Soubory sdílené s tímto sportovcem od trenéra (all_athletes nebo specific kde je zahrnut)
$files = $pdo->prepare("
    SELECT gf.*, fld.name AS folder_name, fld.folder_type,
           a_fld.first_name AS fld_athlete_first, a_fld.last_name AS fld_athlete_last
    FROM gallery_files gf
    LEFT JOIN gallery_folders fld ON fld.id = gf.folder_id
    LEFT JOIN athletes a_fld ON a_fld.id = fld.athlete_id
    WHERE gf.coach_id = ?
      AND (
          gf.visibility = 'all_athletes'
          OR (gf.visibility = 'specific_athletes'
              AND EXISTS (SELECT 1 FROM gallery_file_athletes gfa WHERE gfa.file_id = gf.id AND gfa.athlete_id = ?))
      )
    ORDER BY gf.created_at DESC
");
$files->execute([$coachId, $athleteId]);
$allFiles = $files->fetchAll();

// Seskupit podle složky
$byFolder = [];
foreach ($allFiles as $f) {
    $key = $f['folder_id'] ?? 'none';
    if (!isset($byFolder[$key])) {
        if ($f['folder_id'] === null) {
            $byFolder[$key] = ['name' => 'Ostatní', 'type' => 'none', 'files' => []];
        } elseif ($f['folder_type'] === 'athlete') {
            $byFolder[$key] = ['name' => $f['fld_athlete_first'] . ' ' . $f['fld_athlete_last'], 'type' => 'athlete', 'files' => []];
        } else {
            $byFolder[$key] = ['name' => $f['folder_name'], 'type' => 'custom', 'files' => []];
        }
    }
    $byFolder[$key]['files'][] = $f;
}

// Soubory od admina
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

renderHeader('Galerie');
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h2 class="mb-0"><i class="fas fa-images me-2 text-warning"></i>Galerie</h2>
</div>

<?php if (empty($allFiles) && empty($adminFiles)): ?>
<div class="alert alert-light border text-center py-5">
    <i class="fas fa-images fa-3x text-muted mb-3 d-block"></i>
    <p class="text-muted mb-0">Zatím zde nejsou žádné sdílené soubory.</p>
</div>
<?php else: ?>

<?php foreach ($byFolder as $folder): ?>
<div class="mb-5">
    <h5 class="fw-bold mb-3">
        <i class="fas <?= $folder['type'] === 'athlete' ? 'fa-user text-warning' : 'fa-folder text-primary' ?> me-2"></i>
        <?= h($folder['name']) ?>
        <span class="badge bg-secondary ms-2" style="font-size:.75rem"><?= count($folder['files']) ?></span>
    </h5>
    <div class="row g-3">
        <?php foreach ($folder['files'] as $f): ?>
        <div class="col-6 col-md-4 col-lg-3 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <?php
                $fileSrc = BASE_URL . '/uploads/gallery/coach_' . $coachId . '/' . rawurlencode($f['file_path']);
                $ico = match($f['file_type']) { 'image' => 'fa-image', 'video' => 'fa-video', default => 'fa-file-alt' };
                $icoColor = match($f['file_type']) { 'image' => 'text-success', 'video' => 'text-danger', default => 'text-info' };
                ?>
                <?php if ($f['file_type'] === 'image'): ?>
                <a href="<?= $fileSrc ?>" target="_blank">
                    <img src="<?= $fileSrc ?>" alt="<?= h($f['original_name']) ?>"
                         style="width:100%;height:120px;object-fit:cover;border-radius:.375rem .375rem 0 0">
                </a>
                <?php elseif ($f['file_type'] === 'video'): ?>
                <div class="d-flex align-items-center justify-content-center" style="height:100px;background:#f8f9fa;border-radius:.375rem .375rem 0 0">
                    <a href="<?= $fileSrc ?>" target="_blank" class="text-decoration-none">
                        <i class="fas <?= $ico ?> <?= $icoColor ?>" style="font-size:2.5rem"></i>
                    </a>
                </div>
                <?php else: ?>
                <div class="d-flex align-items-center justify-content-center" style="height:80px;background:#f8f9fa;border-radius:.375rem .375rem 0 0">
                    <a href="<?= $fileSrc ?>" target="_blank" class="text-decoration-none">
                        <i class="fas <?= $ico ?> <?= $icoColor ?>" style="font-size:2rem"></i>
                    </a>
                </div>
                <?php endif; ?>
                <div class="card-body p-2">
                    <div class="small fw-semibold text-truncate"><?= h($f['original_name']) ?></div>
                    <?php if ($f['description']): ?>
                    <div class="text-muted" style="font-size:.75rem"><?= h(mb_strimwidth($f['description'], 0, 60, '…')) ?></div>
                    <?php endif; ?>
                    <div class="text-muted" style="font-size:.7rem"><?= date('d.m.Y', strtotime($f['created_at'])) ?></div>
                    <a href="<?= $fileSrc ?>" target="_blank" class="btn btn-sm btn-outline-secondary w-100 mt-1" style="font-size:.75rem">
                        <i class="fas fa-eye me-1"></i>Otevřít
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if (!empty($adminFiles)): ?>
<div class="mb-5">
    <h5 class="fw-bold mb-3">
        <i class="fas fa-shield-alt me-2 text-info"></i>Od administrátora
    </h5>
    <div class="row g-3">
        <?php foreach ($adminFiles as $f): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-start gap-3">
                    <?php
                    $ico = match($f['file_type']) { 'image' => 'fa-image', 'video' => 'fa-video', default => 'fa-file-alt' };
                    $icoColor = match($f['file_type']) { 'image' => 'text-success', 'video' => 'text-danger', default => 'text-info' };
                    $src = BASE_URL . '/uploads/gallery/admin/' . rawurlencode($f['file_path']);
                    ?>
                    <i class="fas <?= $ico ?> <?= $icoColor ?> mt-1" style="font-size:1.8rem"></i>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate"><?= h($f['original_name']) ?></div>
                        <?php if ($f['description']): ?>
                        <div class="text-muted small"><?= h(mb_strimwidth($f['description'], 0, 80, '…')) ?></div>
                        <?php endif; ?>
                        <div class="text-muted" style="font-size:.75rem"><?= date('d.m.Y', strtotime($f['created_at'])) ?></div>
                    </div>
                    <a href="<?= $src ?>" target="_blank" class="btn btn-sm btn-outline-info flex-shrink-0">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php renderFooter(); ?>
