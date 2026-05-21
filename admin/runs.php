<?php
$adminTitle = 'Log uruchomień';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Usuwanie logów
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';
    $statuses = $_POST['statuses'] ?? [];
    if (!is_array($statuses)) $statuses = [$statuses];
    $statuses = array_values(array_filter($statuses, fn($s) => in_array($s, ['success','disabled','error','running','all'], true)));

    $where = '1=1';
    $params = [];

    if (!in_array('all', $statuses, true) && $statuses) {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $where .= " AND status IN ($placeholders)";
        foreach ($statuses as $s) $params[] = $s;
    } elseif (!$statuses) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Wybierz co najmniej jeden status.'];
        header('Location: runs.php'); exit;
    }

    if ($action === 'delete_older_than') {
        $days = max(1, (int)($_POST['older_than_days'] ?? 30));
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $where .= ' AND started_at < ?';
        $params[] = $cutoff;
        $msg = "starsze niż {$days} dni";
    } elseif ($action === 'delete_range') {
        $from = trim($_POST['date_from'] ?? '');
        $to   = trim($_POST['date_to'] ?? '');
        if ($from !== '') { $where .= ' AND started_at >= ?'; $params[] = $from . ' 00:00:00'; }
        if ($to   !== '') { $where .= ' AND started_at <= ?'; $params[] = $to   . ' 23:59:59'; }
        if ($from === '' && $to === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Podaj przynajmniej jedną datę przedziału.'];
            header('Location: runs.php'); exit;
        }
        $msg = "z przedziału " . ($from ?: 'początek') . ' → ' . ($to ?: 'dziś');
    } else {
        header('Location: runs.php'); exit;
    }

    $countStmt = db()->prepare("SELECT COUNT(*) FROM auto_runs WHERE $where");
    $countStmt->execute($params);
    $count = (int)$countStmt->fetchColumn();

    $del = db()->prepare("DELETE FROM auto_runs WHERE $where");
    $del->execute($params);

    $statusLabel = in_array('all', $statuses, true) ? 'wszystkie' : implode(', ', $statuses);
    $_SESSION['flash'] = ['type' => 'success', 'msg' => "Usunięto {$count} log(ów) ({$msg}, statusy: {$statusLabel})."];
    header('Location: runs.php'); exit;
}

$selectedId = (int)($_GET['id'] ?? 0);
$selected = null;
if ($selectedId) {
    $stmt = db()->prepare('SELECT * FROM auto_runs WHERE id = ?');
    $stmt->execute([$selectedId]);
    $selected = $stmt->fetch();
}
$runs = db()->query('SELECT * FROM auto_runs ORDER BY started_at DESC LIMIT 200')->fetchAll();
$totalRuns = (int)db()->query('SELECT COUNT(*) FROM auto_runs')->fetchColumn();

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
    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
    <?php if ($hasRunning): ?>
        <div class="flash flash--success">⏳ Run jest aktualnie aktywny — strona odświeży się automatycznie co 3 s.</div>
    <?php endif; ?>

    <details class="settings-card runs-cleanup">
        <summary><strong>🧹 Wyczyść logi</strong> <span class="muted">(w bazie: <?= $totalRuns ?>)</span></summary>
        <div class="runs-cleanup__body">
            <fieldset class="radio-group">
                <legend>Statusy do usunięcia</legend>
                <label class="checkbox"><input type="checkbox" form="cleanup-older" name="statuses[]" value="success"> success</label>
                <label class="checkbox"><input type="checkbox" form="cleanup-older" name="statuses[]" value="disabled" checked> disabled</label>
                <label class="checkbox"><input type="checkbox" form="cleanup-older" name="statuses[]" value="error"> error</label>
                <label class="checkbox"><input type="checkbox" form="cleanup-older" name="statuses[]" value="running"> running (porzucone)</label>
                <label class="checkbox"><input type="checkbox" form="cleanup-older" name="statuses[]" value="all"> <strong>wszystkie</strong> (ignoruje powyższe)</label>
                <p class="hint">Te same checkboxy obowiązują dla obu trybów poniżej.</p>
            </fieldset>

            <div class="form-row form-row--2">
                <form method="post" id="cleanup-older" class="settings-form">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_older_than">
                    <h3 class="runs-cleanup__h3">Tryb A — Usuń starsze niż</h3>
                    <label>Liczba dni
                        <input type="number" name="older_than_days" min="1" max="3650" value="30">
                    </label>
                    <button type="submit" class="btn" onclick="return confirm('Usunąć logi starsze niż wskazana liczba dni i o wybranych statusach?')">Usuń starsze</button>
                </form>

                <form method="post" class="settings-form">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_range">
                    <h3 class="runs-cleanup__h3">Tryb B — Usuń z przedziału dat</h3>
                    <!-- powiel statusy do tego formularza przez JS -->
                    <div id="cleanup-range-statuses"></div>
                    <div class="form-row form-row--2">
                        <label>Od<input type="date" name="date_from"></label>
                        <label>Do<input type="date" name="date_to"></label>
                    </div>
                    <button type="submit" class="btn" onclick="return confirm('Usunąć logi z przedziału dat i o wybranych statusach?')">Usuń z przedziału</button>
                </form>
            </div>
        </div>
        <script>
        (function(){
            // synchronizujemy checkboxy między formularzami przed submitem
            document.querySelectorAll('form[action], form').forEach(f => {
                f.addEventListener('submit', function(){
                    const action = f.querySelector('input[name="action"]')?.value;
                    if (action !== 'delete_range') return;
                    // skopiuj statusy
                    document.querySelectorAll('input[name="statuses[]"]:checked').forEach(cb => {
                        const clone = document.createElement('input');
                        clone.type = 'hidden';
                        clone.name = 'statuses[]';
                        clone.value = cb.value;
                        f.appendChild(clone);
                    });
                });
            });
        })();
        </script>
    </details>

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
