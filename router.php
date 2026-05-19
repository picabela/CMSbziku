<?php
/**
 * Dev router for `php -S`.
 * In production, Apache's .htaccess handles routing.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

if (preg_match('#^/kategoria/([a-z0-9-]+)/?$#', $uri, $m)) {
    $_GET['kategoria'] = $m[1];
    require __DIR__ . '/index.php';
    return true;
}

if (preg_match('#^/sitemap\.xml$#', $uri)) {
    require __DIR__ . '/sitemap.php';
    return true;
}

if ($uri === '/' || $uri === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}

if (preg_match('#^/([a-z0-9-]+)/?$#', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/article.php';
    return true;
}

require __DIR__ . '/index.php';
return true;
