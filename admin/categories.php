<?php
$adminTitle = 'Kategorie';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['bulk_action'] ?? $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        setSetting('max_categories_per_post', max(1, (int)($_POST['max_categories_per_post'] ?? 2)));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Ustawienia kategorii zapisane.'];
        header('Location: categories.php'); exit;
    }
    $pdo = db();

    if ($action === 'delete_single') {
        $id = (int)$_POST['id'];
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Kategoria usunięta. Artykuły zachowują nazwę kategorii w tabeli posts.'];
        header('Location: categories.php'); exit;
    }

    $ids = bulkIds($_POST['ids'] ?? []);
    if ($ids && $action === 'delete') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM categories WHERE id IN ($placeholders)")->execute($ids);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => count($ids) . ' kategorii usuniętych.'];
        header('Location: categories.php'); exit;
    }

    if ($action === 'bulk_add') {
        $lines = preg_split('/\r\n|\r|\n/', $_POST['bulk_input'] ?? '');
        $added = 0; $skipped = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            // Format: Nazwa | opis (opcjonalny)
            $name = $line; $desc = '';
            if (str_contains($line, '|')) [$name, $desc] = array_map('trim', explode('|', $line, 2));
            if ($name === '' || mb_strlen($name) > 80) { $skipped++; continue; }
            $slug = slugify($name);
            $check = $pdo->prepare('SELECT id FROM categories WHERE slug = ? OR name = ?');
            $check->execute([$slug, $name]);
            if ($check->fetch()) { $skipped++; continue; }
            try {
                $pdo->prepare('INSERT INTO categories (name, slug, description, sort_order) VALUES (?, ?, ?, ?)')
                    ->execute([$name, $slug, $desc, 1000]);
                $added++;
            } catch (Throwable $e) {
                $skipped++;
            }
        }
        $_SESSION['flash'] = ['type' => $added ? 'success' : 'error', 'msg' => "Dodano: {$added}, pominięto (duplikaty/błędy): {$skipped}."];
        header('Location: categories.php'); exit;
    }
}

$cats = db()->query('SELECT c.*, (SELECT COUNT(*) FROM posts p WHERE p.category = c.name) AS post_count FROM categories c ORDER BY sort_order, name')->fetchAll();
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Kategorie</h1>
        <a href="category-edit.php" class="btn btn--primary">+ Nowa kategoria</a>
    </div>
    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
    <p class="hint">Kategorie definiują, gdzie AI może umieścić wygenerowany artykuł. Opis pomaga modelowi trafniej klasyfikować.</p>

    <section class="settings-card" style="margin-bottom:1.5rem">
        <h2>Ustawienia kategorii</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_settings">
            <label>Maksymalna liczba kategorii na artykuł
                <input type="number" name="max_categories_per_post" min="1" max="10"
                       value="<?= e(setting('max_categories_per_post', '2')) ?>"
                       style="width:80px">
            </label>
            <p class="hint">Domyślnie 2. Pierwsza zaznaczona (kategoria główna) decyduje o adresie URL i filtrowaniu. Pozostałe to kategorie dodatkowe — artykuł pojawia się też na ich stronach.</p>
            <button type="submit" class="btn btn--primary">Zapisz ustawienia</button>
        </form>
    </section>

    <details class="settings-card">
        <summary><strong>+ Hurtowe dodawanie kategorii</strong> <span class="muted">(jedna na wiersz)</span></summary>
        <form method="post" class="settings-form" style="margin-top:1rem">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="bulk_add">
            <label>Lista (jedna na wiersz, opcjonalnie <code>Nazwa | opis</code>)
                <textarea name="bulk_input" rows="8" placeholder="Polityka | Wiadomości polityczne&#10;Sport&#10;Technologia | Newsy z branży IT&#10;# komentarze ignorowane"></textarea>
            </label>
            <button type="submit" class="btn btn--primary">Dodaj wszystkie</button>
        </form>
    </details>

    <?php if (empty($cats)): ?>
        <p class="empty">Brak kategorii. <a href="category-edit.php">Dodaj pierwszą</a>.</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <div class="bulk-bar">
                <label class="bulk-bar__select-all"><input type="checkbox" id="select-all"> zaznacz wszystkie</label>
                <select name="bulk_action" required>
                    <option value="">— akcja zbiorcza —</option>
                    <option value="delete">Usuń</option>
                </select>
                <button type="submit" class="btn" onclick="return confirmBulk(this.form)">Wykonaj</button>
            </div>
            <table class="admin-table">
                <thead>
                    <tr><th class="admin-table__check"></th><th>Nazwa</th><th>Slug</th><th>Opis (dla AI)</th><th>Kolejność</th><th>Artykuły</th><th>Akcje</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($cats as $c): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>" class="bulk-check"></td>
                            <td><a href="category-edit.php?id=<?= (int)$c['id'] ?>" class="admin-table__title"><?= e($c['name']) ?></a></td>
                            <td><code><?= e($c['slug']) ?></code></td>
                            <td><?= e($c['description'] ?: '—') ?></td>
                            <td><?= (int)$c['sort_order'] ?></td>
                            <td><?= (int)$c['post_count'] ?></td>
                            <td class="admin-table__actions">
                                <a href="category-edit.php?id=<?= (int)$c['id'] ?>">Edytuj</a>
                                <button type="button" class="link-btn link-btn--danger" onclick="doInline(<?= (int)$c['id'] ?>)">Usuń</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
        <form id="inline-form" method="post" style="display:none">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="delete_single">
            <input type="hidden" name="id" id="inline-id">
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
                if (!ids) { alert('Zaznacz przynajmniej jedną.'); return false; }
                return confirm('Usunąć ' + ids + ' kategorii? Artykuły zachowają nazwę kategorii.');
            };
            window.doInline = function(id) {
                if (!confirm('Usunąć tę kategorię?')) return;
                document.getElementById('inline-id').value = id;
                document.getElementById('inline-form').submit();
            };
        })();
        </script>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php';
