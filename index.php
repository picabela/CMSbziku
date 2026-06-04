<?php
require_once __DIR__ . '/includes/functions.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$categorySlug = $_GET['kategoria'] ?? null;
$categoryName = $categorySlug ? categoryBySlug($categorySlug) : null;

$perPage = postsPerPage();
$posts = getPosts($page, $categoryName, $perPage);
$total = countPosts($categoryName);
$totalPages = (int)ceil($total / $perPage);
$postCatsMap = getCategoriesForPosts(array_map(fn($p) => (int)$p['id'], $posts));

$pageTitle = $categoryName
    ? $categoryName . ' — ' . siteName()
    : siteName() . ' — ' . siteTagline();
$pageDescription = $categoryName
    ? 'Wszystkie wiadomości z kategorii ' . $categoryName . ' na ' . SITE_NAME
    : SITE_DESCRIPTION;
$canonicalBase = $categoryName ? categoryUrl($categoryName) : BASE_URL . '/';
// Self-referencing canonical — strony paginowane wskazują na siebie, nie na stronę 1.
$canonicalSep = str_contains($canonicalBase, '?') ? '&' : '?';
$canonical = $page > 1 ? $canonicalBase . $canonicalSep . 'page=' . $page : $canonicalBase;

// rel="prev"/"next" dla SEO paginacji
$paginationBaseUrl = $categoryName ? categoryUrl($categoryName) : BASE_URL . '/';
$paginationSep = str_contains($paginationBaseUrl, '?') ? '&' : '?';
$_totalPagesForRel = $totalPages;
if ($_totalPagesForRel > 1) {
    if ($page > 1) {
        $relPrev = $paginationBaseUrl . ($page - 1 > 1 ? $paginationSep . 'page=' . ($page - 1) : '');
    }
    if ($page < $_totalPagesForRel) {
        $relNext = $paginationBaseUrl . $paginationSep . 'page=' . ($page + 1);
    }
}

// Build structured data graph
$sdGraphItems = [];

// Organization / NewsMediaOrganization
$orgLogo = siteLogoUrl();
$sdOrg = [
    '@type' => 'NewsMediaOrganization',
    '@id' => BASE_URL . '/#organization',
    'name' => SITE_NAME,
    'url' => BASE_URL,
    'description' => SITE_DESCRIPTION,
];
if ($orgLogo) {
    $sdOrg['logo'] = [
        '@type' => 'ImageObject',
        'url' => $orgLogo,
        'width' => 600,
        'height' => 60,
    ];
}
$sdGraphItems[] = $sdOrg;

// WebPage (CollectionPage)
$sdGraphItems[] = [
    '@type' => 'CollectionPage',
    '@id' => $canonical . '#webpage',
    'url' => $canonical,
    'name' => $pageTitle,
    'description' => $pageDescription,
    'inLanguage' => SITE_LANG,
    'isPartOf' => ['@id' => BASE_URL . '/#website'],
    'about' => ['@id' => BASE_URL . '/#organization'],
    'publisher' => ['@id' => BASE_URL . '/#organization'],
];

// ItemList for article carousel (only on page 1)
if ($page === 1) {
    $allCardsForSchema = $posts; // $lead is extracted in template later; use full list here
    $listItems = [];
    foreach ($allCardsForSchema as $idx => $sp) {
        $spUrl = postUrl($sp);
        $item = [
            '@type' => 'ListItem',
            'position' => $idx + 1,
            'item' => [
                '@type' => 'NewsArticle',
                'headline' => $sp['title'],
                'url' => $spUrl,
                'datePublished' => date('c', strtotime($sp['published_at'])),
                'dateModified' => date('c', strtotime($sp['updated_at'] ?: $sp['published_at'])),
                'description' => $sp['excerpt'] ?? '',
                'inLanguage' => SITE_LANG,
                'publisher' => ['@id' => BASE_URL . '/#organization'],
                'author' => [
                    '@type' => 'Organization',
                    '@id' => BASE_URL . '/#organization',
                ],
            ],
        ];
        if (!empty($sp['featured_image'])) {
            $item['item']['image'] = [
                '@type' => 'ImageObject',
                'url' => UPLOAD_URL . '/' . $sp['featured_image'],
            ];
        }
        $listItems[] = $item;
    }
    if (!empty($listItems)) {
        $sdGraphItems[] = [
            '@type' => 'ItemList',
            '@id' => $canonical . '#itemlist',
            'name' => $pageTitle,
            'url' => $canonical,
            'itemListElement' => $listItems,
        ];
    }
}

// BreadcrumbList dla stron kategorii
if ($categoryName) {
    $sdGraphItems[] = [
        '@type' => 'BreadcrumbList',
        '@id' => $canonicalBase . '#breadcrumb',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Strona główna', 'item' => BASE_URL . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $categoryName, 'item' => $canonicalBase],
        ],
    ];
}

$structuredData = [
    '@context' => 'https://schema.org',
    '@graph' => $sdGraphItems,
];

// Preload obrazu LCP (zdjęcie lead-artykułu na 1. stronie głównej) — przyspiesza LCP.
if (!$categoryName && $page === 1 && !empty($posts[0]['featured_image'])) {
    $preloadImage = UPLOAD_URL . '/' . $posts[0]['featured_image'];
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($categoryName): ?>
    <nav class="breadcrumbs" aria-label="Okruszki">
        <a href="<?= e(BASE_URL) ?>/">Strona główna</a>
        <span aria-hidden="true">/</span>
        <span><?= e($categoryName) ?></span>
    </nav>
    <h2 class="section-title">Kategoria: <?= e($categoryName) ?></h2>
<?php endif; ?>

<?php if (empty($posts)): ?>
    <p class="empty">Brak artykułów do wyświetlenia.</p>
<?php else: ?>
    <?php if (!$categoryName && $page === 1 && !empty($posts[0])):
        $lead = array_shift($posts); ?>
        <article class="lead-article">
            <a href="<?= e(postUrl($lead)) ?>" class="lead-article__link">
                <?php if ($lead['featured_image']): ?>
                    <div class="lead-article__image">
                        <?= postPictureTag($lead['featured_image'], $lead['featured_image_alt'] ?: $lead['title'], 1200, 630, 'eager') ?>
                    </div>
                <?php endif; ?>
                <div class="lead-article__body">
                    <span class="kicker"><?= e($lead['category']) ?></span>
                    <h2 class="lead-article__title"><?= e($lead['title']) ?></h2>
                    <?php if ($lead['subtitle']): ?>
                        <p class="lead-article__subtitle"><?= e($lead['subtitle']) ?></p>
                    <?php endif; ?>
                    <p class="lead-article__excerpt"><?= e($lead['excerpt']) ?></p>
                    <p class="meta"><time datetime="<?= e($lead['published_at']) ?>"><?= e(formatDate($lead['published_at'])) ?></time> · <?= readingTime($lead['content']) ?> min czytania</p>
                </div>
            </a>
        </article>
    <?php endif; ?>

    <section class="grid" aria-label="Lista artykułów">
        <?php foreach ($posts as $post): ?>
            <article class="card">
                <a href="<?= e(postUrl($post)) ?>" class="card__link">
                    <?php if ($post['featured_image']): ?>
                        <div class="card__image">
                            <?= postPictureTag($post['featured_image'], $post['featured_image_alt'] ?: $post['title'], 600, 400) ?>
                        </div>
                    <?php endif; ?>
                    <div class="card__body">
                        <span class="card__cats">
                            <?php foreach (($postCatsMap[(int)$post['id']] ?? [$post['category']]) as $ci => $cName): ?>
                                <span class="kicker<?= $ci === 0 ? ' kicker--primary' : '' ?>"><?= e($cName) ?></span>
                            <?php endforeach; ?>
                        </span>
                        <h3 class="card__title"><?= e($post['title']) ?></h3>
                        <?php if ($post['excerpt']): ?>
                            <p class="card__excerpt"><?= e($post['excerpt']) ?></p>
                        <?php endif; ?>
                        <p class="meta"><time datetime="<?= e($post['published_at']) ?>"><?= e(formatDate($post['published_at'])) ?></time> · <?= readingTime($post['content']) ?> min</p>
                    </div>
                </a>
            </article>
        <?php endforeach; ?>
    </section>

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Paginacja">
            <?php for ($i = 1; $i <= $totalPages; $i++):
                $url = $categoryName
                    ? categoryUrl($categoryName) . ($i > 1 ? '?page=' . $i : '')
                    : BASE_URL . '/' . ($i > 1 ? '?page=' . $i : '');
            ?>
                <a href="<?= e($url) ?>" class="<?= $i === $page ? 'is-active' : '' ?>" <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php';
