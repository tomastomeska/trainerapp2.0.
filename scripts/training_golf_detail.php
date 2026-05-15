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

$golfSession = getGolfSessionByTrainingSession($sessionId);
if (!$golfSession) {
    createGolfSession($sessionId);
    $golfSession = getGolfSessionByTrainingSession($sessionId);
}

$golfCourses = getGolfCourses();
$teesByCourse = [];
foreach ($golfCourses as $course) {
    $courseId = (int)$course['id'];
    $teesByCourse[$courseId] = getGolfCourseTees($courseId);
}

$selectedCourseId = (int)($golfSession['course_id'] ?? 0);
$selectedTeeId = (int)($golfSession['tee_id'] ?? 0);
$selectedTee = $selectedTeeId > 0 ? getGolfCourseTeeById($selectedTeeId) : null;
if ($selectedTee && $selectedCourseId === 0) {
    $selectedCourseId = (int)$selectedTee['course_id'];
}
$selectedGender = $selectedTee['gender'] ?? 'men';
if ($selectedGender !== 'men' && $selectedGender !== 'women' && $selectedGender !== 'unisex') {
    $selectedGender = 'men';
}

$defaultStartingHandicap = getLatestCountedGolfHandicap((int)$session['athlete_id']);
$handicapBefore = $golfSession['handicap_before'] !== null
    ? (float)$golfSession['handicap_before']
    : $defaultStartingHandicap;
$countForHandicap = (int)($golfSession['count_for_handicap'] ?? 1) === 1;
$courseRating = $golfSession['course_rating'] !== null
    ? (float)$golfSession['course_rating']
    : ($selectedTee ? (float)$selectedTee['course_rating'] : 72.0);
$slopeRating = $golfSession['slope_rating'] !== null
    ? (int)$golfSession['slope_rating']
    : ($selectedTee ? (int)$selectedTee['slope_rating'] : 113);

$savedHoles = getGolfHoles((int)$golfSession['id']);
$savedScoreTotal = calculateGolfScoreTotal($savedHoles);
$savedProjection = calculateGolfHandicapProjection(
    (int)$session['athlete_id'],
    $sessionId,
    $handicapBefore,
    $courseRating,
    $slopeRating,
    $savedScoreTotal,
    $countForHandicap
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/training_golf_detail.php?id=' . $sessionId);
    }

    $courseName = trim($_POST['course_name'] ?? '');
    $courseId = intParam($_POST, 'course_id', 0);
    $teeId = intParam($_POST, 'tee_id', 0);
    $teeGender = (string)($_POST['tee_gender'] ?? 'men');
    $numHoles = intParam($_POST, 'num_holes', 18);
    $gameType = (string)($_POST['game_type'] ?? 'training');
    $distanceKm = $_POST['distance_km'] !== '' ? (float)$_POST['distance_km'] : null;
    $caloriesBurned = $_POST['calories_burned'] !== '' ? (int)$_POST['calories_burned'] : null;
    $weather = trim($_POST['weather'] ?? '');
    $players = trim($_POST['players'] ?? '');
    $handicapBefore = $_POST['handicap_before'] !== '' ? (float)$_POST['handicap_before'] : null;
    $countForHandicap = isset($_POST['count_for_handicap']);
    $courseRating = $_POST['course_rating'] !== '' ? (float)$_POST['course_rating'] : null;
    $slopeRating = $_POST['slope_rating'] !== '' ? (int)$_POST['slope_rating'] : null;
    $durationMinutes = $_POST['duration_minutes'] !== '' ? (int)$_POST['duration_minutes'] : null;
    $feeling = trim($_POST['feeling'] ?? '');

    $selectedTee = $teeId > 0 ? getGolfCourseTeeById($teeId) : null;
    if ($selectedTee) {
        $courseId = (int)$selectedTee['course_id'];
        $courseName = (string)$selectedTee['course_name'];
        $courseRating = (float)$selectedTee['course_rating'];
        $slopeRating = (int)$selectedTee['slope_rating'];
        $teeGender = (string)$selectedTee['gender'];
    }

    if ($handicapBefore === null && $defaultStartingHandicap === null) {
        flash('danger', 'První golf vyžaduje zadat startovní HCP.');
        redirect(BASE_URL . '/training_golf_detail.php?id=' . $sessionId);
    }

    $allowedGameTypes = ['training', 'tournament', 'friendly'];
    if (!in_array($gameType, $allowedGameTypes, true)) {
        $gameType = 'training';
    }

    if ($courseName === '') {
        $courseName = 'Nezadano';
    }

    if ($courseRating === null || $slopeRating === null) {
        flash('danger', 'Vyberte odpaliště nebo doplňte course/slope rating.');
        redirect(BASE_URL . '/training_golf_detail.php?id=' . $sessionId);
    }

    if ($numHoles <= 0) {
        $numHoles = 9;
    }
    if ($numHoles > 36) {
        $numHoles = 36;
    }

    updateGolfSession(
        (int)$golfSession['id'],
        $courseName,
        $numHoles,
        $gameType,
        $distanceKm,
        $caloriesBurned,
        $weather !== '' ? $weather : null,
        $players !== '' ? $players : null,
        null,
        $feeling !== '' ? $feeling : null,
        $durationMinutes,
        $courseId > 0 ? $courseId : null,
        $teeId > 0 ? $teeId : null,
        $selectedTee ? ((string)$selectedTee['tee_name'] . ' (' . (string)$selectedTee['gender'] . ')') : null
    );

    $holeNumbers = $_POST['hole_number'] ?? [];
    $holePars = $_POST['hole_par'] ?? [];
    $holeScores = $_POST['hole_score'] ?? [];
    $holeNotes = $_POST['hole_notes'] ?? [];

    $holes = [];
    $rows = count($holeNumbers);
    for ($i = 0; $i < $rows; $i++) {
        $holeNumber = (int)($holeNumbers[$i] ?? 0);
        $par = (int)($holePars[$i] ?? 0);
        $scoreRaw = trim((string)($holeScores[$i] ?? ''));
        $score = $scoreRaw === '' ? null : (int)$scoreRaw;
        $notes = trim((string)($holeNotes[$i] ?? ''));

        if ($holeNumber <= 0 || $par <= 0) {
            continue;
        }

        $holes[] = [
            'hole_number' => $holeNumber,
            'par' => $par,
            'score' => $score,
            'notes' => $notes !== '' ? $notes : null,
        ];
    }

    $scoreTotal = calculateGolfScoreTotal($holes);
    $projection = calculateGolfHandicapProjection(
        (int)$session['athlete_id'],
        $sessionId,
        $handicapBefore,
        $courseRating,
        $slopeRating,
        $scoreTotal,
        $countForHandicap
    );

    updateGolfHandicapFields(
        (int)$golfSession['id'],
        $projection['handicap_before'],
        $countForHandicap,
        $courseRating,
        $slopeRating,
        $projection['score_total'],
        $projection['score_differential'],
        $projection['handicap_after']
    );

    saveGolfHoles((int)$golfSession['id'], $holes);

    flash('success', 'Golf byl uložen.');
    redirect(BASE_URL . '/training_golf_detail.php?id=' . $sessionId);
}

$golfSession = getGolfSessionByTrainingSession($sessionId);
$savedHoles = getGolfHoles((int)$golfSession['id']);
$history = getGolfHistory((int)$session['athlete_id'], 5);
$stats = calculateGolfStats((int)$session['athlete_id'], 90);

$holesByNumber = [];
foreach ($savedHoles as $hole) {
    $holesByNumber[(int)$hole['hole_number']] = $hole;
}

$numHolesForForm = (int)($golfSession['num_holes'] ?? 18);
if ($numHolesForForm <= 0) {
    $numHolesForForm = 18;
}

renderHeader('Golf - detail');
?>

<div class="row justify-content-center">
    <div class="col-lg-11">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-success text-white fw-bold">
                <i class="fas fa-golf-ball me-2"></i>Golf
            </div>
            <div class="card-body">
                <div class="golf-form-topbar mb-4">
                    <div>
                        <div class="text-uppercase text-muted small fw-semibold">Aktivní kolo</div>
                        <div class="fs-5 fw-bold"><?= h($session['first_name']) ?> <?= h($session['last_name']) ?></div>
                        <div class="text-muted small">Sada: <?= h($session['set_name']) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="badge bg-success text-white px-3 py-2">Automatické ukládání zapnuto</div>
                        <div id="golf-autosave-status" class="small text-muted mt-2">Připraveno</div>
                    </div>
                </div>

                <form method="post" novalidate id="golf-form" class="golf-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save">

                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Hřiště</label>
                                <select class="form-select form-select-lg" name="course_id" id="golf-course-id">
                                    <option value="0">Ruční zadání</option>
                                    <?php foreach ($golfCourses as $course): ?>
                                    <option value="<?= (int)$course['id'] ?>" <?= $selectedCourseId === (int)$course['id'] ? 'selected' : '' ?>>
                                        <?= h((string)$course['name']) ?><?= !empty($course['location']) ? ' - ' . h((string)$course['location']) : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" class="form-control mt-2" name="course_name" id="golf-course-name"
                                        value="<?= h((string)$golfSession['course_name']) ?>" placeholder="např. Albatross">
                                    <small class="text-muted">Vyber hřiště z databáze, nebo zadej ručně.</small>
                            </div>
                        </div>
                        <div class="col-6 col-lg-2">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Pohlaví</label>
                                <select class="form-select form-select-lg" name="tee_gender" id="golf-tee-gender">
                                    <option value="men" <?= $selectedGender === 'men' ? 'selected' : '' ?>>Muži</option>
                                    <option value="women" <?= $selectedGender === 'women' ? 'selected' : '' ?>>Ženy</option>
                                    <option value="unisex" <?= $selectedGender === 'unisex' ? 'selected' : '' ?>>Unisex</option>
                                </select>
                                <small class="text-muted">Podle pohlaví se filtrují odpaliště.</small>
                            </div>
                        </div>
                        <div class="col-6 col-lg-2">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Odpaliště</label>
                                <select class="form-select form-select-lg" name="tee_id" id="golf-tee-id">
                                    <option value="0">Bez odpaliště</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-6 col-lg-2">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Počet jamek</label>
                                <input type="number" class="form-control form-control-lg" name="num_holes" id="num_holes"
                                       min="1" max="36" value="<?= $numHolesForForm ?>">
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Typ hry</label>
                                <select class="form-select form-select-lg" name="game_type">
                                    <option value="training" <?= $golfSession['game_type'] === 'training' ? 'selected' : '' ?>>Trénink</option>
                                    <option value="friendly" <?= $golfSession['game_type'] === 'friendly' ? 'selected' : '' ?>>Přátelské</option>
                                    <option value="tournament" <?= $golfSession['game_type'] === 'tournament' ? 'selected' : '' ?>>Turnaj</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Course rating</label>
                                <input type="number" class="form-control form-control-lg" step="0.1" min="0" name="course_rating" id="golf-course-rating"
                                       value="<?= h((string)$courseRating) ?>"
                                       placeholder="např. 72.0">
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Slope rating</label>
                                <input type="number" class="form-control form-control-lg" min="1" name="slope_rating" id="golf-slope-rating"
                                       value="<?= h((string)$slopeRating) ?>"
                                       placeholder="např. 113">
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Startovní HCP</label>
                                <input type="number" class="form-control form-control-lg" step="0.1" min="0" name="handicap_before"
                                       value="<?= h((string)($handicapBefore ?? '')) ?>"
                                    placeholder="<?= $defaultStartingHandicap !== null ? h(number_format((float)$defaultStartingHandicap, 1, '.', '')) : 'např. 18.4' ?>"
                                        <?= $defaultStartingHandicap === null ? 'required' : '' ?>>
                                <?php if ($defaultStartingHandicap === null): ?>
                                <small class="text-muted">První golf: zadej vstupní HCP.</small>
                                <?php else: ?>
                                <small class="text-muted">Další kolo navazuje na poslední uložené HCP.</small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="mb-3 d-flex flex-column justify-content-end h-100">
                                <div class="alert alert-light border mb-0 py-2 small golf-inline-note">
                                    CR/SR se při výběru odpaliště načte automaticky.
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="mb-3 d-flex flex-column justify-content-end h-100">
                                <label class="form-label fw-semibold">Do HCP</label>
                                <div class="form-check form-switch form-switch-lg mt-1">
                                    <input class="form-check-input" type="checkbox" role="switch" id="count_for_handicap" name="count_for_handicap" <?= $countForHandicap ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="count_for_handicap">Započítat toto kolo</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-6 col-md-2">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Km</label>
                                <input type="number" class="form-control" step="0.1" min="0" name="distance_km"
                                       value="<?= h((string)($golfSession['distance_km'] ?? '')) ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Kalorie</label>
                                <input type="number" class="form-control" min="0" name="calories_burned"
                                       value="<?= h((string)($golfSession['calories_burned'] ?? '')) ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Doba (min)</label>
                                <input type="number" class="form-control" min="0" name="duration_minutes"
                                       value="<?= h((string)($golfSession['duration_minutes'] ?? '')) ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="mb-3">
                                    <label class="form-label fw-semibold">Výsledné HCP</label>
                                <input type="number" class="form-control form-control-lg" step="0.1" readonly id="golf-handicap-after"
                                       value="<?= h((string)($golfSession['handicap_after'] ?? $savedProjection['handicap_after'] ?? '')) ?>">
                                    <small class="text-muted">Po uložení se dopočítá automaticky.</small>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="mb-3">
                                    <label class="form-label fw-semibold">Počasí</label>
                                <input type="text" class="form-control" name="weather"
                                        value="<?= h((string)($golfSession['weather'] ?? '')) ?>" placeholder="slunečno, vítr...">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                           <label class="form-label fw-semibold">Spoluhráči</label>
                        <input type="text" class="form-control" name="players"
                               value="<?= h((string)($golfSession['players'] ?? '')) ?>" placeholder="Jména oddělená čárkou">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Pocit / poznámka</label>
                        <textarea class="form-control" name="feeling" rows="2" placeholder="Shrnutí kola..."><?= h((string)($golfSession['feeling'] ?? '')) ?></textarea>
                    </div>

                    <hr>
                    <h5>Jamky</h5>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered align-middle golf-holes-table" id="holes-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Jamka</th>
                                    <th>Par</th>
                                    <th>Skóre</th>
                                    <th>Poznámka</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($i = 1; $i <= $numHolesForForm; $i++): ?>
                                <?php $hole = $holesByNumber[$i] ?? null; ?>
                                <tr>
                                    <td>
                                        <?= $i ?>
                                        <input type="hidden" name="hole_number[]" value="<?= $i ?>">
                                    </td>
                                    <td><input type="number" class="form-control form-control-sm" name="hole_par[]" min="1" max="10" value="<?= h((string)($hole['par'] ?? 4)) ?>"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="hole_score[]" min="1" max="20" value="<?= h((string)($hole['score'] ?? '')) ?>"></td>
                                    <td><input type="text" class="form-control form-control-sm" name="hole_notes[]" value="<?= h((string)($hole['notes'] ?? '')) ?>"></td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex flex-column flex-sm-row gap-2 align-items-stretch align-items-sm-center">
                        <button type="submit" class="btn btn-success btn-lg fw-bold">
                            <i class="fas fa-save me-1"></i>Uložit golf
                        </button>
                        <a href="<?= BASE_URL ?>/training_session.php?id=<?= $sessionId ?>" class="btn btn-outline-secondary btn-lg">Zpět na trénink</a>
                        <small id="golf-autosave-status" class="text-muted ms-0 ms-sm-2 align-self-center">Automatické ukládání zapnuto</small>
                    </div>
                </form>

                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <div class="alert alert-light border mb-0">
                            <div class="small text-muted">HCP před hrou</div>
                            <div class="fw-bold fs-5" id="golf-hcp-before-value"><?= $savedProjection['handicap_before'] !== null ? number_format((float)$savedProjection['handicap_before'], 1, ',', ' ') : '–' ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="alert alert-light border mb-0">
                            <div class="small text-muted">Score differential</div>
                            <div class="fw-bold fs-5" id="golf-score-diff-value"><?= $savedProjection['score_differential'] !== null ? number_format((float)$savedProjection['score_differential'], 1, ',', ' ') : '–' ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="alert alert-light border mb-0">
                            <div class="small text-muted">Výsledné HCP</div>
                            <div class="fw-bold fs-5" id="golf-hcp-after-value">
                                <?= $savedProjection['handicap_after'] !== null ? number_format((float)$savedProjection['handicap_after'], 1, ',', ' ') : '–' ?>
                                <span class="badge bg-secondary ms-1" id="golf-hcp-count-badge">
                                    <?= $countForHandicap ? 'počítá se' : 'jen informativně' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-dark text-white fw-bold">
                <i class="fas fa-history me-2"></i>Poslední kola
            </div>
            <div class="card-body p-0">
                <?php if (empty($history)): ?>
                <div class="text-center py-4 text-muted">Žádná historie.</div>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Datum</th>
                            <th>Hřiště</th>
                            <th>Skóre</th>
                            <th>Par</th>
                            <th>HCP před</th>
                            <th>HCP po</th>
                            <th>Do HCP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $round): ?>
                        <tr>
                            <td><?= formatDate($round['completed_at'] ?? $round['ts_started_at']) ?></td>
                            <td><?= h((string)$round['course_name']) ?></td>
                            <td><?= (int)$round['total_score'] ?></td>
                            <td><?= (int)$round['total_par'] ?></td>
                            <td><?= $round['handicap_before'] !== null ? number_format((float)$round['handicap_before'], 1, ',', ' ') : '–' ?></td>
                            <td><?= $round['handicap_after'] !== null ? number_format((float)$round['handicap_after'], 1, ',', ' ') : '–' ?></td>
                            <td>
                                <?php if ((int)($round['count_for_handicap'] ?? 1) === 1): ?>
                                <span class="badge bg-success">Ano</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Ne</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-info text-white fw-bold">
                <i class="fas fa-chart-line me-2"></i>Statistiky (90 dní)
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-2">
                        <strong><?= (int)$stats['total_rounds'] ?></strong><br>
                        <small class="text-muted">Kol</small>
                    </div>
                    <div class="col-md-2">
                        <strong><?= $stats['avg_handicap'] !== null ? number_format((float)$stats['avg_handicap'], 1, ',', ' ') : '–' ?></strong><br>
                        <small class="text-muted">Prům. HCP</small>
                    </div>
                    <div class="col-md-2">
                        <strong><?= number_format((float)$stats['total_km'], 1, ',', ' ') ?> km</strong><br>
                        <small class="text-muted">Uchozeno</small>
                    </div>
                    <div class="col-md-2">
                        <strong><?= (int)$stats['total_calories'] ?> kcal</strong><br>
                        <small class="text-muted">Kalorie</small>
                    </div>
                    <div class="col-md-2">
                        <strong><?= (int)$stats['total_score'] ?></strong><br>
                        <small class="text-muted">Skóre</small>
                    </div>
                    <div class="col-md-2">
                        <strong><?= (int)$stats['total_par'] ?></strong><br>
                        <small class="text-muted">Par</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const golfForm = document.getElementById('golf-form');
const autosaveStatus = document.getElementById('golf-autosave-status');
const apiUrl = '<?= BASE_URL ?>/api/save_golf_draft.php';
const sessionId = <?= (int)$sessionId ?>;
const teesByCourse = <?= json_encode($teesByCourse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const initialCourseId = <?= (int)$selectedCourseId ?>;
const initialTeeId = <?= (int)$selectedTeeId ?>;
const initialGender = <?= json_encode($selectedGender, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

let saveTimer = null;
let saveInProgress = false;
let pendingSave = false;
let lastSavedHash = '';

function rebuildTeeOptions(courseId, gender = 'men', teeId = 0) {
    const teeSelect = document.getElementById('golf-tee-id');
    const courseRatingInput = document.getElementById('golf-course-rating');
    const slopeRatingInput = document.getElementById('golf-slope-rating');
    const courseNameInput = document.getElementById('golf-course-name');
    if (!teeSelect) return;

    teeSelect.innerHTML = '';
    const emptyOpt = document.createElement('option');
    emptyOpt.value = '0';
    emptyOpt.textContent = 'Bez odpaliště';
    teeSelect.appendChild(emptyOpt);

    const list = (teesByCourse[String(courseId)] || []).filter(function(tee) {
        return tee.gender === gender || tee.gender === 'unisex';
    });
    list.forEach(function(tee) {
        const opt = document.createElement('option');
        opt.value = String(tee.id);
        opt.textContent = tee.tee_name + ' - ' + (tee.gender === 'men' ? 'muži' : tee.gender === 'women' ? 'ženy' : 'unisex') + ' - CR ' + tee.course_rating + ' / SR ' + tee.slope_rating;
        if (parseInt(tee.id, 10) === parseInt(teeId, 10)) {
            opt.selected = true;
            if (courseRatingInput) courseRatingInput.value = tee.course_rating;
            if (slopeRatingInput) slopeRatingInput.value = tee.slope_rating;
        }
        teeSelect.appendChild(opt);
    });

    const hasSelected = list.some(function(tee) { return parseInt(tee.id, 10) === parseInt(teeId, 10); });
    if (!hasSelected && courseRatingInput && slopeRatingInput && list.length > 0) {
        const first = list[0];
        teeSelect.value = String(first.id);
        courseRatingInput.value = first.course_rating;
        slopeRatingInput.value = first.slope_rating;
    }

    if (courseNameInput) {
        courseNameInput.readOnly = parseInt(courseId, 10) > 0;
    }
}

document.getElementById('golf-course-id')?.addEventListener('change', function() {
    const courseId = parseInt(this.value || '0', 10);
    const gender = document.getElementById('golf-tee-gender')?.value || 'men';
    rebuildTeeOptions(courseId, gender, 0);
    scheduleGolfAutosave();
});

document.getElementById('golf-tee-gender')?.addEventListener('change', function() {
    const courseId = parseInt(document.getElementById('golf-course-id')?.value || '0', 10);
    rebuildTeeOptions(courseId, this.value || 'men', 0);
    scheduleGolfAutosave();
});

document.getElementById('golf-tee-id')?.addEventListener('change', function() {
    const courseId = parseInt(document.getElementById('golf-course-id')?.value || '0', 10);
    const gender = document.getElementById('golf-tee-gender')?.value || 'men';
    const teeId = parseInt(this.value || '0', 10);
    const list = (teesByCourse[String(courseId)] || []).filter(function(tee) {
        return tee.gender === gender || tee.gender === 'unisex';
    });
    const selected = list.find(function(tee) { return parseInt(tee.id, 10) === teeId; });
    if (selected) {
        const courseRatingInput = document.getElementById('golf-course-rating');
        const slopeRatingInput = document.getElementById('golf-slope-rating');
        if (courseRatingInput) courseRatingInput.value = selected.course_rating;
        if (slopeRatingInput) slopeRatingInput.value = selected.slope_rating;
    }
    scheduleGolfAutosave();
});

rebuildTeeOptions(initialCourseId, initialGender, initialTeeId);

function setStatus(text, cls) {
    autosaveStatus.classList.remove('text-muted', 'text-success', 'text-danger');
    autosaveStatus.classList.add(cls);
    autosaveStatus.textContent = text;
}

function collectGolfPayload() {
    const payload = {
        session_id: sessionId,
        course_id: parseInt(golfForm.querySelector('[name="course_id"]')?.value || '0', 10) || 0,
        tee_id: parseInt(golfForm.querySelector('[name="tee_id"]')?.value || '0', 10) || 0,
        tee_gender: golfForm.querySelector('[name="tee_gender"]')?.value || 'men',
        course_name: (golfForm.querySelector('[name="course_name"]')?.value || '').trim(),
        num_holes: parseInt(golfForm.querySelector('[name="num_holes"]')?.value || '18', 10) || 18,
        game_type: golfForm.querySelector('[name="game_type"]')?.value || 'training',
        handicap_before: golfForm.querySelector('[name="handicap_before"]')?.value ?? '',
        count_for_handicap: golfForm.querySelector('[name="count_for_handicap"]')?.checked ? 1 : 0,
        course_rating: golfForm.querySelector('[name="course_rating"]')?.value ?? '',
        slope_rating: golfForm.querySelector('[name="slope_rating"]')?.value ?? '',
        distance_km: golfForm.querySelector('[name="distance_km"]')?.value ?? '',
        calories_burned: golfForm.querySelector('[name="calories_burned"]')?.value ?? '',
        weather: (golfForm.querySelector('[name="weather"]')?.value || '').trim(),
        players: (golfForm.querySelector('[name="players"]')?.value || '').trim(),
        duration_minutes: golfForm.querySelector('[name="duration_minutes"]')?.value ?? '',
        feeling: (golfForm.querySelector('[name="feeling"]')?.value || '').trim(),
        holes: []
    };

    const holeNumbers = golfForm.querySelectorAll('[name="hole_number[]"]');
    const holePars = golfForm.querySelectorAll('[name="hole_par[]"]');
    const holeScores = golfForm.querySelectorAll('[name="hole_score[]"]');
    const holeNotes = golfForm.querySelectorAll('[name="hole_notes[]"]');

    for (let i = 0; i < holeNumbers.length; i++) {
        payload.holes.push({
            hole_number: holeNumbers[i]?.value || '',
            par: holePars[i]?.value || '',
            score: holeScores[i]?.value || '',
            notes: (holeNotes[i]?.value || '').trim()
        });
    }

    return payload;
}

async function saveGolfDraft(immediate = false) {
    if (!immediate && saveInProgress) {
        pendingSave = true;
        return;
    }

    const payload = collectGolfPayload();
    const hash = JSON.stringify(payload);
    if (!immediate && hash === lastSavedHash) {
        return;
    }

    if (saveInProgress) {
        pendingSave = true;
        return;
    }

    saveInProgress = true;
    setStatus('Ukládám...', 'text-muted');

    try {
        const resp = await fetch(apiUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        const data = await resp.json();
        if (!data.success) {
            throw new Error(data.error || 'Uložení se nezdařilo');
        }
        lastSavedHash = hash;
        setStatus('Uloženo ' + (data.saved_at || ''), 'text-success');
        const handicapAfterInput = document.getElementById('golf-handicap-after');
        const handicapBeforeValue = document.getElementById('golf-hcp-before-value');
        const scoreDiffValue = document.getElementById('golf-score-diff-value');
        const handicapAfterValue = document.getElementById('golf-hcp-after-value');
        const countBadge = document.getElementById('golf-hcp-count-badge');
        if (handicapBeforeValue && data.handicap_before !== undefined) {
            handicapBeforeValue.textContent = data.handicap_before !== null ? Number(data.handicap_before).toFixed(1).replace('.', ',') : '–';
        }
        if (scoreDiffValue && data.score_differential !== undefined) {
            scoreDiffValue.textContent = data.score_differential !== null ? Number(data.score_differential).toFixed(1).replace('.', ',') : '–';
        }
        if (handicapAfterInput && data.handicap_after !== undefined) {
            handicapAfterInput.value = data.handicap_after !== null ? Number(data.handicap_after).toFixed(1) : '';
        }
        if (handicapAfterValue && data.handicap_after !== undefined) {
            if (handicapAfterValue.firstChild && handicapAfterValue.firstChild.nodeType === Node.TEXT_NODE) {
                handicapAfterValue.firstChild.nodeValue = data.handicap_after !== null ? Number(data.handicap_after).toFixed(1).replace('.', ',') + ' ' : '– ';
            }
            if (countBadge) {
                countBadge.textContent = data.count_for_handicap ? 'počítá se' : 'jen informativně';
            }
        }
    } catch (err) {
        setStatus('Neuloženo - zkontrolujte připojení', 'text-danger');
    } finally {
        saveInProgress = false;
        if (pendingSave) {
            pendingSave = false;
            saveGolfDraft(false);
        }
    }
}

function scheduleGolfAutosave() {
    setStatus('Neuložené změny', 'text-muted');
    if (saveTimer) {
        clearTimeout(saveTimer);
    }
    saveTimer = setTimeout(function() {
        saveGolfDraft(false);
    }, 700);
}

document.getElementById('num_holes').addEventListener('change', function() {
    const target = parseInt(this.value || '18', 10);
    if (target <= 0 || target > 36) {
        return;
    }

    const tbody = document.querySelector('#holes-table tbody');
    const existingRows = tbody.querySelectorAll('tr');

    if (existingRows.length === target) {
        return;
    }

    const oldData = {};
    existingRows.forEach(function(row) {
        const hole = parseInt(row.querySelector('input[name="hole_number[]"]').value, 10);
        oldData[hole] = {
            par: row.querySelector('input[name="hole_par[]"]').value,
            score: row.querySelector('input[name="hole_score[]"]').value,
            notes: row.querySelector('input[name="hole_notes[]"]').value
        };
    });

    tbody.innerHTML = '';
    for (let i = 1; i <= target; i++) {
        const data = oldData[i] || {par: '4', score: '', notes: ''};
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + i + '<input type="hidden" name="hole_number[]" value="' + i + '"></td>' +
            '<td><input type="number" class="form-control form-control-sm" name="hole_par[]" min="1" max="10" value="' + data.par + '"></td>' +
            '<td><input type="number" class="form-control form-control-sm" name="hole_score[]" min="1" max="20" value="' + data.score + '"></td>' +
            '<td><input type="text" class="form-control form-control-sm" name="hole_notes[]" value="' + data.notes.replace(/"/g, '&quot;') + '"></td>';
        tbody.appendChild(tr);
    }

    scheduleGolfAutosave();
});

golfForm.addEventListener('input', function(e) {
    if (!e.target || e.target.type === 'hidden') {
        return;
    }
    scheduleGolfAutosave();
});

golfForm.addEventListener('change', function(e) {
    if (!e.target || e.target.type === 'hidden') {
        return;
    }
    scheduleGolfAutosave();
});

golfForm.addEventListener('submit', function() {
    setStatus('Ukládám...', 'text-muted');
});

window.addEventListener('beforeunload', function() {
    if (saveTimer) {
        clearTimeout(saveTimer);
    }
    saveGolfDraft(true);
});
</script>

<?php renderFooter(); ?>
