<?php
$adminTitle = 'Strony';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/indexing.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$editId = (int)($_GET['id'] ?? 0);
$page = $editId ? getPageById($editId) : null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM pages WHERE id = ?')->execute([$id]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Strona usunięta.'];
        header('Location: pages.php'); exit;
    }

    if ($action === 'save') {
        $title = trim($_POST['title'] ?? '');
        $slugInput = trim($_POST['slug'] ?? '');
        $content = $_POST['content'] ?? '';
        $metaTitle = trim($_POST['meta_title'] ?? '');
        $metaDesc = trim($_POST['meta_description'] ?? '');
        $metaKw = trim($_POST['meta_keywords'] ?? '');
        $status = $_POST['status'] ?? 'published';
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($title === '') $errors[] = 'Tytuł wymagany.';
        if (!$errors) {
            $slugBase = $slugInput !== '' ? slugify($slugInput) : slugify($title);
            // unikalność slug
            $i = 2; $slug = $slugBase;
            while (true) {
                $check = $pdo->prepare('SELECT id FROM pages WHERE slug = ?' . ($page ? ' AND id != ?' : ''));
                $params = $page ? [$slug, $page['id']] : [$slug];
                $check->execute($params);
                if (!$check->fetch()) break;
                $slug = $slugBase . '-' . $i++;
            }
            if ($page) {
                $stmt = $pdo->prepare('UPDATE pages SET slug=?, title=?, content=?, meta_title=?, meta_description=?, meta_keywords=?, status=?, sort_order=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
                $stmt->execute([$slug, $title, $content, $metaTitle, $metaDesc, $metaKw, $status, $sortOrder, $page['id']]);
                if ($status === 'published' && indexingAutoEnabled()) {
                    indexingOnPublish(absoluteSiteUrl('strona/' . $slug));
                }
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Strona zapisana.'];
            } else {
                $stmt = $pdo->prepare('INSERT INTO pages (slug, title, content, meta_title, meta_description, meta_keywords, status, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$slug, $title, $content, $metaTitle, $metaDesc, $metaKw, $status, $sortOrder]);
                if ($status === 'published' && indexingAutoEnabled()) {
                    indexingOnPublish(absoluteSiteUrl('strona/' . $slug));
                }
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Strona utworzona.'];
            }
            header('Location: pages.php'); exit;
        }
    }
}

$allPages = getAllPages(false);
?>

<?php if ($editId || isset($_GET['new'])): ?>
<!-- Edycja strony -->
<div class="admin-page admin-page--editor">
    <div class="admin-page__head">
        <h1><?= $page ? 'Edytuj stronę' : 'Nowa strona' ?></h1>
        <a href="pages.php">← Wróć do listy</a>
    </div>
    <?php foreach ($errors as $err): ?><div class="flash flash--error"><?= e($err) ?></div><?php endforeach; ?>

    <form method="post" class="editor-form">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <div class="editor-form__grid">
            <div class="editor-form__main">
                <label>Tytuł <input type="text" name="title" required value="<?= e($page['title'] ?? '') ?>"></label>
                <label>Slug (URL) — pusto = auto z tytułu
                    <input type="text" name="slug" value="<?= e($page['slug'] ?? '') ?>" placeholder="np. o-nas">
                </label>
                <div class="editor-form__field">
                    <span class="editor-form__label">Treść strony</span>
                    <div id="editor-toolbar"></div>
                    <div id="editor"><?= $page['content'] ?? '' ?></div>
                    <textarea name="content" id="content-hidden" hidden><?= e($page['content'] ?? '') ?></textarea>
                </div>
            </div>
            <aside class="editor-form__side">
                <fieldset>
                    <legend>Publikacja</legend>
                    <label>Status
                        <select name="status">
                            <option value="published" <?= ($page['status'] ?? '') === 'published' ? 'selected' : '' ?>>Opublikowana</option>
                            <option value="draft" <?= ($page['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Szkic</option>
                        </select>
                    </label>
                    <label>Kolejność <input type="number" name="sort_order" value="<?= (int)($page['sort_order'] ?? 0) ?>"></label>
                    <button type="submit" class="btn btn--primary btn--block">Zapisz stronę</button>
                </fieldset>

                <fieldset>
                    <legend>SEO</legend>
                    <label>Meta title <input type="text" name="meta_title" value="<?= e($page['meta_title'] ?? '') ?>" maxlength="70" placeholder="pusto = tytuł strony"></label>
                    <label>Meta description <textarea name="meta_description" rows="3" maxlength="160"><?= e($page['meta_description'] ?? '') ?></textarea></label>
                    <label>Słowa kluczowe <input type="text" name="meta_keywords" value="<?= e($page['meta_keywords'] ?? '') ?>" placeholder="oddzielone przecinkami"></label>
                </fieldset>

                <?php if ($page): ?>
                    <fieldset>
                        <legend>URL strony</legend>
                        <p><a href="<?= e(pageUrl($page)) ?>" target="_blank" rel="noopener"><?= e(pageUrl($page)) ?></a></p>
                    </fieldset>
                <?php endif; ?>
            </aside>
        </div>
    </form>
</div>

<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script>
(function(){
    const editor = new Quill('#editor', {
        theme: 'snow',
        modules: { toolbar: [[{header:[1,2,3,false]}],['bold','italic','underline','strike'],[{list:'ordered'},{list:'bullet'}],['blockquote','link','image'],[{align:[]}],['clean']] },
        placeholder: 'Treść strony…'
    });
    const hidden = document.getElementById('content-hidden');
    document.querySelector('.editor-form').addEventListener('submit', () => { hidden.value = editor.root.innerHTML; });
})();
</script>

<?php else: ?>
<!-- Lista stron -->
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Strony statyczne</h1>
        <a href="pages.php?new=1" class="btn btn--primary">+ Nowa strona</a>
    </div>
    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
    <p class="hint">Strony statyczne (np. „O nas", „Polityka prywatności", „Regulamin") z własnym SEO. Wstaw je do menu w sekcji <a href="menu.php">Menu</a>.</p>

    <?php if (empty($allPages)): ?>
        <p class="empty">Brak stron. <a href="pages.php?new=1">Stwórz pierwszą</a>.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>Tytuł</th><th>Slug</th><th>Status</th><th>Kolejność</th><th>Aktualizacja</th><th>Akcje</th></tr></thead>
            <tbody>
                <?php foreach ($allPages as $p): ?>
                    <tr>
                        <td><a href="pages.php?id=<?= (int)$p['id'] ?>" class="admin-table__title"><?= e($p['title']) ?></a></td>
                        <td><code>/strona/<?= e($p['slug']) ?></code></td>
                        <td><span class="pill pill--<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
                        <td><?= (int)$p['sort_order'] ?></td>
                        <td><?= e(formatDate($p['updated_at'])) ?></td>
                        <td class="admin-table__actions">
                            <a href="<?= e(pageUrl($p)) ?>" target="_blank" rel="noopener">Zobacz</a>
                            <a href="pages.php?id=<?= (int)$p['id'] ?>">Edytuj</a>
                            <form method="post" style="display:inline" onsubmit="return confirm('Usunąć stronę „<?= e($p['title']) ?>\"?')">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" class="link-btn link-btn--danger">Usuń</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php';
