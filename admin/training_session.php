<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId   = getCurrentCoachId();
$sessionId = intParam($_GET, 'id');
$pdo       = getDB();

// Načtení session + ověření, že patří trenérovi
$stmt = $pdo->prepare(
    'SELECT ts.*, a.first_name, a.last_name, a.id AS athlete_id,
            ws.name AS set_name
     FROM training_sessions ts
     JOIN athletes a ON ts.athlete_id = a.id
     JOIN workout_sets ws ON ts.workout_set_id = ws.id
    WHERE ts.id = ? AND a.coach_id = ? AND ts.deleted_by_coach_at IS NULL'
);
$stmt->execute([$sessionId, $coachId]);
$session = $stmt->fetch();

if (!$session) {
    flash('danger', 'Trénink nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

// Pokud je trénink dokončený, přesměruj na detail
if ($session['completed_at']) {
    redirect(BASE_URL . '/training_detail.php?id=' . $sessionId);
}

// Načtení cviků v session snapshotu (fallback pro starší data)
$exercises = getSessionExercises($sessionId, (int)$session['workout_set_id']);

// Načtení existujících sérií pro každý cvik
$seriesByExercise = [];
$lastCompletedByExercise = [];
foreach ($exercises as $ex) {
    $seriesByExercise[$ex['exercise_id']] = getSeriesForExercise($sessionId, $ex['exercise_id']);
    $lastCompletedByExercise[$ex['exercise_id']] = getLastCompletedSeriesForExercise(
        (int)$session['athlete_id'],
        (int)$ex['exercise_id'],
        $sessionId
    );
}

renderHeader('Aktivní trénink');
?>

<div class="d-flex align-items-center mb-3 gap-3 page-header">
    <div>
        <h2 class="mb-0 fw-bold">
            <i class="fas fa-stopwatch me-2 text-warning"></i>
            <?= h($session['first_name'] . ' ' . $session['last_name']) ?>
        </h2>
        <div class="text-muted">
            <span class="badge bg-warning text-dark me-2 fs-6"><?= h($session['set_name']) ?></span>
            Zahájeno: <?= formatDateTime($session['started_at']) ?>
        </div>
    </div>
    <div class="ms-auto">
        <button class="btn btn-success btn-lg fw-bold training-finish-btn" data-bs-toggle="modal" data-bs-target="#completeModal">
            <i class="fas fa-flag-checkered me-2"></i>Ukončit trénink
        </button>
    </div>
</div>

<?php if (empty($exercises)): ?>
<div class="alert alert-warning">Sada neobsahuje žádné cviky.</div>
<?php else: ?>

<!-- Cviky -->
<?php foreach ($exercises as $idx => $ex): ?>
<?php $series = $seriesByExercise[$ex['exercise_id']] ?? []; ?>
<div class="card border-0 shadow-sm mb-4" id="exercise-card-<?= $ex['exercise_id'] ?>">
    <div class="card-header d-flex align-items-center bg-dark text-white">
        <span class="badge bg-warning text-dark me-2 fs-5"><?= $ex['exercise_order'] ?></span>
        <span class="fw-bold fs-5"><?= h($ex['exercise_name']) ?></span>
        <?php $lastCompleted = $lastCompletedByExercise[$ex['exercise_id']] ?? null; ?>
        <span class="ms-auto badge bg-secondary" id="series-count-<?= $ex['exercise_id'] ?>">
            <?= count($series) ?> séri<?= count($series) === 1 ? 'e' : 'í' ?>
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered mb-0 align-middle text-center" id="series-table-<?= $ex['exercise_id'] ?>">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px">#</th>
                        <th>Váha&nbsp;<small class="text-muted">(kg)</small></th>
                        <th>Opakování</th>
                        <th>Dopomoc</th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody id="series-body-<?= $ex['exercise_id'] ?>">
                    <?php foreach ($series as $s): ?>
                    <tr id="series-row-<?= $s['id'] ?>">
                        <td class="fw-bold text-muted"><?= $s['series_order'] ?></td>
                        <td class="fw-bold"><?= $s['weight'] > 0 ? number_format($s['weight'], 1, ',', '') : '–' ?></td>
                        <td><?= $s['reps'] ?: '–' ?></td>
                        <td>
                            <?php if ($s['assistance_reps'] > 0): ?>
                            <span class="badge bg-warning text-dark"><?= $s['assistance_reps'] ?></span>
                            <?php else: ?>
                            –
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-outline-danger btn-sm"
                                    onclick="deleteSeries(<?= $s['id'] ?>, <?= $ex['exercise_id'] ?>)"
                                    title="Smazat sérii">
                                <i class="fas fa-times"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="p-3 border-top bg-light">
            <?php if ($lastCompleted): ?>
            <div class="previous-exercise-session mb-3">
                <div class="previous-exercise-session__head">
                    <div class="previous-exercise-session__title">
                        <i class="fas fa-history me-2"></i>
                        Poslední dokončený trénink tohoto cviku
                    </div>
                    <div class="small text-muted">
                        <?= formatDateTime($lastCompleted['session']['completed_at']) ?>
                        <span class="mx-1">|</span>
                        <?= h($lastCompleted['session']['set_name']) ?>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0 align-middle text-center previous-exercise-session__table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Váha (kg)</th>
                                <th>Opakování</th>
                                <th>Dopomoc</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lastCompleted['series'] as $prev): ?>
                            <tr>
                                <td class="fw-bold text-muted"><?= (int)$prev['series_order'] ?></td>
                                <td><?= number_format((float)$prev['weight'], 1, ',', '') ?></td>
                                <td><?= (int)$prev['reps'] ?></td>
                                <td><?= (int)$prev['assistance_reps'] > 0 ? (int)$prev['assistance_reps'] : '–' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            <!-- Formulář pro přidání série (inline) -->
            <div class="add-series-row" id="add-series-form-<?= $ex['exercise_id'] ?>">
                <div>
                    <label class="form-label small fw-semibold mb-1">Váha (kg)</label>
                    <input type="number" step="0.5" min="0" max="999"
                           class="form-control form-control-sm series-weight"
                           id="weight-<?= $ex['exercise_id'] ?>"
                           placeholder="80" style="width:90px">
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1">Opakování</label>
                    <input type="number" step="1" min="0" max="999"
                           class="form-control form-control-sm series-reps"
                           id="reps-<?= $ex['exercise_id'] ?>"
                           placeholder="10" style="width:90px">
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1">Dopomoc</label>
                    <input type="number" step="1" min="0" max="999"
                           class="form-control form-control-sm series-assist"
                           id="assist-<?= $ex['exercise_id'] ?>"
                           placeholder="0" style="width:80px">
                </div>
                <div class="mb-0" style="padding-top:22px">
                    <button type="button"
                            class="btn btn-warning fw-bold"
                            onclick="addSeries(<?= $ex['exercise_id'] ?>, <?= $sessionId ?>)">
                        <i class="fas fa-plus me-1"></i>Přidat sérii
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Tlačítko ukončit trénink dole -->
<div class="text-center my-4">
    <button class="btn btn-success btn-lg fw-bold px-5"
            data-bs-toggle="modal" data-bs-target="#completeModal">
        <i class="fas fa-flag-checkered me-2"></i>Ukončit trénink
    </button>
</div>

<?php endif; ?>

<!-- Modal: Ukončit trénink -->
<div class="modal fade" id="completeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= BASE_URL ?>/training_complete.php" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-flag-checkered me-2"></i>Ukončit trénink
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Chcete ukončit trénink?</p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-map-marker-alt me-1"></i>Místo tréninku
                            <small class="text-muted">(volitelné)</small>
                        </label>
                        <input type="text" name="location" class="form-control"
                               placeholder="např. FitStudio Praha, Home gym...">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Poznámka <small class="text-muted">(volitelné)</small></label>
                        <textarea name="notes" class="form-control" rows="2"
                                  placeholder="Celkové hodnocení tréninku..."></textarea>
                    </div>
                    <div class="mb-2 mt-3">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-camera me-1"></i>Fotografie z tréninku
                            <small class="text-muted">(volitelné)</small>
                        </label>
                        <input type="file"
                               name="training_photo"
                               class="form-control"
                               accept="image/*"
                               capture="environment"
                               onchange="previewTrainingPhoto(this)">
                        <div class="form-text">
                            Mobil/tablet nabídne fotoaparát, na počítači výběr souboru. Podporováno JPG, PNG, GIF, WEBP (max 8 MB).
                        </div>
                        <img id="training-photo-preview" alt="Náhled fotky"
                             class="img-fluid rounded border mt-2 d-none"
                             style="max-height:220px; object-fit:cover;">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-arrow-left me-1"></i>Zpět k tréninku
                    </button>
                    <button type="submit" class="btn btn-success fw-bold">
                        <i class="fas fa-check me-1"></i>Uložit a ukončit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Přidání série přes AJAX
async function addSeries(exerciseId, sessionId) {
    const weight  = parseFloat(document.getElementById('weight-' + exerciseId).value) || 0;
    const reps    = parseInt(document.getElementById('reps-' + exerciseId).value)    || 0;
    const assist  = parseInt(document.getElementById('assist-' + exerciseId).value)  || 0;

    const tbody   = document.getElementById('series-body-' + exerciseId);
    const rowCount = tbody.querySelectorAll('tr').length;

    const btn = event.currentTarget;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Ukládám...';

    try {
        const resp = await fetch('<?= BASE_URL ?>/api/save_series.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                session_id:      sessionId,
                exercise_id:     exerciseId,
                series_order:    rowCount + 1,
                weight:          weight,
                reps:            reps,
                assistance_reps: assist
            })
        });
        const data = await resp.json();
        if (data.success) {
            // Přidej řádek do tabulky
            const tr = document.createElement('tr');
            tr.id = 'series-row-' + data.id;
            tr.innerHTML = `
                <td class="fw-bold text-muted">${rowCount + 1}</td>
                <td class="fw-bold">${weight > 0 ? weight.toFixed(1).replace('.', ',') : '–'}</td>
                <td>${reps || '–'}</td>
                <td>${assist > 0 ? '<span class="badge bg-warning text-dark">' + assist + '</span>' : '–'}</td>
                <td>
                    <button class="btn btn-outline-danger btn-sm"
                            onclick="deleteSeries(${data.id}, ${exerciseId})"
                            title="Smazat sérii">
                        <i class="fas fa-times"></i>
                    </button>
                </td>`;
            tbody.appendChild(tr);

            // Reset formuláře
            document.getElementById('weight-' + exerciseId).value  = '';
            document.getElementById('reps-' + exerciseId).value    = '';
            document.getElementById('assist-' + exerciseId).value  = '';
            document.getElementById('weight-' + exerciseId).focus();

            // Aktualizuj počítadlo
            updateSeriesCount(exerciseId);
        } else {
            alert('Chyba při ukládání: ' + (data.error || 'Neznámá chyba'));
        }
    } catch (e) {
        alert('Chyba připojení k serveru.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plus me-1"></i>Přidat sérii';
    }
}

// Smazání série přes AJAX
async function deleteSeries(seriesId, exerciseId) {
    if (!confirm('Smazat tuto sérii?')) return;

    try {
        const resp = await fetch('<?= BASE_URL ?>/api/delete_series.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({series_id: seriesId})
        });
        const data = await resp.json();
        if (data.success) {
            document.getElementById('series-row-' + seriesId)?.remove();
            renumberSeries(exerciseId);
            updateSeriesCount(exerciseId);
        } else {
            alert('Chyba při mazání: ' + (data.error || 'Neznámá chyba'));
        }
    } catch (e) {
        alert('Chyba připojení k serveru.');
    }
}

// Přečíslování sérií po smazání
function renumberSeries(exerciseId) {
    const rows = document.querySelectorAll('#series-body-' + exerciseId + ' tr');
    rows.forEach((row, i) => {
        row.cells[0].textContent = i + 1;
    });
}

// Aktualizace počítadla sérií v hlavičce
function updateSeriesCount(exerciseId) {
    const count = document.querySelectorAll('#series-body-' + exerciseId + ' tr').length;
    const badge = document.getElementById('series-count-' + exerciseId);
    if (badge) {
        badge.textContent = count + ' séri' + (count === 1 ? 'e' : 'í');
    }
}

function previewTrainingPhoto(input) {
    const preview = document.getElementById('training-photo-preview');
    const file = input.files && input.files[0] ? input.files[0] : null;
    if (!preview || !file) {
        if (preview) {
            preview.classList.add('d-none');
            preview.removeAttribute('src');
        }
        return;
    }

    // HEIC a jiné formáty nepodporované prohlížečem nelze zobrazit jako náhled
    const previewable = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!previewable.includes(file.type.toLowerCase())) {
        preview.classList.add('d-none');
        preview.removeAttribute('src');
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        preview.src = e.target.result;
        preview.classList.remove('d-none');
    };
    reader.readAsDataURL(file);
}

// Klávesa Enter v poli dopomoci = přidá sérii
document.querySelectorAll('.series-assist').forEach(function(el) {
    el.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const exerciseId = this.id.replace('assist-', '');
            addSeries(parseInt(exerciseId), <?= $sessionId ?>);
        }
    });
});
</script>

<?php renderFooter(); ?>
