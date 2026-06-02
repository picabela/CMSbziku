<?php
require_once __DIR__ . '/includes/functions.php';

$slug = $_GET['tag'] ?? '';
$tag = $slug ? getTagBySlug($slug) : null;

if (!$tag) {
    http_response_code(404);
    $pageTitle = '404 — ' . siteName();
    include __DIR__ . '/includes/header.php';
    echo '<div class="empty"><h2>404</h2><p>Tag nie istnieje.</p><p><a href="' . e(BASE_URL) . '/">Strona główna</a></p></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = postsPerPage();
$posts = getPostsByTag((int)$tag['id'], $page, $perPage);
$total = countPostsByTag((int)$tag['id']);
$totalPages = (int)ceil($total / $perPage);

$label = tagLabel();
$pageTitle = $label . ': ' . $tag['name'] . ' — ' . siteName();
$pageDescription = 'Artykuły otagowane „' . $tag['name'] . '" w ' . siteName() . '.';
$canonical = tagUrl($tag['slug']);

if ($totalPages > 1) {
    if ($page > 1) {
        $relPrev = tagUrl($tag['slug']) . ($page - 1 > 1 ? '?page=' . ($page - 1) : '');
    }
    if ($page < $totalPages) {
        $relNext = tagUrl($tag['slug']) . '?page=' . ($page + 1);
    }
}

include __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumbs" aria-label="Okruszki">
    <a href="<?= e(BASE_URL) ?>/">Strona główna</a>
    <span aria-hidden="true">/</span>
    <span><?= e($label) ?>: <?= e($tag['name']) ?></span>
</nav>

<h2 class="section-title"><?= e($label) ?>: <?= e($tag['name']) ?> <span class="section-title__count">(<?= (int)$total ?>)</span></h2>

<?php if (empty($posts)): ?>
    <p class="empty">Brak artykułów z tym tagiem.</p>
<?php else: ?>
    <section class="grid" aria-label="Artykuły z tagiem">
        <?php foreach ($posts as $post): ?>
            <article class="card">
                <a href="<?= e(postUrl($post)) ?>" class="card__link">
                    <?php if ($post['featured_image']): ?>
                        <div class="card__image">
                            <img src="<?= e(UPLOAD_URL . '/' . $post['featured_image']) ?>" alt="<?= e($post['featured_image_alt'] ?: $post['title']) ?>" loading="lazy" width="600" height="400">
                        </div>
                    <?php endif; ?>
                    <div class="card__body">
                        <span class="kicker"><?= e($post['category']) ?></span>
                        <h3 class="card__title"><?= e($post['title']) ?></h3>
                        <?php if ($post['excerpt']): ?>
                            <p class="card__excerpt"><?= e($post['excerpt']) ?></p>
                        <?php endif; ?>
                        <p class="meta"><time datetime="<?= e($post['published_at']) ?>"><?= e(formatDate($post['published_at'])) ?></time></p>
                    </div>
                </a>
            </article>
        <?php endforeach; ?>
    </section>

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Paginacja">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="<?= e(tagUrl($tag['slug'])) ?><?= $i > 1 ? '?page=' . $i : '' ?>" class="<?= $i === $page ? 'is-active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php';
