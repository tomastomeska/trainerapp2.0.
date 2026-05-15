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

$coachId = (int)getCurrentCoachId();
$pdo = getDB();

function generateUuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    $hex = bin2hex($data);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function parseRepeatUntil(DateTime $start, string $repeatMode, string $repeatUntilRaw): ?DateTime
{
    if ($repeatMode === 'none') {
        return null;
    }

    if ($repeatMode === 'weekly_until_date') {
        $until = DateTime::createFromFormat('Y-m-d', $repeatUntilRaw);
        if (!$until) {
            return null;
        }
        $until->setTime(23, 59, 59);
        return $until;
    }

    if ($repeatMode === 'weekly_end_of_next_month') {
        $until = clone $start;
        $until->modify('last day of next month')->setTime(23, 59, 59);
        return $until;
    }

    if ($repeatMode === 'weekly_end_of_year') {
        $until = clone $start;
        $until->setDate((int)$start->format('Y'), 12, 31)->setTime(23, 59, 59);
        return $until;
    }

    return null;
}

$eventId = (int)($input['event_id'] ?? 0);
$athleteId = (int)($input['athlete_id'] ?? 0);
$customTitle = trim((string)($input['custom_title'] ?? ''));
$location = trim((string)($input['location'] ?? ''));
$startsAtRaw = trim((string)($input['starts_at'] ?? ''));
$repeatMode = trim((string)($input['repeat_mode'] ?? 'none'));
$repeatUntilRaw = trim((string)($input['repeat_until'] ?? ''));
$colorKey = trim((string)($input['color_key'] ?? 'blue'));

$allowedRepeatModes = ['none', 'weekly_until_date', 'weekly_end_of_next_month', 'weekly_end_of_year'];
if (!in_array($repeatMode, $allowedRepeatModes, true)) {
    $repeatMode = 'none';
}

$allowedColorKeys = ['blue', 'green', 'red', 'orange', 'purple', 'gray'];
if (!in_array($colorKey, $allowedColorKeys, true)) {
    $colorKey = 'blue';
}

$start = DateTime::createFromFormat('Y-m-d\TH:i', $startsAtRaw);
if (!$start) {
    echo json_encode(['success' => false, 'error' => 'Neplatný začátek tréninku']);
    exit;
}

$end = clone $start;
$end->modify('+60 minutes');

if ($athleteId <= 0 && $customTitle === '') {
    echo json_encode(['success' => false, 'error' => 'Vyberte sportovce nebo vyplňte vlastní název']);
    exit;
}

if ($athleteId > 0) {
    $athleteStmt = $pdo->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ?');
    $athleteStmt->execute([$athleteId, $coachId]);
    if (!$athleteStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Sportovec nepatří tomuto trenérovi']);
        exit;
    }
} else {
    $athleteId = null;
}

if ($customTitle !== '') {
    $customTitle = mb_substr($customTitle, 0, 140, 'UTF-8');
} else {
    $customTitle = null;
}
if ($location !== '') {
    $location = mb_substr($location, 0, 255, 'UTF-8');
} else {
    $location = null;
}

if ($eventId > 0) {
    $ownerStmt = $pdo->prepare('SELECT id FROM coach_calendar_events WHERE id = ? AND coach_id = ?');
    $ownerStmt->execute([$eventId, $coachId]);
    if (!$ownerStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Událost nenalezena']);
        exit;
    }
}

$startSql = $start->format('Y-m-d H:i:s');
$endSql = $end->format('Y-m-d H:i:s');

if ($eventId > 0) {
    $lockStmt = $pdo->prepare(
        'SELECT id
         FROM coach_calendar_locks
         WHERE coach_id = ?
           AND starts_at < ?
           AND ends_at > ?
         LIMIT 1'
    );
    $lockStmt->execute([$coachId, $endSql, $startSql]);
    if ($lockStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Čas je uzamčený. Nejprve upravte uzamčení.']);
        exit;
    }

    $overlapStmt = $pdo->prepare(
        'SELECT id
         FROM coach_calendar_events
         WHERE coach_id = ?
           AND starts_at < ?
           AND ends_at > ?
           AND id <> ?
         LIMIT 1'
    );
    $overlapStmt->execute([$coachId, $endSql, $startSql, $eventId]);
    if ($overlapStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'V tomto čase už máte jiný trénink']);
        exit;
    }

    $upd = $pdo->prepare(
        'UPDATE coach_calendar_events
         SET athlete_id = ?,
             color_key = ?,
             custom_title = ?,
             location = ?,
             starts_at = ?,
             ends_at = ?
         WHERE id = ? AND coach_id = ?'
    );
    $upd->execute([$athleteId, $colorKey, $customTitle, $location, $startSql, $endSql, $eventId, $coachId]);

    echo json_encode(['success' => true, 'id' => $eventId, 'mode' => 'updated']);
    exit;
}

$repeatUntil = parseRepeatUntil($start, $repeatMode, $repeatUntilRaw);
if ($repeatMode !== 'none' && !$repeatUntil) {
    echo json_encode(['success' => false, 'error' => 'Neplatné datum opakování']);
    exit;
}

$occurrences = [];
$cursor = clone $start;
$maxOccurrences = 260;

while (true) {
    $occurrences[] = clone $cursor;

    if ($repeatMode === 'none') {
        break;
    }

    if (count($occurrences) >= $maxOccurrences) {
        break;
    }

    $cursor->modify('+7 days');
    if ($repeatUntil instanceof DateTime && $cursor > $repeatUntil) {
        break;
    }
}

$lockStmt = $pdo->prepare(
    'SELECT id
     FROM coach_calendar_locks
     WHERE coach_id = ?
       AND starts_at < ?
       AND ends_at > ?
     LIMIT 1'
);

$overlapStmt = $pdo->prepare(
    'SELECT id
     FROM coach_calendar_events
     WHERE coach_id = ?
       AND starts_at < ?
       AND ends_at > ?
     LIMIT 1'
);

$insertStmt = $pdo->prepare(
    'INSERT INTO coach_calendar_events (coach_id, athlete_id, series_id, color_key, custom_title, location, starts_at, ends_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

$seriesId = $repeatMode === 'none' ? null : generateUuidV4();
$createdIds = [];

try {
    $pdo->beginTransaction();

    foreach ($occurrences as $occurrenceStart) {
        $occurrenceEnd = clone $occurrenceStart;
        $occurrenceEnd->modify('+60 minutes');

        $occurrenceStartSql = $occurrenceStart->format('Y-m-d H:i:s');
        $occurrenceEndSql = $occurrenceEnd->format('Y-m-d H:i:s');

        $lockStmt->execute([$coachId, $occurrenceEndSql, $occurrenceStartSql]);
        if ($lockStmt->fetch()) {
            throw new RuntimeException('Čas je uzamčený: ' . $occurrenceStart->format('d.m.Y H:i'));
        }

        $overlapStmt->execute([$coachId, $occurrenceEndSql, $occurrenceStartSql]);
        if ($overlapStmt->fetch()) {
            throw new RuntimeException('V tomto čase už máte trénink: ' . $occurrenceStart->format('d.m.Y H:i'));
        }

        $insertStmt->execute([
            $coachId,
            $athleteId,
            $seriesId,
            $colorKey,
            $customTitle,
            $location,
            $occurrenceStartSql,
            $occurrenceEndSql,
        ]);
        $createdIds[] = (int)$pdo->lastInsertId();
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

echo json_encode([
    'success' => true,
    'id' => $createdIds[0] ?? 0,
    'created_count' => count($createdIds),
    'mode' => $repeatMode === 'none' ? 'created' : 'created_series',
]);
