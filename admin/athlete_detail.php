<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId   = getCurrentCoachId();
$athleteId = intParam($_GET, 'id');
$pdo       = getDB();

// Ověření vlastnictví
$stmt = $pdo->prepare('SELECT * FROM athletes WHERE id = ? AND coach_id = ?');
$stmt->execute([$athleteId, $coachId]);
$athlete = $stmt->fetch();
$athleteAge = calculateAge($athlete['birth_date'] ?? null);

if (!$athlete) {
    flash('danger', 'Sportovec nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

// Tréninkové záznamy sportovce
$stmt = $pdo->prepare(
    'SELECT ts.*, ws.name AS set_name,
            (SELECT COUNT(*) FROM session_series ss WHERE ss.session_id = ts.id) AS total_series
     FROM training_sessions ts
     JOIN workout_sets ws ON ts.workout_set_id = ws.id
    WHERE ts.athlete_id = ? AND ts.deleted_by_coach_at IS NULL
     ORDER BY ts.started_at DESC'
);
$stmt->execute([$athleteId]);
$sessions = $stmt->fetchAll();

// Poslední trénink
$lastSession = null;
foreach ($sessions as $s) {
    if ($s['completed_at']) { $lastSession = $s; break; }
}

$currentMonthKey = date('Y-m');
$currentMonthSessions = [];
$olderSessionsByMonth = [];
foreach ($sessions as $s) {
    $monthKey = date('Y-m', strtotime($s['started_at']));
    if ($monthKey === $currentMonthKey) {
        $currentMonthSessions[] = $s;
    } else {
        $olderSessionsByMonth[$monthKey][] = $s;
    }
}

// Sady dostupné pro trénink (pro dropdown "Spustit trénink")
$stmtSets = $pdo->prepare(
    'SELECT ws.*, COUNT(wse.id) AS exercise_count,
            GROUP_CONCAT(e.name ORDER BY wse.exercise_order SEPARATOR ", ") AS exercise_names
     FROM workout_sets ws
     LEFT JOIN workout_set_exercises wse ON ws.id = wse.workout_set_id
     LEFT JOIN exercises e ON e.id = wse.exercise_id
     WHERE ws.coach_id = ?
     GROUP BY ws.id
     ORDER BY ws.name'
);
$stmtSets->execute([$coachId]);
$workoutSets = $stmtSets->fetchAll();

renderHeader(h($athlete['first_name'] . ' ' . $athlete['last_name']));
?>

<div class="d-flex align-items-center mb-4 gap-3 page-header">
    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h2 class="mb-0 fw-bold"><?= h($athlete['first_name'] . ' ' . $athlete['last_name']) ?></h2>
    <div class="ms-auto d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/graphs.php?athlete_id=<?= $athleteId ?>"
           class="btn btn-outline-info btn-sm">
            <i class="fas fa-chart-line me-1"></i>Grafy
        </a>
        <a href="<?= BASE_URL ?>/progress_report.php?athlete_id=<?= $athleteId ?>"
           class="btn btn-outline-success btn-sm">
            <i class="fas fa-file-alt me-1"></i>Zpráva o pokroku
        </a>
        <a href="<?= BASE_URL ?>/athlete_edit.php?id=<?= $athleteId ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-edit me-1"></i>Upravit
        </a>
        <form method="post" action="<?= BASE_URL ?>/athlete_delete.php" class="d-inline"
              onsubmit="return confirm('Opravdu smazat tohoto sportovce? Smažou se i všechny tréninky!')">
            <?= csrfField() ?>
            <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="fas fa-trash"></i>
            </button>
        </form>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Info karta -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-user me-2"></i>Informace
            </div>
            <div class="card-body">
                <?php $athletePhoto = photoUrl($athlete['photo'] ?? null, 'athletes'); ?>
                <?php if ($athletePhoto): ?>
                <div class="text-center mb-3">
                    <img src="<?= h($athletePhoto) ?>" alt="<?= h($athlete['first_name']) ?>"
                         class="rounded-circle" style="width:100px;height:100px;object-fit:cover;border:3px solid #ffc107;">
                </div>
                <?php endif; ?>
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted fw-semibold" style="width:40%">Věk</td>
                        <td><?= $athleteAge !== null ? h((string)$athleteAge) . ' let' : '–' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Datum narození</td>
                        <td><?= !empty($athlete['birth_date']) ? formatDate($athlete['birth_date']) : '–' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">E-mail</td>
                        <td><?= $athlete['email'] ? '<a href="mailto:'.h($athlete['email']).'">'.h($athlete['email']).'</a>' : '–' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Tel. kontakt</td>
                        <td><?= $athlete['phone_contact'] ? h($athlete['phone_contact']) : '–' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Tréninků</td>
                        <td><span class="badge bg-warning text-dark"><?= count(array_filter($sessions, fn($s) => $s['completed_at'])) ?>×</span></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Přidán</td>
                        <td><?= formatDate($athlete['created_at']) ?></td>
                    </tr>
                    <?php if ($athlete['notes']): ?>
                    <tr>
                        <td class="text-muted fw-semibold">Poznámky</td>
                        <td><?= h($athlete['notes']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Spustit trénink -->
    <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-warning text-dark">
                <i class="fas fa-play me-2"></i>Spustit nový trénink
            </div>
            <div class="card-body">
                <?php if ($lastSession): ?>
                <div class="alert alert-light border mb-3 py-2">
                    <small class="text-muted">Poslední trénink:</small><br>
                    <strong><?= h($lastSession['set_name']) ?></strong>
                    – <?= formatDateTime($lastSession['completed_at']) ?>
                    <?php if ($lastSession['location']): ?>
                    <span class="text-muted ms-2"><i class="fas fa-map-marker-alt"></i> <?= h($lastSession['location']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (empty($workoutSets)): ?>
                <div class="alert alert-warning py-2 mb-0">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    Nejprve vytvořte <a href="<?= BASE_URL ?>/sady.php" class="alert-link">sady</a> a
                    <a href="<?= BASE_URL ?>/exercises.php" class="alert-link">cviky</a>.
                </div>
                <?php else: ?>
                <form action="<?= BASE_URL ?>/training_start.php" method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Vyberte sadu</label>
                        <select name="workout_set_id" class="form-select" required>
                            <option value="">– vyberte sadu –</option>
                            <?php foreach ($workoutSets as $ws): ?>
                            <option value="<?= $ws['id'] ?>">
                                <?= h($ws['name']) ?>
                                (<?= $ws['exercise_count'] ?> <?= $ws['exercise_count'] === 1 ? 'cvik' : ($ws['exercise_count'] < 5 ? 'cviky' : 'cviků') ?>)
                                <?php if (!empty($ws['exercise_names'])): ?>
                                    – <?= h($ws['exercise_names']) ?>
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-warning fw-bold">
                        <i class="fas fa-play me-1"></i>Zahájit trénink
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Historie tréninků -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <span><i class="fas fa-history me-2"></i>Historie tréninků</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($sessions)): ?>
        <div class="text-center py-4 text-muted">
            <i class="fas fa-inbox fa-2x mb-2"></i><br>Zatím žádné tréninky.
        </div>
        <?php else: ?>
        <div class="p-3 border-bottom bg-light">
            <strong>Aktuální měsíc (<?= date('m/Y') ?>)</strong>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle table-sessions">
                <thead class="table-light">
                    <tr>
                        <th>Datum</th>
                        <th>Sada</th>
                        <th>Místo</th>
                        <th class="text-center">Sérií</th>
                        <th>Stav</th>
                        <th class="text-center" title="Fotografie"><i class="fas fa-camera"></i></th>
                        <th class="text-end">Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($currentMonthSessions)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">V aktuálním měsíci zatím nejsou žádné tréninky.</td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($currentMonthSessions as $s): ?>
                    <tr>
                        <td>
                            <strong><?= formatDate($s['started_at']) ?></strong>
                            <br><small class="text-muted"><?= date('H:i', strtotime($s['started_at'])) ?></small>
                        </td>
                        <td><span class="badge bg-secondary fs-6"><?= h($s['set_name']) ?></span></td>
                        <td class="text-muted"><?= $s['location'] ? h($s['location']) : '–' ?></td>
                        <td class="text-center"><?= $s['total_series'] ?></td>
                        <td>
                            <?php if ($s['completed_at']): ?>
                            <span class="badge bg-success">Dokončeno</span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">Probíhá</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if (!empty($s['training_photo'])): ?>
                            <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>#training-photo"
                               title="Zobrazit fotografii">
                                <img src="<?= h(photoUrl($s['training_photo'], 'trainings')) ?>"
                                     alt="foto"
                                     style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:2px solid #ffc107">
                            </a>
                            <?php else: ?>
                            <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if (!$s['completed_at']): ?>
                            <a href="<?= BASE_URL ?>/training_session.php?id=<?= $s['id'] ?>"
                               class="btn btn-warning btn-sm">
                                <i class="fas fa-play me-1"></i>Pokračovat
                            </a>
                            <?php else: ?>
                            <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>"
                               class="btn btn-outline-dark btn-sm">
                                <i class="fas fa-eye me-1"></i>Detail
                            </a>
                            <?php endif; ?>
                            <form method="post" action="<?= BASE_URL ?>/training_delete.php" class="d-inline"
                                  onsubmit="return confirm('Opravdu smazat tento trénink? V administraci půjde obnovit.');">
                                <?= csrfField() ?>
                                <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                                <input type="hidden" name="redirect_to" value="<?= h(BASE_URL . '/athlete_detail.php?id=' . $athleteId) ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Smazat trénink">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($olderSessionsByMonth)): ?>
        <div class="accordion" id="olderMonthsAccordion">
            <?php $monthIdx = 0; ?>
            <?php foreach ($olderSessionsByMonth as $monthKey => $monthSessions): ?>
            <?php
            $monthIdx++;
            $accId = 'month-' . $monthIdx;
            $monthLabel = date('m/Y', strtotime($monthSessions[0]['started_at']));
            ?>
            <div class="accordion-item">
                <h2 class="accordion-header" id="heading-<?= $accId ?>">
                    <button class="accordion-button collapsed" type="button"
                            data-bs-toggle="collapse" data-bs-target="#collapse-<?= $accId ?>"
                            aria-expanded="false" aria-controls="collapse-<?= $accId ?>">
                        <?= h($monthLabel) ?>
                        <span class="badge bg-secondary ms-2"><?= count($monthSessions) ?></span>
                    </button>
                </h2>
                <div id="collapse-<?= $accId ?>" class="accordion-collapse collapse"
                     aria-labelledby="heading-<?= $accId ?>" data-bs-parent="#olderMonthsAccordion">
                    <div class="accordion-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle table-sessions">
                                <tbody>
                                    <?php foreach ($monthSessions as $s): ?>
                                    <tr>
                                        <td>
                                            <strong><?= formatDate($s['started_at']) ?></strong>
                                            <br><small class="text-muted"><?= date('H:i', strtotime($s['started_at'])) ?></small>
                                        </td>
                                        <td><span class="badge bg-secondary fs-6"><?= h($s['set_name']) ?></span></td>
                                        <td class="text-muted"><?= $s['location'] ? h($s['location']) : '–' ?></td>
                                        <td class="text-center"><?= $s['total_series'] ?></td>
                                        <td>
                                            <?php if ($s['completed_at']): ?>
                                            <span class="badge bg-success">Dokončeno</span>
                                            <?php else: ?>
                                            <span class="badge bg-warning text-dark">Probíhá</span>
                                            <?php endif; ?>
                                            <?php if ($s['paired_session_id']): ?>
                                            <span class="badge bg-info text-dark mt-1">
                                                <i class="fas fa-people-group me-1"></i>Párový
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if (!empty($s['training_photo'])): ?>
                                            <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>#training-photo"
                                               title="Zobrazit fotografii">
                                                <img src="<?= h(photoUrl($s['training_photo'], 'trainings')) ?>"
                                                     alt="foto"
                                                     style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:2px solid #ffc107">
                                            <td>
                                                <?php if ($s['completed_at']): ?>
                                                <span class="badge bg-success">Dokončeno</span>
                                                <?php else: ?>
                                                <span class="badge bg-warning text-dark">Probíhá</span>
                                                <?php endif; ?>
                                                <?php if ($s['paired_session_id']): ?>
                                                <span class="badge bg-info text-dark mt-1">
                                                    <i class="fas fa-people-group me-1"></i>Párový
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                            <a href="<?= BASE_URL ?>/training_session.php?id=<?= $s['id'] ?>"
                                               class="btn btn-warning btn-sm">
                                                <i class="fas fa-play me-1"></i>Pokračovat
                                            </a>
                                            <?php else: ?>
                                            <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>"
                                               class="btn btn-outline-dark btn-sm">
                                                <i class="fas fa-eye me-1"></i>Detail
                                            </a>
                                            <?php endif; ?>
                                            <form method="post" action="<?= BASE_URL ?>/training_delete.php" class="d-inline"
                                                  onsubmit="return confirm('Opravdu smazat tento trénink? V administraci půjde obnovit.');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                                                <input type="hidden" name="redirect_to" value="<?= h(BASE_URL . '/athlete_detail.php?id=' . $athleteId) ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Smazat trénink">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php renderFooter(); ?>
