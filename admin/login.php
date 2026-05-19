<?php
require_once __DIR__ . '/../includes/auth.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        $error = 'Nieprawidłowy token CSRF.';
    } elseif (attemptLogin($_POST['password'] ?? '')) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Nieprawidłowe hasło.';
    }
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Logowanie · Panel redakcji</title>
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/admin.css">
</head>
<body class="login-body">
<main class="login-box">
    <h1><?= e(SITE_NAME) ?></h1>
    <p class="login-box__hint">Panel redakcji</p>
    <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <label for="password">Hasło dostępu</label>
        <input type="password" id="password" name="password" required autofocus>
        <button type="submit">Zaloguj</button>
    </form>
    <p class="login-box__back"><a href="<?= e(BASE_URL) ?>/">← Powrót na stronę</a></p>
</main>
</body>
</html>
