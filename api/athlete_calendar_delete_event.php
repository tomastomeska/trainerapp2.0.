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

$eventId = (int)($input['event_id'] ?? 0);
if ($eventId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Chybí ID termínu']);
    exit;
}

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();

$eventStmt = $pdo->prepare(
    'SELECT e.id,
            e.coach_id,
            e.athlete_id,
            e.requested_by_athlete_id,
            e.starts_at,
            c.name AS coach_name,
            c.username AS coach_username,
            a.first_name,
            a.last_name
     FROM coach_calendar_events e
     JOIN coaches c ON c.id = e.coach_id
     JOIN athletes a ON a.id = e.athlete_id
     WHERE e.id = ?
       AND e.athlete_id = ?
     LIMIT 1'
);
$eventStmt->execute([$eventId, $athleteId]);
$event = $eventStmt->fetch();

if (!$event) {
    echo json_encode(['success' => false, 'error' => 'Termín nebyl nalezen.']);
    exit;
}

$deleteStmt = $pdo->prepare('DELETE FROM coach_calendar_events WHERE id = ? AND athlete_id = ? LIMIT 1');
$deleteStmt->execute([$eventId, $athleteId]);

if ($deleteStmt->rowCount() === 0) {
    echo json_encode(['success' => false, 'error' => 'Termín se nepodařilo zrušit.']);
    exit;
}

$athleteName = trim((string)$event['first_name'] . ' ' . (string)$event['last_name']);
$coachDisplayName = ($event['coach_name'] ?? '') !== '' ? (string)$event['coach_name'] : (string)($event['coach_username'] ?? 'trenér');
$subject = "Sportovec zrušil termín - {$athleteName}";
$body = "Sportovec {$athleteName} zrušil termín " . date('d.m.Y H:i', strtotime((string)$event['starts_at'])) . ".";
createCoachSystemMessage((int)$event['coach_id'], $subject, $body, true);

createAthleteNotification($athleteId, 'Potvrzení zrušení termínu', "Tvůj termín {$event['starts_at']} byl zrušen.");

echo json_encode([
    'success' => true,
    'message' => "Termín byl zrušen. Trenér {$coachDisplayName} byl informován.",
]);
