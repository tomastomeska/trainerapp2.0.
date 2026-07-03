<?php
// admin/coaches.php - sprava treneru
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();

// Rychly toggle aktivity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatny bezpecnostni token.');
        redirect(BASE_URL . '/admin/coaches.php');
    }
    $cid = intParam($_POST, 'coach_id');
    $pdo->prepare('UPDATE coaches SET is_active = 1 - is_active WHERE id = ?')->execute([$cid]);
    flash('success', 'Stav trenera byl zmenen.');
    redirect(BASE_URL . '/admin/coaches.php');
}

// Všichni trenéři se statistikami (razeni dle posledniho prihlaseni)
$coaches = $pdo->query(
    'SELECT c.*,
            (SELECT COUNT(*) FROM athletes a WHERE a.coach_id = c.id) AS athlete_count,
            (SELECT COUNT(*) FROM exercises e WHERE e.coach_id = c.id) AS exercise_count,
            (SELECT COUNT(*)
             FROM training_sessions ts
             JOIN athletes a2 ON a2.id = ts.athlete_id
             WHERE a2.coach_id = c.id
               AND ts.completed_at IS NOT NULL
               AND ts.deleted_by_coach_at IS NULL) AS session_count,
            (SELECT COUNT(*)
             FROM training_sessions ts
             JOIN athletes a3 ON a3.id = ts.athlete_id
             WHERE a3.coach_id = c.id
               AND ts.deleted_by_coach_at IS NOT NULL) AS deleted_session_count
     FROM coaches c
     ORDER BY c.last_login DESC, c.created_at DESC'
)->fetchAll();

renderAdminHeader('Trenéři');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0">
        <i class="fas fa-user-tie me-2" style="color:#a78bfa"></i>Správa trenérů
        <span class="badge ms-2" style="background:#312e81"><?= count($coaches) ?></span>
    </h4>
    <a href="<?= BASE_URL ?>/admin/coach_add.php" class="btn fw-bold" style="background:#7c3aed;color:#fff;border:none">
        <i class="fas fa-plus me-1"></i>Přidat trenéra
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($coaches)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-user-slash fa-3x mb-3 d-block"></i>
            Zatím žádní trenéři. <a href="<?= BASE_URL ?>/admin/coach_add.php">Přidat prvního trenéra.</a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Trenér</th>
                    <th>E-mail</th>
                    <th>Poslední přihlášení</th>
                    <th class="text-center">Sport.</th>
                    <th class="text-center">Cviky</th>
                    <th class="text-center">Trén.</th>
                    <th class="text-center">Smaz.</th>
                    <th>Stav</th>
                    <th class="text-end">Detail</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($coaches as $i => $c): ?>
                <?php $detailId = 'coachDetail' . (int)$c['id']; ?>
                <tr class="<?= $c['is_active'] ? '' : 'table-secondary' ?>">
                    <td>
                        <div class="fw-semibold"><?= h($c['name'] ?: 'Bez jména') ?></div>
                        <div class="small text-muted">#<?= $i + 1 ?> · @<?= h($c['username']) ?></div>
                    </td>
                    <td>
                        <?php if (!empty($c['email'])): ?>
                        <a href="mailto:<?= h($c['email']) ?>"><?= h($c['email']) ?></a>
                        <?php else: ?>
                        <span class="text-muted">Bez e-mailu</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap"><?= $c['last_login'] ? formatDateTime($c['last_login']) : 'Nikdy' ?></td>
                    <td class="text-center">
                        <a href="<?= BASE_URL ?>/admin/coach_athletes.php?coach_id=<?= (int)$c['id'] ?>" class="badge text-bg-warning text-decoration-none" title="Zobrazit sportovce trenéra"><?= (int)$c['athlete_count'] ?></a>
                    </td>
                    <td class="text-center"><span class="badge text-bg-primary"><?= (int)$c['exercise_count'] ?></span></td>
                    <td class="text-center"><span class="badge text-bg-info"><?= (int)$c['session_count'] ?></span></td>
                    <td class="text-center"><span class="badge text-bg-danger"><?= (int)$c['deleted_session_count'] ?></span></td>
                    <td>
                        <?php if ($c['is_active']): ?>
                        <span class="badge bg-success"><i class="fas fa-check me-1"></i>Aktivní</span>
                        <?php else: ?>
                        <span class="badge bg-secondary"><i class="fas fa-ban me-1"></i>Blokován</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $detailId ?>" aria-expanded="false" aria-controls="<?= $detailId ?>">
                            <i class="fas fa-chevron-down me-1"></i>Rozbalit
                        </button>
                    </td>
                </tr>
                <tr>
                    <td colspan="9" class="p-0 border-0">
                        <div id="<?= $detailId ?>" class="collapse px-3 pt-2 pb-3 bg-body-tertiary border-top">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div class="small text-muted">Přidán: <?= formatDate($c['created_at']) ?></div>
                                <a href="<?= BASE_URL ?>/admin/coach_deleted_trainings.php?coach_id=<?= (int)$c['id'] ?>" class="btn btn-outline-danger btn-sm" title="Smazané tréninky">
                                    <i class="fas fa-folder-open me-1"></i>Smazané tréninky
                                </a>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <?php if ($c['is_active']): ?>
                                <form method="post" action="<?= BASE_URL ?>/admin/impersonate.php">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="coach_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Přepnout do profilu trenéra" onclick="return confirm('Přepnout do profilu trenéra <?= h(addslashes($c['name'] ?: $c['username'])) ?>?')">
                                        <i class="fas fa-user-secret me-1"></i>Přepnout profil
                                    </button>
                                </form>
                                <?php endif; ?>
                                <a href="<?= BASE_URL ?>/admin/coach_edit.php?id=<?= $c['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Upravit">
                                    <i class="fas fa-edit me-1"></i>Upravit
                                </a>
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="coach_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $c['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $c['is_active'] ? 'Blokovat' : 'Aktivovat' ?>">
                                        <i class="fas fa-<?= $c['is_active'] ? 'ban' : 'check' ?> me-1"></i><?= $c['is_active'] ? 'Blokovat' : 'Aktivovat' ?>
                                    </button>
                                </form>
                                <a href="<?= BASE_URL ?>/admin/coach_delete.php?id=<?= $c['id'] ?>" class="btn btn-outline-danger btn-sm" title="Smazat">
                                    <i class="fas fa-trash me-1"></i>Smazat
                                </a>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php renderAdminFooter(); ?>
