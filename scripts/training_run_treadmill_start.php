<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$sessionId = intParam($_GET, 'id', 0);
if ($sessionId <= 0) {
    flash('danger', 'Session nenalezena.');
    redirect(BASE_URL . '/dashboard.php');
}

flash('info', 'Běh na páse se zadává až po doběhu. Vyplňte metriky v detailu.');
redirect(BASE_URL . '/training_run_treadmill_detail.php?id=' . $sessionId);
