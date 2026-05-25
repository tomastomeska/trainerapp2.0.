<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = (int)getCurrentCoachId();
$pdo = getDB();

function parseBillingMonthValue(?string $raw): DateTimeImmutable
{
    $raw = trim((string)$raw);
    if ($raw !== '' && preg_match('/^\d{4}-\d{2}$/', $raw) === 1) {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw . '-01 00:00:00');
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }
    }

    return new DateTimeImmutable('first day of next month 00:00:00');
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $quotedTable = $pdo->quote($tableName);
    $stmt = $pdo->query("SHOW TABLES LIKE {$quotedTable}");
    return $stmt !== false && (bool)$stmt->fetchColumn();
}

function columnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $quotedColumn = $pdo->quote($columnName);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE {$quotedColumn}");
    return $stmt !== false && (bool)$stmt->fetch();
}

function ensurePaymentsRuntimeSchema(PDO $pdo): array
{
    $warnings = [];

    try {
        if (!columnExists($pdo, 'athletes', 'training_rate')) {
            $pdo->exec('ALTER TABLE athletes ADD COLUMN training_rate DECIMAL(10,2) NULL AFTER email');
        }
    } catch (Throwable $e) {
        $warnings[] = 'Sloupec athletes.training_rate se nepodařilo doplnit.';
    }

    try {
        if (!columnExists($pdo, 'coach_calendar_events', 'is_makeup_session')) {
            $pdo->exec('ALTER TABLE coach_calendar_events ADD COLUMN is_makeup_session TINYINT(1) NOT NULL DEFAULT 0 AFTER coach_modified_at');
        }
    } catch (Throwable $e) {
        $warnings[] = 'Sloupec coach_calendar_events.is_makeup_session se nepodařilo doplnit.';
    }

    try {
        if (!columnExists($pdo, 'coach_calendar_events', 'billing_month')) {
            $pdo->exec('ALTER TABLE coach_calendar_events ADD COLUMN billing_month DATE NULL AFTER is_makeup_session');
            $pdo->exec("UPDATE coach_calendar_events SET billing_month = DATE_FORMAT(starts_at, '%Y-%m-01') WHERE billing_month IS NULL");
        }
    } catch (Throwable $e) {
        $warnings[] = 'Sloupec coach_calendar_events.billing_month se nepodařilo doplnit.';
    }

    try {
        if (!columnExists($pdo, 'coaches', 'bank_account')) {
            $pdo->exec('ALTER TABLE coaches ADD COLUMN bank_account VARCHAR(64) NULL AFTER email');
        }
    } catch (Throwable $e) {
        $warnings[] = 'Sloupec coaches.bank_account se nepodařilo doplnit.';
    }

    try {
        $pdo->exec(" 
            CREATE TABLE IF NOT EXISTS `athlete_monthly_payments` (
                `id`               INT AUTO_INCREMENT PRIMARY KEY,
                `coach_id`         INT NOT NULL,
                `athlete_id`       INT NOT NULL,
                `billing_month`    DATE NOT NULL,
                `session_rate`     DECIMAL(10,2) NULL,
                `planned_sessions` INT NOT NULL DEFAULT 0,
                `billed_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0,
                `status`           ENUM('pending','paid') NOT NULL DEFAULT 'pending',
                `paid_at`          DATETIME NULL,
                `note`             TEXT NULL,
                `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_athlete_monthly_payment` (`coach_id`, `athlete_id`, `billing_month`),
                KEY `idx_athlete_monthly_payment_month` (`coach_id`, `billing_month`, `status`),
                CONSTRAINT `fk_athlete_monthly_payment_coach`
                    FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_athlete_monthly_payment_athlete`
                    FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        $warnings[] = 'Tabulku athlete_monthly_payments se nepodařilo vytvořit.';
    }

    return [
        'warnings' => $warnings,
        'has_training_rate' => columnExists($pdo, 'athletes', 'training_rate'),
        'has_is_makeup_session' => columnExists($pdo, 'coach_calendar_events', 'is_makeup_session'),
        'has_billing_month' => columnExists($pdo, 'coach_calendar_events', 'billing_month'),
        'has_coach_bank_account' => columnExists($pdo, 'coaches', 'bank_account'),
        'has_payments_table' => tableExists($pdo, 'athlete_monthly_payments'),
    ];
}

function normalizeCoachBankAccount(?string $raw): ?string
{
    $value = strtoupper(str_replace(' ', '', trim((string)$raw)));
    if ($value === '') {
        return null;
    }

    if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $value) === 1) {
        return $value;
    }

    if (
        preg_match('/^[0-9]{1,6}-[0-9]{2,10}\/[0-9]{4}$/', $value) === 1 ||
        preg_match('/^[0-9]{2,10}\/[0-9]{4}$/', $value) === 1
    ) {
        return $value;
    }

    return null;
}

function digitsMod97(string $numeric): int
{
    $remainder = 0;
    $len = strlen($numeric);
    for ($i = 0; $i < $len; $i++) {
        $char = $numeric[$i];
        if ($char < '0' || $char > '9') {
            continue;
        }
        $remainder = (($remainder * 10) + (int)$char) % 97;
    }
    return $remainder;
}

function ibanToNumericString(string $iban): string
{
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    $len = strlen($rearranged);
    for ($i = 0; $i < $len; $i++) {
        $char = $rearranged[$i];
        if ($char >= '0' && $char <= '9') {
            $numeric .= $char;
        } elseif ($char >= 'A' && $char <= 'Z') {
            $numeric .= (string)(ord($char) - 55);
        }
    }
    return $numeric;
}

function isValidIban(string $iban): bool
{
    if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $iban) !== 1) {
        return false;
    }
    return digitsMod97(ibanToNumericString($iban)) === 1;
}

function buildCzIbanFromLocal(string $localAccount): ?string
{
    if (preg_match('/^(?:(\d{1,6})-)?(\d{2,10})\/(\d{4})$/', $localAccount, $m) !== 1) {
        return null;
    }

    $prefix = str_pad((string)($m[1] ?? '0'), 6, '0', STR_PAD_LEFT);
    $account = str_pad($m[2], 10, '0', STR_PAD_LEFT);
    $bankCode = $m[3];
    $bban = $bankCode . $prefix . $account;

    $checkBase = $bban . '123500';
    $checkDigits = 98 - digitsMod97($checkBase);
    $iban = 'CZ' . str_pad((string)$checkDigits, 2, '0', STR_PAD_LEFT) . $bban;

    return isValidIban($iban) ? $iban : null;
}

function accountForSpd(?string $bankAccount): ?string
{
    if ($bankAccount === null || $bankAccount === '') {
        return null;
    }

    if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $bankAccount) === 1) {
        return isValidIban($bankAccount) ? $bankAccount : null;
    }

    return buildCzIbanFromLocal($bankAccount);
}

function paymentAsciiText(string $value): string
{
    $text = trim($value);
    if ($text === '') {
        return '';
    }

    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($converted !== false) {
        $text = $converted;
    }

    $text = preg_replace('/[^A-Za-z0-9 .\/-]/', '', $text) ?? '';
    $text = preg_replace('/\s+/', ' ', $text) ?? '';
    return trim($text);
}

function buildPaymentQrUrl(string $bankAccount, float $amount, string $note): string
{
    $parts = [
        'SPD*1.0',
        'ACC:' . $bankAccount,
        'CC:CZK',
        'AM:' . number_format($amount, 2, '.', ''),
    ];

    if ($note !== '') {
        $parts[] = 'MSG:' . str_replace('*', ' ', $note);
    }

    $spd = implode('*', $parts);
    return 'https://quickchart.io/qr?size=220&text=' . rawurlencode($spd);
}

function fetchBillingStats(PDO $pdo, int $coachId, string $billingMonthSql, bool $hasIsMakeupSession, bool $hasBillingMonth, ?int $athleteId = null): array
{
    $makeupExpr = $hasIsMakeupSession ? 'SUM(CASE WHEN is_makeup_session = 1 THEN 1 ELSE 0 END)' : '0';
    $transferredExpr = $hasBillingMonth
        ? "SUM(CASE WHEN DATE_FORMAT(starts_at, '%Y-%m-01') <> DATE_FORMAT(billing_month, '%Y-%m-01') THEN 1 ELSE 0 END)"
        : '0';
    $billingFilter = $hasBillingMonth
        ? 'AND billing_month = ?'
        : "AND DATE_FORMAT(starts_at, '%Y-%m-01') = ?";

    $sql = "SELECT athlete_id,
                   COUNT(*) AS billed_sessions,
                   {$makeupExpr} AS makeup_sessions,
                   {$transferredExpr} AS transferred_sessions
            FROM coach_calendar_events
            WHERE coach_id = ?
              AND athlete_id IS NOT NULL
              AND approval_status = 'approved'
              {$billingFilter}";
    $params = [$coachId, $billingMonthSql];

    if ($athleteId !== null) {
        $sql .= ' AND athlete_id = ?';
        $params[] = $athleteId;
    }

    $sql .= ' GROUP BY athlete_id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $result[(int)$row['athlete_id']] = [
            'billed_sessions' => (int)$row['billed_sessions'],
            'makeup_sessions' => (int)$row['makeup_sessions'],
            'transferred_sessions' => (int)$row['transferred_sessions'],
        ];
    }

    return $result;
}

$schema = ensurePaymentsRuntimeSchema($pdo);
$schemaWarnings = $schema['warnings'];
$hasTrainingRate = (bool)$schema['has_training_rate'];
$hasIsMakeupSession = (bool)$schema['has_is_makeup_session'];
$hasBillingMonth = (bool)$schema['has_billing_month'];
$hasCoachBankAccount = (bool)$schema['has_coach_bank_account'];
$hasPaymentsTable = (bool)$schema['has_payments_table'];

$coachStmt = $pdo->prepare('SELECT name' . ($hasCoachBankAccount ? ', bank_account' : '') . ' FROM coaches WHERE id = ? LIMIT 1');
$coachStmt->execute([$coachId]);
$coach = $coachStmt->fetch() ?: [];
$coachName = trim((string)($coach['name'] ?? ($_SESSION['coach_name'] ?? '')));
$coachLastNameParts = preg_split('/\s+/u', $coachName) ?: [];
$coachLastName = trim((string)end($coachLastNameParts));
if ($coachLastName === '') {
    $coachLastName = 'Trener';
}
$coachBankAccountRaw = $hasCoachBankAccount ? normalizeCoachBankAccount($coach['bank_account'] ?? null) : null;
$coachBankAccount = accountForSpd($coachBankAccountRaw);

$selectedMonth = parseBillingMonthValue($_REQUEST['month'] ?? null);
$selectedMonthSql = $selectedMonth->format('Y-m-01');
$selectedMonthParam = $selectedMonth->format('Y-m');
$monthFormatter = new IntlDateFormatter('cs_CZ', IntlDateFormatter::LONG, IntlDateFormatter::NONE, date_default_timezone_get(), null, 'LLLL yyyy');
$monthTitle = (string)$monthFormatter->format($selectedMonth);
$prevMonthParam = $selectedMonth->modify('-1 month')->format('Y-m');
$nextMonthParam = $selectedMonth->modify('+1 month')->format('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
    }

    $athleteId = (int)($_POST['athlete_id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? ''));

    $athleteStmt = $pdo->prepare(
        'SELECT id, first_name, last_name, email, training_rate
         FROM athletes
         WHERE id = ? AND coach_id = ?
         LIMIT 1'
    );
    $athleteStmt->execute([$athleteId, $coachId]);
    $athlete = $athleteStmt->fetch();

    if (!$athlete) {
        flash('danger', 'Sportovec nebyl nalezen.');
        redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
    }

    $athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);

    if ($action === 'send_payment_email') {
        $athleteEmail = trim((string)($athlete['email'] ?? ''));
        if ($athleteEmail === '' || !filter_var($athleteEmail, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'Sportovec nemá platný e-mail.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        if ($coachBankAccount === null) {
            flash('danger', 'Nejprve nastavte platné číslo účtu v profilu trenéra.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        $stats = fetchBillingStats($pdo, $coachId, $selectedMonthSql, $hasIsMakeupSession, $hasBillingMonth, $athleteId);
        $athleteStats = $stats[$athleteId] ?? [
            'billed_sessions' => 0,
            'makeup_sessions' => 0,
            'transferred_sessions' => 0,
        ];

        $rate = $hasTrainingRate && $athlete['training_rate'] !== null ? (float)$athlete['training_rate'] : null;
        if ($rate === null) {
            flash('danger', 'Sportovec nemá nastavenou sazbu za trénink.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        $currentSessions = (int)$athleteStats['billed_sessions'];
        $currentAmount = $currentSessions * $rate;
        if ($currentAmount <= 0) {
            flash('danger', 'Není co fakturovat. Částka je 0 Kč.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        $paymentNote = paymentAsciiText($coachLastName . ' ' . $selectedMonth->format('m/Y'));
        $paymentQrUrl = buildPaymentQrUrl($coachBankAccount, $currentAmount, $paymentNote);
        $amountText = number_format($currentAmount, 0, ',', ' ') . ' Kč';

        $sent = sendPaymentRequestEmail($athleteEmail, [
            'athlete_name' => $athleteName,
            'coach_name' => $coachName !== '' ? $coachName : 'Váš trenér',
            'month_label' => $selectedMonth->format('m/Y'),
            'amount_text' => $amountText,
            'account' => $coachBankAccount,
            'note' => $paymentNote,
            'qr_url' => $paymentQrUrl,
        ]);

        if ($sent) {
            createAthleteNotification(
                $athleteId,
                'Nová výzva k platbě',
                'Trenér vám poslal výzvu k platbě za období ' . $selectedMonth->format('m/Y')
                . ' ve výši ' . $amountText . '. Přehled včetně QR kódu najdete v sekci Platby.'
            );
            flash('success', 'Výzva k platbě byla odeslána na ' . $athleteEmail . '.');
        } else {
            flash('danger', 'E-mail se nepodařilo odeslat. Zkontrolujte SMTP nastavení.');
        }
        redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
    }

    if ($action === 'mark_paid') {
        if (!$hasPaymentsTable) {
            flash('danger', 'Evidenční tabulka plateb není dostupná.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        $stats = fetchBillingStats($pdo, $coachId, $selectedMonthSql, $hasIsMakeupSession, $hasBillingMonth, $athleteId);
        $athleteStats = $stats[$athleteId] ?? [
            'billed_sessions' => 0,
            'makeup_sessions' => 0,
            'transferred_sessions' => 0,
        ];

        $rate = $hasTrainingRate && $athlete['training_rate'] !== null ? (float)$athlete['training_rate'] : null;
        if ($rate === null) {
            flash('danger', 'Sportovec nemá nastavenou sazbu za trénink.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        $plannedSessions = (int)$athleteStats['billed_sessions'];
        $billedAmount = $plannedSessions * $rate;

        $upsert = $pdo->prepare(
            "INSERT INTO athlete_monthly_payments (coach_id, athlete_id, billing_month, session_rate, planned_sessions, billed_amount, status, paid_at)
             VALUES (?, ?, ?, ?, ?, ?, 'paid', NOW())
             ON DUPLICATE KEY UPDATE
                session_rate = VALUES(session_rate),
                planned_sessions = VALUES(planned_sessions),
                billed_amount = VALUES(billed_amount),
                status = 'paid',
                paid_at = NOW()"
        );
        $upsert->execute([
            $coachId,
            $athleteId,
            $selectedMonthSql,
            number_format($rate, 2, '.', ''),
            $plannedSessions,
            number_format($billedAmount, 2, '.', ''),
        ]);

        createAthleteNotification(
            $athleteId,
            'Platba byla označena jako uhrazená',
            'Trenér označil platbu za období ' . $selectedMonth->format('m/Y')
            . ' jako uhrazenou. Evidovaná částka je ' . number_format($billedAmount, 0, ',', ' ') . ' Kč.'
        );

        flash('success', 'Úhrada pro ' . $athleteName . ' byla označena jako uhrazená.');
        redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
    }

    if ($action === 'mark_unpaid') {
        if (!$hasPaymentsTable) {
            flash('danger', 'Evidenční tabulka plateb není dostupná.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        $delete = $pdo->prepare(
            'DELETE FROM athlete_monthly_payments
             WHERE coach_id = ? AND athlete_id = ? AND billing_month = ?'
        );
        $delete->execute([$coachId, $athleteId, $selectedMonthSql]);

        createAthleteNotification(
            $athleteId,
            'Evidence úhrady byla zrušena',
            'Trenér zrušil evidenci úhrady za období ' . $selectedMonth->format('m/Y') . '. V sekci Platby znovu vidíte aktivní výzvu k platbě.'
        );

        flash('success', 'Evidence úhrady pro ' . $athleteName . ' byla zrušena.');
        redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
    }

    flash('danger', 'Neznámá akce.');
    redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
}

$athletesStmt = $pdo->prepare(
    'SELECT id, first_name, last_name, email, phone_contact' . ($hasTrainingRate ? ', training_rate' : '') . '
     FROM athletes
     WHERE coach_id = ?
     ORDER BY last_name ASC, first_name ASC'
);
$athletesStmt->execute([$coachId]);
$athletes = $athletesStmt->fetchAll();

$statsByAthlete = fetchBillingStats($pdo, $coachId, $selectedMonthSql, $hasIsMakeupSession, $hasBillingMonth);

$paymentsRows = [];
if ($hasPaymentsTable) {
    $paymentsStmt = $pdo->prepare(
        'SELECT athlete_id, session_rate, planned_sessions, billed_amount, status, paid_at
         FROM athlete_monthly_payments
         WHERE coach_id = ? AND billing_month = ?'
    );
    $paymentsStmt->execute([$coachId, $selectedMonthSql]);
    $paymentsRows = $paymentsStmt->fetchAll();
}
$paymentsByAthlete = [];
foreach ($paymentsRows as $row) {
    $paymentsByAthlete[(int)$row['athlete_id']] = $row;
}

$totalSessions = 0;
$totalAmount = 0.0;
$totalPaidAmount = 0.0;
$totalPaidAthletes = 0;

foreach ($athletes as $athlete) {
    $athleteId = (int)$athlete['id'];
    $stats = $statsByAthlete[$athleteId] ?? [
        'billed_sessions' => 0,
        'makeup_sessions' => 0,
        'transferred_sessions' => 0,
    ];
    $rate = $hasTrainingRate && array_key_exists('training_rate', $athlete) && $athlete['training_rate'] !== null
        ? (float)$athlete['training_rate']
        : null;

    $totalSessions += (int)$stats['billed_sessions'];
    if ($rate !== null) {
        $totalAmount += ((int)$stats['billed_sessions']) * $rate;
    }

    if (isset($paymentsByAthlete[$athleteId]) && ($paymentsByAthlete[$athleteId]['status'] ?? '') === 'paid') {
        $totalPaidAmount += (float)$paymentsByAthlete[$athleteId]['billed_amount'];
        $totalPaidAthletes++;
    }
}

renderHeader('Platby');
?>

<?php if (!empty($schemaWarnings)): ?>
<div class="alert alert-warning">
    <strong>Platby běží v omezeném režimu.</strong>
    <div class="small mt-1">Některé databázové změny se nepodařilo automaticky aplikovat.</div>
    <ul class="mb-0 mt-2">
        <?php foreach ($schemaWarnings as $warn): ?>
        <li><?= h($warn) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="mb-1"><i class="fas fa-wallet me-2 text-warning"></i>Platby</h2>
        <div class="text-muted">Částky se počítají z kalendáře podle hrazeného měsíce. Náhradní termín se může započítat do jiného měsíce než kdy fyzicky proběhne.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/payments.php?month=<?= urlencode($prevMonthParam) ?>">
            <i class="fas fa-chevron-left me-1"></i>Předchozí měsíc
        </a>
        <form method="get" class="d-flex gap-2 align-items-center">
            <input type="month" name="month" class="form-control form-control-sm" value="<?= h($selectedMonthParam) ?>">
            <button type="submit" class="btn btn-dark btn-sm">Zobrazit</button>
        </form>
        <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/payments.php?month=<?= urlencode($nextMonthParam) ?>">
            Další měsíc<i class="fas fa-chevron-right ms-1"></i>
        </a>
    </div>
</div>

<?php if ($coachBankAccount === null): ?>
<div class="alert alert-info">
    QR platby se zobrazí po nastavení platného čísla účtu v <a href="<?= BASE_URL ?>/profile.php">profilu trenéra</a>.
    Akceptováno je české číslo účtu (např. 123456789/0800) nebo validní IBAN.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Hrazený měsíc</div>
                <div class="fs-5 fw-bold"><?= h($monthTitle) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Započítané tréninky</div>
                <div class="fs-5 fw-bold"><?= (int)$totalSessions ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Očekávaná částka</div>
                <div class="fs-5 fw-bold"><?= number_format($totalAmount, 0, ',', ' ') ?> Kč</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Uhrazeno</div>
                <div class="fs-5 fw-bold"><?= number_format($totalPaidAmount, 0, ',', ' ') ?> Kč</div>
                <div class="small text-muted"><?= (int)$totalPaidAthletes ?> sportovců</div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Sportovec</th>
                        <th>Sazba</th>
                        <th>Tréninky</th>
                        <th>Částka</th>
                        <th>Stav</th>
                        <th class="text-end">Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($athletes as $athlete): ?>
                        <?php
                        $athleteId = (int)$athlete['id'];
                        $stats = $statsByAthlete[$athleteId] ?? [
                            'billed_sessions' => 0,
                            'makeup_sessions' => 0,
                            'transferred_sessions' => 0,
                        ];
                        $payment = $paymentsByAthlete[$athleteId] ?? null;
                        $rate = $athlete['training_rate'] !== null ? (float)$athlete['training_rate'] : null;
                        $currentSessions = (int)$stats['billed_sessions'];
                        $currentAmount = $rate !== null ? $currentSessions * $rate : null;
                        $paymentNote = paymentAsciiText($coachLastName . ' ' . $selectedMonth->format('m/Y'));
                        $athleteName = trim((string)$athlete['last_name'] . ' ' . (string)$athlete['first_name']);
                        $athleteEmail = trim((string)($athlete['email'] ?? ''));
                        $athletePhone = preg_replace('/\s+/', '', trim((string)($athlete['phone_contact'] ?? ''))) ?? '';
                        $shareAmountText = $currentAmount !== null
                            ? number_format($currentAmount, 0, ',', ' ') . ' Kč'
                            : '0 Kč';
                        $shareText = 'Výzva k platbě za tréninky ' . $selectedMonth->format('m/Y')
                            . ' pro ' . $athleteName
                            . '. Částka: ' . $shareAmountText
                            . '. Účet: ' . ($coachBankAccount ?? '')
                            . '. Poznámka: ' . $paymentNote
                            . '. QR: ';
                        $paymentQrUrl = ($coachBankAccount !== null && $currentAmount !== null && $currentAmount > 0)
                            ? buildPaymentQrUrl($coachBankAccount, $currentAmount, $paymentNote)
                            : null;
                        $hasDiff = $payment && (((int)$payment['planned_sessions'] !== $currentSessions) || ((float)$payment['billed_amount'] !== (float)($currentAmount ?? 0)));
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= h(trim((string)$athlete['last_name'] . ' ' . (string)$athlete['first_name'])) ?></div>
                                <?php if ((int)$stats['makeup_sessions'] > 0): ?>
                                    <div class="small text-muted">Náhradní termíny: <?= (int)$stats['makeup_sessions'] ?>x</div>
                                <?php endif; ?>
                                <?php if ((int)$stats['transferred_sessions'] > 0): ?>
                                    <div class="small text-muted">Z toho v jiném kalendářním měsíci: <?= (int)$stats['transferred_sessions'] ?>x</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($rate !== null): ?>
                                    <span class="fw-semibold"><?= number_format($rate, 0, ',', ' ') ?> Kč</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Chybí sazba</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="fw-semibold"><?= $currentSessions ?></span>
                                <div class="small text-muted">schválených / trenérem vytvořených</div>
                            </td>
                            <td>
                                <?php if ($currentAmount !== null): ?>
                                    <span class="fw-semibold"><?= number_format($currentAmount, 0, ',', ' ') ?> Kč</span>
                                <?php else: ?>
                                    <span class="text-muted">Nelze spočítat</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($payment && ($payment['status'] ?? '') === 'paid'): ?>
                                    <span class="badge bg-success">Uhrazeno</span>
                                    <?php if (!empty($payment['paid_at'])): ?>
                                        <div class="small text-muted mt-1"><?= h(date('d.m.Y H:i', strtotime((string)$payment['paid_at']))) ?></div>
                                    <?php endif; ?>
                                    <div class="small text-muted">Evidováno: <?= (int)$payment['planned_sessions'] ?> tréninků / <?= number_format((float)$payment['billed_amount'], 0, ',', ' ') ?> Kč</div>
                                    <?php if ($hasDiff): ?>
                                        <div class="small text-danger">Kalendář se od poslední evidence změnil.</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Neuhrazeno</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="d-flex justify-content-end gap-2 flex-wrap">
                                    <?php if ($paymentQrUrl !== null): ?>
                                        <button
                                            type="button"
                                            class="btn btn-outline-dark btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#paymentQrModal"
                                            data-athlete-id="<?= $athleteId ?>"
                                            data-athlete-name="<?= h($athleteName) ?>"
                                            data-athlete-email="<?= h($athleteEmail) ?>"
                                            data-athlete-phone="<?= h($athletePhone) ?>"
                                            data-amount="<?= h($shareAmountText) ?>"
                                            data-account="<?= h($coachBankAccount ?? '') ?>"
                                            data-note="<?= h($paymentNote) ?>"
                                            data-qr-url="<?= h($paymentQrUrl) ?>"
                                            data-share-text="<?= h($shareText) ?>"
                                        >
                                            <i class="fas fa-qrcode me-1"></i>QR platba
                                        </button>
                                    <?php endif; ?>
                                    <a href="<?= BASE_URL ?>/athlete_edit.php?id=<?= $athleteId ?>&return_to=<?= urlencode(BASE_URL . '/payments.php?month=' . $selectedMonthParam) ?>" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-pen me-1"></i>Sazba
                                    </a>
                                    <?php if ($payment && ($payment['status'] ?? '') === 'paid'): ?>
                                        <form method="post" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="month" value="<?= h($selectedMonthParam) ?>">
                                            <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
                                            <input type="hidden" name="action" value="mark_unpaid">
                                            <button type="submit" class="btn btn-outline-danger btn-sm">Zrušit úhradu</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="month" value="<?= h($selectedMonthParam) ?>">
                                            <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
                                            <input type="hidden" name="action" value="mark_paid">
                                            <button type="submit" class="btn btn-success btn-sm" <?= $rate === null ? 'disabled' : '' ?>>Označit uhrazeno</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($athletes)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">Nemáte žádné sportovce.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentQrModal" tabindex="-1" aria-labelledby="paymentQrModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paymentQrModalLabel">QR výzva k platbě</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <img id="paymentQrImage" src="" alt="QR platba" class="img-fluid border rounded p-2 bg-white" style="max-width:220px;">
                </div>
                <div class="small text-muted mb-1" id="paymentQrAthlete"></div>
                <div class="small"><strong>Částka:</strong> <span id="paymentQrAmount"></span></div>
                <div class="small"><strong>Účet:</strong> <span id="paymentQrAccount"></span></div>
                <div class="small"><strong>Poznámka:</strong> <span id="paymentQrNote"></span></div>
            </div>
            <div class="modal-footer d-flex justify-content-between flex-wrap gap-2">
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" id="paymentQrEmailSendBtn" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-envelope me-1"></i>E-mail
                    </button>
                    <a id="paymentQrWhatsappLink" href="#" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm">
                        <i class="fab fa-whatsapp me-1"></i>WhatsApp
                    </a>
                    <a id="paymentQrSmsLink" href="#" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-comment-sms me-1"></i>SMS
                    </a>
                    <button type="button" id="paymentQrNativeShare" class="btn btn-dark btn-sm">
                        <i class="fas fa-share-alt me-1"></i>Sdílet
                    </button>
                </div>
                <button type="button" class="btn btn-outline-dark btn-sm" data-bs-dismiss="modal">Zavřít</button>
            </div>
        </div>
    </div>
</div>

<form id="paymentQrEmailForm" method="post" class="d-none">
    <?= csrfField() ?>
    <input type="hidden" name="month" value="<?= h($selectedMonthParam) ?>">
    <input type="hidden" name="athlete_id" id="paymentQrEmailAthleteId" value="">
    <input type="hidden" name="action" value="send_payment_email">
</form>

<script>
(function () {
    const modal = document.getElementById('paymentQrModal');
    if (!modal) return;

    const qrImage = document.getElementById('paymentQrImage');
    const athleteLabel = document.getElementById('paymentQrAthlete');
    const amountLabel = document.getElementById('paymentQrAmount');
    const accountLabel = document.getElementById('paymentQrAccount');
    const noteLabel = document.getElementById('paymentQrNote');
    const emailSendBtn = document.getElementById('paymentQrEmailSendBtn');
    const emailForm = document.getElementById('paymentQrEmailForm');
    const emailAthleteId = document.getElementById('paymentQrEmailAthleteId');
    const whatsappLink = document.getElementById('paymentQrWhatsappLink');
    const smsLink = document.getElementById('paymentQrSmsLink');
    const nativeShareBtn = document.getElementById('paymentQrNativeShare');

    let currentSharePayload = null;
    let canSendEmail = false;

    modal.addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        if (!trigger) return;

        const data = trigger.dataset;
        const athleteId = data.athleteId || '';
        const athleteName = data.athleteName || '';
        const athleteEmail = data.athleteEmail || '';
        const athletePhone = data.athletePhone || '';
        const amount = data.amount || '';
        const account = data.account || '';
        const note = data.note || '';
        const qrUrl = data.qrUrl || '';
        const shareTextBase = data.shareText || '';
        const shareText = shareTextBase + qrUrl;

        qrImage.src = qrUrl;
        athleteLabel.textContent = athleteName;
        amountLabel.textContent = amount;
        accountLabel.textContent = account;
        noteLabel.textContent = note;

        emailAthleteId.value = athleteId;

        if (athleteEmail) {
            canSendEmail = true;
            emailSendBtn.classList.remove('disabled');
            emailSendBtn.setAttribute('aria-disabled', 'false');
        } else {
            canSendEmail = false;
            emailSendBtn.classList.add('disabled');
            emailSendBtn.setAttribute('aria-disabled', 'true');
        }

        whatsappLink.href = 'https://wa.me/?text=' + encodeURIComponent(shareText);

        const smsTarget = athletePhone ? athletePhone : '';
        smsLink.href = 'sms:' + encodeURIComponent(smsTarget) + '?body=' + encodeURIComponent(shareText);

        currentSharePayload = {
            title: 'Výzva k platbě',
            text: shareText,
            url: qrUrl,
        };

        if (!navigator.share) {
            nativeShareBtn.classList.add('d-none');
        } else {
            nativeShareBtn.classList.remove('d-none');
        }
    });

    nativeShareBtn.addEventListener('click', async function () {
        if (!navigator.share || !currentSharePayload) return;
        try {
            await navigator.share(currentSharePayload);
        } catch (e) {
            // Uživatel sdílení zavřel nebo zařízení sdílení nepodporuje.
        }
    });

    emailSendBtn.addEventListener('click', function () {
        if (!canSendEmail || !emailForm || !emailAthleteId.value) return;
        emailForm.submit();
    });
})();
</script>

<?php renderFooter();
