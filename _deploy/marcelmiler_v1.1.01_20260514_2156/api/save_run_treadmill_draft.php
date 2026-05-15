<?php
// api/save_run_treadmill_draft.php – AJAX endpoint pro průběžné ukládání běhu na páse
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
    'SELECT ts.id
     FROM training_sessions ts
     JOIN athletes a ON a.id = ts.athlete_id
     WHERE ts.id = ?
       AND a.coach_id = ?
       AND ts.deleted_by_coach_at IS NULL
       AND ts.completed_at IS NULL'
);
$stmt->execute([$sessionId, $coachId]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Trénink nenalezen nebo je již dokončen']);
    exit;
}

$run = getRunTreadmillSessionByTrainingSession($sessionId);
if (!$run) {
    createRunTreadmillSession($sessionId, 0, 0);
    $run = getRunTreadmillSessionByTrainingSession($sessionId);
}

$durationSeconds = max(0, (int)($input['duration_minutes'] ?? 0) * 60 + (int)($input['duration_seconds'] ?? 0));
$paceSecondsPerKm = max(0, (int)($input['pace_minutes'] ?? 0) * 60 + (int)($input['pace_seconds'] ?? 0));
$distanceKm = $paceSecondsPerKm > 0
    ? round($durationSeconds / $paceSecondsPerKm, 2)
    : 0;
$caloriesBurned = ($input['calories_burned'] ?? '') !== '' ? (int)$input['calories_burned'] : null;
$location = normalizeTrainingVenueName((string)($input['location'] ?? ''));
$splitsInput = $input['splits'] ?? [];
$splits = [];

if (is_array($splitsInput)) {
    foreach ($splitsInput as $split) {
        if (!is_array($split)) {
            continue;
        }

        $km = isset($split['km_marker']) && $split['km_marker'] !== '' ? (float)$split['km_marker'] : 0;
        $time = trim((string)($split['split_time'] ?? ''));
        $pace = trim((string)($split['pace'] ?? ''));

        if ($km <= 0 || $time === '') {
            continue;
        }

        if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            continue;
        }

        if ($pace !== '' && !preg_match('/^\d{1,2}:\d{2}$/', $pace)) {
            $pace = '';
        }

        $splits[] = [
            'km_marker' => $km,
            'split_time' => $time,
            'pace' => $pace !== '' ? $pace : null,
        ];
    }
}

if ($location !== '') {
    rememberTrainingVenue($location, $coachId);
}

updateRunTreadmillSession(
    (int)$run['id'],
    $durationSeconds,
    $distanceKm,
    $caloriesBurned,
    $location !== '' ? $location : null,
    null
);

$pdo->prepare('UPDATE training_sessions SET location = ? WHERE id = ?')
    ->execute([$location !== '' ? $location : null, $sessionId]);

saveRunTreadmillSplits((int)$run['id'], $splits);

echo json_encode([
    'success' => true,
    'saved_at' => date('H:i:s'),
]);
