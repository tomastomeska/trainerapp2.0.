<?php
// admin/coach_deleted_trainings.php - Smazane treninky trenera + obnova
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();
$coachId = intParam($_GET, 'coach_id');

$stmtCoach = $pdo->prepare('SELECT id, username, name FROM coaches WHERE id = ?');
$stmtCoach->execute([$coachId]);
$coach = $stmtCoach->fetch();

if (!$coach) {
    flash('danger', 'Trener nenalezen.');
    redirect(BASE_URL . '/admin/coaches.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatny bezpecnostni token.');
        redirect(BASE_URL . '/admin/coach_deleted_trainings.php?coach_id=' . $coachId);
    }

    $sessionId = intParam($_POST, 'session_id');
    $check = $pdo->prepare(
        'SELECT ts.id
         FROM training_sessions ts
         JOIN athletes a ON a.id = ts.athlete_id
         WHERE ts.id = ?
           AND a.coach_id = ?
           AND ts.deleted_by_coach_at IS NOT NULL'
    );
    $check->execute([$sessionId, $coachId]);
    if ($check->fetch()) {
        $pdo->prepare('UPDATE training_sessions SET deleted_by_coach_at = NULL, deleted_by_coach_id = NULL WHERE id = ?')
            ->execute([$sessionId]);
        flash('success', 'Trenink byl obnoven.');
    } else {
        flash('danger', 'Smazany trenink nebyl nalezen.');
    }

    redirect(BASE_URL . '/admin/coach_deleted_trainings.php?coach_id=' . $coachId);
}

$stmt = $pdo->prepare(
        'SELECT ts.id, ts.started_at, ts.completed_at, ts.location, ts.deleted_by_coach_at,
                        ts.deleted_by_coach_id,
            ws.name AS set_name,
                        c.name AS coach_name,
                        c.username AS coach_username,
                        dc.name AS deleted_by_name,
                        dc.username AS deleted_by_username,
            a.first_name, a.last_name,
            (SELECT COUNT(*) FROM session_series ss WHERE ss.session_id = ts.id) AS total_series
     FROM training_sessions ts
     JOIN athletes a ON a.id = ts.athlete_id
     JOIN workout_sets ws ON ws.id = ts.workout_set_id
         JOIN coaches c ON c.id = a.coach_id
         LEFT JOIN coaches dc ON dc.id = ts.deleted_by_coach_id
     WHERE a.coach_id = ?
       AND ts.deleted_by_coach_at IS NOT NULL
     ORDER BY ts.deleted_by_coach_at DESC, ts.started_at DESC'
);
$stmt->execute([$coachId]);
$deletedSessions = $stmt->fetchAll();

renderAdminHeader('Smazane treninky');
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/admin/coaches.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Zpet
    </a>
    <h4 class="fw-bold mb-0">
        <i class="fas fa-folder-open me-2 text-danger"></i>
        Smazane treninky: <?= h($coach['name'] ?: $coach['username']) ?>
    </h4>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($deletedSessions)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
            Zadny smazany trenink.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Sportovec</th>
                        <th>Trenér</th>
                        <th>Sada</th>
                        <th>Datum</th>
                        <th class="text-center">Serii</th>
                        <th>Smazal</th>
                        <th>Smazano</th>
                        <th class="text-end">Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deletedSessions as $i => $s): ?>
                    <tr>
                        <td class="text-muted small"><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($s['first_name'] . ' ' . $s['last_name']) ?></td>
                        <td><?= h(($s['coach_name'] ?: $s['coach_username'])) ?></td>
                        <td><span class="badge bg-secondary"><?= h($s['set_name']) ?></span></td>
                        <td><?= formatDateTime($s['completed_at'] ?: $s['started_at']) ?></td>
                        <td class="text-center"><span class="badge bg-dark"><?= (int)$s['total_series'] ?></span></td>
                        <td class="text-muted small">
                            <?= h(($s['deleted_by_name'] ?: $s['deleted_by_username'] ?: ($s['coach_name'] ?: $s['coach_username']))) ?>
                        </td>
                        <td class="text-muted small"><?= formatDateTime($s['deleted_by_coach_at']) ?></td>
                        <td class="text-end">
                            <form method="post" class="d-inline" onsubmit="return confirm('Obnovit tento trenink?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm">
                                    <i class="fas fa-rotate-left me-1"></i>Obnovit
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php renderAdminFooter();
