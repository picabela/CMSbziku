<?php
$adminTitle = 'Eksport / Import';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pdo = db();

// ============================================================
// EKSPORT
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null) && ($_POST['action'] ?? '') === 'export') {
    $dateFrom = trim($_POST['date_from'] ?? '');
    $dateTo   = trim($_POST['date_to'] ?? '');
    $includeSettings = isset($_POST['include_settings']);

    $where = '1=1';
    $params = [];
    if ($dateFrom !== '') {
        $where .= ' AND published_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where .= ' AND published_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }

    $postsStmt = $pdo->prepare("SELECT * FROM posts WHERE $where ORDER BY published_at ASC");
    $postsStmt->execute($params);
    $posts = $postsStmt->fetchAll();
    $postIds = array_map(fn($p) => (int)$p['id'], $posts);

    // Tags per post
    $tagsPerPost = [];
    if ($postIds) {
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = $pdo->prepare("SELECT pt.post_id, t.name FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id IN ($placeholders)");
        $stmt->execute($postIds);
        foreach ($stmt->fetchAll() as $row) {
            $tagsPerPost[(int)$row['post_id']][] = $row['name'];
        }
    }
    foreach ($posts as &$p) {
        $p['_tags'] = $tagsPerPost[(int)$p['id']] ?? [];
    }
    unset($p);

    // Auto-imports skojarzone z tymi postami (źródła streszczeń)
    $autoImports = [];
    if ($postIds) {
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = $pdo->prepare("SELECT ai.*, s.name AS source_name FROM auto_imports ai LEFT JOIN sources s ON s.id = ai.source_id WHERE ai.post_id IN ($placeholders)");
        $stmt->execute($postIds);
        $autoImports = $stmt->fetchAll();
    }

    // Sources, categories, tags - zawsze
    $sources = $pdo->query('SELECT * FROM sources ORDER BY id')->fetchAll();
    $categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll();
    $tags = $pdo->query('SELECT * FROM tags ORDER BY name')->fetchAll();

    $settings = [];
    if ($includeSettings) {
        $rows = $pdo->query("SELECT key, value FROM settings WHERE key NOT IN ('admin_password_hash','openai_api_key','auto_token')")->fetchAll();
        foreach ($rows as $r) $settings[$r['key']] = $r['value'];
    }

    $payload = [
        'format' => 'daily-signal-export',
        'version' => 1,
        'exported_at' => date('c'),
        'filter' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
        'counts' => [
            'posts' => count($posts),
            'auto_imports' => count($autoImports),
            'sources' => count($sources),
            'categories' => count($categories),
            'tags' => count($tags),
        ],
        'posts' => $posts,
        'auto_imports' => $autoImports,
        'sources' => $sources,
        'categories' => $categories,
        'tags' => $tags,
        'settings' => $settings,
    ];

    $filename = 'daily-signal-export-' . date('Y-m-d-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// ============================================================
// IMPORT
// ============================================================
$importReport = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null) && ($_POST['action'] ?? '') === 'import') {
    if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $flash = ['type' => 'error', 'msg' => 'Brak pliku lub błąd uploadu.'];
    } else {
        $json = file_get_contents($_FILES['file']['tmp_name']);
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['format'] ?? '') !== 'daily-signal-export') {
            $flash = ['type' => 'error', 'msg' => 'Nieprawidłowy format pliku.'];
        } else {
            $report = ['posts_added' => 0, 'posts_skipped' => 0, 'auto_imports_added' => 0, 'sources_added' => 0, 'categories_added' => 0, 'tags_added' => 0, 'settings_updated' => 0];
            $replaceSettings = isset($_POST['replace_settings']);
            $importSources = isset($_POST['import_sources']);

            $pdo->beginTransaction();
            try {
                // Categories
                foreach ($data['categories'] ?? [] as $c) {
                    $stmt = $pdo->prepare('INSERT OR IGNORE INTO categories (name, slug, description, sort_order) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$c['name'], $c['slug'] ?? slugify($c['name']), $c['description'] ?? '', (int)($c['sort_order'] ?? 0)]);
                    if ($stmt->rowCount() > 0) $report['categories_added']++;
                }

                // Tags
                foreach ($data['tags'] ?? [] as $t) {
                    $stmt = $pdo->prepare('INSERT OR IGNORE INTO tags (name, slug) VALUES (?, ?)');
                    $stmt->execute([$t['name'], $t['slug'] ?? slugify($t['name'])]);
                    if ($stmt->rowCount() > 0) $report['tags_added']++;
                }

                // Sources (opcjonalnie)
                $sourceOldIdToNewId = [];
                if ($importSources) {
                    foreach ($data['sources'] ?? [] as $s) {
                        $stmt = $pdo->prepare('SELECT id FROM sources WHERE feed_url = ?');
                        $stmt->execute([$s['feed_url']]);
                        $existing = $stmt->fetch();
                        if ($existing) {
                            $sourceOldIdToNewId[(int)$s['id']] = (int)$existing['id'];
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO sources (name, feed_url, site_url, category, language, source_type, link_selector, max_age_days, max_items_per_run, enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                            $stmt->execute([
                                $s['name'], $s['feed_url'], $s['site_url'] ?? '', $s['category'] ?? '',
                                $s['language'] ?? 'en', $s['source_type'] ?? 'rss', $s['link_selector'] ?? null,
                                $s['max_age_days'] ?? null, (int)($s['max_items_per_run'] ?? 2), (int)($s['enabled'] ?? 1),
                            ]);
                            $sourceOldIdToNewId[(int)$s['id']] = (int)$pdo->lastInsertId();
                            $report['sources_added']++;
                        }
                    }
                } else {
                    // Mapuj po feed_url jeśli istnieje (do auto_imports)
                    foreach ($data['sources'] ?? [] as $s) {
                        $stmt = $pdo->prepare('SELECT id FROM sources WHERE feed_url = ?');
                        $stmt->execute([$s['feed_url']]);
                        $existing = $stmt->fetch();
                        if ($existing) $sourceOldIdToNewId[(int)$s['id']] = (int)$existing['id'];
                    }
                }

                // Posts
                $postOldIdToNewId = [];
                foreach ($data['posts'] ?? [] as $p) {
                    $stmt = $pdo->prepare('SELECT id FROM posts WHERE slug = ?');
                    $stmt->execute([$p['slug']]);
                    if ($stmt->fetch()) {
                        $report['posts_skipped']++;
                        continue;
                    }
                    $stmt = $pdo->prepare("INSERT INTO posts (slug, title, subtitle, excerpt, content, featured_image, featured_image_alt, category, author, meta_title, meta_description, meta_keywords, status, source_attribution, published_at, updated_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([
                        $p['slug'], $p['title'], $p['subtitle'] ?? '', $p['excerpt'] ?? '', $p['content'],
                        $p['featured_image'] ?? null, $p['featured_image_alt'] ?? '', $p['category'] ?? 'Aktualności',
                        $p['author'] ?? 'Redakcja', $p['meta_title'] ?? '', $p['meta_description'] ?? '', $p['meta_keywords'] ?? '',
                        $p['status'] ?? 'published', $p['source_attribution'] ?? null,
                        $p['published_at'] ?? date('Y-m-d H:i:s'),
                        $p['updated_at'] ?? date('Y-m-d H:i:s'),
                        $p['created_at'] ?? date('Y-m-d H:i:s'),
                    ]);
                    $newPostId = (int)$pdo->lastInsertId();
                    $postOldIdToNewId[(int)$p['id']] = $newPostId;
                    $report['posts_added']++;

                    // Tags posta
                    foreach (($p['_tags'] ?? []) as $tagName) {
                        $tagId = findOrCreateTag($tagName);
                        if ($tagId) {
                            $pdo->prepare('INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)')->execute([$newPostId, $tagId]);
                        }
                    }
                }

                // Auto-imports — dedup, by przyszłe runy nie pobrały tych samych źródeł ponownie
                foreach ($data['auto_imports'] ?? [] as $ai) {
                    $stmt = $pdo->prepare('SELECT id FROM auto_imports WHERE guid_hash = ?');
                    $stmt->execute([$ai['guid_hash']]);
                    if ($stmt->fetch()) continue;
                    $newPostId = $postOldIdToNewId[(int)($ai['post_id'] ?? 0)] ?? null;
                    $newSourceId = $sourceOldIdToNewId[(int)($ai['source_id'] ?? 0)] ?? null;
                    $pdo->prepare('INSERT INTO auto_imports (source_id, external_url, external_guid, guid_hash, post_id, imported_at) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$newSourceId, $ai['external_url'] ?? '', $ai['external_guid'] ?? '', $ai['guid_hash'], $newPostId, $ai['imported_at'] ?? date('Y-m-d H:i:s')]);
                    $report['auto_imports_added']++;
                }

                // Settings
                if ($replaceSettings && !empty($data['settings'])) {
                    foreach ($data['settings'] as $k => $v) {
                        if (in_array($k, ['admin_password_hash','openai_api_key','auto_token'], true)) continue;
                        setSetting((string)$k, (string)$v);
                        $report['settings_updated']++;
                    }
                }

                $pdo->commit();
                refreshTagUsage();
                $importReport = $report;
                $flash = ['type' => 'success', 'msg' => 'Import zakończony pomyślnie.'];
            } catch (Throwable $e) {
                $pdo->rollBack();
                $flash = ['type' => 'error', 'msg' => 'Import nieudany: ' . $e->getMessage()];
            }
        }
    }
}

$postsCount = (int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
$autoImportsCount = (int)$pdo->query('SELECT COUNT(*) FROM auto_imports')->fetchColumn();
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Eksport / Import</h1>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <?php if ($importReport): ?>
        <div class="flash flash--success">
            Dodane: <?= (int)$importReport['posts_added'] ?> artykułów,
            <?= (int)$importReport['auto_imports_added'] ?> rekordów dedup (auto_imports),
            <?= (int)$importReport['sources_added'] ?> źródeł,
            <?= (int)$importReport['categories_added'] ?> kategorii,
            <?= (int)$importReport['tags_added'] ?> tagów,
            <?= (int)$importReport['settings_updated'] ?> ustawień.
            Pominięte (duplikaty slug): <?= (int)$importReport['posts_skipped'] ?>.
        </div>
    <?php endif; ?>

    <section class="settings-card">
        <h2>Eksport</h2>
        <p class="hint">Zapisuje artykuły wraz z meta, tagami, kategoriami, źródłami oraz powiązaniami auto-import (dzięki temu po imporcie system nie będzie ponownie pobierał tych samych źródeł). W bazie: <strong><?= $postsCount ?></strong> artykułów, <strong><?= $autoImportsCount ?></strong> rekordów dedup.</p>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="export">

            <fieldset class="radio-group">
                <legend>Filtr po dacie publikacji</legend>
                <div class="form-row form-row--2">
                    <label>Od (zostaw puste = od początku)
                        <input type="date" name="date_from">
                    </label>
                    <label>Do (zostaw puste = do dziś)
                        <input type="date" name="date_to">
                    </label>
                </div>
            </fieldset>

            <label class="checkbox"><input type="checkbox" name="include_settings" value="1" checked> Dołącz ustawienia serwisu (bez hasła i klucza API)</label>

            <button type="submit" class="btn btn--primary">Pobierz plik JSON</button>
        </form>
    </section>

    <section class="settings-card">
        <h2>Import</h2>
        <p class="hint">Wgranie pliku JSON wcześniej wyeksportowanego. Duplikaty (po slug-u artykułu lub hash-u auto-import) są pomijane.</p>
        <form method="post" enctype="multipart/form-data" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="import">

            <label>Plik JSON
                <input type="file" name="file" accept="application/json,.json" required>
            </label>
            <label class="checkbox"><input type="checkbox" name="import_sources" value="1"> Dodaj nowe źródła (jeśli nie istnieją po feed_url)</label>
            <label class="checkbox"><input type="checkbox" name="replace_settings" value="1"> Nadpisz ustawienia serwisu (oprócz hasła i klucza API)</label>

            <button type="submit" class="btn btn--primary" onclick="return confirm('Uruchomić import? Operacja może potrwać chwilę.')">Importuj</button>
        </form>
    </section>
</div>
<?php require __DIR__ . '/_footer.php';
