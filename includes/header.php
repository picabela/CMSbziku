<?php
require_once __DIR__ . '/functions.php';
$pageTitle = $pageTitle ?? (siteName() . ' — ' . siteTagline());
$pageDescription = $pageDescription ?? SITE_DESCRIPTION;
$canonical = $canonical ?? BASE_URL . ($_SERVER['REQUEST_URI'] ?? '/');
$ogImage = $ogImage ?? (BASE_URL . '/assets/images/og-default.svg');
$ogType = $ogType ?? 'website';
?><!DOCTYPE html>
<html lang="<?= SITE_LANG ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#ffffff">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($pageDescription) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
<meta name="author" content="<?= e(SITE_NAME) ?>">
<meta name="generator" content="bziku CMS">

<meta property="og:site_name" content="<?= e(siteName()) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e($pageDescription) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:type" content="<?= e($ogType) ?>">
<meta property="og:locale" content="<?= e(SITE_LOCALE) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($pageTitle) ?>">
<meta name="twitter:description" content="<?= e($pageDescription) ?>">
<meta name="twitter:image" content="<?= e($ogImage) ?>">

<link rel="alternate" type="application/rss+xml" title="<?= e(SITE_NAME) ?> RSS" href="<?= e(BASE_URL) ?>/feed.php">
<link rel="sitemap" type="application/xml" href="<?= e(BASE_URL) ?>/sitemap.xml">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400&family=Source+Serif+4:ital,wght@0,400;0,600;1,400&family=Inter:wght@400;500;700&display=swap">
<link rel="stylesheet" href="<?= e(themeAssetUrl('style.css')) ?>">
<?= renderThemeColorStyle() ?>
<link rel="icon" type="image/svg+xml" href="<?= e(BASE_URL) ?>/assets/images/favicon.svg">

<?php if (!empty($structuredData)): ?>
<script type="application/ld+json"><?= json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?></script>
<?php endif; ?>

<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => SITE_NAME,
    'url' => BASE_URL,
    'description' => SITE_DESCRIPTION,
    'inLanguage' => SITE_LANG,
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => BASE_URL . '/szukaj?q={search_term_string}',
        'query-input' => 'required name=search_term_string',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
</script>
<?= renderCustomCode('head') ?>
</head>
<body>
<?= renderCustomCode('body_start') ?>
<a class="skip-link" href="#main">Przejdź do treści</a>
<?php if (setting('top_notice_enabled', '1') === '1' && setting('top_notice_text', '')): ?>
<div class="top-notice" role="note">
    <span class="top-notice__icon" aria-hidden="true">📖</span>
    <span class="top-notice__text"><?= e(setting('top_notice_text', '')) ?></span>
</div>
<?php endif; ?>
<header class="masthead" role="banner">
    <div class="masthead__top">
        <span class="masthead__date"><?= e(formatDate(date('Y-m-d H:i:s'))) ?></span>
        <span class="masthead__edition">Wydanie cyfrowe</span>
    </div>
    <div class="masthead__title">
        <a href="<?= e(BASE_URL) ?>/" aria-label="Strona główna">
            <?php $logo = siteLogoUrl(); if ($logo): ?>
                <img src="<?= e($logo) ?>" alt="<?= e(siteName()) ?>" class="masthead__logo-img">
            <?php else: ?>
                <h1 class="masthead__logo"><?= e(siteName()) ?></h1>
            <?php endif; ?>
        </a>
        <p class="masthead__tagline"><?= e(siteTagline()) ?></p>
    </div>
    <button class="masthead__menu-toggle" type="button" aria-expanded="false" aria-controls="primary-nav" aria-label="Otwórz menu">
        <span class="masthead__menu-bars" aria-hidden="true"></span>
        <span class="masthead__menu-label">Menu</span>
    </button>
    <nav class="masthead__nav" id="primary-nav" aria-label="Menu główne">
        <?php foreach (renderMenu('header') as $mi): ?>
            <a href="<?= e($mi['url']) ?>"><?= e($mi['label']) ?></a>
        <?php endforeach; ?>
    </nav>
</header>
<script>
(function(){
    var toggle = document.querySelector('.masthead__menu-toggle');
    var nav = document.getElementById('primary-nav');
    if (!toggle || !nav) return;
    toggle.addEventListener('click', function(){
        var open = nav.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Zamknij menu' : 'Otwórz menu');
    });
    // Zamykaj po kliknięciu linka (mobile UX)
    nav.querySelectorAll('a').forEach(function(a){
        a.addEventListener('click', function(){
            if (nav.classList.contains('is-open')) {
                nav.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    });
})();
</script>
<main id="main" class="container">
