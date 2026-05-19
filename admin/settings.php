<?php
$adminTitle = 'Ustawienia';
require __DIR__ . '/_layout.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        $flash = ['type' => 'error', 'msg' => 'Nieprawidłowy CSRF.'];
    } else {
        $current = $_POST['current'] ?? '';
        $new = $_POST['new'] ?? '';
        $confirm = $_POST['confirm'] ?? '';
        $hash = setting('admin_password_hash');
        if (!password_verify($current, $hash)) {
            $flash = ['type' => 'error', 'msg' => 'Aktualne hasło jest nieprawidłowe.'];
        } elseif (strlen($new) < 6) {
            $flash = ['type' => 'error', 'msg' => 'Nowe hasło musi mieć co najmniej 6 znaków.'];
        } elseif ($new !== $confirm) {
            $flash = ['type' => 'error', 'msg' => 'Hasła nie są zgodne.'];
        } else {
            changePassword($new);
            $flash = ['type' => 'success', 'msg' => 'Hasło zaktualizowane.'];
        }
    }
}
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Ustawienia</h1>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <section class="settings-card">
        <h2>Zmiana hasła dostępu</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <label>Aktualne hasło<input type="password" name="current" required></label>
            <label>Nowe hasło (min. 6 znaków)<input type="password" name="new" required minlength="6"></label>
            <label>Potwierdź nowe hasło<input type="password" name="confirm" required minlength="6"></label>
            <button type="submit" class="btn btn--primary">Zmień hasło</button>
        </form>
    </section>

    <section class="settings-card">
        <h2>Informacje</h2>
        <dl class="info-list">
            <dt>Nazwa serwisu</dt><dd><?= e(SITE_NAME) ?></dd>
            <dt>Hasło startowe</dt><dd><code>admin123</code> (zmień natychmiast)</dd>
            <dt>Baza danych</dt><dd>SQLite (<code>data/database.sqlite</code>)</dd>
            <dt>Wersja PHP</dt><dd><?= e(PHP_VERSION) ?></dd>
        </dl>
    </section>
</div>
<?php require __DIR__ . '/_footer.php';
