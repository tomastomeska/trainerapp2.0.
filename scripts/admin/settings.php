<?php
// admin/settings.php – obecná nastavení aplikace (verze apod.)
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo     = getDB();
$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $version = trim($_POST['app_version'] ?? '');
        if ($version === '') {
            $error = 'Číslo verze nesmí být prázdné.';
        } elseif (!preg_match('/^[\w.\-]+$/', $version)) {
            $error = 'Číslo verze obsahuje nepovolené znaky. Povoleno: písmena, číslice, tečka, pomlčka.';
        } else {
            $pdo->prepare(
                'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            )->execute(['app_version', $version]);
            $success = 'Verze aplikace byla nastavena na "' . $version . '".';
        }
    }
}

$currentVersion = getAppSetting('app_version', APP_VERSION);

renderAdminHeader('Nastavení aplikace');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0">
        <i class="fas fa-sliders me-2" style="color:#a78bfa"></i>Nastavení aplikace
    </h4>
</div>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-circle me-1"></i><?= h($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle me-1"></i><?= h($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="max-width:480px">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-tag me-2"></i>Číslo verze aplikace
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <div class="mb-3">
                <label for="versionInput" class="form-label fw-semibold">Aktuální verze</label>
                <input type="text" name="app_version" id="versionInput"
                       class="form-control form-control-lg fw-bold"
                       value="<?= h($currentVersion) ?>"
                       placeholder="např. 1.2.0"
                       required>
                <div class="form-text">
                    Zobrazuje se pod přihlašovacím formulářem. Povolený formát: <code>1.0.0</code>, <code>2.1.3-beta</code> apod.
                </div>
            </div>
            <button type="submit" class="btn fw-bold"
                    style="background:#7c3aed;color:#fff;border:none">
                <i class="fas fa-save me-1"></i>Uložit verzi
            </button>
        </form>
    </div>
</div>

<?php
echo '</div></div></div>';
echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>';
echo '</body></html>';
?>
