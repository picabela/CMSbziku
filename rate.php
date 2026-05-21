<?php
/**
 * AJAX endpoint dla ocen artykułów.
 * POST: post_id, rating, csrf
 */
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

if (setting('ratings_enabled', '1') !== '1') {
    echo json_encode(['ok' => false, 'msg' => 'Oceny są wyłączone.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Metoda niedozwolona.']);
    exit;
}

if (!verifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Sesja wygasła. Odśwież stronę.']);
    exit;
}

$postId = (int)($_POST['post_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 0);
$result = submitPostRating($postId, $rating);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
