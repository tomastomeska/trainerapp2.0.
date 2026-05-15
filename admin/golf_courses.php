<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();
$importPreview = null;

function golfNormalizeSpace(string $value): string {
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function golfDetectGender(string $value): string {
    $v = mb_strtolower($value, 'UTF-8');
    if (preg_match('/\b(z|zeny|ženy|lady|ladies|women|female)\b/u', $v)) {
        return 'women';
    }
    if (preg_match('/\b(m|muzi|muži|men|male|gentlemen)\b/u', $v)) {
        return 'men';
    }
    return 'unisex';
}

function golfExtractDecimal(string $value): ?float {
    if (preg_match('/([0-9]{2}(?:[\.,][0-9])?)/', str_replace(' ', '', $value), $m)) {
        return (float)str_replace(',', '.', $m[1]);
    }
    return null;
}

function golfExtractInt(string $value, int $min = 0, int $max = 9999): ?int {
    if (!preg_match_all('/\b([0-9]{1,5})\b/', $value, $m)) {
        return null;
    }
    foreach ($m[1] as $raw) {
        $num = (int)$raw;
        if ($num >= $min && $num <= $max) {
            return $num;
        }
    }
    return null;
}

function golfInferCourseName(string $html, ?string $sourceUrl = null): string {
    $title = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $title = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $title = golfNormalizeSpace($title);
    if ($title !== '') {
        $parts = preg_split('/\s*[\-|\|]\s*/u', $title) ?: [];
        $first = golfNormalizeSpace((string)($parts[0] ?? ''));
        if ($first !== '') {
            return mb_substr($first, 0, 255, 'UTF-8');
        }
    }

    if ($sourceUrl) {
        $host = parse_url($sourceUrl, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return mb_substr($host, 0, 255, 'UTF-8');
        }
    }

    return 'Nové hřiště';
}

function golfParseTeesFromHtmlTables(string $html): array {
    $tees = [];
    $prevUseErrors = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    if (!$loaded) {
        libxml_clear_errors();
        libxml_use_internal_errors($prevUseErrors);
        return [];
    }

    $xpath = new DOMXPath($doc);
    $tables = $xpath->query('//table');
    if (!$tables) {
        libxml_clear_errors();
        libxml_use_internal_errors($prevUseErrors);
        return [];
    }

    foreach ($tables as $table) {
        $rows = [];
        foreach ($xpath->query('.//tr', $table) as $tr) {
            $cells = [];
            foreach ($xpath->query('./th|./td', $tr) as $cell) {
                $cells[] = golfNormalizeSpace((string)$cell->textContent);
            }
            if (!empty($cells)) {
                $rows[] = $cells;
            }
        }

        if (count($rows) < 2) {
            continue;
        }

        $headers = array_map(static fn($v) => mb_strtolower($v, 'UTF-8'), $rows[0]);
        $headerJoined = implode(' ', $headers);
        if (!preg_match('/(course rating|slope|odpali|tee|par)/u', $headerJoined)) {
            continue;
        }

        $findIdx = static function (array $head, array $tokens): ?int {
            foreach ($head as $idx => $h) {
                foreach ($tokens as $token) {
                    if (mb_strpos($h, $token) !== false) {
                        return $idx;
                    }
                }
            }
            return null;
        };

        $idxTee = $findIdx($headers, ['tee', 'odpali', 'barva']);
        $idxGender = $findIdx($headers, ['pohlav', 'gender', 'muž', 'zen']);
        $idxPar = $findIdx($headers, ['par']);
        $idxCR = $findIdx($headers, ['course rating', 'rating', 'cr']);
        $idxSR = $findIdx($headers, ['slope', 'sr']);
        $idxLength = $findIdx($headers, ['délka', 'delka', 'length', 'metr']);

        if ($idxCR === null || $idxSR === null) {
            continue;
        }

        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $joined = golfNormalizeSpace(implode(' ', $row));
            if ($joined === '') {
                continue;
            }

            $crCell = (string)($row[$idxCR] ?? '');
            $srCell = (string)($row[$idxSR] ?? '');
            $courseRating = golfExtractDecimal($crCell);
            $slopeRating = golfExtractInt($srCell, 50, 200);
            if ($courseRating === null || $slopeRating === null) {
                $courseRating = golfExtractDecimal($joined);
                $slopeRating = golfExtractInt($joined, 50, 200);
            }
            if ($courseRating === null || $slopeRating === null) {
                continue;
            }

            $teeName = golfNormalizeSpace((string)($idxTee !== null ? ($row[$idxTee] ?? '') : ''));
            if ($teeName === '') {
                $teeName = preg_replace('/\s*[0-9]{2}[\.,][0-9].*$/', '', $joined) ?? '';
                $teeName = golfNormalizeSpace($teeName);
            }
            if ($teeName === '') {
                $teeName = 'Tee ' . ($r + 1);
            }

            $genderSource = (string)($idxGender !== null ? ($row[$idxGender] ?? '') : $teeName);
            $gender = golfDetectGender($genderSource);

            $par = golfExtractInt((string)($idxPar !== null ? ($row[$idxPar] ?? '') : $joined), 50, 90);
            if ($par === null) {
                $par = 72;
            }

            $lengthM = null;
            if ($idxLength !== null) {
                $lengthM = golfExtractInt((string)($row[$idxLength] ?? ''), 2000, 8000);
            }
            if ($lengthM === null) {
                $lengthM = golfExtractInt($joined, 2000, 8000);
            }

            $tees[] = [
                'tee_name' => mb_substr($teeName, 0, 80, 'UTF-8'),
                'gender' => $gender,
                'par' => (int)$par,
                'course_rating' => round((float)$courseRating, 1),
                'slope_rating' => (int)$slopeRating,
                'length_m' => $lengthM,
            ];
        }
    }

    libxml_clear_errors();
    libxml_use_internal_errors($prevUseErrors);
    return $tees;
}

function golfParseTeesFromPlainText(string $text): array {
    $tees = [];
    $lines = preg_split('/\R/u', $text) ?: [];
    foreach ($lines as $line) {
        $line = golfNormalizeSpace((string)$line);
        if ($line === '') {
            continue;
        }

        if (!preg_match('/([0-9]{2}[\.,][0-9]).*?\b([0-9]{2,3})\b/u', $line, $m)) {
            continue;
        }

        $courseRating = (float)str_replace(',', '.', $m[1]);
        $slopeRating = (int)$m[2];
        if ($slopeRating < 50 || $slopeRating > 200) {
            continue;
        }

        $teeName = preg_replace('/\s*[0-9]{2}[\.,][0-9].*$/u', '', $line) ?? '';
        $teeName = golfNormalizeSpace($teeName);
        if ($teeName === '') {
            $teeName = 'Tee ' . (count($tees) + 1);
        }

        $gender = golfDetectGender($line);
        $par = golfExtractInt($line, 50, 90) ?? 72;
        $lengthM = golfExtractInt($line, 2000, 8000);

        $tees[] = [
            'tee_name' => mb_substr($teeName, 0, 80, 'UTF-8'),
            'gender' => $gender,
            'par' => (int)$par,
            'course_rating' => round($courseRating, 1),
            'slope_rating' => (int)$slopeRating,
            'length_m' => $lengthM,
        ];
    }

    return $tees;
}

function golfDeduplicateTees(array $tees): array {
    $result = [];
    foreach ($tees as $tee) {
        $teeName = golfNormalizeSpace((string)($tee['tee_name'] ?? ''));
        if ($teeName === '') {
            continue;
        }
        $gender = (string)($tee['gender'] ?? 'unisex');
        if (!in_array($gender, ['men', 'women', 'unisex'], true)) {
            $gender = 'unisex';
        }
        $cr = (float)($tee['course_rating'] ?? 0);
        $sr = (int)($tee['slope_rating'] ?? 0);
        if ($cr <= 0 || $sr <= 0) {
            continue;
        }
        $par = (int)($tee['par'] ?? 72);
        $length = $tee['length_m'] !== null ? (int)$tee['length_m'] : null;

        $key = mb_strtolower($teeName, 'UTF-8') . '|' . $gender;
        $result[$key] = [
            'tee_name' => mb_substr($teeName, 0, 80, 'UTF-8'),
            'gender' => $gender,
            'par' => max(1, min(100, $par)),
            'course_rating' => round($cr, 1),
            'slope_rating' => max(1, min(500, $sr)),
            'length_m' => $length,
        ];
    }

    return array_values($result);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/golf_courses.php');
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add_course') {
        $name = trim((string)($_POST['name'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));

        if ($name === '') {
            flash('danger', 'Název hřiště je povinný.');
            redirect(BASE_URL . '/admin/golf_courses.php');
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO golf_courses (name, location, is_active)
                 VALUES (?, ?, 1)'
            );
            $stmt->execute([$name, $location !== '' ? $location : null]);
            flash('success', 'Hřiště bylo přidáno.');
        } catch (Throwable $e) {
            flash('danger', 'Hřiště se nepodařilo přidat. Název už pravděpodobně existuje.');
        }

        redirect(BASE_URL . '/admin/golf_courses.php');
    }

    if ($action === 'toggle_course') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        if ($courseId <= 0) {
            flash('danger', 'Neplatné hřiště.');
            redirect(BASE_URL . '/admin/golf_courses.php');
        }

        $stmt = $pdo->prepare(
            'UPDATE golf_courses
             SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
             WHERE id = ?'
        );
        $stmt->execute([$courseId]);

        flash('success', 'Stav hřiště byl změněn.');
        redirect(BASE_URL . '/admin/golf_courses.php');
    }

    if ($action === 'add_tee') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        $teeName = trim((string)($_POST['tee_name'] ?? ''));
        $gender = trim((string)($_POST['gender'] ?? 'unisex'));
        $par = (int)($_POST['par'] ?? 72);
        $courseRating = $_POST['course_rating'] ?? '';
        $slopeRating = $_POST['slope_rating'] ?? '';
        $lengthM = $_POST['length_m'] ?? '';

        if (!in_array($gender, ['men', 'women', 'unisex'], true)) {
            $gender = 'unisex';
        }

        if ($courseId <= 0 || $teeName === '' || $courseRating === '' || $slopeRating === '') {
            flash('danger', 'Vyplňte povinná pole odpaliště (hřiště, název, course rating, slope rating).');
            redirect(BASE_URL . '/admin/golf_courses.php');
        }

        $stmtCourse = $pdo->prepare('SELECT id FROM golf_courses WHERE id = ?');
        $stmtCourse->execute([$courseId]);
        if (!$stmtCourse->fetch(PDO::FETCH_ASSOC)) {
            flash('danger', 'Vybrané hřiště neexistuje.');
            redirect(BASE_URL . '/admin/golf_courses.php');
        }

        $par = max(1, min(100, $par));
        $courseRatingFloat = (float)str_replace(',', '.', (string)$courseRating);
        $slopeRatingInt = (int)$slopeRating;
        $lengthMInt = trim((string)$lengthM) === '' ? null : (int)$lengthM;

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO golf_course_tees
                    (course_id, tee_name, gender, par, course_rating, slope_rating, length_m, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([
                $courseId,
                $teeName,
                $gender,
                $par,
                $courseRatingFloat,
                $slopeRatingInt,
                $lengthMInt,
            ]);
            flash('success', 'Odpaliště bylo přidáno.');
        } catch (Throwable $e) {
            flash('danger', 'Odpaliště se nepodařilo přidat. Kombinace hřiště + odpaliště + pohlaví už pravděpodobně existuje.');
        }

        redirect(BASE_URL . '/admin/golf_courses.php');
    }

    if ($action === 'toggle_tee') {
        $teeId = (int)($_POST['tee_id'] ?? 0);
        if ($teeId <= 0) {
            flash('danger', 'Neplatné odpaliště.');
            redirect(BASE_URL . '/admin/golf_courses.php');
        }

        $stmt = $pdo->prepare(
            'UPDATE golf_course_tees
             SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
             WHERE id = ?'
        );
        $stmt->execute([$teeId]);

        flash('success', 'Stav odpaliště byl změněn.');
        redirect(BASE_URL . '/admin/golf_courses.php');
    }

    if ($action === 'import_preview_url') {
        $sourceUrl = trim((string)($_POST['source_url'] ?? ''));
        if ($sourceUrl === '') {
            flash('danger', 'Zadejte URL adresu stránky s daty hřiště.');
            redirect(BASE_URL . '/admin/golf_courses.php');
        }

        if (!filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
            flash('danger', 'URL adresa není platná.');
            redirect(BASE_URL . '/admin/golf_courses.php');
        }

        $scheme = strtolower((string)(parse_url($sourceUrl, PHP_URL_SCHEME) ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            flash('danger', 'Podporujeme pouze HTTP/HTTPS odkazy.');
            redirect(BASE_URL . '/admin/golf_courses.php');
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 12,
                'user_agent' => 'TrainerApp/1.0 (golf-import)',
                'ignore_errors' => true,
            ],
            'https' => [
                'method' => 'GET',
                'timeout' => 12,
                'user_agent' => 'TrainerApp/1.0 (golf-import)',
                'ignore_errors' => true,
            ],
        ]);

        $html = @file_get_contents($sourceUrl, false, $ctx);
        if ($html === false || trim($html) === '') {
            flash('danger', 'Stránku se nepodařilo načíst. Zkuste vložit text ručně.');
            redirect(BASE_URL . '/admin/golf_courses.php');
        }

        $tees = golfDeduplicateTees(golfParseTeesFromHtmlTables($html));
        if (empty($tees)) {
            $plainText = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $tees = golfDeduplicateTees(golfParseTeesFromPlainText($plainText));
        }

        if (empty($tees)) {
            flash('danger', 'Na stránce se nepodařilo najít odpaliště. Zkuste variantu "Vložit text".');
            redirect(BASE_URL . '/admin/golf_courses.php');
        }

        $importPreview = [
            'source_type' => 'url',
            'source_url' => $sourceUrl,
            'course_name' => golfInferCourseName($html, $sourceUrl),
            'location' => '',
            'tees' => $tees,
        ];
    }

    if ($action === 'import_preview_text') {
        $rawText = trim((string)($_POST['raw_text'] ?? ''));
        $courseName = golfNormalizeSpace((string)($_POST['course_name_hint'] ?? ''));
        $location = golfNormalizeSpace((string)($_POST['location_hint'] ?? ''));

        if ($rawText === '') {
            flash('danger', 'Vložte text s daty odpališť.');
            redirect(BASE_URL . '/admin/golf_courses.php');
        }

        $tees = golfDeduplicateTees(golfParseTeesFromPlainText($rawText));
        if (empty($tees)) {
            flash('danger', 'Z vloženého textu se nepodařilo rozpoznat žádná odpaliště.');
            redirect(BASE_URL . '/admin/golf_courses.php');
        }

        if ($courseName === '') {
            $firstLine = trim((string)(preg_split('/\R/u', $rawText)[0] ?? ''));
            $courseName = $firstLine !== '' ? mb_substr($firstLine, 0, 255, 'UTF-8') : 'Nové hřiště';
        }

        $importPreview = [
            'source_type' => 'text',
            'source_url' => '',
            'course_name' => $courseName,
            'location' => $location,
            'tees' => $tees,
        ];
    }

    if ($action === 'import_save_preview') {
        $courseName = golfNormalizeSpace((string)($_POST['course_name'] ?? ''));
        $location = golfNormalizeSpace((string)($_POST['location'] ?? ''));
        $sourceUrl = trim((string)($_POST['source_url'] ?? ''));
        $teesPayload = trim((string)($_POST['tees_payload'] ?? ''));

        if ($courseName === '' || $teesPayload === '') {
            flash('danger', 'Chybí náhledová data importu.');
            redirect(BASE_URL . '/admin/golf_courses.php');
        }

        $decoded = json_decode(base64_decode($teesPayload, true) ?: '', true);
        if (!is_array($decoded)) {
            flash('danger', 'Importní data jsou poškozená. Zkuste náhled vytvořit znovu.');
            redirect(BASE_URL . '/admin/golf_courses.php');
        }

        $tees = golfDeduplicateTees($decoded);
        if (empty($tees)) {
            flash('danger', 'V importních datech nejsou žádná platná odpaliště.');
            redirect(BASE_URL . '/admin/golf_courses.php');
        }

        try {
            $pdo->beginTransaction();

            $stmtExisting = $pdo->prepare('SELECT id, location FROM golf_courses WHERE name = ? LIMIT 1');
            $stmtExisting->execute([$courseName]);
            $existingCourse = $stmtExisting->fetch(PDO::FETCH_ASSOC);

            if ($existingCourse) {
                $courseId = (int)$existingCourse['id'];
                $stmtUpdateCourse = $pdo->prepare(
                    'UPDATE golf_courses
                     SET location = ?,
                         is_active = 1,
                         updated_at = NOW()
                     WHERE id = ?'
                );
                $newLocation = $location !== '' ? $location : ($existingCourse['location'] ?: null);
                $stmtUpdateCourse->execute([$newLocation, $courseId]);
            } else {
                $stmtInsertCourse = $pdo->prepare(
                    'INSERT INTO golf_courses (name, location, is_active)
                     VALUES (?, ?, 1)'
                );
                $stmtInsertCourse->execute([$courseName, $location !== '' ? $location : null]);
                $courseId = (int)$pdo->lastInsertId();
            }

            $stmtUpsertTee = $pdo->prepare(
                'INSERT INTO golf_course_tees
                    (course_id, tee_name, gender, par, course_rating, slope_rating, length_m, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    par = VALUES(par),
                    course_rating = VALUES(course_rating),
                    slope_rating = VALUES(slope_rating),
                    length_m = VALUES(length_m),
                    is_active = 1,
                    updated_at = NOW()'
            );

            $affected = 0;
            foreach ($tees as $tee) {
                $stmtUpsertTee->execute([
                    $courseId,
                    $tee['tee_name'],
                    $tee['gender'],
                    $tee['par'],
                    $tee['course_rating'],
                    $tee['slope_rating'],
                    $tee['length_m'],
                ]);
                $affected++;
            }

            $pdo->commit();
            $msg = 'Import dokončen: ' . $affected . ' odpališť u hřiště "' . $courseName . '".';
            if ($sourceUrl !== '') {
                $msg .= ' Zdroj: ' . $sourceUrl;
            }
            flash('success', $msg);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', 'Import se nepodařil uložit: ' . $e->getMessage());
        }

        redirect(BASE_URL . '/admin/golf_courses.php');
    }
}

$stmtCourses = $pdo->query(
    'SELECT gc.*,
            (SELECT COUNT(*) FROM golf_course_tees gct WHERE gct.course_id = gc.id) AS tee_count,
            (SELECT COUNT(*) FROM golf_course_tees gct WHERE gct.course_id = gc.id AND gct.is_active = 1) AS active_tee_count
     FROM golf_courses gc
     ORDER BY gc.is_active DESC, gc.name ASC'
);
$courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);

$teesByCourse = [];
if (!empty($courses)) {
    $courseIds = array_map(static fn($c) => (int)$c['id'], $courses);
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    $stmtTees = $pdo->prepare(
        "SELECT *
         FROM golf_course_tees
         WHERE course_id IN ($placeholders)
         ORDER BY is_active DESC, tee_name ASC, gender ASC"
    );
    $stmtTees->execute($courseIds);

    foreach ($stmtTees->fetchAll(PDO::FETCH_ASSOC) as $tee) {
        $cid = (int)$tee['course_id'];
        if (!isset($teesByCourse[$cid])) {
            $teesByCourse[$cid] = [];
        }
        $teesByCourse[$cid][] = $tee;
    }
}

renderAdminHeader('Golfová hřiště a odpaliště');
?>

<div class="d-flex align-items-center justify-content-between mb-4 gap-3 flex-wrap">
    <div>
        <h2 class="mb-0 fw-bold">
            <i class="fas fa-golf-ball-tee me-2" style="color:#a78bfa"></i>Golfová hřiště a odpaliště
        </h2>
        <div class="text-muted small">Správa databáze hřišť pro přesný výpočet HCP (course rating / slope rating).</div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-semibold">
        <i class="fas fa-cloud-arrow-down me-2" style="color:#a78bfa"></i>Rychlý import dat hřiště (CGF odkaz / vložený text)
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-lg-6">
                <h6 class="fw-bold mb-2">Varianta A: URL stránky</h6>
                <form method="post" class="row g-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="import_preview_url">
                    <div class="col-12">
                        <input type="url" class="form-control" name="source_url"
                               placeholder="https://.../hriste/..." required>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-magnifying-glass me-1"></i>Načíst a připravit náhled
                        </button>
                    </div>
                </form>
            </div>
            <div class="col-lg-6">
                <h6 class="fw-bold mb-2">Varianta B: Vložit text ze stránky</h6>
                <form method="post" class="row g-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="import_preview_text">
                    <div class="col-md-7">
                        <input type="text" class="form-control" name="course_name_hint" maxlength="255" placeholder="Název hřiště (volitelné)">
                    </div>
                    <div class="col-md-5">
                        <input type="text" class="form-control" name="location_hint" maxlength="255" placeholder="Lokalita (volitelné)">
                    </div>
                    <div class="col-12">
                        <textarea class="form-control" name="raw_text" rows="4" placeholder="Sem vlož text z CGF stránky (odpaliště, PAR, CR, SR...)" required></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-magnifying-glass me-1"></i>Připravit náhled z textu
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if (is_array($importPreview) && !empty($importPreview['tees'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-success text-white fw-semibold">
        <i class="fas fa-check me-2"></i>Náhled importu - potvrď uložení
    </div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="import_save_preview">
            <input type="hidden" name="source_url" value="<?= h((string)$importPreview['source_url']) ?>">
            <input type="hidden" name="tees_payload" value="<?= h(base64_encode(json_encode($importPreview['tees'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) ?>">

            <div class="col-md-8">
                <label class="form-label fw-semibold">Název hřiště</label>
                <input type="text" class="form-control" name="course_name" maxlength="255" required
                       value="<?= h((string)$importPreview['course_name']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Lokalita</label>
                <input type="text" class="form-control" name="location" maxlength="255"
                       value="<?= h((string)$importPreview['location']) ?>">
            </div>
            <?php if (!empty($importPreview['source_url'])): ?>
            <div class="col-12">
                <div class="small text-muted">Zdroj: <?= h((string)$importPreview['source_url']) ?></div>
            </div>
            <?php endif; ?>

            <div class="col-12">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Odpaliště</th>
                                <th>Pohlaví</th>
                                <th>PAR</th>
                                <th>CR</th>
                                <th>SR</th>
                                <th>Délka (m)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($importPreview['tees'] as $tee): ?>
                            <tr>
                                <td><?= h((string)$tee['tee_name']) ?></td>
                                <td><?= h((string)$tee['gender']) ?></td>
                                <td><?= (int)$tee['par'] ?></td>
                                <td><?= number_format((float)$tee['course_rating'], 1, ',', ' ') ?></td>
                                <td><?= (int)$tee['slope_rating'] ?></td>
                                <td><?= $tee['length_m'] !== null ? (int)$tee['length_m'] : '–' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-floppy-disk me-1"></i>Uložit import do databáze
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-dark text-white fw-semibold">
                <i class="fas fa-plus me-2" style="color:#a78bfa"></i>Přidat nové hřiště
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_course">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Název hřiště</label>
                        <input type="text" class="form-control" name="name" maxlength="255" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Lokalita <span class="text-muted fw-normal">(volitelné)</span></label>
                        <input type="text" class="form-control" name="location" maxlength="255" placeholder="např. Vysoký Újezd">
                    </div>

                    <button type="submit" class="btn btn-outline-success">
                        <i class="fas fa-floppy-disk me-1"></i>Uložit hřiště
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-dark text-white fw-semibold">
                <i class="fas fa-plus-circle me-2" style="color:#a78bfa"></i>Přidat odpaliště (tee)
            </div>
            <div class="card-body">
                <form method="post" class="row g-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_tee">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Hřiště</label>
                        <select class="form-select" name="course_id" required>
                            <option value="">— Vyberte hřiště —</option>
                            <?php foreach ($courses as $course): ?>
                            <option value="<?= (int)$course['id'] ?>">
                                <?= h((string)$course['name']) ?><?= !empty($course['location']) ? ' - ' . h((string)$course['location']) : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Název odpaliště</label>
                        <input type="text" class="form-control" name="tee_name" maxlength="80" placeholder="např. Bílé" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Pohlaví</label>
                        <select class="form-select" name="gender">
                            <option value="men">Muži</option>
                            <option value="women">Ženy</option>
                            <option value="unisex" selected>Unisex</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">PAR</label>
                        <input type="number" class="form-control" name="par" min="1" max="100" value="72" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Course rating</label>
                        <input type="number" class="form-control" name="course_rating" step="0.1" min="0" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Slope rating</label>
                        <input type="number" class="form-control" name="slope_rating" min="1" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Délka (m) <span class="text-muted fw-normal">vol.</span></label>
                        <input type="number" class="form-control" name="length_m" min="0">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-outline-success">
                            <i class="fas fa-floppy-disk me-1"></i>Uložit odpaliště
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white fw-semibold">
        <i class="fas fa-list me-2" style="color:#a78bfa"></i>Přehled hřišť
    </div>
    <div class="card-body">
        <?php if (empty($courses)): ?>
        <div class="text-muted">Zatím není žádné hřiště.</div>
        <?php else: ?>
        <div class="accordion" id="coursesAccordion">
            <?php foreach ($courses as $index => $course): ?>
            <?php
            $courseId = (int)$course['id'];
            $collapseId = 'course-' . $courseId;
            $isActive = (int)$course['is_active'] === 1;
            $courseTees = $teesByCourse[$courseId] ?? [];
            ?>
            <div class="accordion-item mb-2 border rounded">
                <h2 class="accordion-header" id="heading-<?= $collapseId ?>">
                    <button class="accordion-button <?= $index === 0 ? '' : 'collapsed' ?>" type="button"
                            data-bs-toggle="collapse" data-bs-target="#collapse-<?= $collapseId ?>"
                            aria-expanded="<?= $index === 0 ? 'true' : 'false' ?>" aria-controls="collapse-<?= $collapseId ?>">
                        <span class="fw-semibold me-2"><?= h((string)$course['name']) ?></span>
                        <?php if (!empty($course['location'])): ?>
                        <span class="text-muted small me-2">(<?= h((string)$course['location']) ?>)</span>
                        <?php endif; ?>
                        <span class="badge <?= $isActive ? 'bg-success' : 'bg-secondary' ?> me-2"><?= $isActive ? 'Aktivní' : 'Neaktivní' ?></span>
                        <span class="badge bg-dark"><?= (int)$course['active_tee_count'] ?>/<?= (int)$course['tee_count'] ?> odpališť aktivních</span>
                    </button>
                </h2>
                <div id="collapse-<?= $collapseId ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>"
                     aria-labelledby="heading-<?= $collapseId ?>" data-bs-parent="#coursesAccordion">
                    <div class="accordion-body">
                        <div class="d-flex justify-content-end mb-3">
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_course">
                                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                                    <i class="fas <?= $isActive ? 'fa-eye-slash' : 'fa-eye' ?> me-1"></i>
                                    <?= $isActive ? 'Deaktivovat hřiště' : 'Aktivovat hřiště' ?>
                                </button>
                            </form>
                        </div>

                        <?php if (empty($courseTees)): ?>
                        <div class="text-muted">U tohoto hřiště zatím nejsou žádná odpaliště.</div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Odpaliště</th>
                                        <th>Pohlaví</th>
                                        <th>PAR</th>
                                        <th>CR</th>
                                        <th>SR</th>
                                        <th>Délka (m)</th>
                                        <th>Stav</th>
                                        <th class="text-end">Akce</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($courseTees as $tee): ?>
                                    <?php $teeActive = (int)$tee['is_active'] === 1; ?>
                                    <tr>
                                        <td class="fw-semibold"><?= h((string)$tee['tee_name']) ?></td>
                                        <td><?= h((string)$tee['gender']) ?></td>
                                        <td><?= (int)$tee['par'] ?></td>
                                        <td><?= number_format((float)$tee['course_rating'], 1, ',', ' ') ?></td>
                                        <td><?= (int)$tee['slope_rating'] ?></td>
                                        <td><?= $tee['length_m'] !== null ? (int)$tee['length_m'] : '–' ?></td>
                                        <td><span class="badge <?= $teeActive ? 'bg-success' : 'bg-secondary' ?>"><?= $teeActive ? 'Aktivní' : 'Neaktivní' ?></span></td>
                                        <td class="text-end">
                                            <form method="post" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="toggle_tee">
                                                <input type="hidden" name="tee_id" value="<?= (int)$tee['id'] ?>">
                                                <button type="submit" class="btn btn-sm <?= $teeActive ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                                                    <i class="fas <?= $teeActive ? 'fa-eye-slash' : 'fa-eye' ?> me-1"></i>
                                                    <?= $teeActive ? 'Deaktivovat' : 'Aktivovat' ?>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php renderAdminFooter(); ?>
