<?php
// api/save_series.php – AJAX endpoint pro uložení série
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Nepřihlášen']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Neplatná metoda']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Neplatná data']);
    exit;
}

$coachId    = getCurrentCoachId();
$sessionId  = (int)($input['session_id']  ?? 0);
$exerciseId = (int)($input['exercise_id'] ?? 0);
$order      = (int)($input['series_order'] ?? 1);
$weight     = (float)($input['weight']    ?? 0);
$reps       = (int)($input['reps']        ?? 0);
$assist     = (int)($input['assistance_reps'] ?? 0);

$pdo = getDB();

// Ověření, že session patří trenérovi a je nedokončená
$stmt = $pdo->prepare(
    'SELECT ts.id FROM training_sessions ts
     JOIN athletes a ON ts.athlete_id = a.id
    WHERE ts.id = ? AND a.coach_id = ?
      AND ts.completed_at IS NULL
      AND ts.deleted_by_coach_at IS NULL'
);
$stmt->execute([$sessionId, $coachId]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Trénink nenalezen nebo již dokončen']);
    exit;
}

// Ověření cviku
$stmt2 = $pdo->prepare('SELECT id FROM exercises WHERE id = ? AND (coach_id = ? OR is_global = 1)');
$stmt2->execute([$exerciseId, $coachId]);
if (!$stmt2->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Cvik nenalezen']);
    exit;
}

$stmt3 = $pdo->prepare(
    'INSERT INTO session_series (session_id, exercise_id, series_order, weight, reps, assistance_reps)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$stmt3->execute([$sessionId, $exerciseId, $order, $weight, $reps, $assist]);
$newId = (int)$pdo->lastInsertId();

echo json_encode(['success' => true, 'id' => $newId]);
