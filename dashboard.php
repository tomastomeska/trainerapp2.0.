<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$pdo     = getDB();

// Načtení sportovců s doplňkovými info
$stmt = $pdo->prepare(
    'SELECT a.*, 
            TIMESTAMPDIFF(YEAR, a.birth_date, CURDATE()) AS age,
            (SELECT COUNT(*) FROM training_sessions ts
                         WHERE ts.athlete_id = a.id
                             AND ts.completed_at IS NOT NULL
                             AND ts.deleted_by_coach_at IS NULL) AS session_count,
            (SELECT ts2.started_at FROM training_sessions ts2
                         WHERE ts2.athlete_id = a.id
                             AND ts2.completed_at IS NOT NULL
                             AND ts2.deleted_by_coach_at IS NULL
             ORDER BY ts2.completed_at DESC LIMIT 1) AS last_session_date,
            (SELECT ws.name FROM training_sessions ts3
             JOIN workout_sets ws ON ts3.workout_set_id = ws.id
                         WHERE ts3.athlete_id = a.id
                             AND ts3.completed_at IS NOT NULL
                             AND ts3.deleted_by_coach_at IS NULL
                         ORDER BY ts3.completed_at DESC LIMIT 1) AS last_set_name,
            (SELECT ts4.id FROM training_sessions ts4
                                                 WHERE ts4.athlete_id = a.id
                                                     AND ts4.completed_at IS NULL
                                                     AND ts4.deleted_by_coach_at IS NULL
                         ORDER BY ts4.started_at DESC LIMIT 1) AS active_session_id,
            (SELECT ts4.paired_session_id FROM training_sessions ts4
                                                 WHERE ts4.athlete_id = a.id
                                                     AND ts4.completed_at IS NULL
                                                     AND ts4.deleted_by_coach_at IS NULL
                         ORDER BY ts4.started_at DESC LIMIT 1) AS active_paired_session_id,
            (SELECT ts4.started_at FROM training_sessions ts4
                                                 WHERE ts4.athlete_id = a.id
                                                     AND ts4.completed_at IS NULL
                                                     AND ts4.deleted_by_coach_at IS NULL
                         ORDER BY ts4.started_at DESC LIMIT 1) AS active_session_started_at,
            (SELECT ws.name FROM training_sessions ts4
             JOIN workout_sets ws ON ws.id = ts4.workout_set_id
                                                 WHERE ts4.athlete_id = a.id
                                                     AND ts4.completed_at IS NULL
                                                     AND ts4.deleted_by_coach_at IS NULL
                         ORDER BY ts4.started_at DESC LIMIT 1) AS active_set_name,
            (SELECT w.weight_kg FROM athlete_weight_logs w WHERE w.athlete_id = a.id ORDER BY w.measured_at DESC LIMIT 1) AS current_weight,
                        (SELECT w.weight_kg FROM athlete_weight_logs w WHERE w.athlete_id = a.id ORDER BY w.measured_at ASC LIMIT 1) AS initial_weight,
                        (SELECT COUNT(*) FROM athlete_meal_plans amp
                         WHERE amp.athlete_id = a.id
                             AND amp.removed_at IS NULL) AS active_meal_plan_count
     FROM athletes a
     WHERE a.coach_id = ?
     ORDER BY a.last_name, a.first_name'
);
$stmt->execute([$coachId]);
$athletes = $stmt->fetchAll();

$activeSessionsStmt = $pdo->prepare(
    'SELECT ts.id AS session_id,
            ts.athlete_id,
            ts.paired_session_id,
            ts.started_at,
            a.first_name,
            a.last_name,
            ws.name AS set_name
     FROM training_sessions ts
     JOIN athletes a ON a.id = ts.athlete_id
     JOIN workout_sets ws ON ws.id = ts.workout_set_id
     WHERE a.coach_id = ?
       AND ts.completed_at IS NULL
       AND ts.deleted_by_coach_at IS NULL
     ORDER BY COALESCE(ts.paired_session_id, ts.id) DESC, ts.started_at DESC'
);
$activeSessionsStmt->execute([$coachId]);
$activeSessions = $activeSessionsStmt->fetchAll();

$activeIndividualSessions = [];
$activePairedSessions = [];
foreach ($activeSessions as $session) {
    if (!empty($session['paired_session_id'])) {
        $pairedId = (int)$session['paired_session_id'];
        if (!isset($activePairedSessions[$pairedId])) {
            $activePairedSessions[$pairedId] = [
                'paired_session_id' => $pairedId,
                'started_at' => $session['started_at'],
                'sessions' => [],
            ];
        }
        $activePairedSessions[$pairedId]['sessions'][] = $session;
        continue;
    }

    $activeIndividualSessions[] = $session;
}

// Dnešní plán z kalendáře (neproběhlé + právě probíhající)
$todayCalendarStmt = $pdo->prepare(
        "SELECT e.id,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
            a.first_name,
            a.last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     WHERE e.coach_id = ?
             AND e.approval_status = 'approved'
       AND e.starts_at >= CURDATE()
       AND e.starts_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
       AND e.ends_at > NOW()
         ORDER BY e.starts_at ASC, e.id ASC"
);
$todayCalendarStmt->execute([$coachId]);
$todayPlannedEvents = $todayCalendarStmt->fetchAll();

$now = new DateTimeImmutable('now');

$formatCalendarEventPerson = static function (array $event): string {
    if (!empty($event['first_name']) || !empty($event['last_name'])) {
        return trim((string)($event['first_name'] ?? '') . ' ' . (string)($event['last_name'] ?? ''));
    }
    if (!empty($event['custom_title'])) {
        return (string)$event['custom_title'];
    }
    return 'Trénink bez názvu';
};

$ongoingTodayEvents = [];
$nextTodayEvent = null;

foreach ($todayPlannedEvents as $event) {
    $eventStart = new DateTimeImmutable($event['starts_at']);
    $eventEnd = new DateTimeImmutable($event['ends_at']);

    if ($eventStart <= $now && $eventEnd > $now) {
        $ongoingTodayEvents[] = $event;
        continue;
    }

    if ($eventStart > $now && $nextTodayEvent === null) {
        $nextTodayEvent = $event;
    }
}

$todayPendingCount = count($todayPlannedEvents);

$minutesToNextTodayEvent = null;
if ($nextTodayEvent !== null) {
    $nextStart = new DateTimeImmutable($nextTodayEvent['starts_at']);
    $minutesToNextTodayEvent = (int)max(0, ceil(($nextStart->getTimestamp() - $now->getTimestamp()) / 60));
}

// Zítřejší plán z kalendáře
$tomorrowStart = $now->modify('tomorrow')->setTime(0, 0, 0);
$tomorrowEnd = $tomorrowStart->modify('+1 day');

$tomorrowCalendarStmt = $pdo->prepare(
        "SELECT e.id,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
            a.first_name,
            a.last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     WHERE e.coach_id = ?
             AND e.approval_status = 'approved'
       AND e.starts_at >= ?
       AND e.starts_at < ?
         ORDER BY e.starts_at ASC, e.id ASC"
);
$tomorrowCalendarStmt->execute([
    $coachId,
    $tomorrowStart->format('Y-m-d H:i:s'),
    $tomorrowEnd->format('Y-m-d H:i:s'),
]);
$tomorrowPlannedEvents = $tomorrowCalendarStmt->fetchAll();

$tomorrowCount = count($tomorrowPlannedEvents);
$firstTomorrowEvent = $tomorrowPlannedEvents[0] ?? null;
$firstTomorrowTime = null;
if ($firstTomorrowEvent) {
    $firstTomorrowTime = (new DateTimeImmutable($firstTomorrowEvent['starts_at']))->format('H:i');
}

// Kontrola a zobrazení obrázku sportovce
$athletePhotoPath = BASE_URL . '/uploads/athletes/' . ($athlete['photo'] ?? 'default.jpg');
if (!file_exists(__DIR__ . '/../uploads/athletes/' . ($athlete['photo'] ?? 'default.jpg'))) {
    $athletePhotoPath = BASE_URL . '/uploads/athletes/default.jpg';
}

renderHeader('Dashboard');
?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <div class="text-muted small text-uppercase fw-semibold">Dnešní plán z kalendáře</div>
                <div class="fw-bold fs-4"><?= (int)$todayPendingCount ?> neproběhlých tréninků</div>
            </div>

            <div class="d-flex flex-wrap gap-2 align-items-stretch">
                <?php if (!empty($ongoingTodayEvents)): ?>
                    <?php $current = $ongoingTodayEvents[0]; ?>
                    <div class="border rounded-3 bg-success-subtle text-success-emphasis px-4 py-3 text-start fw-semibold" style="max-width: 420px; font-size: 1.08rem; line-height: 1.4;">
                        <i class="fas fa-circle-play me-1"></i>
                        Probíhá:
                        <strong><?= h($formatCalendarEventPerson($current)) ?></strong>
                        <?php if (!empty($current['location'])): ?>
                            · <?= h($current['location']) ?>
                        <?php endif; ?>
                        <?php if (count($ongoingTodayEvents) > 1): ?>
                            <span class="d-block mt-1">+ další <?= count($ongoingTodayEvents) - 1 ?></span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="border rounded-3 bg-light text-muted px-4 py-3 fw-semibold" style="font-size: 1.08rem; line-height: 1.4;">Aktuálně nic neprobíhá</div>
                <?php endif; ?>

                <?php if ($nextTodayEvent !== null && $minutesToNextTodayEvent !== null): ?>
                <div class="border rounded-3 bg-warning-subtle text-dark px-4 py-3 text-start fw-semibold" style="max-width: 460px; font-size: 1.08rem; line-height: 1.4;">
                    <i class="fas fa-clock me-1"></i>
                    Za <?= (int)$minutesToNextTodayEvent ?> min:
                    <strong><?= h($formatCalendarEventPerson($nextTodayEvent)) ?></strong>
                    <?php if (!empty($nextTodayEvent['location'])): ?>
                        · <?= h($nextTodayEvent['location']) ?>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="border rounded-3 bg-light text-muted px-4 py-3 fw-semibold" style="font-size: 1.08rem; line-height: 1.4;">Dnes už další trénink nezačíná</div>
                <?php endif; ?>

                <div class="border rounded-3 bg-info-subtle text-dark px-4 py-3 text-start fw-semibold" style="max-width: 500px; font-size: 1.08rem; line-height: 1.4;">
                    <i class="fas fa-calendar-day me-1"></i>
                    Zítra naplánováno: <strong><?= (int)$tomorrowCount ?></strong>
                    <?php if ($firstTomorrowEvent): ?>
                        <span class="d-block mt-1">
                            První: <strong><?= h($formatCalendarEventPerson($firstTomorrowEvent)) ?></strong>
                            v <?= h((string)$firstTomorrowTime) ?>
                            <?php if (!empty($firstTomorrowEvent['location'])): ?>
                                · <?= h($firstTomorrowEvent['location']) ?>
                            <?php endif; ?>
                        </span>
                    <?php else: ?>
                        <span class="d-block mt-1">Bez naplánovaného tréninku</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-users me-2 text-warning"></i>Moji sportovci</h2>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (count($athletes) >= 2): ?>
        <a href="<?= BASE_URL ?>/training_paired_start.php" class="btn btn-sm fw-bold btn-paired-highlight">
            <i class="fas fa-people-group me-1"></i>Párový trénink
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/athlete_add.php" class="btn btn-warning btn-sm fw-bold">
            <i class="fas fa-plus me-1"></i>Přidat sportovce
        </a>
    </div>
</div>

<?php if (!empty($activeIndividualSessions) || !empty($activePairedSessions)): ?>
<div class="card border-0 shadow-sm mb-3" id="active-trainings">
    <div class="card-header bg-dark text-white fw-bold py-2">
        <i class="fas fa-stopwatch me-2"></i>Aktivní tréninky
    </div>
    <div class="card-body py-3">
        <?php if (!empty($activeIndividualSessions)): ?>
        <div class="mb-3">
            <div class="fw-semibold mb-2 small text-uppercase text-muted">Individuální</div>
            <div class="row g-2 row-cols-1 row-cols-md-2 row-cols-xl-3">
                <?php foreach ($activeIndividualSessions as $session): ?>
                <div class="col">
                    <div class="border rounded-3 p-2 h-100 bg-light d-flex flex-column gap-1">
                        <div class="fw-bold small"><?= h($session['first_name'] . ' ' . $session['last_name']) ?></div>
                        <div class="text-muted small"><?= h($session['set_name']) ?> · <?= formatDateTime($session['started_at']) ?></div>
                        <a href="<?= BASE_URL ?>/training_session.php?id=<?= (int)$session['session_id'] ?>"
                           class="btn btn-sm btn-warning fw-bold align-self-start">
                            <i class="fas fa-play me-1"></i>Pokračovat
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($activePairedSessions)): ?>
        <div>
            <div class="fw-semibold mb-2 small text-uppercase text-muted">Párové</div>
            <div class="row g-2 row-cols-1 row-cols-md-2 row-cols-xl-3">
                <?php foreach ($activePairedSessions as $pair): ?>
                <div class="col">
                    <div class="border rounded-3 p-2 h-100 bg-light d-flex flex-column gap-1">
                        <div class="fw-bold small">
                            <i class="fas fa-people-group me-1 text-info"></i>Párový trénink
                        </div>
                        <div class="text-muted small">
                            <?= count($pair['sessions']) ?> sportovci · <?= formatDateTime($pair['started_at']) ?>
                        </div>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($pair['sessions'] as $session): ?>
                            <span class="badge bg-white text-dark border small">
                                <?= h($session['first_name'] . ' ' . $session['last_name']) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <a href="<?= BASE_URL ?>/training_paired_session.php?id=<?= (int)$pair['paired_session_id'] ?>"
                           class="btn btn-sm btn-info text-dark fw-bold align-self-start">
                            <i class="fas fa-play me-1"></i>Pokračovat společně
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (empty($athletes)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <div class="display-3 text-muted mb-3">🏃</div>
        <h4 class="text-muted">Zatím nemáte žádné sportovce</h4>
        <p class="text-muted">Přidejte prvního sportovce a začněte trénovat!</p>
        <a href="<?= BASE_URL ?>/athlete_add.php" class="btn btn-warning fw-bold">
            <i class="fas fa-plus me-1"></i>Přidat sportovce
        </a>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($athletes as $a): ?>
    <div class="col-md-6 col-xl-4">
        <div class="card athlete-card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-center mb-3">
                    <?php if ($a['photo']): ?>
                    <img src="<?= h(photoUrl($a['photo'], 'athletes')) ?>" alt="Fotografie"
                         class="rounded-circle"
                         style="width:100px;height:100px;object-fit:cover;border:3px solid #ffc107;">
                    <?php else: ?>
                    <?php $initials = strtoupper(mb_substr($a['first_name'], 0, 1, 'UTF-8') . mb_substr($a['last_name'], 0, 1, 'UTF-8')); ?>
                    <div class="avatar-initials" title="<?= h($a['first_name'] . ' ' . $a['last_name']) ?>">
                        <?= $initials ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h5 class="card-title mb-0 fw-bold">
                            <?= h($a['first_name'] . ' ' . $a['last_name']) ?>
                        </h5>
                        <?php $age = calculateAge($a['birth_date'] ?? null); ?>
                        <small class="text-muted"><?= $age !== null ? $age . ' let' : '' ?></small>
                    </div>
                    <span class="badge bg-warning text-dark rounded-pill fs-6">
                        <?= $a['session_count'] ?>×
                    </span>
                </div>

                <?php if ($a['active_session_id']): ?>
                <div class="mb-3">
                    <span class="badge <?= $a['active_paired_session_id'] ? 'bg-info text-dark' : 'bg-success' ?> me-1">
                        <i class="fas <?= $a['active_paired_session_id'] ? 'fa-people-group' : 'fa-circle-play' ?> me-1"></i>
                        <?= $a['active_paired_session_id'] ? 'Probíhá párový trénink' : 'Probíhá trénink' ?>
                    </span>
                    <span class="badge bg-light text-dark border">
                        <i class="fas fa-layer-group me-1"></i><?= h($a['active_set_name'] ?? '') ?>
                    </span>
                    <?php if (!empty($a['active_session_started_at'])): ?>
                    <div class="small text-muted mt-1">
                        <i class="fas fa-clock me-1"></i>Od <?= formatDateTime($a['active_session_started_at']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($a['email']): ?>
                <p class="text-muted small mb-2">
                    <i class="fas fa-envelope me-1"></i><?= h($a['email']) ?>
                </p>
                <?php endif; ?>

                <?php if ($a['phone_contact']): ?>
                <p class="text-muted small mb-2">
                    <i class="fas fa-phone me-1"></i><?= h($a['phone_contact']) ?>
                </p>
                <?php endif; ?>

                <div class="mb-3">
                    <span class="badge bg-light text-dark border me-1">
                        <i class="fas fa-utensils me-1"></i>Jídelníčky: <?= (int)$a['active_meal_plan_count'] ?>
                    </span>
                    <?php if ($a['last_session_date']): ?>
                    <span class="badge bg-light text-dark border me-1">
                        <i class="fas fa-clock me-1"></i>Poslední trénink: <?= formatDate($a['last_session_date']) ?>
                    </span>
                    <?php if ($a['last_set_name']): ?>
                    <span class="badge bg-secondary">
                        <i class="fas fa-layer-group me-1"></i><?= h($a['last_set_name']) ?>
                    </span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="badge bg-light text-muted border">Žádný trénink</span>
                    <?php endif; ?>
                </div>

                <div class="d-flex gap-2">
                    <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= $a['id'] ?>"
                       class="btn btn-dark btn-sm flex-fill">
                        <i class="fas fa-user me-1"></i>Detail
                    </a>
                    <?php if ($a['active_session_id']): ?>
                    <a href="<?= $a['active_paired_session_id'] ? BASE_URL . '/training_paired_session.php?id=' . (int)$a['active_paired_session_id'] : BASE_URL . '/training_session.php?id=' . (int)$a['active_session_id'] ?>"
                       class="btn <?= $a['active_paired_session_id'] ? 'btn-info text-dark' : 'btn-warning' ?> btn-sm flex-fill fw-bold">
                        <i class="fas fa-play me-1"></i>Pokračovat
                    </a>
                    <?php else: ?>
                    <a href="<?= BASE_URL ?>/training_new.php?athlete_id=<?= $a['id'] ?>"
                       class="btn btn-warning btn-sm flex-fill">
                        <i class="fas fa-play me-1"></i>Trénink
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
