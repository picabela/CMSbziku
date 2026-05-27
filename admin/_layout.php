<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/updater.php';
requireLogin();
$adminTitle = $adminTitle ?? 'Panel redakcji';

// Badge "nowa wersja" — bazujemy na cache (nie odpytuje GitHuba przy każdej stronie)
$_updateCache = getCachedUpdateInfo();
$_hasUpdate = $_updateCache && !empty($_updateCache['has_update'])
    && compareVersions($_updateCache['latest_version'] ?? '0', getCurrentVersion()) > 0;
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($adminTitle) ?> · <?= e(SITE_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/admin.css">
</head>
<body>
<header class="admin-header">
    <div class="admin-header__inner">
        <a href="index.php" class="admin-header__logo"><?= e(SITE_NAME) ?> <span>· CMS</span></a>
        <nav class="admin-nav">
            <a href="index.php">Artykuły</a>
            <a href="edit.php">Nowy</a>
            <a href="pages.php">Strony</a>
            <a href="menu.php">Menu</a>
            <a href="categories.php">Kategorie</a>
            <a href="tags.php"><?= e(tagLabel()) ?></a>
            <a href="sources.php">Źródła</a>
            <a href="queue.php">Kolejka</a>
            <a href="auto.php">Auto-import</a>
            <a href="themes.php">Motywy</a>
            <a href="rodo.php">RODO</a>
            <a href="export.php">Eksport/Import</a>
            <a href="settings.php">Ustawienia</a>
            <a href="update.php" class="<?= $_hasUpdate ? 'admin-nav__update admin-nav__update--available' : 'admin-nav__update' ?>">
                Aktualizacja
                <?php if ($_hasUpdate): ?>
                    <span class="admin-nav__badge" title="Dostępna nowa wersja: v<?= e($_updateCache['latest_version']) ?>">!</span>
                <?php endif; ?>
            </a>
            <a href="<?= e(BASE_URL) ?>/" target="_blank" rel="noopener">Strona ↗</a>
            <a href="logout.php" class="admin-nav__logout">Wyloguj</a>
        </nav>
    </div>
</header>
<main class="admin-main">
