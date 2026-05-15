<?php
// setup_admin.php – jednorázové vytvoření prvního superadmin účtu
// Po vytvoření účtu tento soubor smažte!
require_once __DIR__ . '/config/config.php';
if (!defined('ENABLE_SETUP_ADMIN') || ENABLE_SETUP_ADMIN !== true) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '404 Not Found';
    exit;
}
require_once __DIR__ . '/config/database.php';

$error   = null;
$success = false;

$pdo = getDB(); // spustí migrace (vytvoří tabulku superadmins)

// Pokud již existuje alespoň jeden superadmin, stránku zablokovat
$existing = (int)$pdo->query('SELECT COUNT(*) FROM superadmins')->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $existing === 0) {
    $username  = trim($_POST['username']  ?? '');
    $name      = trim($_POST['name']      ?? '');
    $email     = trim($_POST['email']     ?? '');
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($username === '' || strlen($username) < 3) {
        $error = 'Zadejte uživatelské jméno (min. 3 znaky).';
    } elseif (strlen($password) < 8) {
        $error = 'Heslo superadmina musí mít alespoň 8 znaků.';
    } elseif ($password !== $password2) {
        $error = 'Hesla se neshodují.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Neplatná e-mailová adresa.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare(
            'INSERT INTO superadmins (username, password, name, email) VALUES (?, ?, ?, ?)'
        )->execute([$username, $hash, $name ?: null, $email ?: null]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nastavení superadmina – <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { background: #0f0f1a; min-height: 100vh; display: flex; align-items: center; }
    </style>
</head>
<body>
<div class="container" style="max-width:500px">
    <div class="text-center mb-4">
        <div class="display-4 mb-2" style="color:#a78bfa">
            <i class="fas fa-shield-halved"></i>
        </div>
        <h2 class="text-white fw-bold"><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="text-secondary">Nastavení superadministrátora</p>
    </div>

    <div class="card shadow-lg border-0" style="background:#1e1e2e;border:1px solid #312e81 !important">
        <div class="card-body p-4">

        <?php if ($existing > 0): ?>
            <div class="alert alert-warning">
                <i class="fas fa-lock me-2"></i>
                <strong>Přístup odepřen.</strong><br>
                Superadmin účet již existuje. Smažte tento soubor ze serveru.
            </div>
            <a href="<?= htmlspecialchars(BASE_URL . '/login_admin.php', ENT_QUOTES, 'UTF-8') ?>"
               class="btn w-100" style="background:#7c3aed;color:#fff">
                Přejít na přihlášení admina
            </a>

        <?php elseif ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                <strong>Superadmin byl vytvořen!</strong><br>
                <span class="text-danger fw-bold">⚠ Ihned smažte soubor <code>setup_admin.php</code> ze serveru!</span>
            </div>
            <a href="<?= htmlspecialchars(BASE_URL . '/login_admin.php', ENT_QUOTES, 'UTF-8') ?>"
               class="btn w-100 fw-bold" style="background:#7c3aed;color:#fff">
                <i class="fas fa-sign-in-alt me-1"></i>Přihlásit se do administrace
            </a>

        <?php else: ?>
            <h5 class="text-white mb-4">
                <i class="fas fa-user-shield me-2" style="color:#a78bfa"></i>Vytvořit superadmin účet
            </h5>

            <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <div class="mb-3">
                    <label class="form-label text-secondary fw-semibold">Celé jméno</label>
                    <input type="text" name="name" class="form-control bg-dark text-white border-secondary"
                           value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Jan Správce">
                </div>
                <div class="mb-3">
                    <label class="form-label text-secondary fw-semibold">
                        Uživatelské jméno <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="username" class="form-control bg-dark text-white border-secondary"
                           value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           required autofocus autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label text-secondary fw-semibold">E-mail</label>
                    <input type="email" name="email" class="form-control bg-dark text-white border-secondary"
                           value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label text-secondary fw-semibold">
                        Heslo <span class="text-danger">*</span>
                        <small class="text-muted fw-normal">(min. 8 znaků)</small>
                    </label>
                    <input type="password" name="password" class="form-control bg-dark text-white border-secondary"
                           required autocomplete="new-password">
                </div>
                <div class="mb-4">
                    <label class="form-label text-secondary fw-semibold">
                        Heslo znovu <span class="text-danger">*</span>
                    </label>
                    <input type="password" name="password2" class="form-control bg-dark text-white border-secondary"
                           required autocomplete="new-password">
                </div>
                <button type="submit" class="btn w-100 fw-bold py-2"
                        style="background:#7c3aed;color:#fff;border:none">
                    <i class="fas fa-shield-halved me-2"></i>Vytvořit superadmin účet
                </button>
            </form>

            <div class="mt-3 p-2 rounded small text-warning" style="background:#1a1200">
                <i class="fas fa-exclamation-triangle me-1"></i>
                Po vytvoření účtu <strong>okamžitě smažte</strong> soubor <code>setup_admin.php</code> z vašeho serveru!
            </div>
        <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
