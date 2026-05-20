<?php
$adminTitle = 'Tagi';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'rename_label') {
        $label = trim($_POST['tag_label'] ?? 'Tagi');
        if ($label === '') $label = 'Tagi';
        setSetting('tag_label', $label);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Etykieta zaktualizowana.'];
        header('Location: tags.php'); exit;
    }

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $id = findOrCreateTag($name);
            $_SESSION['flash'] = ['type' => $id ? 'success' : 'error', 'msg' => $id ? 'Tag dodany.' : 'Nie udało się dodać tagu.'];
        }
        header('Location: tags.php'); exit;
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id && $name !== '') {
            $slug = slugify($name);
            try {
                $pdo->prepare('UPDATE tags SET name = ?, slug = ? WHERE id = ?')->execute([$name, $slug, $id]);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Tag zaktualizowany.'];
            } catch (Throwable $e) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Konflikt: tag o tej nazwie/slug już istnieje.'];
            }
        }
        header('Location: tags.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Tag usunięty.'];
        }
        header('Location: tags.php'); exit;
    }

    if ($action === 'bulk') {
        $ids = bulkIds($_POST['ids'] ?? []);
        $bulk = $_POST['bulk_action'] ?? '';
        if ($ids && $bulk === 'delete') {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM tags WHERE id IN ($placeholders)")->execute($ids);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => count($ids) . ' tag(ów) usuniętych.'];
        }
        header('Location: tags.php'); exit;
    }

    if ($action === 'bulk_add') {
        $lines = preg_split('/\r\n|\r|\n/', $_POST['bulk_input'] ?? '');
        $added = 0; $skipped = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $id = findOrCreateTag($line);
            if ($id) $added++; else $skipped++;
        }
        refreshTagUsage();
        $_SESSION['flash'] = ['type' => $added ? 'success' : 'error', 'msg' => "Dodano: {$added}, pominięto (duplikaty/błędy): {$skipped}."];
        header('Location: tags.php'); exit;
    }
}

refreshTagUsage();
$tags = allTags();
$label = tagLabel();
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1><?= e($label) ?></h1>
        <div>
            <a href="auto.php" class="btn">← Auto-import</a>
        </div>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <section class="settings-card">
        <h2>Etykieta sekcji</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="rename_label">
            <label>Nazwa wyświetlana w panelu i pod artykułami (domyślnie „Tagi")
                <input type="text" name="tag_label" value="<?= e($label) ?>" maxlength="40">
            </label>
            <button class="btn btn--primary" type="submit">Zapisz etykietę</button>
        </form>
    </section>

    <section class="settings-card">
        <h2>Dodaj nowy tag</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="add">
            <label>Nazwa tagu (firma, marka, produkt)
                <input type="text" name="name" required maxlength="60" placeholder="np. Google, Perplexity, ChatGPT">
            </label>
            <button class="btn btn--primary" type="submit">Dodaj</button>
        </form>
    </section>

    <details class="settings-card">
        <summary><strong>+ Hurtowe dodawanie tagów</strong> <span class="muted">(jeden na wiersz)</span></summary>
        <form method="post" class="settings-form" style="margin-top:1rem">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="bulk_add">
            <label>Lista (jeden tag na wiersz)
                <textarea name="bulk_input" rows="10" placeholder="Google&#10;Perplexity&#10;ChatGPT&#10;Anthropic&#10;# komentarze ignorowane"></textarea>
            </label>
            <button type="submit" class="btn btn--primary">Dodaj wszystkie</button>
        </form>
    </details>

    <?php if (empty($tags)): ?>
        <p class="empty">Brak tagów. AI dodaje je automatycznie przy każdym imporcie albo dodaj ręcznie powyżej.</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="bulk">
            <div class="bulk-bar">
                <label class="bulk-bar__select-all"><input type="checkbox" id="select-all"> zaznacz wszystkie</label>
                <select name="bulk_action" required>
                    <option value="">— akcja —</option>
                    <option value="delete">Usuń</option>
                </select>
                <button type="submit" class="btn" onclick="return confirmBulk(this.form)">Wykonaj</button>
            </div>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="admin-table__check"></th>
                        <th>Nazwa</th>
                        <th>Slug</th>
                        <th>Użycia</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tags as $t): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?= (int)$t['id'] ?>" class="bulk-check"></td>
                            <td>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                    <input type="text" name="name" value="<?= e($t['name']) ?>" maxlength="60">
                                    <button class="link-btn" type="submit">Zapisz</button>
                                </form>
                            </td>
                            <td><code><?= e($t['slug']) ?></code></td>
                            <td><?= (int)$t['usage_count'] ?></td>
                            <td class="admin-table__actions">
                                <a href="<?= e(tagUrl($t['slug'])) ?>" target="_blank" rel="noopener">Zobacz</a>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                    <button class="link-btn link-btn--danger" type="submit" onclick="return confirm('Usunąć tag „<?= e($t['name']) ?>\"?')">Usuń</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
        <script>
        (function(){
            const all = document.getElementById('select-all');
            const checks = document.querySelectorAll('.bulk-check');
            all.addEventListener('change', () => { checks.forEach(c => c.checked = all.checked); });
            window.confirmBulk = function(form) {
                const action = form.bulk_action.value;
                const ids = form.querySelectorAll('.bulk-check:checked').length;
                if (!action) { alert('Wybierz akcję.'); return false; }
                if (!ids) { alert('Zaznacz przynajmniej jeden tag.'); return false; }
                if (action === 'delete') return confirm('Usunąć ' + ids + ' tagów?');
                return true;
            };
        })();
        </script>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php';
