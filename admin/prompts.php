<?php
$adminTitle = 'Prompty';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/**
 * Rejestr wszystkich promptów CMS — posortowane wg grupy/kolejności.
 * key      => klucz ustawienia
 * default  => nazwa funkcji zwracającej domyślny prompt
 * vars     => lista placeholderów dostępnych w prompcie
 */
$registry = [
    'auto_prompt' => [
        'group'   => 'Auto-import artykułów (AI)',
        'order'   => 1,
        'title'   => 'Prompt redakcyjny (system)',
        'desc'    => 'Definiuje styl, ton i format JSON generowanych artykułów. Wysyłany jako wiadomość „system” do modelu.',
        'default' => 'defaultSystemPrompt',
        'vars'    => [],
        'rows'    => 16,
    ],
    'auto_prompt_category' => [
        'group'   => 'Auto-import artykułów (AI)',
        'order'   => 2,
        'title'   => 'Instrukcja wyboru kategorii',
        'desc'    => 'Dołączana do wiadomości użytkownika. Decyduje, jak model przypisuje kategorię główną i dodatkowe (multi-category).',
        'default' => 'defaultCategoryPrompt',
        'vars'    => ['{categories}' => 'lista dostępnych kategorii', '{max}' => 'maks. kategorii łącznie', '{extra_max}' => 'maks. kategorii dodatkowych'],
        'rows'    => 8,
    ],
    'auto_prompt_tags' => [
        'group'   => 'Auto-import artykułów (AI)',
        'order'   => 3,
        'title'   => 'Instrukcja wyciągania tagów',
        'desc'    => 'Dołączana do wiadomości użytkownika tylko gdy limit tagów > 0 (ustawiany w Auto-import).',
        'default' => 'defaultTagsPrompt',
        'vars'    => ['{max_tags}' => 'maks. liczba tagów'],
        'rows'    => 6,
    ],
    'theme_ai_prompt' => [
        'group'   => 'Generator motywów (AI)',
        'order'   => 1,
        'title'   => 'Specyfikacja motywu dla AI',
        'desc'    => 'Prompt kopiowany przez użytkownika do Claude/ChatGPT, by wygenerować nowy motyw graficzny. Wyświetlany w zakładce Motywy.',
        'default' => 'themeAiPromptDefault',
        'vars'    => [],
        'rows'    => 20,
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $key = $_POST['key'] ?? '';
    $action = $_POST['action'] ?? 'save';
    if (isset($registry[$key])) {
        if ($action === 'reset') {
            setSetting($key, ''); // puste = wraca do wbudowanego domyślnego
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Przywrócono domyślny prompt: ' . $registry[$key]['title'] . '.'];
        } else {
            setSetting($key, (string)($_POST['value'] ?? ''));
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Zapisano prompt: ' . $registry[$key]['title'] . '.'];
        }
    }
    header('Location: prompts.php#' . urlencode($key)); exit;
}

// Sortuj: grupa, potem order
$groups = [];
foreach ($registry as $key => $def) {
    $groups[$def['group']][$key] = $def;
}
foreach ($groups as &$items) {
    uasort($items, fn($a, $b) => $a['order'] <=> $b['order']);
}
unset($items);
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Prompty</h1>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <p class="hint">Wszystkie prompty AI używane w tym CMS w jednym miejscu. Edytuj treść i zapisz, albo przywróć domyślny w każdej chwili. Pozostawienie pustego pola = używany jest wbudowany prompt domyślny.</p>

    <?php foreach ($groups as $groupName => $items): ?>
        <h2 class="section-title" style="margin-top:1.5rem"><?= e($groupName) ?></h2>

        <?php foreach ($items as $key => $def):
            $stored = (string)setting($key, '');
            $effective = trim($stored) !== '' ? $stored : ($def['default'])();
            $isDefault = trim($stored) === '';
        ?>
            <section class="settings-card" id="<?= e($key) ?>">
                <h3 style="margin-top:0"><?= e($def['title']) ?>
                    <?php if ($isDefault): ?>
                        <span class="muted" style="font-size:.7em;font-weight:400">(domyślny)</span>
                    <?php else: ?>
                        <span style="font-size:.7em;font-weight:400;color:#2540b8">(zmodyfikowany)</span>
                    <?php endif; ?>
                </h3>
                <p class="hint"><?= e($def['desc']) ?></p>
                <?php if (!empty($def['vars'])): ?>
                    <p class="hint">Dostępne placeholdery:
                        <?php foreach ($def['vars'] as $ph => $meaning): ?>
                            <code><?= e($ph) ?></code> — <?= e($meaning) ?>;
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>
                <form method="post" class="settings-form">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="key" value="<?= e($key) ?>">
                    <textarea name="value" rows="<?= (int)$def['rows'] ?>" style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.85rem;line-height:1.5"><?= e($effective) ?></textarea>
                    <div style="display:flex;gap:0.5rem;margin-top:0.5rem">
                        <button type="submit" name="action" value="save" class="btn btn--primary">Zapisz</button>
                        <?php if (!$isDefault): ?>
                            <button type="submit" name="action" value="reset" class="btn"
                                onclick="return confirm('Przywrócić domyślny prompt? Twoje zmiany zostaną utracone.');">Przywróć domyślny</button>
                        <?php endif; ?>
                    </div>
                </form>
            </section>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/_footer.php';
