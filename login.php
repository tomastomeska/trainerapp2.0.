<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

// Přesměrovat přihlášeného na dashboard
if (isLoggedIn()) {
    redirect(BASE_URL . '/dashboard.php');
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
            $stmt = $pdo->prepare('SELECT id, password, name, is_active FROM coaches WHERE username = ?');
            $stmt->execute([$username]);
            $coach = $stmt->fetch();

            if ($coach && password_verify($password, $coach['password'])) {
                if (!$coach['is_active']) {
                    $error = 'Váš účet byl zablokován. Kontaktujte správce.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['coach_id']   = $coach['id'];
                    $_SESSION['coach_name'] = $coach['name'] ?: $username;
                    // Aktualizace posledního přihlášení
                    $pdo->prepare('UPDATE coaches SET last_login = NOW() WHERE id = ?')->execute([$coach['id']]);
                    redirect(BASE_URL . '/dashboard.php');
                }
            } else {
                $error = 'Nesprávné přihlašovací údaje.';
            }
        }
    }
}

$logoFile = null;
$logoDir = __DIR__ . '/uploads/logo';
if (is_dir($logoDir)) {
    $allowedExt = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
    foreach (scandir($logoDir) ?: [] as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExt, true)) {
            $logoFile = $file;
            break;
        }
    }
}
$logoUrl = $logoFile ? (BASE_URL . '/uploads/logo/' . rawurlencode($logoFile)) : null;
$showFormOnLoad = $_SERVER['REQUEST_METHOD'] === 'POST';
?>
<!DOCTYPE html>
<html lang="cs">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Přihlášení – <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        :root {
            --brand-dark: #101f46;
            --brand-darker: #070d1e;
            --brand-gold: #f3b300;
            --panel-bg: rgba(255, 255, 255, 0.97);
            --text-main: #1b2433;
            --text-soft: #6b7485;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", "Trebuchet MS", sans-serif;
            background:
                radial-gradient(900px 600px at 12% 90%, rgba(243, 179, 0, 0.16), transparent 60%),
                radial-gradient(820px 560px at 100% 10%, rgba(30, 73, 170, 0.28), transparent 58%),
                linear-gradient(150deg, #050b18 0%, #0a1531 30%, #11285f 68%, #132d6e 100%);
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px 16px;
        }

        .login-wrap {
            width: 100%;
            max-width: 620px;
        }

        .brand {
            text-align: center;
            margin-bottom: 8px;
        }

        .brand-stage {
            display: inline-block;
            background: linear-gradient(160deg, rgba(2, 6, 16, 0.95), rgba(10, 18, 35, 0.94));
            border: 1px solid rgba(243, 179, 0, 0.3);
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 14px 28px rgba(0, 0, 0, 0.35);
            transition: padding .4s ease, transform .4s ease, box-shadow .4s ease;
        }

        .brand-logo {
            max-width: min(72vw, 540px);
            width: 100%;
            height: auto;
            display: inline-block;
            cursor: pointer;
            user-select: none;
            border-radius: 8px;
            transition: max-width .45s ease;
        }

        .brand-fallback {
            color: #ffffff;
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            margin: 0;
            cursor: pointer;
            user-select: none;
            text-shadow: 0 6px 22px rgba(0, 0, 0, 0.35);
        }

        .intro-actions {
            text-align: center;
            margin-top: 16px;
            opacity: 1;
            transform: translateY(0);
            transition: opacity .25s ease, transform .25s ease;
        }

        .intro-actions .btn-login {
            width: min(92vw, 380px);
        }

        .login-card {
            background: var(--panel-bg);
            border: 0;
            border-radius: 18px;
            box-shadow: 0 18px 50px rgba(7, 18, 44, 0.45);
            overflow: hidden;
            opacity: 0;
            transform: translateY(20px);
            max-height: 0;
            margin-top: 0;
            pointer-events: none;
            transition: opacity .35s ease, transform .35s ease, max-height .4s ease, margin-top .35s ease;
        }

        .login-card .card-body {
            padding: 26px 24px;
        }

        .login-title {
            text-align: center;
            font-size: 1.85rem;
            margin: 0 0 4px;
            font-weight: 800;
            color: #0f234f;
        }

        .login-sub {
            text-align: center;
            margin: 0 0 22px;
            color: var(--text-soft);
        }

        .form-label {
            font-weight: 700;
            color: #263552;
            margin-bottom: 7px;
        }

        .form-control {
            border-radius: 10px;
            border: 2px solid #d8dfeb;
            min-height: 44px;
            font-size: 0.98rem;
        }

        .form-control:focus {
            border-color: var(--brand-gold);
            box-shadow: 0 0 0 0.25rem rgba(243, 179, 0, 0.2);
        }

        .btn-login {
            min-height: 46px;
            border: 0;
            border-radius: 10px;
            font-weight: 800;
            font-size: 1.05rem;
            color: #10275b;
            background: linear-gradient(180deg, #ffca2f 0%, #f3b300 100%);
            box-shadow: 0 8px 18px rgba(243, 179, 0, 0.35);
        }

        .btn-login:hover {
            color: #0a1a3d;
            transform: translateY(-1px);
            background: linear-gradient(180deg, #ffd34f 0%, #f7bc1f 100%);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .footer-meta {
            text-align: center;
            margin-top: 18px;
            color: #d0d9ee;
            font-size: 0.95rem;
            opacity: 0;
            transform: translateY(8px);
            pointer-events: none;
            transition: opacity .25s ease, transform .25s ease;
        }

        .footer-meta a {
            color: #ffd55b;
            text-decoration: none;
            font-weight: 700;
        }

        .footer-meta a:hover {
            text-decoration: underline;
        }

        body.show-form .login-wrap {
            max-width: 500px;
        }

        body.show-form .brand-stage {
            padding: 10px;
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.35);
            transform: translateY(-2px);
        }

        body.show-form .brand-logo {
            max-width: 300px;
        }

        body.show-form .intro-actions {
            opacity: 0;
            transform: translateY(-8px);
            pointer-events: none;
            height: 0;
            margin: 0;
            overflow: hidden;
        }

        body.show-form .login-card {
            opacity: 1;
            transform: translateY(0);
            max-height: 700px;
            margin-top: 12px;
            pointer-events: auto;
        }

        body.show-form .footer-meta {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        @media (max-width: 575px) {
            .login-card .card-body {
                padding: 22px 18px;
            }

            .login-title {
                font-size: 1.55rem;
            }

            .brand-logo {
                max-width: min(84vw, 440px);
            }

            body.show-form .brand-logo {
                max-width: 240px;
            }
        }
    </style>
</head>

<body class="<?= $showFormOnLoad ? 'show-form' : '' ?>">
    <div class="login-wrap">
        <div class="brand">
            <?php if ($logoUrl): ?>
                <div class="brand-stage">
                    <img src="<?= h($logoUrl) ?>"
                        alt="<?= h(APP_NAME) ?>"
                        id="brandLogo"
                        class="brand-logo"
                        title="Dvojklik pro administraci">
                </div>
            <?php else: ?>
                <h1 id="brandLogo" class="brand-fallback" title="Dvojklik pro administraci"><?= h(APP_NAME) ?></h1>
            <?php endif; ?>
        </div>

        <div class="intro-actions">
            <button type="button" id="btnShowLogin" class="btn btn-login">Přihlášení</button>
        </div>

        <div class="card login-card">
            <div class="card-body">
                <h2 class="login-title">Přihlášení</h2>
                <p class="login-sub">Přihlášení pro trenéry</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger py-2 mb-3"><?= h($error) ?></div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label" for="username">Uživatelské jméno</label>
                        <input id="username" type="text" name="username" class="form-control"
                            value="<?= h($_POST['username'] ?? '') ?>"
                            autofocus autocomplete="username" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="password">Heslo</label>
                        <input id="password" type="password" name="password" class="form-control"
                            autocomplete="current-password" required>
                    </div>

                    <button type="submit" class="btn btn-login w-100">Přihlásit se</button>
                </form>
            </div>
        </div>

        <div class="footer-meta">
            <div class="mb-1">verze <?= h(getAppSetting('app_version', defined('APP_VERSION') ? APP_VERSION : '—')) ?></div>
            <div>
                Vytvořil <strong>WebNexGen</strong>
                &nbsp;·&nbsp;
                <a href="mailto:tomas.tomeska@seznam.cz">Kontaktujte nás</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const brandLogo = document.getElementById('brandLogo');
        if (brandLogo) {
            brandLogo.addEventListener('dblclick', function() {
                window.location.href = '<?= BASE_URL ?>/login_admin.php';
            });
        }

        const btnShowLogin = document.getElementById('btnShowLogin');
        if (btnShowLogin) {
            btnShowLogin.addEventListener('click', function() {
                document.body.classList.add('show-form');
                setTimeout(function() {
                    const username = document.getElementById('username');
                    if (username) username.focus();
                }, 260);
            });
        }
    </script>
</body>

</html>