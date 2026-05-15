<?php
// admin/exercise_export.php – export globálních cviků (CSV nebo SQL)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$format = $_GET['format'] ?? 'csv';
if (!in_array($format, ['csv', 'sql'], true)) {
    $format = 'csv';
}

$pdo = getDB();
$exercises = $pdo->query(
    'SELECT id, name, photo FROM exercises WHERE is_global = 1 ORDER BY name'
)->fetchAll(PDO::FETCH_ASSOC);

$filename = 'globalni_cviky_' . date('Y-m-d');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    // BOM pro Excel
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'name', 'photo'], ';');
    foreach ($exercises as $ex) {
        fputcsv($out, [$ex['id'], $ex['name'], $ex['photo'] ?? ''], ';');
    }
    fclose($out);
    exit;
}

// SQL export
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.sql"');

echo "-- Globální cviky TrainerApp\n";
echo "-- Exportováno: " . date('Y-m-d H:i:s') . "\n\n";
echo "-- Odstraní stávající globální cviky a nahradí je exportovanými:\n";
echo "DELETE FROM exercises WHERE is_global = 1;\n\n";

if (!empty($exercises)) {
    echo "INSERT INTO exercises (coach_id, name, photo, is_global) VALUES\n";
    $rows = [];
    foreach ($exercises as $ex) {
        $name  = str_replace("'", "''", $ex['name']);
        $photo = $ex['photo'] ? str_replace("'", "''", $ex['photo']) : 'NULL';
        $photoVal = $ex['photo'] ? "'$photo'" : 'NULL';
        $rows[] = "(NULL, '$name', $photoVal, 1)";
    }
    echo implode(",\n", $rows) . ";\n";
}
exit;
