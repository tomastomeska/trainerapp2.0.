<?php
// ============================================================
// config/env.local.php - PRODUKCNI override pro jistotu load order
// ============================================================

// Databaze
if (!defined('DB_HOST')) define('DB_HOST', 'localhost,md433.wedos.net');
if (!defined('DB_NAME')) define('DB_NAME', 'd391857_tplan');
if (!defined('DB_USER')) define('DB_USER', 'a391857_tplan');
if (!defined('DB_PASS')) define('DB_PASS', 'rfea4txM');

// Nasazeni produkce v rootu domeny (bez podslozky).
if (!defined('BASE_URL')) define('BASE_URL', '');

// Nazev session cookie aplikace.
if (!defined('SESSION_NAME')) define('SESSION_NAME', 'trainerapp_v2_sess');
if (!defined('SESSION_SECURE')) define('SESSION_SECURE', true);

// Bezpecnostni pojistka setup_admin.php
if (!defined('ENABLE_SETUP_ADMIN')) define('ENABLE_SETUP_ADMIN', false);

// SMTP nastaveni pro odesilani e-mailu (PHPMailer)
if (!defined('SMTP_HOST'))      define('SMTP_HOST',      'smtp.wedos.com');
if (!defined('SMTP_PORT'))      define('SMTP_PORT',      587);
if (!defined('SMTP_USER'))      define('SMTP_USER',      'no_reply@reservio.online');
if (!defined('SMTP_PASS'))      define('SMTP_PASS',      '20Tomeska@17');
if (!defined('SMTP_FROM'))      define('SMTP_FROM',      'no_reply@reservio.online');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', 'TrainerApp');