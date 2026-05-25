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
    'SELECT e.id,
            e.series_id,
            e.starts_at,
            e.athlete_id,
        e.requested_by_athlete_id,
        e.approval_status,
            a.email AS athlete_email,
            a.first_name,
            a.last_name
    FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     WHERE e.id = ? AND e.coach_id = ?
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

if (!empty($event['athlete_id'])) {
    $athleteId = (int)$event['athlete_id'];
    $athleteName = trim((string)$event['first_name'] . ' ' . (string)$event['last_name']);
    $isPendingRequest = (($event['approval_status'] ?? 'approved') === 'pending') && !empty($event['requested_by_athlete_id']);
    if ($isPendingRequest) {
        $subject = 'Požadavek termínu byl zamítnut';
        $body = 'Trenér zamítl váš požadavek na termín ' . date('d.m.Y H:i', strtotime((string)$event['starts_at'])) . '.';
    } elseif ($deleteScope === 'future') {
        $subject = 'Zrušení série tréninků';
        $body = 'Trenér zrušil navazující termíny od ' . date('d.m.Y H:i', strtotime((string)$event['starts_at'])) . '.';
    } else {
        $subject = 'Zrušení tréninku';
        $body = 'Trenér zrušil trénink naplánovaný na ' . date('d.m.Y H:i', strtotime((string)$event['starts_at'])) . '.';
    }

    createAthleteNotification($athleteId, $subject, $body);
    if (!empty($event['athlete_email'])) {
        sendAthleteCalendarNotificationEmail((string)$event['athlete_email'], $athleteName, $subject, $body);
    }
}

echo json_encode(['success' => true, 'deleted_count' => $del->rowCount(), 'scope' => $deleteScope]);
