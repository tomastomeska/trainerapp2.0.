<?php
// training_run_treadmill_detail.php – Detail a editace běhu na páse
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$pdo = getDB();
$trainingVenues = getTrainingVenues();

$sessionId = intParam($_GET, 'id', 0);
if ($sessionId === 0) {
    flash('danger', 'Session nenalezena.');
    redirect(BASE_URL . '/dashboard.php');
}

$stmt = $pdo->prepare(
    'SELECT ts.*, a.first_name, a.last_name, ws.name as set_name, a.id as athlete_id
     FROM training_sessions ts
     JOIN athletes a ON a.id = ts.athlete_id
     JOIN workout_sets ws ON ws.id = ts.workout_set_id
     WHERE ts.id = ? AND a.coach_id = ?'
);
$stmt->execute([$sessionId, $coachId]);
$session = $stmt->fetch();

if (!$session) {
    flash('danger', 'Session nenalezena.');
    redirect(BASE_URL . '/dashboard.php');
}

$runTreadmill = getRunTreadmillSessionByTrainingSession($sessionId);
if (!$runTreadmill) {
    createRunTreadmillSession($sessionId, 0, 0);
    $runTreadmill = getRunTreadmillSessionByTrainingSession($sessionId);
}
$runTreadmillSplits = getRunTreadmillSplits((int)$runTreadmill['id']);
$treadmillPaceSeconds = 0;
if ((int)$runTreadmill['duration_seconds'] > 0 && (float)$runTreadmill['distance_km'] > 0) {
    $treadmillPaceSeconds = (int)round((int)$runTreadmill['duration_seconds'] / (float)$runTreadmill['distance_km']);
}
$paceMinutesValue = $treadmillPaceSeconds > 0 ? intdiv($treadmillPaceSeconds, 60) : 0;
$paceSecondsValue = $treadmillPaceSeconds > 0 ? $treadmillPaceSeconds % 60 : 0;
$paceDisplay = $treadmillPaceSeconds > 0
    ? sprintf('%d:%02d / km', intdiv($treadmillPaceSeconds, 60), $treadmillPaceSeconds % 60)
    : '–';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/training_run_treadmill_detail.php?id=' . $sessionId);
    }

    $durationSeconds = intParam($_POST, 'duration_minutes', 0) * 60 + intParam($_POST, 'duration_seconds', 0);
    $paceInputSeconds = intParam($_POST, 'pace_minutes', 0) * 60 + intParam($_POST, 'pace_seconds', 0);
    $distanceKm = $paceInputSeconds > 0
        ? round($durationSeconds / $paceInputSeconds, 2)
        : 0;
    $caloriesBurned = $_POST['calories_burned'] !== '' ? (int)$_POST['calories_burned'] : null;
    $location = normalizeTrainingVenueName($_POST['location'] ?? '');
    $splitKm = $_POST['split_km'] ?? [];
    $splitTime = $_POST['split_time'] ?? [];
    $splitPace = $_POST['split_pace'] ?? [];

    if ($durationSeconds <= 0 || $paceInputSeconds <= 0) {
        flash('danger', 'Vyplňte dobu běhu a průměrné tempo.');
        redirect(BASE_URL . '/training_run_treadmill_detail.php?id=' . $sessionId);
    }

    if ($location !== '') {
        rememberTrainingVenue($location, $coachId);
    }

    updateRunTreadmillSession(
        (int)$runTreadmill['id'],
        $durationSeconds,
        $distanceKm,
        $caloriesBurned,
        $location !== '' ? $location : null,
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

    saveRunTreadmillSplits((int)$runTreadmill['id'], $splits);

    flash('success', 'Běh na páse byl uložen.');
    redirect(BASE_URL . '/training_run_treadmill_detail.php?id=' . $sessionId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/training_run_treadmill_detail.php?id=' . $sessionId);
    }

    $pdo->prepare('UPDATE training_sessions SET completed_at = NOW() WHERE id = ?')
        ->execute([$sessionId]);

    flash('success', 'Běh na páse byl ukončen.');
    redirect(BASE_URL . '/athlete_detail.php?id=' . $session['athlete_id']);
}

renderHeader('Běh na páse – Detail');
?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-primary text-white fw-bold">
                <i class="fas fa-running me-2"></i>Běh na páse
            </div>
            <div class="card-body">
                <div class="run-form-topbar mb-4">
                    <div>
                        <div class="text-uppercase text-muted small fw-semibold">Záznam běhu</div>
                        <div class="fs-5 fw-bold"><?= h($session['first_name']) ?> <?= h($session['last_name']) ?></div>
                        <div class="text-muted small">Sada: <?= h($session['set_name']) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="badge bg-primary text-white px-3 py-2">Treadmill detail</div>
                        <div class="small text-muted mt-2">Vyplň čas, tempo a ulož</div>
                    </div>
                </div>

                <form method="post" novalidate id="treadmill-form" class="treadmill-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save">

                    <div class="card border-0 shadow-sm mb-3 run-section-card">
                        <div class="card-header bg-white border-0 pb-0">
                            <div class="run-section-kicker">Běh</div>
                            <h5 class="mb-0">Čas a tempo</h5>
                        </div>
                        <div class="card-body pt-3">
                            <div class="row g-3">
                                <div class="col-6 col-md-3">
                                    <label class="form-label fw-semibold">Doba min</label>
                                    <input type="number" name="duration_minutes" class="form-control form-control-lg"
                                           value="<?= intval($runTreadmill['duration_seconds'] / 60) ?>"
                                           min="0" required>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label fw-semibold">Doba sek</label>
                                    <input type="number" name="duration_seconds" class="form-control form-control-lg"
                                           value="<?= $runTreadmill['duration_seconds'] % 60 ?>"
                                           min="0" max="59">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label fw-semibold">Tempo min/km</label>
                                    <input type="number" name="pace_minutes" class="form-control form-control-lg"
                                           value="<?= $paceMinutesValue ?>"
                                           min="0" required>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label fw-semibold">Tempo sek</label>
                                    <input type="number" name="pace_seconds" class="form-control form-control-lg"
                                           value="<?= $paceSecondsValue ?>"
                                           min="0" max="59" required>
                                </div>
                                <div class="col-12">
                                    <div class="run-inline-note">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Vzdálenost dopočítáme automaticky z času a tempa.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mb-3 run-section-card">
                        <div class="card-header bg-white border-0 pb-0">
                            <div class="run-section-kicker">Detaily</div>
                            <h5 class="mb-0">Kalorie, místo a splity</h5>
                        </div>
                        <div class="card-body pt-3">
                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-semibold">Spálené kalorie <span class="text-muted fw-normal">(nepovinné)</span></label>
                                    <input type="number" name="calories_burned" class="form-control"
                                           value="<?= h($runTreadmill['calories_burned'] ?? '') ?>"
                                           min="0">
                                </div>
                                <div class="col-12 col-md-8">
                                    <label class="form-label fw-semibold">Místo <span class="text-muted fw-normal">(nepovinné)</span></label>
                                    <?php
                                    $currentTreadmillLocation = (string)($runTreadmill['location'] ?? $session['location'] ?? '');
                                    $knownVenueNames = array_map(static fn(array $venue): string => (string)$venue['name'], $trainingVenues);
                                    $isCustomTreadmillLocation = $currentTreadmillLocation !== '' && !in_array($currentTreadmillLocation, $knownVenueNames, true);
                                    ?>
                                    <select class="form-select mb-2" id="treadmill-location-select">
                                        <option value="">- Bez místa -</option>
                                        <?php foreach ($trainingVenues as $venue): ?>
                                        <?php $venueName = (string)$venue['name']; ?>
                                        <option value="<?= h($venueName) ?>" <?= $venueName === $currentTreadmillLocation ? 'selected' : '' ?>>
                                            <?= h($venueName) ?><?= !empty($venue['address']) ? ' - ' . h((string)$venue['address']) : '' ?>
                                        </option>
                                        <?php endforeach; ?>
                                        <option value="__custom__" <?= $isCustomTreadmillLocation ? 'selected' : '' ?>>Jiné místo (zadat ručně)</option>
                                    </select>
                                    <input type="text" name="location" class="form-control"
                                           id="treadmill-location-input"
                                           value="<?= h($currentTreadmillLocation) ?>"
                                           placeholder="Napište nové místo..."
                                           <?= $isCustomTreadmillLocation ? '' : 'readonly' ?>>
                                </div>
                            </div>

                            <hr class="my-4">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <h5 class="mb-0">Splity</h5>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addSplitRow()">
                                    <i class="fas fa-plus me-1"></i>Přidat split
                                </button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle" id="splits-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Km</th>
                                            <th>Čas splitu</th>
                                            <th>Tempo</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($runTreadmillSplits)): ?>
                                        <tr>
                                            <td><input type="number" step="0.01" min="0" name="split_km[]" class="form-control form-control-sm"></td>
                                            <td><input type="text" name="split_time[]" class="form-control form-control-sm" placeholder="05:15"></td>
                                            <td><input type="text" name="split_pace[]" class="form-control form-control-sm" placeholder="05:15"></td>
                                            <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeSplitRow(this)"><i class="fas fa-times"></i></button></td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($runTreadmillSplits as $split): ?>
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
                            <div class="form-text mt-2">Splity jsou volitelné. Stačí vyplnit kilometr, čas a případně tempo.</div>
                        </div>
                    </div>

                    <div class="d-flex flex-column flex-sm-row gap-2 align-items-stretch align-items-sm-center">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold">
                            <i class="fas fa-save me-1"></i>Uložit běh
                        </button>
                        <a href="<?= BASE_URL ?>/training_session.php?id=<?= $sessionId ?>" class="btn btn-outline-secondary btn-lg">
                            Zpět
                        </a>
                        <small id="treadmill-autosave-status" class="text-muted ms-0 ms-sm-2 align-self-center">Automatické ukládání zapnuto</small>
                    </div>
                </form>

                <form method="post" class="mt-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="complete">
                    <button type="submit" class="btn btn-success fw-bold"
                            onclick="return confirm('Chcete ukončit tento běh na páse?')">
                        <i class="fas fa-flag-checkered me-1"></i>Ukončit trénink
                    </button>
                </form>

                <hr>

                <h5 class="mb-3">Statistika běhu</h5>
                <div class="row g-3 text-center">
                    <div class="col-6 col-md-3">
                        <div class="run-stat-card">
                            <strong><?= number_format($runTreadmill['distance_km'], 2, ',', ' ') ?> km</strong>
                            <br><small class="text-muted">Vzdálenost</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="run-stat-card">
                            <strong><?= gmdate('H:i:s', $runTreadmill['duration_seconds']) ?></strong>
                            <br><small class="text-muted">Čas</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="run-stat-card">
                            <strong><?= h($paceDisplay) ?></strong>
                            <br><small class="text-muted">Průměrné tempo</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="run-stat-card">
                            <strong><?= h($runTreadmill['calories_burned'] ?? '–') ?> kcal</strong>
                            <br><small class="text-muted">Kalorie</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white fw-bold">
                <i class="fas fa-history me-2"></i>Poslední běhy na páse
            </div>
            <div class="card-body p-0">
                <?php
                $history = getRunTreadmillHistory($session['athlete_id'], 5);
                if (empty($history)):
                ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                    Žádné běhy v historii.
                </div>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Datum</th>
                            <th>Čas</th>
                            <th>Vzdálenost</th>
                            <th>Kalorie</th>
                            <th>Tempo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $run): ?>
                        <?php
                        $historyPace = '–';
                        if ((float)$run['distance_km'] > 0) {
                            $historyPaceSeconds = (int)round($run['duration_seconds'] / $run['distance_km']);
                            $historyPace = sprintf('%d:%02d / km', intdiv($historyPaceSeconds, 60), $historyPaceSeconds % 60);
                        }
                        ?>
                        <tr>
                            <td><?= formatDate($run['completed_at'] ?? $run['ts_started_at']) ?></td>
                            <td><?= gmdate('H:i:s', $run['duration_seconds']) ?></td>
                            <td><?= number_format($run['distance_km'], 2, ',', ' ') ?> km</td>
                            <td><?= h($run['calories_burned'] ?? '–') ?></td>
                            <td><?= h($historyPace) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-info text-white fw-bold">
                <i class="fas fa-chart-bar me-2"></i>Celkové statistiky (poslední 30 dní)
            </div>
            <div class="card-body">
                <?php $stats = calculateRunTreadmillStats($session['athlete_id'], 30); ?>
                <div class="row text-center">
                    <div class="col-md-3">
                        <strong><?= $stats['total_runs'] ?></strong>
                        <br><small class="text-muted">Počet běhů</small>
                    </div>
                    <div class="col-md-3">
                        <strong><?= number_format($stats['total_km'], 1, ',', ' ') ?> km</strong>
                        <br><small class="text-muted">Celkem</small>
                    </div>
                    <div class="col-md-3">
                        <strong><?= number_format($stats['avg_km'], 1, ',', ' ') ?> km</strong>
                        <br><small class="text-muted">Průměr</small>
                    </div>
                    <div class="col-md-3">
                        <strong><?= $stats['total_calories'] ?> kcal</strong>
                        <br><small class="text-muted">Celkem spáleno</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function addSplitRow() {
    const tbody = document.querySelector('#splits-table tbody');
    if (!tbody) return;

    const tr = document.createElement('tr');
    tr.innerHTML =
        '<td><input type="number" step="0.01" min="0" name="split_km[]" class="form-control form-control-sm"></td>' +
        '<td><input type="text" name="split_time[]" class="form-control form-control-sm" placeholder="05:15"></td>' +
        '<td><input type="text" name="split_pace[]" class="form-control form-control-sm" placeholder="05:15"></td>' +
        '<td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeSplitRow(this)"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);

    if (window.__treadmillAutosave && typeof window.__treadmillAutosave.scheduleSave === 'function') {
        window.__treadmillAutosave.scheduleSave();
    }
}

function removeSplitRow(btn) {
    const row = btn.closest('tr');
    const tbody = document.querySelector('#splits-table tbody');
    if (!row || !tbody) return;

    if (tbody.querySelectorAll('tr').length > 1) {
        row.remove();
        if (window.__treadmillAutosave && typeof window.__treadmillAutosave.scheduleSave === 'function') {
            window.__treadmillAutosave.scheduleSave();
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('treadmill-form');
    const statusEl = document.getElementById('treadmill-autosave-status');
    const locationSelect = document.getElementById('treadmill-location-select');
    const locationInput = document.getElementById('treadmill-location-input');

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
        endpoint: '<?= BASE_URL ?>/api/save_run_treadmill_draft.php',
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
                    pace: splitPace[i]?.value || ''
                });
            }

            return {
                session_id: <?= (int)$sessionId ?>,
                duration_minutes: form.querySelector('[name="duration_minutes"]').value || '0',
                duration_seconds: form.querySelector('[name="duration_seconds"]').value || '0',
                pace_minutes: form.querySelector('[name="pace_minutes"]').value || '0',
                pace_seconds: form.querySelector('[name="pace_seconds"]').value || '0',
                calories_burned: form.querySelector('[name="calories_burned"]').value || '',
                location: form.querySelector('[name="location"]').value || '',
                splits: splits
            };
        }
    });

    window.__treadmillAutosave = autosave;

    form.addEventListener('submit', function() {
        if (autosave && typeof autosave.saveNow === 'function') {
            autosave.saveNow();
        }
    });
});
</script>

<?php renderFooter(); ?>
