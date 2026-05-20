<?php
$adminTitle = 'Hurtowe dodawanie źródeł';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$result = null;
$prefill = [
    'urls' => '',
    'source_type' => 'rss',
    'category' => '',
    'language' => 'en',
    'max_items_per_run' => 2,
    'max_age_days' => '',
    'enabled' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $prefill['urls'] = (string)($_POST['urls'] ?? '');
    $prefill['source_type'] = in_array($_POST['source_type'] ?? '', ['rss','html'], true) ? $_POST['source_type'] : 'rss';
    $prefill['category'] = trim($_POST['category'] ?? '');
    $prefill['language'] = trim($_POST['language'] ?? 'en') ?: 'en';
    $prefill['max_items_per_run'] = max(1, (int)($_POST['max_items_per_run'] ?? 2));
    $prefill['max_age_days'] = $_POST['max_age_days'] !== '' ? (int)$_POST['max_age_days'] : '';
    $prefill['enabled'] = isset($_POST['enabled']) ? 1 : 0;
    $apVal = $_POST['auto_publish'] ?? 'inherit';
    $autoPublish = $apVal === 'yes' ? 1 : ($apVal === 'no' ? 0 : null);

    $lines = preg_split('/\r\n|\r|\n/', $prefill['urls']);
    $lines = array_filter(array_map('trim', $lines), fn($l) => $l !== '' && $l[0] !== '#');

    $report = ['added' => [], 'skipped' => [], 'errors' => []];
    $pdo = db();
    $stmtCheck = $pdo->prepare('SELECT id FROM sources WHERE feed_url = ?');
    $stmtIns = $pdo->prepare('INSERT INTO sources (name, feed_url, site_url, category, language, source_type, link_selector, max_items_per_run, max_age_days, auto_publish, enabled) VALUES (:name, :feed_url, :site_url, :category, :language, :source_type, NULL, :mx, :max_age_days, :ap, :enabled)');

    foreach ($lines as $url) {
        // Pozwól na format: URL | Nazwa (separator pipe), żeby user mógł nadpisać nazwę
        $name = '';
        if (str_contains($url, '|')) {
            [$url, $name] = array_map('trim', explode('|', $url, 2));
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $report['errors'][] = ['url' => $url, 'reason' => 'Nieprawidłowy URL'];
            continue;
        }
        $stmtCheck->execute([$url]);
        if ($stmtCheck->fetch()) {
            $report['skipped'][] = ['url' => $url, 'reason' => 'Już istnieje w bazie'];
            continue;
        }
        if ($name === '') {
            // Heurystyka: nazwa z domeny (bez "www.", z dużej litery, podział kropek na spacje)
            $host = parse_url($url, PHP_URL_HOST) ?: $url;
            $host = preg_replace('/^www\./', '', $host);
            // Usuń TLD-y i przejdź na Title Case (uproszczone)
            $parts = explode('.', $host);
            if (count($parts) >= 2) array_pop($parts); // usuń tld
            $name = implode(' ', $parts);
            $name = ucwords(str_replace(['-', '_'], ' ', $name));
        }
        try {
            $siteUrl = '';
            if ($prefill['source_type'] === 'rss') {
                // Strona główna = scheme+host
                $p = parse_url($url);
                if ($p) $siteUrl = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
            } else {
                $siteUrl = $url;
            }
            $stmtIns->execute([
                ':name' => mb_substr($name, 0, 100),
                ':feed_url' => $url,
                ':site_url' => $siteUrl,
                ':category' => $prefill['category'],
                ':language' => $prefill['language'],
                ':source_type' => $prefill['source_type'],
                ':mx' => $prefill['max_items_per_run'],
                ':max_age_days' => $prefill['max_age_days'] !== '' ? (int)$prefill['max_age_days'] : null,
                ':ap' => $autoPublish,
                ':enabled' => $prefill['enabled'],
            ]);
            $report['added'][] = ['url' => $url, 'name' => $name, 'id' => (int)$pdo->lastInsertId()];
        } catch (Throwable $e) {
            $report['errors'][] = ['url' => $url, 'reason' => $e->getMessage()];
        }
    }

    if (count($report['added']) === count($lines) && $lines) {
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Dodano ' . count($report['added']) . ' źródeł.'];
        header('Location: sources.php'); exit;
    }
    $result = $report;
}
?>
<div class="admin-page admin-page--editor">
    <div class="admin-page__head">
        <h1>Hurtowe dodawanie źródeł</h1>
        <a href="sources.php">← Wróć do listy</a>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <?php if ($result): ?>
        <div class="flash flash--<?= !empty($result['errors']) ? 'error' : 'success' ?>">
            Dodane: <strong><?= count($result['added']) ?></strong>,
            pominięte (duplikaty): <strong><?= count($result['skipped']) ?></strong>,
            błędy: <strong><?= count($result['errors']) ?></strong>.
        </div>
        <?php foreach (['added' => '✓ Dodane', 'skipped' => '⊘ Pominięte', 'errors' => '⚠ Błędy'] as $key => $label): ?>
            <?php if (!empty($result[$key])): ?>
                <details class="bulk-result" <?= $key === 'errors' ? 'open' : '' ?>>
                    <summary><?= $label ?> (<?= count($result[$key]) ?>)</summary>
                    <ul class="bulk-result__list">
                        <?php foreach ($result[$key] as $row): ?>
                            <li>
                                <code><?= e($row['url']) ?></code>
                                <?php if (!empty($row['name'])): ?> — <strong><?= e($row['name']) ?></strong><?php endif; ?>
                                <?php if (!empty($row['reason'])): ?> <span class="muted">(<?= e($row['reason']) ?>)</span><?php endif; ?>
                                <?php if (!empty($row['id'])): ?> <a href="source-edit.php?id=<?= (int)$row['id'] ?>">Edytuj</a><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <form method="post" class="editor-form">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <div class="editor-form__grid">
            <div class="editor-form__main">
                <label>Lista URL-i (jeden na wiersz)
                    <textarea name="urls" rows="14" placeholder="https://example.com/feed/
https://blog.example.com/rss
# komentarze zaczynające się od # są ignorowane
https://news.example.com/feed | Custom Nazwa Źródła
https://example.org/atom.xml"><?= e($prefill['urls']) ?></textarea>
                    <small class="hint">
                        Dla wszystkich URL-i obowiązują parametry zdefiniowane po prawej.<br>
                        Aby nadpisać nazwę dla konkretnego źródła użyj formatu: <code>URL | Nazwa</code>.<br>
                        Linie zaczynające się od <code>#</code> są ignorowane (komentarze).<br>
                        Domyślna nazwa = wyprowadzona z domeny (np. <code>searchenginejournal.com</code> → „Searchenginejournal").<br>
                        Duplikaty (po <code>feed_url</code>) są pomijane.
                    </small>
                </label>
            </div>
            <aside class="editor-form__side">
                <fieldset>
                    <legend>Wspólne parametry</legend>
                    <label>Typ źródła
                        <select name="source_type">
                            <option value="rss" <?= $prefill['source_type'] === 'rss' ? 'selected' : '' ?>>RSS / Atom feed</option>
                            <option value="html" <?= $prefill['source_type'] === 'html' ? 'selected' : '' ?>>Listing HTML</option>
                        </select>
                        <small class="hint">Dla HTML — selektory zostawiamy puste (heurystyka). Edytuj pojedynczo po dodaniu jeśli potrzeba.</small>
                    </label>
                    <label>Kategoria docelowa
                        <input type="text" name="category" value="<?= e($prefill['category']) ?>" placeholder="SEO / GEO / ADS / AI / puste">
                    </label>
                    <label>Język źródła
                        <input type="text" name="language" value="<?= e($prefill['language']) ?>" placeholder="en">
                    </label>
                    <label>Max artykułów na run
                        <input type="number" min="1" max="20" name="max_items_per_run" value="<?= (int)$prefill['max_items_per_run'] ?>">
                    </label>
                    <label>Max wiek artykułu (dni)
                        <input type="number" min="1" max="365" name="max_age_days" value="<?= e((string)$prefill['max_age_days']) ?>" placeholder="puste = globalny">
                    </label>
                    <label>Auto-publish
                        <select name="auto_publish">
                            <option value="inherit">Dziedzicz z ustawień globalnych</option>
                            <option value="yes">Tak — publikuj od razu</option>
                            <option value="no">Nie — zapisuj jako draft</option>
                        </select>
                    </label>
                    <label class="checkbox"><input type="checkbox" name="enabled" value="1" <?= $prefill['enabled'] ? 'checked' : '' ?>> Wszystkie aktywne od razu</label>

                    <button type="submit" class="btn btn--primary btn--block">Dodaj wszystkie</button>
                </fieldset>
            </aside>
        </div>
    </form>
</div>
<?php require __DIR__ . '/_footer.php';
