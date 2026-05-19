<?php
$adminTitle = 'Auto-import AI';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$lastResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $keys = ['auto_enabled','auto_interval_minutes','auto_max_posts_per_run','auto_publish',
                 'openai_api_key','openai_model','openai_temperature',
                 'auto_target_language','auto_default_category','auto_default_author','auto_prompt'];
        foreach ($keys as $k) {
            $v = $_POST[$k] ?? '';
            if (in_array($k, ['auto_enabled','auto_publish'], true)) $v = isset($_POST[$k]) ? '1' : '0';
            setSetting($k, (string)$v);
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Ustawienia zapisane.'];
        header('Location: auto.php'); exit;
    }

    if ($action === 'rotate_token') {
        setSetting('auto_token', bin2hex(random_bytes(16)));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Wygenerowano nowy token.'];
        header('Location: auto.php'); exit;
    }

    if ($action === 'run_now') {
        require_once __DIR__ . '/../includes/autoimport.php';
        $lastResult = runAutoImport((int)($_POST['max'] ?? 1));
    }
}

$cronUrl = BASE_URL . '/cron/run.php?token=' . urlencode(setting('auto_token'));
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Auto-import AI</h1>
        <div>
            <a href="sources.php" class="btn">Zarządzaj źródłami</a>
            <a href="runs.php" class="btn">Log uruchomień</a>
        </div>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <?php if ($lastResult): ?>
        <div class="flash flash--<?= !empty($lastResult['error']) ? 'error' : 'success' ?>">
            Run zakończony. Znalezione: <?= (int)($lastResult['found'] ?? 0) ?>,
            zaimportowane: <?= (int)($lastResult['imported'] ?? 0) ?>,
            pominięte: <?= (int)($lastResult['skipped'] ?? 0) ?>,
            błędy: <?= (int)($lastResult['failed'] ?? 0) ?>.
            <?php if (!empty($lastResult['error'])): ?> <strong><?= e($lastResult['error']) ?></strong><?php endif; ?>
        </div>
        <?php if (!empty($lastResult['log'])): ?>
            <pre class="log-box"><?= e(implode("\n", $lastResult['log'])) ?></pre>
        <?php endif; ?>
    <?php endif; ?>

    <div class="settings-card">
        <h2>Uruchom teraz (test)</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="run_now">
            <label>Ile artykułów wygenerować w tym teście?
                <input type="number" name="max" min="1" max="10" value="1">
            </label>
            <button class="btn btn--primary" type="submit">Uruchom teraz</button>
        </form>
        <p class="hint">Wymaga klucza OpenAI poniżej. Jeden run może zająć 30-90 sekund.</p>
    </div>

    <div class="settings-card">
        <h2>Cron — token i URL</h2>
        <p>Ustaw cron na swoim hostingu, by ten URL był wywoływany cyklicznie:</p>
        <code class="code-block"><?= e($cronUrl) ?></code>
        <p class="hint">Przykładowa linia w crontab (co godzinę):<br>
            <code>0 * * * * curl -s "<?= e($cronUrl) ?>" &gt; /dev/null</code></p>
        <form method="post" style="margin-top:1rem">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="rotate_token">
            <button class="btn" type="submit" onclick="return confirm('Wygenerować nowy token? Stary przestanie działać.');">Rotuj token</button>
        </form>
    </div>

    <form method="post" class="settings-card">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">

        <h2>Status</h2>
        <label class="checkbox"><input type="checkbox" name="auto_enabled" value="1" <?= setting('auto_enabled') === '1' ? 'checked' : '' ?>> Auto-import włączony</label>
        <label class="checkbox"><input type="checkbox" name="auto_publish" value="1" <?= setting('auto_publish', '1') === '1' ? 'checked' : '' ?>> Publikuj wygenerowane artykuły od razu (inaczej draft)</label>

        <h2 style="margin-top:1.5rem">Harmonogram</h2>
        <label>Interwał (minuty) — tylko informacyjnie, faktyczny harmonogram ustaw w cron
            <input type="number" name="auto_interval_minutes" min="5" value="<?= e(setting('auto_interval_minutes', '60')) ?>">
        </label>
        <label>Max liczba postów na jeden run
            <input type="number" name="auto_max_posts_per_run" min="1" max="20" value="<?= e(setting('auto_max_posts_per_run', '3')) ?>">
        </label>

        <h2 style="margin-top:1.5rem">OpenAI</h2>
        <label>API key
            <input type="password" name="openai_api_key" value="<?= e(setting('openai_api_key', '')) ?>" placeholder="sk-...">
        </label>
        <label>Model
            <input type="text" name="openai_model" value="<?= e(setting('openai_model', 'gpt-4o-mini')) ?>">
        </label>
        <label>Temperatura (0.0 – 1.5)
            <input type="number" step="0.1" min="0" max="1.5" name="openai_temperature" value="<?= e(setting('openai_temperature', '0.4')) ?>">
        </label>

        <h2 style="margin-top:1.5rem">Domyślne wartości</h2>
        <label>Język docelowy<input type="text" name="auto_target_language" value="<?= e(setting('auto_target_language', 'pl')) ?>"></label>
        <label>Domyślna kategoria<input type="text" name="auto_default_category" value="<?= e(setting('auto_default_category', 'Aktualności')) ?>"></label>
        <label>Domyślny autor<input type="text" name="auto_default_author" value="<?= e(setting('auto_default_author', 'Redakcja AI')) ?>"></label>

        <h2 style="margin-top:1.5rem">Prompt redakcyjny (system)</h2>
        <label>Treść promptu — definiuje styl i format generowanych artykułów
            <textarea name="auto_prompt" rows="14"><?= e(setting('auto_prompt', '')) ?></textarea>
        </label>
        <p class="hint">Model musi zwrócić JSON z polami: <code>title, subtitle, excerpt, content, category, keywords, image_alt</code>.</p>

        <button type="submit" class="btn btn--primary">Zapisz ustawienia</button>
    </form>

    <div class="settings-card">
        <h2>Ostatni run</h2>
        <p><?= setting('auto_last_run') ? 'Zakończony: <strong>' . e(setting('auto_last_run')) . '</strong>' : 'Brak — nic jeszcze nie uruchomiono.' ?></p>
    </div>
</div>
<?php require __DIR__ . '/_footer.php';
