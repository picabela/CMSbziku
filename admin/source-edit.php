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
            'max_items_per_run' => max(1, (int)($_POST['max_items_per_run'] ?? 2)),
            'enabled' => isset($_POST['enabled']) ? 1 : 0,
        ];
        $ap = $_POST['auto_publish'] ?? 'inherit';
        $data['auto_publish'] = $ap === 'yes' ? 1 : ($ap === 'no' ? 0 : null);

        if ($data['name'] === '') $errors[] = 'Nazwa wymagana.';
        if (!filter_var($data['feed_url'], FILTER_VALIDATE_URL)) $errors[] = 'Feed URL musi być poprawnym adresem.';

        if (!$errors) {
            $pdo = db();
            if ($source) {
                $stmt = $pdo->prepare('UPDATE sources SET name=:name, feed_url=:feed_url, site_url=:site_url, category=:category, language=:language, max_items_per_run=:mx, auto_publish=:ap, enabled=:enabled WHERE id=:id');
                $data['id'] = $source['id'];
                $data['mx'] = $data['max_items_per_run']; unset($data['max_items_per_run']);
                $data['ap'] = $data['auto_publish']; unset($data['auto_publish']);
                $stmt->execute($data);
            } else {
                $stmt = $pdo->prepare('INSERT INTO sources (name, feed_url, site_url, category, language, max_items_per_run, auto_publish, enabled) VALUES (:name, :feed_url, :site_url, :category, :language, :mx, :ap, :enabled)');
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

$current = $source ?: ['name'=>'', 'feed_url'=>'', 'site_url'=>'', 'category'=>'', 'language'=>'en', 'max_items_per_run'=>2, 'auto_publish'=>null, 'enabled'=>1];
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
                <label>Feed URL (RSS lub Atom)<input type="url" name="feed_url" required value="<?= e($current['feed_url']) ?>" placeholder="https://example.com/feed/"></label>
                <label>Strona główna źródła (opcjonalnie)<input type="url" name="site_url" value="<?= e($current['site_url']) ?>" placeholder="https://example.com"></label>
            </div>
            <aside class="editor-form__side">
                <fieldset>
                    <legend>Parametry</legend>
                    <label>Kategoria docelowa<input type="text" name="category" value="<?= e($current['category']) ?>" placeholder="SEO / GEO / ADS / AI"></label>
                    <label>Język źródła<input type="text" name="language" value="<?= e($current['language']) ?>" placeholder="en"></label>
                    <label>Max artykułów na run<input type="number" min="1" max="20" name="max_items_per_run" value="<?= (int)$current['max_items_per_run'] ?>"></label>
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
