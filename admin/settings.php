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

        if ($section === 'footer') {
            setSetting('footer_tags_count', max(0, (int)($_POST['footer_tags_count'] ?? 20)));
            setSetting('footer_categories_count', max(0, (int)($_POST['footer_categories_count'] ?? 8)));
            $flash = ['type' => 'success', 'msg' => 'Ustawienia stopki zapisane.'];
        }

        if ($section === 'integrations') {
            foreach (['gtm_id','ga4_id','gsc_verification','bing_verification','facebook_pixel_id'] as $k) {
                setSetting($k, trim($_POST[$k] ?? ''));
            }
            $flash = ['type' => 'success', 'msg' => 'Integracje zapisane.'];
        }

        if ($section === 'custom_code') {
            foreach (['custom_head_code','custom_body_start_code','custom_body_end_code'] as $k) {
                setSetting($k, (string)($_POST[$k] ?? ''));
            }
            $flash = ['type' => 'success', 'msg' => 'Custom code zapisany.'];
        }

        if ($section === 'seo_geo') {
            foreach (['toc_enabled_global','auto_generate_tldr','auto_internal_links','webp_conversion','reading_progress_bar','critical_css_inline'] as $k) {
                setSetting($k, isset($_POST[$k]) ? '1' : '0');
            }
            $flash = ['type' => 'success', 'msg' => 'Ustawienia SEO/GEO zapisane.'];
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
                <input type="text" name="contact_subject_prefix" value="<?= e(setting('contact_subject_prefix', '[bziku CMS]')) ?>">
            </label>
            <p class="hint">Wiadomości wysyłane są przez PHP <code>mail()</code>. Spam-protection: honeypot + prosta kalkulacja + rate limit 1 wiadomość / 60 s z IP.</p>
            <button class="btn btn--primary" type="submit">Zapisz kontakt</button>
        </form>
    </section>

    <section class="settings-card">
        <h2>Stopka serwisu</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section" value="footer">
            <div class="form-row form-row--2">
                <label>Liczba kategorii w stopce
                    <input type="number" name="footer_categories_count" min="0" max="50" value="<?= e(setting('footer_categories_count', '8')) ?>">
                </label>
                <label>Liczba tagów w chmurze
                    <input type="number" name="footer_tags_count" min="0" max="100" value="<?= e(setting('footer_tags_count', '20')) ?>">
                </label>
            </div>
            <p class="hint">Zawsze pokazujemy te z największą liczbą artykułów. Rozmiar chipów tagów skaluje się z popularnością.</p>
            <button class="btn btn--primary" type="submit">Zapisz stopkę</button>
        </form>
    </section>

    <section class="settings-card">
        <h2>Integracje — analityka i weryfikacja</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section" value="integrations">
            <label>Google Tag Manager ID (format: <code>GTM-XXXXXXX</code>)
                <input type="text" name="gtm_id" value="<?= e(setting('gtm_id', '')) ?>" placeholder="GTM-XXXXXXX">
                <small class="hint">Wstrzykuje skrypt do <code>&lt;head&gt;</code> i noscript na początku <code>&lt;body&gt;</code>.</small>
            </label>
            <label>Google Analytics 4 — Measurement ID (format: <code>G-XXXXXXXXXX</code>)
                <input type="text" name="ga4_id" value="<?= e(setting('ga4_id', '')) ?>" placeholder="G-XXXXXXXXXX">
                <small class="hint">Dodawany tylko jeśli NIE używasz GTM (GA4 podpinasz wtedy przez GTM).</small>
            </label>
            <label>Google Search Console — kod weryfikacyjny (sam content, nie cały tag)
                <input type="text" name="gsc_verification" value="<?= e(setting('gsc_verification', '')) ?>" placeholder="np. abcdef1234567890">
                <small class="hint">Wstrzykuje <code>&lt;meta name="google-site-verification" content="..."&gt;</code>.</small>
            </label>
            <label>Bing Webmaster Tools — kod weryfikacyjny
                <input type="text" name="bing_verification" value="<?= e(setting('bing_verification', '')) ?>" placeholder="np. ABC123...">
                <small class="hint">Wstrzykuje <code>&lt;meta name="msvalidate.01" content="..."&gt;</code>.</small>
            </label>
            <label>Meta (Facebook) Pixel ID
                <input type="text" name="facebook_pixel_id" value="<?= e(setting('facebook_pixel_id', '')) ?>" placeholder="np. 1234567890">
            </label>
            <p class="hint">Zostaw puste, aby nie aktywować danej integracji. Wszystkie skrypty wczytują się z <code>async</code>.</p>
            <button class="btn btn--primary" type="submit">Zapisz integracje</button>
        </form>
    </section>

    <section class="settings-card settings-card--wide">
        <h2>Własny kod (header / body)</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section" value="custom_code">
            <label>Kod w <code>&lt;head&gt;</code>
                <textarea name="custom_head_code" rows="6" placeholder="<!-- np. dodatkowe meta tagi, własny CSS, Hotjar, Clarity, Plausible itp. -->"><?= e(setting('custom_head_code', '')) ?></textarea>
                <small class="hint">Wstawione zaraz pod auto-generowanym kodem GTM/GA4/weryfikacji.</small>
            </label>
            <label>Kod tuż po <code>&lt;body&gt;</code> (otwarciu)
                <textarea name="custom_body_start_code" rows="4" placeholder="<!-- np. noscript dla pixeli, banery -->"><?= e(setting('custom_body_start_code', '')) ?></textarea>
            </label>
            <label>Kod przed <code>&lt;/body&gt;</code> (zamknięciem)
                <textarea name="custom_body_end_code" rows="6" placeholder="<!-- np. chat widget, late-loading analytics -->"><?= e(setting('custom_body_end_code', '')) ?></textarea>
                <small class="hint">Tu wstaw skrypty asynchroniczne i widgety — szybciej ładuje się strona.</small>
            </label>
            <p class="hint">⚠ Wklejony kod jest renderowany 1:1 bez sanityzacji. Wklejaj tylko zaufane snippety od dostawców (Google, Microsoft, Meta).</p>
            <button class="btn btn--primary" type="submit">Zapisz custom code</button>
        </form>
    </section>

    <section class="settings-card">
        <h2>🎯 SEO / GEO — optymalizacja pod wyszukiwarki i AI</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section" value="seo_geo">

            <label class="checkbox"><input type="checkbox" name="toc_enabled_global" value="1" <?= setting('toc_enabled_global', '1') === '1' ? 'checked' : '' ?>> <strong>Spis treści (TOC)</strong> globalnie dla artykułów</label>
            <p class="hint">TOC pojawia się tylko gdy artykuł ma ≥3 nagłówków H2/H3. Per-artykuł można nadpisać w edytorze.</p>

            <label class="checkbox"><input type="checkbox" name="auto_generate_tldr" value="1" <?= setting('auto_generate_tldr', '1') === '1' ? 'checked' : '' ?>> <strong>Auto-generuj TL;DR</strong> przez AI przy imporcie</label>
            <p class="hint">2–3 zdania na początku artykułu (max 280 zn). Optymalizacja pod cytowanie w AI Overviews / Perplexity.</p>

            <label class="checkbox"><input type="checkbox" name="auto_internal_links" value="1" <?= setting('auto_internal_links', '1') === '1' ? 'checked' : '' ?>> <strong>Auto internal linking</strong> — linkuj wzmianki tagów do stron tagów</label>
            <p class="hint">Wzmacnia crawl Google i sygnały silosa contentowego. Każdy tag linkowany tylko raz / artykuł.</p>

            <label class="checkbox"><input type="checkbox" name="webp_conversion" value="1" <?= setting('webp_conversion', '1') === '1' ? 'checked' : '' ?>> <strong>Auto-konwersja WebP</strong> przy uploadzie obrazów</label>
            <p class="hint">Średnio 40% mniej KB → lepsze Core Web Vitals. Wymaga PHP-GD z obsługą WebP. <?= function_exists('imagewebp') ? '<strong style="color:green">✓ dostępne</strong>' : '<strong style="color:red">✗ niedostępne</strong>' ?></p>

            <label class="checkbox"><input type="checkbox" name="reading_progress_bar" value="1" <?= setting('reading_progress_bar', '1') === '1' ? 'checked' : '' ?>> <strong>Reading progress bar</strong> na stronach artykułów</label>
            <p class="hint">Pasek na górze pokazujący scroll. Micro-engagement signal — pomaga w Web Vitals.</p>

            <label class="checkbox"><input type="checkbox" name="critical_css_inline" value="1" <?= setting('critical_css_inline', '1') === '1' ? 'checked' : '' ?>> <strong>Inline critical CSS</strong> (jeśli motyw dostarcza)</label>
            <p class="hint">Jeśli motyw ma plik <code>critical.css</code>, wstrzykujemy go inline w &lt;head&gt; dla LCP &lt;1s.</p>

            <button class="btn btn--primary" type="submit">Zapisz SEO/GEO</button>
        </form>
        <p class="hint" style="margin-top:1rem">📄 <a href="<?= e(BASE_URL) ?>/llms.txt" target="_blank">Twój llms.txt</a> dla crawlerów AI (Anthropic, OpenAI, Perplexity) jest generowany automatycznie.</p>
        <p class="hint">🔍 <a href="<?= e(BASE_URL) ?>/szukaj" target="_blank">Wyszukiwarka /szukaj</a> — wbudowana, z paginacją i scoringiem (tytuł × 3, excerpt × 2, keywords × 1).</p>
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
