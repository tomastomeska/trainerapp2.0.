<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$pdo     = getDB();
$error   = null;

// Přidání cviku – formulář odesílá multipart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $sportType = $_POST['sport_type'] ?? 'standard';
        if ($name === '') {
            $error = 'Zadejte název cviku.';
        } else {
            $photo = saveUploadedPhoto('photo', 'exercises');
            $pdo->prepare('INSERT INTO exercises (coach_id, name, photo, sport_type) VALUES (?, ?, ?, ?)')
                ->execute([$coachId, $name, $photo, $sportType]);
            flash('success', "Cvik \"$name\" byl přidán.");
            redirect(BASE_URL . '/exercises.php');
        }
    }
}

// Smazání cviku
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $exId = intParam($_POST, 'exercise_id');
        // Ověř vlastnictví
        $stmt = $pdo->prepare('SELECT id FROM exercises WHERE id = ? AND coach_id = ?');
        $stmt->execute([$exId, $coachId]);
        if ($stmt->fetch()) {
            // Zkontroluj, zda cvik není použit v sadě
            $stmt2 = $pdo->prepare('SELECT COUNT(*) FROM workout_set_exercises WHERE exercise_id = ?');
            $stmt2->execute([$exId]);
            if ((int)$stmt2->fetchColumn() > 0) {
                $error = 'Tento cvik nelze smazat, protože je použit v sadě.';
            } else {
                $pdo->prepare('DELETE FROM exercises WHERE id = ? AND coach_id = ?')
                    ->execute([$exId, $coachId]);
                flash('success', 'Cvik byl smazán.');
                redirect(BASE_URL . '/exercises.php');
            }
        }
    }
}

// Přejmenování cviku
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rename') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $exId    = intParam($_POST, 'exercise_id');
        $newName = trim($_POST['new_name'] ?? '');
        $sportType = $_POST['sport_type'] ?? 'standard';
        if ($newName === '') {
            $error = 'Zadejte název cviku.';
        } else {
            $newPhoto = saveUploadedPhoto('photo', 'exercises');
            if ($newPhoto !== null) {
                // Smazat starou fotografii
                $stmtOld = $pdo->prepare('SELECT photo FROM exercises WHERE id = ? AND coach_id = ?');
                $stmtOld->execute([$exId, $coachId]);
                $oldRow = $stmtOld->fetch();
                if ($oldRow) deleteUploadedPhoto($oldRow['photo'], 'exercises');
                $pdo->prepare('UPDATE exercises SET name = ?, photo = ?, sport_type = ? WHERE id = ? AND coach_id = ?')
                    ->execute([$newName, $newPhoto, $sportType, $exId, $coachId]);
            } else {
                $pdo->prepare('UPDATE exercises SET name = ?, sport_type = ? WHERE id = ? AND coach_id = ?')
                    ->execute([$newName, $sportType, $exId, $coachId]);
            }
            flash('success', 'Cvik byl upraven.');
            redirect(BASE_URL . '/exercises.php');
        }
    }
}

// Načtení cviků – vlastní trenéra
$stmt = $pdo->prepare(
    'SELECT e.*,
            (SELECT COUNT(*) FROM workout_set_exercises wse WHERE wse.exercise_id = e.id) AS set_count,
            (SELECT COUNT(*) FROM session_series ss WHERE ss.exercise_id = e.id) AS series_count
     FROM exercises e
     WHERE e.coach_id = ?
     ORDER BY e.name'
);
$stmt->execute([$coachId]);
$exercises = $stmt->fetchAll();

// Globální cviky (spravuje superadmin)
$globalExercises = $pdo->query(
    'SELECT e.*,
            (SELECT COUNT(*) FROM workout_set_exercises wse WHERE wse.exercise_id = e.id) AS set_count,
            (SELECT COUNT(*) FROM session_series ss WHERE ss.exercise_id = e.id) AS series_count
     FROM exercises e
     WHERE e.is_global = 1
     ORDER BY e.name'
)->fetchAll();

renderHeader('Cviky');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="fas fa-list me-2 text-warning"></i>Cviky</h2>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Přidat cvik -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-warning text-dark fw-bold">
                <i class="fas fa-plus me-2"></i>Přidat cvik
            </div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Název cviku</label>
                        <input type="text" name="name" class="form-control"
                               placeholder="např. Benchpress, Dřep, Mrtvý tah..."
                               required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Typ cviku</label>
                        <select name="sport_type" class="form-select">
                            <option value="standard" selected>Standardní cvik (váha, opakování)</option>
                            <option value="golf">Golf (jamky, par)</option>
                            <option value="run_outdoor">Běh venku (tempo, splity)</option>
                            <option value="run_treadmill">Běh na páse (čas, km)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Fotografie <span class="text-muted fw-normal">(nepovinné)</span></label>
                        <input type="file" name="photo" class="form-control" accept="image/*">
                    </div>
                    <button type="submit" class="btn btn-warning fw-bold w-100">
                        <i class="fas fa-plus me-1"></i>Přidat
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Seznam cviků -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-list me-2"></i>Seznam cviků
                <span class="badge bg-secondary ms-2"><?= count($exercises) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($exercises)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                    Zatím žádné cviky. Přidejte první cvik vlevo.
                </div>
                <?php else: ?>
                <div class="list-group list-group-flush" id="exercises-list">
                    <?php foreach ($exercises as $ex): ?>
                    <div class="list-group-item list-group-item-action d-flex align-items-center gap-3"
                         id="ex-row-<?= $ex['id'] ?>">
                        <?php $exPhoto = photoUrl($ex['photo'] ?? null, 'exercises'); ?>
                        <div class="flex-shrink-0">
                            <?php if ($exPhoto): ?>
                            <img src="<?= h($exPhoto) ?>" alt="<?= h($ex['name']) ?>"
                                 class="rounded" style="width:48px;height:48px;object-fit:cover;">
                            <?php else: ?>
                            <div class="rounded bg-light d-flex align-items-center justify-content-center"
                                 style="width:48px;height:48px;">
                                <i class="fas fa-dumbbell text-muted"></i>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1">
                            <span class="exercise-name fw-semibold"><?= h($ex['name']) ?></span>
                            <?php
                                $typeLabels = [
                                    'standard' => ['label' => 'Cvik', 'color' => 'secondary'],
                                    'golf' => ['label' => 'Golf', 'color' => 'info'],
                                    'run_outdoor' => ['label' => 'Běh venku', 'color' => 'success'],
                                    'run_treadmill' => ['label' => 'Běh na páse', 'color' => 'primary'],
                                ];
                                $typeInfo = $typeLabels[$ex['sport_type']] ?? $typeLabels['standard'];
                            ?>
                            <span class="badge bg-<?= $typeInfo['color'] ?> ms-2 small"><?= $typeInfo['label'] ?></span>
                            <span class="exercise-edit d-none">
                                <form method="post" enctype="multipart/form-data"
                                      class="d-flex flex-wrap gap-2 align-items-center mt-1">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="rename">
                                    <input type="hidden" name="exercise_id" value="<?= $ex['id'] ?>">
                                    <input type="text" name="new_name" class="form-control form-control-sm"
                                           value="<?= h($ex['name']) ?>" style="min-width:180px">
                                    <select name="sport_type" class="form-select form-select-sm" style="max-width:200px">
                                        <option value="standard" <?= $ex['sport_type'] === 'standard' ? 'selected' : '' ?>>Standardní</option>
                                        <option value="golf" <?= $ex['sport_type'] === 'golf' ? 'selected' : '' ?>>Golf</option>
                                        <option value="run_outdoor" <?= $ex['sport_type'] === 'run_outdoor' ? 'selected' : '' ?>>Běh venku</option>
                                        <option value="run_treadmill" <?= $ex['sport_type'] === 'run_treadmill' ? 'selected' : '' ?>>Běh na páse</option>
                                    </select>
                                    <input type="file" name="photo" class="form-control form-control-sm"
                                           accept="image/*" style="max-width:100%;flex:1;min-width:0"
                                           title="Změnit fotografii (nepovinné)">
                                    <button type="submit" class="btn btn-success btn-sm">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm"
                                            onclick="cancelEdit(<?= $ex['id'] ?>)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                            </span>
                        </div>
                        <div class="text-muted small me-2">
                            <?php if ($ex['set_count'] > 0): ?>
                            <span class="badge bg-light text-dark border">
                                <?= $ex['set_count'] ?> <?= $ex['set_count'] === 1 ? 'sada' : 'sad' ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($ex['series_count'] > 0): ?>
                            <span class="badge bg-light text-dark border ms-1">
                                <?= $ex['series_count'] ?> sérií
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-1 flex-shrink-0">
                            <button class="btn btn-outline-secondary btn-sm"
                                    onclick="editExercise(<?= $ex['id'] ?>)"
                                    title="Přejmenovat">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php if ($ex['set_count'] == 0): ?>
                            <form method="post" class="d-inline"
                                  onsubmit="return confirm('Smazat cvik \'<?= h(addslashes($ex['name'])) ?>\'?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="exercise_id" value="<?= $ex['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Smazat">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <button class="btn btn-outline-secondary btn-sm" disabled
                                    title="Nelze smazat – cvik je použit v sadě">
                                <i class="fas fa-lock"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function editExercise(id) {
    document.querySelector('#ex-row-' + id + ' .exercise-name').classList.add('d-none');
    document.querySelector('#ex-row-' + id + ' .exercise-edit').classList.remove('d-none');
}
function cancelEdit(id) {
    document.querySelector('#ex-row-' + id + ' .exercise-name').classList.remove('d-none');
    document.querySelector('#ex-row-' + id + ' .exercise-edit').classList.add('d-none');
}
</script>



<?php if (!empty($globalExercises)): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header d-flex align-items-center gap-2" style="background:#312e81;color:#fff">
        <i class="fas fa-globe me-1"></i>
        <span class="fw-bold">Globální cviky</span>
        <span class="badge bg-light text-dark ms-1"><?= count($globalExercises) ?></span>
        <span class="ms-auto small opacity-75">Spravuje superadministrátor &ndash; lze použít v sadách</span>
    </div>
    <div class="card-body p-0">
        <div class="list-group list-group-flush">
            <?php foreach ($globalExercises as $ex): ?>
            <div class="list-group-item d-flex align-items-center gap-3">
                <?php $exPhoto = photoUrl($ex['photo'] ?? null, 'exercises'); ?>
                <div class="flex-shrink-0">
                    <?php if ($exPhoto): ?>
                    <img src="<?= h($exPhoto) ?>" alt="<?= h($ex['name']) ?>"
                         class="rounded" style="width:40px;height:40px;object-fit:cover;">
                    <?php else: ?>
                    <div class="rounded d-flex align-items-center justify-content-center"
                         style="width:40px;height:40px;background:#e8e4ff">
                        <i class="fas fa-globe" style="color:#7c3aed"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="flex-grow-1 fw-semibold"><?= h($ex['name']) ?></div>
                <div class="text-muted small d-flex gap-2">
                    <?php if ($ex['set_count'] > 0): ?>
                    <span class="badge bg-light text-dark border"><?= $ex['set_count'] ?> sad</span>
                    <?php endif; ?>
                    <?php if ($ex['series_count'] > 0): ?>
                    <span class="badge bg-light text-dark border"><?= $ex['series_count'] ?> sérií</span>
                    <?php endif; ?>
                    <span class="badge" style="background:#e8e4ff;color:#7c3aed">globální</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?><?php renderFooter(); ?>
