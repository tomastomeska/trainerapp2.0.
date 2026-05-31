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

function athleteDeleteHasColumn(PDO $pdo, string $table, string $column): bool
{
    $quotedColumn = $pdo->quote($column);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$quotedColumn}");
    return $stmt !== false && (bool)$stmt->fetch();
}

$eventStmt = $pdo->prepare(
    'SELECT e.id,
            e.coach_id,
            e.athlete_id,
            e.requested_by_athlete_id,
            e.billing_month,
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

$eventStartTs = strtotime((string)$event['starts_at']);
$nowTs = time();
if ($eventStartTs !== false && $eventStartTs <= $nowTs) {
    echo json_encode([
        'success' => false,
        'error' => 'Minulé nebo právě probíhající termíny nelze rušit.',
    ]);
    exit;
}

$hasBillingMonth = athleteDeleteHasColumn($pdo, 'coach_calendar_events', 'billing_month');
$hasPayments = false;
try {
    $hasPaymentsStmt = $pdo->query("SHOW TABLES LIKE 'athlete_monthly_payments'");
    $hasPayments = $hasPaymentsStmt !== false && (bool)$hasPaymentsStmt->fetchColumn();
} catch (Throwable $e) {
    $hasPayments = false;
}

$billingMonthSql = $hasBillingMonth && !empty($event['billing_month'])
    ? date('Y-m-01', strtotime((string)$event['billing_month']))
    : date('Y-m-01', strtotime((string)$event['starts_at']));

$wasAlreadyPaid = false;
if ($hasPayments) {
    $paidStmt = $pdo->prepare(
        'SELECT id
         FROM athlete_monthly_payments
         WHERE coach_id = ?
           AND athlete_id = ?
           AND billing_month = ?
           AND status = "paid"
         LIMIT 1'
    );
    $paidStmt->execute([(int)$event['coach_id'], $athleteId, $billingMonthSql]);
    $wasAlreadyPaid = (bool)$paidStmt->fetch();
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
if ($wasAlreadyPaid) {
    $body .= ' Termín byl již uhrazen a systém jej automaticky započte jako zápočet do další fakturace.';
}
createCoachSystemMessage((int)$event['coach_id'], $subject, $body, true);

createAthleteNotification(
    $athleteId,
    'Potvrzení zrušení termínu',
    "Tvůj termín {$event['starts_at']} byl zrušen."
    . ($wasAlreadyPaid ? ' Tento termín byl již uhrazen a bude započten do další fakturace jako zápočet.' : '')
);

echo json_encode([
    'success' => true,
    'message' => "Termín byl zrušen. Trenér {$coachDisplayName} byl informován."
        . ($wasAlreadyPaid ? ' Jednalo se o již uhrazený termín, který bude započten v další fakturaci.' : ''),
    'was_paid' => $wasAlreadyPaid,
]);
