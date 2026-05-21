<?php
$adminTitle = 'RODO';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $section = $_POST['section'] ?? '';

    if ($section === 'general') {
        setSetting('rodo_enabled', isset($_POST['rodo_enabled']) ? '1' : '0');
        setSetting('rodo_consent_mode_v2', isset($_POST['rodo_consent_mode_v2']) ? '1' : '0');
        setSetting('rodo_banner_position', in_array($_POST['rodo_banner_position'] ?? '', ['bottom','top','center'], true) ? $_POST['rodo_banner_position'] : 'bottom');
        setSetting('rodo_consent_lifetime_days', max(1, min(3650, (int)($_POST['rodo_consent_lifetime_days'] ?? 365))));
        setSetting('rodo_color_primary', preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($_POST['rodo_color_primary'] ?? '')) ? trim($_POST['rodo_color_primary']) : '#2540b8');
        $flash = ['type' => 'success', 'msg' => 'Ustawienia ogólne zapisane.'];
    }

    if ($section === 'texts') {
        foreach (['rodo_banner_title','rodo_banner_text','rodo_accept_all_text','rodo_accept_selected_text','rodo_reject_text'] as $k) {
            setSetting($k, trim((string)($_POST[$k] ?? '')));
        }
        $flash = ['type' => 'success', 'msg' => 'Teksty zapisane.'];
    }

    if ($section === 'categories') {
        $names = $_POST['cat_name'] ?? [];
        $descs = $_POST['cat_desc'] ?? [];
        $examples = $_POST['cat_examples'] ?? [];
        $cats = rodoGetCategories();
        foreach ($cats as $i => &$c) {
            if (isset($names[$i])) $c['name'] = trim((string)$names[$i]);
            if (isset($descs[$i])) $c['description'] = trim((string)$descs[$i]);
            if (isset($examples[$i])) {
                $ex = trim((string)$examples[$i]);
                $c['examples'] = $ex !== '' ? array_filter(array_map('trim', explode(',', $ex))) : [];
            }
        }
        unset($c);
        setSetting('rodo_categories', json_encode($cats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $flash = ['type' => 'success', 'msg' => 'Kategorie zapisane.'];
    }

    if ($section === 'policy') {
        $form = in_array($_POST['rodo_company_form'] ?? '', ['individual','company'], true) ? $_POST['rodo_company_form'] : 'individual';
        setSetting('rodo_company_form', $form);
        setSetting('rodo_show_company_data', isset($_POST['rodo_show_company_data']) ? '1' : '0');
        setSetting('rodo_company_name', trim((string)($_POST['rodo_company_name'] ?? '')));
        setSetting('rodo_company_address', trim((string)($_POST['rodo_company_address'] ?? '')));
        setSetting('rodo_company_email', trim((string)($_POST['rodo_company_email'] ?? '')));
        setSetting('rodo_company_nip', trim((string)($_POST['rodo_company_nip'] ?? '')));
        setSetting('rodo_dpo_contact', trim((string)($_POST['rodo_dpo_contact'] ?? '')));
        setSetting('rodo_auto_generate_policy', isset($_POST['rodo_auto_generate_policy']) ? '1' : '0');

        if (setting('rodo_auto_generate_policy', '0') === '1') {
            rodoUpsertPage('polityka-prywatnosci', 'Polityka prywatności', rodoGeneratePrivacyPolicy(), 'Polityka prywatności — informacja o przetwarzaniu danych osobowych w ' . siteName());
            rodoUpsertPage('polityka-cookies', 'Polityka cookies', rodoGenerateCookiesPolicy(), 'Polityka cookies — informacja o plikach cookies używanych w ' . siteName());
            $flash = ['type' => 'success', 'msg' => 'Polityki zaktualizowane: /strona/polityka-prywatnosci i /strona/polityka-cookies.'];
        } else {
            $flash = ['type' => 'success', 'msg' => 'Dane administratora zapisane. Auto-generowanie polityk wyłączone.'];
        }
    }

    if ($section === 'regenerate') {
        rodoUpsertPage('polityka-prywatnosci', 'Polityka prywatności', rodoGeneratePrivacyPolicy(), 'Polityka prywatności');
        rodoUpsertPage('polityka-cookies', 'Polityka cookies', rodoGenerateCookiesPolicy(), 'Polityka cookies');
        $flash = ['type' => 'success', 'msg' => 'Polityki przegenerowane na podstawie aktualnych ustawień.'];
    }

    if (!$flash) $flash = ['type' => 'success', 'msg' => 'Zapisano.'];
    $_SESSION['flash'] = $flash;
    header('Location: rodo.php'); exit;
}

$cats = rodoGetCategories();
$enabled = rodoEnabled();
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>RODO — zgoda na cookies</h1>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <?php if (!$enabled): ?>
        <div class="flash flash--error"><strong>⚠ Tryb RODO jest WYŁĄCZONY.</strong> Banner się nie pokazuje na stronie. Włącz w sekcji „Ustawienia ogólne" poniżej.</div>
    <?php else: ?>
        <div class="flash flash--success">✓ Tryb RODO jest <strong>aktywny</strong>. Banner pokazuje się odwiedzającym do momentu wyrażenia zgody.</div>
    <?php endif; ?>

    <section class="settings-card">
        <h2>Ustawienia ogólne</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section" value="general">

            <label class="checkbox"><input type="checkbox" name="rodo_enabled" value="1" <?= $enabled ? 'checked' : '' ?>> <strong>Włącz tryb RODO</strong> — pokaż banner zgody na każdej stronie</label>
            <label class="checkbox"><input type="checkbox" name="rodo_consent_mode_v2" value="1" <?= setting('rodo_consent_mode_v2', '1') === '1' ? 'checked' : '' ?>> <strong>Google Consent Mode v2</strong> — automatyczne sygnały do GTM/GA4/Ads</label>
            <p class="hint">Consent Mode v2 wstrzykuje <code>gtag('consent', 'default', {...})</code> ZANIM załadują się GTM/GA4/Pixel. Domyślnie wszystko jest <code>denied</code> — odbędzie się <code>update</code> tylko po wyrażeniu zgody. <strong>Wymagane od marca 2024 dla reklam w UE.</strong></p>

            <div class="form-row form-row--2">
                <label>Pozycja banner-a
                    <select name="rodo_banner_position">
                        <option value="bottom" <?= setting('rodo_banner_position', 'bottom') === 'bottom' ? 'selected' : '' ?>>Dół (sticky bottom)</option>
                        <option value="center" <?= setting('rodo_banner_position', 'bottom') === 'center' ? 'selected' : '' ?>>Środek (modal)</option>
                        <option value="top" <?= setting('rodo_banner_position', 'bottom') === 'top' ? 'selected' : '' ?>>Góra</option>
                    </select>
                </label>
                <label>Kolor akcentu (przyciski + przełączniki)
                    <input type="color" name="rodo_color_primary" value="<?= e(substr(setting('rodo_color_primary', '#2540b8'), 0, 7)) ?>" style="width:60px;height:38px;padding:2px">
                    <input type="text" value="<?= e(setting('rodo_color_primary', '#2540b8')) ?>" oninput="this.previousElementSibling.value = this.value" pattern="#[0-9a-fA-F]{3,8}" style="width:120px;display:inline-block;margin-left:0.5rem">
                </label>
            </div>

            <label>Czas zapamiętania zgody (dni)
                <input type="number" name="rodo_consent_lifetime_days" min="1" max="3650" value="<?= e(setting('rodo_consent_lifetime_days', '365')) ?>">
                <small class="hint">Po tym czasie banner pojawi się ponownie. RODO zaleca max 12 miesięcy (365 dni).</small>
            </label>

            <button type="submit" class="btn btn--primary">Zapisz ustawienia ogólne</button>
        </form>
    </section>

    <section class="settings-card">
        <h2>Teksty banner-a</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section" value="texts">

            <label>Tytuł
                <input type="text" name="rodo_banner_title" value="<?= e(setting('rodo_banner_title', '')) ?>" maxlength="150">
            </label>
            <label>Treść informacyjna
                <textarea name="rodo_banner_text" rows="5"><?= e(setting('rodo_banner_text', '')) ?></textarea>
            </label>
            <div class="form-row form-row--2">
                <label>Tekst: „Odmowa"
                    <input type="text" name="rodo_reject_text" value="<?= e(setting('rodo_reject_text', 'Odmowa')) ?>" maxlength="40">
                </label>
                <label>Tekst: „Zezwól na wybór"
                    <input type="text" name="rodo_accept_selected_text" value="<?= e(setting('rodo_accept_selected_text', 'Zezwól na wybór')) ?>" maxlength="40">
                </label>
            </div>
            <label>Tekst: „Zezwól na wszystkie"
                <input type="text" name="rodo_accept_all_text" value="<?= e(setting('rodo_accept_all_text', 'Zezwól na wszystkie')) ?>" maxlength="40">
            </label>

            <button type="submit" class="btn btn--primary">Zapisz teksty</button>
        </form>
    </section>

    <section class="settings-card settings-card--wide">
        <h2>Kategorie cookies</h2>
        <p class="hint">4 standardowe kategorie zgodnie z CNIL i Cookiebot. „Niezbędne" są zawsze aktywne — wymagane do działania strony.</p>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section" value="categories">

            <?php foreach ($cats as $i => $c): ?>
                <fieldset>
                    <legend><?= e($c['name']) ?> <?= !empty($c['required']) ? '<span style="color:#888;font-weight:400">(zawsze aktywne)</span>' : '' ?></legend>
                    <label>Nazwa wyświetlana
                        <input type="text" name="cat_name[<?= $i ?>]" value="<?= e($c['name']) ?>" maxlength="40">
                    </label>
                    <label>Opis kategorii (pokazywany w „Szczegóły")
                        <textarea name="cat_desc[<?= $i ?>]" rows="3"><?= e($c['description']) ?></textarea>
                    </label>
                    <label>Przykładowe cookies (oddzielone przecinkami)
                        <input type="text" name="cat_examples[<?= $i ?>]" value="<?= e(implode(', ', $c['examples'] ?? [])) ?>" placeholder="_ga, _gid, _gat">
                    </label>
                    <p class="hint">Mapowanie Consent Mode v2: <code><?= e($c['consent_mode'] ?? '—') ?></code></p>
                </fieldset>
            <?php endforeach; ?>

            <button type="submit" class="btn btn--primary">Zapisz kategorie</button>
        </form>
    </section>

    <section class="settings-card settings-card--wide">
        <h2>Polityka prywatności i cookies</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section" value="policy">

            <label class="checkbox"><input type="checkbox" name="rodo_auto_generate_policy" value="1" <?= setting('rodo_auto_generate_policy', '1') === '1' ? 'checked' : '' ?>> <strong>Auto-generuj politykę prywatności i cookies</strong> jako strony statyczne</label>
            <p class="hint">Strony powstają pod adresami <a href="<?= e(BASE_URL) ?>/strona/polityka-prywatnosci" target="_blank">/strona/polityka-prywatnosci</a> i <a href="<?= e(BASE_URL) ?>/strona/polityka-cookies" target="_blank">/strona/polityka-cookies</a>. Możesz je później edytować ręcznie w sekcji <a href="pages.php">Strony</a>.</p>

            <fieldset class="radio-group">
                <legend>Rodzaj administratora</legend>
                <label class="checkbox"><input type="radio" name="rodo_company_form" value="individual" <?= setting('rodo_company_form', 'individual') === 'individual' ? 'checked' : '' ?>> Osoba fizyczna / blog osobisty</label>
                <label class="checkbox"><input type="radio" name="rodo_company_form" value="company" <?= setting('rodo_company_form', 'individual') === 'company' ? 'checked' : '' ?>> Firma / działalność gospodarcza</label>
            </fieldset>

            <label class="checkbox"><input type="checkbox" name="rodo_show_company_data" value="1" <?= setting('rodo_show_company_data', '0') === '1' ? 'checked' : '' ?>> Pokaż moje dane w polityce</label>
            <p class="hint"><strong>WAŻNE dla prywatności:</strong> jeśli zostawisz wyłączone, polityka będzie odsyłać do strony Kontakt zamiast podawać Twoje dane wprost. RODO wymaga ujawnienia administratora — ale e-mail kontaktowy w formularzu w pełni spełnia ten wymóg.</p>

            <label>Nazwa administratora (opcjonalnie)
                <input type="text" name="rodo_company_name" value="<?= e(setting('rodo_company_name', '')) ?>" placeholder="np. Jan Kowalski / ACME Sp. z o.o.">
            </label>
            <label>Adres siedziby / korespondencyjny (opcjonalnie)
                <input type="text" name="rodo_company_address" value="<?= e(setting('rodo_company_address', '')) ?>" placeholder="ul. Przykładowa 1, 00-000 Warszawa">
            </label>
            <div class="form-row form-row--2">
                <label>E-mail kontaktowy
                    <input type="email" name="rodo_company_email" value="<?= e(setting('rodo_company_email', '')) ?>" placeholder="kontakt@domena.pl">
                </label>
                <label>NIP (tylko dla firm)
                    <input type="text" name="rodo_company_nip" value="<?= e(setting('rodo_company_nip', '')) ?>" placeholder="0000000000">
                </label>
            </div>
            <label>Kontakt do IOD / DPO (opcjonalnie)
                <input type="email" name="rodo_dpo_contact" value="<?= e(setting('rodo_dpo_contact', '')) ?>" placeholder="iod@domena.pl">
                <small class="hint">Pole wymagane tylko dla niektórych podmiotów (organy publiczne, masowe przetwarzanie). Większość małych firm i blogów go nie potrzebuje.</small>
            </label>

            <button type="submit" class="btn btn--primary">Zapisz i regeneruj polityki</button>
        </form>

        <form method="post" style="margin-top:1rem">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section" value="regenerate">
            <button type="submit" class="btn" onclick="return confirm('Nadpisać aktualną treść polityk wygenerowaną wersją? Twoje ręczne zmiany w strona/polityka-* zostaną utracone.')">🔄 Przegeneruj polityki teraz</button>
        </form>
    </section>

    <section class="settings-card">
        <h2>Podgląd i status</h2>
        <p>Po włączeniu trybu RODO odwiedzający zobaczą banner przy pierwszym wejściu. Plik cookie <code>rodo_consent</code> przechowuje wybory przez <?= (int)setting('rodo_consent_lifetime_days', '365') ?> dni.</p>
        <p><strong>Zarządzanie zgodą:</strong> okrągły przycisk z ikoną cookie w lewym dolnym rogu strony — kliknięcie ponownie otwiera banner.</p>
        <p><strong>Anulowanie zgody przez użytkownika:</strong> wystarczy że wyczyści cookies w przeglądarce lub kliknie ikonę „Zarządzaj cookies".</p>
        <h3 style="margin-top:1rem">Co się dzieje pod maską:</h3>
        <ol>
            <li>Strona ładuje się z <code>Consent Mode v2 default: all denied</code></li>
            <li>GTM/GA4/Pixel ładują się ale <strong>nie zbierają danych</strong> dopóki użytkownik nie wyrazi zgody</li>
            <li>Po kliknięciu „Zezwól" → <code>gtag('consent', 'update', ...)</code> przełącza odpowiednie storage na <code>granted</code></li>
            <li>Eventy/page_view są wysyłane retroaktywnie (Google's „cookieless ping" feature)</li>
        </ol>
    </section>
</div>
<?php require __DIR__ . '/_footer.php';
