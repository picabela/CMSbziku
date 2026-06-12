<?php
$adminTitle = 'Edycja artykułu';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../includes/indexing.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$post = $id ? getPostById($id) : null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Nieprawidłowy token CSRF.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $excerpt = trim($_POST['excerpt'] ?? '');
        $content = $_POST['content'] ?? '';
        $category = trim($_POST['category'] ?? 'Aktualności'); // kategoria główna
        $extraCats = is_array($_POST['extra_categories'] ?? null)
            ? array_map('trim', $_POST['extra_categories'])
            : [];
        $extraCats = array_values(array_filter($extraCats));
        $allCatsSelected = array_unique(array_merge([$category], $extraCats));
        $maxCats = maxCategoriesPerPost();
        if (count($allCatsSelected) > $maxCats) {
            $errors[] = "Możesz wybrać maksymalnie {$maxCats} " . ($maxCats === 1 ? 'kategorię' : 'kategorie') . '.';
        }
        $author = trim($_POST['author'] ?? 'Redakcja');
        $authorIdRaw = trim($_POST['author_id'] ?? '');
        $authorId = ($authorIdRaw !== '' && ctype_digit($authorIdRaw) && (int)$authorIdRaw > 0) ? (int)$authorIdRaw : null;
        $featuredAlt = trim($_POST['featured_image_alt'] ?? '');
        $metaTitle = trim($_POST['meta_title'] ?? '');
        $metaDesc = trim($_POST['meta_description'] ?? '');
        $metaKw = trim($_POST['meta_keywords'] ?? '');
        $slugInput = trim($_POST['slug'] ?? '');
        $status = $_POST['status'] ?? 'published';
        $publishedAt = $_POST['published_at'] ?? date('Y-m-d H:i:s');
        $tldr = trim($_POST['tldr'] ?? '');
        if (mb_strlen($tldr) > 320) $tldr = mb_substr($tldr, 0, 320);
        $showTocInput = $_POST['show_toc'] ?? 'global';
        $showToc = $showTocInput === 'yes' ? 1 : ($showTocInput === 'no' ? 0 : null);
        $nofollowLinks = isset($_POST['nofollow_links']) ? 1 : 0;

        // FAQ — pary pytanie/odpowiedź → JSON (puste pary pomijamy)
        $faqQ = $_POST['faq_q'] ?? [];
        $faqA = $_POST['faq_a'] ?? [];
        $faqPairs = [];
        if (is_array($faqQ)) {
            foreach ($faqQ as $fi => $fq) {
                $fq = trim((string)$fq);
                $fa = trim((string)($faqA[$fi] ?? ''));
                if ($fq !== '' && $fa !== '') $faqPairs[] = ['q' => $fq, 'a' => $fa];
            }
        }
        $faqJson = $faqPairs ? json_encode($faqPairs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        if ($title === '') $errors[] = 'Tytuł jest wymagany.';
        if ($content === '') $errors[] = 'Treść jest wymagana.';

        $featuredImage = $post['featured_image'] ?? null;
        if (!empty($_FILES['featured_image']['name']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
            $f = $_FILES['featured_image'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp','gif','svg'];
            if (!in_array($ext, $allowed, true)) {
                $errors[] = 'Niedozwolony format obrazu.';
            } elseif ($f['size'] > 8 * 1024 * 1024) {
                $errors[] = 'Obraz jest za duży (max 8 MB).';
            } else {
                @mkdir(UPLOAD_DIR, 0775, true);
                $filename = uniqid('img_', true) . '.' . $ext;
                if (move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $filename)) {
                    $featuredImage = $filename;
                    // Auto-konwersja do WebP (silent fail jeśli GD bez WebP / SVG)
                    @convertImageToWebp(UPLOAD_DIR . '/' . $filename);
                } else {
                    $errors[] = 'Błąd zapisu pliku.';
                }
            }
        }

        $tagsRaw = trim($_POST['tags_csv'] ?? '');
        $tagNames = [];
        if ($tagsRaw !== '') {
            $tagNames = array_filter(array_map('trim', explode(',', $tagsRaw)));
        }

        if (empty($errors)) {
            $slugBase = $slugInput !== '' ? slugify($slugInput) : slugify($title);
            $slug = uniqueSlug($slugBase, $post['id'] ?? null);
            $pdo = db();
            if ($post) {
                $stmt = $pdo->prepare("UPDATE posts SET slug=?, title=?, subtitle=?, excerpt=?, content=?, featured_image=?, featured_image_alt=?, category=?, author=?, author_id=?, meta_title=?, meta_description=?, meta_keywords=?, status=?, tldr=?, show_toc=?, nofollow_links=?, faq_json=?, published_at=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
                $stmt->execute([$slug, $title, $subtitle, $excerpt, $content, $featuredImage, $featuredAlt, $category, $author, $authorId, $metaTitle, $metaDesc, $metaKw, $status, ($tldr ?: null), $showToc, $nofollowLinks, $faqJson, $publishedAt, $post['id']]);
                attachTagsToPost((int)$post['id'], $tagNames);
                attachCategoriesToPost((int)$post['id'], $category, $allCatsSelected);
                if ($status === 'published' && indexingAutoEnabled()) {
                    indexingSubmitUrl(postIndexUrl($pdo->query("SELECT slug,category FROM posts WHERE id={$post['id']}")->fetch()));
                }
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Artykuł zapisany.'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO posts (slug, title, subtitle, excerpt, content, featured_image, featured_image_alt, category, author, author_id, meta_title, meta_description, meta_keywords, status, tldr, show_toc, nofollow_links, faq_json, published_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$slug, $title, $subtitle, $excerpt, $content, $featuredImage, $featuredAlt, $category, $author, $authorId, $metaTitle, $metaDesc, $metaKw, $status, ($tldr ?: null), $showToc, $nofollowLinks, $faqJson, $publishedAt]);
                $newId = (int)$pdo->lastInsertId();
                attachTagsToPost($newId, $tagNames);
                attachCategoriesToPost($newId, $category, $allCatsSelected);
                if ($status === 'published' && indexingAutoEnabled()) {
                    indexingSubmitUrl(postIndexUrl($pdo->query("SELECT slug,category FROM posts WHERE id=$newId")->fetch()));
                }
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Artykuł utworzony.'];
            }
            header('Location: index.php');
            exit;
        }
    }
}

$allCats = allCategories();
$allAuthorsList = allAuthors();
$currentAuthorId = $post['author_id'] ?? defaultAuthorId();
$existingTags = $post ? getPostTags((int)$post['id']) : [];
$existingTagsCsv = implode(', ', array_map(fn($t) => $t['name'], $existingTags));
$existingPostCats = $post ? getPostCategories((int)$post['id']) : [];
$existingFaq = postFaqItems($post);
$maxCatsAllowed = maxCategoriesPerPost();
?>
<div class="admin-page admin-page--editor">
    <div class="admin-page__head">
        <h1><?= $post ? 'Edytuj artykuł' : 'Nowy artykuł' ?></h1>
        <a href="index.php">← Wróć</a>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="flash flash--error"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="post" enctype="multipart/form-data" class="editor-form">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

        <div class="editor-form__grid">
            <div class="editor-form__main">
                <label>Tytuł
                    <input type="text" name="title" required value="<?= e($post['title'] ?? '') ?>">
                </label>
                <label>Podtytuł
                    <input type="text" name="subtitle" value="<?= e($post['subtitle'] ?? '') ?>" placeholder="Opcjonalny krótki opis pod tytułem">
                </label>
                <label>Zajawka (excerpt)
                    <textarea name="excerpt" rows="3" placeholder="Krótkie streszczenie wyświetlane na listach"><?= e($post['excerpt'] ?? '') ?></textarea>
                </label>
                <label>TL;DR (2–3 zdania, max 280 zn — wyświetlane na górze artykułu, optymalizacja pod cytowanie przez AI)
                    <textarea name="tldr" rows="2" maxlength="320" placeholder="Kluczowy fakt + dlaczego ważne. AI generuje to automatycznie przy auto-importcie."><?= e($post['tldr'] ?? '') ?></textarea>
                </label>
                <div class="editor-form__field">
                    <span class="editor-form__label">Treść</span>
                    <div id="editor-toolbar"></div>
                    <div id="editor"><?= $post['content'] ?? '' ?></div>
                    <textarea name="content" id="content-hidden" hidden><?= e($post['content'] ?? '') ?></textarea>
                </div>
            </div>

            <aside class="editor-form__side">
                <fieldset>
                    <legend>Publikacja</legend>
                    <label>Status
                        <select name="status">
                            <option value="published" <?= ($post['status'] ?? '') === 'published' ? 'selected' : '' ?>>Opublikowany</option>
                            <option value="draft" <?= ($post['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Szkic</option>
                        </select>
                    </label>
                    <label>Data publikacji
                        <input type="datetime-local" name="published_at" value="<?= e(date('Y-m-d\TH:i', strtotime($post['published_at'] ?? 'now'))) ?>">
                    </label>
                    <label>Spis treści (TOC)
                        <?php $tocCurrent = $post['show_toc'] ?? null; ?>
                        <select name="show_toc">
                            <option value="global" <?= $tocCurrent === null ? 'selected' : '' ?>>Globalne ustawienie (<?= setting('toc_enabled_global', '1') === '1' ? 'TAK' : 'NIE' ?>)</option>
                            <option value="yes" <?= (int)$tocCurrent === 1 ? 'selected' : '' ?>>Wymuś: pokaż TOC</option>
                            <option value="no" <?= $tocCurrent !== null && (int)$tocCurrent === 0 ? 'selected' : '' ?>>Wymuś: ukryj TOC</option>
                        </select>
                        <small class="hint">TOC pojawia się tylko gdy artykuł ma ≥3 nagłówków H2/H3.</small>
                    </label>
                    <button type="submit" class="btn btn--primary btn--block">Zapisz artykuł</button>
                </fieldset>

                <fieldset>
                    <legend>Klasyfikacja</legend>
                    <label>Kategoria główna <small class="hint">(decyduje o URL i filtrach)</small>
                        <?php $curPrimary = $post['category'] ?? 'Aktualności'; ?>
                        <select name="category" id="cat-primary">
                            <?php foreach ($allCats as $c): ?>
                                <option value="<?= e($c['name']) ?>" <?= $c['name'] === $curPrimary ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="hint"><a href="categories.php">Zarządzaj kategoriami</a></small>
                    </label>
                    <?php if ($maxCatsAllowed > 1): ?>
                    <div id="extra-cats-wrap">
                        <span class="editor-form__label" style="display:block;margin:0.5rem 0 0.25rem">Dodatkowe kategorie <small class="hint">(max łącznie <?= $maxCatsAllowed ?>)</small></span>
                        <div id="extra-cats-list" style="display:flex;flex-direction:column;gap:0.25rem">
                            <?php foreach ($allCats as $c):
                                $checked = in_array($c['name'], $existingPostCats, true) && $c['name'] !== $curPrimary;
                            ?>
                                <label class="checkbox extra-cat-item" data-cat="<?= e($c['name']) ?>">
                                    <input type="checkbox" name="extra_categories[]" value="<?= e($c['name']) ?>"<?= $checked ? ' checked' : '' ?>>
                                    <?= e($c['name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small class="hint" id="extra-cats-hint"></small>
                    </div>
                    <?php endif; ?>
                    <label>Autor (z bazy)
                        <select name="author_id">
                            <option value="">— brak (użyj pola tekstowego poniżej) —</option>
                            <?php foreach ($allAuthorsList as $a): ?>
                                <option value="<?= (int)$a['id'] ?>" <?= (int)$currentAuthorId === (int)$a['id'] ? 'selected' : '' ?>>
                                    <?= e($a['name']) ?><?= (int)$a['active'] !== 1 ? ' (nieaktywny)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="hint"><a href="authors.php">Zarządzaj autorami</a> · domyślny autor jest auto-przypisywany do nowych artykułów</small>
                    </label>
                    <label>Autor (tekst — fallback / legacy)
                        <input type="text" name="author" value="<?= e($post['author'] ?? 'Redakcja') ?>">
                    </label>
                    <label>Slug (URL)
                        <input type="text" name="slug" value="<?= e($post['slug'] ?? '') ?>" placeholder="auto z tytułu">
                    </label>
                    <label><?= e(tagLabel()) ?> (oddzielone przecinkiem)
                        <input type="text" name="tags_csv" value="<?= e($existingTagsCsv) ?>" placeholder="np. Google, Perplexity, ChatGPT">
                        <small class="hint">Tylko nazwy firm/marek/produktów. Istniejące tagi są ponownie używane.</small>
                    </label>
                </fieldset>

                <fieldset>
                    <legend>Zdjęcie wyróżniające</legend>
                    <?php if (!empty($post['featured_image'])): ?>
                        <img class="thumb" src="<?= e(UPLOAD_URL . '/' . $post['featured_image']) ?>" alt="">
                    <?php endif; ?>
                    <label>Plik (JPG, PNG, WebP, SVG, max 8MB)
                        <input type="file" name="featured_image" accept="image/*">
                    </label>
                    <label>Tekst alt
                        <input type="text" name="featured_image_alt" value="<?= e($post['featured_image_alt'] ?? '') ?>" placeholder="Opis obrazu dla SEO i dostępności">
                    </label>
                </fieldset>

                <fieldset>
                    <legend>SEO</legend>
                    <label>Meta title
                        <input type="text" name="meta_title" value="<?= e($post['meta_title'] ?? '') ?>" maxlength="70">
                    </label>
                    <label>Meta description
                        <textarea name="meta_description" rows="3" maxlength="160"><?= e($post['meta_description'] ?? '') ?></textarea>
                    </label>
                    <label>Słowa kluczowe
                        <input type="text" name="meta_keywords" value="<?= e($post['meta_keywords'] ?? '') ?>" placeholder="seo, geo, ai">
                    </label>
                    <label class="checkbox" style="margin-top:.5rem">
                        <input type="checkbox" name="nofollow_links" value="1" <?= (int)($post['nofollow_links'] ?? 0) === 1 ? 'checked' : '' ?>>
                        Linki wychodzące w tym artykule jako <code>nofollow</code>
                    </label>
                    <small class="hint">Nadpisuje ustawienie globalne tylko dla tego artykułu. Linki wewnętrzne pozostają dofollow.</small>
                </fieldset>

                <fieldset>
                    <legend>FAQ (dane strukturalne)</legend>
                    <small class="hint">Dodaj pytania i odpowiedzi — pojawią się jako sekcja na stronie oraz znacznik <code>FAQPage</code> w Google. Zostaw puste, by FAQ się nie pojawiało.</small>
                    <div id="faq-list" style="display:flex;flex-direction:column;gap:.75rem;margin-top:.5rem">
                        <?php foreach (($existingFaq ?: [['q'=>'','a'=>'']]) as $fi): ?>
                            <div class="faq-row" style="border:1px solid #e5e7eb;border-radius:6px;padding:.5rem">
                                <input type="text" name="faq_q[]" value="<?= e($fi['q']) ?>" placeholder="Pytanie" style="margin-bottom:.35rem">
                                <textarea name="faq_a[]" rows="2" placeholder="Odpowiedź"><?= e($fi['a']) ?></textarea>
                                <button type="button" class="btn btn--small faq-remove" style="margin-top:.35rem">Usuń</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn--small" id="faq-add" style="margin-top:.5rem">+ Dodaj pytanie</button>
                </fieldset>
            </aside>
        </div>
    </form>
</div>

<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<script>
(function(){
    const MAX = <?= (int)$maxCatsAllowed ?>;
    const primary = document.getElementById('cat-primary');
    const wrap = document.getElementById('extra-cats-list');
    const hint = document.getElementById('extra-cats-hint');
    if (!primary || !wrap) return;

    function refresh() {
        const pVal = primary.value;
        const items = wrap.querySelectorAll('.extra-cat-item');
        let checked = 0;
        items.forEach(item => {
            const cb = item.querySelector('input[type=checkbox]');
            const cat = item.dataset.cat;
            // Ukryj jeśli to kategoria główna
            if (cat === pVal) {
                item.style.display = 'none';
                cb.checked = false;
                cb.disabled = true;
            } else {
                item.style.display = '';
                cb.disabled = false;
                if (cb.checked) checked++;
            }
        });
        const remaining = (MAX - 1) - checked; // -1 bo główna zawsze wliczona
        if (hint) {
            hint.textContent = remaining > 0
                ? 'Możesz zaznaczyć jeszcze ' + remaining + (remaining === 1 ? ' dodatkową kategorię.' : ' dodatkowe kategorie.')
                : 'Osiągnięto limit. Odznacz jedną, by wybrać inną.';
        }
        // Zablokuj niezaznaczone gdy limit osiągnięty
        items.forEach(item => {
            const cb = item.querySelector('input[type=checkbox]');
            if (!cb.disabled && !cb.checked && remaining <= 0) cb.disabled = true;
            else if (!cb.disabled && !cb.checked && remaining > 0) cb.disabled = false;
        });
    }

    primary.addEventListener('change', refresh);
    wrap.addEventListener('change', refresh);
    refresh();
})();
</script>
<script>
(function(){
    const list = document.getElementById('faq-list');
    const addBtn = document.getElementById('faq-add');
    if (!list || !addBtn) return;
    function rowHtml() {
        return '<div class="faq-row" style="border:1px solid #e5e7eb;border-radius:6px;padding:.5rem">'
            + '<input type="text" name="faq_q[]" placeholder="Pytanie" style="margin-bottom:.35rem">'
            + '<textarea name="faq_a[]" rows="2" placeholder="Odpowiedź"></textarea>'
            + '<button type="button" class="btn btn--small faq-remove" style="margin-top:.35rem">Usuń</button></div>';
    }
    addBtn.addEventListener('click', function(){
        const tmp = document.createElement('div');
        tmp.innerHTML = rowHtml();
        list.appendChild(tmp.firstChild);
    });
    list.addEventListener('click', function(e){
        if (e.target.classList.contains('faq-remove')) {
            const row = e.target.closest('.faq-row');
            if (row) row.remove();
        }
    });
})();
</script>
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script>
(function(){
    const editor = new Quill('#editor', {
        theme: 'snow',
        modules: {
            toolbar: [
                [{ header: [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                ['blockquote', 'code-block'],
                ['link', 'image'],
                [{ align: [] }],
                ['clean']
            ]
        },
        placeholder: 'Pisz tutaj…'
    });
    const hidden = document.getElementById('content-hidden');
    const form = document.querySelector('.editor-form');
    form.addEventListener('submit', () => {
        hidden.value = editor.root.innerHTML;
    });
})();
</script>
<?php require __DIR__ . '/_footer.php';
