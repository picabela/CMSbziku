<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(400);
    exit('Bad request');
}

$id = (int)($_POST['id'] ?? 0);
if ($id) {
    $post = getPostById($id);
    if ($post) {
        if ($post['featured_image']) {
            @unlink(UPLOAD_DIR . '/' . $post['featured_image']);
        }
        db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Artykuł usunięty.'];
    }
}
header('Location: index.php');
exit;
