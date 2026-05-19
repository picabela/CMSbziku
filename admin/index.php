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
        }
        if ($msg) $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
        header('Location: index.php');
        exit;
    }
}

$posts = getAllPostsAdmin();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$cats = allCategories();
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Artykuły</h1>
        <a href="edit.php" class="btn btn--primary">+ Nowy artykuł</a>
    </div>
    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <?php if (empty($posts)): ?>
        <p class="empty">Brak artykułów. <a href="edit.php">Stwórz pierwszy</a>.</p>
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
                    <option value="delete">Usuń</option>
                </select>
                <select name="bulk_category">
                    <option value="">— wybierz kategorię —</option>
                    <?php foreach ($cats as $c): ?>
                        <option value="<?= e($c['name']) ?>"><?= e($c['name']) ?></option>
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
                        <th>Status</th>
                        <th>Data publikacji</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posts as $p): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>" class="bulk-check"></td>
                            <td>
                                <a href="edit.php?id=<?= (int)$p['id'] ?>" class="admin-table__title"><?= e($p['title']) ?></a>
                                <div class="admin-table__slug">/<?= e($p['slug']) ?></div>
                            </td>
                            <td><?= e($p['category']) ?></td>
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
                if (action === 'delete') return confirm('Usunąć ' + ids + ' artykułów?');
                return true;
            };
        })();
        </script>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php';
