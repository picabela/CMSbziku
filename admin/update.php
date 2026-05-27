<?php
$adminTitle = 'Aktualizacja CMS';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/updater.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$checkResult = null;
$updateResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_config') {
        $repo = trim($_POST['update_github_repo'] ?? '');
        $branch = trim($_POST['update_github_branch'] ?? '');
        if (preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#i', $repo) && $branch !== '') {
            setSetting('update_github_repo', $repo);
            setSetting('update_github_branch', $branch);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Konfiguracja zapisana.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nieprawidłowe repo (format: owner/name) lub branch.'];
        }
        header('Location: update.php'); exit;
    }

    if ($action === 'check') $checkResult = checkLatestVersion(true);

    if ($action === 'update') {
        $updateResult = performUpdate();
        $checkResult = checkLatestVersion(true);
    }
}

$currentVersion = getCurrentVersion();
$cached = getCachedUpdateInfo();
$repo = setting('update_github_repo', 'picabela/CMSbziku');
$branch = setting('update_github_branch', 'main');
$lastCheck = setting('update_last_check', '');
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Aktualizacja CMS</h1>
        <span class="update-current-version">Aktualna wersja: <strong>v<?= e($currentVersion) ?></strong></span>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <?php if ($updateResult): ?>
        <div class="flash flash--<?= $updateResult['ok'] ? 'success' : 'error' ?>">
            <?= $updateResult['ok']
                ? '✓ Aktualizacja zakończona pomyślnie. Nowa wersja: v' . e($updateResult['new_version'] ?? '?') . '.'
                : '⚠ Aktualizacja nieudana. Szczegóły w logu poniżej.' ?>
        </div>
        <?php if (!empty($updateResult['stats'])): ?>
            <p class="hint">
                Pliki nadpisane: <strong><?= (int)$updateResult['stats']['files_updated'] ?></strong> ·
                Pominięte (chronione): <strong><?= (int)$updateResult['stats']['files_skipped'] ?></strong> ·
                Utworzone foldery: <strong><?= (int)$updateResult['stats']['dirs_created'] ?></strong>
            </p>
        <?php endif; ?>
        <details open class="settings-card">
            <summary><strong>📜 Log aktualizacji</strong></summary>
            <pre class="log-box" style="margin-top:0.75rem"><?= e(implode("\n", $updateResult['log'])) ?></pre>
        </details>
    <?php endif; ?>

    <section class="settings-card">
        <h2>Sprawdź dostępność aktualizacji</h2>
        <p class="hint">
            Sprawdza plik <code>VERSION</code> w repo GitHub.
            <?php if ($lastCheck): ?>Ostatnie sprawdzenie: <strong><?= e($lastCheck) ?></strong>.<?php endif; ?>
        </p>

        <form method="post" style="display:inline-block">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="check">
            <button type="submit" class="btn btn--primary">🔄 Sprawdź aktualizację</button>
        </form>

        <?php $resultToShow = $checkResult ?? $cached; ?>
        <?php if ($resultToShow && !empty($resultToShow['ok'])): ?>
            <div class="update-status update-status--<?= !empty($resultToShow['has_update']) ? 'available' : 'current' ?>" style="margin-top:1.25rem">
                <?php if (!empty($resultToShow['has_update'])): ?>
                    <h3>🆕 Dostępna nowa wersja: <span class="update-version">v<?= e($resultToShow['latest_version']) ?></span></h3>
                    <p>Twoja wersja: <strong>v<?= e($resultToShow['current_version']) ?></strong> → nowa: <strong>v<?= e($resultToShow['latest_version']) ?></strong></p>
                <?php else: ?>
                    <h3>✓ Masz najnowszą wersję</h3>
                    <p>Wersja na serwerze (<strong>v<?= e($resultToShow['current_version']) ?></strong>) jest taka sama jak najnowsza dostępna w repo.</p>
                <?php endif; ?>

                <?php if (!empty($resultToShow['last_commit'])): ?>
                    <p class="update-commit">
                        Ostatni commit:
                        <code><?= e($resultToShow['last_commit']['sha']) ?></code>
                        — <em><?= e(mb_substr((string)$resultToShow['last_commit']['message'], 0, 200)) ?></em>
                        <br><span class="muted"><?= e($resultToShow['last_commit']['author']) ?> · <?= e(substr((string)$resultToShow['last_commit']['date'], 0, 19)) ?></span>
                    </p>
                <?php endif; ?>

                <?php if (!empty($resultToShow['changelog'])): ?>
                    <details class="update-changelog" open><summary><strong>📋 Changelog</strong></summary><pre><?= e($resultToShow['changelog']) ?></pre></details>
                <?php endif; ?>

                <?php if (!empty($resultToShow['has_update'])): ?>
                    <form method="post" onsubmit="return confirmUpdate(this)" style="margin-top:1.25rem">
                        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="update">
                        <button type="submit" class="btn btn--primary btn--update-now">⬇ Aktualizuj do v<?= e($resultToShow['latest_version']) ?></button>
                    </form>
                    <p class="hint" style="margin-top:0.75rem"><strong>Bezpieczne:</strong> Twoje artykuły (<code>data/</code>), zdjęcia (<code>uploads/</code>) i własne motywy są chronione.</p>
                <?php endif; ?>
            </div>
        <?php elseif ($resultToShow): ?>
            <div class="flash flash--error" style="margin-top:1rem">⚠ <?= e($resultToShow['msg'] ?? 'Nieznany błąd.') ?></div>
        <?php endif; ?>
    </section>

    <section class="settings-card">
        <h2>⚙ Konfiguracja źródła aktualizacji</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_config">
            <div class="form-row form-row--2">
                <label>Repozytorium GitHub (owner/name)
                    <input type="text" name="update_github_repo" value="<?= e($repo) ?>" pattern="^[a-z0-9_.-]+/[a-z0-9_.-]+$" placeholder="picabela/CMSbziku">
                </label>
                <label>Branch z plikiem VERSION
                    <input type="text" name="update_github_branch" value="<?= e($branch) ?>" placeholder="main">
                </label>
            </div>
            <p class="hint">CMS sprawdza wersję: <code>https://raw.githubusercontent.com/<?= e($repo) ?>/<?= e($branch) ?>/VERSION</code></p>
            <button type="submit" class="btn">Zapisz konfigurację</button>
        </form>
    </section>
</div>

<script>
function confirmUpdate(form) {
    if (!confirm('Aktualizować CMS teraz? Operacja zajmuje ~30 sekund.\n\nZalecane: przed potwierdzeniem zrób eksport bazy w Eksport/Import.')) return false;
    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    btn.textContent = '⏳ Aktualizowanie... (czekaj, do 5 min)';
    return true;
}
</script>

<?php require __DIR__ . '/_footer.php';
