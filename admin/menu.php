<?php
$adminTitle = 'Menu';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$location = $_GET['location'] ?? 'header';
if (!in_array($location, ['header', 'footer'], true)) $location = 'header';
$settingKey = $location === 'header' ? 'header_menu_items' : 'footer_menu_items';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $types  = $_POST['type']   ?? [];
        $targs  = $_POST['target'] ?? [];
        $labels = $_POST['label']  ?? [];
        $items = [];
        $count = max(count($types), count($targs), count($labels));
        for ($i = 0; $i < $count; $i++) {
            $type = $types[$i] ?? '';
            if (!in_array($type, ['home','category','tag','page','url'], true)) continue;
            $target = trim((string)($targs[$i] ?? ''));
            $label  = trim((string)($labels[$i] ?? ''));
            if ($type === 'home' || $target !== '') {
                $items[] = ['type' => $type, 'target' => $target, 'label' => $label];
            }
        }
        setSetting($settingKey, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Menu zapisane (' . count($items) . ' elementów).'];
        header('Location: menu.php?location=' . $location); exit;
    }

    if ($action === 'reset') {
        setSetting($settingKey, '');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Menu wyczyszczone — wrócono do domyślnego (kategorie auto).'];
        header('Location: menu.php?location=' . $location); exit;
    }
}

$items = getMenuItems($location);
$pages = getAllPages(true);
$cats = allCategories();
$tags = allTags();
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Edytor menu</h1>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <div class="filter-tabs">
        <a href="?location=header" class="<?= $location === 'header' ? 'is-active' : '' ?>">Menu górne <span class="filter-tabs__count"><?= count(getMenuItems('header')) ?></span></a>
        <a href="?location=footer" class="<?= $location === 'footer' ? 'is-active' : '' ?>">Menu w stopce <span class="filter-tabs__count"><?= count(getMenuItems('footer')) ?></span></a>
    </div>

    <p class="hint">
        <?php if ($location === 'header'): ?>
            Jeśli zostawisz menu puste — pokazujemy automatycznie listę wszystkich kategorii (jak dotąd).
        <?php else: ?>
            Jeśli zostawisz menu w stopce puste — pokazujemy domyślne linki (Kontakt, Mapa strony, RSS, Panel).
        <?php endif; ?>
    </p>

    <form method="post" id="menu-form" class="settings-card settings-card--wide">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">

        <h2>Elementy menu (<?= $location === 'header' ? 'górnego' : 'w stopce' ?>)</h2>

        <table class="admin-table menu-items">
            <thead>
                <tr><th style="width:40px">#</th><th>Typ</th><th>Cel</th><th>Etykieta (override)</th><th style="width:50px"></th></tr>
            </thead>
            <tbody id="menu-rows">
                <?php foreach ($items as $i => $item): ?>
                    <tr class="menu-row">
                        <td class="menu-row__handle"><?= $i + 1 ?></td>
                        <td>
                            <select name="type[]" class="menu-type">
                                <option value="home"     <?= $item['type'] === 'home' ? 'selected' : '' ?>>Strona główna</option>
                                <option value="category" <?= $item['type'] === 'category' ? 'selected' : '' ?>>Kategoria</option>
                                <option value="tag"      <?= $item['type'] === 'tag' ? 'selected' : '' ?>>Tag</option>
                                <option value="page"     <?= $item['type'] === 'page' ? 'selected' : '' ?>>Strona statyczna</option>
                                <option value="url"      <?= $item['type'] === 'url' ? 'selected' : '' ?>>Własny URL</option>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="target[]" value="<?= e($item['target'] ?? '') ?>" list="menu-targets" placeholder="(nazwa kategorii / slug tagu / slug strony / URL)">
                        </td>
                        <td>
                            <input type="text" name="label[]" value="<?= e($item['label'] ?? '') ?>" placeholder="(opcjonalnie — nadpisuje nazwę)">
                        </td>
                        <td><button type="button" class="link-btn link-btn--danger menu-row__remove">×</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <datalist id="menu-targets">
            <?php foreach ($cats as $c): ?><option value="<?= e($c['name']) ?>" label="Kategoria"></option><?php endforeach; ?>
            <?php foreach ($tags as $t): ?><option value="<?= e($t['slug']) ?>" label="Tag"></option><?php endforeach; ?>
            <?php foreach ($pages as $p): ?><option value="<?= e($p['slug']) ?>" label="Strona"></option><?php endforeach; ?>
        </datalist>

        <div class="menu-actions">
            <button type="button" class="btn" id="add-row">+ Dodaj element</button>
            <button type="submit" class="btn btn--primary">Zapisz menu</button>
            <button type="submit" name="action" value="reset" class="link-btn link-btn--danger" formnovalidate onclick="return confirm('Wyczyścić menu? Powrót do domyślnego.')">Wyczyść menu</button>
        </div>

        <details class="menu-help" style="margin-top:1.5rem">
            <summary><strong>📘 Jak ustawić cel (pole „Cel")</strong></summary>
            <ul class="menu-help__list">
                <li><strong>Strona główna</strong> — cel zostaw pusty</li>
                <li><strong>Kategoria</strong> — wpisz <strong>nazwę kategorii</strong> (np. <code>SEO</code>, <code>Aktualności</code>)</li>
                <li><strong>Tag</strong> — wpisz <strong>slug tagu</strong> (np. <code>google</code>, <code>chatgpt</code>)</li>
                <li><strong>Strona statyczna</strong> — wpisz <strong>slug strony</strong> (np. <code>o-nas</code>, <code>regulamin</code>)</li>
                <li><strong>Własny URL</strong> — wpisz pełny URL (np. <code>https://example.com</code>)</li>
            </ul>
        </details>
    </form>
</div>

<template id="menu-row-template">
    <tr class="menu-row">
        <td class="menu-row__handle">·</td>
        <td>
            <select name="type[]" class="menu-type">
                <option value="home">Strona główna</option>
                <option value="category" selected>Kategoria</option>
                <option value="tag">Tag</option>
                <option value="page">Strona statyczna</option>
                <option value="url">Własny URL</option>
            </select>
        </td>
        <td><input type="text" name="target[]" list="menu-targets" placeholder="(nazwa / slug / URL)"></td>
        <td><input type="text" name="label[]" placeholder="(opcjonalnie)"></td>
        <td><button type="button" class="link-btn link-btn--danger menu-row__remove">×</button></td>
    </tr>
</template>

<script>
(function(){
    const rows = document.getElementById('menu-rows');
    const tpl = document.getElementById('menu-row-template');

    function renumber() {
        rows.querySelectorAll('.menu-row__handle').forEach((h, i) => h.textContent = i + 1);
    }
    function bindRemove(btn) {
        btn.addEventListener('click', () => {
            btn.closest('tr').remove();
            renumber();
        });
    }

    document.getElementById('add-row').addEventListener('click', () => {
        const clone = tpl.content.cloneNode(true);
        rows.appendChild(clone);
        const newRow = rows.lastElementChild;
        bindRemove(newRow.querySelector('.menu-row__remove'));
        renumber();
    });

    rows.querySelectorAll('.menu-row__remove').forEach(bindRemove);
})();
</script>
<?php require __DIR__ . '/_footer.php';
