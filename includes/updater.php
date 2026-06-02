<?php
/**
 * System aktualizacji CMS z GitHuba (gałąź main).
 *
 * Założenia bezpieczeństwa:
 *  - NIGDY nie nadpisujemy danych lokalnych: data/ (baza SQLite), uploads/ (media),
 *    includes/config.php (konfiguracja serwera) ani .htaccess.
 *  - Przed każdą aktualizacją tworzymy kopię zapasową kodu (bez data/ i uploads/).
 *  - Pobieramy publiczne archiwum ZIP — brak potrzeby tokenu GitHub.
 */

require_once __DIR__ . '/functions.php';

/** Slug repozytorium na GitHubie (właściciel/nazwa). Można nadpisać w ustawieniach. */
function updaterRepo(): string {
    $v = trim((string)setting('update_repo', ''));
    return $v !== '' ? $v : 'picabela/CMSbziku';
}

/** Gałąź, z której pobieramy aktualizacje. */
function updaterBranch(): string {
    $v = trim((string)setting('update_branch', ''));
    return $v !== '' ? $v : 'main';
}

/** Katalog roboczy aktualizacji (pod data/, więc wykluczony z nadpisywania). */
function updaterWorkDir(): string {
    return dirname(DB_PATH) . '/_update';
}

/** Katalog kopii zapasowych. */
function updaterBackupDir(): string {
    return dirname(DB_PATH) . '/backups';
}

/** Korzeń instalacji (folder z index.php). */
function updaterRootDir(): string {
    return dirname(__DIR__);
}

/** Ścieżki, których aktualizacja NIE może nadpisać (relatywnie do korzenia). */
function updaterProtectedPaths(): array {
    return [
        'data',                  // baza SQLite, kopie, pliki robocze
        'uploads',               // media wgrane przez użytkownika
        'includes/config.php',   // konfiguracja specyficzna dla serwera
        '.htaccess',             // lokalne reguły serwera
        '.git',                  // repozytorium (jeśli istnieje)
    ];
}

function updaterIsProtected(string $relPath): bool {
    $relPath = ltrim(str_replace('\\', '/', $relPath), '/');
    foreach (updaterProtectedPaths() as $p) {
        if ($relPath === $p || str_starts_with($relPath, $p . '/')) {
            return true;
        }
    }
    return false;
}

/** Lokalna wersja z version.json (fallback 0.0.0). */
function updaterCurrentVersion(): array {
    $file = updaterRootDir() . '/version.json';
    if (is_file($file)) {
        $data = json_decode((string)file_get_contents($file), true);
        if (is_array($data) && !empty($data['version'])) {
            return [
                'version'  => (string)$data['version'],
                'released' => (string)($data['released'] ?? ''),
                'notes'    => (string)($data['notes'] ?? ''),
            ];
        }
    }
    return ['version' => '0.0.0', 'released' => '', 'notes' => ''];
}

/**
 * Prosty klient HTTP — curl jeśli dostępny, inaczej file_get_contents.
 * Zwraca [ok(bool), body(string), error(string), http_code(int)].
 */
function updaterHttpGet(string $url, int $timeout = 60): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'bziku-CMS-Updater',
            CURLOPT_HTTPHEADER     => ['Accept: */*'],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            return [false, '', 'curl: ' . $err, $code];
        }
        if ($code >= 400) {
            return [false, '', 'HTTP ' . $code, $code];
        }
        return [true, (string)$body, '', $code];
    }

    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => ['timeout' => $timeout, 'user_agent' => 'bziku-CMS-Updater', 'follow_location' => 1],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return [false, '', 'file_get_contents nie powiódł się (allow_url_fopen).', 0];
        }
        return [true, (string)$body, '', 200];
    }

    return [false, '', 'Brak curl oraz allow_url_fopen — nie można pobierać przez HTTP.', 0];
}

/** URL pliku version.json z gałęzi. */
function updaterRemoteVersionUrl(): string {
    return 'https://raw.githubusercontent.com/' . updaterRepo() . '/' . updaterBranch() . '/version.json';
}

/** URL archiwum ZIP gałęzi. */
function updaterZipUrl(): string {
    return 'https://codeload.github.com/' . updaterRepo() . '/zip/refs/heads/' . updaterBranch();
}

/**
 * Pobiera zdalną wersję z GitHuba.
 * Zwraca [ok, data(array)|null, error].
 */
function updaterFetchRemoteVersion(): array {
    [$ok, $body, $err] = updaterHttpGet(updaterRemoteVersionUrl(), 30);
    if (!$ok) {
        return [false, null, 'Nie udało się pobrać informacji o wersji: ' . $err];
    }
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['version'])) {
        return [false, null, 'Plik version.json na GitHubie jest nieprawidłowy.'];
    }
    return [true, [
        'version'  => (string)$data['version'],
        'released' => (string)($data['released'] ?? ''),
        'notes'    => (string)($data['notes'] ?? ''),
    ], ''];
}

/** Czy zdalna wersja jest nowsza od lokalnej. */
function updaterIsNewer(string $remote, string $local): bool {
    return version_compare($remote, $local, '>');
}

/**
 * Sprawdza wymagania środowiska potrzebne do aktualizacji.
 * Zwraca listę [label, ok(bool), detail].
 */
function updaterCheckRequirements(): array {
    $root = updaterRootDir();
    $checks = [];

    $checks[] = [
        'label'  => 'Rozszerzenie ZipArchive',
        'ok'     => class_exists('ZipArchive'),
        'detail' => class_exists('ZipArchive') ? 'dostępne' : 'BRAK — wymagane do rozpakowania paczki',
    ];

    $hasHttp = function_exists('curl_init') || ini_get('allow_url_fopen');
    $checks[] = [
        'label'  => 'Pobieranie HTTP (curl lub allow_url_fopen)',
        'ok'     => (bool)$hasHttp,
        'detail' => function_exists('curl_init') ? 'curl dostępny' : (ini_get('allow_url_fopen') ? 'allow_url_fopen=On' : 'BRAK obu metod'),
    ];

    $rootWritable = is_writable($root);
    $checks[] = [
        'label'  => 'Zapis w katalogu instalacji',
        'ok'     => $rootWritable,
        'detail' => $rootWritable ? $root : 'BRAK uprawnień zapisu w ' . $root,
    ];

    $dataDir = dirname(DB_PATH);
    $dataWritable = is_writable($dataDir);
    $checks[] = [
        'label'  => 'Zapis w katalogu data/ (kopie + pliki robocze)',
        'ok'     => $dataWritable,
        'detail' => $dataWritable ? $dataDir : 'BRAK uprawnień zapisu w ' . $dataDir,
    ];

    // Sprawdź zapis kilku kluczowych plików/katalogów, które będą nadpisywane
    $writableSample = true;
    foreach (['index.php', 'includes', 'admin', 'assets'] as $p) {
        $full = $root . '/' . $p;
        if (file_exists($full) && !is_writable($full)) {
            $writableSample = false;
            break;
        }
    }
    $checks[] = [
        'label'  => 'Zapis w plikach rdzenia (index.php, includes, admin, assets)',
        'ok'     => $writableSample,
        'detail' => $writableSample ? 'OK' : 'Część plików rdzenia jest tylko do odczytu',
    ];

    return $checks;
}

function updaterRequirementsOk(array $checks): bool {
    foreach ($checks as $c) {
        if (!$c['ok']) return false;
    }
    return true;
}

/** Rekurencyjne usuwanie katalogu. */
function updaterRrmdir(string $dir): void {
    if (!is_dir($dir)) {
        if (is_file($dir)) @unlink($dir);
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

/**
 * Tworzy kopię zapasową obecnego kodu (bez data/, uploads/, .git/).
 * Zwraca [ok, path|null, error].
 */
function updaterCreateBackup(): array {
    if (!class_exists('ZipArchive')) {
        return [false, null, 'ZipArchive niedostępne — nie można utworzyć kopii.'];
    }
    $root = updaterRootDir();
    $backupDir = updaterBackupDir();
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true)) {
        return [false, null, 'Nie można utworzyć katalogu kopii: ' . $backupDir];
    }

    $current = updaterCurrentVersion()['version'];
    $name = 'backup-v' . $current . '-' . date('Ymd-His') . '.zip';
    $path = $backupDir . '/' . $name;

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return [false, null, 'Nie można utworzyć pliku ZIP kopii.'];
    }

    $skip = ['data', 'uploads', '.git'];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $file) {
        $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
        if ($rel === '') continue;
        $top = explode('/', $rel)[0];
        if (in_array($top, $skip, true)) continue;
        if ($file->isDir()) {
            $zip->addEmptyDir($rel);
        } else {
            $zip->addFile($file->getPathname(), $rel);
        }
    }
    $zip->close();

    // Zachowaj tylko 5 ostatnich kopii
    updaterPruneBackups(5);

    return [true, $path, ''];
}

/** Usuwa najstarsze kopie, pozostawiając $keep najnowszych. */
function updaterPruneBackups(int $keep = 5): void {
    $dir = updaterBackupDir();
    if (!is_dir($dir)) return;
    $files = glob($dir . '/backup-*.zip') ?: [];
    if (count($files) <= $keep) return;
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a)); // najnowsze pierwsze
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }
}

/** Lista istniejących kopii zapasowych [name, path, size, mtime]. */
function updaterListBackups(): array {
    $dir = updaterBackupDir();
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/backup-*.zip') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return array_map(fn($f) => [
        'name'  => basename($f),
        'path'  => $f,
        'size'  => filesize($f),
        'mtime' => filemtime($f),
    ], $files);
}

/**
 * Rekurencyjnie kopiuje pliki ze źródła do celu, pomijając ścieżki chronione.
 * Zwraca [copied(int), skipped(int), errors(array)].
 */
function updaterCopyTree(string $src, string $dest): array {
    $copied = 0; $skipped = 0; $errors = [];
    $src = rtrim($src, '/');
    $dest = rtrim($dest, '/');

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $item) {
        $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($src))), '/');
        if ($rel === '') continue;

        if (updaterIsProtected($rel)) {
            $skipped++;
            continue;
        }

        $target = $dest . '/' . $rel;
        if ($item->isDir()) {
            if (!is_dir($target) && !@mkdir($target, 0775, true)) {
                $errors[] = 'Nie można utworzyć katalogu: ' . $rel;
            }
        } else {
            $dir = dirname($target);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            if (@copy($item->getPathname(), $target)) {
                $copied++;
            } else {
                $errors[] = 'Nie można zapisać pliku: ' . $rel;
            }
        }
    }
    return [$copied, $skipped, $errors];
}

/**
 * Pełna procedura aktualizacji: backup → pobierz ZIP → rozpakuj → skopiuj → sprzątanie.
 * Zwraca [ok(bool), log(array), error(string)].
 */
function updaterRunUpdate(): array {
    $log = [];
    $work = updaterWorkDir();

    // 0. Wymagania
    $reqs = updaterCheckRequirements();
    if (!updaterRequirementsOk($reqs)) {
        return [false, $log, 'Środowisko nie spełnia wymagań aktualizacji — sprawdź sekcję wymagań.'];
    }

    // 1. Kopia zapasowa
    [$bok, $bpath, $berr] = updaterCreateBackup();
    if (!$bok) {
        return [false, $log, 'Kopia zapasowa nie powiodła się: ' . $berr];
    }
    $log[] = 'Kopia zapasowa utworzona: ' . basename($bpath);

    // 2. Przygotuj katalog roboczy
    updaterRrmdir($work);
    if (!@mkdir($work, 0775, true)) {
        return [false, $log, 'Nie można utworzyć katalogu roboczego: ' . $work];
    }

    // 3. Pobierz ZIP
    $zipPath = $work . '/package.zip';
    [$ok, $body, $err] = updaterHttpGet(updaterZipUrl(), 180);
    if (!$ok) {
        updaterRrmdir($work);
        return [false, $log, 'Pobieranie paczki nie powiodło się: ' . $err];
    }
    if (@file_put_contents($zipPath, $body) === false) {
        updaterRrmdir($work);
        return [false, $log, 'Nie można zapisać pobranej paczki.'];
    }
    $log[] = 'Pobrano paczkę (' . round(strlen($body) / 1024) . ' KB).';
    unset($body);

    // 4. Rozpakuj
    $extractDir = $work . '/extracted';
    @mkdir($extractDir, 0775, true);
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        updaterRrmdir($work);
        return [false, $log, 'Nie można otworzyć pobranego archiwum ZIP.'];
    }
    if (!$zip->extractTo($extractDir)) {
        $zip->close();
        updaterRrmdir($work);
        return [false, $log, 'Rozpakowanie archiwum nie powiodło się.'];
    }
    $zip->close();
    $log[] = 'Archiwum rozpakowane.';

    // 5. Znajdź katalog źródłowy (GitHub pakuje do podfolderu {repo}-{branch})
    $subdirs = glob($extractDir . '/*', GLOB_ONLYDIR) ?: [];
    if (count($subdirs) !== 1) {
        updaterRrmdir($work);
        return [false, $log, 'Nieoczekiwana struktura archiwum — oczekiwano jednego katalogu głównego.'];
    }
    $sourceRoot = $subdirs[0];

    // Walidacja: paczka musi wyglądać jak ten CMS
    if (!is_file($sourceRoot . '/index.php') || !is_file($sourceRoot . '/version.json')) {
        updaterRrmdir($work);
        return [false, $log, 'Paczka nie zawiera oczekiwanych plików CMS (index.php / version.json).'];
    }

    // 6. Skopiuj pliki (z pominięciem chronionych)
    [$copied, $skipped, $errors] = updaterCopyTree($sourceRoot, updaterRootDir());
    $log[] = "Skopiowano plików: {$copied}, pominięto chronionych: {$skipped}.";
    if ($errors) {
        foreach (array_slice($errors, 0, 10) as $e) $log[] = 'Błąd: ' . $e;
        updaterRrmdir($work);
        return [false, $log, count($errors) . ' plików nie udało się zaktualizować. Przywróć kopię zapasową, jeśli strona działa nieprawidłowo.'];
    }

    // 7. Nowa wersja config.php — nie nadpisujemy, ale sygnalizujemy różnicę
    $newConfig = $sourceRoot . '/includes/config.php';
    $curConfig = updaterRootDir() . '/includes/config.php';
    if (is_file($newConfig) && is_file($curConfig)
        && md5_file($newConfig) !== md5_file($curConfig)) {
        @copy($newConfig, $curConfig . '.new');
        $log[] = 'Uwaga: nowa wersja zmienia includes/config.php. Zapisano wzorzec jako config.php.new — porównaj ręcznie.';
    }

    // 8. Wymuś migracje bazy (np. nowe kolumny/ustawienia) i odśwież cache
    try {
        db(); // initSchema() uruchamia addColumnIfMissing + INSERT OR IGNORE nowych ustawień
        $log[] = 'Migracje bazy danych wykonane.';
    } catch (Throwable $t) {
        $log[] = 'Ostrzeżenie: migracja bazy zgłosiła wyjątek: ' . $t->getMessage();
    }
    if (function_exists('clearAllCaches')) {
        clearAllCaches();
        $log[] = 'Cache wyczyszczony.';
    }

    // 9. Sprzątanie
    updaterRrmdir($work);
    $new = updaterCurrentVersion();
    $log[] = 'Aktualizacja zakończona. Aktualna wersja: v' . $new['version'] . '.';

    return [true, $log, ''];
}

/**
 * Przywraca instalację z wskazanej kopii zapasowej.
 * Zwraca [ok, log(array), error].
 */
function updaterRestoreBackup(string $backupName): array {
    $log = [];
    $dir = updaterBackupDir();
    $path = $dir . '/' . basename($backupName); // basename — ochrona przed traversal
    if (!is_file($path)) {
        return [false, $log, 'Wskazana kopia zapasowa nie istnieje.'];
    }
    if (!class_exists('ZipArchive')) {
        return [false, $log, 'ZipArchive niedostępne.'];
    }

    $work = updaterWorkDir() . '_restore';
    updaterRrmdir($work);
    @mkdir($work, 0775, true);

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        updaterRrmdir($work);
        return [false, $log, 'Nie można otworzyć kopii zapasowej.'];
    }
    if (!$zip->extractTo($work)) {
        $zip->close();
        updaterRrmdir($work);
        return [false, $log, 'Rozpakowanie kopii nie powiodło się.'];
    }
    $zip->close();

    [$copied, $skipped, $errors] = updaterCopyTree($work, updaterRootDir());
    $log[] = "Przywrócono plików: {$copied}, pominięto chronionych: {$skipped}.";
    updaterRrmdir($work);

    if ($errors) {
        return [false, $log, count($errors) . ' plików nie udało się przywrócić.'];
    }
    if (function_exists('clearAllCaches')) clearAllCaches();
    $log[] = 'Przywracanie zakończone.';
    return [true, $log, ''];
}
