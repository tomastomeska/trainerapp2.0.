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

function coachDeleteHasColumn(PDO $pdo, string $table, string $column): bool
{
    $quotedColumn = $pdo->quote($column);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$quotedColumn}");
    return $stmt !== false && (bool)$stmt->fetch();
}

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

$hasBillingMonth = coachDeleteHasColumn($pdo, 'coach_calendar_events', 'billing_month');
$hasPayments = false;
try {
    $hasPaymentsStmt = $pdo->query("SHOW TABLES LIKE 'athlete_monthly_payments'");
    $hasPayments = $hasPaymentsStmt !== false && (bool)$hasPaymentsStmt->fetchColumn();
} catch (Throwable $e) {
    $hasPayments = false;
}

$paidAffectedCount = 0;
if ($hasPayments) {
    if ($deleteScope === 'future' && !empty($event['series_id'])) {
        $billingExpr = $hasBillingMonth
            ? "DATE_FORMAT(COALESCE(e.billing_month, e.starts_at), '%Y-%m-01')"
            : "DATE_FORMAT(e.starts_at, '%Y-%m-01')";

        $paidAffectedStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM coach_calendar_events e
             JOIN athlete_monthly_payments p
               ON p.coach_id = e.coach_id
              AND p.athlete_id = e.athlete_id
              AND p.status = 'paid'
              AND p.billing_month = {$billingExpr}
             WHERE e.coach_id = ?
               AND e.series_id = ?
               AND e.starts_at >= ?
               AND e.athlete_id IS NOT NULL"
        );
        $paidAffectedStmt->execute([$coachId, $event['series_id'], $event['starts_at']]);
        $paidAffectedCount = (int)$paidAffectedStmt->fetchColumn();
    } else {
        $billingMonthSql = date('Y-m-01', strtotime((string)$event['starts_at']));
        if ($hasBillingMonth) {
            $billingMonthStmt = $pdo->prepare('SELECT DATE_FORMAT(COALESCE(billing_month, starts_at), "%Y-%m-01") AS billing_month FROM coach_calendar_events WHERE id = ? LIMIT 1');
            $billingMonthStmt->execute([$eventId]);
            $billingMonthSql = (string)($billingMonthStmt->fetchColumn() ?: $billingMonthSql);
        }

        if (!empty($event['athlete_id'])) {
            $paidAffectedStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM athlete_monthly_payments
                 WHERE coach_id = ?
                   AND athlete_id = ?
                   AND billing_month = ?
                   AND status = "paid"'
            );
            $paidAffectedStmt->execute([$coachId, (int)$event['athlete_id'], $billingMonthSql]);
            $paidAffectedCount = (int)$paidAffectedStmt->fetchColumn();
        }
    }
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

    if ($paidAffectedCount > 0) {
        $body .= ' Šlo o již uhrazený termín, který aplikace automaticky započte do další fakturace jako zápočet.';
    }

    createAthleteNotification($athleteId, $subject, $body);
    if (!empty($event['athlete_email'])) {
        sendAthleteCalendarNotificationEmail((string)$event['athlete_email'], $athleteName, $subject, $body);
    }
}

echo json_encode([
    'success' => true,
    'deleted_count' => $del->rowCount(),
    'scope' => $deleteScope,
    'paid_affected_count' => $paidAffectedCount,
    'message' => $paidAffectedCount > 0
        ? 'Byl zrušen již uhrazený termín. Systém jej započte do další fakturace.'
        : 'Událost byla zrušena.',
]);
