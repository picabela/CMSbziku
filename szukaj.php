<?php
require_once __DIR__ . '/includes/functions.php';

$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$results = [];
$total = 0;

if ($q !== '' && mb_strlen($q) >= 2) {
    $pdo = db();
    $like = '%' . $q . '%';
    $offset = ($page - 1) * POSTS_PER_PAGE;

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) FROM posts
        WHERE status='published'
          AND (title LIKE ? OR excerpt LIKE ? OR content LIKE ? OR meta_keywords LIKE ?)
    ");
    $countStmt->execute([$like, $like, $like, $like]);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT *,
               (CASE WHEN title LIKE ? THEN 3 ELSE 0 END
              + CASE WHEN excerpt LIKE ? THEN 2 ELSE 0 END
              + CASE WHEN meta_keywords LIKE ? THEN 1 ELSE 0 END) AS score
        FROM posts
        WHERE status='published'
          AND (title LIKE ? OR excerpt LIKE ? OR content LIKE ? OR meta_keywords LIKE ?)
        ORDER BY score DESC, published_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $i = 1;
    foreach ([$like, $like, $like, $like, $like, $like, $like] as $v) $stmt->bindValue($i++, $v);
    $stmt->bindValue(':limit', POSTS_PER_PAGE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll();
}

$totalPages = (int)ceil($total / POSTS_PER_PAGE);
$pageTitle = $q !== '' ? ('Wyniki dla „' . $q . '" — ' . siteName()) : ('Szukaj — ' . siteName());
$pageDescription = $q !== '' ? 'Artykuły pasujące do zapytania „' . $q . '" w ' . siteName() : 'Przeszukaj artykuły w ' . siteName();
$canonical = BASE_URL . '/szukaj' . ($q !== '' ? '?q=' . urlencode($q) : '');

// Pagination prev/next dla <head>
if ($totalPages > 1) {
    if ($page > 1) {
        $relPrev = BASE_URL . '/szukaj?q=' . urlencode($q) . ($page - 1 > 1 ? '&page=' . ($page - 1) : '');
    }
    if ($page < $totalPages) {
        $relNext = BASE_URL . '/szukaj?q=' . urlencode($q) . '&page=' . ($page + 1);
    }
}

include __DIR__ . '/includes/header.php';
?>
<nav class="breadcrumbs" aria-label="Okruszki">
    <a href="<?= e(BASE_URL) ?>/">Strona główna</a>
    <span aria-hidden="true">/</span>
    <span>Szukaj</span>
</nav>

<form method="get" action="<?= e(BASE_URL) ?>/szukaj" class="search-form" role="search">
    <label for="search-q" class="visually-hidden">Szukaj artykułów</label>
    <input type="search" id="search-q" name="q" value="<?= e($q) ?>" placeholder="Wpisz frazę…" required minlength="2" autofocus>
    <button type="submit">Szukaj</button>
</form>

<?php if ($q === ''): ?>
    <p class="empty">Wpisz minimum 2 znaki w pole wyszukiwania.</p>
<?php elseif (!$results): ?>
    <p class="empty">Nie znaleziono artykułów dla „<strong><?= e($q) ?></strong>". Spróbuj innej frazy lub <a href="<?= e(BASE_URL) ?>/">przeglądaj wszystkie</a>.</p>
<?php else: ?>
    <h2 class="section-title">Wyniki dla „<?= e($q) ?>" <span class="section-title__count">(<?= $total ?>)</span></h2>

    <section class="grid" aria-label="Wyniki wyszukiwania">
        <?php foreach ($results as $post): ?>
            <article class="card">
                <a href="<?= e(postUrl($post)) ?>" class="card__link">
                    <?php if ($post['featured_image']): ?>
                        <div class="card__image">
                            <img src="<?= e(UPLOAD_URL . '/' . $post['featured_image']) ?>" alt="<?= e($post['featured_image_alt'] ?: $post['title']) ?>" loading="lazy">
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
                <a href="<?= e(BASE_URL) ?>/szukaj?q=<?= e(urlencode($q)) ?><?= $i > 1 ? '&page=' . $i : '' ?>" class="<?= $i === $page ? 'is-active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php';
