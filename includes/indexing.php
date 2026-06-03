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

function _indexingGoogleJwt(array $key): ?string {
    if (empty($key['client_email']) || empty($key['private_key'])) return null;
    $header  = _indexingBase64Url((string)json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $now     = time();
    $payload = _indexingBase64Url((string)json_encode([
        'iss'   => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/indexing',
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

function indexingGoogleGetToken(): ?string {
    $key = indexingGoogleKeyData();
    if (!$key) return null;
    $jwt = _indexingGoogleJwt($key);
    if (!$jwt) return null;
    $r = _indexingHttpPost(
        'https://oauth2.googleapis.com/token',
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
        ['Content-Type: application/x-www-form-urlencoded']
    );
    $data = json_decode($r['body'], true);
    return $data['access_token'] ?? null;
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
function indexingSubmitUrl(string $url): array {
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
