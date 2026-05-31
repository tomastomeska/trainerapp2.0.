<?php
// Local development profile
if (!defined('DB_HOST')) define('DB_HOST', 'localhost:3307');
if (!defined('DB_NAME')) define('DB_NAME', 'trainerapp_v2_dev');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

if (!defined('BASE_URL')) define('BASE_URL', '/TrainerApp_v.2.0.0/admin');
if (!defined('SESSION_SECURE')) define('SESSION_SECURE', false);
if (!defined('ENABLE_SETUP_ADMIN')) define('ENABLE_SETUP_ADMIN', false);

if (!defined('SMTP_HOST'))      define('SMTP_HOST',      '');
if (!defined('SMTP_PORT'))      define('SMTP_PORT',      587);
if (!defined('SMTP_USER'))      define('SMTP_USER',      '');
if (!defined('SMTP_PASS'))      define('SMTP_PASS',      '');
if (!defined('SMTP_FROM'))      define('SMTP_FROM',      'noreply@example.com');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', 'TrainerApp');
