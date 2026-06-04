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
        <?= applyOutboundNofollow($page['content'], setting('outbound_nofollow', '0') === '1') ?>
    </div>
</article>

<?php $recentPosts = getPosts(1, null, 3); ?>
<?php if (!empty($recentPosts)): ?>
    <aside class="related">
        <h2 class="section-title">Przeczytaj również</h2>
        <div class="grid">
            <?php foreach ($recentPosts as $rp): ?>
                <article class="card">
                    <a href="<?= e(postUrl($rp)) ?>" class="card__link">
                        <?php if ($rp['featured_image']): ?>
                            <div class="card__image">
                                <img src="<?= e(UPLOAD_URL . '/' . $rp['featured_image']) ?>" alt="<?= e($rp['featured_image_alt'] ?: $rp['title']) ?>" loading="lazy" width="600" height="400">
                            </div>
                        <?php endif; ?>
                        <div class="card__body">
                            <span class="kicker"><?= e($rp['category']) ?></span>
                            <h3 class="card__title"><?= e($rp['title']) ?></h3>
                            <p class="meta"><time datetime="<?= e($rp['published_at']) ?>"><?= e(formatDate($rp['published_at'])) ?></time></p>
                        </div>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    </aside>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php';
