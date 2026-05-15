<?php
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
if (!is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Neplatná data']);
    exit;
}

if (!verifyCsrf((string)($input['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'error' => 'Neplatný CSRF token']);
    exit;
}

$eventId = (int)($input['event_id'] ?? 0);
$deleteScope = trim((string)($input['delete_scope'] ?? 'single'));
$coachId = (int)getCurrentCoachId();

if (!in_array($deleteScope, ['single', 'future'], true)) {
    $deleteScope = 'single';
}

if ($eventId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Chybí ID události']);
    exit;
}

$pdo = getDB();

$eventStmt = $pdo->prepare(
    'SELECT id, series_id, starts_at
     FROM coach_calendar_events
     WHERE id = ? AND coach_id = ?
     LIMIT 1'
);
$eventStmt->execute([$eventId, $coachId]);
$event = $eventStmt->fetch();

if (!$event) {
    echo json_encode(['success' => false, 'error' => 'Událost nenalezena']);
    exit;
}

if ($deleteScope === 'future') {
    if (empty($event['series_id'])) {
        echo json_encode(['success' => false, 'error' => 'Událost není součástí série.']);
        exit;
    }

    $del = $pdo->prepare(
        'DELETE FROM coach_calendar_events
         WHERE coach_id = ?
           AND series_id = ?
           AND starts_at >= ?'
    );
    $del->execute([$coachId, $event['series_id'], $event['starts_at']]);
} else {
    $del = $pdo->prepare('DELETE FROM coach_calendar_events WHERE id = ? AND coach_id = ?');
    $del->execute([$eventId, $coachId]);
}

if ($del->rowCount() === 0) {
    echo json_encode(['success' => false, 'error' => 'Událost nenalezena']);
    exit;
}

echo json_encode(['success' => true, 'deleted_count' => $del->rowCount(), 'scope' => $deleteScope]);
