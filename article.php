<?php
require_once __DIR__ . '/includes/functions.php';

$slug = $_GET['slug'] ?? '';
$post = $slug ? getPostBySlug($slug) : null;

if (!$post) {
    http_response_code(404);
    $pageTitle = '404 — Strona nie znaleziona | ' . SITE_NAME;
    include __DIR__ . '/includes/header.php';
    echo '<div class="empty"><h2>404</h2><p>Nie znaleźliśmy tego artykułu.</p><p><a href="' . e(BASE_URL) . '/">Wróć na stronę główną</a></p></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = ($post['meta_title'] ?: $post['title']) . ' | ' . SITE_NAME;
$pageDescription = $post['meta_description'] ?: $post['excerpt'];
$canonical = postUrl($post);
$ogImage = $post['featured_image']
    ? UPLOAD_URL . '/' . $post['featured_image']
    : BASE_URL . '/assets/images/og-default.svg';
$ogType = 'article';

$structuredData = [
    '@context' => 'https://schema.org',
    '@type' => 'NewsArticle',
    'headline' => $post['title'],
    'description' => $post['excerpt'],
    'image' => [$ogImage],
    'datePublished' => date('c', strtotime($post['published_at'])),
    'dateModified' => date('c', strtotime($post['updated_at'] ?: $post['published_at'])),
    'author' => [
        '@type' => 'Organization',
        'name' => $post['author'] ?: 'Redakcja',
        'url' => BASE_URL,
    ],
    'publisher' => [
        '@type' => 'Organization',
        'name' => SITE_NAME,
        'logo' => [
            '@type' => 'ImageObject',
            'url' => BASE_URL . '/assets/images/logo.svg',
        ],
    ],
    'mainEntityOfPage' => [
        '@type' => 'WebPage',
        '@id' => $canonical,
    ],
    'articleSection' => $post['category'],
    'inLanguage' => SITE_LANG,
    'keywords' => $post['meta_keywords'],
];

$breadcrumbData = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Strona główna', 'item' => BASE_URL . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $post['category'], 'item' => categoryUrl($post['category'])],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $post['title'], 'item' => $canonical],
    ],
];

include __DIR__ . '/includes/header.php';
?>

<script type="application/ld+json"><?= json_encode($breadcrumbData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

<nav class="breadcrumbs" aria-label="Okruszki">
    <a href="<?= e(BASE_URL) ?>/">Strona główna</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(categoryUrl($post['category'])) ?>"><?= e($post['category']) ?></a>
    <span aria-hidden="true">/</span>
    <span><?= e($post['title']) ?></span>
</nav>

<article class="article" itemscope itemtype="https://schema.org/NewsArticle">
    <header class="article__header">
        <span class="kicker" itemprop="articleSection"><?= e($post['category']) ?></span>
        <h1 class="article__title" itemprop="headline"><?= e($post['title']) ?></h1>
        <?php if ($post['subtitle']): ?>
            <p class="article__subtitle"><?= e($post['subtitle']) ?></p>
        <?php endif; ?>
        <p class="article__meta">
            <span itemprop="author" itemscope itemtype="https://schema.org/Organization">
                <span itemprop="name"><?= e($post['author']) ?></span>
            </span>
            · <time itemprop="datePublished" datetime="<?= e(date('c', strtotime($post['published_at']))) ?>"><?= e(formatDate($post['published_at'])) ?></time>
            · <?= readingTime($post['content']) ?> min czytania
        </p>
    </header>

    <?php if ($post['featured_image']): ?>
        <figure class="article__hero">
            <img src="<?= e(UPLOAD_URL . '/' . $post['featured_image']) ?>" alt="<?= e($post['featured_image_alt'] ?: $post['title']) ?>" itemprop="image" width="1200" height="630">
            <?php if ($post['featured_image_alt']): ?>
                <figcaption><?= e($post['featured_image_alt']) ?></figcaption>
            <?php endif; ?>
        </figure>
    <?php endif; ?>

    <div class="article__content" itemprop="articleBody">
        <?= $post['content'] ?>
    </div>
</article>

<?php $related = getRelatedPosts($post['category'], (int)$post['id']); ?>
<?php if (!empty($related)): ?>
    <aside class="related">
        <h2 class="section-title">Powiązane artykuły</h2>
        <div class="grid">
            <?php foreach ($related as $r): ?>
                <article class="card">
                    <a href="<?= e(postUrl($r)) ?>" class="card__link">
                        <?php if ($r['featured_image']): ?>
                            <div class="card__image">
                                <img src="<?= e(UPLOAD_URL . '/' . $r['featured_image']) ?>" alt="<?= e($r['featured_image_alt'] ?: $r['title']) ?>" loading="lazy" width="600" height="400">
                            </div>
                        <?php endif; ?>
                        <div class="card__body">
                            <span class="kicker"><?= e($r['category']) ?></span>
                            <h3 class="card__title"><?= e($r['title']) ?></h3>
                            <p class="meta"><time datetime="<?= e($r['published_at']) ?>"><?= e(formatDate($r['published_at'])) ?></time></p>
                        </div>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    </aside>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php';
