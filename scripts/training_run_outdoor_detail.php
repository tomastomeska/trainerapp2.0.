<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$pdo = getDB();
$sessionId = intParam($_GET, 'id', 0);

if ($sessionId <= 0) {
    flash('danger', 'Session nenalezena.');
    redirect(BASE_URL . '/dashboard.php');
}

$stmt = $pdo->prepare(
    'SELECT ts.*, a.first_name, a.last_name, a.id AS athlete_id, ws.name AS set_name
     FROM training_sessions ts
     JOIN athletes a ON a.id = ts.athlete_id
     JOIN workout_sets ws ON ws.id = ts.workout_set_id
     WHERE ts.id = ? AND a.coach_id = ? AND ts.deleted_by_coach_at IS NULL'
);
$stmt->execute([$sessionId, $coachId]);
$session = $stmt->fetch();

if (!$session) {
    flash('danger', 'Session nenalezena.');
    redirect(BASE_URL . '/dashboard.php');
}

$trainingVenues = getTrainingVenues();

$runOutdoor = getRunOutdoorSessionByTrainingSession($sessionId);
if (!$runOutdoor) {
    createRunOutdoorSession($sessionId);
    $runOutdoor = getRunOutdoorSessionByTrainingSession($sessionId);
}
$outdoorPaceSeconds = 0;
if ((int)$runOutdoor['duration_seconds'] > 0 && (float)$runOutdoor['distance_km'] > 0) {
    $outdoorPaceSeconds = (int)round((int)$runOutdoor['duration_seconds'] / (float)$runOutdoor['distance_km']);
}
$outdoorPaceMinutesValue = $outdoorPaceSeconds > 0 ? intdiv($outdoorPaceSeconds, 60) : 0;
$outdoorPaceSecondsValue = $outdoorPaceSeconds > 0 ? $outdoorPaceSeconds % 60 : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/training_run_outdoor_detail.php?id=' . $sessionId);
    }

    $durationSeconds = intParam($_POST, 'duration_minutes', 0) * 60 + intParam($_POST, 'duration_seconds', 0);
    $paceSeconds = intParam($_POST, 'pace_minutes', 0) * 60 + intParam($_POST, 'pace_seconds', 0);
    $distanceKm = $paceSeconds > 0 ? round($durationSeconds / $paceSeconds, 2) : 0;
    $surface = (string)($_POST['surface'] ?? 'asphalt');
    $location = normalizeTrainingVenueName($_POST['location'] ?? '');
    $weather = trim((string)($_POST['weather'] ?? ''));

    $allowedSurfaces = ['asphalt', 'trail', 'mixed'];
    if (!in_array($surface, $allowedSurfaces, true)) {
        $surface = 'asphalt';
    }

    $caloriesBurned = $_POST['calories_burned'] !== '' ? (int)$_POST['calories_burned'] : null;
    $splitKm = $_POST['split_km'] ?? [];
    $splitTime = $_POST['split_time'] ?? [];
    $splitPace = $_POST['split_pace'] ?? [];

    if ($durationSeconds <= 0 || $paceSeconds <= 0) {
        flash('danger', 'Vyplňte dobu běhu a průměrné tempo.');
        redirect(BASE_URL . '/training_run_outdoor_detail.php?id=' . $sessionId);
    }

    if ($location !== '') {
        rememberTrainingVenue($location, $coachId);
    }

        updateRunOutdoorSession(
            (int)$runOutdoor['id'],
            $durationSeconds,
            $distanceKm,
            'free',
            $surface,
            $weather !== '' ? $weather : null,
            null,
            $caloriesBurned,
            null,
            null,
            null,
            null
        );

    $pdo->prepare('UPDATE training_sessions SET location = ? WHERE id = ?')
        ->execute([$location !== '' ? $location : null, $sessionId]);

    $splits = [];
    $rows = max(count($splitKm), count($splitTime));
    for ($i = 0; $i < $rows; $i++) {
        $km = isset($splitKm[$i]) && $splitKm[$i] !== '' ? (float)$splitKm[$i] : 0;
        $time = trim((string)($splitTime[$i] ?? ''));
        $pace = trim((string)($splitPace[$i] ?? ''));

        if ($km <= 0 || $time === '') {
            continue;
        }

        if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            continue;
        }

        if ($pace !== '' && !preg_match('/^\d{1,2}:\d{2}$/', $pace)) {
            $pace = null;
        }

        $splits[] = [
            'km_marker' => $km,
            'split_time' => $time,
            'pace' => $pace ?: null,
        ];
    }

    saveRunOutdoorSplits((int)$runOutdoor['id'], $splits);

    flash('success', 'Běh venku byl uložen.');
    redirect(BASE_URL . '/training_run_outdoor_detail.php?id=' . $sessionId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/training_run_outdoor_detail.php?id=' . $sessionId);
    }

    $pdo->prepare('UPDATE training_sessions SET completed_at = NOW() WHERE id = ?')
        ->execute([$sessionId]);

    flash('success', 'Běh venku byl ukončen.');
    redirect(BASE_URL . '/athlete_detail.php?id=' . $session['athlete_id']);
}

$runOutdoor = getRunOutdoorSessionByTrainingSession($sessionId);
$splits = getRunOutdoorSplits((int)$runOutdoor['id']);
$history = getRunOutdoorHistory((int)$session['athlete_id'], 5);
$stats = calculateRunOutdoorStats((int)$session['athlete_id'], 30);

renderHeader('Běh venku - detail');
?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-success text-white fw-bold">
                <i class="fas fa-person-hiking me-2"></i>Běh venku
            </div>
            <div class="card-body">
                <div class="run-form-topbar mb-4">
                    <div>
                        <div class="text-uppercase text-muted small fw-semibold">Záznam běhu</div>
                        <div class="fs-5 fw-bold"><?= h($session['first_name']) ?> <?= h($session['last_name']) ?></div>
                        <div class="text-muted small">Sada: <?= h($session['set_name']) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="badge bg-success text-white px-3 py-2">Outdoor detail</div>
                        <div class="small text-muted mt-2">Vyplň čas, tempo a ulož</div>
                    </div>
                </div>

                <form method="post" novalidate id="run-outdoor-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save">

                    <div class="card border-0 shadow-sm mb-3 run-section-card">
                        <div class="card-header bg-white border-0 pb-0">
                            <div class="run-section-kicker">Běh</div>
                            <h5 class="mb-0">Čas a tempo</h5>
                        </div>
                        <div class="card-body pt-3">
                            <div class="row g-3">
                                <div class="col-6 col-md-2">
                                    <label class="form-label fw-semibold">Doba min</label>
                                    <input type="number" class="form-control" name="duration_minutes"
                                           min="0" value="<?= intdiv((int)$runOutdoor['duration_seconds'], 60) ?>" required>
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label fw-semibold">Doba sek</label>
                                    <input type="number" class="form-control" name="duration_seconds"
                                           min="0" max="59" value="<?= ((int)$runOutdoor['duration_seconds']) % 60 ?>" required>
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label fw-semibold">Tempo min/km</label>
                                    <input type="number" class="form-control" name="pace_minutes" min="0" value="<?= $outdoorPaceMinutesValue ?>" required>
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label fw-semibold">Tempo sek</label>
                                    <input type="number" class="form-control" name="pace_seconds" min="0" max="59" value="<?= $outdoorPaceSecondsValue ?>" required>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-semibold">Kalorie</label>
                                    <input type="number" class="form-control" name="calories_burned" min="0"
                                           value="<?= h((string)($runOutdoor['calories_burned'] ?? '')) ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mb-3 run-section-card">
                        <div class="card-header bg-white border-0 pb-0">
                            <div class="run-section-kicker">Detaily</div>
                            <h5 class="mb-0">Místo, povrch a počasí</h5>
                        </div>
                        <div class="card-body pt-3">
                            <div class="row g-3">
                                <div class="col-12 col-lg-5">
                                    <label class="form-label fw-semibold">Místo</label>
                                    <?php
                                    $currentOutdoorLocation = (string)($session['location'] ?? '');
                                    $knownVenueNames = array_map(static fn(array $venue): string => (string)$venue['name'], $trainingVenues);
                                    $isCustomOutdoorLocation = $currentOutdoorLocation !== '' && !in_array($currentOutdoorLocation, $knownVenueNames, true);
                                    ?>
                                    <select class="form-select mb-2" id="run-outdoor-location-select">
                                        <option value="">- Bez místa -</option>
                                        <?php foreach ($trainingVenues as $venue): ?>
                                        <?php $venueName = (string)$venue['name']; ?>
                                        <option value="<?= h($venueName) ?>" <?= $venueName === $currentOutdoorLocation ? 'selected' : '' ?>>
                                            <?= h($venueName) ?><?= !empty($venue['address']) ? ' - ' . h((string)$venue['address']) : '' ?>
                                        </option>
                                        <?php endforeach; ?>
                                        <option value="__custom__" <?= $isCustomOutdoorLocation ? 'selected' : '' ?>>Jiné místo (zadat ručně)</option>
                                    </select>
                                    <input type="text" class="form-control" name="location"
                                           id="run-outdoor-location-input"
                                           value="<?= h($currentOutdoorLocation) ?>"
                                           placeholder="Napište nové místo..."
                                           <?= $isCustomOutdoorLocation ? '' : 'readonly' ?>>
                                </div>
                                <div class="col-6 col-lg-3">
                                    <label class="form-label fw-semibold">Povrch</label>
                                    <select class="form-select" name="surface">
                                        <option value="asphalt" <?= ($runOutdoor['surface'] ?? 'asphalt') === 'asphalt' ? 'selected' : '' ?>>Asfalt</option>
                                        <option value="trail" <?= ($runOutdoor['surface'] ?? '') === 'trail' ? 'selected' : '' ?>>Terén</option>
                                        <option value="mixed" <?= ($runOutdoor['surface'] ?? '') === 'mixed' ? 'selected' : '' ?>>Mix</option>
                                    </select>
                                </div>
                                <div class="col-6 col-lg-4">
                                    <label class="form-label fw-semibold">Počasí</label>
                                    <input type="text" class="form-control" name="weather"
                                           value="<?= h((string)($runOutdoor['weather'] ?? '')) ?>"
                                           placeholder="slunečno, déšť, vítr...">
                                </div>
                                <div class="col-12">
                                    <div class="run-inline-note">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Vzdálenost se dopočítá z času a průměrného tempa.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h5 class="mb-0">Splity</h5>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addSplitRow()">
                            <i class="fas fa-plus me-1"></i>Přidat split
                        </button>
                    </div>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered align-middle" id="splits-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Km</th>
                                    <th>Čas splitu (mm:ss)</th>
                                    <th>Tempo (mm:ss)</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($splits)): ?>
                                <tr>
                                    <td><input type="number" step="0.01" min="0" name="split_km[]" class="form-control form-control-sm"></td>
                                    <td><input type="text" name="split_time[]" class="form-control form-control-sm" placeholder="05:15"></td>
                                    <td><input type="text" name="split_pace[]" class="form-control form-control-sm" placeholder="05:15"></td>
                                    <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeSplitRow(this)"><i class="fas fa-times"></i></button></td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($splits as $split): ?>
                                <tr>
                                    <td><input type="number" step="0.01" min="0" name="split_km[]" class="form-control form-control-sm" value="<?= h((string)$split['km_marker']) ?>"></td>
                                    <td><input type="text" name="split_time[]" class="form-control form-control-sm" value="<?= h($split['split_time']) ?>"></td>
                                    <td><input type="text" name="split_pace[]" class="form-control form-control-sm" value="<?= h((string)($split['pace'] ?? '')) ?>"></td>
                                    <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeSplitRow(this)"><i class="fas fa-times"></i></button></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex flex-column flex-sm-row gap-2 align-items-stretch align-items-sm-center">
                        <button type="submit" class="btn btn-success fw-bold">
                            <i class="fas fa-save me-1"></i>Uložit běh
                        </button>
                        <a href="<?= BASE_URL ?>/training_session.php?id=<?= $sessionId ?>" class="btn btn-outline-secondary">Zpět</a>
                        <small id="run-outdoor-autosave-status" class="text-muted ms-0 ms-sm-2 align-self-center">Automatické ukládání zapnuto</small>
                    </div>
                </form>

                <hr>

                <form method="post" class="mt-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="complete">
                    <button type="submit" class="btn btn-primary fw-bold"
                            onclick="return confirm('Chcete ukončit tento běh venku?')">
                        <i class="fas fa-flag-checkered me-1"></i>Ukončit trénink
                    </button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-dark text-white fw-bold">
                <i class="fas fa-history me-2"></i>Poslední běhy venku
            </div>
            <div class="card-body p-0">
                <?php if (empty($history)): ?>
                <div class="text-center py-4 text-muted">Žádná historie.</div>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Datum</th>
                            <th>Vzdálenost</th>
                            <th>Čas</th>
                            <th>Tempo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $run): ?>
                        <tr>
                            <td><?= formatDate($run['completed_at'] ?? $run['ts_started_at']) ?></td>
                            <td><?= number_format((float)$run['distance_km'], 2, ',', ' ') ?> km</td>
                            <td><?= gmdate('H:i:s', (int)$run['duration_seconds']) ?></td>
                            <td><?= h((string)($run['avg_pace'] ?? '–')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-info text-white fw-bold">
                <i class="fas fa-chart-bar me-2"></i>Statistiky (30 dní)
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <div class="run-stat-card">
                            <strong><?= (int)$stats['total_runs'] ?></strong><br>
                            <small class="text-muted">Běhů</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="run-stat-card">
                            <strong><?= number_format((float)$stats['total_km'], 2, ',', ' ') ?> km</strong><br>
                            <small class="text-muted">Celkem</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="run-stat-card">
                            <strong><?= gmdate('H:i:s', (int)$stats['total_seconds']) ?></strong><br>
                            <small class="text-muted">Celkový čas</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="run-stat-card">
                            <strong><?= (int)$stats['total_calories'] ?> kcal</strong><br>
                            <small class="text-muted">Kalorie</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function addSplitRow() {
    const tbody = document.querySelector('#splits-table tbody');
    const tr = document.createElement('tr');
    tr.innerHTML =
        '<td><input type="number" step="0.01" min="0" name="split_km[]" class="form-control form-control-sm"></td>' +
        '<td><input type="text" name="split_time[]" class="form-control form-control-sm" placeholder="05:15"></td>' +
        '<td><input type="text" name="split_pace[]" class="form-control form-control-sm" placeholder="05:15"></td>' +
        '<td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeSplitRow(this)"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);

    if (window.__runOutdoorAutosave && typeof window.__runOutdoorAutosave.scheduleSave === 'function') {
        window.__runOutdoorAutosave.scheduleSave();
    }
}

function removeSplitRow(btn) {
    const row = btn.closest('tr');
    const tbody = document.querySelector('#splits-table tbody');
    if (tbody.querySelectorAll('tr').length > 1) {
        row.remove();
        if (window.__runOutdoorAutosave && typeof window.__runOutdoorAutosave.scheduleSave === 'function') {
            window.__runOutdoorAutosave.scheduleSave();
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('run-outdoor-form');
    const statusEl = document.getElementById('run-outdoor-autosave-status');
    const locationSelect = document.getElementById('run-outdoor-location-select');
    const locationInput = document.getElementById('run-outdoor-location-input');

    if (locationSelect && locationInput) {
        const syncLocationInput = function() {
            const value = locationSelect.value;
            if (value === '__custom__') {
                locationInput.readOnly = false;
                locationInput.focus();
                return;
            }

            locationInput.readOnly = true;
            locationInput.value = value;
        };

        locationSelect.addEventListener('change', syncLocationInput);
        syncLocationInput();
    }

    if (!form || !window.createSportAutosave) {
        return;
    }

    const autosave = window.createSportAutosave({
        form: form,
        statusEl: statusEl,
        endpoint: '<?= BASE_URL ?>/api/save_run_outdoor_draft.php',
        debounceMs: 700,
        buildPayload: function() {
            const splitKm = form.querySelectorAll('[name="split_km[]"]');
            const splitTime = form.querySelectorAll('[name="split_time[]"]');
            const splitPace = form.querySelectorAll('[name="split_pace[]"]');
            const splits = [];

            for (let i = 0; i < splitKm.length; i++) {
                splits.push({
                    km_marker: splitKm[i]?.value || '',
                    split_time: splitTime[i]?.value || '',
                    pace: splitPace[i]?.value || '',
                });
            }

            return {
                session_id: <?= (int)$sessionId ?>,
                duration_minutes: form.querySelector('[name="duration_minutes"]').value || '0',
                duration_seconds: form.querySelector('[name="duration_seconds"]').value || '0',
                pace_minutes: form.querySelector('[name="pace_minutes"]').value || '0',
                pace_seconds: form.querySelector('[name="pace_seconds"]').value || '0',
                location: form.querySelector('[name="location"]').value || '',
                surface: form.querySelector('[name="surface"]').value || 'asphalt',
                calories_burned: form.querySelector('[name="calories_burned"]').value || '',
                weather: form.querySelector('[name="weather"]').value || '',
                splits: splits
            };
        }
    });

    window.__runOutdoorAutosave = autosave;

    form.addEventListener('submit', function() {
        if (autosave && typeof autosave.saveNow === 'function') {
            autosave.saveNow();
        }
    });
});
</script>

<?php renderFooter(); ?>
