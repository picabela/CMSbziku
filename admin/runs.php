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
$runs = db()->query('SELECT * FROM auto_runs ORDER BY started_at DESC LIMIT 200')->fetchAll();

// Grupuj consecutive 'disabled' do zwijanej zakładki
$groups = [];
$disabledBuf = [];
foreach ($runs as $r) {
    if ($r['status'] === 'disabled') {
        $disabledBuf[] = $r;
    } else {
        if ($disabledBuf) { $groups[] = ['type' => 'disabled', 'rows' => $disabledBuf]; $disabledBuf = []; }
        $groups[] = ['type' => 'single', 'row' => $r];
    }
}
if ($disabledBuf) $groups[] = ['type' => 'disabled', 'rows' => $disabledBuf];

$hasRunning = false;
foreach ($runs as $r) if ($r['status'] === 'running') { $hasRunning = true; break; }
?>
<?php if ($hasRunning): ?>
    <meta http-equiv="refresh" content="3">
<?php endif; ?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Log uruchomień auto-importu</h1>
        <a href="auto.php" class="btn">← Auto-import</a>
    </div>
    <?php if ($hasRunning): ?>
        <div class="flash flash--success">⏳ Run jest aktualnie aktywny — strona odświeży się automatycznie co 3 s.</div>
    <?php endif; ?>

    <?php if (empty($runs)): ?>
        <p class="empty">Brak uruchomień. Idź do <a href="auto.php">Auto-import</a> i kliknij „Uruchom teraz".</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr><th>ID</th><th>Start</th><th>Status</th><th>Znalezione</th><th>Kolejka</th><th>Import.</th><th>Pominięte</th><th>Błędy</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $g): ?>
                    <?php if ($g['type'] === 'disabled' && count($g['rows']) > 1): ?>
                        <tr class="runs-disabled-group">
                            <td colspan="9">
                                <details>
                                    <summary>
                                        <span class="pill pill--draft">disabled</span>
                                        <strong><?= count($g['rows']) ?>×</strong> ticki gdy auto-import był wyłączony
                                        <span class="muted">(od <?= e($g['rows'][count($g['rows'])-1]['started_at']) ?> do <?= e($g['rows'][0]['started_at']) ?>)</span>
                                    </summary>
                                    <table class="admin-table" style="margin-top:0.75rem">
                                        <?php foreach ($g['rows'] as $r): ?>
                                            <tr>
                                                <td>#<?= (int)$r['id'] ?></td>
                                                <td><?= e($r['started_at']) ?></td>
                                                <td><a href="?id=<?= (int)$r['id'] ?>">Log</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </details>
                            </td>
                        </tr>
                    <?php else:
                        $rows = $g['type'] === 'single' ? [$g['row']] : $g['rows'];
                        foreach ($rows as $r): ?>
                            <tr>
                                <td>#<?= (int)$r['id'] ?></td>
                                <td><?= e($r['started_at']) ?></td>
                                <td><span class="pill pill--<?= e($r['status'] === 'success' ? 'published' : ($r['status'] === 'disabled' ? 'draft' : $r['status'])) ?>"><?= e($r['status']) ?></span></td>
                                <td><?= (int)$r['items_found'] ?></td>
                                <td><?= (int)$r['items_enqueued'] ?></td>
                                <td><strong><?= (int)$r['items_imported'] ?></strong></td>
                                <td><?= (int)$r['items_skipped'] ?></td>
                                <td><?= (int)$r['items_failed'] ?></td>
                                <td><a href="?id=<?= (int)$r['id'] ?>">Log</a></td>
                            </tr>
                        <?php endforeach;
                    endif; ?>
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
