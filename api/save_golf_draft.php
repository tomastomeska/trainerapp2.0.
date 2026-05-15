<?php
// api/save_golf_draft.php – AJAX endpoint pro průběžné ukládání golfového detailu
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Nepřihlášen']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Neplatná metoda']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Neplatná data']);
    exit;
}

$coachId = getCurrentCoachId();
$sessionId = (int)($input['session_id'] ?? 0);
if ($sessionId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Neplatné session_id']);
    exit;
}

$pdo = getDB();

$stmt = $pdo->prepare(
    'SELECT ts.id, ts.athlete_id
     FROM training_sessions ts
     JOIN athletes a ON a.id = ts.athlete_id
     WHERE ts.id = ?
       AND a.coach_id = ?
       AND ts.deleted_by_coach_at IS NULL
       AND ts.completed_at IS NULL'
);
$stmt->execute([$sessionId, $coachId]);
$sessionRow = $stmt->fetch();
if (!$sessionRow) {
    echo json_encode(['success' => false, 'error' => 'Trénink nenalezen nebo je již dokončen']);
    exit;
}
$athleteId = (int)$sessionRow['athlete_id'];

$golfSession = getGolfSessionByTrainingSession($sessionId);
if (!$golfSession) {
    createGolfSession($sessionId);
    $golfSession = getGolfSessionByTrainingSession($sessionId);
}

$courseId = (int)($input['course_id'] ?? 0);
$teeId = (int)($input['tee_id'] ?? 0);
$teeGender = (string)($input['tee_gender'] ?? 'men');
$courseName = trim((string)($input['course_name'] ?? ''));
$numHoles = (int)($input['num_holes'] ?? 18);
$gameType = (string)($input['game_type'] ?? 'training');
$distanceKmRaw = $input['distance_km'] ?? '';
$caloriesRaw = $input['calories_burned'] ?? '';
$weather = trim((string)($input['weather'] ?? ''));
$players = trim((string)($input['players'] ?? ''));
$handicapBeforeRaw = $input['handicap_before'] ?? '';
$countForHandicap = !empty($input['count_for_handicap']);
$courseRatingRaw = $input['course_rating'] ?? '';
$slopeRatingRaw = $input['slope_rating'] ?? '';
$durationRaw = $input['duration_minutes'] ?? '';
$feeling = trim((string)($input['feeling'] ?? ''));

$allowedGameTypes = ['training', 'tournament', 'friendly'];
if (!in_array($gameType, $allowedGameTypes, true)) {
    $gameType = 'training';
}

if ($courseName === '') {
    $courseName = 'Nezadano';
}

if ($numHoles < 1) {
    $numHoles = 1;
}
if ($numHoles > 36) {
    $numHoles = 36;
}

$distanceKm = $distanceKmRaw !== '' ? (float)$distanceKmRaw : null;
$caloriesBurned = $caloriesRaw !== '' ? (int)$caloriesRaw : null;
$startingHandicap = $handicapBeforeRaw !== '' ? (float)$handicapBeforeRaw : null;
$courseRating = $courseRatingRaw !== '' ? (float)$courseRatingRaw : null;
$slopeRating = $slopeRatingRaw !== '' ? (int)$slopeRatingRaw : null;
$durationMinutes = $durationRaw !== '' ? (int)$durationRaw : null;

$selectedTee = $teeId > 0 ? getGolfCourseTeeById($teeId) : null;
if ($selectedTee) {
    $courseId = (int)$selectedTee['course_id'];
    $courseName = (string)$selectedTee['course_name'];
    $courseRating = (float)$selectedTee['course_rating'];
    $slopeRating = (int)$selectedTee['slope_rating'];
    $teeGender = (string)$selectedTee['gender'];
}

if ($startingHandicap === null && getLatestCountedGolfHandicap($athleteId) === null) {
    echo json_encode(['success' => false, 'error' => 'První golf vyžaduje zadat startovní HCP']);
    exit;
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

$holesInput = $input['holes'] ?? [];
$holes = [];
if (is_array($holesInput)) {
    foreach ($holesInput as $row) {
        if (!is_array($row)) {
            continue;
        }

        $holeNumber = (int)($row['hole_number'] ?? 0);
        $par = (int)($row['par'] ?? 0);
        $scoreRaw = trim((string)($row['score'] ?? ''));
        $score = $scoreRaw === '' ? null : (int)$scoreRaw;
        $notes = trim((string)($row['notes'] ?? ''));

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
}

$scoreTotal = calculateGolfScoreTotal($holes);
$projection = calculateGolfHandicapProjection(
    $athleteId,
    $sessionId,
    $startingHandicap,
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

echo json_encode([
    'success' => true,
    'saved_at' => date('H:i:s'),
    'handicap_before' => $projection['handicap_before'],
    'handicap_after' => $projection['handicap_after'],
    'score_differential' => $projection['score_differential'],
    'count_for_handicap' => $countForHandicap,
    'tee_gender' => $teeGender,
]);
