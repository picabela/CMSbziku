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

// Speakable — dla voice asystentów (Google Assistant cytuje te fragmenty)
if (!empty($post['tldr'])) {
    $structuredData['speakable'] = [
        '@type' => 'SpeakableSpecification',
        'cssSelector' => ['.article__title', '.article__tldr p'],
    ];
}

// AggregateRating — gdy są oceny od użytkowników
$ratingStats = setting('ratings_enabled', '1') === '1' ? getPostRatingStats((int)$post['id']) : ['count' => 0, 'average' => 0];
$userRating = setting('ratings_enabled', '1') === '1' ? getUserRatingForPost((int)$post['id']) : null;
if ($ratingStats['count'] > 0) {
    $structuredData['aggregateRating'] = [
        '@type' => 'AggregateRating',
        'ratingValue' => $ratingStats['average'],
        'bestRating' => 5,
        'worstRating' => 1,
        'ratingCount' => $ratingStats['count'],
    ];
}

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
            <?php
            $imgFile = $post['featured_image'];
            $webpFile = preg_replace('/\.[^.]+$/', '.webp', $imgFile);
            $hasWebp = file_exists(UPLOAD_DIR . '/' . $webpFile);
            ?>
            <?php if ($hasWebp): ?>
                <picture>
                    <source srcset="<?= e(UPLOAD_URL . '/' . $webpFile) ?>" type="image/webp">
                    <img src="<?= e(UPLOAD_URL . '/' . $imgFile) ?>" alt="<?= e($post['featured_image_alt'] ?: $post['title']) ?>" itemprop="image" width="1200" height="630" loading="eager">
                </picture>
            <?php else: ?>
                <img src="<?= e(UPLOAD_URL . '/' . $imgFile) ?>" alt="<?= e($post['featured_image_alt'] ?: $post['title']) ?>" itemprop="image" width="1200" height="630" loading="eager">
            <?php endif; ?>
            <?php if ($post['featured_image_alt']): ?>
                <figcaption><?= e($post['featured_image_alt']) ?></figcaption>
            <?php endif; ?>
        </figure>
    <?php endif; ?>

    <?php if (!empty($post['tldr'])): ?>
        <aside class="article__tldr" aria-label="TL;DR — szybkie streszczenie">
            <span class="article__tldr-label">TL;DR</span>
            <p><?= e($post['tldr']) ?></p>
        </aside>
    <?php endif; ?>

    <?php
    // Build TOC and add anchor IDs to H2/H3 (jeśli włączone)
    $tocGlobal = setting('toc_enabled_global', '1') === '1';
    $tocOverride = $post['show_toc'];
    $showToc = $tocOverride === null ? $tocGlobal : ((int)$tocOverride === 1);
    $contentHtml = $post['content'];
    if ($showToc) {
        $parsed = buildTocAndAnchors($contentHtml);
        $contentHtml = $parsed['html'];
        $toc = $parsed['toc'];
    } else {
        $toc = [];
    }
    // Auto internal links
    $contentHtml = applyAutoInternalLinks($contentHtml, (int)$post['id']);
    ?>

    <?php if ($showToc && count($toc) >= 3): ?>
        <nav class="article__toc" aria-label="Spis treści">
            <h2 class="article__toc-title">Spis treści</h2>
            <ol>
                <?php foreach ($toc as $item): ?>
                    <li class="article__toc-item article__toc-item--l<?= (int)$item['level'] ?>">
                        <a href="#<?= e($item['id']) ?>"><?= e($item['text']) ?></a>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>
    <?php endif; ?>

    <div class="article__content" itemprop="articleBody">
        <?= $contentHtml ?>
    </div>

    <?php if (setting('ratings_enabled', '1') === '1'): ?>
        <section class="article__rating" aria-label="Oceń ten artykuł" data-post-id="<?= (int)$post['id'] ?>" data-user-rating="<?= (int)($userRating ?? 0) ?>">
            <h2 class="article__rating-title">Czy ten artykuł był pomocny?</h2>
            <div class="article__rating-row">
                <div class="rating-stars" role="radiogroup" aria-label="Wybierz ocenę od 1 do 5 gwiazdek">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <button type="button" class="rating-stars__btn" role="radio" aria-checked="<?= $userRating === $i ? 'true' : 'false' ?>" aria-label="<?= $i ?> z 5" data-value="<?= $i ?>">
                            <svg viewBox="0 0 24 24" width="32" height="32" fill="currentColor" aria-hidden="true"><path d="M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                        </button>
                    <?php endfor; ?>
                </div>
                <div class="rating-summary">
                    <span class="rating-summary__avg"><?= $ratingStats['count'] > 0 ? number_format($ratingStats['average'], 1, ',', '') : '—' ?></span>
                    <span class="rating-summary__sep">/</span>
                    <span class="rating-summary__max">5</span>
                    <span class="rating-summary__count">(<span class="rating-summary__count-num"><?= (int)$ratingStats['count'] ?></span> ocen)</span>
                </div>
            </div>
            <p class="rating-message" hidden></p>
        </section>
    <?php endif; ?>

    <?php $postTags = getPostTags((int)$post['id']); ?>
    <?php if ($postTags): ?>
        <footer class="article__tags" aria-label="<?= e(tagLabel()) ?>">
            <span class="article__tags-label"><?= e(tagLabel()) ?>:</span>
            <ul class="tag-list">
                <?php foreach ($postTags as $t): ?>
                    <li><a href="<?= e(tagUrl($t['slug'])) ?>" class="tag-chip"><?= e($t['name']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </footer>
    <?php endif; ?>
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

<?php if (setting('ratings_enabled', '1') === '1'): ?>
<script>
(function(){
    const section = document.querySelector('.article__rating');
    if (!section) return;
    const postId = section.dataset.postId;
    const csrfToken = <?= json_encode(csrfToken()) ?>;
    const buttons = section.querySelectorAll('.rating-stars__btn');
    const stars = section.querySelector('.rating-stars');
    const avgEl = section.querySelector('.rating-summary__avg');
    const cntEl = section.querySelector('.rating-summary__count-num');
    const msg = section.querySelector('.rating-message');
    let current = parseInt(section.dataset.userRating || '0', 10);

    function paint(val) {
        buttons.forEach(b => {
            const v = parseInt(b.dataset.value, 10);
            b.classList.toggle('is-filled', v <= val);
        });
    }
    function setActive(val) {
        current = val;
        buttons.forEach(b => {
            const v = parseInt(b.dataset.value, 10);
            b.setAttribute('aria-checked', v === val ? 'true' : 'false');
        });
        paint(val);
    }
    paint(current);

    stars.addEventListener('mouseleave', () => paint(current));
    buttons.forEach(b => {
        b.addEventListener('mouseenter', () => paint(parseInt(b.dataset.value, 10)));
        b.addEventListener('click', async () => {
            const val = parseInt(b.dataset.value, 10);
            if (b.disabled) return;
            buttons.forEach(x => x.disabled = true);
            try {
                const form = new FormData();
                form.append('post_id', postId);
                form.append('rating', val);
                form.append('csrf', csrfToken);
                const res = await fetch('<?= e(BASE_URL) ?>/rate', { method: 'POST', body: form });
                const data = await res.json();
                if (data.ok) {
                    setActive(val);
                    avgEl.textContent = (data.average || 0).toFixed(1).replace('.', ',');
                    cntEl.textContent = data.count || 0;
                    msg.hidden = false;
                    msg.textContent = current === val && data.user_rating === val ? '✓ Dziękujemy za ocenę!' : '✓ Twoja ocena zaktualizowana.';
                    msg.className = 'rating-message rating-message--ok';
                } else {
                    msg.hidden = false;
                    msg.textContent = '⚠ ' + (data.msg || 'Błąd');
                    msg.className = 'rating-message rating-message--err';
                }
            } catch (e) {
                msg.hidden = false;
                msg.textContent = '⚠ Błąd połączenia.';
                msg.className = 'rating-message rating-message--err';
            } finally {
                buttons.forEach(x => x.disabled = false);
            }
        });
    });
})();
</script>
<?php endif; ?>

<?php if (setting('reading_progress_bar', '1') === '1'): ?>
<div class="reading-progress" id="reading-progress" aria-hidden="true"><div class="reading-progress__bar"></div></div>
<script>
(function(){
    var article = document.querySelector('.article__content');
    var bar = document.querySelector('.reading-progress__bar');
    if (!article || !bar) return;
    function update() {
        var rect = article.getBoundingClientRect();
        var total = article.offsetHeight - window.innerHeight + rect.top + window.scrollY;
        var top = window.scrollY - (rect.top + window.scrollY - 60);
        var pct = Math.max(0, Math.min(100, (top / Math.max(1, article.offsetHeight - 200)) * 100));
        bar.style.width = pct + '%';
    }
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php';
