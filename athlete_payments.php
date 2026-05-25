<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();

function athletePaymentsColumnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $quotedColumn = $pdo->quote($columnName);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE {$quotedColumn}");
    return $stmt !== false && (bool)$stmt->fetch();
}

function athletePaymentsNormalizeBankAccount(?string $raw): ?string
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

function athletePaymentsDigitsMod97(string $numeric): int
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

function athletePaymentsIbanToNumeric(string $iban): string
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

function athletePaymentsValidIban(string $iban): bool
{
    if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $iban) !== 1) {
        return false;
    }
    return athletePaymentsDigitsMod97(athletePaymentsIbanToNumeric($iban)) === 1;
}

function athletePaymentsCzIban(string $localAccount): ?string
{
    if (preg_match('/^(?:(\d{1,6})-)?(\d{2,10})\/(\d{4})$/', $localAccount, $m) !== 1) {
        return null;
    }

    $prefix = str_pad((string)($m[1] ?? '0'), 6, '0', STR_PAD_LEFT);
    $account = str_pad($m[2], 10, '0', STR_PAD_LEFT);
    $bankCode = $m[3];
    $bban = $bankCode . $prefix . $account;
    $checkBase = $bban . '123500';
    $checkDigits = 98 - athletePaymentsDigitsMod97($checkBase);
    $iban = 'CZ' . str_pad((string)$checkDigits, 2, '0', STR_PAD_LEFT) . $bban;

    return athletePaymentsValidIban($iban) ? $iban : null;
}

function athletePaymentsAccountForSpd(?string $bankAccount): ?string
{
    if ($bankAccount === null || $bankAccount === '') {
        return null;
    }

    if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $bankAccount) === 1) {
        return athletePaymentsValidIban($bankAccount) ? $bankAccount : null;
    }

    return athletePaymentsCzIban($bankAccount);
}

function athletePaymentsAscii(string $value): string
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

function athletePaymentsQrUrl(string $bankAccount, float $amount, string $note): string
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

    return 'https://quickchart.io/qr?size=220&text=' . rawurlencode(implode('*', $parts));
}

$hasBillingMonth = athletePaymentsColumnExists($pdo, 'coach_calendar_events', 'billing_month');
$hasIsMakeup = athletePaymentsColumnExists($pdo, 'coach_calendar_events', 'is_makeup_session');
$hasCoachBankAccount = athletePaymentsColumnExists($pdo, 'coaches', 'bank_account');

$athleteStmt = $pdo->prepare(
    'SELECT a.id, a.first_name, a.last_name, a.training_rate, c.id AS coach_id, c.name AS coach_name, c.username AS coach_username'
    . ($hasCoachBankAccount ? ', c.bank_account' : '') . '
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

$coachDisplayName = trim((string)($athlete['coach_name'] ?: $athlete['coach_username']));
$coachLastNameParts = preg_split('/\s+/u', $coachDisplayName) ?: [];
$coachLastName = trim((string)end($coachLastNameParts));
if ($coachLastName === '') {
    $coachLastName = 'Trener';
}

$coachBankAccount = $hasCoachBankAccount
    ? athletePaymentsAccountForSpd(athletePaymentsNormalizeBankAccount($athlete['bank_account'] ?? null))
    : null;

$billingSelect = $hasBillingMonth ? 'billing_month' : "DATE_FORMAT(starts_at, '%Y-%m-01')";
$billingFilter = $hasBillingMonth ? 'billing_month IS NOT NULL' : '1=1';
$transferredExpr = $hasBillingMonth
    ? "SUM(CASE WHEN DATE_FORMAT(starts_at, '%Y-%m-01') <> DATE_FORMAT(billing_month, '%Y-%m-01') THEN 1 ELSE 0 END)"
    : '0';
$makeupExpr = $hasIsMakeup ? 'SUM(CASE WHEN is_makeup_session = 1 THEN 1 ELSE 0 END)' : '0';

$statsStmt = $pdo->prepare(
    "SELECT {$billingSelect} AS billing_month,
            COUNT(*) AS billed_sessions,
            {$makeupExpr} AS makeup_sessions,
            {$transferredExpr} AS transferred_sessions
     FROM coach_calendar_events
     WHERE athlete_id = ?
       AND approval_status = 'approved'
       AND {$billingFilter}
     GROUP BY {$billingSelect}
     ORDER BY {$billingSelect} DESC"
);
$statsStmt->execute([$athleteId]);
$statsRows = $statsStmt->fetchAll();

$paymentRows = [];
try {
    $paymentStmt = $pdo->prepare(
        'SELECT billing_month, session_rate, planned_sessions, billed_amount, status, paid_at
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
        'makeup_sessions' => (int)$row['makeup_sessions'],
        'transferred_sessions' => (int)$row['transferred_sessions'],
    ];
}

foreach ($paymentsByMonth as $month => $payment) {
    if (!isset($rowsByMonth[$month])) {
        $rowsByMonth[$month] = [
            'billing_month' => $month,
            'billed_sessions' => (int)($payment['planned_sessions'] ?? 0),
            'makeup_sessions' => 0,
            'transferred_sessions' => 0,
        ];
    }
}

krsort($rowsByMonth);

$rate = isset($athlete['training_rate']) && $athlete['training_rate'] !== null ? (float)$athlete['training_rate'] : null;
$paymentRowsForView = [];
$openPaymentCount = 0;

foreach ($rowsByMonth as $month => $stats) {
    $payment = $paymentsByMonth[$month] ?? null;
    $amount = $rate !== null ? ((int)$stats['billed_sessions']) * $rate : null;
    $note = athletePaymentsAscii($coachLastName . ' ' . date('m/Y', strtotime($month)));
    $qrUrl = ($coachBankAccount !== null && $amount !== null && $amount > 0)
        ? athletePaymentsQrUrl($coachBankAccount, $amount, $note)
        : null;
    $isPaid = $payment && ($payment['status'] ?? '') === 'paid';

    if (!$isPaid && $amount !== null && $amount > 0) {
        $openPaymentCount++;
    }

    $paymentRowsForView[] = [
        'billing_month' => $month,
        'month_label' => date('m/Y', strtotime($month)),
        'stats' => $stats,
        'payment' => $payment,
        'amount' => $amount,
        'note' => $note,
        'qr_url' => $qrUrl,
        'is_paid' => $isPaid,
    ];
}

renderAthleteHeader('Platby');
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="mb-1"><i class="fas fa-wallet me-2 text-warning"></i>Platby</h2>
        <div class="text-muted">Tady vidíte platební výzvy od trenéra, QR kód k úhradě a stav zaplacení jednotlivých měsíců.</div>
    </div>
    <a href="<?= BASE_URL ?>/athlete_zpravy.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-envelope me-1"></i>Zprávy od trenéra
    </a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Trenér</div>
                <div class="fs-5 fw-bold"><?= h($coachDisplayName) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Otevřené výzvy</div>
                <div class="fs-5 fw-bold"><?= $openPaymentCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Sazba za trénink</div>
                <div class="fs-5 fw-bold"><?= $rate !== null ? number_format($rate, 0, ',', ' ') . ' Kč' : 'Nenastavena' ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($coachBankAccount === null): ?>
<div class="alert alert-info">Trenér zatím nemá nastavené platné číslo účtu, proto zde QR platba nemusí být k dispozici.</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($paymentRowsForView)): ?>
            <div class="text-center text-muted py-4">Zatím tu nemáte žádné platební výzvy.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Měsíc</th>
                            <th>Tréninky</th>
                            <th>Částka</th>
                            <th>Stav</th>
                            <th class="text-end">Akce</th>
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
                                    <span class="fw-semibold"><?= (int)$row['stats']['billed_sessions'] ?></span>
                                    <div class="small text-muted">započítaných tréninků</div>
                                </td>
                                <td>
                                    <?php if ($row['amount'] !== null): ?>
                                        <span class="fw-semibold"><?= number_format((float)$row['amount'], 0, ',', ' ') ?> Kč</span>
                                    <?php else: ?>
                                        <span class="text-muted">Nelze spočítat</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['is_paid']): ?>
                                        <span class="badge bg-success">Uhrazeno</span>
                                        <?php if (!empty($row['payment']['paid_at'])): ?>
                                            <div class="small text-muted mt-1"><?= h(date('d.m.Y H:i', strtotime((string)$row['payment']['paid_at']))) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Čeká na úhradu</span>
                                        <div class="small text-muted mt-1">Poznámka: <?= h($row['note']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if (!$row['is_paid'] && $row['qr_url'] !== null): ?>
                                        <button
                                            type="button"
                                            class="btn btn-outline-dark btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#athletePaymentQrModal"
                                            data-month-label="<?= h($row['month_label']) ?>"
                                            data-amount="<?= h(number_format((float)$row['amount'], 0, ',', ' ') . ' Kč') ?>"
                                            data-account="<?= h($coachBankAccount ?? '') ?>"
                                            data-note="<?= h($row['note']) ?>"
                                            data-qr-url="<?= h($row['qr_url']) ?>"
                                        >
                                            <i class="fas fa-qrcode me-1"></i>Zobrazit QR
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small">Bez akce</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="athletePaymentQrModal" tabindex="-1" aria-labelledby="athletePaymentQrModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="athletePaymentQrModalLabel">QR platba</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <img id="athletePaymentQrImage" src="" alt="QR platba" class="img-fluid border rounded p-2 bg-white" style="max-width:220px;">
                </div>
                <div class="small"><strong>Období:</strong> <span id="athletePaymentQrMonth"></span></div>
                <div class="small"><strong>Částka:</strong> <span id="athletePaymentQrAmount"></span></div>
                <div class="small"><strong>Účet:</strong> <span id="athletePaymentQrAccount"></span></div>
                <div class="small"><strong>Poznámka:</strong> <span id="athletePaymentQrNote"></span></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-dark btn-sm" data-bs-dismiss="modal">Zavřít</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('athletePaymentQrModal');
    if (!modal) return;

    const qrImage = document.getElementById('athletePaymentQrImage');
    const monthLabel = document.getElementById('athletePaymentQrMonth');
    const amountLabel = document.getElementById('athletePaymentQrAmount');
    const accountLabel = document.getElementById('athletePaymentQrAccount');
    const noteLabel = document.getElementById('athletePaymentQrNote');

    modal.addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        if (!trigger) return;

        const data = trigger.dataset;
        qrImage.src = data.qrUrl || '';
        monthLabel.textContent = data.monthLabel || '';
        amountLabel.textContent = data.amount || '';
        accountLabel.textContent = data.account || '';
        noteLabel.textContent = data.note || '';
    });
})();
</script>

<?php renderAthleteFooter(); ?>