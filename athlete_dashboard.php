<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_dashboard.php');
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_weight') {
        $weightInput = str_replace(',', '.', trim((string)($_POST['weight_kg'] ?? '')));
        $measuredAt = preg_replace('/[^0-9\-]/', '', (string)($_POST['measured_at'] ?? date('Y-m-d')));
        $weightKg = is_numeric($weightInput) ? (float)$weightInput : 0.0;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $measuredAt)) {
            flash('danger', 'Zadejte platné datum vážení.');
        } elseif ($weightKg < 20 || $weightKg > 400) {
            flash('danger', 'Zadejte platnou hmotnost v kg.');
        } else {
            addAthleteWeightLog($athleteId, $measuredAt, $weightKg, 'athlete_link', null, null);

            $coachDisplayName = (string)($athlete['coach_name'] ?: $athlete['coach_username']);
            $athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
            $subject = "Nová hmotnost - {$athleteName}";
            $body = "Sportovec {$athleteName} zadal novou hmotnost: " . number_format($weightKg, 1, ',', '') . " kg ({$measuredAt}).";
            createCoachSystemMessage((int)$athlete['coach_id'], $subject, $body, true);

            flash('success', 'Hmotnost byla uložena a trenér byl informován.');
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

$weightStats = getAthleteWeightStats($athleteId);

$paymentSummary = null;
try {
    $paymentSummaryStmt = $pdo->prepare(
        "SELECT p.billing_month, p.billed_amount, p.status, p.paid_at
         FROM athlete_monthly_payments p
         WHERE p.athlete_id = ?
         ORDER BY (p.status = 'pending') DESC, p.billing_month DESC
         LIMIT 1"
    );
    $paymentSummaryStmt->execute([$athleteId]);
    $paymentSummary = $paymentSummaryStmt->fetch() ?: null;
} catch (Throwable $e) {
    $paymentSummary = null;
}

renderAthleteHeader('Profil sportovce');
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-user me-2 text-warning"></i>Můj profil</h2>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/athlete_mealplans.php" class="btn btn-outline-success btn-sm"><i class="fas fa-utensils me-1"></i>Jídelníčky</a>
        <a href="<?= BASE_URL ?>/athlete_graphs.php" class="btn btn-outline-info btn-sm"><i class="fas fa-chart-line me-1"></i>Grafy</a>
        <a href="<?= BASE_URL ?>/athlete_calendar.php" class="btn btn-outline-warning btn-sm"><i class="fas fa-calendar-alt me-1"></i>Kalendář</a>
        <a href="<?= BASE_URL ?>/athlete_change_password.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-key me-1"></i>Změnit heslo</a>
    </div>
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
            <div class="card-header bg-primary text-white"><i class="fas fa-weight-scale me-2"></i>Odeslat aktuální hmotnost trenérovi</div>
            <div class="card-body">
                <form method="post" class="row g-3 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_weight">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Datum vážení</label>
                        <input type="date" name="measured_at" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Hmotnost (kg)</label>
                        <input type="number" name="weight_kg" class="form-control" min="20" max="400" step="0.1" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100 fw-semibold">Odeslat</button>
                    </div>
                </form>
                <small class="text-muted d-block mt-2">Data jsou jen pro čtení, tréninky nelze upravovat ani mazat.</small>
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
        <?php if ($paymentSummary): ?>
            <?php $paymentMonth = date('m/Y', strtotime((string)$paymentSummary['billing_month'])); ?>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <div class="fw-semibold">Poslední evidovaná platba: <?= h($paymentMonth) ?></div>
                    <div class="text-muted small">Částka <?= number_format((float)$paymentSummary['billed_amount'], 0, ',', ' ') ?> Kč</div>
                </div>
                <div class="text-end">
                    <?php if (($paymentSummary['status'] ?? '') === 'paid'): ?>
                        <span class="badge bg-success">Uhrazeno</span>
                        <?php if (!empty($paymentSummary['paid_at'])): ?>
                            <div class="small text-muted mt-1"><?= formatDateTime((string)$paymentSummary['paid_at']) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">Čeká na úhradu</span>
                    <?php endif; ?>
                </div>
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
                <?php foreach ($sessions as $s): ?>
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
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php renderAthleteFooter();
