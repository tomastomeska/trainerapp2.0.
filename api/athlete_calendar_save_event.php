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
$isMakeupSession = !empty($input['is_makeup_session']) ? 1 : 0;

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

function athleteReserveTableExists(PDO $pdo, string $tableName): bool
{
    $quoted = $pdo->quote($tableName);
    $stmt = $pdo->query("SHOW TABLES LIKE {$quoted}");
    return $stmt !== false && (bool)$stmt->fetchColumn();
}

function athleteReserveColumnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $quoted = $pdo->quote($columnName);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE {$quoted}");
    return $stmt !== false && (bool)$stmt->fetch();
}

function athleteResolveAutoMakeupBillingMonth(PDO $pdo, int $coachId, int $athleteId, string $targetMonthSql): ?string
{
    if (!athleteReserveTableExists($pdo, 'athlete_monthly_payments') || !athleteReserveTableExists($pdo, 'coach_calendar_events')) {
        return null;
    }

    $hasBillingMonth = athleteReserveColumnExists($pdo, 'coach_calendar_events', 'billing_month');
    $hasSecondAthlete = athleteReserveColumnExists($pdo, 'coach_calendar_events', 'second_athlete_id');
    $hasCarryoverUsed = athleteReserveColumnExists($pdo, 'athlete_monthly_payments', 'carryover_used_sessions');

    $monthExpr = $hasBillingMonth
        ? "DATE_FORMAT(COALESCE(t.billing_month, t.starts_at), '%Y-%m-01')"
        : "DATE_FORMAT(t.starts_at, '%Y-%m-01')";
    $billingField = $hasBillingMonth ? 'billing_month' : 'NULL AS billing_month';

    if ($hasSecondAthlete) {
        $participantsSql = "
            SELECT starts_at, {$billingField}
            FROM coach_calendar_events
            WHERE coach_id = ?
              AND approval_status = 'approved'
              AND athlete_id = ?
            UNION ALL
            SELECT starts_at, {$billingField}
            FROM coach_calendar_events
            WHERE coach_id = ?
              AND approval_status = 'approved'
              AND second_athlete_id = ?
        ";
        $actualParams = [$coachId, $athleteId, $coachId, $athleteId, $targetMonthSql];
    } else {
        $participantsSql = "
            SELECT starts_at, {$billingField}
            FROM coach_calendar_events
            WHERE coach_id = ?
              AND approval_status = 'approved'
              AND athlete_id = ?
        ";
        $actualParams = [$coachId, $athleteId, $targetMonthSql];
    }

    $actualByMonthStmt = $pdo->prepare(
        "SELECT {$monthExpr} AS billing_month,
                COUNT(*) AS billed_sessions
         FROM ({$participantsSql}) t
         WHERE {$monthExpr} < ?
         GROUP BY {$monthExpr}
         ORDER BY {$monthExpr} ASC"
    );
    $actualByMonthStmt->execute($actualParams);

    $actualByMonth = [];
    foreach ($actualByMonthStmt->fetchAll() as $row) {
        $actualByMonth[(string)$row['billing_month']] = (int)$row['billed_sessions'];
    }

    $paymentStmt = $pdo->prepare(
        'SELECT billing_month, planned_sessions, ' . ($hasCarryoverUsed ? 'carryover_used_sessions' : '0 AS carryover_used_sessions') . '
         FROM athlete_monthly_payments
         WHERE coach_id = ?
           AND athlete_id = ?
           AND status = "paid"
           AND billing_month < ?
         ORDER BY billing_month ASC'
    );
    $paymentStmt->execute([$coachId, $athleteId, $targetMonthSql]);

    $balances = [];
    foreach ($paymentStmt->fetchAll() as $row) {
        $month = (string)$row['billing_month'];
        $planned = max(0, (int)($row['planned_sessions'] ?? 0));
        $actual = max(0, (int)($actualByMonth[$month] ?? 0));
        $generated = max(0, $planned - $actual);
        $used = max(0, (int)($row['carryover_used_sessions'] ?? 0));

        if ($generated > 0) {
            $balances[] = [
                'month' => $month,
                'remaining' => $generated,
            ];
        }

        while ($used > 0 && !empty($balances)) {
            $deduct = min($used, (int)$balances[0]['remaining']);
            $balances[0]['remaining'] -= $deduct;
            $used -= $deduct;

            if ((int)$balances[0]['remaining'] <= 0) {
                array_shift($balances);
            }
        }
    }

    return empty($balances) ? null : (string)$balances[0]['month'];
}

function athleteResolveOpenBillingMonth(PDO $pdo, int $coachId, int $athleteId, string $targetMonthSql): string
{
    if (!athleteReserveTableExists($pdo, 'athlete_monthly_payments')) {
        return $targetMonthSql;
    }

    $month = DateTime::createFromFormat('Y-m-d', $targetMonthSql) ?: new DateTime($targetMonthSql);
    if (!$month) {
        return $targetMonthSql;
    }

    $checkStmt = $pdo->prepare(
        'SELECT status
         FROM athlete_monthly_payments
         WHERE coach_id = ?
           AND athlete_id = ?
           AND billing_month = ?
         LIMIT 1'
    );

    for ($i = 0; $i < 24; $i++) {
        $monthSql = $month->format('Y-m-01');
        $checkStmt->execute([$coachId, $athleteId, $monthSql]);
        $status = (string)($checkStmt->fetchColumn() ?: '');
        if ($status !== 'paid') {
            return $monthSql;
        }

        $month->modify('first day of next month');
    }

    return $targetMonthSql;
}

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

if ($isMakeupSession === 1) {
    $targetMonthSql = $start->format('Y-m-01');
    $billingMonthSql = athleteResolveAutoMakeupBillingMonth($pdo, (int)$athlete['coach_id'], $athleteId, $targetMonthSql) ?: '';
    if ($billingMonthSql === '') {
        echo json_encode(['success' => false, 'error' => 'Momentálně nemáte dostupný žádný nevyužitý uhrazený trénink.']);
        exit;
    }
} else {
    $billingMonthSql = athleteResolveOpenBillingMonth($pdo, (int)$athlete['coach_id'], $athleteId, $billingMonthSql);
}

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
    VALUES (?, ?, ?, ?, NULL, ?, ?, NULL, ?, ?, ?, ?, ?)'
);
$insert->execute([
    (int)$athlete['coach_id'],
    $athleteId,
    $athleteId,
    'pending',
    $isMakeupSession,
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
