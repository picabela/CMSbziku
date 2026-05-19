<?php
$adminTitle = 'Źródła';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['bulk_action'] ?? $_POST['action'] ?? '';
    $pdo = db();

    // Pojedyncze akcje (przyciski w wierszach)
    if ($action === 'toggle') {
        $id = (int)$_POST['id'];
        $pdo->prepare('UPDATE sources SET enabled = 1 - enabled WHERE id = ?')->execute([$id]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Status źródła zmieniony.'];
        header('Location: sources.php'); exit;
    }
    if ($action === 'delete_single') {
        $id = (int)$_POST['id'];
        $pdo->prepare('DELETE FROM sources WHERE id = ?')->execute([$id]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Źródło usunięte.'];
        header('Location: sources.php'); exit;
    }

    // Bulk
    $ids = bulkIds($_POST['ids'] ?? []);
    if ($ids && in_array($action, ['enable','disable','delete'], true)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        switch ($action) {
            case 'enable':
                $pdo->prepare("UPDATE sources SET enabled=1 WHERE id IN ($placeholders)")->execute($ids);
                $msg = count($ids) . ' źródeł włączonych.';
                break;
            case 'disable':
                $pdo->prepare("UPDATE sources SET enabled=0 WHERE id IN ($placeholders)")->execute($ids);
                $msg = count($ids) . ' źródeł wyłączonych.';
                break;
            case 'delete':
                $pdo->prepare("DELETE FROM sources WHERE id IN ($placeholders)")->execute($ids);
                $msg = count($ids) . ' źródeł usuniętych.';
                break;
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
        header('Location: sources.php'); exit;
    }
}

$sources = db()->query('SELECT * FROM sources ORDER BY enabled DESC, name')->fetchAll();
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Źródła RSS / HTML</h1>
        <a href="source-edit.php" class="btn btn--primary">+ Nowe źródło</a>
    </div>
    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
    <p class="hint">Źródła są odpytywane przez auto-importer. Każde może być typu RSS lub HTML listing (gdy strona nie ma feedu).</p>

    <?php if (empty($sources)): ?>
        <p class="empty">Brak źródeł. <a href="source-edit.php">Dodaj pierwsze</a>.</p>
    <?php else: ?>
        <form method="post" id="bulk-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <div class="bulk-bar">
                <label class="bulk-bar__select-all"><input type="checkbox" id="select-all"> zaznacz wszystkie</label>
                <select name="bulk_action" required>
                    <option value="">— akcja zbiorcza —</option>
                    <option value="enable">Włącz</option>
                    <option value="disable">Wyłącz</option>
                    <option value="delete">Usuń</option>
                </select>
                <button type="submit" class="btn" onclick="return confirmBulk(this.form)">Wykonaj</button>
            </div>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="admin-table__check"></th>
                        <th>Nazwa</th><th>Typ</th><th>Kategoria</th><th>Limit/run</th><th>Max wiek</th><th>Auto-publish</th><th>Status</th><th>Ostatnio</th><th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sources as $s): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?= (int)$s['id'] ?>" class="bulk-check"></td>
                            <td>
                                <a href="source-edit.php?id=<?= (int)$s['id'] ?>" class="admin-table__title"><?= e($s['name']) ?></a>
                                <div class="admin-table__slug"><?= e($s['feed_url']) ?></div>
                                <?php if ($s['last_error']): ?>
                                    <div class="admin-table__error">⚠ <?= e($s['last_error']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="pill pill--<?= ($s['source_type'] ?? 'rss') === 'html' ? 'draft' : 'published' ?>"><?= e(strtoupper($s['source_type'] ?? 'rss')) ?></span></td>
                            <td><?= e($s['category'] ?: '—') ?></td>
                            <td><?= (int)$s['max_items_per_run'] ?></td>
                            <td><?= $s['max_age_days'] !== null ? (int)$s['max_age_days'] . ' dni' : '<em>globalny</em>' ?></td>
                            <td><?= $s['auto_publish'] === null ? '<em>domyślnie</em>' : ((int)$s['auto_publish'] === 1 ? 'tak' : 'draft') ?></td>
                            <td><span class="pill pill--<?= $s['enabled'] ? 'published' : 'draft' ?>"><?= $s['enabled'] ? 'aktywne' : 'wyłączone' ?></span></td>
                            <td><?= $s['last_fetched_at'] ? e(formatDate($s['last_fetched_at'])) : '—' ?></td>
                            <td class="admin-table__actions">
                                <a href="source-edit.php?id=<?= (int)$s['id'] ?>">Edytuj</a>
                                <button type="button" class="link-btn" onclick="doInline('toggle', <?= (int)$s['id'] ?>)"><?= $s['enabled'] ? 'Wyłącz' : 'Włącz' ?></button>
                                <button type="button" class="link-btn link-btn--danger" onclick="doInline('delete_single', <?= (int)$s['id'] ?>, true)">Usuń</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
        <form id="inline-form" method="post" style="display:none">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" id="inline-action">
            <input type="hidden" name="id" id="inline-id">
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
                if (!ids) { alert('Zaznacz przynajmniej jedno źródło.'); return false; }
                if (action === 'delete') return confirm('Usunąć ' + ids + ' źródeł?');
                return true;
            };
            window.doInline = function(action, id, ask) {
                if (ask && !confirm('Na pewno?')) return;
                document.getElementById('inline-action').value = action;
                document.getElementById('inline-id').value = id;
                document.getElementById('inline-form').submit();
            };
        })();
        </script>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php';
