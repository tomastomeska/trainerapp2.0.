<?php
// zpravy.php – seznam zpráv pro přihlášeného trenéra
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$coachId = getCurrentCoachId();
$pdo     = getDB();

// Zpracování přesunu do archivu / smazání / obnovení / trvalé smazání
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/zpravy.php');
    }
    $action = $_POST['action'] ?? '';
    $mid    = intParam($_POST, 'message_id');

    // Zjisti aktuální stav (musí být příjemcem)
    $r = $pdo->prepare("SELECT * FROM admin_message_recipients WHERE message_id = ? AND coach_id = ?");
    $r->execute([$mid, $coachId]);
    $rec = $r->fetch();

    if ($rec) {
        if ($action === 'archive' && $rec['read_at'] !== null) {
            $pdo->prepare("UPDATE admin_message_recipients SET status='archived' WHERE message_id=? AND coach_id=?")
                ->execute([$mid, $coachId]);
            flash('success', 'Zpráva přesunuta do archivu.');
        } elseif ($action === 'delete' && $rec['read_at'] !== null) {
            $pdo->prepare("UPDATE admin_message_recipients SET status='deleted' WHERE message_id=? AND coach_id=?")
                ->execute([$mid, $coachId]);
            flash('success', 'Zpráva přesunuta do koše.');
        } elseif ($action === 'restore') {
            $pdo->prepare("UPDATE admin_message_recipients SET status='inbox' WHERE message_id=? AND coach_id=?")
                ->execute([$mid, $coachId]);
            flash('success', 'Zpráva obnovena do přijatých.');
        } elseif ($action === 'destroy') {
            $pdo->prepare("DELETE FROM admin_message_recipients WHERE message_id=? AND coach_id=?")
                ->execute([$mid, $coachId]);
            flash('success', 'Zpráva byla trvale smazána.');
        }
    }
    $redirectTab = $_GET['tab'] ?? '';
    redirect(BASE_URL . '/zpravy.php' . ($redirectTab ? '?tab=' . urlencode($redirectTab) : ''));
}

$tab = in_array($_GET['tab'] ?? '', ['archived','deleted']) ? $_GET['tab'] : 'inbox';

// Zprávy pro tohoto trenéra dle záložky
$messages = $pdo->prepare("
    SELECT m.id, m.subject, m.sent_at, m.attachment_name,
           r.read_at, r.status
    FROM admin_messages m
    JOIN admin_message_recipients r ON r.message_id = m.id AND r.coach_id = ?
    WHERE r.status = ?
    ORDER BY m.sent_at DESC
");
$messages->execute([$coachId, $tab]);
$messages = $messages->fetchAll();

// Počty pro badge záložek
$counts = $pdo->prepare("
    SELECT status, COUNT(*) AS cnt,
           SUM(read_at IS NULL) AS unread
    FROM admin_message_recipients
    WHERE coach_id = ?
    GROUP BY status
");
$counts->execute([$coachId]);
$tabCounts = [];
foreach ($counts->fetchAll() as $row) {
    $tabCounts[$row['status']] = ['cnt' => $row['cnt'], 'unread' => $row['unread']];
}

$unreadInbox = (int)($tabCounts['inbox']['unread'] ?? 0);

renderHeader('Zprávy');
?>

<h3 class="fw-bold mb-4">
    <i class="fas fa-envelope me-2 text-primary"></i>Moje zprávy
</h3>

<!-- Záložky -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'inbox' ? 'active' : '' ?>" href="<?= BASE_URL ?>/zpravy.php?tab=inbox">
            <i class="fas fa-inbox me-1"></i>Přijaté
            <?php $inboxCnt = (int)($tabCounts['inbox']['cnt'] ?? 0); if ($inboxCnt > 0): ?>
            <span class="badge <?= $unreadInbox > 0 ? 'bg-danger' : 'bg-secondary' ?> ms-1">
                <?= $unreadInbox > 0 ? $unreadInbox : $inboxCnt ?>
            </span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'archived' ? 'active' : '' ?>" href="<?= BASE_URL ?>/zpravy.php?tab=archived">
            <i class="fas fa-archive me-1"></i>Archiv
            <?php $archCnt = (int)($tabCounts['archived']['cnt'] ?? 0); if ($archCnt > 0): ?>
            <span class="badge bg-secondary ms-1"><?= $archCnt ?></span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'deleted' ? 'active' : '' ?>" href="<?= BASE_URL ?>/zpravy.php?tab=deleted">
            <i class="fas fa-trash me-1"></i>Smazané
            <?php $delCnt = (int)($tabCounts['deleted']['cnt'] ?? 0); if ($delCnt > 0): ?>
            <span class="badge bg-secondary ms-1"><?= $delCnt ?></span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<?php if (empty($messages)): ?>
<div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>
    <?php if ($tab === 'inbox'): ?>Nemáte žádné zprávy.
    <?php elseif ($tab === 'archived'): ?>Archiv je prázdný.
    <?php else: ?>Koš je prázdný.<?php endif; ?>
</div>
<?php else: ?>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="msgTable">
            <thead class="table-dark">
                <tr>
                    <th style="width:22px"></th>
                    <th>Předmět</th>
                    <th>Datum</th>
                    <th style="width:40px"><i class="fas fa-paperclip"></i></th>
                    <th>Stav</th>
                    <th style="width:110px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($messages as $m): ?>
            <?php $unread = $m['read_at'] === null; ?>
            <tr class="<?= $unread ? 'table-warning fw-semibold' : '' ?> msg-row"
                style="cursor:pointer"
                data-href="<?= BASE_URL ?>/zprava_detail.php?id=<?= $m['id'] ?>">
                <td>
                    <i class="fas fa-circle <?= $unread ? 'text-danger' : 'text-success' ?>"
                       style="font-size:.55rem"></i>
                </td>
                <td><?= h($m['subject']) ?></td>
                <td class="text-nowrap"><?= date('d.m.Y H:i', strtotime($m['sent_at'])) ?></td>
                <td><?php if ($m['attachment_name']): ?><i class="fas fa-paperclip text-muted"></i><?php endif; ?></td>
                <td>
                    <?php if ($unread): ?>
                    <span class="badge bg-danger">Nepřečteno</span>
                    <?php else: ?>
                    <span class="badge bg-success">Přečteno</span>
                    <?php endif; ?>
                </td>
                <td class="text-end" onclick="event.stopPropagation()">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="message_id" value="<?= $m['id'] ?>">
                        <?php if ($tab === 'inbox'): ?>
                            <?php if (!$unread): ?>
                            <button name="action" value="archive" class="btn btn-sm btn-outline-secondary" title="Archivovat">
                                <i class="fas fa-archive"></i>
                            </button>
                            <button name="action" value="delete" class="btn btn-sm btn-outline-danger" title="Smazat"
                                    onclick="return confirm('Přesunout do koše?')">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php else: ?>
                            <span class="text-muted small">nejdřív přečíst</span>
                            <?php endif; ?>
                        <?php elseif ($tab === 'archived'): ?>
                            <button name="action" value="restore" class="btn btn-sm btn-outline-primary" title="Obnovit">
                                <i class="fas fa-inbox"></i>
                            </button>
                            <button name="action" value="delete" class="btn btn-sm btn-outline-danger" title="Do koše"
                                    onclick="return confirm('Přesunout do koše?')">
                                <i class="fas fa-trash"></i>
                            </button>
                        <?php else: ?>
                            <button name="action" value="restore" class="btn btn-sm btn-outline-primary" title="Obnovit">
                                <i class="fas fa-inbox"></i>
                            </button>
                            <button name="action" value="destroy" class="btn btn-sm btn-outline-danger"
                                    onclick="return confirm('Trvale smazat? Tuto akci nelze vrátit.')" title="Trvale smazat">
                                <i class="fas fa-times"></i>
                            </button>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
// Klik na celý řádek → detail zprávy
document.querySelectorAll('.msg-row').forEach(row => {
    row.addEventListener('click', () => {
        window.location.href = row.dataset.href;
    });
});
</script>

<?php renderFooter(); ?>
