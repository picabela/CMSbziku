<?php
/**
 * Silnik szybkiego indeksowania URL: Google Indexing API + IndexNow.
 * Wymaga: PHP z openssl (do JWT) i allow_url_fopen lub cURL.
 */

/* ===== Helpers ustawień ===== */

function indexingGoogleEnabled(): bool {
    return setting('indexing_google_enabled', '0') === '1';
}

function indexingGoogleKeyPath(): string {
    return __DIR__ . '/../data/google-indexing-key.json';
}

function indexingGoogleKeyData(): ?array {
    $path = indexingGoogleKeyPath();
    if (!is_file($path)) return null;
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) && !empty($data['private_key']) ? $data : null;
}

function indexingIndexNowEnabled(): bool {
    return setting('indexing_indexnow_enabled', '0') === '1';
}

function indexingIndexNowKey(): string {
    return trim((string)setting('indexing_indexnow_key', ''));
}

function indexingAutoEnabled(): bool {
    return setting('indexing_auto_on_publish', '0') === '1';
}

/* ===== Google Indexing API (OAuth2 JWT) ===== */

function _indexingBase64Url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function _indexingGoogleJwt(array $key, string $scope = 'https://www.googleapis.com/auth/indexing'): ?string {
    if (empty($key['client_email']) || empty($key['private_key'])) return null;
    $header  = _indexingBase64Url((string)json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $now     = time();
    $payload = _indexingBase64Url((string)json_encode([
        'iss'   => $key['client_email'],
        'scope' => $scope,
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $now + 3600,
        'iat'   => $now,
    ]));
    $sig = '';
    if (!openssl_sign("$header.$payload", $sig, $key['private_key'], OPENSSL_ALGO_SHA256)) return null;
    return "$header.$payload." . _indexingBase64Url($sig);
}

function _indexingHttpPost(string $url, string $body, array $headers, int $timeout = 12): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $resp = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['code' => $code, 'body' => $resp ?: $err];
    }
    // fallback: allow_url_fopen
    $flatHeaders = '';
    foreach ($headers as $h) $flatHeaders .= $h . "\r\n";
    $ctx  = stream_context_create(['http' => [
        'method'         => 'POST',
        'header'         => $flatHeaders,
        'content'        => $body,
        'timeout'        => $timeout,
        'ignore_errors'  => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header)) {
        preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
        $code = (int)($m[1] ?? 0);
    }
    return ['code' => $code, 'body' => (string)$resp];
}

function indexingGoogleGetToken(string $scope = 'https://www.googleapis.com/auth/indexing'): ?string {
    static $cache = [];
    if (isset($cache[$scope]) && $cache[$scope]['exp'] > time()) {
        return $cache[$scope]['token'];
    }
    $key = indexingGoogleKeyData();
    if (!$key) return null;
    $jwt = _indexingGoogleJwt($key, $scope);
    if (!$jwt) return null;
    $r = _indexingHttpPost(
        'https://oauth2.googleapis.com/token',
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
        ['Content-Type: application/x-www-form-urlencoded']
    );
    $data = json_decode($r['body'], true);
    $token = $data['access_token'] ?? null;
    if ($token) $cache[$scope] = ['token' => $token, 'exp' => time() + 3000];
    return $token;
}

function indexingGoogleSubmitUrl(string $url, string $type = 'URL_UPDATED'): array {
    if (!function_exists('openssl_sign')) {
        return ['ok' => false, 'msg' => 'Wymagane rozszerzenie PHP openssl.'];
    }
    $token = indexingGoogleGetToken();
    if (!$token) {
        return ['ok' => false, 'msg' => 'Nie udało się pobrać tokenu Google (sprawdź klucz JSON).'];
    }
    $r = _indexingHttpPost(
        'https://indexing.googleapis.com/v3/urlNotifications:publish',
        (string)json_encode(['url' => $url, 'type' => $type]),
        ['Content-Type: application/json', "Authorization: Bearer $token"]
    );
    $ok  = $r['code'] >= 200 && $r['code'] < 300;
    $body = mb_substr($r['body'], 0, 300);
    return ['ok' => $ok, 'msg' => "HTTP {$r['code']}: $body"];
}

/* ===== IndexNow ===== */

function indexingIndexNowSubmitUrl(string $url): array {
    $key = indexingIndexNowKey();
    if ($key === '') return ['ok' => false, 'msg' => 'Brak klucza IndexNow.'];
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    $r = _indexingHttpPost(
        'https://api.indexnow.org/IndexNow',
        (string)json_encode([
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => BASE_URL . '/' . $key . '.txt',
            'urlList'     => [$url],
        ]),
        ['Content-Type: application/json; charset=utf-8', 'Host: api.indexnow.org']
    );
    $ok = in_array($r['code'], [200, 202], true);
    $body = mb_substr($r['body'], 0, 300);
    return ['ok' => $ok, 'msg' => "HTTP {$r['code']}: $body"];
}

/**
 * Zapisuje klucz IndexNow do pliku TXT w korzeniu serwisu.
 * IndexNow wymaga, by plik {key}.txt był dostępny pod BASE_URL/{key}.txt.
 */
function indexingWriteIndexNowKeyFile(string $key): bool {
    if ($key === '') return false;
    $safe = preg_replace('/[^a-zA-Z0-9\-]/', '', $key);
    if ($safe === '') return false;
    return (bool)@file_put_contents(__DIR__ . '/../' . $safe . '.txt', $safe);
}

function indexingDeleteIndexNowKeyFile(string $key): void {
    if ($key === '') return;
    $safe = preg_replace('/[^a-zA-Z0-9\-]/', '', $key);
    if ($safe !== '') @unlink(__DIR__ . '/../' . $safe . '.txt');
}

/* ===== Wysyłanie URL (oba kanały) ===== */

/**
 * Zgłasza URL do wszystkich włączonych kanałów i zapisuje w logu.
 * Wywołuj po opublikowaniu artykułu/strony.
 */
/**
 * Normalizuje URL przed zgłoszeniem/zalogowaniem do kanonicznej bazy strony.
 * Naprawia adresy zbudowane przy błędnym BASE_URL z crona (localhost lub
 * doklejony przedrostek /cron), żeby pasowały do realnych URL-i artykułów.
 */
function indexingNormalizeUrl(string $url): string {
    $base = trim((string)setting('site_url', ''));
    if ($base === '') return $url;
    $base = rtrim($base, '/');
    $parts = @parse_url($url);
    if (!is_array($parts)) return $url;
    $path = $parts['path'] ?? '/';
    // Usuń błędny przedrostek /cron doklejany przez SCRIPT_NAME w /cron/run.php
    $path = preg_replace('#^/cron(?=/|$)#', '', $path);
    if ($path === '' || $path[0] !== '/') $path = '/' . $path;
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return $base . $path . $query;
}

function indexingSubmitUrl(string $url): array {
    $url = indexingNormalizeUrl($url);
    $results = [];
    if (indexingGoogleEnabled()) {
        $r = indexingGoogleSubmitUrl($url);
        $results['google'] = $r;
        _indexingLog($url, 'Google', $r['ok'], $r['msg']);
    }
    if (indexingIndexNowEnabled()) {
        $r = indexingIndexNowSubmitUrl($url);
        $results['indexnow'] = $r;
        _indexingLog($url, 'IndexNow', $r['ok'], $r['msg']);
    }
    return $results;
}

/* ===== Log ===== */

function _indexingLog(string $url, string $method, bool $ok, string $response): void {
    try {
        db()->prepare('INSERT INTO indexing_log (url, method, ok, response, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)')
            ->execute([$url, $method, $ok ? 1 : 0, mb_substr($response, 0, 500)]);
    } catch (\Throwable $e) {
        // nie przerywaj normalnego flow przy błędzie logu
    }
}

function indexingGetLog(int $limit = 200): array {
    return db()->query("SELECT * FROM indexing_log ORDER BY id DESC LIMIT $limit")->fetchAll();
}

function indexingClearLog(): void {
    db()->exec('DELETE FROM indexing_log');
}

/**
 * Zwraca unikalne URL-e, których OSTATNI wpis w logu ma ok=0 (błąd).
 * Używane do ponownego zgłaszania po przekroczeniu limitu dziennego itp.
 */
function indexingFailedUrls(): array {
    try {
        return db()->query(
            "SELECT url, method, response, created_at
             FROM indexing_log AS l
             WHERE id = (
                 SELECT id FROM indexing_log WHERE url = l.url ORDER BY id DESC LIMIT 1
             )
             AND ok = 0
             ORDER BY created_at DESC"
        )->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
}

/** Czy włączony jest jakikolwiek kanał indeksowania. */
function indexingAnyEnabled(): bool {
    return indexingGoogleEnabled() || indexingIndexNowEnabled();
}

/** Mapa url => liczba zgłoszeń (wszystkie kanały razem). Do oznaczeń na liście artykułów. */
function indexingSubmissionCounts(): array {
    $map = [];
    try {
        foreach (db()->query('SELECT url, COUNT(*) AS c FROM indexing_log GROUP BY url')->fetchAll() as $r) {
            $map[$r['url']] = (int)$r['c'];
        }
    } catch (\Throwable $e) {
        // brak tabeli/log — zwróć pusto
    }
    return $map;
}

/* ============================================================
 *  WebSub / PubSubHubbub — push przez HUB (Priorytet 1)
 * ============================================================
 *
 * Idea: przy publikacji pingujemy hub (POST hub.mode=publish&hub.url=<FEED>),
 * a hub powiadamia subskrybentów (m.in. Google) „feed się zmienił, pobierz go".
 * Kluczowe: pingujemy URL FEEDU (nie pojedynczego artykułu), a nowy artykuł
 * musi już być w feedzie w momencie pinga. Feed (feed.php) jest dynamiczny —
 * czyta bazę na żywo — więc kolejność publikacja → ping jest zawsze poprawna.
 */

function websubEnabled(): bool {
    return setting('websub_enabled', '0') === '1';
}

function websubHubUrl(): string {
    $u = trim((string)setting('websub_hub_url', ''));
    return $u !== '' ? $u : 'https://pubsubhubbub.appspot.com/';
}

/** URL feedu zgłaszanego do huba (puste ustawienie = auto z kanonicznej bazy). */
function websubFeedUrl(): string {
    $u = trim((string)setting('websub_feed_url', ''));
    if ($u !== '') return $u;
    return rtrim((string)(function_exists('siteBaseUrl') ? siteBaseUrl() : BASE_URL), '/') . '/feed.php';
}

/**
 * Pinguje hub, że feed się zmienił. Zwraca ['ok'=>bool,'msg'=>string].
 * Sukces huba to zwykle HTTP 204 No Content (akceptujemy całe 2xx).
 */
function websubPublish(?string $feedUrl = null): array {
    $hub  = websubHubUrl();
    $feed = $feedUrl ?: websubFeedUrl();
    if ($feed === '' || !filter_var($feed, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'msg' => 'Nieprawidłowy URL feedu.'];
    }
    $r = _indexingHttpPost(
        $hub,
        http_build_query(['hub.mode' => 'publish', 'hub.url' => $feed]),
        ['Content-Type: application/x-www-form-urlencoded']
    );
    $ok   = $r['code'] >= 200 && $r['code'] < 300;
    $body = mb_substr(trim((string)$r['body']), 0, 250);
    return ['ok' => $ok, 'msg' => "HTTP {$r['code']}" . ($body !== '' ? ": $body" : ''), 'feed' => $feed];
}

/**
 * Centralny hook publikacji: zgłoś URL do kanałów instant-indexing (Google/IndexNow)
 * i — jeśli włączone — pingnij hub WebSub. Wołać po opublikowaniu artykułu/strony,
 * gdy włączono indexing_auto_on_publish.
 */
function indexingOnPublish(string $url): array {
    $results = indexingSubmitUrl($url);
    if (websubEnabled()) {
        $w = websubPublish();
        $results['websub'] = $w;
        _indexingLog($w['feed'] ?? websubFeedUrl(), 'WebSub', $w['ok'], $w['msg']);
    }
    // Zarejestruj URL w monitoringu indeksacji (jeśli włączony), by liczyć time-to-index.
    if (gscInspectionEnabled()) {
        indexStatusTrack(indexingNormalizeUrl($url));
    }
    return $results;
}

/* ============================================================
 *  Monitoring indeksacji — Google Search Console URL Inspection API
 *  (Priorytet 2). Osobna pula limitów: 2000/dobę i 600/min na property.
 * ============================================================ */

function gscInspectionEnabled(): bool {
    return setting('gsc_inspection_enabled', '0') === '1';
}

function gscSiteUrl(): string {
    return trim((string)setting('gsc_site_url', ''));
}

function gscMonitorAutoEnabled(): bool {
    return setting('gsc_monitor_auto', '0') === '1';
}

/** Token z zakresem tylko-do-odczytu Search Console (osobny scope od Indexing API). */
function gscGetToken(): ?string {
    return indexingGoogleGetToken('https://www.googleapis.com/auth/webmasters.readonly');
}

/* ----- Pula limitu (miękka, liczona po naszej stronie, reset dzienny) ----- */

function _gscQuotaRollover(): void {
    $today = date('Y-m-d');
    if (setting('gsc_quota_day', '') !== $today) {
        setSetting('gsc_quota_day', $today);
        setSetting('gsc_quota_used', '0');
    }
}

function gscQuotaUsed(): int {
    _gscQuotaRollover();
    return (int)setting('gsc_quota_used', '0');
}

function gscQuotaLimit(): int {
    return max(1, (int)setting('gsc_daily_quota', '1800'));
}

function gscQuotaRemaining(): int {
    return max(0, gscQuotaLimit() - gscQuotaUsed());
}

function _gscQuotaConsume(int $n = 1): void {
    _gscQuotaRollover();
    setSetting('gsc_quota_used', (string)(gscQuotaUsed() + $n));
}

/**
 * Odpytuje URL Inspection API o stan jednego URL.
 * Zwraca znormalizowaną tablicę pól + 'ok' i 'raw_code'.
 */
function gscInspectUrl(string $inspectionUrl, ?string $siteUrl = null): array {
    if (!function_exists('openssl_sign')) {
        return ['ok' => false, 'error' => 'Wymagane rozszerzenie PHP openssl.'];
    }
    $siteUrl = $siteUrl ?: gscSiteUrl();
    if ($siteUrl === '') {
        return ['ok' => false, 'error' => 'Brak skonfigurowanej property (gsc_site_url).'];
    }
    $token = gscGetToken();
    if (!$token) {
        return ['ok' => false, 'error' => 'Nie udało się pobrać tokenu GSC (sprawdź klucz JSON i scope).'];
    }
    $r = _indexingHttpPost(
        'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
        (string)json_encode([
            'inspectionUrl' => $inspectionUrl,
            'siteUrl'       => $siteUrl,
            'languageCode'  => SITE_LANG,
        ]),
        ['Content-Type: application/json', "Authorization: Bearer $token"]
    );
    _gscQuotaConsume(1);

    $code = (int)$r['code'];
    $data = json_decode((string)$r['body'], true);

    if ($code < 200 || $code >= 300 || !is_array($data)) {
        $msg = is_array($data) && isset($data['error']['message'])
            ? $data['error']['message']
            : mb_substr((string)$r['body'], 0, 200);
        return ['ok' => false, 'raw_code' => $code, 'error' => "HTTP $code: $msg"];
    }

    $idx = $data['inspectionResult']['indexStatusResult'] ?? [];
    return [
        'ok'               => true,
        'raw_code'         => $code,
        'verdict'          => $idx['verdict']            ?? null,
        'coverage_state'   => $idx['coverageState']      ?? null,
        'robots_state'     => $idx['robotsTxtState']     ?? null,
        'indexing_state'   => $idx['indexingState']      ?? null,
        'page_fetch_state' => $idx['pageFetchState']     ?? null,
        'last_crawl_time'  => $idx['lastCrawlTime']      ?? null,
        'google_canonical' => $idx['googleCanonical']    ?? null,
    ];
}

/* ----- Przechowywanie stanu (tabela index_status) ----- */

/** Dodaje URL do monitoringu (jeśli jeszcze go nie ma). Nie odpytuje API. */
function indexStatusTrack(string $url, ?int $postId = null, ?string $publishedAt = null): void {
    try {
        if ($postId === null || $publishedAt === null) {
            $slug = ltrim((string)parse_url($url, PHP_URL_PATH), '/');
            $row = db()->prepare('SELECT id, published_at FROM posts WHERE slug = ? LIMIT 1');
            $row->execute([$slug]);
            if ($p = $row->fetch()) {
                $postId      = $postId      ?? (int)$p['id'];
                $publishedAt = $publishedAt ?? $p['published_at'];
            }
        }
        db()->prepare('INSERT OR IGNORE INTO index_status (url, post_id, published_at) VALUES (?, ?, ?)')
            ->execute([$url, $postId, $publishedAt]);
    } catch (\Throwable $e) {
        // nie przerywaj flow publikacji
    }
}

/** Zapisuje wynik inspekcji do index_status. */
function indexStatusSave(string $url, array $res): void {
    $now = date('Y-m-d H:i:s');
    $pdo = db();
    // Upewnij się, że wiersz istnieje (wraz z post_id/published_at jeśli dostępne).
    indexStatusTrack($url);
    $cur = $pdo->prepare('SELECT indexed_at FROM index_status WHERE url = ?');
    $cur->execute([$url]);
    $existingIndexedAt = $cur->fetchColumn();

    if (!empty($res['ok'])) {
        $isPass = ($res['verdict'] ?? '') === 'PASS';
        // indexed_at zapisujemy raz — przy pierwszym PASS (do liczenia time-to-index).
        $indexedAt = $existingIndexedAt ?: ($isPass ? $now : null);
        $pdo->prepare("
            UPDATE index_status SET
                verdict = ?, coverage_state = ?, robots_state = ?, indexing_state = ?,
                page_fetch_state = ?, last_crawl_time = ?, indexed_at = ?,
                checks_count = checks_count + 1, last_checked_at = ?, last_error = NULL, updated_at = ?
            WHERE url = ?
        ")->execute([
            $res['verdict'] ?? null, $res['coverage_state'] ?? null, $res['robots_state'] ?? null,
            $res['indexing_state'] ?? null, $res['page_fetch_state'] ?? null, $res['last_crawl_time'] ?? null,
            $indexedAt, $now, $now, $url,
        ]);
    } else {
        $pdo->prepare("
            UPDATE index_status SET
                checks_count = checks_count + 1, last_checked_at = ?, last_error = ?, updated_at = ?
            WHERE url = ?
        ")->execute([$now, $res['error'] ?? 'błąd', $now, $url]);
    }
}

/** Inspekcja pojedynczego URL + zapis stanu. Zwraca wynik gscInspectUrl(). */
function indexStatusCheckUrl(string $url): array {
    indexStatusTrack($url);
    $res = gscInspectUrl($url);
    indexStatusSave($url, $res);
    return $res;
}

/**
 * Zwraca URL-e wymagające sprawdzenia: zarejestrowane, jeszcze nie PASS,
 * po upływie opóźnienia od publikacji i odstępu od ostatniego checku.
 */
function indexStatusDueForCheck(int $limit): array {
    $delayMin   = max(0, (int)setting('gsc_first_check_delay_min', '120'));
    $recheckHrs = max(1, (int)setting('gsc_recheck_hours', '12'));
    try {
        $stmt = db()->prepare("
            SELECT url FROM index_status
            WHERE (verdict IS NULL OR verdict != 'PASS')
              AND (published_at IS NULL OR published_at <= datetime('now', ?))
              AND (last_checked_at IS NULL OR last_checked_at <= datetime('now', ?))
            ORDER BY (last_checked_at IS NULL) DESC, COALESCE(published_at, first_seen_at) DESC
            LIMIT $limit
        ");
        $stmt->execute(["-{$delayMin} minutes", "-{$recheckHrs} hours"]);
        return array_column($stmt->fetchAll(), 'url');
    } catch (\Throwable $e) {
        return [];
    }
}

/* ----- Pętla naprawcza: ponawianie pushu dla niezaindeksowanych URL-i ----- */

function gscResubmitEnabled(): bool {
    return setting('gsc_resubmit_enabled', '1') === '1';
}

/**
 * Zwraca URL-e kwalifikujące się do ponownego pushu.
 * Tryb automatyczny: niezaindeksowane (verdict != PASS), starsze niż próg od publikacji,
 * poniżej limitu prób i po odstępie od ostatniego ponowienia.
 * Tryb ręczny ($ignoreThrottle=true): wszystkie aktualnie niezaindeksowane, bez progów i limitu.
 */
function indexStatusDueForResubmit(int $limit, bool $ignoreThrottle = false): array {
    try {
        if ($ignoreThrottle) {
            $stmt = db()->prepare("
                SELECT url FROM index_status
                WHERE (verdict IS NULL OR verdict != 'PASS')
                ORDER BY COALESCE(published_at, first_seen_at) DESC
                LIMIT $limit
            ");
            $stmt->execute();
        } else {
            $afterH    = max(0, (int)setting('gsc_resubmit_after_hours', '24'));
            $intervalH = max(1, (int)setting('gsc_resubmit_interval_hours', '48'));
            $maxTries  = max(1, (int)setting('gsc_resubmit_max', '2'));
            $stmt = db()->prepare("
                SELECT url FROM index_status
                WHERE (verdict IS NULL OR verdict != 'PASS')
                  AND resubmit_count < ?
                  AND (published_at IS NULL OR published_at <= datetime('now', ?))
                  AND (last_resubmit_at IS NULL OR last_resubmit_at <= datetime('now', ?))
                ORDER BY (last_resubmit_at IS NULL) DESC, COALESCE(published_at, first_seen_at) ASC
                LIMIT $limit
            ");
            $stmt->execute([$maxTries, "-{$afterH} hours", "-{$intervalH} hours"]);
        }
        return array_column($stmt->fetchAll(), 'url');
    } catch (\Throwable $e) {
        return [];
    }
}

/** Ponawia push pojedynczego URL przez aktywne kanały URL-owe (Google/IndexNow). Aktualizuje liczniki. */
function indexStatusResubmit(string $url): array {
    $res = indexingSubmitUrl($url);
    $now = date('Y-m-d H:i:s');
    try {
        db()->prepare("UPDATE index_status SET resubmit_count = resubmit_count + 1, last_resubmit_at = ?, updated_at = ? WHERE url = ?")
            ->execute([$now, $now, $url]);
    } catch (\Throwable $e) {
        // nie przerywaj tury
    }
    return $res;
}

/**
 * Pętla naprawcza wpinana w cron oraz wyzwalana ręcznie z panelu.
 * Ponawia push (Google/IndexNow) dla URL-i, które po przekroczeniu progu czasu
 * wciąż nie są zaindeksowane. WebSub pinguje feed, więc nie nadaje się do ponawiania
 * pojedynczego starego URL — pętla naprawcza używa kanałów URL-owych.
 *
 * @param bool $force          pomiń wymóg włączonego auto-resubmit (przycisk ręczny)
 * @param bool $ignoreThrottle ponów wszystkie niezaindeksowane teraz, bez progów/limitu (przycisk ręczny)
 */
function gscResubmitDue(bool $force = false, int $limit = 50, bool $ignoreThrottle = false): array {
    if (!$force && !gscResubmitEnabled()) {
        return ['ran' => false, 'reason' => 'disabled'];
    }
    if (!indexingAnyEnabled()) {
        return ['ran' => false, 'reason' => 'no_channel'];
    }
    $urls = indexStatusDueForResubmit($limit, $ignoreThrottle);
    if (empty($urls)) {
        return ['ran' => true, 'resubmitted' => 0, 'ok' => 0, 'errors' => 0];
    }
    $ok = $err = 0;
    foreach ($urls as $url) {
        $res = indexStatusResubmit($url);
        foreach ($res as $r) { !empty($r['ok']) ? $ok++ : $err++; }
    }
    return ['ran' => true, 'resubmitted' => count($urls), 'ok' => $ok, 'errors' => $err];
}

/**
 * Tura monitoringu wpinana w cron. Throttlowana (gsc_check_interval_minutes),
 * batch (gsc_batch_per_run), z poszanowaniem dziennej puli limitu.
 * Najpierw rejestruje świeże opublikowane artykuły do monitoringu.
 */
function gscScheduledMonitor(bool $force = false): array {
    if (!gscInspectionEnabled() || !gscMonitorAutoEnabled()) {
        return ['checked' => false, 'reason' => 'disabled'];
    }
    if (gscSiteUrl() === '') {
        return ['checked' => false, 'reason' => 'no_property'];
    }
    if (!$force) {
        $interval = max(5, (int)setting('gsc_check_interval_minutes', '180'));
        $last = (int)setting('gsc_check_last_ts', '0');
        if ($last > 0 && (time() - $last) < $interval * 60) {
            return ['checked' => false, 'reason' => 'throttled'];
        }
    }
    setSetting('gsc_check_last_ts', (string)time());

    // Dorejestruj opublikowane artykuły z ostatnich 30 dni, których nie ma w monitoringu.
    try {
        $rows = db()->query("
            SELECT slug, id, published_at FROM posts
            WHERE status = 'published' AND published_at >= datetime('now', '-30 days')
        ")->fetchAll();
        foreach ($rows as $p) {
            indexStatusTrack(absoluteSiteUrl($p['slug']), (int)$p['id'], $p['published_at']);
        }
    } catch (\Throwable $e) {
        // ignoruj
    }

    $remaining = gscQuotaRemaining();
    if ($remaining <= 0) {
        return ['checked' => true, 'reason' => 'quota_exhausted', 'checked_count' => 0];
    }
    $batch = min(max(1, (int)setting('gsc_batch_per_run', '20')), $remaining);
    $urls  = indexStatusDueForCheck($batch);

    $checked = $pass = $fail = 0;
    foreach ($urls as $url) {
        if (gscQuotaRemaining() <= 0) break;
        $res = indexStatusCheckUrl($url);
        $checked++;
        if (!empty($res['ok']) && ($res['verdict'] ?? '') === 'PASS') $pass++;
        elseif (empty($res['ok'])) $fail++;
    }

    // Pętla naprawcza: po sprawdzeniu statusów ponów push dla URL-i wciąż niezaindeksowanych.
    $resub = gscResubmitDue(false, 50);

    return ['checked' => true, 'checked_count' => $checked, 'pass' => $pass, 'errors' => $fail,
            'resubmitted' => $resub['resubmitted'] ?? 0,
            'quota_used' => gscQuotaUsed(), 'quota_limit' => gscQuotaLimit()];
}

/* ----- Odczyt dla panelu ----- */

function indexStatusList(int $limit = 300): array {
    try {
        return db()->query("
            SELECT s.*, p.title AS post_title, p.slug AS post_slug
            FROM index_status s
            LEFT JOIN posts p ON p.id = s.post_id
            ORDER BY COALESCE(s.published_at, s.first_seen_at) DESC
            LIMIT $limit
        ")->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
}

function indexStatusSummary(): array {
    $out = ['total' => 0, 'pass' => 0, 'pending' => 0, 'fail' => 0, 'avg_ttiminutes' => null];
    try {
        $rows = db()->query('SELECT verdict, published_at, indexed_at FROM index_status')->fetchAll();
        $ttiSum = 0; $ttiN = 0;
        foreach ($rows as $r) {
            $out['total']++;
            if (($r['verdict'] ?? '') === 'PASS') {
                $out['pass']++;
                if (!empty($r['published_at']) && !empty($r['indexed_at'])) {
                    $d = strtotime($r['indexed_at']) - strtotime($r['published_at']);
                    if ($d > 0) { $ttiSum += $d; $ttiN++; }
                }
            } elseif (($r['verdict'] ?? '') === 'FAIL' || ($r['verdict'] ?? '') === 'PARTIAL') {
                $out['fail']++;
            } else {
                $out['pending']++;
            }
        }
        if ($ttiN > 0) $out['avg_ttiminutes'] = (int)round($ttiSum / $ttiN / 60);
    } catch (\Throwable $e) {
        // pusto
    }
    return $out;
}

function indexStatusClear(): void {
    try { db()->exec('DELETE FROM index_status'); } catch (\Throwable $e) {}
}

/** Formatuje liczbę minut na czytelny czas: „45 min", „6 h 12 min", „2 dni 3 h". */
function _fmtTti(int $minutes): string {
    if ($minutes < 60) return $minutes . ' min';
    if ($minutes < 1440) {
        $h = intdiv($minutes, 60); $m = $minutes % 60;
        return $h . ' h' . ($m ? " $m min" : '');
    }
    $d = intdiv($minutes, 1440); $h = intdiv($minutes % 1440, 60);
    return $d . ' ' . ($d === 1 ? 'dzień' : 'dni') . ($h ? " $h h" : '');
}
