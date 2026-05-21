<?php
require_once __DIR__ . '/includes/functions.php';

$slug = $_GET['slug'] ?? '';
$page = $slug ? getPageBySlug($slug) : null;

if (!$page) {
    http_response_code(404);
    $pageTitle = '404 — ' . siteName();
    include __DIR__ . '/includes/header.php';
    echo '<div class="empty"><h2>404</h2><p>Strona nie istnieje.</p><p><a href="' . e(BASE_URL) . '/">Strona główna</a></p></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = ($page['meta_title'] ?: $page['title']) . ' — ' . siteName();
$pageDescription = $page['meta_description'] ?: '';
$canonical = pageUrl($page);
$ogType = 'article';

include __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumbs" aria-label="Okruszki">
    <a href="<?= e(BASE_URL) ?>/">Strona główna</a>
    <span aria-hidden="true">/</span>
    <span><?= e($page['title']) ?></span>
</nav>

<article class="article">
    <header class="article__header">
        <h1 class="article__title"><?= e($page['title']) ?></h1>
    </header>
    <div class="article__content">
        <?= $page['content'] ?>
    </div>
</article>

<?php include __DIR__ . '/includes/footer.php';
