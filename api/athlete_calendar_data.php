<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!athleteIsLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Nepřihlášen']);
    exit;
}

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();

$athleteStmt = $pdo->prepare('SELECT id, coach_id FROM athletes WHERE id = ? LIMIT 1');
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();
if (!$athlete) {
    echo json_encode(['success' => false, 'error' => 'Sportovec nenalezen']);
    exit;
}

$weekStartRaw = trim((string)($_GET['week_start'] ?? ''));
$weekBase = DateTimeImmutable::createFromFormat('Y-m-d', $weekStartRaw) ?: new DateTimeImmutable('today');
$weekStart = $weekBase->modify('-' . ((int)$weekBase->format('N') - 1) . ' days')->setTime(0, 0, 0);
$weekEnd = $weekStart->modify('+7 days');

$eventsStmt = $pdo->prepare(
        "SELECT e.id,
                        e.athlete_id,
                        e.requested_by_athlete_id,
                        e.approval_status,
                        e.color_key,
                        e.coach_modified_at,
                        e.custom_title,
                        e.location,
                        e.starts_at,
                        e.ends_at,
                        a.first_name,
                        a.last_name
         FROM coach_calendar_events e
         LEFT JOIN athletes a ON a.id = e.athlete_id
         WHERE e.coach_id = ?
             AND e.starts_at < ?
             AND e.ends_at > ?
             AND (e.approval_status = 'approved' OR e.athlete_id = ?)
         ORDER BY e.starts_at ASC, e.id ASC"
);
$eventsStmt->execute([
    (int)$athlete['coach_id'],
    $weekEnd->format('Y-m-d H:i:s'),
    $weekStart->format('Y-m-d H:i:s'),
    $athleteId,
]);
$events = $eventsStmt->fetchAll();

foreach ($events as &$event) {
    $event['is_mine'] = ((int)$event['athlete_id'] === $athleteId);
    $event['is_requested_by_me'] = ((int)($event['requested_by_athlete_id'] ?? 0) === $athleteId);
    $canCancelOwnership = ($event['is_mine'] || $event['is_requested_by_me']);
    $eventStartTs = strtotime((string)($event['starts_at'] ?? ''));
    $canCancelByTime = ($eventStartTs !== false && $eventStartTs > time());
    $event['can_cancel'] = ($canCancelOwnership && $canCancelByTime);
    $event['is_pending'] = (($event['approval_status'] ?? 'approved') === 'pending');
    $event['was_modified_by_coach'] = !empty($event['coach_modified_at']);
}
unset($event);

$locksStmt = $pdo->prepare(
    'SELECT id, note, starts_at, ends_at
     FROM coach_calendar_locks
     WHERE coach_id = ?
       AND starts_at < ?
       AND ends_at > ?
     ORDER BY starts_at ASC, id ASC'
);
$locksStmt->execute([
    (int)$athlete['coach_id'],
    $weekEnd->format('Y-m-d H:i:s'),
    $weekStart->format('Y-m-d H:i:s'),
]);
$locks = $locksStmt->fetchAll();

echo json_encode([
    'success' => true,
    'week_start' => $weekStart->format('Y-m-d'),
    'week_end' => $weekEnd->modify('-1 day')->format('Y-m-d'),
    'events' => $events,
    'locks' => $locks,
]);
