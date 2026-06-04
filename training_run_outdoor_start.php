<?php
// training_run_outdoor_start.php – Redirect na detail běhu venku
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$sessionId = intParam($_GET, 'id', 0);
flash('warning', 'Režim běhu venku byl z aplikace odstraněn.');
if ($sessionId > 0) {
	redirect(BASE_URL . '/training_session.php?id=' . $sessionId);
}
redirect(BASE_URL . '/dashboard.php');
?>
