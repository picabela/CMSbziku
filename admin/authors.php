<?php
$adminTitle = 'Autorzy';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pdo = db();
$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId ? getAuthorById($editId) : null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_global') {
        setSetting('authors_footer_enabled', isset($_POST['authors_footer_enabled']) ? '1' : '0');
        setSetting('default_author_id', trim((string)($_POST['default_author_id'] ?? '')));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Ustawienia globalne zapisane.'];
        header('Location: authors.php'); exit;
    }

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $active = isset($_POST['active']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($name === '' || mb_strlen($name) > 100) $errors[] = 'Imię i nazwisko (1-100 znaków) jest wymagane.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidłowy email.';
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) $errors[] = 'Nieprawidłowy URL.';

        $photo = $editing['photo'] ?? null;
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $f = $_FILES['photo'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) {
                $errors[] = 'Zdjęcie musi być JPG/PNG/WebP.';
            } elseif ($f['size'] > 4 * 1024 * 1024) {
                $errors[] = 'Zdjęcie za duże (max 4 MB).';
            } else {
                @mkdir(UPLOAD_DIR, 0775, true);
                $filename = 'author_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                if (move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $filename)) {
                    if ($photo) @unlink(UPLOAD_DIR . '/' . $photo);
                    $photo = $filename;
                    if (function_exists('convertImageToWebp')) @convertImageToWebp(UPLOAD_DIR . '/' . $filename);
                }
            }
        }
        if (isset($_POST['remove_photo']) && $photo) {
            @unlink(UPLOAD_DIR . '/' . $photo);
            $photo = null;
        }

        if (!$errors) {
            $slugBase = slugify($name);
            $slug = $slugBase;
            $i = 2;
            while (true) {
                $check = $pdo->prepare('SELECT id FROM authors WHERE slug = ?' . ($id ? ' AND id != ?' : ''));
                $check->execute($id ? [$slug, $id] : [$slug]);
                if (!$check->fetch()) break;
                $slug = $slugBase . '-' . $i++;
            }
            if ($id) {
                $stmt = $pdo->prepare('UPDATE authors SET name=?, slug=?, bio=?, photo=?, email=?, url=?, active=?, sort_order=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
                $stmt->execute([$name, $slug, $bio, $photo, $email ?: null, $url ?: null, $active, $sortOrder, $id]);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Autor zaktualizowany.'];
            } else {
                $stmt = $pdo->prepare('INSERT INTO authors (name, slug, bio, photo, email, url, active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $slug, $bio, $photo, $email ?: null, $url ?: null, $active, $sortOrder]);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Autor dodany.'];
            }
            header('Location: authors.php'); exit;
        }
    }

    if ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $pdo->prepare('UPDATE authors SET active = 1 - active, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);
        header('Location: authors.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $a = getAuthorById($id);
            if ($a && !empty($a['photo'])) @unlink(UPLOAD_DIR . '/' . $a['photo']);
            // Set posts' author_id to NULL przed usunięciem
            $pdo->prepare('UPDATE posts SET author_id = NULL WHERE author_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM authors WHERE id = ?')->execute([$id]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Autor usunięty. Artykuły zachowały swój tekstowy podpis autora.'];
        }
        header('Location: authors.php'); exit;
    }
}

$authors = allAuthors(false);
$defaultId = defaultAuthorId();
$globalEnabled = authorsFooterEnabled();
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Autorzy <span class="muted" style="font-weight:400;font-size:0.95rem">(<?= count($authors) ?>)</span></h1>
        <a href="authors.php?edit=new" class="btn btn--primary">+ Nowy autor</a>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="flash flash--error"><?= e($err) ?></div><?php endforeach; ?>

    <section class="settings-card">
        <h2>Ustawienia globalne</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_global">

            <label class="checkbox">
                <input type="checkbox" name="authors_footer_enabled" value="1" <?= $globalEnabled ? 'checked' : '' ?>>
                <strong>Pokazuj stopkę autora pod artykułami</strong>
            </label>
            <p class="hint">Gdy wyłączone — nawet aktywni autorzy nie pojawią się w stopce. Działa jako globalny przełącznik.</p>

            <label>Domyślny autor (przypisywany do nowych artykułów)
                <select name="default_author_id">
                    <option value="">— brak —</option>
                    <?php foreach ($authors as $a): ?>
                        <option value="<?= (int)$a['id'] ?>" <?= $defaultId === (int)$a['id'] ? 'selected' : '' ?>>
                            <?= e($a['name']) ?><?= (int)$a['active'] !== 1 ? ' (nieaktywny)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="btn btn--primary">Zapisz ustawienia globalne</button>
        </form>
    </section>

    <?php if ($editId || ($_GET['edit'] ?? '') === 'new'): ?>
        <section class="settings-card settings-card--wide">
            <h2><?= $editing ? 'Edytuj autora: ' . e($editing['name']) : 'Nowy autor' ?></h2>
            <form method="post" enctype="multipart/form-data" class="settings-form">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="save">
                <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

                <div class="form-row form-row--2">
                    <label>Imię i nazwisko *
                        <input type="text" name="name" value="<?= e($editing['name'] ?? '') ?>" required maxlength="100">
                    </label>
                    <label>Kolejność sortowania
                        <input type="number" name="sort_order" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
                    </label>
                </div>

                <label>Bio / opis autora
                    <textarea name="bio" rows="6" placeholder="Krótki opis autora — kilka zdań o doświadczeniu, specjalizacji."><?= e($editing['bio'] ?? '') ?></textarea>
                </label>

                <div class="form-row form-row--2">
                    <label>Email (opcjonalnie)
                        <input type="email" name="email" value="<?= e($editing['email'] ?? '') ?>">
                    </label>
                    <label>Strona / URL (opcjonalnie)
                        <input type="url" name="url" value="<?= e($editing['url'] ?? '') ?>" placeholder="https://twitter.com/...">
                    </label>
                </div>

                <fieldset class="radio-group">
                    <legend>Zdjęcie autora (JPG/PNG/WebP, max 4 MB)</legend>
                    <?php if (!empty($editing['photo'])): ?>
                        <p><img src="<?= e(UPLOAD_URL . '/' . $editing['photo']) ?>" alt="" style="max-height:120px;border-radius:50%"></p>
                        <label class="checkbox"><input type="checkbox" name="remove_photo" value="1"> Usuń obecne zdjęcie</label>
                    <?php endif; ?>
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                </fieldset>

                <label class="checkbox">
                    <input type="checkbox" name="active" value="1" <?= !$editing || (int)($editing['active'] ?? 1) === 1 ? 'checked' : '' ?>>
                    <strong>Autor aktywny</strong> — jego bio pojawi się w stopce artykułów (gdy globalna opcja włączona)
                </label>

                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:1rem">
                    <button type="submit" class="btn btn--primary">Zapisz autora</button>
                    <a href="authors.php" class="btn">Anuluj</a>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php if (empty($authors)): ?>
        <p class="empty">Brak autorów. <a href="authors.php?edit=new">Dodaj pierwszego</a>.</p>
    <?php else: ?>
        <section class="settings-card settings-card--wide">
            <h2>Lista autorów</h2>
            <p class="hint">Kliknij wiersz aby rozwinąć szczegóły. Aktywni autorzy mają zielony badge.</p>

            <?php foreach ($authors as $a): ?>
                <details class="author-card <?= (int)$a['active'] === 1 ? 'author-card--active' : 'author-card--inactive' ?>">
                    <summary class="author-card__summary">
                        <span class="author-card__photo-wrap">
                            <?php if (!empty($a['photo'])): ?>
                                <img src="<?= e(UPLOAD_URL . '/' . $a['photo']) ?>" alt="" class="author-card__photo">
                            <?php else: ?>
                                <span class="author-card__photo-placeholder"><?= e(mb_substr($a['name'], 0, 1)) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="author-card__main">
                            <strong class="author-card__name"><?= e($a['name']) ?></strong>
                            <span class="author-card__meta">
                                <?php if ((int)$a['active'] === 1): ?>
                                    <span class="pill pill--published">aktywny</span>
                                <?php else: ?>
                                    <span class="pill pill--draft">nieaktywny</span>
                                <?php endif; ?>
                                <?php if ($defaultId === (int)$a['id']): ?>
                                    <span class="pill" style="background:#d97706;color:#fff;border-color:#d97706">domyślny</span>
                                <?php endif; ?>
                                <span class="muted">· <?= (int)$a['posts_count'] ?> artykułów · utworzony <?= e(date('Y-m-d', strtotime($a['created_at']))) ?></span>
                            </span>
                        </span>
                    </summary>
                    <div class="author-card__body">
                        <?php if (!empty($a['bio'])): ?>
                            <p><?= nl2br(e($a['bio'])) ?></p>
                        <?php else: ?>
                            <p class="muted"><em>Brak bio.</em></p>
                        <?php endif; ?>
                        <?php if (!empty($a['email']) || !empty($a['url'])): ?>
                            <p class="muted">
                                <?php if (!empty($a['email'])): ?>📧 <a href="mailto:<?= e($a['email']) ?>"><?= e($a['email']) ?></a><?php endif; ?>
                                <?php if (!empty($a['url'])): ?> · 🔗 <a href="<?= e($a['url']) ?>" target="_blank" rel="noopener"><?= e($a['url']) ?></a><?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <div class="author-card__actions">
                            <a href="authors.php?edit=<?= (int)$a['id'] ?>" class="btn">Edytuj</a>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button type="submit" class="btn"><?= (int)$a['active'] === 1 ? 'Dezaktywuj' : 'Aktywuj' ?></button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('Usunąć autora „<?= e($a['name']) ?>\"? Artykuły zostaną odłączone (zachowają tekstowy podpis autora).')">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button type="submit" class="link-btn link-btn--danger">Usuń</button>
                            </form>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php';
