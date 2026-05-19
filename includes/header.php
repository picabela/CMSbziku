<?php
require_once __DIR__ . '/functions.php';
$pageTitle = $pageTitle ?? SITE_NAME . ' — ' . SITE_TAGLINE;
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
<meta name="generator" content="The Daily Signal CMS">

<meta property="og:site_name" content="<?= e(SITE_NAME) ?>">
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
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
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
</head>
<body>
<a class="skip-link" href="#main">Przejdź do treści</a>
<header class="masthead" role="banner">
    <div class="masthead__top">
        <span class="masthead__date"><?= e(formatDate(date('Y-m-d H:i:s'))) ?></span>
        <span class="masthead__edition">Wydanie cyfrowe</span>
    </div>
    <div class="masthead__title">
        <a href="<?= e(BASE_URL) ?>/" aria-label="Strona główna">
            <h1 class="masthead__logo"><?= e(SITE_NAME) ?></h1>
        </a>
        <p class="masthead__tagline"><?= e(SITE_TAGLINE) ?></p>
    </div>
    <nav class="masthead__nav" aria-label="Kategorie">
        <a href="<?= e(BASE_URL) ?>/">Wszystkie</a>
        <?php foreach (getCategories() as $cat): ?>
            <a href="<?= e(categoryUrl($cat['category'])) ?>"><?= e($cat['category']) ?></a>
        <?php endforeach; ?>
    </nav>
</header>
<main id="main" class="container">
