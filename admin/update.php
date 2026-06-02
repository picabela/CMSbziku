<?php
$adminTitle = 'Aktualizacje';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/updater.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$remote = null;      // wynik sprawdzenia zdalnej wersji
$updateLog = null;   // log po aktualizacji/przywracaniu

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        $flash = ['type' => 'error', 'msg' => 'Nieprawidłowy token CSRF.'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'check') {
            [$ok, $data, $err] = updaterFetchRemoteVersion();
            if (!$ok) {
                $flash = ['type' => 'error', 'msg' => $err];
            } else {
                $local = updaterCurrentVersion()['version'];
                $remote = $data;
                $remote['is_newer'] = updaterIsNewer($data['version'], $local);
                if ($remote['is_newer']) {
                    $flash = ['type' => 'success', 'msg' => 'Dostępna nowa wersja: v' . $data['version'] . '.'];
                } else {
                    $flash = ['type' => 'success', 'msg' => 'Masz najnowszą wersję (v' . $local . ').'];
                }
            }
        }

        if ($action === 'update') {
            // Ponowna weryfikacja, że faktycznie jest nowsza wersja
            [$ok, $data, $err] = updaterFetchRemoteVersion();
            if (!$ok) {
                $flash = ['type' => 'error', 'msg' => $err];
            } elseif (!updaterIsNewer($data['version'], updaterCurrentVersion()['version'])) {
                $flash = ['type' => 'error', 'msg' => 'Brak nowszej wersji do zainstalowania.'];
            } else {
                @set_time_limit(300);
                [$uok, $log, $uerr] = updaterRunUpdate();
                $updateLog = $log;
                if ($uok) {
                    $flash = ['type' => 'success', 'msg' => 'Zaktualizowano do v' . updaterCurrentVersion()['version'] . '.'];
                } else {
                    $flash = ['type' => 'error', 'msg' => $uerr];
                }
            }
        }

        if ($action === 'restore') {
            $name = $_POST['backup'] ?? '';
            @set_time_limit(300);
            [$rok, $log, $rerr] = updaterRestoreBackup($name);
            $updateLog = $log;
            $flash = $rok
                ? ['type' => 'success', 'msg' => 'Przywrócono kopię zapasową.']
                : ['type' => 'error', 'msg' => $rerr];
        }
    }
}

$current = updaterCurrentVersion();
$reqs = updaterCheckRequirements();
$reqsOk = updaterRequirementsOk($reqs);
$backups = updaterListBackups();

function updaterFmtBytes(int $b): string {
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024) return round($b / 1024) . ' KB';
    return $b . ' B';
}
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Aktualizacje CMS</h1>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <section class="settings-card">
        <h2>Wersja</h2>
        <dl class="info-list">
            <dt>Zainstalowana wersja</dt><dd><strong>v<?= e($current['version']) ?></strong><?= $current['released'] ? ' · ' . e($current['released']) : '' ?></dd>
            <dt>Repozytorium</dt><dd><code><?= e(updaterRepo()) ?></code> (gałąź <code><?= e(updaterBranch()) ?></code>)</dd>
        </dl>

        <form method="post" style="margin-top:1rem">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="check">
            <button type="submit" class="btn btn--primary">Sprawdź najnowszą wersję</button>
        </form>

        <?php if ($remote !== null): ?>
            <div class="flash <?= $remote['is_newer'] ? 'flash--success' : '' ?>" style="margin-top:1rem">
                <p style="margin:0"><strong>Wersja na GitHubie:</strong> v<?= e($remote['version']) ?><?= $remote['released'] ? ' · ' . e($remote['released']) : '' ?></p>
                <?php if ($remote['notes']): ?><p style="margin:0.4rem 0 0"><?= e($remote['notes']) ?></p><?php endif; ?>
            </div>

            <?php if ($remote['is_newer']): ?>
                <?php if ($reqsOk): ?>
                    <form method="post" style="margin-top:1rem" onsubmit="return confirm('Zaktualizować CMS do v<?= e($remote['version']) ?>?\n\nPrzed nadpisaniem plików zostanie utworzona kopia zapasowa. Baza danych, media, config.php i .htaccess pozostaną nietknięte.');">
                        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="update">
                        <button type="submit" class="btn btn--primary">Zaktualizuj do v<?= e($remote['version']) ?> teraz</button>
                    </form>
                <?php else: ?>
                    <p class="flash flash--error" style="margin-top:1rem">Środowisko nie spełnia wymagań — popraw pozycje oznaczone na czerwono w sekcji „Wymagania środowiska", aby odblokować aktualizację.</p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($updateLog !== null): ?>
        <section class="settings-card">
            <h2>Dziennik operacji</h2>
            <ul class="hint" style="margin:0 0 0 1.2rem;line-height:1.8">
                <?php foreach ($updateLog as $line): ?>
                    <li><?= e($line) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <section class="settings-card">
        <h2>Wymagania środowiska</h2>
        <p class="hint">Wszystkie pozycje muszą być spełnione, aby aktualizacja przebiegła bezpiecznie.</p>
        <ul style="list-style:none;padding:0;margin:0.5rem 0 0">
            <?php foreach ($reqs as $c): ?>
                <li style="padding:0.4rem 0;border-bottom:1px solid #eee">
                    <strong style="color:<?= $c['ok'] ? 'green' : 'red' ?>"><?= $c['ok'] ? '✓' : '✗' ?></strong>
                    <?= e($c['label']) ?>
                    <span class="hint" style="display:block;margin-left:1.4rem"><?= e($c['detail']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="settings-card">
        <h2>Co jest chronione podczas aktualizacji</h2>
        <p class="hint">Aktualizacja nadpisuje wyłącznie pliki rdzenia CMS. Następujące elementy NIGDY nie są nadpisywane — Twoje treści i konfiguracja są bezpieczne:</p>
        <ul class="hint" style="margin:0.4rem 0 0 1.2rem;line-height:1.8">
            <li><code>data/</code> — baza danych SQLite (artykuły, ustawienia, oceny, kolejka importu)</li>
            <li><code>uploads/</code> — wgrane media i logo</li>
            <li><code>includes/config.php</code> — konfiguracja serwera (jeśli nowa wersja ją zmienia, dostaniesz wzorzec <code>config.php.new</code> do porównania)</li>
            <li><code>.htaccess</code> — lokalne reguły serwera</li>
        </ul>
    </section>

    <section class="settings-card">
        <h2>Kopie zapasowe</h2>
        <p class="hint">Każda aktualizacja automatycznie tworzy kopię kodu (bez bazy i mediów). Przechowujemy 5 ostatnich. W razie problemów możesz przywrócić poprzednią wersję plików.</p>
        <?php if (empty($backups)): ?>
            <p class="hint">Brak kopii zapasowych — pojawią się po pierwszej aktualizacji.</p>
        <?php else: ?>
            <table style="width:100%;border-collapse:collapse;margin-top:0.5rem">
                <thead>
                    <tr style="text-align:left;border-bottom:2px solid #ddd">
                        <th style="padding:0.4rem">Plik</th>
                        <th style="padding:0.4rem">Rozmiar</th>
                        <th style="padding:0.4rem">Data</th>
                        <th style="padding:0.4rem"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $b): ?>
                        <tr style="border-bottom:1px solid #eee">
                            <td style="padding:0.4rem"><code><?= e($b['name']) ?></code></td>
                            <td style="padding:0.4rem"><?= e(updaterFmtBytes((int)$b['size'])) ?></td>
                            <td style="padding:0.4rem"><?= e(date('Y-m-d H:i', (int)$b['mtime'])) ?></td>
                            <td style="padding:0.4rem">
                                <form method="post" onsubmit="return confirm('Przywrócić pliki rdzenia z tej kopii? Baza danych i media pozostaną nietknięte.');">
                                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="backup" value="<?= e($b['name']) ?>">
                                    <button type="submit" class="btn">Przywróć</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/_footer.php';
