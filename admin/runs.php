<?php
$adminTitle = 'Log uruchomień';
require __DIR__ . '/_layout.php';

$selectedId = (int)($_GET['id'] ?? 0);
$selected = null;
if ($selectedId) {
    $stmt = db()->prepare('SELECT * FROM auto_runs WHERE id = ?');
    $stmt->execute([$selectedId]);
    $selected = $stmt->fetch();
}
$runs = db()->query('SELECT * FROM auto_runs ORDER BY started_at DESC LIMIT 50')->fetchAll();
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Log uruchomień auto-importu</h1>
        <a href="auto.php" class="btn">← Auto-import</a>
    </div>

    <?php if (empty($runs)): ?>
        <p class="empty">Brak uruchomień. Idź do <a href="auto.php">Auto-import</a> i kliknij „Uruchom teraz”.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr><th>ID</th><th>Start</th><th>Status</th><th>Znalezione</th><th>Import.</th><th>Pominięte</th><th>Błędy</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($runs as $r): ?>
                    <tr>
                        <td>#<?= (int)$r['id'] ?></td>
                        <td><?= e($r['started_at']) ?></td>
                        <td><span class="pill pill--<?= $r['status'] === 'success' ? 'published' : 'draft' ?>"><?= e($r['status']) ?></span></td>
                        <td><?= (int)$r['items_found'] ?></td>
                        <td><strong><?= (int)$r['items_imported'] ?></strong></td>
                        <td><?= (int)$r['items_skipped'] ?></td>
                        <td><?= (int)$r['items_failed'] ?></td>
                        <td><a href="?id=<?= (int)$r['id'] ?>">Log</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($selected): ?>
            <section class="settings-card" style="margin-top:2rem">
                <h2>Run #<?= (int)$selected['id'] ?> — <?= e($selected['status']) ?></h2>
                <?php if ($selected['error']): ?>
                    <div class="flash flash--error"><?= e($selected['error']) ?></div>
                <?php endif; ?>
                <pre class="log-box"><?= e($selected['log'] ?: '(pusty log)') ?></pre>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php';
