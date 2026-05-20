<?php
$adminTitle = 'Motywy';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$themesDir = realpath(__DIR__ . '/../themes');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';

    // Aktywacja motywu
    if ($action === 'activate') {
        $slug = trim($_POST['slug'] ?? '');
        $manifest = readThemeManifest($slug);
        if ($manifest) {
            setSetting('active_theme', $slug);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Aktywowano motyw: ' . $manifest['name']];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Motyw nieprawidłowy lub nie istnieje.'];
        }
        header('Location: themes.php'); exit;
    }

    // Usuwanie motywu
    if ($action === 'delete') {
        $slug = trim($_POST['slug'] ?? '');
        if ($slug === '' || $slug === setting('active_theme', 'classic')) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nie można usunąć aktywnego motywu.'];
        } elseif (in_array($slug, ['classic', 'modern'], true)) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nie można usunąć wbudowanego motywu.'];
        } else {
            $dir = $themesDir . '/' . $slug;
            if (is_dir($dir) && str_starts_with(realpath($dir) ?: '', $themesDir)) {
                deleteThemeDir($dir);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Motyw usunięty.'];
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nieprawidłowa ścieżka motywu.'];
            }
        }
        header('Location: themes.php'); exit;
    }

    // Upload .zip
    if ($action === 'upload') {
        if (!class_exists('ZipArchive')) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Brak rozszerzenia PHP ZipArchive na serwerze.'];
            header('Location: themes.php'); exit;
        }
        if (empty($_FILES['theme_zip']['tmp_name']) || $_FILES['theme_zip']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Brak pliku lub błąd uploadu.'];
            header('Location: themes.php'); exit;
        }
        $tmp = $_FILES['theme_zip']['tmp_name'];
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nie udało się otworzyć archiwum ZIP.'];
            header('Location: themes.php'); exit;
        }

        $result = installThemeFromZip($zip, $themesDir);
        $zip->close();
        $_SESSION['flash'] = ['type' => $result['ok'] ? 'success' : 'error', 'msg' => $result['msg']];
        header('Location: themes.php'); exit;
    }
}

function deleteThemeDir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) deleteThemeDir($p);
        else @unlink($p);
    }
    @rmdir($dir);
}

function installThemeFromZip(ZipArchive $zip, string $themesDir): array {
    // Krok 1: znajdź theme.json (na poziomie root lub w folderze głównym ZIP)
    $manifestIndex = -1;
    $manifestPath = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = $stat['name'];
        if (basename($name) === 'theme.json') {
            // Akceptujemy maks. 1 poziom zagłębienia (np. "moj-motyw/theme.json")
            $parts = explode('/', trim($name, '/'));
            if (count($parts) <= 2) {
                $manifestIndex = $i;
                $manifestPath = $name;
                break;
            }
        }
    }
    if ($manifestIndex < 0) {
        return ['ok' => false, 'msg' => 'W archiwum brakuje pliku theme.json (na poziomie root lub w głównym folderze).'];
    }

    $manifestJson = $zip->getFromIndex($manifestIndex);
    $manifest = json_decode($manifestJson, true);
    if (!is_array($manifest)) {
        return ['ok' => false, 'msg' => 'theme.json nie jest prawidłowym JSON-em.'];
    }
    foreach (['name','slug','version'] as $req) {
        if (empty($manifest[$req])) return ['ok' => false, 'msg' => "theme.json: brak wymaganego pola '{$req}'."];
    }
    $slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)$manifest['slug']));
    if ($slug === '') return ['ok' => false, 'msg' => 'theme.json: nieprawidłowy slug.'];
    if (in_array($slug, ['classic','modern'], true)) {
        return ['ok' => false, 'msg' => "Slug '{$slug}' jest zarezerwowany dla wbudowanego motywu."];
    }

    $prefix = '';
    if (strpos($manifestPath, '/') !== false) {
        $prefix = explode('/', $manifestPath)[0] . '/';
    }

    // Walidacja: musi mieć style.css w tym samym katalogu co theme.json
    $styleCss = $prefix . 'style.css';
    if ($zip->locateName($styleCss) === false) {
        return ['ok' => false, 'msg' => "W archiwum brakuje style.css obok theme.json (oczekiwane: {$styleCss})."];
    }

    $target = $themesDir . '/' . $slug;
    if (is_dir($target)) {
        // Backup istniejącego
        $backup = $target . '.bak.' . date('YmdHis');
        @rename($target, $backup);
    }
    @mkdir($target, 0775, true);

    // Whitelista bezpiecznych rozszerzeń
    $allowed = ['json','css','js','png','jpg','jpeg','gif','svg','webp','woff','woff2','ttf','eot','otf','txt','md','php','html'];
    $extracted = 0; $skipped = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $rel = $stat['name'];
        if ($prefix && !str_starts_with($rel, $prefix)) continue;
        $relInTheme = $prefix ? substr($rel, strlen($prefix)) : $rel;
        if ($relInTheme === '' || str_ends_with($relInTheme, '/')) continue;

        // Bezpieczeństwo: bez ".." i absolutnych ścieżek
        if (str_contains($relInTheme, '..') || str_starts_with($relInTheme, '/')) {
            $skipped[] = $relInTheme . ' (niedozwolona ścieżka)';
            continue;
        }
        $ext = strtolower(pathinfo($relInTheme, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $skipped[] = $relInTheme . ' (rozszerzenie .' . $ext . ')';
            continue;
        }

        $dest = $target . '/' . $relInTheme;
        @mkdir(dirname($dest), 0775, true);
        $stream = $zip->getStream($rel);
        if ($stream) {
            $content = stream_get_contents($stream);
            fclose($stream);
            file_put_contents($dest, $content);
            $extracted++;
        }
    }

    $msg = "Zainstalowano motyw „{$manifest['name']}" . '” (' . $extracted . ' plików).';
    if ($skipped) $msg .= ' Pominięto: ' . implode(', ', array_slice($skipped, 0, 5)) . (count($skipped) > 5 ? '…' : '');
    return ['ok' => true, 'msg' => $msg];
}

$themes = listThemes();
$active = setting('active_theme', 'classic');
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Motywy</h1>
        <p class="admin-page__sub">Aktywny: <strong><?= e($active) ?></strong></p>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <section class="themes-grid">
        <?php foreach ($themes as $t): ?>
            <article class="theme-card <?= $t['slug'] === $active ? 'theme-card--active' : '' ?>">
                <div class="theme-card__screenshot" style="background:<?= e($t['preview_color'] ?? '#ddd') ?>">
                    <?php if (!empty($t['_screenshot_url'])): ?>
                        <img src="<?= e($t['_screenshot_url']) ?>" alt="<?= e($t['name']) ?>">
                    <?php else: ?>
                        <span class="theme-card__no-shot"><?= e(mb_substr($t['name'], 0, 2)) ?></span>
                    <?php endif; ?>
                </div>
                <div class="theme-card__body">
                    <h2 class="theme-card__name">
                        <?= e($t['name']) ?>
                        <?php if ($t['slug'] === $active): ?>
                            <span class="theme-card__badge">Aktywny</span>
                        <?php endif; ?>
                    </h2>
                    <p class="theme-card__meta">
                        v<?= e($t['version']) ?>
                        <?php if (!empty($t['author'])): ?> · <?= e($t['author']) ?><?php endif; ?>
                    </p>
                    <?php if (!empty($t['description'])): ?>
                        <p class="theme-card__desc"><?= e($t['description']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($t['tags'])): ?>
                        <div class="theme-card__tags">
                            <?php foreach ((array)$t['tags'] as $tag): ?>
                                <span class="theme-card__tag"><?= e($tag) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="theme-card__actions">
                        <?php if ($t['slug'] !== $active): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="slug" value="<?= e($t['slug']) ?>">
                                <button type="submit" class="btn btn--primary">Aktywuj</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!in_array($t['slug'], ['classic','modern'], true) && $t['slug'] !== $active): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="slug" value="<?= e($t['slug']) ?>">
                                <button type="submit" class="link-btn link-btn--danger" onclick="return confirm('Usunąć motyw „<?= e($t['name']) ?>\"?')">Usuń</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="settings-card">
        <h2>Wgraj nowy motyw (.zip)</h2>
        <form method="post" enctype="multipart/form-data" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="upload">
            <label>Plik ZIP z motywem
                <input type="file" name="theme_zip" accept=".zip,application/zip" required>
                <small class="hint">Archiwum może zawierać motyw bezpośrednio (theme.json w root) lub w jednym folderze (theme.json w folderze głównym).</small>
            </label>
            <button type="submit" class="btn btn--primary">Wgraj i zainstaluj</button>
        </form>
        <?php if (!class_exists('ZipArchive')): ?>
            <p class="flash flash--error">⚠ Brak rozszerzenia PHP <code>ZipArchive</code> — upload .zip nie będzie działać. Zainstaluj <code>php-zip</code> na hostingu.</p>
        <?php endif; ?>
    </section>

    <section class="settings-card settings-card--wide">
        <h2>📋 Standard motywu — specyfikacja dla AI</h2>
        <p class="hint">Skopiuj poniższy prompt i wklej do AI (Claude, ChatGPT, itp.) wraz z prośbą o stworzenie nowego motywu. AI dostarczy spakowany .zip gotowy do wgrania powyżej.</p>
        <details class="theme-spec-details" open>
            <summary><strong>📄 Zwiń / rozwiń prompt dla AI</strong></summary>
            <pre class="theme-spec-prompt" id="theme-prompt"><?= e(themeAiPrompt()) ?></pre>
            <button type="button" class="btn" onclick="copyPrompt()">📋 Skopiuj do schowka</button>
        </details>
        <script>
        function copyPrompt() {
            const text = document.getElementById('theme-prompt').textContent;
            navigator.clipboard.writeText(text).then(() => {
                const btn = event.target;
                const orig = btn.textContent;
                btn.textContent = '✓ Skopiowano';
                setTimeout(() => btn.textContent = orig, 2000);
            });
        }
        </script>
    </section>
</div>

<?php
function themeAiPrompt(): string {
    return <<<'PROMPT'
# Zadanie: Stwórz motyw dla CMS "The Daily Signal"

Stwórz kompletny motyw graficzny w formacie .zip, zgodny z poniższą specyfikacją. Motyw NIE modyfikuje HTML-a ani logiki PHP — tylko warstwę wizualną przez CSS.

## Struktura archiwum

Archiwum .zip musi zawierać (na poziomie root lub w jednym folderze głównym):

```
moj-motyw/
├── theme.json          (WYMAGANY — manifest motywu)
├── style.css           (WYMAGANY — główny stylesheet)
├── screenshot.svg      (zalecany — podgląd 400×300 px, SVG lub PNG)
└── assets/             (opcjonalnie — fonty, obrazy, dodatkowe CSS)
```

## theme.json — manifest

```json
{
    "name": "Nazwa Motywu",
    "slug": "nazwa-motywu",
    "version": "1.0.0",
    "author": "Twoje imię / nazwa",
    "description": "Krótki opis stylu (max 240 znaków): paleta, typografia, charakter.",
    "tags": ["minimal", "dark", "magazine"],
    "supports": ["dark-mode", "responsive", "hamburger-menu", "tag-cloud"],
    "preview_color": "#1a1a2e",
    "screenshot": "screenshot.svg",
    "fonts": ["Inter", "Playfair Display"],
    "requires_php": "8.0",
    "tested_with": "1.0"
}
```

Pola wymagane: `name`, `slug`, `version`. Slug musi być URL-safe (a-z, 0-9, myślniki).
Zarezerwowane slug-i: `classic`, `modern`.

## Wymagane klasy CSS (struktura HTML — NIE zmieniaj jej w kodzie, tylko stylizuj)

CMS renderuje stronę z poniższymi klasami. Twój CSS musi nadać sensowny wygląd KAŻDEJ z nich:

### Layout główny
- `.top-notice`, `.top-notice__icon`, `.top-notice__text` — pasek powyżej nagłówka
- `.masthead`, `.masthead__top`, `.masthead__date`, `.masthead__edition`, `.masthead__title`, `.masthead__logo`, `.masthead__logo-img`, `.masthead__tagline` — nagłówek serwisu
- `.masthead__nav` — nawigacja kategorii (linki <a>)
- `.masthead__menu-toggle`, `.masthead__menu-bars` — hamburger toggle (display:none na desktop, display:flex na ≤720px). MUSI zmieniać wygląd dla `[aria-expanded="true"]`
- `.skip-link` — link "Przejdź do treści" (visible-only-on-focus)
- `.container` — główny wrapper treści
- `.breadcrumbs` — okruszki nawigacyjne
- `.section-title`, `.section-title__count` — nagłówki sekcji

### Strona główna (lista artykułów)
- `.lead-article`, `.lead-article__link`, `.lead-article__image`, `.lead-article__body`, `.lead-article__title`, `.lead-article__subtitle`, `.lead-article__excerpt` — wyróżniony pierwszy artykuł
- `.grid` — kontener kart (grid auto-fill, minmax 280–300px)
- `.card`, `.card__link`, `.card__image`, `.card__body`, `.card__title`, `.card__excerpt` — pojedyncza karta artykułu
- `.kicker` — etykieta kategorii nad tytułem
- `.meta` — metadane (data, czas czytania)
- `.pagination` — paginacja (linki <a>, aktywny ma klasę `.is-active`)
- `.empty` — placeholder pustej listy

### Artykuł (pojedynczy)
- `.article`, `.article__header`, `.article__title`, `.article__subtitle`, `.article__meta`, `.article__hero` (z `<figcaption>`), `.article__content` — treść artykułu
- `.article__content h2`, `.article__content h3`, `.article__content p`, `.article__content ul`, `.article__content ol`, `.article__content blockquote`, `.article__content code`, `.article__content pre`, `.article__content a` — wewnątrz treści (HTML z edytora)
- `.source-attribution` — stopka źródła pod artykułem
- `.article__tags`, `.article__tags-label` — sekcja tagów pod artykułem
- `.tag-list`, `.tag-chip` — lista tagów (chipy)
- `.related` — sekcja powiązanych artykułów

### Formularz kontaktowy
- `.contact-form`, `.contact-form__captcha`, `.contact-success`, `.contact-error`
- `.contact-form input[type=text|email|number]`, `.contact-form textarea`, `.contact-form .btn`

### Stopka serwisu
- `.site-footer`, `.site-footer__inner`, `.site-footer__col`, `.site-footer__title`, `.site-footer__copy`
- `.site-footer__links li a` — lista linków serwisu
- `.site-footer__categories li a`, `.site-footer__cat-name`, `.site-footer__cat-count` — kategorie z licznikami
- `.footer-tag-cloud` — chmura tagów (lista)
- `.footer-tag-chip` + 5 wariantów rozmiaru: `.footer-tag-chip--s1` (najmniejszy) do `.footer-tag-chip--s5` (największy) — skalowanie wg popularności

## Wymagania techniczne

1. **CSS variables na początku** — zdefiniuj `:root { --ink: ...; --paper: ...; --accent: ...; }` itd. dla łatwej modyfikacji
2. **Dark mode** — dodaj `@media (prefers-color-scheme: dark) { :root { ... } }` z odwróconą paletą
3. **Responsywność** — breakpointy:
   - `≤600px` — mobile
   - `601–900px` — tablet
   - `>900px` — desktop
4. **Hamburger** — na `≤720px` ukryj nav (`.masthead__nav { display: none }`) i pokaż toggle. Otwarcie przez JS dodaje klasę `.is-open`
5. **Reduced motion** — `@media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition: none !important; animation: none !important; } }`
6. **Print** — ukryj nav, footer, pagination
7. **A11y** — focus visible na input/select/button (`:focus { outline: ... }`)

## Czego NIE robić

- ❌ Nie modyfikuj HTML (nie ma takiej możliwości — szablon jest globalny)
- ❌ Nie ładuj zewnętrznych CSS-ów przez `@import` (wolno — wkleją się ładnie do <head>)
- ❌ Nie używaj `!important` poza absolutnie niezbędnymi przypadkami (dark mode override, print)
- ❌ Nie używaj JS w motywie (motyw to tylko CSS + opcjonalne assety)
- ❌ Nie wstawiaj plików .php — będą zignorowane
- ❌ Nie umieszczaj malware / iframe / inline JS

## Wzorzec do naśladowania

Sprawdź dwa wbudowane motywy:
- `themes/classic/style.css` — czarno-biały, Playfair + Source Serif, klasyczna gazeta
- `themes/modern/style.css` — Inter sans-serif, akcent czerwony, miękkie cienie, karty

## Po skończeniu

Spakuj cały folder do .zip i wgraj przez panel CMS → Motywy → "Wgraj nowy motyw".
Zainstalowany motyw pojawi się na liście. Kliknij "Aktywuj" by przełączyć wygląd całej strony.

PROMPT;
}

require __DIR__ . '/_footer.php';
