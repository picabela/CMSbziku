<?php
require_once __DIR__ . '/functions.php';

function isLoggedIn(): bool {
    return !empty($_SESSION['admin_logged_in']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/admin/login.php');
        exit;
    }
}

function attemptLogin(string $password): bool {
    $hash = setting('admin_password_hash');
    if ($hash && password_verify($password, $hash)) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        return true;
    }
    return false;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function changePassword(string $newPassword): bool {
    if (strlen($newPassword) < 6) return false;
    setSetting('admin_password_hash', password_hash($newPassword, PASSWORD_DEFAULT));
    return true;
}
