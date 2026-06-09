<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();
$validAudience = ['all', 'coach', 'athlete'];

function infoAudienceLabel(string $value): string {
    return match ($value) {
        'coach' => 'Pouze trenéři',
        'athlete' => 'Pouze sportovci',
        default => 'Všichni',
    };
}

function infoNormalizeDateTime(?string $value): ?string {
    $v = trim((string)$value);
    if ($v === '') {
        return null;
    }

    $ts = strtotime($v);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/infokanal.php');
    }

    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'add_category') {
            $name = trim((string)($_POST['name'] ?? ''));
            $audience = trim((string)($_POST['audience'] ?? 'all'));
            $sortOrder = (int)($_POST['sort_order'] ?? 100);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($name === '') {
                flash('danger', 'Název kategorie je povinný.');
            } elseif (!in_array($audience, $validAudience, true)) {
                flash('danger', 'Neplatná cílová skupina kategorie.');
            } else {
                $pdo->prepare(
                    'INSERT INTO info_categories (name, audience, sort_order, is_active) VALUES (?, ?, ?, ?)'
                )->execute([$name, $audience, $sortOrder, $isActive]);
                flash('success', 'Kategorie byla vytvořena.');
            }
        }

        if ($action === 'update_category') {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $audience = trim((string)($_POST['audience'] ?? 'all'));
            $sortOrder = (int)($_POST['sort_order'] ?? 100);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($categoryId <= 0 || $name === '') {
                flash('danger', 'Kategorie se nepodařilo uložit.');
            } elseif (!in_array($audience, $validAudience, true)) {
                flash('danger', 'Neplatná cílová skupina kategorie.');
            } else {
                $pdo->prepare(
                    'UPDATE info_categories SET name = ?, audience = ?, sort_order = ?, is_active = ? WHERE id = ?'
                )->execute([$name, $audience, $sortOrder, $isActive, $categoryId]);
                flash('success', 'Kategorie byla upravena.');
            }
        }

        if ($action === 'delete_category') {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            if ($categoryId > 0) {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM info_articles WHERE category_id = ?');
                $countStmt->execute([$categoryId]);
                $articleCount = (int)$countStmt->fetchColumn();

                if ($articleCount > 0) {
                    flash('danger', 'Kategorie obsahuje články. Nejdříve je smažte nebo přesuňte.');
                } else {
                    $pdo->prepare('DELETE FROM info_categories WHERE id = ?')->execute([$categoryId]);
                    flash('success', 'Kategorie byla smazána.');
                }
            }
        }

        if ($action === 'add_article') {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $targetAudience = trim((string)($_POST['target_audience'] ?? 'all'));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $publishedAt = infoNormalizeDateTime($_POST['published_at'] ?? null) ?? date('Y-m-d H:i:s');
            $createdBy = (int)($_SESSION['superadmin_id'] ?? 0);

            if ($categoryId <= 0 || $title === '' || $body === '') {
                flash('danger', 'Vyplňte kategorii, název a text článku.');
            } elseif (!in_array($targetAudience, $validAudience, true)) {
                flash('danger', 'Neplatné cílení článku.');
            } else {
                $pdo->prepare(
                    'INSERT INTO info_articles (category_id, title, body, target_audience, is_active, published_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$categoryId, $title, $body, $targetAudience, $isActive, $publishedAt, $createdBy > 0 ? $createdBy : null]);
                flash('success', 'Článek byl vytvořen.');
            }
        }

        if ($action === 'update_article') {
            $articleId = (int)($_POST['article_id'] ?? 0);
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $targetAudience = trim((string)($_POST['target_audience'] ?? 'all'));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $publishedAt = infoNormalizeDateTime($_POST['published_at'] ?? null) ?? date('Y-m-d H:i:s');

            if ($articleId <= 0 || $categoryId <= 0 || $title === '' || $body === '') {
                flash('danger', 'Článek se nepodařilo uložit.');
            } elseif (!in_array($targetAudience, $validAudience, true)) {
                flash('danger', 'Neplatné cílení článku.');
            } else {
                $pdo->prepare(
                    'UPDATE info_articles
                     SET category_id = ?, title = ?, body = ?, target_audience = ?, is_active = ?, published_at = ?
                     WHERE id = ?'
                )->execute([$categoryId, $title, $body, $targetAudience, $isActive, $publishedAt, $articleId]);
                flash('success', 'Článek byl upraven.');
            }
        }

        if ($action === 'delete_article') {
            $articleId = (int)($_POST['article_id'] ?? 0);
            if ($articleId > 0) {
                $pdo->prepare('DELETE FROM info_articles WHERE id = ?')->execute([$articleId]);
                flash('success', 'Článek byl smazán.');
            }
        }
    } catch (Throwable $e) {
        flash('danger', 'Operaci se nepodařilo dokončit.');
    }

    redirect(BASE_URL . '/admin/infokanal.php');
}

$categories = $pdo->query(
    "SELECT ic.*, COUNT(ia.id) AS article_count
     FROM info_categories ic
     LEFT JOIN info_articles ia ON ia.category_id = ic.id
     GROUP BY ic.id
     ORDER BY ic.sort_order ASC, ic.name ASC"
)->fetchAll();

$articles = $pdo->query(
    "SELECT ia.*, ic.name AS category_name
     FROM info_articles ia
     JOIN info_categories ic ON ic.id = ia.category_id
     ORDER BY ia.published_at DESC, ia.id DESC"
)->fetchAll();

renderAdminHeader('Infokanal');
?>

<style>
.info-admin-compact .card-header { padding: .55rem .85rem; }
.info-admin-compact .card-body { padding: .8rem; }
.info-admin-compact .list-group-item { padding: .65rem .8rem; }
.info-admin-compact .form-label { margin-bottom: .22rem; font-size: .86rem; }
.info-admin-compact .form-control,
.info-admin-compact .form-select { padding-top: .33rem; padding-bottom: .33rem; font-size: .9rem; }
.info-admin-compact .btn { padding-top: .3rem; padding-bottom: .3rem; }
.info-admin-compact .small { font-size: .8rem !important; }
</style>

<div class="info-admin-compact">
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h4 class="fw-bold mb-0">
        <i class="fas fa-lightbulb me-2" style="color:#a78bfa"></i>Infokanál
    </h4>
    <span class="text-muted small">Správa kategorií a článků pro trenéry/sportovce</span>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
                <i class="fas fa-folder-plus me-2"></i>Nová kategorie
            </div>
            <div class="card-body py-2">
                <form method="post" class="row g-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_category">
                    <div class="col-12">
                        <label class="form-label">Název</label>
                        <input type="text" class="form-control" name="name" maxlength="180" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Viditelnost kategorie</label>
                        <select name="audience" class="form-select">
                            <option value="all">Všichni</option>
                            <option value="coach">Trenéři</option>
                            <option value="athlete">Sportovci</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Pořadí</label>
                        <input type="number" class="form-control" name="sort_order" value="100">
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="newCatActive" checked>
                            <label class="form-check-label" for="newCatActive">Aktivní</label>
                        </div>
                    </div>
                    <div class="col-12 mt-1">
                        <button class="btn fw-bold" style="background:#7c3aed;color:#fff;border:none">
                            <i class="fas fa-plus me-1"></i>Vytvořit kategorii
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
                <i class="fas fa-folder-open me-2"></i>Kategorie
            </div>
            <div class="card-body p-0">
                <?php if (empty($categories)): ?>
                <div class="p-3 text-muted">Zatím nejsou vytvořené žádné kategorie.</div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($categories as $cat): ?>
                    <div class="list-group-item">
                        <form method="post" class="row g-1 align-items-end">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update_category">
                            <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                            <div class="col-12">
                                <label class="form-label small mb-1">Název</label>
                                <input type="text" class="form-control form-control-sm" name="name" value="<?= h((string)$cat['name']) ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1">Skupina</label>
                                <select name="audience" class="form-select form-select-sm">
                                    <option value="all" <?= (string)$cat['audience'] === 'all' ? 'selected' : '' ?>>Všichni</option>
                                    <option value="coach" <?= (string)$cat['audience'] === 'coach' ? 'selected' : '' ?>>Trenéři</option>
                                    <option value="athlete" <?= (string)$cat['audience'] === 'athlete' ? 'selected' : '' ?>>Sportovci</option>
                                </select>
                            </div>
                            <div class="col-3">
                                <label class="form-label small mb-1">Pořadí</label>
                                <input type="number" class="form-control form-control-sm" name="sort_order" value="<?= (int)$cat['sort_order'] ?>">
                            </div>
                            <div class="col-3">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="catActive<?= (int)$cat['id'] ?>" <?= (int)$cat['is_active'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="catActive<?= (int)$cat['id'] ?>">Aktivní</label>
                                </div>
                            </div>
                            <div class="col-12 d-flex gap-2 mt-1">
                                <button class="btn btn-sm btn-outline-primary" type="submit">
                                    <i class="fas fa-save me-1"></i>Uložit
                                </button>
                            </div>
                        </form>
                        <form method="post" class="mt-1" onsubmit="return confirm('Opravdu smazat kategorii?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" <?= (int)$cat['article_count'] > 0 ? 'disabled' : '' ?>>
                                <i class="fas fa-trash me-1"></i>Smazat
                            </button>
                            <span class="small text-muted ms-2">Článků: <?= (int)$cat['article_count'] ?></span>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
                <i class="fas fa-file-circle-plus me-2"></i>Nový článek
            </div>
            <div class="card-body py-2">
                <form method="post" class="row g-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_article">
                    <div class="col-md-6">
                        <label class="form-label">Kategorie</label>
                        <select name="category_id" class="form-select" required>
                            <option value="">Vyberte kategorii</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>"><?= h((string)$cat['name']) ?> (<?= h(infoAudienceLabel((string)$cat['audience'])) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Cílení článku</label>
                        <select name="target_audience" class="form-select">
                            <option value="all">Všichni</option>
                            <option value="coach">Pouze trenéři</option>
                            <option value="athlete">Pouze sportovci</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Název</label>
                        <input type="text" class="form-control" name="title" maxlength="220" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Krátký text</label>
                        <textarea class="form-control" name="body" rows="4" maxlength="5000" required></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Publikovat od</label>
                        <input type="datetime-local" class="form-control" name="published_at">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="is_active" id="newArticleActive" checked>
                            <label class="form-check-label" for="newArticleActive">Aktivní</label>
                        </div>
                    </div>
                    <div class="col-12 mt-1">
                        <button class="btn fw-bold" style="background:#7c3aed;color:#fff;border:none">
                            <i class="fas fa-plus me-1"></i>Publikovat článek
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
                <i class="fas fa-newspaper me-2"></i>Články
            </div>
            <div class="card-body p-0">
                <?php if (empty($articles)): ?>
                <div class="p-3 text-muted">Zatím není vytvořený žádný článek.</div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($articles as $article): ?>
                    <div class="list-group-item">
                        <form method="post" class="row g-1">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update_article">
                            <input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>">
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Kategorie</label>
                                <select name="category_id" class="form-select form-select-sm" required>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?= (int)$cat['id'] ?>" <?= (int)$cat['id'] === (int)$article['category_id'] ? 'selected' : '' ?>>
                                        <?= h((string)$cat['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Cílení</label>
                                <select name="target_audience" class="form-select form-select-sm">
                                    <option value="all" <?= (string)$article['target_audience'] === 'all' ? 'selected' : '' ?>>Všichni</option>
                                    <option value="coach" <?= (string)$article['target_audience'] === 'coach' ? 'selected' : '' ?>>Pouze trenéři</option>
                                    <option value="athlete" <?= (string)$article['target_audience'] === 'athlete' ? 'selected' : '' ?>>Pouze sportovci</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-1">Název</label>
                                <input type="text" class="form-control form-control-sm" name="title" value="<?= h((string)$article['title']) ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-1">Text</label>
                                <textarea class="form-control form-control-sm" name="body" rows="3" required><?= h((string)$article['body']) ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Publikováno od</label>
                                <input type="datetime-local" class="form-control form-control-sm" name="published_at" value="<?= h(date('Y-m-d\TH:i', strtotime((string)$article['published_at']))) ?>">
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="articleActive<?= (int)$article['id'] ?>" <?= (int)$article['is_active'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="articleActive<?= (int)$article['id'] ?>">Aktivní</label>
                                </div>
                            </div>
                            <div class="col-12 d-flex gap-2 mt-1">
                                <button class="btn btn-sm btn-outline-primary" type="submit">
                                    <i class="fas fa-save me-1"></i>Uložit
                                </button>
                            </div>
                        </form>
                        <form method="post" class="mt-1" onsubmit="return confirm('Opravdu smazat článek?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_article">
                            <input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash me-1"></i>Smazat
                            </button>
                            <span class="small text-muted ms-2">
                                <?= h((string)$article['category_name']) ?> | <?= h(infoAudienceLabel((string)$article['target_audience'])) ?>
                            </span>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<?php renderAdminFooter(); ?>
