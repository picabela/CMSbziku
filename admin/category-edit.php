<?php
$adminTitle = 'Edycja kategorii';
require __DIR__ . '/_layout.php';

$id = (int)($_GET['id'] ?? 0);
$cat = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    $cat = $stmt->fetch();
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Nieprawidłowy CSRF.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $slugInput = trim($_POST['slug'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($name === '') $errors[] = 'Nazwa wymagana.';
        $slug = $slugInput !== '' ? slugify($slugInput) : slugify($name);

        if (!$errors) {
            $pdo = db();
            try {
                if ($cat) {
                    $oldName = $cat['name'];
                    $stmt = $pdo->prepare('UPDATE categories SET name=?, slug=?, description=?, sort_order=? WHERE id=?');
                    $stmt->execute([$name, $slug, $description, $sortOrder, $cat['id']]);
                    // Synchronizuj nazwę kategorii w istniejących artykułach
                    if ($oldName !== $name) {
                        $pdo->prepare('UPDATE posts SET category=? WHERE category=?')->execute([$name, $oldName]);
                    }
                } else {
                    $pdo->prepare('INSERT INTO categories (name, slug, description, sort_order) VALUES (?, ?, ?, ?)')
                        ->execute([$name, $slug, $description, $sortOrder]);
                }
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Kategoria zapisana.'];
                header('Location: categories.php'); exit;
            } catch (PDOException $e) {
                $errors[] = 'Konflikt nazwy lub sluga: ' . $e->getMessage();
            }
        }
    }
}

$current = $cat ?: ['name'=>'', 'slug'=>'', 'description'=>'', 'sort_order'=>0];
?>
<div class="admin-page admin-page--editor">
    <div class="admin-page__head">
        <h1><?= $cat ? 'Edytuj kategorię' : 'Nowa kategoria' ?></h1>
        <a href="categories.php">← Wróć</a>
    </div>

    <?php foreach ($errors as $e): ?><div class="flash flash--error"><?= e($e) ?></div><?php endforeach; ?>

    <form method="post" class="editor-form">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <div class="editor-form__grid">
            <div class="editor-form__main">
                <label>Nazwa<input type="text" name="name" required value="<?= e($current['name']) ?>" placeholder="np. SEO, GEO, ADS"></label>
                <label>Slug (URL)
                    <input type="text" name="slug" value="<?= e($current['slug']) ?>" placeholder="auto z nazwy">
                    <small class="hint">Używany w URL: /kategoria/<strong>slug</strong></small>
                </label>
                <label>Opis dla AI (krótki — pomaga modelowi klasyfikować)
                    <textarea name="description" rows="4" placeholder="np. Reklamy płatne: Google Ads, Meta Ads, programmatic, retargeting."><?= e($current['description']) ?></textarea>
                </label>
                <label>Kolejność (mniejsza = wyżej w menu)
                    <input type="number" name="sort_order" value="<?= (int)$current['sort_order'] ?>">
                </label>
            </div>
            <aside class="editor-form__side">
                <fieldset>
                    <legend>Akcja</legend>
                    <button type="submit" class="btn btn--primary btn--block">Zapisz kategorię</button>
                    <p class="hint" style="margin-top:1rem">Zmiana nazwy zaktualizuje też powiązane artykuły.</p>
                </fieldset>
            </aside>
        </div>
    </form>
</div>
<?php require __DIR__ . '/_footer.php';
