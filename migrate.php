<?php
/**
 * bziku CMS — jednorazowy skrypt migracyjny
 *
 * Wrzuć ten plik do katalogu głównego swojej (starej) instalacji CMS,
 * wejdź na https://twoja-strona.pl/migrate.php i postępuj krok po kroku.
 *
 * Co robi:
 *   1. Sprawdza wymagania środowiska
 *   2. Tworzy kopię zapasową kodu (ZIP, bez data/ i uploads/)
 *   3. Pobiera najnowszą wersję z GitHub i nadpisuje pliki CMS
 *   4. Uruchamia migracje bazy danych (nowe tabele, kolumny, ustawienia)
 *   5. Zachowuje: artykuły, ustawienia, pliki, includes/config.php, .htaccess
 *
 * Po zakończeniu USUŃ ten plik z serwera.
 */

@set_time_limit(300);
@ini_set('memory_limit', '256M');

// ─────────────────────────────────────────────────────────────────────────────
// Konfiguracja
// ─────────────────────────────────────────────────────────────────────────────

const REPO          = 'picabela/CMSbziku';
const BRANCH        = 'main';
const MIGRATE_TOKEN = 'migrate_2026_bziku'; // prosta ochrona przed przypadkowym uruchomieniem

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap – wczytaj config.php z katalogu CMS żeby znaleźć ścieżkę do bazy
// ─────────────────────────────────────────────────────────────────────────────

$ROOT = __DIR__;

// Wczytaj stałe z config.php (musi istnieć)
if (!file_exists($ROOT . '/includes/config.php')) {
    migrateHtml('Błąd', '<div class="err">Nie znaleziono <code>includes/config.php</code>. Upewnij się, że wrzuciłeś <code>migrate.php</code> do katalogu głównego swojej instalacji CMS.</div>');
    exit;
}

// Tymczasowo załaduj stałe z config.php
try {
    require_once $ROOT . '/includes/config.php';
} catch (Throwable $e) {
    migrateHtml('Błąd', '<div class="err">Nie można załadować <code>includes/config.php</code>: ' . htmlspecialchars($e->getMessage()) . '</div>');
    exit;
}

if (!defined('DB_PATH')) {
    migrateHtml('Błąd', '<div class="err">Stała <code>DB_PATH</code> nie jest zdefiniowana w <code>includes/config.php</code>.</div>');
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Token bezpieczeństwa: wymagamy ?token=... lub POST
// ─────────────────────────────────────────────────────────────────────────────

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$tokenOk = $token === MIGRATE_TOKEN;

$action = $_POST['action'] ?? '';

// ─────────────────────────────────────────────────────────────────────────────
// Pomocnicze funkcje
// ─────────────────────────────────────────────────────────────────────────────

function migrateHtml(string $title, string $body): void {
    echo '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8">
<meta name="robots" content="noindex,nofollow">
<title>' . htmlspecialchars($title) . ' · bziku CMS Migrate</title>
<style>
*{box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f5f5f5;margin:0;padding:2rem;color:#111}
.wrap{max-width:760px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.1);padding:2rem 2.5rem}
h1{font-size:1.5rem;margin:0 0 .25rem;border-bottom:3px solid #111;padding-bottom:.5rem}
.sub{color:#555;margin-bottom:1.5rem;font-size:.9rem}
.step{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:1.2rem 1.5rem;margin-bottom:1.2rem}
.step h2{font-size:1rem;margin:0 0 .6rem}
.ok{color:#1a7a1a;font-weight:600} .fail{color:#b91c1c;font-weight:600} .warn{color:#b45309;font-weight:600}
.log{font-family:monospace;font-size:.82rem;background:#111;color:#e5e7eb;padding:1rem;border-radius:4px;max-height:360px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;margin-top:.8rem}
.btn{display:inline-block;padding:.65rem 1.4rem;background:#111;color:#fff;border:none;border-radius:4px;font-size:.95rem;cursor:pointer;text-decoration:none;font-weight:600;margin-top:1rem}
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn-danger{background:#b91c1c}
.err{background:#fef2f2;border:1px solid #f87171;color:#b91c1c;border-radius:4px;padding:.8rem 1rem;margin:.5rem 0}
.succ{background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:4px;padding:.8rem 1rem;margin:.5rem 0}
.info{background:#eff6ff;border:1px solid #93c5fd;color:#1e40af;border-radius:4px;padding:.8rem 1rem;margin:.5rem 0}
table{width:100%;border-collapse:collapse;font-size:.88rem}
td,th{padding:.4rem .6rem;border:1px solid #e0e0e0;text-align:left}
th{background:#f5f5f5}
code{background:#f1f1f1;padding:.1em .4em;border-radius:3px;font-size:.88em}
</style></head><body><div class="wrap">
<h1>🔧 bziku CMS — Migrator</h1>
<p class="sub">Jednorazowy skrypt aktualizacji dla starszych instalacji CMS</p>
' . $body . '
</div></body></html>';
}

function migrateHttpGet(string $url, int $timeout = 90): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'bziku-CMS-Migrator/1.0',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) return [false, '', 'curl: ' . $err];
        if ($code >= 400)    return [false, '', 'HTTP ' . $code];
        return [true, (string)$body, ''];
    }
    if (ini_get('allow_url_fopen')) {
        $ctx  = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => 'bziku-CMS-Migrator/1.0', 'follow_location' => 1], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) return [false, '', 'file_get_contents nie powiódł się'];
        return [true, (string)$body, ''];
    }
    return [false, '', 'Brak curl i allow_url_fopen'];
}

function migrateRrmdir(string $dir): void {
    if (!is_dir($dir)) { if (is_file($dir)) @unlink($dir); return; }
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iter as $i) { $i->isDir() ? @rmdir($i->getPathname()) : @unlink($i->getPathname()); }
    @rmdir($dir);
}

function migrateIsProtected(string $rel): bool {
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    foreach (['data', 'uploads', 'includes/config.php', '.htaccess', '.git', basename(__FILE__)] as $p) {
        if ($rel === $p || str_starts_with($rel, rtrim($p, '/') . '/')) return true;
    }
    return false;
}

function migrateAddColumnIfMissing(PDO $pdo, string $table, string $col, string $def): void {
    try {
        $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) { if ($c['name'] === $col) return; }
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
    } catch (Throwable $e) {
        // nieszkodliwe
    }
}

function migrateTableExists(PDO $pdo, string $table): bool {
    $r = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table))->fetch();
    return (bool)$r;
}

// ─────────────────────────────────────────────────────────────────────────────
// Strona startowa (bez tokena)
// ─────────────────────────────────────────────────────────────────────────────

if (!$tokenOk && $action === '') {
    migrateHtml('Witaj', '
<div class="info"><strong>Bezpieczeństwo:</strong> Aby uruchomić migratora, wejdź pod adres:<br>
<code>' . htmlspecialchars((isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'twoja-strona.pl') . $_SERVER['REQUEST_URI']) . '<strong>?token=' . MIGRATE_TOKEN . '</strong></code></div>
<p>Skrypt zaktualizuje pliki CMS do najnowszej wersji zachowując:</p>
<ul><li>Artykuły i ustawienia w bazie danych</li><li>Pliki w <code>uploads/</code></li><li><code>includes/config.php</code> i <code>.htaccess</code></li></ul>
<p><strong>Po zakończeniu usuń <code>migrate.php</code> z serwera!</strong></p>
');
    exit;
}
if (!$tokenOk) {
    migrateHtml('Brak dostępu', '<div class="err">Nieprawidłowy token.</div>');
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// KROK 1 — Sprawdzenie wymagań i ekran powitalny
// ─────────────────────────────────────────────────────────────────────────────

if ($action === '') {
    // Sprawdź wymagania
    $checks = [];

    $checks[] = ['PHP ≥ 8.0', PHP_VERSION_ID >= 80000, 'Zainstalowana: ' . PHP_VERSION];
    $checks[] = ['SQLite3 / PDO SQLite', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? 'dostępny' : 'BRAK rozszerzenia pdo_sqlite'];
    $checks[] = ['ZipArchive', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'dostępny' : 'BRAK — wymagany do pobrania paczki'];
    $hasHttp = function_exists('curl_init') || ini_get('allow_url_fopen');
    $checks[] = ['HTTP (curl lub allow_url_fopen)', $hasHttp, function_exists('curl_init') ? 'curl OK' : (ini_get('allow_url_fopen') ? 'allow_url_fopen=On' : 'BRAK — nie można pobierać')];
    $checks[] = ['Zapis w katalogu instalacji', is_writable($ROOT), is_writable($ROOT) ? $ROOT : 'BRAK uprawnień zapisu w ' . $ROOT];
    $dataDir = dirname(DB_PATH);
    $dataOk = (is_dir($dataDir) ? is_writable($dataDir) : @mkdir($dataDir, 0775, true));
    $checks[] = ['Katalog data/ zapisywalny', (bool)$dataOk, $dataOk ? $dataDir : 'BRAK uprawnień zapisu w ' . $dataDir];
    $checks[] = ['Plik bazy danych istnieje', is_file(DB_PATH), is_file(DB_PATH) ? DB_PATH : 'NIE ZNALEZIONO — czy ścieżka w config.php jest poprawna?'];

    $allOk = true;
    $rows = '';
    foreach ($checks as [$label, $ok, $detail]) {
        if (!$ok) $allOk = false;
        $rows .= '<tr><td>' . htmlspecialchars($label) . '</td><td class="' . ($ok ? 'ok' : 'fail') . '">' . ($ok ? '✓' : '✗') . '</td><td>' . htmlspecialchars($detail) . '</td></tr>';
    }

    // Wersja lokalna
    $localVer = '(nieznana)';
    if (is_file($ROOT . '/version.json')) {
        $vd = json_decode((string)file_get_contents($ROOT . '/version.json'), true);
        $localVer = $vd['version'] ?? '(nieznana)';
    } elseif (is_file($ROOT . '/includes/functions.php')) {
        $localVer = '< 1.0.0 (brak version.json)';
    }

    // Wersja zdalna (szybki podgląd)
    $remoteVer = '(nie pobrano)'; $remoteNotes = '';
    [$rvOk, $rvBody] = migrateHttpGet('https://raw.githubusercontent.com/' . REPO . '/' . BRANCH . '/version.json', 10);
    if ($rvOk) {
        $rvd = json_decode($rvBody, true);
        $remoteVer = $rvd['version'] ?? '?';
        $remoteNotes = $rvd['notes'] ?? '';
    }

    $btn = $allOk
        ? '<form method="post"><input type="hidden" name="token" value="' . MIGRATE_TOKEN . '"><input type="hidden" name="action" value="run"><button class="btn" type="submit">▶ Uruchom migrację i aktualizację</button></form>'
        : '<div class="err">Napraw powyższe problemy przed uruchomieniem migracji.</div>';

    migrateHtml('Przygotowanie', '
<div class="step">
  <h2>Wersje</h2>
  <table><tr><th>Zainstalowana</th><td>' . htmlspecialchars($localVer) . '</td></tr>
  <tr><th>Dostępna (GitHub)</th><td><strong>' . htmlspecialchars($remoteVer) . '</strong>' . ($remoteNotes ? '<br><small style="color:#555">' . htmlspecialchars(mb_substr($remoteNotes, 0, 200)) . '</small>' : '') . '</td></tr></table>
</div>
<div class="step">
  <h2>Sprawdzenie wymagań</h2>
  <table><thead><tr><th>Wymaganie</th><th>Status</th><th>Szczegóły</th></tr></thead><tbody>' . $rows . '</tbody></table>
</div>
<div class="step">
  <h2>Co zostanie zachowane</h2>
  <ul>
    <li>📦 <strong>Baza danych</strong> — artykuły, ustawienia, kategorie, tagi, kolejka, źródła</li>
    <li>🖼 <strong>Pliki</strong> — katalog <code>uploads/</code> (obrazy, favicon itp.)</li>
    <li>⚙️ <strong>Konfiguracja serwera</strong> — <code>includes/config.php</code> i <code>.htaccess</code></li>
    <li>📁 <strong>Ten plik</strong> — <code>migrate.php</code> nie zostanie nadpisany</li>
  </ul>
  <p><em>Przed kopiowaniem plików skrypt tworzy kopię zapasową ZIP kodu (w <code>data/backups/</code>).</em></p>
</div>
' . $btn . '
');
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// KROK 2 — Właściwa migracja (action=run)
// ─────────────────────────────────────────────────────────────────────────────

if ($action === 'run') {

    $log   = [];
    $error = null;

    function L(string $msg, string $type = 'ok'): void {
        global $log;
        $log[] = [$type, $msg];
    }

    // ── 1. Backup kodu ──────────────────────────────────────────────────────

    L('━━ KROK 1: Tworzenie kopii zapasowej kodu ━━', 'head');

    $backupDir = dirname(DB_PATH) . '/backups';
    if (!is_dir($backupDir)) @mkdir($backupDir, 0775, true);

    if (!class_exists('ZipArchive')) {
        $error = 'ZipArchive niedostępne — nie można kontynuować.';
    } else {
        $localVer = '0.0.0';
        if (is_file($ROOT . '/version.json')) {
            $vd = json_decode((string)file_get_contents($ROOT . '/version.json'), true);
            $localVer = $vd['version'] ?? '0.0.0';
        }
        $backupName = 'backup-pre-migrate-v' . $localVer . '-' . date('Ymd-His') . '.zip';
        $backupPath = $backupDir . '/' . $backupName;

        $zip = new ZipArchive();
        if ($zip->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $skip = ['data', 'uploads', '.git'];
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            $bc = 0;
            foreach ($iter as $file) {
                $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($ROOT))), '/');
                if ($rel === '') continue;
                if (in_array(explode('/', $rel)[0], $skip, true)) continue;
                if ($file->isDir()) { $zip->addEmptyDir($rel); } else { $zip->addFile($file->getPathname(), $rel); $bc++; }
            }
            $zip->close();
            L("Kopia zapasowa: {$backupName} ({$bc} plików)");
        } else {
            L('Nie można utworzyć kopii zapasowej ZIP — kontynuuję bez kopii.', 'warn');
        }

        // ── 2. Pobierz ZIP z GitHub ─────────────────────────────────────────

        L('━━ KROK 2: Pobieranie najnowszej wersji z GitHub ━━', 'head');

        $workDir = dirname(DB_PATH) . '/_migrate_work';
        migrateRrmdir($workDir);
        @mkdir($workDir, 0775, true);

        $zipUrl  = 'https://codeload.github.com/' . REPO . '/zip/refs/heads/' . BRANCH;
        [$dlOk, $dlBody, $dlErr] = migrateHttpGet($zipUrl, 180);

        if (!$dlOk) {
            $error = 'Pobieranie paczki nie powiodło się: ' . $dlErr;
        } else {
            $zipPath = $workDir . '/package.zip';
            if (@file_put_contents($zipPath, $dlBody) === false) {
                $error = 'Nie można zapisać pobranego archiwum.';
            } else {
                L('Pobrano: ' . round(strlen($dlBody) / 1024) . ' KB');
                unset($dlBody);

                // ── 3. Rozpakuj ─────────────────────────────────────────────

                L('━━ KROK 3: Rozpakowywanie archiwum ━━', 'head');

                $extractDir = $workDir . '/extracted';
                @mkdir($extractDir, 0775, true);
                $zip2 = new ZipArchive();
                if ($zip2->open($zipPath) !== true) {
                    $error = 'Nie można otworzyć pobranego ZIP.';
                } elseif (!$zip2->extractTo($extractDir)) {
                    $zip2->close();
                    $error = 'Rozpakowywanie archiwum nie powiodło się.';
                } else {
                    $zip2->close();
                    L('Archiwum rozpakowane.');

                    $subdirs = glob($extractDir . '/*', GLOB_ONLYDIR) ?: [];
                    if (count($subdirs) !== 1) {
                        $error = 'Nieoczekiwana struktura archiwum (oczekiwano 1 katalogu głównego, znaleziono: ' . count($subdirs) . ').';
                    } else {
                        $srcRoot = $subdirs[0];

                        if (!is_file($srcRoot . '/index.php') || !is_file($srcRoot . '/version.json')) {
                            $error = 'Paczka nie wygląda jak instalacja bziku CMS (brak index.php lub version.json).';
                        } else {
                            $newVer = json_decode((string)file_get_contents($srcRoot . '/version.json'), true);
                            L('Wersja docelowa: v' . ($newVer['version'] ?? '?'));

                            // ── 4. Kopiuj pliki ─────────────────────────────

                            L('━━ KROK 4: Kopiowanie plików CMS ━━', 'head');

                            $copied = 0; $skipped = 0; $copyErrors = [];
                            $iter2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                            foreach ($iter2 as $item) {
                                $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($srcRoot))), '/');
                                if ($rel === '') continue;
                                if (migrateIsProtected($rel)) { $skipped++; continue; }
                                $target = $ROOT . '/' . $rel;
                                if ($item->isDir()) {
                                    if (!is_dir($target) && !@mkdir($target, 0775, true)) $copyErrors[] = 'Nie można utworzyć katalogu: ' . $rel;
                                } else {
                                    $d = dirname($target);
                                    if (!is_dir($d)) @mkdir($d, 0775, true);
                                    if (@copy($item->getPathname(), $target)) { $copied++; }
                                    else { $copyErrors[] = 'Nie można zapisać: ' . $rel; }
                                }
                            }

                            L("Skopiowano: {$copied} plików, pominięto (chronione): {$skipped}.");

                            // Wzorzec nowego config.php jeśli różny
                            $newCfg = $srcRoot . '/includes/config.php';
                            $curCfg = $ROOT . '/includes/config.php';
                            if (is_file($newCfg) && is_file($curCfg) && md5_file($newCfg) !== md5_file($curCfg)) {
                                @copy($newCfg, $curCfg . '.new');
                                L('Uwaga: config.php zmienił się w nowej wersji. Nowy wzorzec zapisano jako config.php.new — porównaj ręcznie.', 'warn');
                            }

                            if ($copyErrors) {
                                foreach (array_slice($copyErrors, 0, 15) as $ce) L('Błąd kopiowania: ' . $ce, 'err');
                                $error = count($copyErrors) . ' plików nie udało się skopiować. Strona może działać nieprawidłowo.';
                            }
                        }
                    }
                }
            }
        }

        migrateRrmdir($workDir);
    }

    // ── 5. Migracje bazy danych ─────────────────────────────────────────────

    L('━━ KROK 5: Migracje bazy danych ━━', 'head');

    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON;');

        // ── Tabele (CREATE IF NOT EXISTS) ──
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT UNIQUE NOT NULL,
                title TEXT NOT NULL,
                subtitle TEXT,
                excerpt TEXT,
                content TEXT NOT NULL DEFAULT '',
                featured_image TEXT,
                featured_image_alt TEXT,
                category TEXT DEFAULT 'Aktualności',
                author TEXT DEFAULT 'Redakcja',
                meta_title TEXT,
                meta_description TEXT,
                meta_keywords TEXT,
                status TEXT DEFAULT 'published',
                published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT);

            CREATE INDEX IF NOT EXISTS idx_posts_slug      ON posts(slug);
            CREATE INDEX IF NOT EXISTS idx_posts_status    ON posts(status);
            CREATE INDEX IF NOT EXISTS idx_posts_published ON posts(published_at);
            CREATE INDEX IF NOT EXISTS idx_posts_category  ON posts(category);

            CREATE TABLE IF NOT EXISTS sources (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                feed_url TEXT NOT NULL,
                site_url TEXT,
                category TEXT,
                language TEXT DEFAULT 'en',
                max_items_per_run INTEGER DEFAULT 2,
                auto_publish INTEGER DEFAULT NULL,
                enabled INTEGER DEFAULT 1,
                last_fetched_at DATETIME,
                last_error TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS auto_imports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id INTEGER,
                external_url TEXT,
                external_guid TEXT,
                guid_hash TEXT UNIQUE NOT NULL,
                post_id INTEGER,
                imported_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_imports_hash ON auto_imports(guid_hash);

            CREATE TABLE IF NOT EXISTS auto_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                finished_at DATETIME,
                status TEXT DEFAULT 'running',
                items_found INTEGER DEFAULT 0,
                items_imported INTEGER DEFAULT 0,
                items_skipped INTEGER DEFAULT 0,
                items_failed INTEGER DEFAULT 0,
                items_enqueued INTEGER DEFAULT 0,
                log TEXT,
                error TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_runs_started ON auto_runs(started_at);

            CREATE TABLE IF NOT EXISTS auto_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id INTEGER,
                external_url TEXT NOT NULL,
                external_guid TEXT,
                guid_hash TEXT UNIQUE NOT NULL,
                title TEXT,
                description TEXT,
                published_ts INTEGER,
                status TEXT DEFAULT 'pending',
                attempts INTEGER DEFAULT 0,
                max_attempts INTEGER DEFAULT 3,
                next_attempt_at DATETIME,
                error TEXT,
                post_id INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_queue_status ON auto_queue(status);
            CREATE INDEX IF NOT EXISTS idx_queue_next   ON auto_queue(next_attempt_at);
            CREATE INDEX IF NOT EXISTS idx_queue_hash   ON auto_queue(guid_hash);

            CREATE TABLE IF NOT EXISTS categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                description TEXT,
                sort_order INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                usage_count INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_tags_slug ON tags(slug);

            CREATE TABLE IF NOT EXISTS pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT UNIQUE NOT NULL,
                title TEXT NOT NULL,
                content TEXT NOT NULL DEFAULT '',
                meta_title TEXT,
                meta_description TEXT,
                meta_keywords TEXT,
                status TEXT DEFAULT 'published',
                sort_order INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_pages_slug ON pages(slug);

            CREATE TABLE IF NOT EXISTS post_tags (
                post_id INTEGER NOT NULL,
                tag_id  INTEGER NOT NULL,
                PRIMARY KEY (post_id, tag_id)
            );
            CREATE INDEX IF NOT EXISTS idx_post_tags_post ON post_tags(post_id);
            CREATE INDEX IF NOT EXISTS idx_post_tags_tag  ON post_tags(tag_id);

            CREATE TABLE IF NOT EXISTS post_ratings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                ip_hash TEXT NOT NULL,
                rating INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(post_id, ip_hash)
            );
            CREATE INDEX IF NOT EXISTS idx_ratings_post ON post_ratings(post_id);

            CREATE TABLE IF NOT EXISTS post_categories (
                post_id  INTEGER NOT NULL,
                cat_name TEXT    NOT NULL,
                PRIMARY KEY (post_id, cat_name)
            );
            CREATE INDEX IF NOT EXISTS idx_post_cats_post ON post_categories(post_id);
            CREATE INDEX IF NOT EXISTS idx_post_cats_cat  ON post_categories(cat_name);

            CREATE TABLE IF NOT EXISTS indexing_log (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                url        TEXT    NOT NULL,
                method     TEXT    NOT NULL,
                ok         INTEGER NOT NULL DEFAULT 0,
                response   TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_indexing_log_created ON indexing_log(created_at);
        ");
        L('Tabele: utworzone / już istniały.');

        // ── Brakujące kolumny (ALTER TABLE) ──
        $cols = [
            ['posts',     'subtitle',           "TEXT"],
            ['posts',     'excerpt',             "TEXT"],
            ['posts',     'featured_image',      "TEXT"],
            ['posts',     'featured_image_alt',  "TEXT"],
            ['posts',     'source_attribution',  "TEXT"],
            ['posts',     'tldr',                "TEXT"],
            ['posts',     'show_toc',            "INTEGER"],
            ['sources',   'source_type',         "TEXT DEFAULT 'rss'"],
            ['sources',   'link_selector',       "TEXT"],
            ['sources',   'max_age_days',        "INTEGER"],
            ['sources',   'auto_publish',        "INTEGER DEFAULT NULL"],
            ['auto_runs', 'items_enqueued',      "INTEGER DEFAULT 0"],
        ];
        $altered = 0;
        foreach ($cols as [$tbl, $col, $def]) {
            $before = array_column($pdo->query("PRAGMA table_info({$tbl})")->fetchAll(), 'name');
            if (!in_array($col, $before, true)) {
                $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN {$col} {$def}");
                $altered++;
            }
        }
        L("Brakujące kolumny dodane: {$altered}.");

        // ── Domyślne wpisy kategorii (jeśli tabela pusta) ──
        $catCount = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
        if ($catCount === 0) {
            $catStmt = $pdo->prepare('INSERT OR IGNORE INTO categories (name, slug, description, sort_order) VALUES (?, ?, ?, ?)');
            foreach ([
                ['SEO',           'seo',           'Klasyczne pozycjonowanie i optymalizacja pod wyszukiwarki.', 0],
                ['GEO',           'geo',           'Generative Engine Optimization — widoczność w AI.', 10],
                ['ADS',           'ads',           'Reklamy płatne: Google Ads, Meta Ads, programmatic.', 20],
                ['AI',            'ai',            'Sztuczna inteligencja w marketingu i modele językowe.', 30],
                ['Technical SEO', 'technical-seo', 'Performance, Core Web Vitals, dane strukturalne, indeksacja.', 40],
                ['Aktualności',   'aktualnosci',   'Wszystko, co nie pasuje do wyspecjalizowanych kategorii.', 50],
            ] as $c) $catStmt->execute($c);
            L('Domyślne kategorie SEO/GEO/ADS/AI dodane.');
        }

        // ── Backfill post_categories na podstawie posts.category ──
        $missing = (int)$pdo->query('SELECT COUNT(*) FROM posts WHERE id NOT IN (SELECT DISTINCT post_id FROM post_categories)')->fetchColumn();
        if ($missing > 0) {
            $pdo->exec("INSERT OR IGNORE INTO post_categories (post_id, cat_name) SELECT id, category FROM posts WHERE category IS NOT NULL AND category != ''");
            L("Backfill post_categories: {$missing} artykułów przypisanych do swoich kategorii.");
        }

        // ── Domyślne ustawienia (INSERT OR IGNORE) ──
        $defaults = [
            'auto_enabled' => '0',
            'auto_token' => bin2hex(random_bytes(16)),
            'auto_interval_minutes' => '60',
            'auto_max_posts_per_run' => '3',
            'auto_max_age_days' => '3',
            'auto_posts_per_tick' => '2',
            'auto_discovery_interval_minutes' => '60',
            'auto_publish' => '1',
            'openai_api_key' => '',
            'openai_model' => 'gpt-4o-mini',
            'openai_temperature' => '0.4',
            'auto_target_language' => 'pl',
            'auto_default_category' => 'Aktualności',
            'auto_default_author' => 'Redakcja AI',
            'auto_last_run' => '',
            'site_name' => '',
            'site_tagline' => '',
            'site_logo' => '',
            'site_favicon' => '',
            'indexing_google_enabled'   => '0',
            'indexing_indexnow_enabled' => '0',
            'indexing_indexnow_key'     => '',
            'indexing_auto_on_publish'  => '0',
            'top_notice_enabled' => '1',
            'top_notice_text' => 'Same fakty, bez lania wody. Czytaj wygodnie na ebooku, tablecie lub w przerwie na kawę.',
            'contact_enabled' => '1',
            'contact_email' => '',
            'contact_subject_prefix' => '[bziku CMS]',
            'auto_max_tags' => '3',
            'tag_label' => 'Tagi',
            'source_attribution_template' => 'Opracowanie redakcji na podstawie źródła: {url} ({source}).',
            'auto_keep_original_date' => '0',
            'auto_content_max_chars' => '30000',
            'auto_date_range_enabled' => '0',
            'auto_date_from' => '',
            'auto_date_to' => '',
            'max_categories_per_post' => '2',
            'auto_prompt_category' => '',
            'auto_prompt_tags' => '',
            'theme_ai_prompt' => '',
            'footer_tags_count' => '20',
            'footer_categories_count' => '8',
            'custom_head_code' => '',
            'custom_body_start_code' => '',
            'custom_body_end_code' => '',
            'gtm_id' => '',
            'ga4_id' => '',
            'gsc_verification' => '',
            'bing_verification' => '',
            'facebook_pixel_id' => '',
            'active_theme' => 'classic',
            'header_menu_items' => '',
            'footer_menu_items' => '',
            'theme_color_overrides' => '',
            'toc_enabled_global' => '1',
            'auto_generate_tldr' => '1',
            'auto_internal_links' => '1',
            'webp_conversion' => '1',
            'reading_progress_bar' => '1',
            'critical_css_inline' => '1',
            'ratings_enabled' => '1',
            'cache_version' => '1',
            'masthead_edition_enabled' => '1',
            'masthead_edition_text' => 'Wydanie cyfrowe',
            'rodo_enabled' => '0',
            'rodo_consent_mode_v2' => '1',
            'rodo_banner_position' => 'bottom',
            'rodo_banner_style' => 'modal',
            'rodo_banner_title' => 'Szanujemy Twoją prywatność',
            'rodo_banner_text' => 'Używamy plików cookie, by strona działała sprawnie i lepiej dopasowywała się do Twoich potrzeb.',
            'rodo_show_logo' => '1',
            'rodo_consent_lifetime_days' => '365',
            'rodo_auto_generate_policy' => '1',
            'rodo_company_form' => 'individual',
            'rodo_company_name' => '',
            'rodo_company_address' => '',
            'rodo_company_email' => '',
            'rodo_company_nip' => '',
            'rodo_dpo_contact' => '',
            'rodo_show_company_data' => '0',
            'rodo_categories' => '',
            'rodo_color_primary' => '#2540b8',
            'rodo_accept_all_text' => 'Akceptuję wszystkie',
            'rodo_accept_selected_text' => 'Zapisz mój wybór',
            'rodo_reject_text' => 'Tylko niezbędne',
        ];
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
        $inserted = 0;
        foreach ($defaults as $k => $v) {
            $stmt->execute([$k, $v]);
            $inserted += $stmt->rowCount();
        }
        L("Ustawienia: {$inserted} nowych wpisów domyślnych dodanych (istniejące zachowane).");

        // ── Ustawienie hasła (jeśli brakuje) ──
        $hasPass = $pdo->query("SELECT value FROM settings WHERE key='admin_password_hash'")->fetchColumn();
        if (!$hasPass) {
            $defPass = defined('DEFAULT_ADMIN_PASSWORD') ? DEFAULT_ADMIN_PASSWORD : 'admin';
            $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES ('admin_password_hash', ?)")
                ->execute([password_hash($defPass, PASSWORD_DEFAULT)]);
            L("Hasło admina ustawione na: <strong>" . htmlspecialchars($defPass) . "</strong> — zmień je natychmiast po zalogowaniu!", 'warn');
        }

        L('Migracje bazy zakończone pomyślnie.');

    } catch (Throwable $dbe) {
        L('BŁĄD migracji bazy: ' . $dbe->getMessage(), 'err');
        if (!$error) $error = 'Migracja bazy zgłosiła błąd: ' . $dbe->getMessage();
    }

    // ── Wynik ──────────────────────────────────────────────────────────────

    $logHtml = '';
    foreach ($log as [$type, $msg]) {
        $icons = ['head' => '──', 'ok' => '✓', 'warn' => '⚠', 'err' => '✗'];
        $colors = ['head' => '#93c5fd', 'ok' => '#86efac', 'warn' => '#fcd34d', 'err' => '#f87171'];
        $ic = $icons[$type] ?? '·';
        $cl = $colors[$type] ?? '#e5e7eb';
        $logHtml .= '<span style="color:' . $cl . '">' . $ic . ' ' . $msg . '</span>' . "\n";
    }

    if ($error) {
        $result = '<div class="err"><strong>Wystąpił błąd:</strong><br>' . htmlspecialchars($error) . '</div>';
    } else {
        // Finalna wersja
        $finalVer = '?';
        if (is_file($ROOT . '/version.json')) {
            $fvd = json_decode((string)file_get_contents($ROOT . '/version.json'), true);
            $finalVer = $fvd['version'] ?? '?';
        }
        $result = '<div class="succ">✅ <strong>Migracja zakończona pomyślnie!</strong> Zainstalowana wersja: <strong>v' . htmlspecialchars($finalVer) . '</strong></div>';
    }

    $adminUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['REQUEST_URI'] ?? ''), '/') . '/admin/';

    migrateHtml('Wynik migracji', '
' . $result . '
<div class="step">
  <h2>Log operacji</h2>
  <div class="log">' . $logHtml . '</div>
</div>
' . (!$error ? '
<div class="step">
  <h2>Co dalej?</h2>
  <ol>
    <li>Sprawdź, czy strona działa poprawnie: <a href="' . htmlspecialchars($adminUrl) . '" target="_blank">przejdź do panelu CMS</a></li>
    <li>Zaloguj się i zweryfikuj artykuły, ustawienia i motywy</li>
    <li><strong>Usuń plik <code>migrate.php</code> z serwera — pozostawienie go stanowi ryzyko bezpieczeństwa!</strong></li>
    <li>Od teraz możesz aktualizować CMS z panelu: <em>Panel → Aktualizacje</em></li>
  </ol>
  <a class="btn btn-danger" href="?token=' . MIGRATE_TOKEN . '&delete=1" onclick="return confirm(\'Na pewno usunąć migrate.php z serwera?\')">🗑 Usuń migrate.php teraz</a>
  &nbsp;
  <a class="btn" href="' . htmlspecialchars($adminUrl) . '" target="_blank">Przejdź do panelu →</a>
</div>
' : '
<div class="step">
  <h2>Opcje naprawcze</h2>
  <p>W katalogu <code>data/backups/</code> powinna znajdować się kopia zapasowa kodu sprzed migracji.<br>
  Możesz ją przywrócić ręcznie lub ponownie uruchomić skrypt.</p>
  <a class="btn" href="?token=' . MIGRATE_TOKEN . '">← Wróć do ekranu startowego</a>
</div>
') . '
');
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Akcja usunięcia skryptu
// ─────────────────────────────────────────────────────────────────────────────

if (isset($_GET['delete']) && $tokenOk) {
    $self = __FILE__;
    if (@unlink($self)) {
        migrateHtml('Gotowe', '<div class="succ">✅ Plik <code>migrate.php</code> został usunięty. Instalacja jest bezpieczna.</div><p><a href="' . htmlspecialchars((isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . '/admin/') . '">Przejdź do panelu CMS →</a></p>');
    } else {
        migrateHtml('Błąd', '<div class="err">Nie udało się usunąć pliku. Usuń <code>migrate.php</code> ręcznie przez FTP/menedżer plików.</div>');
    }
    exit;
}

// fallback
header('Location: ?token=' . MIGRATE_TOKEN);
