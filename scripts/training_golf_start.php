<?php
// training_golf_start.php – Redirect na detail golfu
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$sessionId = intParam($_GET, 'id', 0);
flash('warning', 'Golfový režim byl z aplikace odstraněn.');
if ($sessionId > 0) {
	redirect(BASE_URL . '/training_session.php?id=' . $sessionId);
}
redirect(BASE_URL . '/dashboard.php');
?>
