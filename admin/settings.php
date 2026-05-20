<?php
$adminTitle = 'Ustawienia';
require __DIR__ . '/_layout.php';

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        $flash = ['type' => 'error', 'msg' => 'Nieprawidłowy CSRF.'];
    } else {
        $section = $_POST['section'] ?? '';

        if ($section === 'password') {
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

        if ($section === 'identity') {
            setSetting('site_name', trim($_POST['site_name'] ?? ''));
            setSetting('site_tagline', trim($_POST['site_tagline'] ?? ''));
            setSetting('top_notice_enabled', isset($_POST['top_notice_enabled']) ? '1' : '0');
            setSetting('top_notice_text', trim($_POST['top_notice_text'] ?? ''));

            // Logo upload
            if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $f = $_FILES['logo'];
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                $allowed = ['png','jpg','jpeg','svg','webp'];
                if (!in_array($ext, $allowed, true)) {
                    $flash = ['type' => 'error', 'msg' => 'Logo musi być PNG/JPG/SVG/WebP.'];
                } elseif ($f['size'] > 2 * 1024 * 1024) {
                    $flash = ['type' => 'error', 'msg' => 'Logo zbyt duże (max 2 MB).'];
                } else {
                    @mkdir(UPLOAD_DIR, 0775, true);
                    $filename = 'logo_' . time() . '.' . $ext;
                    if (move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $filename)) {
                        $old = setting('site_logo');
                        if ($old) @unlink(UPLOAD_DIR . '/' . $old);
                        setSetting('site_logo', $filename);
                    }
                }
            }
            if (isset($_POST['remove_logo'])) {
                $old = setting('site_logo');
                if ($old) @unlink(UPLOAD_DIR . '/' . $old);
                setSetting('site_logo', '');
            }
            if (!$flash) $flash = ['type' => 'success', 'msg' => 'Tożsamość strony zaktualizowana.'];
        }

        if ($section === 'contact') {
            setSetting('contact_enabled', isset($_POST['contact_enabled']) ? '1' : '0');
            setSetting('contact_email', trim($_POST['contact_email'] ?? ''));
            setSetting('contact_subject_prefix', trim($_POST['contact_subject_prefix'] ?? ''));
            $flash = ['type' => 'success', 'msg' => 'Ustawienia kontaktu zapisane.'];
        }
    }
}
$logoFile = setting('site_logo');
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Ustawienia</h1>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <section class="settings-card">
        <h2>Tożsamość strony</h2>
        <form method="post" enctype="multipart/form-data" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section" value="identity">

            <label>Nazwa serwisu (zostaw puste, by użyć domyślnej z config.php)
                <input type="text" name="site_name" value="<?= e(setting('site_name', '')) ?>" placeholder="<?= e(SITE_NAME) ?>">
            </label>
            <label>Slogan / tagline
                <input type="text" name="site_tagline" value="<?= e(setting('site_tagline', '')) ?>" placeholder="<?= e(SITE_TAGLINE) ?>">
            </label>

            <fieldset class="radio-group">
                <legend>Logo</legend>
                <?php if ($logoFile): ?>
                    <p><img src="<?= e(UPLOAD_URL . '/' . $logoFile) ?>" alt="Logo" style="max-height:60px;background:#fff;padding:0.5rem"></p>
                    <label class="checkbox"><input type="checkbox" name="remove_logo" value="1"> Usuń obecne logo (wróci do tekstu)</label>
                <?php endif; ?>
                <label>Wgraj nowe logo (PNG/JPG/SVG/WebP, max 2 MB)
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp">
                </label>
                <p class="hint">Jeśli nie wgrasz logo, używana będzie nazwa tekstowa.</p>
            </fieldset>

            <fieldset class="radio-group">
                <legend>Informacja na górze strony</legend>
                <label class="checkbox"><input type="checkbox" name="top_notice_enabled" value="1" <?= setting('top_notice_enabled', '1') === '1' ? 'checked' : '' ?>> Pokaż dyskretny pasek u góry</label>
                <label>Treść paska
                    <textarea name="top_notice_text" rows="2"><?= e(setting('top_notice_text', '')) ?></textarea>
                </label>
                <p class="hint">Sugestia: krótko o tym, że strona jest zoptymalizowana pod czytniki/tablety i że treści są konkretne.</p>
            </fieldset>

            <button class="btn btn--primary" type="submit">Zapisz tożsamość</button>
        </form>
    </section>

    <section class="settings-card">
        <h2>Formularz kontaktowy</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section" value="contact">
            <label class="checkbox"><input type="checkbox" name="contact_enabled" value="1" <?= setting('contact_enabled', '1') === '1' ? 'checked' : '' ?>> Włącz stronę /kontakt</label>
            <label>Adres e-mail, na który mają trafiać wiadomości
                <input type="email" name="contact_email" value="<?= e(setting('contact_email', '')) ?>" placeholder="redakcja@twojadomena.pl">
            </label>
            <label>Prefix tematu wiadomości
                <input type="text" name="contact_subject_prefix" value="<?= e(setting('contact_subject_prefix', '[The Daily Signal]')) ?>">
            </label>
            <p class="hint">Wiadomości wysyłane są przez PHP <code>mail()</code>. Spam-protection: honeypot + prosta kalkulacja + rate limit 1 wiadomość / 60 s z IP.</p>
            <button class="btn btn--primary" type="submit">Zapisz kontakt</button>
        </form>
    </section>

    <section class="settings-card">
        <h2>Zmiana hasła dostępu</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section" value="password">
            <label>Aktualne hasło<input type="password" name="current" required></label>
            <label>Nowe hasło (min. 6 znaków)<input type="password" name="new" required minlength="6"></label>
            <label>Potwierdź nowe hasło<input type="password" name="confirm" required minlength="6"></label>
            <button type="submit" class="btn btn--primary">Zmień hasło</button>
        </form>
    </section>

    <section class="settings-card">
        <h2>Informacje</h2>
        <dl class="info-list">
            <dt>Nazwa serwisu</dt><dd><?= e(siteName()) ?></dd>
            <dt>Hasło startowe</dt><dd><code>admin123</code> (zmień natychmiast)</dd>
            <dt>Baza danych</dt><dd>SQLite (<code>data/database.sqlite</code>)</dd>
            <dt>Wersja PHP</dt><dd><?= e(PHP_VERSION) ?></dd>
        </dl>
    </section>
</div>
<?php require __DIR__ . '/_footer.php';
