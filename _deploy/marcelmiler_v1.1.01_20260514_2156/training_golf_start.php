<?php
// training_golf_start.php – Redirect na detail golfu
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

redirect(BASE_URL . '/training_golf_detail.php?id=' . intParam($_GET, 'id', 0));
?>
