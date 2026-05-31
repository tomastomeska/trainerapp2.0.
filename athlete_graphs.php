<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();

$athleteStmt = $pdo->prepare('SELECT first_name, last_name FROM athletes WHERE id = ? LIMIT 1');
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();
if (!$athlete) {
    session_destroy();
    redirect(BASE_URL . '/login.php');
}

$exerciseStmt = $pdo->prepare(
    'SELECT DISTINCT e.id, e.name
     FROM exercises e
     JOIN session_series ss ON ss.exercise_id = e.id
     JOIN training_sessions ts ON ts.id = ss.session_id
     WHERE ts.athlete_id = ?
       AND ts.completed_at IS NOT NULL
       AND ts.deleted_by_coach_at IS NULL
     ORDER BY e.name ASC'
);
$exerciseStmt->execute([$athleteId]);
$exercises = $exerciseStmt->fetchAll();

$selectedExerciseId = (int)($_GET['exercise_id'] ?? 0);
if ($selectedExerciseId <= 0 && !empty($exercises)) {
    $selectedExerciseId = (int)$exercises[0]['id'];
}

$chartData = [];
if ($selectedExerciseId > 0) {
    $dataStmt = $pdo->prepare(
        'SELECT ts.completed_at AS session_date,
                ws.name AS set_name,
                MAX(COALESCE(ss.weight, 0) + COALESCE(ss.equipment_weight, 0)) AS max_weight,
                SUM((COALESCE(ss.weight, 0) + COALESCE(ss.equipment_weight, 0)) * ss.reps) AS total_volume,
                MAX(ss.reps) AS max_reps,
                SUM(ss.reps) AS total_reps
         FROM session_series ss
         JOIN training_sessions ts ON ts.id = ss.session_id
         JOIN workout_sets ws ON ws.id = ts.workout_set_id
         WHERE ts.athlete_id = ?
           AND ss.exercise_id = ?
           AND ts.completed_at IS NOT NULL
           AND ts.deleted_by_coach_at IS NULL
         GROUP BY ts.id
         ORDER BY ts.completed_at ASC'
    );
    $dataStmt->execute([$athleteId, $selectedExerciseId]);
    $chartData = $dataStmt->fetchAll();
}

renderAthleteHeader('Grafy', true);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-chart-line me-2 text-warning"></i>Grafy výkonu</h2>
    <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Zpět na profil</a>
</div>

<?php if (empty($exercises)): ?>
<div class="alert alert-info">Zatím nejsou dostupná data pro grafy.</div>
<?php else: ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="d-flex gap-3 align-items-end flex-wrap">
            <div>
                <label class="form-label fw-semibold mb-1">Cvik</label>
                <select name="exercise_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($exercises as $exercise): ?>
                    <option value="<?= (int)$exercise['id'] ?>" <?= (int)$exercise['id'] === $selectedExerciseId ? 'selected' : '' ?>>
                        <?= h((string)$exercise['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if (empty($chartData)): ?>
<div class="alert alert-warning">Pro vybraný cvik nejsou zatím dokončené tréninky.</div>
<?php else: ?>
<div class="row g-4">
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white">Maximální váha</div>
            <div class="card-body"><canvas id="maxWeightChart" style="max-height:320px"></canvas></div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white">Celkový objem</div>
            <div class="card-body"><canvas id="volumeChart" style="max-height:320px"></canvas></div>
        </div>
    </div>
</div>

<script>
const chartRows = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>;
const labels = chartRows.map(r => {
    const dt = new Date(r.session_date.replace(' ', 'T'));
    const dd = String(dt.getDate()).padStart(2, '0');
    const mm = String(dt.getMonth() + 1).padStart(2, '0');
    const yy = dt.getFullYear();
    return `${dd}.${mm}.${yy}`;
});

new Chart(document.getElementById('maxWeightChart'), {
    type: 'line',
    data: {
        labels,
        datasets: [{
            label: 'Max váha (kg)',
            data: chartRows.map(r => Number(r.max_weight || 0)),
            borderColor: '#f3b300',
            backgroundColor: 'rgba(243, 179, 0, 0.25)',
            borderWidth: 3,
            tension: 0.25,
            fill: true
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});

new Chart(document.getElementById('volumeChart'), {
    type: 'bar',
    data: {
        labels,
        datasets: [{
            label: 'Objem (kg x opak.)',
            data: chartRows.map(r => Number(r.total_volume || 0)),
            backgroundColor: 'rgba(14, 165, 233, 0.75)',
            borderColor: '#0284c7',
            borderWidth: 1
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});
</script>
<?php endif; ?>
<?php endif; ?>

<?php renderAthleteFooter();
