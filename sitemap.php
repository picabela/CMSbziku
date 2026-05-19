<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/xml; charset=utf-8');
$pdo = db();
$posts = $pdo->query("SELECT slug, updated_at, published_at FROM posts WHERE status = 'published' ORDER BY published_at DESC")->fetchAll();
$categories = getCategories();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc><?= e(BASE_URL) ?>/</loc>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
        <lastmod><?= date('c') ?></lastmod>
    </url>
    <?php foreach ($categories as $c): ?>
    <url>
        <loc><?= e(categoryUrl($c['category'])) ?></loc>
        <changefreq>daily</changefreq>
        <priority>0.7</priority>
    </url>
    <?php endforeach; ?>
    <?php foreach ($posts as $p): ?>
    <url>
        <loc><?= e(BASE_URL . '/' . $p['slug']) ?></loc>
        <lastmod><?= date('c', strtotime($p['updated_at'] ?: $p['published_at'])) ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>
    <?php endforeach; ?>
</urlset>
