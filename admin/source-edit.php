<?php
$adminTitle = 'Edycja źródła';
require __DIR__ . '/_layout.php';

$id = (int)($_GET['id'] ?? 0);
$source = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM sources WHERE id = ?');
    $stmt->execute([$id]);
    $source = $stmt->fetch();
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Nieprawidłowy CSRF.';
    } else {
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'feed_url' => trim($_POST['feed_url'] ?? ''),
            'site_url' => trim($_POST['site_url'] ?? ''),
            'category' => trim($_POST['category'] ?? ''),
            'language' => trim($_POST['language'] ?? 'en'),
            'source_type' => in_array($_POST['source_type'] ?? '', ['rss','html'], true) ? $_POST['source_type'] : 'rss',
            'link_selector' => trim($_POST['link_selector'] ?? '') ?: null,
            'date_selector' => trim($_POST['date_selector'] ?? '') ?: null,
            'content_selector' => trim($_POST['content_selector'] ?? '') ?: null,
            'max_items_per_run' => max(1, (int)($_POST['max_items_per_run'] ?? 2)),
            'max_age_days' => $_POST['max_age_days'] === '' ? null : max(1, (int)$_POST['max_age_days']),
            'enabled' => isset($_POST['enabled']) ? 1 : 0,
        ];
        $ap = $_POST['auto_publish'] ?? 'inherit';
        $data['auto_publish'] = $ap === 'yes' ? 1 : ($ap === 'no' ? 0 : null);

        if ($data['name'] === '') $errors[] = 'Nazwa wymagana.';
        if (!filter_var($data['feed_url'], FILTER_VALIDATE_URL)) $errors[] = 'URL musi być poprawnym adresem.';

        if (!$errors) {
            $pdo = db();
            if ($source) {
                $stmt = $pdo->prepare('UPDATE sources SET name=:name, feed_url=:feed_url, site_url=:site_url, category=:category, language=:language, source_type=:source_type, link_selector=:link_selector, date_selector=:date_selector, content_selector=:content_selector, max_items_per_run=:mx, max_age_days=:max_age_days, auto_publish=:ap, enabled=:enabled WHERE id=:id');
                $data['id'] = $source['id'];
                $data['mx'] = $data['max_items_per_run']; unset($data['max_items_per_run']);
                $data['ap'] = $data['auto_publish']; unset($data['auto_publish']);
                $stmt->execute($data);
            } else {
                $stmt = $pdo->prepare('INSERT INTO sources (name, feed_url, site_url, category, language, source_type, link_selector, date_selector, content_selector, max_items_per_run, max_age_days, auto_publish, enabled) VALUES (:name, :feed_url, :site_url, :category, :language, :source_type, :link_selector, :date_selector, :content_selector, :mx, :max_age_days, :ap, :enabled)');
                $data['mx'] = $data['max_items_per_run']; unset($data['max_items_per_run']);
                $data['ap'] = $data['auto_publish']; unset($data['auto_publish']);
                $stmt->execute($data);
            }
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Źródło zapisane.'];
            header('Location: sources.php');
            exit;
        }
    }
}

$current = $source ?: ['name'=>'', 'feed_url'=>'', 'site_url'=>'', 'category'=>'', 'language'=>'en', 'source_type'=>'rss', 'link_selector'=>'', 'date_selector'=>'', 'content_selector'=>'', 'max_items_per_run'=>2, 'max_age_days'=>null, 'auto_publish'=>null, 'enabled'=>1];
$apVal = $current['auto_publish'];
?>
<div class="admin-page admin-page--editor">
    <div class="admin-page__head">
        <h1><?= $source ? 'Edytuj źródło' : 'Nowe źródło' ?></h1>
        <a href="sources.php">← Wróć</a>
    </div>

    <?php foreach ($errors as $e): ?><div class="flash flash--error"><?= e($e) ?></div><?php endforeach; ?>

    <form method="post" class="editor-form">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <div class="editor-form__grid">
            <div class="editor-form__main">
                <label>Nazwa<input type="text" name="name" required value="<?= e($current['name']) ?>"></label>
                <label>Typ źródła
                    <select name="source_type" id="source-type">
                        <option value="rss" <?= ($current['source_type'] ?? 'rss') === 'rss' ? 'selected' : '' ?>>RSS / Atom feed</option>
                        <option value="html" <?= ($current['source_type'] ?? '') === 'html' ? 'selected' : '' ?>>Listing HTML (gdy strona nie ma feedu)</option>
                    </select>
                </label>
                <label>URL feedu (RSS) lub listingu (HTML)
                    <input type="url" name="feed_url" required value="<?= e($current['feed_url']) ?>" placeholder="https://example.com/feed/  lub  https://example.com/blog">
                </label>
                <label>Selektor linków do artykułów (tylko HTML)
                    <input type="text" name="link_selector" value="<?= e($current['link_selector'] ?? '') ?>" placeholder="np. h2.entry-title a  lub XPath //article//h2/a">
                    <small class="hint">Opcjonalny. Domyślnie szuka linków w &lt;article&gt;, &lt;h1-3&gt;. CSS lub XPath.</small>
                </label>
                <label>Strona główna źródła (opcjonalnie)<input type="url" name="site_url" value="<?= e($current['site_url']) ?>" placeholder="https://example.com"></label>

                <fieldset style="margin-top:1rem">
                    <legend>Ręczne wskazanie daty i treści (gdy automat zawodzi)</legend>
                    <label>Selektor daty publikacji (na stronie artykułu)
                        <input type="text" name="date_selector" value="<?= e($current['date_selector'] ?? '') ?>" placeholder="np. time.post-date  lub  .article-meta .date">
                        <small class="hint">Opcjonalny. Gdy ustawiony, ma najwyższy priorytet — data jest brana z tego elementu. CMS odczyta atrybut <code>datetime</code>/<code>content</code> albo tekst elementu (rozumie też polskie daty, np. „9 cze 2026").</small>
                    </label>
                    <label>Selektor treści artykułu (na stronie artykułu)
                        <input type="text" name="content_selector" value="<?= e($current['content_selector'] ?? '') ?>" placeholder="np. div.post-content  lub  article .entry-body">
                        <small class="hint">Opcjonalny. Gdy ustawiony, do streszczenia AI trafia tylko zawartość tego elementu (czystsza treść = lepsze artykuły). Gdy selektor nic nie znajdzie, działa automat.</small>
                    </label>
                    <details style="margin-top:.5rem">
                        <summary style="cursor:pointer;font-weight:600">📖 Jak znaleźć selektor? (krótka instrukcja)</summary>
                        <ol class="hint" style="margin:.5rem 0 0 1.25rem;line-height:1.6">
                            <li>Otwórz <strong>stronę artykułu</strong> (nie listingu) w przeglądarce.</li>
                            <li>Kliknij <strong>prawym przyciskiem</strong> na datę (lub treść) → <strong>„Zbadaj"</strong> / „Inspect".</li>
                            <li>W DevTools zobaczysz podświetlony element, np. <code>&lt;time class="post-date"&gt;9 cze 2026&lt;/time&gt;</code>.</li>
                            <li>Zbuduj selektor z tagu i klasy: <code>time.post-date</code>. Sama klasa też działa: <code>.post-date</code>.</li>
                            <li>Dla treści wskaż kontener całego tekstu, np. <code>div.article-body</code>.</li>
                        </ol>
                        <p class="hint" style="margin:.5rem 0 0">Obsługiwane są proste selektory CSS (tag, <code>.klasa</code>, <code>#id</code>, zagnieżdżenie przez spację) oraz pełny XPath (zaczynający się od <code>/</code> lub <code>(</code>). Ustawienie zapisuje się przy źródle i działa dla wszystkich kolejnych importów.</p>
                    </details>
                </fieldset>
            </div>
            <aside class="editor-form__side">
                <fieldset>
                    <legend>Parametry</legend>
                    <label>Kategoria docelowa<input type="text" name="category" value="<?= e($current['category']) ?>" placeholder="SEO / GEO / ADS / AI"></label>
                    <label>Język źródła<input type="text" name="language" value="<?= e($current['language']) ?>" placeholder="en"></label>
                    <label>Max artykułów na run<input type="number" min="1" max="20" name="max_items_per_run" value="<?= (int)$current['max_items_per_run'] ?>"></label>
                    <label>Max wiek artykułu (dni)
                        <input type="number" min="1" max="365" name="max_age_days" value="<?= $current['max_age_days'] !== null ? (int)$current['max_age_days'] : '' ?>" placeholder="puste = globalny">
                        <small class="hint">Starsze artykuły będą pomijane. Bez daty → pomijane zawsze.</small>
                    </label>
                    <label>Auto-publish dla tego źródła
                        <select name="auto_publish">
                            <option value="inherit" <?= $apVal === null ? 'selected' : '' ?>>Dziedzicz z ustawień globalnych</option>
                            <option value="yes" <?= $apVal === 1 ? 'selected' : '' ?>>Tak — publikuj od razu</option>
                            <option value="no" <?= $apVal === 0 ? 'selected' : '' ?>>Nie — zapisuj jako draft</option>
                        </select>
                    </label>
                    <label class="checkbox"><input type="checkbox" name="enabled" value="1" <?= $current['enabled'] ? 'checked' : '' ?>> Źródło aktywne</label>
                    <button type="submit" class="btn btn--primary btn--block">Zapisz źródło</button>
                </fieldset>
            </aside>
        </div>
    </form>
</div>
<?php require __DIR__ . '/_footer.php';
