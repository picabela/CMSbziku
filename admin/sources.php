<?php
$adminTitle = 'Źródła RSS';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle') {
        $id = (int)$_POST['id'];
        db()->prepare('UPDATE sources SET enabled = 1 - enabled WHERE id = ?')->execute([$id]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Status źródła zmieniony.'];
        header('Location: sources.php'); exit;
    }
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        db()->prepare('DELETE FROM sources WHERE id = ?')->execute([$id]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Źródło usunięte.'];
        header('Location: sources.php'); exit;
    }
}

$sources = db()->query('SELECT * FROM sources ORDER BY enabled DESC, name')->fetchAll();
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Źródła RSS / Atom</h1>
        <a href="source-edit.php" class="btn btn--primary">+ Nowe źródło</a>
    </div>
    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
    <p class="hint">Definiowane tu strony są regularnie odpytywane przez auto-importer. Z każdego źródła pobierane są najnowsze artykuły, streszczane przez AI i publikowane.</p>

    <?php if (empty($sources)): ?>
        <p class="empty">Brak źródeł. <a href="source-edit.php">Dodaj pierwsze</a>.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr><th>Nazwa</th><th>Typ</th><th>Kategoria</th><th>Limit/run</th><th>Max wiek</th><th>Auto-publish</th><th>Status</th><th>Ostatnio</th><th>Akcje</th></tr>
            </thead>
            <tbody>
                <?php foreach ($sources as $s): ?>
                    <tr>
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
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="link-btn" type="submit"><?= $s['enabled'] ? 'Wyłącz' : 'Włącz' ?></button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('Usunąć źródło?');">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="link-btn link-btn--danger" type="submit">Usuń</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php';
