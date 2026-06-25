<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

if (!function_exists('getAthleteWeightLogById')) {
    function getAthleteWeightLogById(int $logId, int $athleteId = 0): ?array {
        $pdo = getDB();
        $sql = 'SELECT * FROM athlete_weight_logs WHERE id = ?';
        $params = [$logId];

        if ($athleteId > 0) {
            $sql .= ' AND athlete_id = ?';
            $params[] = $athleteId;
        }

        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() ?: null;
    }
}

if (!function_exists('updateAthleteWeightLog')) {
    function updateAthleteWeightLog(int $logId, int $athleteId, string $measuredAt, float $weightKg): bool {
        if (!getAthleteWeightLogById($logId, $athleteId)) {
            return false;
        }

        $pdo = getDB();
        $stmt = $pdo->prepare(
            'UPDATE athlete_weight_logs
             SET measured_at = ?, weight_kg = ?
             WHERE id = ? AND athlete_id = ?'
        );

        return $stmt->execute([$measuredAt, $weightKg, $logId, $athleteId]);
    }
}

if (!function_exists('deleteAthleteWeightLog')) {
    function deleteAthleteWeightLog(int $logId, int $athleteId): bool {
        if (!getAthleteWeightLogById($logId, $athleteId)) {
            return false;
        }

        $pdo = getDB();
        $stmt = $pdo->prepare('DELETE FROM athlete_weight_logs WHERE id = ? AND athlete_id = ?');

        return $stmt->execute([$logId, $athleteId]);
    }
}

if (!function_exists('athleteDashboardPaymentColumnExists')) {
    function athleteDashboardPaymentColumnExists(PDO $pdo, string $tableName, string $columnName): bool {
        $quotedColumn = $pdo->quote($columnName);
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE {$quotedColumn}");
        return $stmt !== false && (bool)$stmt->fetch();
    }
}

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();

$athleteStmt = $pdo->prepare(
    'SELECT a.*, c.id AS coach_id, c.name AS coach_name, c.username AS coach_username
     FROM athletes a
     JOIN coaches c ON c.id = a.coach_id
     WHERE a.id = ?
     LIMIT 1'
);
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();

if (!$athlete) {
    session_destroy();
    redirect(BASE_URL . '/login.php');
}

$supportBankAccount = trim(getAppSetting('support_bank_account', ''));
$supportContributorName = trim((string)($athlete['first_name'] . ' ' . $athlete['last_name']));
if ($supportContributorName === '') {
    $supportContributorName = 'sportovec';
}
$supportBankAccountForQr = accountForSpd($supportBankAccount);
$supportQrNote = paymentAsciiText('Podpora TrainerApp - ' . $supportContributorName);

$unreadInboxCount = 0;
try {
    $unreadStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM athlete_notifications
         WHERE athlete_id = ?
           AND read_at IS NULL'
    );
    $unreadStmt->execute([$athleteId]);
    $unreadInboxCount = (int)$unreadStmt->fetchColumn();
} catch (Throwable $e) {
    $unreadInboxCount = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_dashboard.php');
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_weight' || $action === 'update_weight') {
        $weightInput = str_replace(',', '.', trim((string)($_POST['weight_kg'] ?? '')));
        $measuredAt = preg_replace('/[^0-9\-]/', '', (string)($_POST['measured_at'] ?? date('Y-m-d')));
        $weightKg = is_numeric($weightInput) ? (float)$weightInput : 0.0;
        $weightLogId = (int)($_POST['weight_log_id'] ?? 0);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $measuredAt)) {
            flash('danger', 'Zadejte platné datum vážení.');
        } elseif ($weightKg < 20 || $weightKg > 400) {
            flash('danger', 'Zadejte platnou hmotnost v kg.');
        } elseif ($action === 'update_weight' && $weightLogId <= 0) {
            flash('danger', 'Vybraný záznam hmotnosti nebyl nalezen.');
        } else {
            if ($action === 'save_weight') {
                addAthleteWeightLog($athleteId, $measuredAt, $weightKg, 'athlete_link', null, null);

                flash('success', 'Hmotnost byla uložena.');
            } elseif (updateAthleteWeightLog($weightLogId, $athleteId, $measuredAt, $weightKg)) {
                flash('success', 'Záznam hmotnosti byl upraven.');
            } else {
                flash('danger', 'Záznam hmotnosti se nepodařilo upravit.');
            }
        }

        redirect(BASE_URL . '/athlete_dashboard.php');
    }

    if ($action === 'delete_weight') {
        $weightLogId = (int)($_POST['weight_log_id'] ?? 0);

        if ($weightLogId <= 0) {
            flash('danger', 'Vybraný záznam hmotnosti nebyl nalezen.');
        } elseif (deleteAthleteWeightLog($weightLogId, $athleteId)) {
            flash('success', 'Záznam hmotnosti byl smazán.');
        } else {
            flash('danger', 'Záznam hmotnosti se nepodařilo smazat.');
        }

        redirect(BASE_URL . '/athlete_dashboard.php');
    }
}

$sessionsStmt = $pdo->prepare(
    'SELECT ts.id, ts.started_at, ts.completed_at, ts.location, ws.name AS set_name
     FROM training_sessions ts
     JOIN workout_sets ws ON ws.id = ts.workout_set_id
     WHERE ts.athlete_id = ?
       AND ts.deleted_by_coach_at IS NULL
     ORDER BY ts.started_at DESC
     LIMIT 120'
);
$sessionsStmt->execute([$athleteId]);
$sessions = $sessionsStmt->fetchAll();

$weightHistory = getAthleteWeightHistory($athleteId, 200);
usort($weightHistory, static function (array $a, array $b): int {
    $dateCompare = strcmp((string)($b['measured_at'] ?? ''), (string)($a['measured_at'] ?? ''));
    if ($dateCompare !== 0) {
        return $dateCompare;
    }

    return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
});
$weightStats = getAthleteWeightStats($athleteId);
$editWeightLogId = intParam($_GET, 'edit_weight');
$editingWeightLog = $editWeightLogId > 0 ? getAthleteWeightLogById($editWeightLogId, $athleteId) : null;
$weightFormAction = $editingWeightLog ? 'update_weight' : 'save_weight';
$weightFormDate = $editingWeightLog['measured_at'] ?? date('Y-m-d');
$weightFormValue = $editingWeightLog['weight_kg'] ?? '';
$weightPreviewLimit = 5;
$weightVisibleRows = array_slice($weightHistory, 0, $weightPreviewLimit);
$weightCollapsedRows = array_slice($weightHistory, $weightPreviewLimit);
$weightShouldExpandAll = $editingWeightLog !== null;
$trainingPreviewLimit = 5;
$trainingVisibleRows = array_slice($sessions, 0, $trainingPreviewLimit);
$trainingCollapsedRows = array_slice($sessions, $trainingPreviewLimit);

$coachDisplayName = trim((string)($athlete['coach_name'] ?: $athlete['coach_username']));
$coachLastNameParts = preg_split('/\s+/u', $coachDisplayName) ?: [];
$coachLastName = trim((string)end($coachLastNameParts));
if ($coachLastName === '') {
    $coachLastName = 'Trener';
}

$hasBillingMonth = athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_events', 'billing_month');
$hasIsMakeup = athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_events', 'is_makeup_session');
$hasSecondAthlete = athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_events', 'second_athlete_id');
$hasCoachBankAccount = athleteDashboardPaymentColumnExists($pdo, 'coaches', 'bank_account');
$hasCarryoverUsed = athleteDashboardPaymentColumnExists($pdo, 'athlete_monthly_payments', 'carryover_used_sessions');
$hasPairedTrainingRate = athleteDashboardPaymentColumnExists($pdo, 'athletes', 'paired_training_rate');

        $billingSelect = "DATE_FORMAT(starts_at, '%Y-%m-01')";
        $billingFilter = '1=1';
        $transferredExpr = $hasBillingMonth
            ? "SUM(CASE WHEN DATE_FORMAT(starts_at, '%Y-%m-01') <> DATE_FORMAT(billing_month, '%Y-%m-01') THEN 1 ELSE 0 END)"
            : '0';
        $makeupExpr = $hasIsMakeup ? 'SUM(CASE WHEN is_makeup_session = 1 THEN 1 ELSE 0 END)' : '0';

        $statsSql = "
            SELECT t.billing_month,
                   COUNT(*) AS billed_sessions,
                   SUM(CASE WHEN t.is_paired = 1 THEN 1 ELSE 0 END) AS paired_sessions,
                   SUM(CASE WHEN t.is_paired = 0 THEN 1 ELSE 0 END) AS single_sessions,
                   {$makeupExpr} AS makeup_sessions,
                   {$transferredExpr} AS transferred_sessions
            FROM (
                SELECT {$billingSelect} AS billing_month,
                       starts_at,
                       " . ($hasIsMakeup ? 'is_makeup_session' : '0') . " AS is_makeup_session,
                       " . ($hasSecondAthlete ? 'CASE WHEN second_athlete_id IS NOT NULL THEN 1 ELSE 0 END' : '0') . " AS is_paired
                FROM coach_calendar_events
                WHERE approval_status = 'approved'
                  AND athlete_id = ?
                  AND {$billingFilter}
        " . ($hasSecondAthlete ? "
                UNION ALL
                SELECT {$billingSelect} AS billing_month,
                       starts_at,
                       " . ($hasIsMakeup ? 'is_makeup_session' : '0') . " AS is_makeup_session,
                       1 AS is_paired
                FROM coach_calendar_events
                WHERE approval_status = 'approved'
                  AND second_athlete_id = ?
                  AND {$billingFilter}
        " : '') . "
            ) t
            GROUP BY t.billing_month
            ORDER BY t.billing_month DESC
        ";

        $statsStmt = $pdo->prepare($statsSql);
        if ($hasSecondAthlete) {
            $statsStmt->execute([$athleteId, $athleteId]);
        } else {
            $statsStmt->execute([$athleteId]);
        }
        $statsRows = $statsStmt->fetchAll();

        $paymentRows = [];
        try {
            $paymentStmt = $pdo->prepare(
                'SELECT billing_month, session_rate, planned_sessions, '
                . ($hasCarryoverUsed ? 'carryover_used_sessions' : '0 AS carryover_used_sessions') . ', billed_amount, status, paid_at
                 FROM athlete_monthly_payments
                 WHERE athlete_id = ?
                 ORDER BY billing_month DESC'
            );
            $paymentStmt->execute([$athleteId]);
            $paymentRows = $paymentStmt->fetchAll();
        } catch (Throwable $e) {
            $paymentRows = [];
        }

        $paymentsByMonth = [];
        foreach ($paymentRows as $row) {
            $paymentsByMonth[(string)$row['billing_month']] = $row;
        }

        $rowsByMonth = [];
        foreach ($statsRows as $row) {
            $month = (string)$row['billing_month'];
            $rowsByMonth[$month] = [
                'billing_month' => $month,
                'billed_sessions' => (int)$row['billed_sessions'],
                'paired_sessions' => (int)($row['paired_sessions'] ?? 0),
                'single_sessions' => (int)($row['single_sessions'] ?? 0),
                'makeup_sessions' => (int)$row['makeup_sessions'],
                'transferred_sessions' => (int)$row['transferred_sessions'],
            ];
        }

        foreach ($paymentsByMonth as $month => $payment) {
            if (!isset($rowsByMonth[$month])) {
                $rowsByMonth[$month] = [
                    'billing_month' => $month,
                    'billed_sessions' => (int)($payment['planned_sessions'] ?? 0),
                    'paired_sessions' => 0,
                    'single_sessions' => (int)($payment['planned_sessions'] ?? 0),
                    'makeup_sessions' => 0,
                    'transferred_sessions' => 0,
                ];
            }
        }

        $releasesByMonth = [];
        try {
            $releaseStmt = $pdo->prepare('SELECT billing_month, status FROM coach_billing_months WHERE coach_id = ?');
            $releaseStmt->execute([(int)$athlete['coach_id']]);
            foreach ($releaseStmt->fetchAll() as $releaseRow) {
                $releasesByMonth[(string)$releaseRow['billing_month']] = (string)($releaseRow['status'] ?? 'draft');
            }
        } catch (Throwable $e) {
            $releasesByMonth = [];
        }

        try {
            $releaseAthleteStmt = $pdo->prepare(
                'SELECT billing_month, status
                 FROM coach_billing_month_athletes
                 WHERE coach_id = ? AND athlete_id = ?'
            );
            $releaseAthleteStmt->execute([(int)$athlete['coach_id'], $athleteId]);
            foreach ($releaseAthleteStmt->fetchAll() as $releaseAthleteRow) {
                if ((string)($releaseAthleteRow['status'] ?? 'draft') === 'released') {
                    $releasesByMonth[(string)$releaseAthleteRow['billing_month']] = 'released';
                }
            }
        } catch (Throwable $e) {
            // Tabulka s individuálním otevřením nemusí v některých starších instalacích existovat.
        }

        krsort($rowsByMonth);
        $monthsAsc = array_keys($rowsByMonth);
        sort($monthsAsc);
        $outstanding = 0;
        $outstandingBeforeByMonth = [];
        foreach ($monthsAsc as $monthKey) {
            $outstandingBeforeByMonth[$monthKey] = $outstanding;
            $paidMonthRow = $paymentsByMonth[$monthKey] ?? null;
            if ($paidMonthRow && (($paidMonthRow['status'] ?? '') === 'paid')) {
                $planned = max(0, (int)($paidMonthRow['planned_sessions'] ?? 0));
                $actual = max(0, (int)($rowsByMonth[$monthKey]['billed_sessions'] ?? 0));
                $generated = max(0, $planned - $actual);
                $used = max(0, (int)($paidMonthRow['carryover_used_sessions'] ?? 0));
                $outstanding += $generated;
                $outstanding = max(0, $outstanding - $used);
            }
        }

        $rate = isset($athlete['training_rate']) && $athlete['training_rate'] !== null ? (float)$athlete['training_rate'] : null;
        $pairedRate = ($hasPairedTrainingRate && array_key_exists('paired_training_rate', $athlete) && $athlete['paired_training_rate'] !== null)
            ? (float)$athlete['paired_training_rate']
            : $rate;
        $paymentRowsForView = [];

        foreach ($rowsByMonth as $month => $stats) {
            $payment = $paymentsByMonth[$month] ?? null;
            $paymentStatus = (string)($payment['status'] ?? '');
            $rawSessions = (int)$stats['billed_sessions'];
            $rawSingleSessions = (int)($stats['single_sessions'] ?? $rawSessions);
            $rawPairedSessions = (int)($stats['paired_sessions'] ?? 0);
            $carryoverApplied = min((int)($outstandingBeforeByMonth[$month] ?? 0), $rawSessions);
            $billableSingle = max(0, $rawSingleSessions - $carryoverApplied);
            $remainingCarryover = max(0, $carryoverApplied - $rawSingleSessions);
            $billablePaired = max(0, $rawPairedSessions - $remainingCarryover);
            $billableSessions = $billableSingle + $billablePaired;
            $amount = ($rate !== null && $pairedRate !== null)
                ? (($billableSingle * $rate) + ($billablePaired * $pairedRate))
                : null;
            $displayAmount = ($payment && isset($payment['billed_amount']) && $payment['billed_amount'] !== null)
                ? (float)$payment['billed_amount']
                : $amount;
            $note = paymentAsciiText($coachLastName . ' ' . date('m/Y', strtotime($month)));
            $isReleased = (($releasesByMonth[$month] ?? 'draft') === 'released') || $paymentStatus === 'pending' || $paymentStatus === 'paid';
            $isPaid = $paymentStatus === 'paid';

            $paymentRowsForView[] = [
                'billing_month' => $month,
                'month_label' => date('m/Y', strtotime($month)),
                'stats' => $stats,
                'payment' => $payment,
                'amount' => $amount,
                'display_amount' => $displayAmount,
                'billable_sessions' => $billableSessions,
                'billable_single_sessions' => $billableSingle,
                'billable_paired_sessions' => $billablePaired,
                'paired_sessions' => $rawPairedSessions,
                'single_sessions' => $rawSingleSessions,
                'carryover_applied' => $carryoverApplied,
                'note' => $note,
                'is_released' => $isReleased,
                'is_pending' => $paymentStatus === 'pending',
                'is_paid' => $isPaid,
            ];
        }

                $paymentRowsForView = array_slice($paymentRowsForView, 0, 3);

                $upcomingPlannedCount = 0;
                $nearestPlannedTraining = null;
                try {
                    $upcomingCountStmt = $pdo->prepare(
                        "SELECT COUNT(*)
                         FROM coach_calendar_events
                         WHERE athlete_id = ?
                           AND starts_at >= NOW()
                           AND approval_status IN ('approved', 'pending')"
                    );
                    $upcomingCountStmt->execute([$athleteId]);
                    $upcomingPlannedCount = (int)$upcomingCountStmt->fetchColumn();

                    $nearestTrainingStmt = $pdo->prepare(
                        "SELECT starts_at, ends_at, location, custom_title, approval_status
                         FROM coach_calendar_events
                         WHERE athlete_id = ?
                           AND starts_at >= NOW()
                           AND approval_status IN ('approved', 'pending')
                         ORDER BY starts_at ASC
                         LIMIT 1"
                    );
                    $nearestTrainingStmt->execute([$athleteId]);
                    $nearestPlannedTraining = $nearestTrainingStmt->fetch() ?: null;
                } catch (Throwable $e) {
                    $upcomingPlannedCount = 0;
                    $nearestPlannedTraining = null;
                }

renderAthleteHeader('Profil sportovce');
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-user me-2 text-warning"></i>Můj profil</h2>
    <div class="d-flex gap-2 flex-wrap"></div>
</div>

<div class="dashboard-quick-tiles mb-3">
    <a href="<?= BASE_URL ?>/athlete_zpravy.php" class="quick-tile quick-tile-danger">
        <span class="quick-tile__label"><i class="fas fa-envelope me-1"></i>Zprávy</span>
        <span class="quick-tile__value"><?= (int)$unreadInboxCount ?></span>
    </a>
    <a href="<?= BASE_URL ?>/athlete_mealplans.php" class="quick-tile quick-tile-success">
        <span class="quick-tile__label"><i class="fas fa-utensils me-1"></i>Jídelníčky</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a href="<?= BASE_URL ?>/athlete_graphs.php" class="quick-tile quick-tile-info">
        <span class="quick-tile__label"><i class="fas fa-chart-line me-1"></i>Grafy</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a href="<?= BASE_URL ?>/athlete_calendar.php" class="quick-tile quick-tile-warning">
        <span class="quick-tile__label"><i class="fas fa-calendar-alt me-1"></i>Kalendář</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a href="<?= BASE_URL ?>/athlete_change_password.php" class="quick-tile quick-tile-muted">
        <span class="quick-tile__label"><i class="fas fa-key me-1"></i>Heslo</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
</div>

<div class="border rounded-3 bg-light px-3 py-2 mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div class="text-muted small">
        <i class="fas fa-heart me-1 text-secondary"></i>
        Pokud chcete podpořit provoz aplikace, je tu i dobrovolná možnost příspěvku.
    </div>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#supportContributionModal">
        Zobrazit možnosti
    </button>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-dark text-white"><i class="fas fa-id-card me-2"></i>Informace</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted fw-semibold" style="width:45%">Jméno</td><td><?= h(trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name'])) ?></td></tr>
                    <tr><td class="text-muted fw-semibold">E-mail</td><td><?= h((string)$athlete['email']) ?></td></tr>
                    <tr><td class="text-muted fw-semibold">Trenér</td><td><?= h((string)($athlete['coach_name'] ?: $athlete['coach_username'])) ?></td></tr>
                    <tr><td class="text-muted fw-semibold">Datum narození</td><td><?= !empty($athlete['birth_date']) ? formatDate((string)$athlete['birth_date']) : '–' ?></td></tr>
                    <tr><td class="text-muted fw-semibold">Aktuální váha</td><td><?= $weightStats['current_weight'] !== null ? number_format((float)$weightStats['current_weight'], 1, ',', '') . ' kg' : '–' ?></td></tr>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-primary text-white"><i class="fas fa-weight-scale me-2"></i>Zaznamenat aktuální hmotnost</div>
            <div class="card-body">
                <form method="post" class="row g-3 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= h($weightFormAction) ?>">
                    <?php if ($editingWeightLog): ?>
                    <input type="hidden" name="weight_log_id" value="<?= (int)$editingWeightLog['id'] ?>">
                    <?php endif; ?>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Datum vážení</label>
                        <input type="date" name="measured_at" class="form-control" value="<?= h((string)$weightFormDate) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Hmotnost (kg)</label>
                        <input type="number" name="weight_kg" class="form-control" min="20" max="400" step="0.1" value="<?= h((string)$weightFormValue) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary w-100 fw-semibold"><?= $editingWeightLog ? 'Uložit změny' : 'Uložit' ?></button>
                            <?php if ($editingWeightLog): ?>
                            <a href="<?= BASE_URL ?>/athlete_dashboard.php#weight-history" class="btn btn-outline-secondary">Zrušit</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
                <small class="text-muted d-block mt-2">Svou historii hmotnosti můžete průběžně doplňovat, upravovat i mazat.</small>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="weight-history">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fas fa-clock-rotate-left me-2"></i>Historie hmotnosti</span>
        <span class="badge bg-light text-dark"><?= count($weightHistory) ?> záznamů</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($weightHistory)): ?>
        <div class="text-center text-muted py-4">Zatím tu není žádný záznam hmotnosti.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                <tr>
                    <th>Datum</th>
                    <th>Hmotnost</th>
                    <th>Zdroj</th>
                    <th class="text-end">Akce</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($weightVisibleRows as $weightRow): ?>
                    <?php
                        $sourceLabel = 'Ruční záznam';
                        if (($weightRow['source'] ?? '') === 'coach') {
                            $sourceLabel = 'Trenér';
                        } elseif (($weightRow['source'] ?? '') === 'athlete_link') {
                            $sourceLabel = 'Sportovec';
                        }
                    ?>
                <tr class="<?= $editingWeightLog && (int)$editingWeightLog['id'] === (int)$weightRow['id'] ? 'table-warning' : '' ?>">
                    <td><?= formatDate((string)$weightRow['measured_at']) ?></td>
                    <td><strong><?= number_format((float)$weightRow['weight_kg'], 1, ',', '') ?> kg</strong></td>
                    <td><span class="badge bg-secondary"><?= h($sourceLabel) ?></span></td>
                    <td class="text-end">
                        <a href="<?= BASE_URL ?>/athlete_dashboard.php?edit_weight=<?= (int)$weightRow['id'] ?>#weight-history" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-pen me-1"></i>Upravit
                        </a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Opravdu smazat tento záznam hmotnosti?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_weight">
                            <input type="hidden" name="weight_log_id" value="<?= (int)$weightRow['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash me-1"></i>Smazat
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if (!empty($weightCollapsedRows)): ?>
                <tbody id="athleteWeightHistoryCollapse" class="collapse <?= $weightShouldExpandAll ? 'show' : '' ?>">
                <?php foreach ($weightCollapsedRows as $weightRow): ?>
                    <?php
                        $sourceLabel = 'Ruční záznam';
                        if (($weightRow['source'] ?? '') === 'coach') {
                            $sourceLabel = 'Trenér';
                        } elseif (($weightRow['source'] ?? '') === 'athlete_link') {
                            $sourceLabel = 'Sportovec';
                        }
                    ?>
                <tr class="<?= $editingWeightLog && (int)$editingWeightLog['id'] === (int)$weightRow['id'] ? 'table-warning' : '' ?>">
                    <td><?= formatDate((string)$weightRow['measured_at']) ?></td>
                    <td><strong><?= number_format((float)$weightRow['weight_kg'], 1, ',', '') ?> kg</strong></td>
                    <td><span class="badge bg-secondary"><?= h($sourceLabel) ?></span></td>
                    <td class="text-end">
                        <a href="<?= BASE_URL ?>/athlete_dashboard.php?edit_weight=<?= (int)$weightRow['id'] ?>#weight-history" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-pen me-1"></i>Upravit
                        </a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Opravdu smazat tento záznam hmotnosti?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_weight">
                            <input type="hidden" name="weight_log_id" value="<?= (int)$weightRow['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash me-1"></i>Smazat
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <?php endif; ?>
            </table>
        </div>
        <?php if (!empty($weightCollapsedRows)): ?>
        <div class="border-top p-3 text-center bg-light">
            <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#athleteWeightHistoryCollapse" aria-expanded="<?= $weightShouldExpandAll ? 'true' : 'false' ?>" aria-controls="athleteWeightHistoryCollapse">
                <i class="fas fa-chevron-down me-1"></i>
                <?= $weightShouldExpandAll ? 'Skrýt starší záznamy' : 'Zobrazit starší záznamy (' . count($weightCollapsedRows) . ')' ?>
            </button>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white"><i class="fas fa-calendar-check me-2"></i>Plán tréninků</div>
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="text-muted small">Zaplánované tréninky</div>
                <div class="display-6 fw-bold mb-0"><?= (int)$upcomingPlannedCount ?></div>
            </div>
            <div class="text-start text-md-end">
                <?php if ($nearestPlannedTraining): ?>
                    <div class="fw-semibold">Nejbližší termín</div>
                    <div><?= formatDateTime((string)$nearestPlannedTraining['starts_at']) ?></div>
                    <div class="text-muted small">
                        Místo: <?= !empty($nearestPlannedTraining['location']) ? h((string)$nearestPlannedTraining['location']) : 'neuvedeno' ?>
                    </div>
                    <?php if (($nearestPlannedTraining['approval_status'] ?? 'approved') === 'pending'): ?>
                    <span class="badge bg-warning text-dark mt-1">Ke schválení</span>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-muted">Nejbližší termín zatím není naplánovaný.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fas fa-wallet me-2"></i>Platby</span>
        <a href="<?= BASE_URL ?>/athlete_payments.php" class="btn btn-warning btn-sm fw-semibold">
            <i class="fas fa-eye me-1"></i>Zobrazit platby
        </a>
    </div>
    <div class="card-body">
        <?php if (!empty($paymentRowsForView)): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Měsíc</th>
                        <th>Tréninky</th>
                        <th>Částka</th>
                        <th>Stav</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($paymentRowsForView as $row): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= h($row['month_label']) ?></div>
                                <?php if ((int)$row['stats']['makeup_sessions'] > 0): ?>
                                    <div class="small text-muted">Náhradní termíny: <?= (int)$row['stats']['makeup_sessions'] ?>x</div>
                                <?php endif; ?>
                                <?php if ((int)$row['stats']['transferred_sessions'] > 0): ?>
                                    <div class="small text-muted">Z jiného kalendářního měsíce: <?= (int)$row['stats']['transferred_sessions'] ?>x</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="fw-semibold"><?= (int)$row['billable_sessions'] ?></span>
                                <div class="small text-muted">započítaných tréninků</div>
                                <?php if ((int)($row['paired_sessions'] ?? 0) > 0): ?>
                                    <div class="small text-muted">párové: <?= (int)$row['paired_sessions'] ?>x</div>
                                <?php endif; ?>
                                <?php if ((int)($row['single_sessions'] ?? 0) > 0): ?>
                                    <div class="small text-muted">individuální: <?= (int)$row['single_sessions'] ?>x</div>
                                <?php endif; ?>
                                <?php if ((int)$row['carryover_applied'] > 0): ?>
                                    <div class="small text-warning">Zápočet z dříve uhrazených: -<?= (int)$row['carryover_applied'] ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['display_amount'] !== null): ?>
                                    <span class="fw-semibold"><?= number_format((float)$row['display_amount'], 0, ',', ' ') ?> Kč</span>
                                    <?php if ((float)$row['display_amount'] <= 0.0001): ?>
                                        <div class="small text-success">Fakturovaná částka: 0 Kč</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Nelze spočítat</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['is_paid']): ?>
                                    <span class="badge bg-success">Uhrazeno</span>
                                    <?php if (!empty($row['payment']['paid_at'])): ?>
                                        <div class="small text-muted mt-1"><?= formatDateTime((string)$row['payment']['paid_at']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Čeká na úhradu</span>
                                    <div class="small text-muted mt-1">Poznámka: <?= h($row['note']) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-muted">Aktuálně tu nemáte žádnou evidovanou platbu. Jakmile trenér připraví výzvu, uvidíte ji zde i v sekci Platby.</div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white"><i class="fas fa-history me-2"></i>Historie tréninků</div>
    <div class="card-body p-0">
        <?php if (empty($sessions)): ?>
        <div class="text-center text-muted py-4">Zatím nemáte žádné tréninky.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                <tr>
                    <th>Datum</th>
                    <th>Sada</th>
                    <th>Místo</th>
                    <th>Stav</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($trainingVisibleRows as $s): ?>
                <tr>
                    <td><?= formatDateTime((string)$s['started_at']) ?></td>
                    <td><?= h((string)$s['set_name']) ?></td>
                    <td><?= !empty($s['location']) ? h((string)$s['location']) : '–' ?></td>
                    <td>
                        <?php if (!empty($s['completed_at'])): ?>
                        <span class="badge bg-success">Dokončeno</span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">Naplánováno / probíhá</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/athlete_training_detail.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-eye me-1"></i>Detail
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if (!empty($trainingCollapsedRows)): ?>
                <tbody id="athleteTrainingHistoryCollapse" class="collapse">
                <?php foreach ($trainingCollapsedRows as $s): ?>
                <tr>
                    <td><?= formatDateTime((string)$s['started_at']) ?></td>
                    <td><?= h((string)$s['set_name']) ?></td>
                    <td><?= !empty($s['location']) ? h((string)$s['location']) : '–' ?></td>
                    <td>
                        <?php if (!empty($s['completed_at'])): ?>
                        <span class="badge bg-success">Dokončeno</span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">Naplánováno / probíhá</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/athlete_training_detail.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-eye me-1"></i>Detail
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <?php endif; ?>
            </table>
        </div>
        <?php if (!empty($trainingCollapsedRows)): ?>
        <div class="border-top p-3 text-center bg-light">
            <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#athleteTrainingHistoryCollapse" aria-expanded="false" aria-controls="athleteTrainingHistoryCollapse">
                <i class="fas fa-chevron-down me-1"></i>
                Zobrazit starší tréninky (<?= count($trainingCollapsedRows) ?>)
            </button>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="supportContributionModal" tabindex="-1" aria-labelledby="supportContributionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="supportContributionModalLabel"><i class="fas fa-heart me-2 text-warning"></i>Dobrovolná podpora provozu</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">Jde jen o volitelnou podporu provozu aplikace. Aplikace zůstává zdarma a nic není potřeba platit.</p>
                <?php if ($supportBankAccountForQr === null): ?>
                <div class="alert alert-warning mb-3">Pro tento účet zatím není v administraci nastavené číslo účtu.</div>
                <?php else: ?>
                <div class="mb-3">
                    <label for="supportContributionAmount" class="form-label fw-semibold">Částka</label>
                    <input type="number" min="1" step="1" class="form-control form-control-lg" id="supportContributionAmount" placeholder="Např. 100">
                </div>
                <div class="border rounded-3 p-3 bg-light mb-3">
                    <img id="supportContributionQrImage" src="" alt="QR kód pro příspěvek" class="img-fluid border rounded p-2 bg-white d-none" style="max-width:220px;">
                    <div id="supportContributionQrEmpty" class="text-muted small">Zadejte částku a QR kód se zobrazí automaticky.</div>
                </div>
                <div class="small"><strong>Účet:</strong> <span id="supportContributionAccount"><?= h($supportBankAccount) ?></span></div>
                <div class="small"><strong>Odesílatel:</strong> <span id="supportContributionSender"><?= h($supportContributorName) ?></span></div>
                <div class="small"><strong>Poznámka:</strong> <span id="supportContributionNotePreview"><?= h($supportQrNote) ?></span></div>
                <?php endif; ?>
            </div>
            <div class="modal-footer justify-content-between flex-wrap gap-2">
                <div class="small text-muted">Aplikace zůstává bezplatná. Příspěvek je pouze dobrovolná pomoc s provozem.</div>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zavřít</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const supportBankAccount = <?= json_encode($supportBankAccountForQr, JSON_UNESCAPED_UNICODE) ?>;
    const supportContributorName = <?= json_encode($supportContributorName, JSON_UNESCAPED_UNICODE) ?>;
    const supportQrNote = <?= json_encode($supportQrNote, JSON_UNESCAPED_UNICODE) ?>;
    const amountInput = document.getElementById('supportContributionAmount');
    const qrImage = document.getElementById('supportContributionQrImage');
    const qrEmpty = document.getElementById('supportContributionQrEmpty');

    if (!amountInput || !qrImage || !qrEmpty || supportBankAccount === null) {
        return;
    }

    const buildQrUrl = (amount) => {
        const spd = [
            'SPD*1.0',
            'ACC:' + supportBankAccount,
            'CC:CZK',
            'AM:' + amount.toFixed(2),
            'MSG:' + supportQrNote,
        ].join('*');

        return 'https://quickchart.io/qr?size=220&text=' + encodeURIComponent(spd);
    };

    const updateQr = () => {
        const amount = parseFloat(String(amountInput.value || '').replace(',', '.'));
        if (!Number.isFinite(amount) || amount <= 0) {
            qrImage.classList.add('d-none');
            qrEmpty.classList.remove('d-none');
            qrImage.removeAttribute('src');
            return;
        }

        qrImage.src = buildQrUrl(amount);
        qrImage.classList.remove('d-none');
        qrEmpty.classList.add('d-none');
    };

    amountInput.addEventListener('input', updateQr);
    amountInput.addEventListener('change', updateQr);
})();
</script>

<?php renderAthleteFooter();
