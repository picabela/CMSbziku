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

function postsPerPage(): int {
    $v = (int)setting('posts_per_page', (string)POSTS_PER_PAGE);
    return max(1, $v);
}

function getPosts(int $page = 1, ?string $category = null, ?int $perPage = null): array {
    $pdo = db();
    $perPage = $perPage ?? postsPerPage();
    $offset = ($page - 1) * $perPage;
    $where = "status = 'published'";
    $params = [];
    if ($category) {
        $where .= " AND (category = :cat OR EXISTS (SELECT 1 FROM post_categories pc WHERE pc.post_id = posts.id AND pc.cat_name = :cat))";
        $params[':cat'] = $category;
    }
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE $where ORDER BY published_at DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function countPosts(?string $category = null): int {
    $pdo = db();
    $where = "status = 'published'";
    $params = [];
    if ($category) {
        $where .= " AND (category = ? OR EXISTS (SELECT 1 FROM post_categories pc WHERE pc.post_id = posts.id AND pc.cat_name = ?))";
        $params[] = $category;
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

/**
 * @param string|string[] $categories  Kategoria główna lub tablica wszystkich kategorii artykułu.
 */
function getRelatedPosts(string|array $categories, int $excludeId, int $limit = 3): array {
    $pdo = db();
    // Normalizuj do tablicy
    if (is_string($categories)) $categories = [$categories];
    $categories = array_values(array_unique(array_filter($categories)));
    $primaryCat = $categories[0] ?? '';

    $tagsStmt = $pdo->prepare('SELECT tag_id FROM post_tags WHERE post_id = ?');
    $tagsStmt->execute([$excludeId]);
    $tagIds = array_map(fn($r) => (int)$r['tag_id'], $tagsStmt->fetchAll());

    // Buduj wyrażenie dopasowania kategorii (+2 za każdą wspólną kategorię)
    $catPh = implode(',', array_fill(0, count($categories), '?'));
    $catMatchExpr = count($categories)
        ? "(SELECT COUNT(*) FROM post_categories pc2 WHERE pc2.post_id = p.id AND pc2.cat_name IN ($catPh)) * 2
           + CASE WHEN p.category IN ($catPh) THEN 2 ELSE 0 END"
        : '0';

    if (!$tagIds) {
        // Fallback: brak tagów — sortuj po kategorii + dacie
        $sql = "SELECT p.* FROM posts p
                WHERE p.id != ? AND p.status = 'published'
                ORDER BY ($catMatchExpr) DESC, p.published_at DESC
                LIMIT ?";
        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($categories as $c) $stmt->bindValue($i++, $c);
        foreach ($categories as $c) $stmt->bindValue($i++, $c);
        $stmt->bindValue($i++, $excludeId, PDO::PARAM_INT);
        $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    $tagPh = implode(',', array_fill(0, count($tagIds), '?'));
    $sql = "
        SELECT p.*,
               (SELECT COUNT(*) FROM post_tags pt WHERE pt.post_id = p.id AND pt.tag_id IN ($tagPh)) AS shared_tags,
               ($catMatchExpr) AS cat_match
        FROM posts p
        WHERE p.id != ? AND p.status = 'published'
        HAVING (shared_tags + cat_match) > 0
        ORDER BY (shared_tags + cat_match) DESC, p.published_at DESC
        LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $i = 1;
    foreach ($tagIds as $tid) $stmt->bindValue($i++, $tid, PDO::PARAM_INT);
    foreach ($categories as $c) $stmt->bindValue($i++, $c);
    foreach ($categories as $c) $stmt->bindValue($i++, $c);
    $stmt->bindValue($i++, $excludeId, PDO::PARAM_INT);
    $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (count($rows) >= $limit) return $rows;

    // Dopełnij świeżymi z kategorii głównej
    $haveIds = array_map(fn($r) => (int)$r['id'], $rows);
    $excludeIds = array_merge([$excludeId], $haveIds);
    $need = $limit - count($rows);
    $ph = implode(',', array_fill(0, count($excludeIds), '?'));
    $fill = $pdo->prepare("SELECT * FROM posts WHERE category = ? AND id NOT IN ($ph) AND status='published' ORDER BY published_at DESC LIMIT ?");
    $i = 1;
    $fill->bindValue($i++, $primaryCat);
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

function maxCategoriesPerPost(): int {
    return max(1, (int)setting('max_categories_per_post', '2'));
}

/* ===== Domyślne prompty AI (fallback, gdy ustawienie puste) ===== */

function defaultSystemPrompt(): string {
    return "Jesteś dziennikarzem branżowym piszącym po polsku dla minimalistycznej gazety online o SEO, GEO, reklamie cyfrowej (ADS) i AI.\n\nNa podstawie poniższego artykułu źródłowego napisz oryginalne, ciekawe streszczenie po polsku — w formie samodzielnego newsa redakcyjnego, nie kopiując zdań ze źródła. Tekst powinien:\n- mieć ok. 300–500 słów,\n- być zwięzły, informacyjny i konkretny,\n- używać prostego języka, krótkich akapitów <p>,\n- zawierać 1-2 śródtytuły <h2> oraz listę <ul> jeśli to naturalne,\n- nie zaczynać od „W artykule…\", „Według…\" — pisz wprost,\n- na końcu dodać akapit „Dlaczego to ważne\" w 2-3 zdaniach.\n\nZwróć WYŁĄCZNIE poprawny JSON o strukturze:\n{\n  \"title\": \"chwytliwy tytuł po polsku, max 80 znaków\",\n  \"subtitle\": \"krótki podtytuł po polsku, max 140 znaków\",\n  \"excerpt\": \"zajawka 1-2 zdania po polsku, max 220 znaków\",\n  \"content\": \"treść w prostym HTML (<p>, <h2>, <ul>, <li>, <strong>, <em>, <blockquote>)\",\n  \"category\": \"nazwa kategorii GŁÓWNEJ z podanej listy\",\n  \"extra_categories\": [\"opcjonalnie dodatkowe pasujące kategorie z listy — patrz instrukcja w wiadomości użytkownika\"],\n  \"keywords\": \"5-7 słów kluczowych po polsku, przecinki\",\n  \"image_alt\": \"opis sugerowanego obrazu po polsku, max 120 znaków\",\n  \"tags\": [\"tablica nazw firm/marek/produktów występujących w tekście, max 3\"],\n  \"tldr\": \"2-3 zdania streszczenia (TL;DR) na sam początek — kluczowy fakt + dlaczego ważne, do 280 znaków, idealne do cytowania przez AI\"\n}";
}

function defaultCategoryPrompt(): string {
    return "Dostępne kategorie:\n{categories}\n\n"
        . "Wybierz kategorię GŁÓWNĄ, która najlepiej pasuje do artykułu, i wpisz jej nazwę w pole \"category\".\n"
        . "Jeśli artykuł wyraźnie pasuje także do innych kategorii z powyższej listy, wpisz ich nazwy w pole \"extra_categories\" jako tablicę JSON (maksymalnie {extra_max} dodatkowych). "
        . "Jeśli pasuje tylko jedna kategoria — zwróć \"extra_categories\": [].\n"
        . "Używaj wyłącznie nazw dokładnie z listy powyżej.";
}

function defaultTagsPrompt(): string {
    return "Wyciągnij z artykułu listę tagów — TYLKO nazwy firm, marek, produktów lub usług, które bezpośrednio występują w tekście "
        . "(np. Google, Bing, Perplexity, ChatGPT, Anthropic, Microsoft Edge, Bing Ads). "
        . "Maks. {max_tags} tagów, oryginalna pisownia nazw własnych. "
        . "Pomiń ogólne pojęcia (SEO, AI, marketing). Jeśli żadna marka/firma nie występuje — zwróć pustą tablicę. "
        . "Zwróć pole \"tags\" jako JSON-tablicę stringów, np. [\"Google\",\"Perplexity\"].";
}

/** Efektywny prompt (ustawienie użytkownika lub domyślny). */
function effectivePrompt(string $key, callable $default): string {
    $v = (string)setting($key, '');
    return trim($v) !== '' ? $v : $default();
}

/**
 * Zwraca wszystkie kategorie artykułu (pierwsza = główna).
 * Dla starych artykułów bez wpisów w post_categories fallback do posts.category.
 */
function getPostCategories(int $postId): array {
    $pdo = db();
    // Zwróć wpisy z post_categories; główna (z posts.category) zawsze na początku
    $stmt = $pdo->prepare(
        'SELECT pc.cat_name FROM post_categories pc
         JOIN posts p ON p.id = pc.post_id
         WHERE pc.post_id = ?
         ORDER BY CASE WHEN pc.cat_name = p.category THEN 0 ELSE 1 END, pc.cat_name'
    );
    $stmt->execute([$postId]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($rows)) return $rows;
    // Fallback: stary artykuł bez wpisów junction
    $s = $pdo->prepare('SELECT category FROM posts WHERE id = ?');
    $s->execute([$postId]);
    $cat = $s->fetchColumn();
    return $cat ? [$cat] : [];
}

/**
 * Zapisuje kategorie artykułu.
 * @param string   $primary  Kategoria główna (trafia też do posts.category — nie ruszamy jej tu)
 * @param string[] $all      Wszystkie kategorie (łącznie z główną)
 */
function attachCategoriesToPost(int $postId, string $primary, array $all): void {
    $pdo = db();
    $pdo->prepare('DELETE FROM post_categories WHERE post_id = ?')->execute([$postId]);
    $ins = $pdo->prepare('INSERT OR IGNORE INTO post_categories (post_id, cat_name) VALUES (?, ?)');
    $ins->execute([$postId, $primary]);
    foreach ($all as $cat) {
        $cat = trim($cat);
        if ($cat !== '' && $cat !== $primary) {
            $ins->execute([$postId, $cat]);
        }
    }
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

/**
 * Zwraca <link rel="icon"> dla wgranego favicona albo domyślny SVG z motywu.
 */
function faviconUrl(): string {
    $v = trim((string)setting('site_favicon', ''));
    if ($v !== '') return UPLOAD_URL . '/' . $v;
    return BASE_URL . '/assets/images/favicon.svg';
}

function faviconMimeType(): string {
    $v = trim((string)setting('site_favicon', ''));
    $ext = $v !== '' ? strtolower(pathinfo($v, PATHINFO_EXTENSION)) : 'svg';
    return match ($ext) {
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        default => 'image/svg+xml',
    };
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

function getPostsByTag(int $tagId, int $page = 1, ?int $perPage = null): array {
    $pdo = db();
    $perPage = $perPage ?? postsPerPage();
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare("SELECT p.* FROM posts p JOIN post_tags pt ON pt.post_id = p.id WHERE pt.tag_id = ? AND p.status = 'published' ORDER BY p.published_at DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(1, $tagId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
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
 * Globalny cache-buster (inkrementowany przy "Wyczyść cache").
 */
function cacheVersion(): string {
    return (string)(setting('cache_version', '1') ?: '1');
}

/**
 * Zwraca URL do pliku w aktywnym motywie. Przykład: themeAssetUrl('style.css').
 * Dodaje filemtime jako cache-buster + globalny cache_version.
 */
function themeAssetUrl(string $file): string {
    $slug = activeTheme();
    $path = __DIR__ . '/../themes/' . $slug . '/' . $file;
    $url  = BASE_URL . '/themes/' . rawurlencode($slug) . '/' . ltrim($file, '/');
    $v = file_exists($path) ? filemtime($path) : time();
    $url .= '?v=' . $v . '.' . cacheVersion();
    return $url;
}

/**
 * Pełne czyszczenie cache: inkrementuje cache_version + reset OPcache.
 * Zwraca tablicę z informacją co zostało wyczyszczone.
 */
function clearAllCaches(): array {
    $result = ['cache_version' => false, 'opcache' => false, 'errors' => []];

    // 1) Inkrementuj cache_version (forsuje re-download wszystkich assets)
    try {
        $current = (int)setting('cache_version', '1');
        setSetting('cache_version', (string)($current + 1));
        $result['cache_version'] = $current + 1;
    } catch (Throwable $e) {
        $result['errors'][] = 'cache_version: ' . $e->getMessage();
    }

    // 2) Reset PHP OPcache (jeśli dostępne)
    if (function_exists('opcache_reset')) {
        try {
            $result['opcache'] = @opcache_reset();
        } catch (Throwable $e) {
            $result['errors'][] = 'opcache: ' . $e->getMessage();
        }
    } else {
        $result['opcache'] = null; // niedostępne
    }

    // 3) Wymuś świeży odczyt z sources przy następnym auto-import
    //    (last_fetched_at = NULL zmusi discovery do natychmiastowego sprawdzenia)
    //    Wyłączone domyślnie żeby nie zmieniać stanu importera - user może chcieć ręcznie

    return $result;
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
 * Oceny artykułów (1-5 gwiazdek).
 */
function ratingIpHash(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return hash('sha256', $ip . '|' . $ua . '|' . (defined('SITE_NAME') ? SITE_NAME : ''));
}

function getPostRatingStats(int $postId): array {
    $stmt = db()->prepare('SELECT COUNT(*) AS cnt, AVG(rating) AS avg FROM post_ratings WHERE post_id = ?');
    $stmt->execute([$postId]);
    $row = $stmt->fetch();
    return [
        'count' => (int)($row['cnt'] ?? 0),
        'average' => $row['cnt'] > 0 ? round((float)$row['avg'], 1) : 0,
    ];
}

function getUserRatingForPost(int $postId): ?int {
    $stmt = db()->prepare('SELECT rating FROM post_ratings WHERE post_id = ? AND ip_hash = ?');
    $stmt->execute([$postId, ratingIpHash()]);
    $row = $stmt->fetch();
    return $row ? (int)$row['rating'] : null;
}

function submitPostRating(int $postId, int $rating): array {
    if ($rating < 1 || $rating > 5) return ['ok' => false, 'msg' => 'Ocena musi być w zakresie 1-5.'];
    $pdo = db();
    $check = $pdo->prepare('SELECT id FROM posts WHERE id = ? AND status = "published"');
    $check->execute([$postId]);
    if (!$check->fetch()) return ['ok' => false, 'msg' => 'Artykuł nie istnieje.'];
    $hash = ratingIpHash();
    $stmt = $pdo->prepare('INSERT INTO post_ratings (post_id, ip_hash, rating) VALUES (?, ?, ?) ON CONFLICT(post_id, ip_hash) DO UPDATE SET rating = excluded.rating, created_at = CURRENT_TIMESTAMP');
    $stmt->execute([$postId, $hash, $rating]);
    return array_merge(['ok' => true, 'user_rating' => $rating], getPostRatingStats($postId));
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
 * Domyślne kategorie cookies w stylu Cookiebot.
 */
function rodoDefaultCategories(): array {
    return [
        [
            'key' => 'necessary',
            'name' => 'Niezbędne',
            'required' => true,
            'consent_mode' => 'security_storage',
            'description' => 'Pliki cookie niezbędne pomagają uczynić stronę zdatną do użytku, włączając podstawowe funkcje, takie jak nawigacja na stronie czy dostęp do bezpiecznych obszarów strony. Strona internetowa nie może funkcjonować poprawnie bez tych ciasteczek.',
            'examples' => ['PHPSESSID', 'csrf', 'rodo_consent'],
        ],
        [
            'key' => 'preferences',
            'name' => 'Preferencje',
            'required' => false,
            'consent_mode' => 'functionality_storage,personalization_storage',
            'description' => 'Pliki cookie preferencji umożliwiają zapamiętanie informacji, które zmieniają sposób wyglądu lub działania strony, jak preferowany język lub region, w którym znajduje się użytkownik.',
            'examples' => [],
        ],
        [
            'key' => 'statistics',
            'name' => 'Statystyka',
            'required' => false,
            'consent_mode' => 'analytics_storage',
            'description' => 'Pliki cookie statystyczne pomagają właścicielom stron internetowych zrozumieć, w jaki sposób różni użytkownicy zachowują się na stronie, gromadząc i zgłaszając anonimowe informacje.',
            'examples' => ['_ga', '_gid', '_gat'],
        ],
        [
            'key' => 'marketing',
            'name' => 'Marketing',
            'required' => false,
            'consent_mode' => 'ad_storage,ad_user_data,ad_personalization',
            'description' => 'Pliki cookie marketingowe stosowane są w celu śledzenia użytkowników na stronach internetowych. Celem jest wyświetlanie reklam, które są istotne i interesujące dla poszczególnych użytkowników i tym samym bardziej cenne dla wydawców i reklamodawców.',
            'examples' => ['_fbp', 'fr', '_gcl_au'],
        ],
    ];
}

function rodoGetCategories(): array {
    $json = setting('rodo_categories', '');
    if ($json) {
        $decoded = json_decode($json, true);
        if (is_array($decoded) && $decoded) return $decoded;
    }
    return rodoDefaultCategories();
}

function rodoEnabled(): bool {
    return setting('rodo_enabled', '0') === '1';
}

/**
 * Generuje skrypt Consent Mode v2 (Google) — wstawiany ZANIM załadują się GTM/GA4/Pixel.
 * Domyślnie wszystko 'denied' + 'security_storage' granted.
 */
function rodoConsentModeDefaults(): string {
    if (!rodoEnabled() || setting('rodo_consent_mode_v2', '1') !== '1') return '';
    return <<<HTML
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('consent', 'default', {
    'ad_storage': 'denied',
    'ad_user_data': 'denied',
    'ad_personalization': 'denied',
    'analytics_storage': 'denied',
    'functionality_storage': 'denied',
    'personalization_storage': 'denied',
    'security_storage': 'granted',
    'wait_for_update': 500
});
gtag('set', 'ads_data_redaction', true);
gtag('set', 'url_passthrough', false);
</script>
HTML;
}

/**
 * Renderuje banner zgody — wstrzykiwany tuż po <body>.
 */
function rodoRenderBanner(): string {
    if (!rodoEnabled()) return '';

    $title = setting('rodo_banner_title', 'Niniejsza strona korzysta z plików cookie');
    $text  = setting('rodo_banner_text', '');
    $position = setting('rodo_banner_position', 'bottom');
    $primary = setting('rodo_color_primary', '#2540b8');
    $lifetime = max(1, (int)setting('rodo_consent_lifetime_days', '365'));
    $cats = rodoGetCategories();
    $policyUrl = BASE_URL . '/strona/polityka-prywatnosci';
    $cookiesUrl = BASE_URL . '/strona/polityka-cookies';

    $btnAcceptAll = e(setting('rodo_accept_all_text', 'Zezwól na wszystkie'));
    $btnAcceptSel = e(setting('rodo_accept_selected_text', 'Zezwól na wybór'));
    $btnReject    = e(setting('rodo_reject_text', 'Odmowa'));

    ob_start();
    ?>
<div id="rodo-banner" class="rodo-banner rodo-banner--<?= e($position) ?>" style="--rodo-primary: <?= e($primary) ?>; --rodo-primary-soft: color-mix(in srgb, <?= e($primary) ?> 10%, transparent)" data-lifetime="<?= (int)$lifetime ?>" data-consent-mode="<?= e(setting('rodo_consent_mode_v2', '1')) ?>" hidden>
    <div class="rodo-banner__inner" role="dialog" aria-labelledby="rodo-title" aria-describedby="rodo-text">
        <header class="rodo-header">
            <span class="rodo-header__icon" aria-hidden="true">🍪</span>
            <h2 id="rodo-title" class="rodo-banner__title"><?= e($title) ?></h2>
        </header>

        <div class="rodo-banner__tabs" role="tablist">
            <button type="button" class="rodo-tab is-active" role="tab" aria-selected="true" data-tab="consent">Twój wybór</button>
            <button type="button" class="rodo-tab" role="tab" aria-selected="false" data-tab="details">Kategorie cookies</button>
            <button type="button" class="rodo-tab" role="tab" aria-selected="false" data-tab="about">Więcej info</button>
        </div>

        <div class="rodo-banner__panels">
            <section class="rodo-panel is-active" data-panel="consent">
                <p id="rodo-text" class="rodo-banner__text"><?= e($text) ?></p>

                <div class="rodo-toggles">
                    <?php foreach ($cats as $cat): ?>
                        <label class="rodo-toggle">
                            <span class="rodo-toggle__switch <?= !empty($cat['required']) ? 'is-required is-on' : '' ?>">
                                <input type="checkbox" data-category="<?= e($cat['key']) ?>" data-consent-mode="<?= e($cat['consent_mode'] ?? '') ?>" <?= !empty($cat['required']) ? 'checked disabled' : '' ?>>
                                <span class="rodo-toggle__slider" aria-hidden="true"></span>
                            </span>
                            <span class="rodo-toggle__name"><?= e($cat['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="rodo-panel" data-panel="details" hidden>
                <?php foreach ($cats as $cat): ?>
                    <details class="rodo-cat" <?= !empty($cat['required']) ? 'open' : '' ?>>
                        <summary>
                            <strong><?= e($cat['name']) ?></strong>
                            <?php if (!empty($cat['required'])): ?>
                                <span class="rodo-cat__badge">Zawsze aktywne</span>
                            <?php endif; ?>
                        </summary>
                        <p><?= e($cat['description']) ?></p>
                        <?php if (!empty($cat['examples'])): ?>
                            <p class="rodo-cat__examples"><strong>Przykłady:</strong> <?= e(implode(', ', $cat['examples'])) ?></p>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>
            </section>

            <section class="rodo-panel" data-panel="about" hidden>
                <p>Pliki cookie to drobne notatki zostawiane w Twojej przeglądarce, które pomagają stronie zapamiętać Twoje preferencje i sprawnie działać.</p>
                <p>Część z nich jest absolutnie konieczna — bez nich logowanie czy formularze nie zadziałają. Pozostałe są opcjonalne i służą do statystyk, personalizacji lub reklamy.</p>
                <p>Sam decydujesz, na co się zgadzasz. W każdej chwili możesz wrócić do tego okna, klikając ikonę 🍪 w lewym dolnym rogu strony.</p>
                <p>Pełne informacje znajdziesz w naszej <a href="<?= e($policyUrl) ?>">Polityce prywatności</a> oraz <a href="<?= e($cookiesUrl) ?>">Polityce cookies</a>.</p>
            </section>
        </div>

        <div class="rodo-banner__buttons">
            <button type="button" class="rodo-btn rodo-btn--reject" data-action="reject"><?= $btnReject ?></button>
            <button type="button" class="rodo-btn rodo-btn--selected" data-action="selected"><?= $btnAcceptSel ?></button>
            <button type="button" class="rodo-btn rodo-btn--all" data-action="all"><?= $btnAcceptAll ?></button>
        </div>
    </div>
</div>

<!-- Pływający przycisk "Zarządzaj cookies" -->
<button type="button" id="rodo-toggle-btn" class="rodo-toggle-btn" title="Zarządzaj zgodą cookies" aria-label="Otwórz preferencje cookies" hidden>🍪</button>

<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/rodo.css">
<script src="<?= e(BASE_URL) ?>/assets/js/rodo.js" defer></script>
<?php
    return ob_get_clean();
}

/**
 * Generuje treść polityki prywatności (uniwersalna, dla osoby fizycznej lub firmy).
 * Wywoływana z admin/rodo.php po zmianie ustawień.
 */
function rodoGeneratePrivacyPolicy(): string {
    $name = trim((string)setting('rodo_company_name', ''));
    $email = trim((string)setting('rodo_company_email', ''));
    $address = trim((string)setting('rodo_company_address', ''));
    $nip = trim((string)setting('rodo_company_nip', ''));
    $dpo = trim((string)setting('rodo_dpo_contact', ''));
    $form = setting('rodo_company_form', 'individual');
    $show = setting('rodo_show_company_data', '0') === '1';
    $siteName = siteName();
    $siteUrl = BASE_URL;
    $date = date('d.m.Y');

    $admin = '';
    if ($show && $name) {
        if ($form === 'company') {
            $admin = '<p>Administratorem danych osobowych jest <strong>' . e($name) . '</strong>';
            if ($address) $admin .= ', z siedzibą: ' . e($address);
            if ($nip) $admin .= ', NIP: ' . e($nip);
            if ($email) $admin .= ', e-mail: <a href="mailto:' . e($email) . '">' . e($email) . '</a>';
            $admin .= ' (dalej: „Administrator").</p>';
        } else {
            $admin = '<p>Administratorem danych osobowych jest ' . e($name);
            if ($email) $admin .= ', adres e-mail: <a href="mailto:' . e($email) . '">' . e($email) . '</a>';
            $admin .= ' (dalej: „Administrator").</p>';
        }
    } else {
        $admin = '<p>Administratorem danych osobowych jest właściciel serwisu <strong>' . e($siteName) . '</strong> dostępnego pod adresem <a href="' . e($siteUrl) . '">' . e($siteUrl) . '</a> (dalej: „Administrator"). Dane kontaktowe Administratora są dostępne na stronie <a href="' . e($siteUrl) . '/kontakt">Kontakt</a>.</p>';
    }

    $dpoSection = $dpo ? '<p>W sprawach związanych z ochroną danych osobowych można kontaktować się z Inspektorem Ochrony Danych pod adresem: <a href="mailto:' . e($dpo) . '">' . e($dpo) . '</a>.</p>' : '';

    return <<<HTML
<p><em>Data ostatniej aktualizacji: {$date}</em></p>

<h2>1. Administrator danych osobowych</h2>
{$admin}
{$dpoSection}

<h2>2. Zakres zbieranych danych</h2>
<p>Administrator zbiera następujące dane osobowe:</p>
<ul>
    <li><strong>Dane podawane dobrowolnie</strong> — w formularzu kontaktowym: imię, adres e-mail, treść wiadomości</li>
    <li><strong>Dane techniczne</strong> — adres IP, informacje o przeglądarce i urządzeniu (zapisywane w logach serwera przez okres do 90 dni dla celów bezpieczeństwa)</li>
    <li><strong>Pliki cookies</strong> — szczegółowo opisane w <a href="/strona/polityka-cookies">Polityce cookies</a></li>
</ul>

<h2>3. Cel i podstawa prawna przetwarzania</h2>
<p>Dane osobowe są przetwarzane w następujących celach:</p>
<ul>
    <li><strong>Obsługa formularza kontaktowego</strong> — na podstawie art. 6 ust. 1 lit. f RODO (prawnie uzasadniony interes Administratora)</li>
    <li><strong>Pliki cookies i analityka</strong> — na podstawie art. 6 ust. 1 lit. a RODO (zgoda użytkownika)</li>
    <li><strong>Bezpieczeństwo serwisu</strong> — na podstawie art. 6 ust. 1 lit. f RODO (prawnie uzasadniony interes)</li>
</ul>

<h2>4. Okres przechowywania danych</h2>
<ul>
    <li>Wiadomości z formularza kontaktowego: do 12 miesięcy lub do zakończenia korespondencji</li>
    <li>Logi serwera: do 90 dni</li>
    <li>Pliki cookies: do momentu wycofania zgody lub upływu czasu ich ważności</li>
</ul>

<h2>5. Prawa użytkownika</h2>
<p>W związku z przetwarzaniem danych osobowych, użytkownik posiada następujące prawa:</p>
<ul>
    <li>Prawo dostępu do swoich danych osobowych (art. 15 RODO)</li>
    <li>Prawo do sprostowania danych (art. 16 RODO)</li>
    <li>Prawo do usunięcia danych — „prawo do bycia zapomnianym" (art. 17 RODO)</li>
    <li>Prawo do ograniczenia przetwarzania (art. 18 RODO)</li>
    <li>Prawo do przenoszenia danych (art. 20 RODO)</li>
    <li>Prawo do wniesienia sprzeciwu wobec przetwarzania (art. 21 RODO)</li>
    <li>Prawo do wycofania zgody w dowolnym momencie (art. 7 ust. 3 RODO)</li>
    <li>Prawo do wniesienia skargi do organu nadzorczego — Prezesa Urzędu Ochrony Danych Osobowych (PUODO), ul. Stawki 2, 00-193 Warszawa</li>
</ul>

<h2>6. Odbiorcy danych</h2>
<p>Dane mogą być udostępniane:</p>
<ul>
    <li>Dostawcy hostingu (przechowywanie danych na serwerach)</li>
    <li>Dostawcy usług analitycznych (Google Analytics) — tylko po wyrażeniu zgody</li>
    <li>Dostawcy usług reklamowych (Google Ads, Meta) — tylko po wyrażeniu zgody</li>
    <li>Organy państwowe — jeśli wymaga tego obowiązujące prawo</li>
</ul>

<h2>7. Przekazywanie danych poza EOG</h2>
<p>W przypadku korzystania z usług Google (Analytics, Ads) oraz Meta (Pixel), dane mogą być przekazywane do USA. Przekazywanie odbywa się na podstawie standardowych klauzul umownych zatwierdzonych przez Komisję Europejską oraz Data Privacy Framework.</p>

<h2>8. Profilowanie i decyzje automatyczne</h2>
<p>Administrator nie podejmuje decyzji w sposób wyłącznie zautomatyzowany, w tym profilowania, które wywoływałoby skutki prawne dla użytkownika lub istotnie wpływało na jego sytuację.</p>

<h2>9. Zmiany Polityki prywatności</h2>
<p>Administrator zastrzega sobie prawo do zmian w niniejszej Polityce prywatności. Aktualna wersja jest zawsze dostępna na tej stronie z podaną datą ostatniej aktualizacji.</p>
HTML;
}

function rodoGenerateCookiesPolicy(): string {
    $siteName = siteName();
    $cats = rodoGetCategories();
    $date = date('d.m.Y');

    $catsHtml = '';
    foreach ($cats as $c) {
        $catsHtml .= '<h3>' . e($c['name']) . '</h3>';
        $catsHtml .= '<p>' . e($c['description']) . '</p>';
        if (!empty($c['examples'])) {
            $catsHtml .= '<p><strong>Przykłady plików cookies:</strong> ' . e(implode(', ', $c['examples'])) . '</p>';
        }
    }

    return <<<HTML
<p><em>Data ostatniej aktualizacji: {$date}</em></p>

<h2>Czym są pliki cookies?</h2>
<p>Pliki cookies (tzw. „ciasteczka") to małe pliki tekstowe wysyłane przez serwis internetowy i zapisywane na urządzeniu użytkownika (komputerze, telefonie itp.). Pliki te służą do prawidłowego działania serwisu, statystyki odwiedzin oraz personalizacji treści.</p>

<h2>Jakie cookies wykorzystuje {$siteName}?</h2>
{$catsHtml}

<h2>Zarządzanie zgodą na cookies</h2>
<p>Możesz w każdej chwili zmienić lub wycofać swoją zgodę na używanie cookies, klikając ikonę „Zarządzaj zgodą cookies" w prawym dolnym rogu strony.</p>

<h2>Zarządzanie cookies w przeglądarce</h2>
<p>Możesz również zarządzać plikami cookies bezpośrednio w ustawieniach swojej przeglądarki:</p>
<ul>
    <li><a href="https://support.google.com/chrome/answer/95647" target="_blank" rel="noopener">Google Chrome</a></li>
    <li><a href="https://support.mozilla.org/pl/kb/ciasteczka" target="_blank" rel="noopener">Mozilla Firefox</a></li>
    <li><a href="https://support.apple.com/pl-pl/guide/safari/sfri11471/mac" target="_blank" rel="noopener">Safari</a></li>
    <li><a href="https://support.microsoft.com/pl-pl/microsoft-edge/usuwanie-plik%C3%B3w-cookie-w-przegl%C4%85darce-microsoft-edge-63947406-40ac-c3b8-57b9-2a946a29ae09" target="_blank" rel="noopener">Microsoft Edge</a></li>
</ul>

<h2>Zgody zewnętrzne (Consent Mode)</h2>
<p>Po wyrażeniu zgody na cookies statystyki i marketingowe, dane mogą być przesyłane do następujących usług:</p>
<ul>
    <li><strong>Google</strong> (Analytics, Ads) — <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">polityka prywatności</a></li>
    <li><strong>Meta</strong> (Facebook Pixel) — <a href="https://www.facebook.com/privacy/policy" target="_blank" rel="noopener">polityka prywatności</a></li>
</ul>
HTML;
}

/**
 * Tworzy lub aktualizuje stronę o danym slug-u.
 */
function rodoUpsertPage(string $slug, string $title, string $content, string $metaDesc = ''): void {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM pages WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    if ($row) {
        $upd = $pdo->prepare('UPDATE pages SET title=?, content=?, meta_title=?, meta_description=?, status="published", updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $upd->execute([$title, $content, $title, $metaDesc, (int)$row['id']]);
    } else {
        $ins = $pdo->prepare('INSERT INTO pages (slug, title, content, meta_title, meta_description, status, sort_order) VALUES (?, ?, ?, ?, ?, "published", 100)');
        $ins->execute([$slug, $title, $content, $title, $metaDesc]);
    }
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

function themeAiPromptDefault(): string {
    return <<<'PROMPT'
# Zadanie: Stwórz motyw dla CMS "bziku CMS"

Stwórz kompletny motyw graficzny w formacie .zip, zgodny z poniższą specyfikacją. Motyw NIE modyfikuje HTML-a ani logiki PHP — tylko warstwę wizualną przez CSS.

## Struktura archiwum

Archiwum .zip musi zawierać (na poziomie root lub w jednym folderze głównym):

```
moj-motyw/
├── theme.json          (WYMAGANY — manifest motywu)
├── style.css           (WYMAGANY — główny stylesheet)
├── screenshot.svg      (zalecany — podgląd 400×300 px, SVG lub PNG)
└── assets/             (opcjonalnie — fonty, obrazy, dodatkowe CSS)
```

## theme.json — manifest

```json
{
    "name": "Nazwa Motywu",
    "slug": "nazwa-motywu",
    "version": "1.0.0",
    "author": "Twoje imię / nazwa",
    "description": "Krótki opis stylu (max 240 znaków): paleta, typografia, charakter.",
    "tags": ["minimal", "dark", "magazine"],
    "supports": ["dark-mode", "responsive", "hamburger-menu", "tag-cloud"],
    "preview_color": "#1a1a2e",
    "screenshot": "screenshot.svg",
    "fonts": ["Inter", "Playfair Display"],
    "requires_php": "8.0",
    "tested_with": "1.0"
}
```

Pola wymagane: `name`, `slug`, `version`. Slug musi być URL-safe (a-z, 0-9, myślniki).
Zarezerwowane slug-i: `classic`, `modern`.

## Wymagane klasy CSS (struktura HTML — NIE zmieniaj jej w kodzie, tylko stylizuj)

CMS renderuje stronę z poniższymi klasami. Twój CSS musi nadać sensowny wygląd KAŻDEJ z nich:

### Layout główny
- `.top-notice`, `.top-notice__icon`, `.top-notice__text` — pasek powyżej nagłówka
- `.masthead`, `.masthead__top`, `.masthead__date`, `.masthead__edition`, `.masthead__title`, `.masthead__logo`, `.masthead__logo-img`, `.masthead__tagline` — nagłówek serwisu
- `.masthead__nav` — nawigacja kategorii (linki <a>)
- `.masthead__menu-toggle`, `.masthead__menu-bars` — hamburger toggle (display:none na desktop, display:flex na ≤720px). MUSI zmieniać wygląd dla `[aria-expanded="true"]`
- `.skip-link` — link "Przejdź do treści" (visible-only-on-focus)
- `.container` — główny wrapper treści
- `.breadcrumbs` — okruszki nawigacyjne
- `.section-title`, `.section-title__count` — nagłówki sekcji

### Strona główna (lista artykułów)
- `.lead-article`, `.lead-article__link`, `.lead-article__image`, `.lead-article__body`, `.lead-article__title`, `.lead-article__subtitle`, `.lead-article__excerpt` — wyróżniony pierwszy artykuł
- `.grid` — kontener kart (grid auto-fill, minmax 280–300px)
- `.card`, `.card__link`, `.card__image`, `.card__body`, `.card__title`, `.card__excerpt` — pojedyncza karta artykułu
- `.kicker` — etykieta kategorii nad tytułem
- `.meta` — metadane (data, czas czytania)
- `.pagination` — paginacja (linki <a>, aktywny ma klasę `.is-active`)
- `.empty` — placeholder pustej listy

### Artykuł (pojedynczy)
- `.article`, `.article__header`, `.article__title`, `.article__subtitle`, `.article__meta`, `.article__hero` (z `<figcaption>`), `.article__content` — treść artykułu
- `.article__content h2`, `.article__content h3`, `.article__content p`, `.article__content ul`, `.article__content ol`, `.article__content blockquote`, `.article__content code`, `.article__content pre`, `.article__content a` — wewnątrz treści (HTML z edytora)
- `.source-attribution` — stopka źródła pod artykułem
- `.article__tags`, `.article__tags-label` — sekcja tagów pod artykułem
- `.tag-list`, `.tag-chip` — lista tagów (chipy)
- `.related` — sekcja powiązanych artykułów

### Formularz kontaktowy
- `.contact-form`, `.contact-form__captcha`, `.contact-success`, `.contact-error`
- `.contact-form input[type=text|email|number]`, `.contact-form textarea`, `.contact-form .btn`

### Stopka serwisu
- `.site-footer`, `.site-footer__inner`, `.site-footer__col`, `.site-footer__title`, `.site-footer__copy`
- `.site-footer__links li a` — lista linków serwisu
- `.site-footer__categories li a`, `.site-footer__cat-name`, `.site-footer__cat-count` — kategorie z licznikami
- `.footer-tag-cloud` — chmura tagów (lista)
- `.footer-tag-chip` + 5 wariantów rozmiaru: `.footer-tag-chip--s1` (najmniejszy) do `.footer-tag-chip--s5` (największy) — skalowanie wg popularności

## Wymagania techniczne

1. **CSS variables na początku** — zdefiniuj `:root { --ink: ...; --paper: ...; --accent: ...; }` itd. dla łatwej modyfikacji
2. **Dark mode** — dodaj `@media (prefers-color-scheme: dark) { :root { ... } }` z odwróconą paletą
3. **Responsywność** — breakpointy:
   - `≤600px` — mobile
   - `601–900px` — tablet
   - `>900px` — desktop
4. **Hamburger** — na `≤720px` ukryj nav (`.masthead__nav { display: none }`) i pokaż toggle. Otwarcie przez JS dodaje klasę `.is-open`
5. **Reduced motion** — `@media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition: none !important; animation: none !important; } }`
6. **Print** — ukryj nav, footer, pagination
7. **A11y** — focus visible na input/select/button (`:focus { outline: ... }`)

## Czego NIE robić

- ❌ Nie modyfikuj HTML (nie ma takiej możliwości — szablon jest globalny)
- ❌ Nie ładuj zewnętrznych CSS-ów przez `@import` (wolno — wkleją się ładnie do <head>)
- ❌ Nie używaj `!important` poza absolutnie niezbędnymi przypadkami (dark mode override, print)
- ❌ Nie używaj JS w motywie (motyw to tylko CSS + opcjonalne assety)
- ❌ Nie wstawiaj plików .php — będą zignorowane
- ❌ Nie umieszczaj malware / iframe / inline JS

## Wzorzec do naśladowania

Sprawdź dwa wbudowane motywy:
- `themes/classic/style.css` — czarno-biały, Playfair + Source Serif, klasyczna gazeta
- `themes/modern/style.css` — Inter sans-serif, akcent czerwony, miękkie cienie, karty

## Po skończeniu

Spakuj cały folder do .zip i wgraj przez panel CMS → Motywy → "Wgraj nowy motyw".
Zainstalowany motyw pojawi się na liście. Kliknij "Aktywuj" by przełączyć wygląd całej strony.

PROMPT;
}

/** Efektywny prompt motywu — edytowalny w zakładce Prompty, z fallbackiem do domyślnego. */
function themeAiPrompt(): string {
    return effectivePrompt('theme_ai_prompt', 'themeAiPromptDefault');
}
