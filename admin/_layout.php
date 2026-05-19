<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$adminTitle = $adminTitle ?? 'Panel redakcji';
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
            <a href="edit.php">Nowy artykuł</a>
            <a href="settings.php">Ustawienia</a>
            <a href="<?= e(BASE_URL) ?>/" target="_blank" rel="noopener">Strona ↗</a>
            <a href="logout.php" class="admin-nav__logout">Wyloguj</a>
        </nav>
    </div>
</header>
<main class="admin-main">
