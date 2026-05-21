<?php
/**
 * llms.txt — standard dla crawlerów AI (Anthropic, OpenAI, Perplexity, Google AI).
 * Specyfikacja: https://llmstxt.org
 */
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$name = siteName();
$tagline = siteTagline();
$description = (string)setting('site_tagline', '') ?: SITE_DESCRIPTION;

echo "# {$name}\n\n";
echo "> {$tagline}\n\n";
echo "{$description}\n\n";

echo "## Site information\n\n";
echo "- Base URL: " . BASE_URL . "\n";
echo "- Language: " . SITE_LANG . "\n";
echo "- Last updated: " . date('c') . "\n";
echo "- Total published articles: " . (int)db()->query("SELECT COUNT(*) FROM posts WHERE status='published'")->fetchColumn() . "\n\n";

echo "## Categories\n\n";
foreach (getCategories() as $cat) {
    echo "- [{$cat['category']}](" . categoryUrl($cat['category']) . ") — {$cat['count']} artykułów\n";
}
echo "\n";

echo "## Latest articles\n\n";
$latest = db()->query("SELECT slug, title, excerpt, published_at FROM posts WHERE status='published' ORDER BY published_at DESC LIMIT 50")->fetchAll();
foreach ($latest as $p) {
    $url = BASE_URL . '/' . $p['slug'];
    echo "- [{$p['title']}]({$url})";
    if (!empty($p['excerpt'])) {
        echo ": " . mb_substr($p['excerpt'], 0, 200);
    }
    echo "\n";
}
echo "\n";

echo "## Resources\n\n";
echo "- [Sitemap](" . BASE_URL . "/sitemap.xml)\n";
echo "- [RSS feed](" . BASE_URL . "/feed.php)\n";
echo "- [Search](" . BASE_URL . "/szukaj)\n";

$pages = getAllPages(true);
if ($pages) {
    echo "\n## Static pages\n\n";
    foreach ($pages as $pg) {
        echo "- [{$pg['title']}](" . pageUrl($pg) . ")\n";
    }
}

echo "\n## Usage guidelines\n\n";
echo "Treści tego serwisu mogą być cytowane przez modele językowe (LLM) z atrybucją do źródła.\n";
echo "Prosimy o linkowanie zwrotne do oryginalnych URL-i artykułów.\n";
echo "Dane strukturalne JSON-LD są dostępne na każdej stronie artykułu.\n";
