<?php
// login_admin.php – přihlašovací stránka superadministrátora
// Tato stránka není linkována z aplikace – přístup pouze přes přímou URL.
require_once __DIR__ . '/includes/admin_auth.php';

$adminBase = adminBaseUrl();

// Přesměrovat přihlášeného admina
if (isAdminLoggedIn()) {
    redirect($adminBase . '/admin/coaches.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token. Zkuste to znovu.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Vyplňte uživatelské jméno i heslo.';
        } else {
            $pdo  = getDB();
            $stmt = $pdo->prepare('SELECT id, password, name FROM superadmins WHERE username = ?');
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password'])) {
                session_regenerate_id(true);
                $_SESSION['superadmin_id']   = $admin['id'];
                $_SESSION['superadmin_name'] = $admin['name'] ?: $username;
                // Aktualizace posledního přihlášení nesmí blokovat samotné přihlášení.
                try {
                    $pdo->prepare('UPDATE superadmins SET last_login = NOW() WHERE id = ?')->execute([$admin['id']]);
                } catch (Throwable $e) {
                    error_log('Admin last_login update failed: ' . $e->getMessage());
                }
                redirect($adminBase . '/admin/coaches.php');
            } else {
                // Záměrné zpoždění pro ochranu proti brute-force
                usleep(500000);
                $error = 'Nesprávné přihlašovací údaje.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrace – <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $adminBase ?>/assets/css/style.css">
    <style>
        body { background: #0f0f1a; min-height: 100vh; display: flex; align-items: center; }
        .admin-card { border: 1px solid #312e81; background: #1e1e2e; }
        .admin-logo { color: #a78bfa; }
    </style>
</head>
<body>
<div class="container" style="max-width:420px">
    <div class="text-center mb-4">
        <div class="display-4 admin-logo mb-2">
            <i class="fas fa-shield-halved"></i>
        </div>
        <h2 class="text-white fw-bold"><?= APP_NAME ?></h2>
        <p class="text-secondary">Administrátorský přístup</p>
    </div>
    <div class="card shadow-lg border-0 admin-card">
        <div class="card-body p-4">
            <h5 class="card-title mb-4 text-center text-white">
                <i class="fas fa-user-shield me-2" style="color:#a78bfa"></i>Přihlásit se
            </h5>

            <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label text-secondary fw-semibold">Uživatelské jméno</label>
                    <input type="text" name="username" class="form-control bg-dark text-white border-secondary"
                           value="<?= h($_POST['username'] ?? '') ?>"
                           required autofocus autocomplete="username">
                </div>
                <div class="mb-4">
                    <label class="form-label text-secondary fw-semibold">Heslo</label>
                    <input type="password" name="password" class="form-control bg-dark text-white border-secondary"
                           required autocomplete="current-password">
                </div>
                <button type="submit" class="btn w-100 fw-bold py-2"
                        style="background:#7c3aed;color:#fff;border:none">
                    <i class="fas fa-sign-in-alt me-2"></i>Přihlásit se
                </button>
            </form>
        </div>
    </div>
    <p class="text-center mt-3 small text-secondary">
        <a href="<?= $adminBase ?>/login.php" class="text-secondary">← Zpět na přihlášení trenérů</a>
    </p>
</div>
</body>
</html>
