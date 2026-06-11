<?php
$adminTitle = 'Wszystkie artykuły';
require __DIR__ . '/_layout.php';

// Bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $ids = bulkIds($_POST['ids'] ?? []);
    $action = $_POST['bulk_action'] ?? '';
    if ($ids && $action) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo = db();
        $msg = '';
        switch ($action) {
            case 'delete':
                $stmt = $pdo->prepare("SELECT featured_image FROM posts WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $img) {
                    if ($img) @unlink(UPLOAD_DIR . '/' . $img);
                }
                $pdo->prepare("DELETE FROM posts WHERE id IN ($placeholders)")->execute($ids);
                $msg = count($ids) . ' artykuł(ów) usuniętych.';
                break;
            case 'publish':
                $pdo->prepare("UPDATE posts SET status='published' WHERE id IN ($placeholders)")->execute($ids);
                $msg = count($ids) . ' opublikowanych.';
                break;
            case 'unpublish':
                $pdo->prepare("UPDATE posts SET status='draft' WHERE id IN ($placeholders)")->execute($ids);
                $msg = count($ids) . ' przeniesionych do szkiców.';
                break;
            case 'set_category':
                $cat = trim($_POST['bulk_category'] ?? '');
                if ($cat !== '') {
                    $params = array_merge([$cat], $ids);
                    $pdo->prepare("UPDATE posts SET category=? WHERE id IN ($placeholders)")->execute($params);
                    $msg = count($ids) . ' przypisanych do kategorii „' . $cat . '".';
                }
                break;
            case 'set_author':
                $authorIdRaw = trim($_POST['bulk_author_id'] ?? '');
                if ($authorIdRaw !== '') {
                    $authorIdVal = ctype_digit($authorIdRaw) ? (int)$authorIdRaw : null;
                    $params = array_merge([$authorIdVal], $ids);
                    $pdo->prepare("UPDATE posts SET author_id=? WHERE id IN ($placeholders)")->execute($params);
                    if ($authorIdVal) {
                        $au = getAuthorById($authorIdVal);
                        $msg = count($ids) . ' przypisanych do autora „' . ($au['name'] ?? '?') . '".';
                    } else {
                        $msg = count($ids) . ' artykułom usunięto przypisanie autora.';
                    }
                }
                break;
        }
        if ($msg) $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
        header('Location: index.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }
}

// Filtry: od / do / ostatnie X dni
$filterFrom = trim($_GET['from'] ?? '');
$filterTo = trim($_GET['to'] ?? '');
$filterDays = isset($_GET['days']) ? trim((string)$_GET['days']) : '3';
$applyDays = isset($_GET['apply_days']);

$where = [];
$params = [];
if ($applyDays && ctype_digit($filterDays) && (int)$filterDays > 0) {
    $where[] = "published_at >= datetime('now', '-' || ? || ' days')";
    $params[] = (int)$filterDays;
} else {
    if ($filterFrom !== '') {
        $where[] = 'date(published_at) >= ?';
        $params[] = $filterFrom;
    }
    if ($filterTo !== '') {
        $where[] = 'date(published_at) <= ?';
        $params[] = $filterTo;
    }
}

$sql = 'SELECT * FROM posts';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY published_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

// Statystyki
$totalPublished = (int)db()->query("SELECT COUNT(*) FROM posts WHERE status='published'")->fetchColumn();
$totalDrafts = (int)db()->query("SELECT COUNT(*) FROM posts WHERE status='draft'")->fetchColumn();
$totalAll = $totalPublished + $totalDrafts;
$filteredCount = count($posts);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$cats = allCategories();
$authors = allAuthors();
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Artykuły</h1>
        <a href="edit.php" class="btn btn--primary">+ Nowy artykuł</a>
    </div>
    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <div class="admin-stats">
        <span class="admin-stats__item"><strong><?= $totalPublished ?></strong> opublikowanych</span>
        <span class="admin-stats__item"><strong><?= $totalDrafts ?></strong> szkiców</span>
        <span class="admin-stats__item"><strong><?= $totalAll ?></strong> łącznie</span>
        <?php if ($where): ?>
            <span class="admin-stats__item admin-stats__item--accent">Filtr: <strong><?= $filteredCount ?></strong> wyników</span>
        <?php endif; ?>
    </div>

    <form method="get" class="admin-filters">
        <label class="admin-filters__field">
            <span>Od</span>
            <input type="date" name="from" value="<?= e($filterFrom) ?>">
        </label>
        <label class="admin-filters__field">
            <span>Do</span>
            <input type="date" name="to" value="<?= e($filterTo) ?>">
        </label>
        <button type="submit" class="btn">Filtruj zakresem</button>

        <span class="admin-filters__sep">lub</span>

        <label class="admin-filters__field">
            <span>Ostatnie</span>
            <input type="number" name="days" value="<?= e($filterDays) ?>" min="1" max="3650" style="width:80px">
            <span>dni</span>
        </label>
        <button type="submit" name="apply_days" value="1" class="btn">Pokaż</button>

        <?php if ($where): ?>
            <a href="index.php" class="btn btn--ghost">Wyczyść</a>
        <?php endif; ?>
    </form>

    <?php if (empty($posts)): ?>
        <p class="empty"><?= $where ? 'Brak artykułów w wybranym zakresie.' : 'Brak artykułów.' ?> <a href="edit.php">Stwórz nowy</a>.</p>
    <?php else: ?>
        <form method="post" id="bulk-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

            <div class="bulk-bar">
                <label class="bulk-bar__select-all"><input type="checkbox" id="select-all"> zaznacz wszystkie</label>
                <select name="bulk_action" required>
                    <option value="">— akcja zbiorcza —</option>
                    <option value="publish">Opublikuj</option>
                    <option value="unpublish">Przenieś do szkiców</option>
                    <option value="set_category">Zmień kategorię na…</option>
                    <option value="set_author">Przypisz autora…</option>
                    <option value="delete">Usuń</option>
                </select>
                <select name="bulk_category">
                    <option value="">— wybierz kategorię —</option>
                    <?php foreach ($cats as $c): ?>
                        <option value="<?= e($c['name']) ?>"><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="bulk_author_id">
                    <option value="">— wybierz autora —</option>
                    <option value="0">(brak / wyczyść)</option>
                    <?php foreach ($authors as $a): ?>
                        <option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn" onclick="return confirmBulk(this.form)">Wykonaj</button>
            </div>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="admin-table__check"></th>
                        <th>Tytuł</th>
                        <th>Kategoria</th>
                        <th>Autor</th>
                        <th>Status</th>
                        <th>Data publikacji</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posts as $p): ?>
                        <?php $assignedAuthor = !empty($p['author_id']) ? getAuthorById((int)$p['author_id']) : null; ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>" class="bulk-check"></td>
                            <td>
                                <a href="edit.php?id=<?= (int)$p['id'] ?>" class="admin-table__title"><?= e($p['title']) ?></a>
                                <div class="admin-table__slug">/<?= e($p['slug']) ?></div>
                            </td>
                            <td><?= e($p['category']) ?></td>
                            <td><?= $assignedAuthor ? e($assignedAuthor['name']) : '<span class="muted">' . e($p['author'] ?: '—') . '</span>' ?></td>
                            <td><span class="pill pill--<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
                            <td><?= e(formatDate($p['published_at'])) ?></td>
                            <td class="admin-table__actions">
                                <a href="<?= e(postUrl($p)) ?>" target="_blank" rel="noopener">Zobacz</a>
                                <a href="edit.php?id=<?= (int)$p['id'] ?>">Edytuj</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
        <script>
        (function(){
            const all = document.getElementById('select-all');
            const checks = document.querySelectorAll('.bulk-check');
            all.addEventListener('change', () => { checks.forEach(c => c.checked = all.checked); });
            window.confirmBulk = function(form) {
                const action = form.bulk_action.value;
                const ids = form.querySelectorAll('.bulk-check:checked').length;
                if (!action) { alert('Wybierz akcję.'); return false; }
                if (!ids) { alert('Zaznacz przynajmniej jeden wiersz.'); return false; }
                if (action === 'set_category' && !form.bulk_category.value) { alert('Wybierz kategorię.'); return false; }
                if (action === 'set_author' && form.bulk_author_id.value === '') { alert('Wybierz autora.'); return false; }
                if (action === 'delete') return confirm('Usunąć ' + ids + ' artykułów?');
                return true;
            };
        })();
        </script>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php';
