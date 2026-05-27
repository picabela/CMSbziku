<?php
/**
 * System aktualizacji CMS-a.
 *
 * Pobiera wersję z pliku VERSION na GitHubie (raw.githubusercontent.com),
 * jeśli różni się od lokalnej → oferuje aktualizację przez pobranie
 * archiwum ZIP z brancha i podmianę plików.
 *
 * Whitelista plików chronionych (nie nadpisywane):
 *   - data/*        (Twoja baza SQLite, ustawienia)
 *   - uploads/*     (zdjęcia w artykułach, logo)
 *   - themes/{custom}/  (motywy NIE-wbudowane)
 *
 * Konfiguracja w settings:
 *   - update_github_repo:   "picabela/CMSbziku"
 *   - update_github_branch: "main"
 *
 * Wymaga: ZipArchive, cURL.
 */

require_once __DIR__ . '/functions.php';

/** Zwraca aktualną wersję z pliku VERSION (lub '0.0.0' jeśli brak). */
function getCurrentVersion(): string {
    $path = __DIR__ . '/../VERSION';
    if (!is_file($path)) return '0.0.0';
    $v = trim((string)@file_get_contents($path));
    return $v !== '' ? $v : '0.0.0';
}

/** Wbudowane motywy — zawsze nadpisywane przy update. Reszta to user-installed. */
function builtinThemes(): array {
    return ['classic','modern','bulletin','broadsheet','tribune','gazette'];
}

/** Semver compare. Zwraca -1, 0, 1. */
function compareVersions(string $a, string $b): int {
    $a = preg_replace('/^v/', '', trim($a));
    $b = preg_replace('/^v/', '', trim($b));
    return version_compare($a, $b);
}

/**
 * Sprawdza najnowszą wersję na GitHub przez raw.githubusercontent.com/VERSION.
 */
function checkLatestVersion(bool $force = false): array {
    $repo   = (string)setting('update_github_repo', 'picabela/CMSbziku');
    $branch = (string)setting('update_github_branch', 'main');
    $current = getCurrentVersion();

    $result = ['ok' => false, 'repo' => $repo, 'branch' => $branch, 'current_version' => $current, 'latest_version' => null, 'has_update' => false, 'changelog' => '', 'last_commit' => null, 'msg' => ''];

    if (!preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#i', $repo)) {
        $result['msg'] = 'Nieprawidłowa nazwa repo. Format: owner/name';
        return $result;
    }

    $versionUrl = "https://raw.githubusercontent.com/{$repo}/{$branch}/VERSION";
    [$body, $code, $err] = updaterHttpGet($versionUrl);
    if ($code === 404) { $result['msg'] = "Brak pliku VERSION w repo {$repo} na branchu {$branch}."; return $result; }
    if ($code !== 200 || $body === false) { $result['msg'] = "Nie udało się pobrać VERSION (HTTP {$code}). " . $err; return $result; }
    $latest = trim($body);
    if (!preg_match('/^v?\d+(\.\d+){0,3}(-[a-z0-9.]+)?$/i', $latest)) { $result['msg'] = "Plik VERSION ma nieprawidłowy format: " . substr($latest, 0, 50); return $result; }
    $result['latest_version'] = $latest;
    $result['has_update'] = compareVersions($latest, $current) > 0;
    $result['ok'] = true;

    $commitUrl = "https://api.github.com/repos/{$repo}/commits/{$branch}";
    [$cbody, $ccode] = updaterHttpGet($commitUrl, ['Accept: application/vnd.github+json']);
    if ($ccode === 200) {
        $cdata = json_decode($cbody, true);
        if (is_array($cdata)) {
            $result['last_commit'] = [
                'sha'     => substr((string)($cdata['sha'] ?? ''), 0, 7),
                'message' => $cdata['commit']['message'] ?? '',
                'author'  => $cdata['commit']['author']['name'] ?? '',
                'date'    => $cdata['commit']['author']['date'] ?? '',
                'url'     => $cdata['html_url'] ?? '',
            ];
        }
    }

    $clogUrl = "https://raw.githubusercontent.com/{$repo}/{$branch}/CHANGELOG.md";
    [$clBody, $clCode] = updaterHttpGet($clogUrl);
    if ($clCode === 200 && $clBody) $result['changelog'] = mb_substr($clBody, 0, 4000);

    setSetting('update_last_check', date('Y-m-d H:i:s'));
    setSetting('update_latest_version', $latest);
    setSetting('update_latest_data', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $result;
}

function performUpdate(): array {
    $log = []; $stats = ['files_updated' => 0, 'files_skipped' => 0, 'dirs_created' => 0];
    $logf = function(string $m) use (&$log) { $log[] = '[' . date('H:i:s') . '] ' . $m; };
    set_time_limit(300); ignore_user_abort(true);

    if (!class_exists('ZipArchive')) return ['ok' => false, 'log' => ['Brak rozszerzenia ZipArchive na hostingu.'], 'stats' => $stats];

    $repo   = (string)setting('update_github_repo', 'picabela/CMSbziku');
    $branch = (string)setting('update_github_branch', 'main');
    $current = getCurrentVersion();
    $logf("Start aktualizacji. Aktualna: v{$current}, repo: {$repo}@{$branch}");

    $check = checkLatestVersion(true);
    if (!$check['ok']) return ['ok' => false, 'log' => array_merge($log, ['Sprawdzenie wersji nieudane: ' . $check['msg']]), 'stats' => $stats];
    $latest = $check['latest_version'];
    $logf("Najnowsza dostępna: v{$latest}");
    if (compareVersions($latest, $current) <= 0) return ['ok' => false, 'log' => array_merge($log, ['Nic do aktualizacji — masz już najnowszą wersję.']), 'stats' => $stats];

    $zipUrl = "https://github.com/{$repo}/archive/refs/heads/" . rawurlencode($branch) . ".zip";
    $logf("Pobieram archiwum: {$zipUrl}");
    $tmpZip = sys_get_temp_dir() . '/cms-update-' . bin2hex(random_bytes(6)) . '.zip';
    [$ok, $size, $err] = updaterDownloadFile($zipUrl, $tmpZip);
    if (!$ok) return ['ok' => false, 'log' => array_merge($log, ['Pobieranie nieudane: ' . $err]), 'stats' => $stats];
    $logf('Pobrano: ' . number_format($size / 1024, 1, ',', ' ') . ' KB');

    $stagingBase = __DIR__ . '/../data/update-staging-' . bin2hex(random_bytes(4));
    @mkdir($stagingBase, 0775, true);
    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) { @unlink($tmpZip); return ['ok' => false, 'log' => array_merge($log, ['Nie można otworzyć ZIP.']), 'stats' => $stats]; }
    $zip->extractTo($stagingBase); $zip->close(); @unlink($tmpZip);

    $sourceDir = updaterFindExtractedRoot($stagingBase);
    if (!$sourceDir) { updaterRrmdir($stagingBase); return ['ok' => false, 'log' => array_merge($log, ['Nieprawidłowa struktura archiwum.']), 'stats' => $stats]; }
    $logf("Rozpakowano do: " . basename($sourceDir));

    if (!is_file($sourceDir . '/VERSION') || !is_file($sourceDir . '/includes/db.php')) {
        updaterRrmdir($stagingBase);
        return ['ok' => false, 'log' => array_merge($log, ['Archiwum uszkodzone — brak VERSION lub includes/db.php.']), 'stats' => $stats];
    }
    $stagingVer = trim((string)file_get_contents($sourceDir . '/VERSION'));
    if (compareVersions($stagingVer, $current) <= 0) {
        updaterRrmdir($stagingBase);
        return ['ok' => false, 'log' => array_merge($log, ["Archiwum zawiera v{$stagingVer} — nie nowsza niż lokalna v{$current}."]), 'stats' => $stats];
    }
    $logf("Walidacja OK. Wersja w archiwum: v{$stagingVer}");

    $targetBase = realpath(__DIR__ . '/..');
    $builtinThemes = builtinThemes();
    $skipPatterns = ['@^data/@', '@^uploads/@', '@^\.git/@', '@^\.github/@'];

    $logf('Kopiuję pliki...');
    updaterCopyTree($sourceDir, $targetBase, '', $skipPatterns, $builtinThemes, $stats, $logf);

    @file_put_contents($targetBase . '/VERSION', $stagingVer . "\n");
    $logf("Plik VERSION zaktualizowany do v{$stagingVer}");

    if (function_exists('clearAllCaches')) clearAllCaches();
    $logf('Cache wyczyszczony.');

    try { $pdo = db(); initSchema($pdo); $logf('Migracje DB: OK'); } catch (Throwable $e) { $logf('UWAGA migracje DB: ' . $e->getMessage()); }

    updaterRrmdir($stagingBase);
    $logf('Staging usunięty.');

    setSetting('update_last_check', date('Y-m-d H:i:s'));
    setSetting('update_latest_version', $stagingVer);

    $logf("✓ Aktualizacja zakończona. Nowa wersja: v{$stagingVer}");
    return ['ok' => true, 'log' => $log, 'stats' => $stats, 'new_version' => $stagingVer];
}

function updaterHttpGet(string $url, array $extraHeaders = []): array {
    $ch = curl_init($url);
    $headers = array_merge(['User-Agent: bziku-cms-updater/1.0'], $extraHeaders);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return [$body, $code, $err];
}

function updaterDownloadFile(string $url, string $destPath): array {
    $fp = @fopen($destPath, 'w');
    if (!$fp) return [false, 0, 'Nie można otworzyć pliku zapisu.'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => 'bziku-cms-updater/1.0']);
    $ok = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch); fclose($fp);
    if (!$ok || $code !== 200) { @unlink($destPath); return [false, 0, "HTTP {$code}: {$err}"]; }
    return [true, filesize($destPath), ''];
}

function updaterFindExtractedRoot(string $staging): ?string {
    $items = @scandir($staging);
    if (!$items) return null;
    foreach ($items as $i) {
        if ($i === '.' || $i === '..') continue;
        $full = $staging . '/' . $i;
        if (is_dir($full) && (is_file($full . '/VERSION') || is_file($full . '/includes/db.php'))) return $full;
    }
    if (is_file($staging . '/VERSION') || is_file($staging . '/includes/db.php')) return $staging;
    return null;
}

function updaterCopyTree(string $src, string $dst, string $relativePath, array $skipPatterns, array $builtinThemes, array &$stats, callable $logf): void {
    $items = @scandir($src);
    if (!$items) return;
    foreach ($items as $i) {
        if ($i === '.' || $i === '..') continue;
        $srcPath = $src . '/' . $i; $dstPath = $dst . '/' . $i;
        $relPath = ltrim($relativePath . '/' . $i, '/');
        foreach ($skipPatterns as $p) { if (preg_match($p, $relPath)) { $stats['files_skipped']++; continue 2; } }
        if (preg_match('@^themes/([^/]+)(/|$)@', $relPath, $m)) {
            if (!in_array($m[1], $builtinThemes, true)) { $stats['files_skipped']++; continue; }
        }
        if (is_dir($srcPath)) {
            if (!is_dir($dstPath)) { @mkdir($dstPath, 0775, true); $stats['dirs_created']++; }
            updaterCopyTree($srcPath, $dstPath, $relPath, $skipPatterns, $builtinThemes, $stats, $logf);
        } else {
            if (@copy($srcPath, $dstPath)) $stats['files_updated']++;
            else $logf("⚠ Nie udało się skopiować: {$relPath}");
        }
    }
}

function updaterRrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (@scandir($dir) ?: [] as $i) {
        if ($i === '.' || $i === '..') continue;
        $p = $dir . '/' . $i;
        if (is_dir($p)) updaterRrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

function getCachedUpdateInfo(): ?array {
    $json = setting('update_latest_data', '');
    if (!$json) return null;
    $d = json_decode($json, true);
    return is_array($d) ? $d : null;
}
