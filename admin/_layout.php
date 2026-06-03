<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/updater.php';
requireLogin();
rememberSiteUrl();
$adminTitle = $adminTitle ?? 'Panel redakcji';
$pendingUpdate = updaterPendingVersion();
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
            <a href="prompts.php">Prompty</a>
            <a href="indexing.php">Indeksowanie</a>
            <a href="themes.php">Motywy</a>
            <a href="rodo.php">RODO</a>
            <a href="export.php">Eksport/Import</a>
            <a href="settings.php">Ustawienia</a>
            <a href="update.php">Aktualizacje</a>
            <a href="<?= e(BASE_URL) ?>/" target="_blank" rel="noopener">Strona ↗</a>
            <a href="logout.php" class="admin-nav__logout">Wyloguj</a>
        </nav>
    </div>
</header>
<main class="admin-main">
<?php if ($pendingUpdate !== '' && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'update.php'): ?>
    <div class="update-banner" style="background:#eff6ff;border:1px solid #93c5fd;color:#1e40af;border-radius:6px;padding:.75rem 1rem;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
        <span>🎉 Dostępna nowa wersja CMS: <strong>v<?= e($pendingUpdate) ?></strong><?= setting('update_auto_install', '0') === '1' ? ' — zostanie zainstalowana automatycznie przy najbliższym cronie.' : '' ?></span>
        <a href="<?= e(BASE_URL) ?>/admin/update.php" class="btn btn--primary" style="white-space:nowrap">Przejdź do aktualizacji →</a>
    </div>
<?php endif; ?>
