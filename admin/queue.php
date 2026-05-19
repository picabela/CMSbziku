<?php
$adminTitle = 'Kolejka auto-importu';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['bulk_action'] ?? '';
    $ids = bulkIds($_POST['ids'] ?? []);
    $pdo = db();
    if ($ids && $action) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        switch ($action) {
            case 'retry':
                $pdo->prepare("UPDATE auto_queue SET status='pending', attempts=0, next_attempt_at=CURRENT_TIMESTAMP, error=NULL WHERE id IN ($placeholders)")->execute($ids);
                $msg = count($ids) . ' wznowionych — zostaną przetworzone w następnym ticku.';
                break;
            case 'skip':
                $pdo->prepare("UPDATE auto_queue SET status='skipped', error='Ręcznie pominięte' WHERE id IN ($placeholders)")->execute($ids);
                $msg = count($ids) . ' pominiętych.';
                break;
            case 'delete':
                $pdo->prepare("DELETE FROM auto_queue WHERE id IN ($placeholders)")->execute($ids);
                $msg = count($ids) . ' usuniętych z kolejki.';
                break;
        }
        if (!empty($msg)) $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
        header('Location: queue.php' . ($_GET['status'] ?? '' ? '?status=' . urlencode($_GET['status']) : ''));
        exit;
    }
}

$filter = $_GET['status'] ?? 'pending';
$allowed = ['pending','processing','done','failed','skipped','all'];
if (!in_array($filter, $allowed, true)) $filter = 'pending';

$sql = 'SELECT q.*, s.name AS source_name FROM auto_queue q LEFT JOIN sources s ON s.id = q.source_id';
$params = [];
if ($filter !== 'all') { $sql .= ' WHERE q.status = ?'; $params[] = $filter; }
$sql .= ' ORDER BY q.id DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts = [];
foreach (db()->query('SELECT status, COUNT(*) AS c FROM auto_queue GROUP BY status') as $r) {
    $counts[$r['status']] = (int)$r['c'];
}
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Kolejka auto-importu</h1>
        <div>
            <a href="auto.php" class="btn">← Auto-import</a>
            <a href="runs.php" class="btn">Log uruchomień</a>
        </div>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <nav class="filter-tabs">
        <?php foreach (['pending'=>'Oczekujące','processing'=>'W trakcie','done'=>'Zrobione','failed'=>'Błędy','skipped'=>'Pominięte','all'=>'Wszystkie'] as $key => $label):
            $count = $key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0); ?>
            <a href="?status=<?= e($key) ?>" class="<?= $filter === $key ? 'is-active' : '' ?>"><?= e($label) ?> <span class="filter-tabs__count"><?= $count ?></span></a>
        <?php endforeach; ?>
    </nav>

    <?php if (empty($rows)): ?>
        <p class="empty">Brak elementów w tym statusie.</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <div class="bulk-bar">
                <label class="bulk-bar__select-all"><input type="checkbox" id="select-all"> zaznacz wszystkie</label>
                <select name="bulk_action" required>
                    <option value="">— akcja zbiorcza —</option>
                    <option value="retry">Wznów (retry teraz)</option>
                    <option value="skip">Oznacz jako pominięte</option>
                    <option value="delete">Usuń z kolejki</option>
                </select>
                <button type="submit" class="btn" onclick="return confirmBulk(this.form)">Wykonaj</button>
            </div>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="admin-table__check"></th>
                        <th>ID</th><th>Tytuł</th><th>Źródło</th><th>Status</th><th>Próby</th><th>Następna próba</th><th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" class="bulk-check"></td>
                            <td>#<?= (int)$r['id'] ?></td>
                            <td>
                                <a href="<?= e($r['external_url']) ?>" target="_blank" rel="noopener nofollow" class="admin-table__title"><?= e(mb_substr($r['title'] ?: $r['external_url'], 0, 90)) ?></a>
                                <?php if ($r['error']): ?><div class="admin-table__error">⚠ <?= e($r['error']) ?></div><?php endif; ?>
                                <?php if ($r['post_id']): ?><div class="admin-table__slug">→ post #<?= (int)$r['post_id'] ?></div><?php endif; ?>
                            </td>
                            <td><?= e($r['source_name'] ?: '—') ?></td>
                            <td><span class="pill pill--<?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
                            <td><?= (int)$r['attempts'] ?>/<?= (int)$r['max_attempts'] ?></td>
                            <td><?= $r['next_attempt_at'] ? e($r['next_attempt_at']) : '—' ?></td>
                            <td class="admin-table__actions">
                                <?php if ($r['post_id']): ?>
                                    <a href="edit.php?id=<?= (int)$r['post_id'] ?>">Edytuj post</a>
                                <?php endif; ?>
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
                if (!ids) { alert('Zaznacz przynajmniej jeden.'); return false; }
                if (action === 'delete') return confirm('Usunąć ' + ids + ' z kolejki?');
                return true;
            };
        })();
        </script>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php';
