<?php
/**
 * HTTP endpoint dla zewnętrznego crona.
 * Wywołanie:  GET /cron/run.php?token=XXX
 * Token z Ustawień (admin/auto.php).
 *
 * Przykładowa linia crontab (co 60 min):
 *   0 * * * * curl -s "https://twoja-domena.pl/cron/run.php?token=XXX" > /dev/null
 */
declare(strict_types=1);

ignore_user_abort(true);
set_time_limit(300);

require_once __DIR__ . '/../includes/autoimport.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$token = $_GET['token'] ?? $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
$expected = setting('auto_token');

if (!$expected || !is_string($token) || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$result = runAutoImport();
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
