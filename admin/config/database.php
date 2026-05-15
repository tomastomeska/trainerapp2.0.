<?php
// ============================================================
// Konfigurace databaze
// Upravte podle vaseho nastaveni WAMP/MySQL
// ============================================================

// Bezpecnostni fallback: nacti env profil i kdyz vstupni skript nenasel config.php
$_envCandidates = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/env.php',
    __DIR__ . '/env.production.php',
];
foreach ($_envCandidates as $_envFile) {
    if (file_exists($_envFile)) {
        require_once $_envFile;
        break;
    }
}
unset($_envCandidates, $_envFile);

if (!defined('DB_HOST'))    define('DB_HOST',    'localhost');
if (!defined('DB_NAME'))    define('DB_NAME',    'marcelmiler');
if (!defined('DB_USER'))    define('DB_USER',    'root');
if (!defined('DB_PASS'))    define('DB_PASS',    '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            ensureSchemaUpgrades($pdo);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            die('<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8">
                <title>Chyba DB</title>
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
                </head><body class="bg-light"><div class="container mt-5">
                <div class="alert alert-danger">
                    <h4>Nelze se pripojit k databazi</h4>
                    <p>Zkontrolujte nastaveni v <code>config/env.php</code> dle <code>config/env.example.php</code> a ujistete se, ze databazovy server je dostupny.</p>
                </div></div></body></html>');
        }
    }
    return $pdo;
}

function ensureSchemaUpgrades(PDO $pdo): void {
    // Kompatibilita: starsi instalace mely u sportovce pouze sloupec "age".
    $stmt = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'birth_date'");
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE athletes ADD COLUMN birth_date DATE NULL AFTER last_name');
    }

    // Foto sloupec pro cviky
    $stmt2 = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'photo'");
    if (!$stmt2->fetch()) {
        $pdo->exec('ALTER TABLE exercises ADD COLUMN photo VARCHAR(255) NULL');
    }

    // Globalni cviky mohou mit coach_id = NULL
    $stmtCoachId = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'coach_id'");
    $coachIdColumn = $stmtCoachId->fetch();
    if ($coachIdColumn && strtoupper((string)($coachIdColumn['Null'] ?? 'NO')) !== 'YES') {
        $pdo->exec('ALTER TABLE exercises MODIFY COLUMN coach_id INT NULL');
    }

    // Foto sloupec pro sportovce
    $stmt3 = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'photo'");
    if (!$stmt3->fetch()) {
        $pdo->exec('ALTER TABLE athletes ADD COLUMN photo VARCHAR(255) NULL');
    }

    // Foto sloupec pro dokonceny trenink
    $stmtTsPhoto = $pdo->query("SHOW COLUMNS FROM training_sessions LIKE 'training_photo'");
    if (!$stmtTsPhoto->fetch()) {
        $pdo->exec('ALTER TABLE training_sessions ADD COLUMN training_photo VARCHAR(255) NULL AFTER notes');
    }

    // Soft-delete tréninku trenérem (pro admin obnovu)
    $stmtTsDeleted = $pdo->query("SHOW COLUMNS FROM training_sessions LIKE 'deleted_by_coach_at'");
    if (!$stmtTsDeleted->fetch()) {
        $pdo->exec('ALTER TABLE training_sessions ADD COLUMN deleted_by_coach_at DATETIME NULL AFTER completed_at');
    }

    // ID trenéra, který trénink smazal (audit)
    $stmtTsDeletedBy = $pdo->query("SHOW COLUMNS FROM training_sessions LIKE 'deleted_by_coach_id'");
    if (!$stmtTsDeletedBy->fetch()) {
        $pdo->exec('ALTER TABLE training_sessions ADD COLUMN deleted_by_coach_id INT NULL AFTER deleted_by_coach_at');
    }

    // Snapshot cviků v konkrétní session (historie nezávislá na editaci sady)
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `training_session_exercises` (
            `id`             INT AUTO_INCREMENT PRIMARY KEY,
            `session_id`     INT NOT NULL,
            `exercise_id`    INT NOT NULL,
            `exercise_order` INT NOT NULL,
            `exercise_name`  VARCHAR(200) NOT NULL,
            UNIQUE KEY `uniq_session_exercise` (`session_id`, `exercise_id`),
            KEY `idx_session_order` (`session_id`, `exercise_order`),
            CONSTRAINT `fk_tse_session`
                FOREIGN KEY (`session_id`) REFERENCES `training_sessions`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_tse_exercise`
                FOREIGN KEY (`exercise_id`) REFERENCES `exercises`(`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Tel. kontakt pro sportovce
    $stmtPhone = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'phone_contact'");
    if (!$stmtPhone->fetch()) {
        $pdo->exec('ALTER TABLE athletes ADD COLUMN phone_contact VARCHAR(20) NULL AFTER birth_date');
    }

    // Poslední přihlášení trenéra
    $stmtLogin = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'last_login'");
    if (!$stmtLogin->fetch()) {
        $pdo->exec('ALTER TABLE coaches ADD COLUMN last_login DATETIME NULL');
    }

    // Tabulka superadminu
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `superadmins` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `username`   VARCHAR(100) NOT NULL UNIQUE,
            `password`   VARCHAR(255) NOT NULL,
            `name`       VARCHAR(200),
            `email`      VARCHAR(255),
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Aktivni stav trenera
    $stmtAct = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'is_active'");
    if (!$stmtAct->fetch()) {
        $pdo->exec('ALTER TABLE coaches ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
    }

    // Globalni cviky
    $stmtGlob = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'is_global'");
    if (!$stmtGlob->fetch()) {
        $pdo->exec('ALTER TABLE exercises ADD COLUMN is_global TINYINT(1) NOT NULL DEFAULT 0');
    }

    // Hlaska po prihlaseni (admin edituje zpravy pro trenery)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `login_message` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `message`    TEXT NOT NULL,
            `version`    INT NOT NULL DEFAULT 1,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Sledovani trvaleho skryti hlasky konkretnim trenerem
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `coach_message_seen` (
            `coach_id`        INT NOT NULL,
            `message_version` INT NOT NULL,
            PRIMARY KEY (`coach_id`, `message_version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Nastaveni aplikace (klic-hodnota)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `app_settings` (
            `key`        VARCHAR(100) NOT NULL PRIMARY KEY,
            `value`      TEXT NOT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Výchozí verze z konstanty – pouze pokud záznam ještě neexistuje
    $pdo->exec("
        INSERT IGNORE INTO `app_settings` (`key`, `value`)
        VALUES ('app_version', '" . APP_VERSION . "')
    ");

    // Poslední přihlášení superadmina
    $stmtAdminLogin = $pdo->query("SHOW COLUMNS FROM superadmins LIKE 'last_login'");
    if (!$stmtAdminLogin->fetch()) {
        $pdo->exec('ALTER TABLE superadmins ADD COLUMN last_login DATETIME NULL');
    }

    // Párový trénink: tabulka skupin a cizí klíč v training_sessions
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `paired_sessions` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`   INT NOT NULL,
            `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $stmtPaired = $pdo->query("SHOW COLUMNS FROM `training_sessions` LIKE 'paired_session_id'");
    if (!$stmtPaired->fetch()) {
        $pdo->exec('ALTER TABLE `training_sessions` ADD COLUMN `paired_session_id` INT NULL DEFAULT NULL');
    }
}