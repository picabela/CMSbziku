<?php
/**
 * Diagnostyka renderowania artykułów (TYMCZASOWY plik — usuń po użyciu).
 * Wejdź na: /admin/_diag.php?slug=SLUG-NOWEGO-ARTYKULU
 * Pokazuje wersję CMS, PHP, dane artykułu i symuluje render krok po kroku.
 */
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
header('Content-Type: text/plain; charset=utf-8');

echo "=== ŚRODOWISKO ===\n";
echo "PHP: " . PHP_VERSION . "\n";
$ver = is_file(__DIR__ . '/../version.json') ? json_decode(file_get_contents(__DIR__ . '/../version.json'), true) : null;
echo "version.json: " . ($ver['version'] ?? '(brak pliku)') . "\n";
echo "article.php zawiera footer.php: " . (str_contains(file_get_contents(__DIR__ . '/../article.php'), "includes/footer.php") ? "TAK" : "NIE") . "\n";
echo "article.php zawiera Przeczytaj również: " . (str_contains(file_get_contents(__DIR__ . '/../article.php'), "Przeczytaj również") ? "TAK" : "NIE") . "\n";
echo "fetch w article.php do: " . (preg_match('#/rate(\.php)?#', file_get_contents(__DIR__ . '/../article.php'), $m) ? $m[0] : "(nie znaleziono)") . "\n";
echo "funkcja getPostCategories istnieje: " . (function_exists('getPostCategories') ? "TAK" : "NIE") . "\n";
echo "funkcja faviconUrl istnieje: " . (function_exists('faviconUrl') ? "TAK" : "NIE") . "\n";
echo ".htaccess ma regułę /rate: " . (is_file(__DIR__ . '/../.htaccess') && str_contains(file_get_contents(__DIR__ . '/../.htaccess'), 'rate.php') ? "TAK" : "NIE/brak pliku") . "\n";
echo "ratings_enabled: " . setting('ratings_enabled', '1') . "\n\n";

$slug = $_GET['slug'] ?? '';
if ($slug === '') { echo "Dodaj ?slug=... aby zdiagnozować konkretny artykuł.\n"; exit; }

$post = getPostBySlug($slug);
if (!$post) { echo "Nie znaleziono artykułu o slug=$slug\n"; exit; }

echo "=== ARTYKUŁ id={$post['id']} ===\n";
foreach (['category','status','published_at','show_toc'] as $f) {
    echo "$f = " . var_export($post[$f] ?? '(NULL)', true) . "\n";
}
$cats = getPostCategories((int)$post['id']);
echo "getPostCategories: " . json_encode($cats, JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== SYMULACJA RENDERU (każdy krok = funkcja użyta w article.php) ===\n";
$steps = [
    'categoryUrl(category)'      => fn() => categoryUrl($post['category']),
    'date(c, published_at)'      => fn() => date('c', strtotime($post['published_at'])),
    'readingTime(content)'       => fn() => readingTime($post['content']),
    'buildTocAndAnchors'         => fn() => buildTocAndAnchors($post['content']),
    'applyAutoInternalLinks'     => fn() => applyAutoInternalLinks($post['content'], (int)$post['id']),
    'getPostRatingStats'         => fn() => getPostRatingStats((int)$post['id']),
    'getUserRatingForPost'       => fn() => getUserRatingForPost((int)$post['id']),
    'getPostTags'                => fn() => getPostTags((int)$post['id']),
    'getRelatedPosts'            => fn() => getRelatedPosts($cats ?: [$post['category']], (int)$post['id']),
    'topCategories (footer)'     => fn() => topCategories(8),
    'topTags (footer)'           => fn() => topTags(20),
    'getMenuItems(footer)'       => fn() => getMenuItems('footer'),
    'faviconUrl'                 => fn() => function_exists('faviconUrl') ? faviconUrl() : 'BRAK FUNKCJI',
];
foreach ($steps as $name => $fn) {
    try { $fn(); echo "OK   $name\n"; }
    catch (\Throwable $e) { echo "BŁĄD $name -> " . get_class($e) . ": " . $e->getMessage() . "\n"; }
}
echo "\nJeśli wszystkie kroki OK — render działa, problem jest gdzie indziej (cache/CDN). Usuń ten plik.\n";
