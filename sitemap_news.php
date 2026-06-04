<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=600');

// Google News Sitemap — tylko artykuły z ostatnich 2 dni (wymóg Google News).
// Crawler Google News odwiedza ten plik bardzo często → szybsza indeksacja świeżych newsów.
$enabled = setting('news_sitemap_enabled', '1') === '1';
$pubName = siteName();
$lang = SITE_LANG;

$rows = [];
if ($enabled) {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT slug, title, published_at
        FROM posts
        WHERE status = 'published'
          AND published_at >= datetime('now', '-2 days')
        ORDER BY published_at DESC
        LIMIT 1000
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
<?php foreach ($rows as $p): ?>
    <url>
        <loc><?= e(absoluteSiteUrl($p['slug'])) ?></loc>
        <news:news>
            <news:publication>
                <news:name><?= e($pubName) ?></news:name>
                <news:language><?= e($lang) ?></news:language>
            </news:publication>
            <news:publication_date><?= e(date('c', strtotime($p['published_at']))) ?></news:publication_date>
            <news:title><?= e($p['title']) ?></news:title>
        </news:news>
    </url>
<?php endforeach; ?>
</urlset>
