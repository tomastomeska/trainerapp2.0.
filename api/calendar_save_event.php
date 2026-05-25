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

function normalizeBillingMonth(DateTime $start, string $billingMonthRaw): string
{
    $billingMonthRaw = trim($billingMonthRaw);
    if ($billingMonthRaw !== '') {
        if (preg_match('/^\d{4}-\d{2}$/', $billingMonthRaw) === 1) {
            return $billingMonthRaw . '-01';
        }

        $billingDate = DateTime::createFromFormat('Y-m-d', $billingMonthRaw);
        if ($billingDate) {
            return $billingDate->format('Y-m-01');
        }
    }

    return $start->format('Y-m-01');
}

$eventId = (int)($input['event_id'] ?? 0);
$athleteId = (int)($input['athlete_id'] ?? 0);
$customTitle = trim((string)($input['custom_title'] ?? ''));
$location = trim((string)($input['location'] ?? ''));
$startsAtRaw = trim((string)($input['starts_at'] ?? ''));
$repeatMode = trim((string)($input['repeat_mode'] ?? 'none'));
$repeatUntilRaw = trim((string)($input['repeat_until'] ?? ''));
$colorKey = trim((string)($input['color_key'] ?? 'blue'));
$approvalAction = trim((string)($input['approval_action'] ?? ''));
$isMakeupSession = !empty($input['is_makeup_session']);
$billingMonthRaw = trim((string)($input['billing_month'] ?? ''));

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
    $ownerStmt = $pdo->prepare(
        'SELECT e.id,
                e.athlete_id,
                e.requested_by_athlete_id,
                e.approval_status,
                e.coach_modified_at,
                e.is_makeup_session,
                e.billing_month,
                e.custom_title,
                e.location,
                e.starts_at,
                e.ends_at,
                a.email AS athlete_email,
                a.first_name,
                a.last_name
         FROM coach_calendar_events e
         LEFT JOIN athletes a ON a.id = e.athlete_id
         WHERE e.id = ? AND e.coach_id = ?'
    );
    $ownerStmt->execute([$eventId, $coachId]);
    $existingEvent = $ownerStmt->fetch();
    if (!$existingEvent) {
        echo json_encode(['success' => false, 'error' => 'Událost nenalezena']);
        exit;
    }
}

$startSql = $start->format('Y-m-d H:i:s');
$endSql = $end->format('Y-m-d H:i:s');
$billingMonthSql = $isMakeupSession ? normalizeBillingMonth($start, $billingMonthRaw) : $start->format('Y-m-01');

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
             approval_status = ?,
             coach_modified_at = ?,
             is_makeup_session = ?,
             billing_month = ?,
             color_key = ?,
             custom_title = ?,
             location = ?,
             starts_at = ?,
             ends_at = ?
         WHERE id = ? AND coach_id = ?'
    );

    $oldStart = (string)$existingEvent['starts_at'];
    $oldEnd = (string)$existingEvent['ends_at'];
    $oldLocation = (string)($existingEvent['location'] ?? '');
    $oldTitle = (string)($existingEvent['custom_title'] ?? '');
    $oldIsMakeup = (int)($existingEvent['is_makeup_session'] ?? 0);
    $oldBillingMonth = (string)($existingEvent['billing_month'] ?? '');
    $changed = ($oldStart !== $startSql)
        || ($oldEnd !== $endSql)
        || ($oldLocation !== (string)$location)
        || ($oldTitle !== (string)$customTitle)
        || ($oldIsMakeup !== (int)$isMakeupSession)
        || ($oldBillingMonth !== $billingMonthSql);
    $isPendingRequest = (($existingEvent['approval_status'] ?? 'approved') === 'pending') && !empty($existingEvent['requested_by_athlete_id']);
    $nextApprovalStatus = ($approvalAction === 'approve' || $isPendingRequest) ? 'approved' : (string)($existingEvent['approval_status'] ?? 'approved');
    $coachModifiedAt = $changed ? date('Y-m-d H:i:s') : ($existingEvent['coach_modified_at'] ?: null);

    $upd->execute([$athleteId, $nextApprovalStatus, $coachModifiedAt, (int)$isMakeupSession, $billingMonthSql, $colorKey, $customTitle, $location, $startSql, $endSql, $eventId, $coachId]);

    if (!empty($existingEvent['athlete_id'])) {
        if ($changed || ($approvalAction === 'approve' && $isPendingRequest)) {
            $athleteEventId = (int)$existingEvent['athlete_id'];
            $athleteName = trim((string)$existingEvent['first_name'] . ' ' . (string)$existingEvent['last_name']);
            $newStartLabel = date('d.m.Y H:i', strtotime($startSql));
            if ($changed) {
                $subject = 'Trenér upravil termín tréninku';
                $body = 'Trenér upravil váš trénink. Nový termín: ' . $newStartLabel . '.';
                if ($location) {
                    $body .= ' Místo: ' . $location . '.';
                }
                if ($isMakeupSession) {
                    $body .= ' Termín je veden jako náhrada hrazená v měsíci ' . date('m/Y', strtotime($billingMonthSql)) . '.';
                }
            } else {
                $subject = 'Trénink byl schválen';
                $body = 'Trenér schválil váš termín ' . $newStartLabel . '.';
                if ($location) {
                    $body .= ' Místo: ' . $location . '.';
                }
            }

            createAthleteNotification($athleteEventId, $subject, $body);
            if (!empty($existingEvent['athlete_email'])) {
                sendAthleteCalendarNotificationEmail((string)$existingEvent['athlete_email'], $athleteName, $subject, $body);
            }
        }
    }

    echo json_encode(['success' => true, 'id' => $eventId, 'mode' => 'updated', 'approval_status' => $nextApprovalStatus]);
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
    'INSERT INTO coach_calendar_events (coach_id, athlete_id, requested_by_athlete_id, approval_status, coach_modified_at, is_makeup_session, billing_month, series_id, color_key, custom_title, location, starts_at, ends_at)
     VALUES (?, ?, NULL, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
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

        $occurrenceBillingMonthSql = $isMakeupSession ? $billingMonthSql : $occurrenceStart->format('Y-m-01');

        $insertStmt->execute([
            $coachId,
            $athleteId,
            'approved',
            (int)$isMakeupSession,
            $occurrenceBillingMonthSql,
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
