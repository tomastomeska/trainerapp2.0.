<?php
// training_run_outdoor_start.php – Redirect na detail běhu venku
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

redirect(BASE_URL . '/training_run_outdoor_detail.php?id=' . intParam($_GET, 'id', 0));
?>
