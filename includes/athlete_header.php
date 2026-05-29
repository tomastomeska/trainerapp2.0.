<?php
function renderAthleteHeader(string $title = '', bool $withCharts = false): void {
    $athlete = getCurrentAthlete();
    $flash = getFlash();
    $fullTitle = $title !== '' ? ($title . ' - ' . APP_NAME) : APP_NAME;
    $unread = 0;

    if ($athlete) {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM athlete_notifications WHERE athlete_id = ? AND read_at IS NULL');
            $stmt->execute([(int)$athlete['id']]);
            $unread = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $unread = 0;
        }
    }
    ?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($fullTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <?php if ($withCharts): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <?php endif; ?>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm sticky-top">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold text-warning" href="<?= BASE_URL ?>/athlete_dashboard.php">
            <i class="fas fa-dumbbell me-2"></i><?= h(APP_NAME) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#athleteNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="athleteNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/athlete_dashboard.php"><i class="fas fa-user me-1"></i>Profil</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/athlete_calendar.php"><i class="fas fa-calendar-alt me-1"></i>Kalendář</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/athlete_payments.php"><i class="fas fa-wallet me-1"></i>Platby</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/athlete_mealplans.php"><i class="fas fa-utensils me-1"></i>Jídelníčky</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/athlete_graphs.php"><i class="fas fa-chart-line me-1"></i>Grafy</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/athlete_gallery.php"><i class="fas fa-images me-1"></i>Galerie</a></li>
                <li class="nav-item"><a class="nav-link position-relative" href="<?= BASE_URL ?>/athlete_zpravy.php"><i class="fas fa-envelope me-1"></i>Zprávy
                    <?php if ($unread > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.65rem"><?= $unread ?></span>
                    <?php endif; ?>
                </a></li>
            </ul>
            <div class="navbar-nav">
                <?php if ($athlete): ?>
                <span class="nav-link text-secondary"><i class="fas fa-user me-1"></i><?= h(trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name'])) ?></span>
                <?php endif; ?>
                <a class="nav-link text-danger" href="<?= BASE_URL ?>/logout.php"><i class="fas fa-sign-out-alt me-1"></i>Odhlásit</a>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid px-3 px-md-4 py-3 py-md-4">
<?php if ($flash): ?>
<div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
    <?= h($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php
}

function renderAthleteFooter(): void {
    ?>
</div>
<footer class="footer mt-auto py-3 bg-dark text-center text-secondary">
    <small><?= h(APP_NAME) ?> &copy; <?= date('Y') ?></small>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>
<?php
}
