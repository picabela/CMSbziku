<?php
require_once __DIR__ . '/db.php';

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function slugify(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $map = [
        'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
        'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ź'=>'z','Ż'=>'z',
    ];
    $text = strtr($text, $map);
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = trim($text, '-');
    $text = preg_replace('~[^-\w]+~', '', $text);
    return $text ?: 'post-' . time();
}

function uniqueSlug(string $base, ?int $excludeId = null): string {
    $pdo = db();
    $slug = $base;
    $i = 2;
    while (true) {
        $sql = 'SELECT id FROM posts WHERE slug = ?';
        $params = [$slug];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) return $slug;
        $slug = $base . '-' . $i++;
    }
}

function getPosts(int $page = 1, ?string $category = null): array {
    $pdo = db();
    $offset = ($page - 1) * POSTS_PER_PAGE;
    $where = "status = 'published'";
    $params = [];
    if ($category) {
        $where .= ' AND category = :cat';
        $params[':cat'] = $category;
    }
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE $where ORDER BY published_at DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', POSTS_PER_PAGE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function countPosts(?string $category = null): int {
    $pdo = db();
    $where = "status = 'published'";
    $params = [];
    if ($category) {
        $where .= ' AND category = ?';
        $params[] = $category;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM posts WHERE $where");
    $stmt->execute($params);
    return (int)$stmt->fetch()['c'];
}

function getPostBySlug(string $slug): ?array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE slug = ? AND status = 'published'");
    $stmt->execute([$slug]);
    $post = $stmt->fetch();
    return $post ?: null;
}

function getPostById(int $id): ?array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
    $stmt->execute([$id]);
    $post = $stmt->fetch();
    return $post ?: null;
}

function getAllPostsAdmin(): array {
    $pdo = db();
    return $pdo->query('SELECT * FROM posts ORDER BY published_at DESC')->fetchAll();
}

function getCategories(): array {
    $pdo = db();
    return $pdo->query("SELECT category, COUNT(*) AS count FROM posts WHERE status = 'published' GROUP BY category ORDER BY count DESC")->fetchAll();
}

function getRelatedPosts(string $category, int $excludeId, int $limit = 3): array {
    // Semantic-ish: ranking po wspólnych tagach (Jaccard-like) + boost dla tej samej kategorii.
    $pdo = db();
    $tagsStmt = $pdo->prepare('SELECT tag_id FROM post_tags WHERE post_id = ?');
    $tagsStmt->execute([$excludeId]);
    $tagIds = array_map(fn($r) => (int)$r['tag_id'], $tagsStmt->fetchAll());

    if (!$tagIds) {
        // Fallback: tylko kategoria (jak wcześniej)
        $stmt = $pdo->prepare("SELECT * FROM posts WHERE category = ? AND id != ? AND status = 'published' ORDER BY published_at DESC LIMIT ?");
        $stmt->bindValue(1, $category);
        $stmt->bindValue(2, $excludeId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // Punktacja: +1 za każdy wspólny tag, +2 za zgodność kategorii, świeżość jako tie-breaker
    $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
    $sql = "
        SELECT p.*,
               (SELECT COUNT(*) FROM post_tags pt WHERE pt.post_id = p.id AND pt.tag_id IN ($placeholders)) AS shared_tags,
               CASE WHEN p.category = ? THEN 2 ELSE 0 END AS cat_match
        FROM posts p
        WHERE p.id != ? AND p.status = 'published'
        HAVING (shared_tags + cat_match) > 0
        ORDER BY (shared_tags + cat_match) DESC, p.published_at DESC
        LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $i = 1;
    foreach ($tagIds as $tid) $stmt->bindValue($i++, $tid, PDO::PARAM_INT);
    $stmt->bindValue($i++, $category);
    $stmt->bindValue($i++, $excludeId, PDO::PARAM_INT);
    $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (count($rows) >= $limit) return $rows;

    // Dopełnij brakujące świeżymi z tej samej kategorii (jeśli mało wspólnych tagów)
    $haveIds = array_map(fn($r) => (int)$r['id'], $rows);
    $excludeIds = array_merge([$excludeId], $haveIds);
    $need = $limit - count($rows);
    $ph = implode(',', array_fill(0, count($excludeIds), '?'));
    $fill = $pdo->prepare("SELECT * FROM posts WHERE category = ? AND id NOT IN ($ph) AND status='published' ORDER BY published_at DESC LIMIT ?");
    $i = 1;
    $fill->bindValue($i++, $category);
    foreach ($excludeIds as $eid) $fill->bindValue($i++, $eid, PDO::PARAM_INT);
    $fill->bindValue($i++, $need, PDO::PARAM_INT);
    $fill->execute();
    return array_merge($rows, $fill->fetchAll());
}

function formatDate(string $datetime): string {
    $ts = strtotime($datetime);
    $months = ['stycznia','lutego','marca','kwietnia','maja','czerwca','lipca','sierpnia','września','października','listopada','grudnia'];
    return date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

function readingTime(string $html): int {
    $words = str_word_count(strip_tags($html));
    return max(1, (int)ceil($words / 200));
}

function postUrl(array $post): string {
    return BASE_URL . '/' . $post['slug'];
}

function categoryUrl(string $category): string {
    return BASE_URL . '/kategoria/' . slugify($category);
}

function categoryBySlug(string $slug): ?string {
    foreach (getCategories() as $cat) {
        if (slugify($cat['category']) === $slug) return $cat['category'];
    }
    return null;
}

function setting(string $key, ?string $default = null): ?string {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

function setSetting(string $key, string $value): void {
    $pdo = db();
    $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(?string $token): bool {
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

/**
 * Sanityzacja listy ID-ków z bulk POST: tylko liczby całkowite > 0.
 */
function bulkIds($raw): array {
    if (!is_array($raw)) return [];
    $ids = array_map('intval', $raw);
    $ids = array_filter($ids, fn($i) => $i > 0);
    return array_values(array_unique($ids));
}

function allCategories(): array {
    return db()->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll();
}

function siteName(): string {
    $v = trim((string)setting('site_name', ''));
    return $v !== '' ? $v : SITE_NAME;
}

function siteTagline(): string {
    $v = trim((string)setting('site_tagline', ''));
    return $v !== '' ? $v : SITE_TAGLINE;
}

function siteLogoUrl(): ?string {
    $v = trim((string)setting('site_logo', ''));
    return $v !== '' ? UPLOAD_URL . '/' . $v : null;
}

function tagLabel(): string {
    $v = trim((string)setting('tag_label', ''));
    return $v !== '' ? $v : 'Tagi';
}

function tagUrl(string $slug): string {
    return BASE_URL . '/tag/' . $slug;
}

/**
 * Znajdź istniejący tag (po znormalizowanej nazwie) lub stwórz nowy.
 * Zwraca id tagu.
 */
function findOrCreateTag(string $name): ?int {
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 60) return null;
    $slug = slugify($name);
    if ($slug === '') return null;
    $pdo = db();
    // Próba po slug — case insensitive matching
    $stmt = $pdo->prepare('SELECT id FROM tags WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];
    try {
        $pdo->prepare('INSERT INTO tags (name, slug) VALUES (?, ?)')->execute([$name, $slug]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        // race: spróbuj jeszcze raz odczytać
        $stmt = $pdo->prepare('SELECT id FROM tags WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    }
}

function attachTagsToPost(int $postId, array $tagNames): void {
    if (!$tagNames) return;
    $pdo = db();
    // Usuwamy stare powiązania (przy reedycji nadpisujemy)
    $pdo->prepare('DELETE FROM post_tags WHERE post_id = ?')->execute([$postId]);
    $ins = $pdo->prepare('INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)');
    $tagIds = [];
    foreach ($tagNames as $name) {
        $tagId = findOrCreateTag($name);
        if ($tagId) {
            $ins->execute([$postId, $tagId]);
            $tagIds[] = $tagId;
        }
    }
    refreshTagUsage($tagIds);
}

function refreshTagUsage(array $tagIds = []): void {
    $pdo = db();
    if ($tagIds) {
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $pdo->prepare("UPDATE tags SET usage_count = (SELECT COUNT(*) FROM post_tags WHERE tag_id = tags.id) WHERE id IN ($placeholders)")
            ->execute($tagIds);
    } else {
        $pdo->exec("UPDATE tags SET usage_count = (SELECT COUNT(*) FROM post_tags WHERE tag_id = tags.id)");
    }
}

function getPostTags(int $postId): array {
    $stmt = db()->prepare('SELECT t.* FROM tags t JOIN post_tags pt ON pt.tag_id = t.id WHERE pt.post_id = ? ORDER BY t.name');
    $stmt->execute([$postId]);
    return $stmt->fetchAll();
}

function getTagBySlug(string $slug): ?array {
    $stmt = db()->prepare('SELECT * FROM tags WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getPostsByTag(int $tagId, int $page = 1): array {
    $pdo = db();
    $offset = ($page - 1) * POSTS_PER_PAGE;
    $stmt = $pdo->prepare("SELECT p.* FROM posts p JOIN post_tags pt ON pt.post_id = p.id WHERE pt.tag_id = ? AND p.status = 'published' ORDER BY p.published_at DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(1, $tagId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', POSTS_PER_PAGE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function countPostsByTag(int $tagId): int {
    $stmt = db()->prepare("SELECT COUNT(*) AS c FROM posts p JOIN post_tags pt ON pt.post_id = p.id WHERE pt.tag_id = ? AND p.status = 'published'");
    $stmt->execute([$tagId]);
    return (int)$stmt->fetch()['c'];
}

function allTags(): array {
    return db()->query('SELECT * FROM tags ORDER BY usage_count DESC, name')->fetchAll();
}

/**
 * Top N tagów po liczbie użyć (do stopki).
 */
function topTags(int $limit = 20): array {
    $stmt = db()->prepare('SELECT * FROM tags WHERE usage_count > 0 ORDER BY usage_count DESC, name LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Top N kategorii po liczbie opublikowanych artykułów.
 */
function topCategories(int $limit = 8): array {
    $stmt = db()->prepare("SELECT category, COUNT(*) AS count FROM posts WHERE status = 'published' GROUP BY category ORDER BY count DESC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Skaluje rozmiar chipa tagu (1-5) na podstawie rozkładu usage_count w danej liście.
 */
function tagSizeBucket(int $count, int $maxCount): int {
    if ($maxCount <= 1) return 3;
    $ratio = $count / $maxCount;
    if ($ratio > 0.8) return 5;
    if ($ratio > 0.55) return 4;
    if ($ratio > 0.3) return 3;
    if ($ratio > 0.1) return 2;
    return 1;
}

/**
 * Strony statyczne (jak WordPress Pages).
 */
function getPageBySlug(string $slug): ?array {
    $stmt = db()->prepare("SELECT * FROM pages WHERE slug = ? AND status = 'published'");
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getPageById(int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM pages WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getAllPages(bool $publishedOnly = false): array {
    $sql = 'SELECT * FROM pages';
    if ($publishedOnly) $sql .= " WHERE status = 'published'";
    $sql .= ' ORDER BY sort_order, title';
    return db()->query($sql)->fetchAll();
}

function pageUrl(array $page): string {
    return BASE_URL . '/strona/' . $page['slug'];
}

/**
 * Menu: zwraca listę elementów (zdekodowane z JSON-a).
 * $location: 'header' | 'footer'
 */
function getMenuItems(string $location): array {
    $key = $location === 'footer' ? 'footer_menu_items' : 'header_menu_items';
    $json = setting($key, '');
    if (!$json) return [];
    $items = json_decode($json, true);
    return is_array($items) ? $items : [];
}

/**
 * Resolves a menu item to a URL + label for rendering.
 * Item shape: ['type' => 'home|category|tag|page|url', 'target' => ..., 'label' => optional override]
 */
function resolveMenuItem(array $item): ?array {
    $type = $item['type'] ?? '';
    $target = $item['target'] ?? '';
    $labelOverride = trim((string)($item['label'] ?? ''));
    switch ($type) {
        case 'home':
            return ['url' => BASE_URL . '/', 'label' => $labelOverride ?: 'Strona główna'];
        case 'category':
            return ['url' => categoryUrl($target), 'label' => $labelOverride ?: $target];
        case 'tag':
            $tag = getTagBySlug($target);
            if (!$tag) return null;
            return ['url' => tagUrl($tag['slug']), 'label' => $labelOverride ?: $tag['name']];
        case 'page':
            $page = getPageBySlug($target);
            if (!$page) return null;
            return ['url' => pageUrl($page), 'label' => $labelOverride ?: $page['title']];
        case 'url':
            if (!$target) return null;
            return ['url' => $target, 'label' => $labelOverride ?: $target];
    }
    return null;
}

/**
 * Renderuje menu z lokacji. Jeśli puste — fallback do auto-listy kategorii (kompatybilność).
 */
function renderMenu(string $location, bool $fallbackToCategories = true): array {
    $items = getMenuItems($location);
    $out = [];
    if ($items) {
        foreach ($items as $item) {
            $r = resolveMenuItem($item);
            if ($r) $out[] = $r;
        }
        return $out;
    }
    // Fallback: kategorie z licznikami (jak dotąd) — tylko dla header
    if ($fallbackToCategories && $location === 'header') {
        $out[] = ['url' => BASE_URL . '/', 'label' => 'Wszystkie'];
        foreach (getCategories() as $cat) {
            $out[] = ['url' => categoryUrl($cat['category']), 'label' => $cat['category']];
        }
    }
    return $out;
}

/**
 * Zwraca slug aktywnego motywu (z fallbackiem do 'classic' jeśli nie istnieje).
 */
function activeTheme(): string {
    $slug = trim((string)setting('active_theme', 'classic'));
    if ($slug === '' || !is_dir(__DIR__ . '/../themes/' . $slug)) {
        return 'classic';
    }
    return $slug;
}

/**
 * Zwraca URL do pliku w aktywnym motywie. Przykład: themeAssetUrl('style.css').
 * Dodaje filemtime jako cache-buster.
 */
function themeAssetUrl(string $file): string {
    $slug = activeTheme();
    $path = __DIR__ . '/../themes/' . $slug . '/' . $file;
    $url  = BASE_URL . '/themes/' . rawurlencode($slug) . '/' . ltrim($file, '/');
    if (file_exists($path)) $url .= '?v=' . filemtime($path);
    return $url;
}

/**
 * Skanuje katalog themes/ i zwraca listę dostępnych motywów (manifest + ścieżki).
 */
function listThemes(): array {
    $base = __DIR__ . '/../themes';
    if (!is_dir($base)) return [];
    $themes = [];
    foreach (scandir($base) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $dir = $base . '/' . $entry;
        if (!is_dir($dir)) continue;
        $manifest = readThemeManifest($entry);
        if ($manifest) $themes[] = $manifest;
    }
    usort($themes, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $themes;
}

/**
 * Wczytuje theme.json motywu i waliduje wymagane pola.
 * Zwraca null jeśli nieprawidłowy.
 */
function readThemeManifest(string $slug): ?array {
    $dir = __DIR__ . '/../themes/' . $slug;
    $manifestFile = $dir . '/theme.json';
    if (!is_file($manifestFile)) return null;
    $data = json_decode(file_get_contents($manifestFile), true);
    if (!is_array($data)) return null;
    foreach (['name', 'slug', 'version'] as $req) {
        if (empty($data[$req])) return null;
    }
    // Wymagamy też style.css
    if (!is_file($dir . '/style.css')) return null;
    $data['_dir'] = $dir;
    $data['_slug_actual'] = $slug;
    $data['_screenshot_url'] = is_file($dir . '/screenshot.svg')
        ? BASE_URL . '/themes/' . rawurlencode($slug) . '/screenshot.svg'
        : (is_file($dir . '/screenshot.png') ? BASE_URL . '/themes/' . rawurlencode($slug) . '/screenshot.png' : null);
    return $data;
}

/**
 * Zwraca override-y kolorów dla danego motywu z ustawień (JSON).
 */
function themeColorOverrides(string $slug): array {
    $json = setting('theme_color_overrides', '');
    if (!$json) return [];
    $all = json_decode($json, true);
    return is_array($all) && is_array($all[$slug] ?? null) ? $all[$slug] : [];
}

function setThemeColorOverrides(string $slug, array $overrides): void {
    $json = setting('theme_color_overrides', '');
    $all = $json ? (json_decode($json, true) ?: []) : [];
    if (!is_array($all)) $all = [];
    // Sanityzacja: tylko hexy (#xxx, #xxxxxx, #xxxxxxxx)
    $clean = [];
    foreach ($overrides as $var => $val) {
        if (!preg_match('/^--[a-z][a-z0-9-]*$/', $var)) continue;
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($val))) {
            $clean[$var] = trim($val);
        }
    }
    $all[$slug] = $clean;
    setSetting('theme_color_overrides', json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Generuje inline <style> z CSS variable overrides dla aktywnego motywu.
 * Wstrzykiwane w <head> po theme CSS, by nadpisać :root.
 */
function renderThemeColorStyle(): string {
    $slug = activeTheme();
    $overrides = themeColorOverrides($slug);
    if (!$overrides) return '';
    $rules = [];
    foreach ($overrides as $var => $val) {
        $rules[] = $var . ': ' . $val . ';';
    }
    return '<style id="theme-colors">:root { ' . implode(' ', $rules) . ' }</style>';
}

/**
 * Auto-generuje ID dla każdego <h2>/<h3> w treści (jeśli brak),
 * zwraca tablicę pozycji TOC + zmodyfikowany HTML z dodanymi id-kami.
 * Zwraca ['html' => ..., 'toc' => [['level' => 2, 'id' => 'slug', 'text' => 'Tekst nagłówka']]]
 */
function buildTocAndAnchors(string $html): array {
    $toc = [];
    $usedIds = [];
    $html = preg_replace_callback(
        '#<(h[23])\b([^>]*)>(.*?)</\1>#si',
        function($m) use (&$toc, &$usedIds) {
            $tag = $m[1];
            $attrs = $m[2];
            $inner = $m[3];
            $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === '') return $m[0];

            // Istniejący id w atrybutach?
            $id = null;
            if (preg_match('/\bid=["\']([^"\']+)["\']/i', $attrs, $idm)) {
                $id = $idm[1];
            }
            if (!$id) {
                $base = slugify($text);
                if (!$base) $base = 'sec-' . count($toc);
                $id = $base;
                $i = 2;
                while (in_array($id, $usedIds, true)) {
                    $id = $base . '-' . $i++;
                }
                $attrs .= ' id="' . htmlspecialchars($id, ENT_QUOTES) . '"';
            }
            $usedIds[] = $id;
            $toc[] = ['level' => (int)substr($tag, 1), 'id' => $id, 'text' => $text];
            return '<' . $tag . $attrs . '>' . $inner . '</' . $tag . '>';
        },
        $html
    );
    return ['html' => $html, 'toc' => $toc];
}

/**
 * Auto internal linking: skanuje plaintextowe fragmenty HTML i linkuje
 * wystąpienia nazw tagów (do strony tagu). Pomija fragmenty wewnątrz
 * istniejących <a>, headings, code, pre.
 */
function applyAutoInternalLinks(string $html, int $excludePostId = 0): string {
    if (setting('auto_internal_links', '1') !== '1') return $html;
    $tags = db()->query('SELECT name, slug FROM tags WHERE usage_count > 0')->fetchAll();
    if (!$tags) return $html;

    // Posortuj od najdłuższych — chcemy "Microsoft Edge" zamatchować przed "Microsoft"
    usort($tags, fn($a, $b) => mb_strlen($b['name']) - mb_strlen($a['name']));

    // Maska: zamieniamy chronione bloki na placeholdery, robimy replace, przywracamy.
    $blocks = [];
    $idx = 0;
    $masked = preg_replace_callback(
        '#<(a|h1|h2|h3|h4|code|pre)\b[^>]*>.*?</\1>#si',
        function($m) use (&$blocks, &$idx) {
            $key = "\0BLOCK{$idx}\0";
            $blocks[$key] = $m[0];
            $idx++;
            return $key;
        },
        $html
    );

    $linkedTags = [];  // każdy tag linkujemy tylko raz na artykuł
    foreach ($tags as $tag) {
        if (in_array($tag['slug'], $linkedTags, true)) continue;
        $name = $tag['name'];
        $pattern = '/(?<![\p{L}\p{N}\-_])(' . preg_quote($name, '/') . ')(?![\p{L}\p{N}\-_])/u';
        $replaced = preg_replace_callback($pattern, function($m) use ($tag, &$linkedTags) {
            if (in_array($tag['slug'], $linkedTags, true)) return $m[1];
            $linkedTags[] = $tag['slug'];
            return '<a href="' . htmlspecialchars(tagUrl($tag['slug']), ENT_QUOTES)
                 . '" class="auto-link" data-tag="' . htmlspecialchars($tag['slug'], ENT_QUOTES) . '">' . $m[1] . '</a>';
        }, $masked, 1);
        if ($replaced !== null) $masked = $replaced;
    }

    foreach ($blocks as $key => $orig) {
        $masked = str_replace($key, $orig, $masked);
    }
    return $masked;
}

/**
 * Auto-konwersja obrazu do WebP. Zwraca nazwę pliku .webp obok oryginału, lub null.
 * Wymaga GD z obsługą WebP (PHP >= 7.0).
 */
function convertImageToWebp(string $sourcePath, int $quality = 82): ?string {
    if (setting('webp_conversion', '1') !== '1') return null;
    if (!function_exists('imagewebp')) return null;
    if (!is_file($sourcePath)) return null;

    $info = @getimagesize($sourcePath);
    if (!$info) return null;
    $mime = $info['mime'] ?? '';
    $img = null;
    switch ($mime) {
        case 'image/jpeg': $img = @imagecreatefromjpeg($sourcePath); break;
        case 'image/png':  $img = @imagecreatefrompng($sourcePath);
            if ($img) { imagepalettetotruecolor($img); imagealphablending($img, true); imagesavealpha($img, true); }
            break;
        case 'image/gif':  $img = @imagecreatefromgif($sourcePath); break;
        default: return null;  // svg, webp już — pomijamy
    }
    if (!$img) return null;
    $webpPath = preg_replace('/\.[^.]+$/', '.webp', $sourcePath);
    $ok = @imagewebp($img, $webpPath, $quality);
    imagedestroy($img);
    return $ok ? basename($webpPath) : null;
}

/**
 * Renderuje surowy custom-code z ustawień + auto-generowane snippety (GTM, GA4, weryfikacje).
 * Lokacja: 'head' | 'body_start' | 'body_end'.
 * Zwraca HTML bez ucieczki — to jest świadome zachowanie (admin paste).
 */
function renderCustomCode(string $location): string {
    $out = '';
    switch ($location) {
        case 'head':
            $gsc = trim((string)setting('gsc_verification', ''));
            if ($gsc !== '') $out .= '<meta name="google-site-verification" content="' . e($gsc) . '">' . "\n";
            $bing = trim((string)setting('bing_verification', ''));
            if ($bing !== '') $out .= '<meta name="msvalidate.01" content="' . e($bing) . '">' . "\n";

            $gtmId = trim((string)setting('gtm_id', ''));
            if ($gtmId !== '' && preg_match('/^GTM-[A-Z0-9]+$/i', $gtmId)) {
                $out .= "<!-- Google Tag Manager -->\n<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':"
                     . "new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src="
                     . "'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . e($gtmId) . "');</script>\n<!-- End Google Tag Manager -->\n";
            }
            $ga4 = trim((string)setting('ga4_id', ''));
            if ($ga4 !== '' && preg_match('/^G-[A-Z0-9]+$/i', $ga4)) {
                $out .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . e($ga4) . '"></script>' . "\n"
                     . "<script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date()); gtag('config', '" . e($ga4) . "');</script>\n";
            }
            $pixel = trim((string)setting('facebook_pixel_id', ''));
            if ($pixel !== '' && preg_match('/^\d+$/', $pixel)) {
                $out .= "<!-- Meta Pixel -->\n<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','" . e($pixel) . "');fbq('track','PageView');</script>\n";
            }
            $out .= (string)setting('custom_head_code', '');
            break;

        case 'body_start':
            $gtmId = trim((string)setting('gtm_id', ''));
            if ($gtmId !== '' && preg_match('/^GTM-[A-Z0-9]+$/i', $gtmId)) {
                $out .= '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . e($gtmId) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
            }
            $out .= (string)setting('custom_body_start_code', '');
            break;

        case 'body_end':
            $out .= (string)setting('custom_body_end_code', '');
            break;
    }
    return $out;
}

/**
 * Renderuje stopkę źródła wg szablonu z ustawień. Placeholder-y: {url}, {source}.
 * Używane tylko dla NOWYCH publikacji — stare mają stopkę zapisaną w content.
 */
function renderSourceAttribution(string $url, string $sourceName, ?string $template = null): string {
    $tpl = $template ?? setting('source_attribution_template', 'Opracowanie redakcji na podstawie źródła: {url} ({source}).');
    $tpl = strtr($tpl, [
        '{url}' => e($url),
        '{source}' => e($sourceName),
    ]);
    return '<hr><p class="source-attribution"><small>' . $tpl . '</small></p>';
}
