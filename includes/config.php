<?php
/**
 * Główna konfiguracja aplikacji.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SITE_NAME', 'The Daily Signal');
define('SITE_TAGLINE', 'Wiadomości ze świata GEO i SEO');
define('SITE_DESCRIPTION', 'Minimalistyczna gazeta online z najświeższymi wiadomościami ze świata GEO, SEO i marketingu cyfrowego. Zaprojektowana dla czytników ebook i tabletów.');
define('SITE_LANG', 'pl');
define('SITE_LOCALE', 'pl_PL');

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false) {
    $basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
}

define('BASE_URL', $protocol . $host . ($basePath === '/' ? '' : $basePath));

define('DB_PATH', __DIR__ . '/../data/database.sqlite');
define('UPLOAD_DIR', __DIR__ . '/../uploads');
define('UPLOAD_URL', BASE_URL . '/uploads');

define('DEFAULT_ADMIN_PASSWORD', 'admin123');
define('POSTS_PER_PAGE', 12);
