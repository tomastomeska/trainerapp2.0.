<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!athleteIsLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Nepřihlášen']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Neplatná metoda']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Neplatná data']);
    exit;
}

if (!verifyCsrf((string)($input['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'error' => 'Neplatný CSRF token']);
    exit;
}

$athleteId = (int)getCurrentAthleteId();
$startsAtRaw = trim((string)($input['starts_at'] ?? ''));
$location = trim((string)($input['location'] ?? ''));
$titleType = trim((string)($input['title_type'] ?? 'training'));

$start = DateTime::createFromFormat('Y-m-d\TH:i', $startsAtRaw);
if (!$start) {
    echo json_encode(['success' => false, 'error' => 'Neplatný začátek termínu']);
    exit;
}

$end = clone $start;
$end->modify('+60 minutes');

if (!in_array($titleType, ['training', 'consultation', 'other'], true)) {
    $titleType = 'training';
}

$titleLabels = [
    'training' => 'Trénink',
    'consultation' => 'Konzultační hodina',
    'other' => 'Jiné',
];
$customTitle = $titleLabels[$titleType];
if ($location !== '') {
    $location = mb_substr($location, 0, 255, 'UTF-8');
} else {
    $location = null;
}

$pdo = getDB();
$athleteStmt = $pdo->prepare(
    'SELECT a.id, a.first_name, a.last_name, a.email, a.coach_id,
            c.name AS coach_name, c.username AS coach_username, c.email AS coach_email
     FROM athletes a
     JOIN coaches c ON c.id = a.coach_id
     WHERE a.id = ?
     LIMIT 1'
);
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();
if (!$athlete) {
    echo json_encode(['success' => false, 'error' => 'Sportovec nenalezen']);
    exit;
}

if ($location !== null) {
    rememberTrainingVenue($location, (int)$athlete['coach_id']);

    $venueStmt = $pdo->prepare('SELECT name FROM training_venues WHERE name = ? LIMIT 1');
    $venueStmt->execute([$location]);
    $venue = $venueStmt->fetch();
    if ($venue && !empty($venue['name'])) {
        $location = (string)$venue['name'];
    }
}

$startSql = $start->format('Y-m-d H:i:s');
$endSql = $end->format('Y-m-d H:i:s');
$billingMonthSql = $start->format('Y-m-01');

$lockStmt = $pdo->prepare(
    'SELECT id
     FROM coach_calendar_locks
     WHERE coach_id = ?
       AND starts_at < ?
       AND ends_at > ?
     LIMIT 1'
);
$lockStmt->execute([(int)$athlete['coach_id'], $endSql, $startSql]);
if ($lockStmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Termín je uzamčený a nelze jej rezervovat.']);
    exit;
}

$overlapStmt = $pdo->prepare(
    'SELECT id
     FROM coach_calendar_events
     WHERE coach_id = ?
       AND starts_at < ?
       AND ends_at > ?
     LIMIT 1'
);
$overlapStmt->execute([(int)$athlete['coach_id'], $endSql, $startSql]);
if ($overlapStmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'V tomto čase je slot obsazený.']);
    exit;
}

$insert = $pdo->prepare(
    'INSERT INTO coach_calendar_events (coach_id, athlete_id, requested_by_athlete_id, approval_status, coach_modified_at, is_makeup_session, billing_month, series_id, color_key, custom_title, location, starts_at, ends_at)
     VALUES (?, ?, ?, ?, NULL, 0, ?, NULL, ?, ?, ?, ?, ?)'
);
$insert->execute([
    (int)$athlete['coach_id'],
    $athleteId,
    $athleteId,
    'pending',
    $billingMonthSql,
    'green',
    $customTitle,
    $location,
    $startSql,
    $endSql,
]);

$athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
$timeLabel = $start->format('d.m.Y H:i');
$subject = "Nový požadavek termínu - {$athleteName}";
$body = "Sportovec {$athleteName} si rezervoval termín {$timeLabel}.";
if ($location) {
    $body .= " Místo: {$location}.";
}
if ($customTitle !== '') {
    $body .= " Poznámka: {$customTitle}.";
}
createCoachSystemMessage((int)$athlete['coach_id'], $subject, $body, true);

createAthleteNotification($athleteId, 'Požadavek odeslán ke schválení', "Tvůj požadavek na termín {$timeLabel} čeká na schválení trenérem.");

echo json_encode(['success' => true, 'message' => 'Požadavek byl odeslán ke schválení.']);
