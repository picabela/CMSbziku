<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/rss+xml; charset=utf-8');
$posts = getPosts(1);
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title><?= e(SITE_NAME) ?></title>
    <link><?= e(BASE_URL) ?></link>
    <description><?= e(SITE_DESCRIPTION) ?></description>
    <language><?= e(SITE_LANG) ?></language>
    <atom:link href="<?= e(BASE_URL) ?>/feed.php" rel="self" type="application/rss+xml" />
    <?php foreach ($posts as $p): ?>
    <item>
        <title><?= e($p['title']) ?></title>
        <link><?= e(postUrl($p)) ?></link>
        <guid isPermaLink="true"><?= e(postUrl($p)) ?></guid>
        <pubDate><?= date(DATE_RSS, strtotime($p['published_at'])) ?></pubDate>
        <category><?= e($p['category']) ?></category>
        <description><![CDATA[<?= $p['excerpt'] ?>]]></description>
    </item>
    <?php endforeach; ?>
</channel>
</rss>
