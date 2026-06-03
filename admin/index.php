<?php
$adminTitle = 'Wszystkie artykuły';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../includes/indexing.php';

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
            case 'index':
            case 'index_new':
                if (!indexingAnyEnabled()) {
                    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Żaden kanał indeksowania nie jest włączony. Skonfiguruj go w zakładce Indeksowanie.'];
                    header('Location: index.php'); exit;
                }
                $counts = indexingSubmissionCounts();
                $stmt = $pdo->prepare("SELECT id, slug, category, status FROM posts WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                $rows = $stmt->fetchAll();
                $submitted = 0; $skipped = 0;
                foreach ($rows as $r) {
                    if ($r['status'] !== 'published') { $skipped++; continue; }
                    $url = postUrl($r);
                    if ($action === 'index_new' && !empty($counts[$url])) { $skipped++; continue; }
                    indexingSubmitUrl($url);
                    $submitted++;
                }
                $msg = "Zgłoszono do indeksacji: {$submitted}." . ($skipped ? " Pominięto: {$skipped} (szkice lub już zgłoszone)." : '');
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
$catMap = getCategoriesForPosts(array_map(fn($p) => (int)$p['id'], $posts));
$indexCounts = indexingSubmissionCounts();
$indexingOn = indexingAnyEnabled();
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
                    <option value="index"<?= $indexingOn ? '' : ' disabled' ?>>Zgłoś do indeksacji</option>
                    <option value="index_new"<?= $indexingOn ? '' : ' disabled' ?>>Zgłoś tylko niezgłoszone</option>
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
            <?php if (!$indexingOn): ?>
                <p class="hint">Akcje indeksacji są nieaktywne — włącz kanał w zakładce <a href="indexing.php">Indeksowanie</a>.</p>
            <?php endif; ?>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="admin-table__check"></th>
                        <th>Tytuł</th>
                        <th>Kategorie</th>
                        <th>Status</th>
                        <th title="Liczba zgłoszeń do indeksacji">Indeks.</th>
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
                            <td>
                                <?php foreach (($catMap[(int)$p['id']] ?? [$p['category']]) as $ci => $cName): ?>
                                    <span class="cat-chip<?= $ci === 0 ? ' cat-chip--primary' : '' ?>"><?= e($cName) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td><span class="pill pill--<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
                            <?php $cnt = (int)($indexCounts[postUrl($p)] ?? 0); ?>
                            <td><span class="index-count<?= $cnt > 0 ? ' index-count--ok' : ' index-count--zero' ?>" title="<?= $cnt > 0 ? 'Zgłoszono ' . $cnt . ' raz(y)' : 'Nigdy nie zgłoszono' ?>"><?= $cnt ?>×</span></td>
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
                if (action === 'index') return confirm('Zgłosić ' + ids + ' artykuł(ów) do indeksacji?');
                if (action === 'index_new') return confirm('Zgłosić do indeksacji tylko niezgłoszone z ' + ids + ' zaznaczonych?');
                return true;
            };
        })();
        </script>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php';
