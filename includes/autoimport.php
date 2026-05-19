<?php
/**
 * Auto-import pipeline (dwufazowy, z kolejką w bazie):
 *
 *   FAZA A — Discovery (szybka, ~kilka sekund/źródło):
 *     - Pobiera RSS/Atom albo listing HTML z aktywnych źródeł.
 *     - Filtruje po dedup hashu (sha256 z GUID|URL) — sprawdzane jest
 *       JEDNOCZEŚNIE auto_imports (już opublikowane) i auto_queue (już w kolejce).
 *     - Dla nowych elementów wstawia rekordy do auto_queue ze statusem 'pending'.
 *     - Discovery dla danego źródła odpala się tylko jeśli minęło
 *       auto_discovery_interval_minutes od ostatniego pobrania.
 *
 *   FAZA B — Processing (wolna, limit auto_posts_per_tick):
 *     - Bierze N pending elementów z kolejki (FIFO, najstarsze najpierw).
 *     - Atomowo oznacza je 'processing'.
 *     - Dla każdego: pobiera HTML, wyciąga datę i treść, sumaryzuje OpenAI,
 *       tworzy post, oznacza 'done'.
 *     - Failure → attempts++, exponential backoff (5 min, 30 min, 2 h),
 *       po wyczerpaniu max_attempts → 'failed'.
 *
 * Każdy tick crona robi obie fazy. Dzięki temu run trwa max ~kilkadziesiąt sekund,
 * a kolejka rozładowuje się płynnie nawet przy 100+ artykułach.
 *
 * Wywoływane przez /cron/run.php (HTTP z tokenem) lub bin/auto.php (CLI).
 * Lock plikowy zapobiega nakładającym się uruchomieniom.
 */

require_once __DIR__ . '/functions.php';

class AutoImporter {
    private PDO $pdo;
    private array $log = [];
    private int $runId = 0;
    private int $found = 0;
    private int $imported = 0;
    private int $skipped = 0;
    private int $failed = 0;
    private int $enqueued = 0;

    public function __construct() {
        $this->pdo = db();
    }

    /**
     * @param int|null $maxPostsOverride  Wymuszony limit (ignoruje auto_enabled).
     * @param bool $force  Pomija sprawdzanie auto_enabled (przycisk "Uruchom teraz").
     */
    public function run(?int $maxPostsOverride = null, bool $force = false): array {
        $perTick = $maxPostsOverride ?? (int)setting('auto_posts_per_tick', '2');
        $this->startRun();

        try {
            if (!$force && setting('auto_enabled', '0') !== '1') {
                $this->logLine('Auto-import jest wyłączony w ustawieniach. Pomijam.');
                $this->finishRun('disabled');
                return $this->result();
            }

            if (!setting('openai_api_key')) {
                throw new RuntimeException('Brak OpenAI API key w ustawieniach.');
            }

            // FAZA A — discovery
            $this->discover();

            // FAZA B — przetwarzanie kolejki
            $this->processQueue($perTick);

            $this->finishRun('success');
        } catch (Throwable $e) {
            $this->logLine('FATAL: ' . $e->getMessage());
            $this->finishRun('error', $e->getMessage());
        }
        setSetting('auto_last_run', date('Y-m-d H:i:s'));
        return $this->result();
    }

    // ============================================================
    //  FAZA A — DISCOVERY
    // ============================================================

    private function discover(): void {
        $this->logLine('━━ FAZA A: Discovery ━━');
        $discoveryInterval = max(1, (int)setting('auto_discovery_interval_minutes', '60'));
        $cutoffDiscovery = date('Y-m-d H:i:s', time() - $discoveryInterval * 60);

        $sources = $this->pdo->query('SELECT * FROM sources WHERE enabled = 1 ORDER BY id')->fetchAll();
        if (!$sources) {
            $this->logLine('Brak aktywnych źródeł.');
            return;
        }

        foreach ($sources as $source) {
            if ($source['last_fetched_at'] && $source['last_fetched_at'] > $cutoffDiscovery) {
                $this->logLine("  ⊙ Skipped #{$source['id']} {$source['name']} — pobrane {$source['last_fetched_at']}");
                continue;
            }
            try {
                $count = $this->discoverSource($source);
                $this->enqueued += $count;
            } catch (Throwable $e) {
                $this->updateSource($source['id'], $e->getMessage());
                $this->logLine("  ✗ {$source['name']}: " . $e->getMessage());
            }
        }
        $this->logLine("Faza A zakończona — dodano do kolejki: {$this->enqueued} elementów.");
    }

    private function discoverSource(array $source): int {
        $this->logLine("→ Discovery #{$source['id']}: {$source['name']} [" . ($source['source_type'] ?: 'rss') . "]");

        $globalMaxAge = max(1, (int)setting('auto_max_age_days', '3'));
        $maxAgeDays = !empty($source['max_age_days']) ? (int)$source['max_age_days'] : $globalMaxAge;
        $cutoff = time() - ($maxAgeDays * 86400);

        $items = (($source['source_type'] ?? 'rss') === 'html')
            ? $this->fetchListing($source)
            : $this->fetchFeed($source['feed_url']);

        $this->found += count($items);
        $this->logLine('  Znaleziono ' . count($items) . ' kandydatów w źródle.');

        $added = 0;
        $maxPerSource = (int)$source['max_items_per_run'];
        $considered = 0;
        foreach ($items as $item) {
            if ($considered >= $maxPerSource) break;
            $hash = hash('sha256', $item['guid'] ?: $item['url']);
            if ($this->alreadyImported($hash) || $this->alreadyInQueue($hash)) {
                continue;
            }

            // Wstępny age-filter z RSS-a (jeśli mamy datę). Brak daty z RSS-a NIE
            // pomija — finalny check po dacie z HTML-a robimy w fazie B.
            $rssTs = !empty($item['published']) ? @strtotime($item['published']) : null;
            if ($rssTs !== null && $rssTs > 0 && $rssTs < $cutoff) {
                continue;
            }

            $this->enqueueItem($source['id'], $item, $hash, $rssTs ?: null);
            $added++;
            $considered++;
        }
        $this->updateSource($source['id'], null);
        $this->logLine("  + Do kolejki: {$added}");
        return $added;
    }

    private function alreadyInQueue(string $hash): bool {
        $stmt = $this->pdo->prepare('SELECT id FROM auto_queue WHERE guid_hash = ?');
        $stmt->execute([$hash]);
        return (bool)$stmt->fetch();
    }

    private function enqueueItem(int $sourceId, array $item, string $hash, ?int $publishedTs): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO auto_queue (source_id, external_url, external_guid, guid_hash, title, description, published_ts, status, next_attempt_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $sourceId,
            $item['url'],
            $item['guid'] ?? '',
            $hash,
            mb_substr($item['title'] ?? '', 0, 500),
            mb_substr($item['description'] ?? '', 0, 2000),
            $publishedTs,
        ]);
    }

    // ============================================================
    //  FAZA B — PROCESSING
    // ============================================================

    private function processQueue(int $maxPerTick): void {
        $this->logLine("━━ FAZA B: Processing (max {$maxPerTick} na ten tick) ━━");

        $rows = $this->pdo->prepare("
            SELECT * FROM auto_queue
            WHERE status = 'pending'
              AND (next_attempt_at IS NULL OR next_attempt_at <= datetime('now'))
            ORDER BY id ASC
            LIMIT ?
        ");
        $rows->bindValue(1, $maxPerTick, PDO::PARAM_INT);
        $rows->execute();
        $items = $rows->fetchAll();

        if (!$items) {
            $this->logLine('  Kolejka pusta.');
            return;
        }

        // Atomowo "claimujemy" wybrane wiersze.
        $ids = array_map(fn($r) => (int)$r['id'], $items);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare("UPDATE auto_queue SET status = 'processing', updated_at = CURRENT_TIMESTAMP WHERE id IN ($placeholders) AND status = 'pending'")
            ->execute($ids);

        $this->logLine('  Przejmuję z kolejki: #' . implode(', #', $ids));

        foreach ($items as $row) {
            $this->processQueueItem($row);
        }
    }

    private function processQueueItem(array $row): void {
        $sourceStmt = $this->pdo->prepare('SELECT * FROM sources WHERE id = ?');
        $sourceStmt->execute([$row['source_id']]);
        $source = $sourceStmt->fetch();
        if (!$source) {
            $this->markQueueFailed((int)$row['id'], 'Źródło zostało usunięte.');
            $this->failed++;
            return;
        }

        $globalMaxAge = max(1, (int)setting('auto_max_age_days', '3'));
        $maxAgeDays = !empty($source['max_age_days']) ? (int)$source['max_age_days'] : $globalMaxAge;
        $cutoff = time() - ($maxAgeDays * 86400);

        $title = $row['title'] ?: $row['external_url'];
        $this->logLine('  · Przetwarzam #' . $row['id'] . ': ' . mb_substr($title, 0, 70));

        try {
            $html = $this->httpGet($row['external_url'], 20);
            $htmlTs = $this->extractDateFromHtml($html);
            $effectiveTs = $row['published_ts'] ? (int)$row['published_ts'] : $htmlTs;

            if ($effectiveTs === null) {
                $this->markQueueSkipped((int)$row['id'], 'Brak wykrytej daty publikacji.');
                $this->skipped++;
                $this->logLine('    ⊘ Brak daty — pomijam.');
                return;
            }
            if ($effectiveTs < $cutoff) {
                $this->markQueueSkipped((int)$row['id'], 'Artykuł starszy niż ' . $maxAgeDays . ' dni (' . date('Y-m-d', $effectiveTs) . ').');
                $this->skipped++;
                $this->logLine('    ⏰ Za stary (' . date('Y-m-d', $effectiveTs) . ') — pomijam.');
                return;
            }

            $content = $this->extractMainText($html);
            if (mb_strlen($content) < 400) $content = trim(strip_tags($row['description'] ?? ''));
            if (mb_strlen($content) < 200) {
                throw new RuntimeException('Za mało treści do streszczenia (' . mb_strlen($content) . ' znaków).');
            }

            $item = [
                'title' => $row['title'] ?: ($this->extractTitleFromHtml($html) ?: $row['external_url']),
                'url' => $row['external_url'],
                'guid' => $row['external_guid'] ?? '',
                'description' => $row['description'] ?? '',
                'published_ts' => $effectiveTs,
            ];

            $generated = $this->summarize($content, $item, $source);
            $postId = $this->savePost($generated, $item, $source);
            $this->markQueueDone((int)$row['id'], $postId);
            $this->markImported($row['guid_hash'], (int)$source['id'], $item, $postId);
            $this->imported++;
            $this->logLine("    ✓ Post #{$postId}: " . $generated['title']);
        } catch (Throwable $e) {
            $this->failed++;
            $attempts = (int)$row['attempts'] + 1;
            $maxAttempts = (int)$row['max_attempts'];
            if ($attempts >= $maxAttempts) {
                $this->markQueueFailed((int)$row['id'], $e->getMessage());
                $this->logLine("    ✗ FAILED po {$attempts} próbach: " . $e->getMessage());
            } else {
                $backoff = [5, 30, 120][$attempts - 1] ?? 120;
                $nextAttempt = date('Y-m-d H:i:s', time() + $backoff * 60);
                $this->pdo->prepare("UPDATE auto_queue SET status='pending', attempts=?, next_attempt_at=?, error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$attempts, $nextAttempt, $e->getMessage(), $row['id']]);
                $this->logLine("    ↻ Retry #{$attempts}/{$maxAttempts} za {$backoff} min: " . $e->getMessage());
            }
        }
    }

    private function markQueueDone(int $id, int $postId): void {
        $this->pdo->prepare("UPDATE auto_queue SET status='done', post_id=?, error=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$postId, $id]);
    }
    private function markQueueSkipped(int $id, string $reason): void {
        $this->pdo->prepare("UPDATE auto_queue SET status='skipped', error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$reason, $id]);
    }
    private function markQueueFailed(int $id, string $reason): void {
        $this->pdo->prepare("UPDATE auto_queue SET status='failed', error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$reason, $id]);
    }

    private function fetchListing(array $source): array {
        $baseUrl = $source['feed_url'];
        $html = $this->httpGet($baseUrl, 20);

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        $selector = trim($source['link_selector'] ?? '');
        if ($selector !== '') {
            $expr = $this->isXPath($selector) ? $selector : $this->cssToXPath($selector);
        } else {
            $expr = '//article//a[@href] | //h1//a[@href] | //h2//a[@href] | //h3//a[@href]';
        }

        $nodes = @$xpath->query($expr);
        if (!$nodes) {
            throw new RuntimeException('Selektor nic nie znalazł: ' . $expr);
        }

        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        $items = [];
        $seen = [];
        foreach ($nodes as $node) {
            $href = trim($node->getAttribute('href'));
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            if ($href === '' || $href[0] === '#') continue;
            $abs = $this->resolveUrl($href, $baseUrl);
            if (!$abs) continue;
            // Trzymaj się tej samej domeny
            if (parse_url($abs, PHP_URL_HOST) !== $baseHost) continue;
            // Pomiń linki listingowe / strony kategorii
            $path = parse_url($abs, PHP_URL_PATH) ?? '';
            if ($path === '' || $path === '/') continue;
            if ($abs === $baseUrl) continue;
            if (mb_strlen($text) < 12) continue;  // za krótki tekst kotwicy — pewnie nie tytuł
            if (isset($seen[$abs])) continue;
            $seen[$abs] = true;
            $items[] = [
                'title' => $text,
                'url' => $abs,
                'guid' => $abs,
                'description' => '',
                'published' => '',
            ];
            if (count($items) >= 30) break;
        }
        return $items;
    }

    private function extractDateFromHtml(string $html): ?int {
        // 1) <meta property="article:published_time" ...>
        if (preg_match('#<meta[^>]+property=["\']article:(?:published_time|modified_time)["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m)) {
            $t = strtotime($m[1]); if ($t) return $t;
        }
        // 2) <meta name="..." content="ISO">
        foreach (['datePublished','dateModified','pubdate','date','article:published','article:modified'] as $attr) {
            if (preg_match('#<meta[^>]+(?:name|itemprop|property)=["\']' . preg_quote($attr, '#') . '["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m)) {
                $t = strtotime($m[1]); if ($t) return $t;
            }
        }
        // 3) JSON-LD (NewsArticle / Article / BlogPosting)
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', $html, $matches)) {
            foreach ($matches[1] as $json) {
                $data = json_decode($json, true);
                if (!is_array($data)) continue;
                $found = $this->jsonLdDate($data);
                if ($found) return $found;
            }
        }
        // 4) <time datetime="...">
        if (preg_match('#<time[^>]+datetime=["\']([^"\']+)["\']#i', $html, $m)) {
            $t = strtotime($m[1]); if ($t) return $t;
        }
        return null;
    }

    private function jsonLdDate($data): ?int {
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $node) {
                $r = $this->jsonLdDate($node);
                if ($r) return $r;
            }
        }
        $type = $data['@type'] ?? '';
        $types = is_array($type) ? $type : [$type];
        $articleLike = array_intersect($types, ['NewsArticle','Article','BlogPosting','Report','TechArticle']);
        if (!empty($articleLike) || isset($data['datePublished']) || isset($data['dateModified'])) {
            foreach (['datePublished','dateModified','dateCreated'] as $k) {
                if (!empty($data[$k])) {
                    $t = strtotime((string)$data[$k]);
                    if ($t) return $t;
                }
            }
        }
        if (is_array($data)) {
            foreach ($data as $v) {
                if (is_array($v)) {
                    $r = $this->jsonLdDate($v);
                    if ($r) return $r;
                }
            }
        }
        return null;
    }

    private function extractTitleFromHtml(string $html): ?string {
        if (preg_match('#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('#<h1[^>]*>(.*?)</h1>#si', $html, $m)) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('#<title[^>]*>(.*?)</title>#si', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return null;
    }

    private function isXPath(string $s): bool {
        return $s !== '' && ($s[0] === '/' || str_starts_with($s, '(') || str_starts_with($s, './'));
    }

    private function cssToXPath(string $css): string {
        // Konwersja prostych selektorów CSS na XPath. Wystarcza dla typowych przypadków.
        $parts = preg_split('/\s+/', trim($css));
        $xpath = '';
        foreach ($parts as $part) {
            $segment = '*';
            if (preg_match('/^([a-z][a-z0-9]*)/i', $part, $m)) {
                $segment = $m[1];
                $part = substr($part, strlen($m[1]));
            }
            $predicates = [];
            if (preg_match('/#([\w-]+)/', $part, $m)) {
                $predicates[] = "@id='" . $m[1] . "'";
            }
            if (preg_match_all('/\.([\w-]+)/', $part, $mm)) {
                foreach ($mm[1] as $cls) {
                    $predicates[] = "contains(concat(' ', normalize-space(@class), ' '), ' {$cls} ')";
                }
            }
            $node = $segment . ($predicates ? '[' . implode(' and ', $predicates) . ']' : '');
            $xpath .= '//' . $node;
        }
        return $xpath ?: '//a';
    }

    private function resolveUrl(string $href, string $base): ?string {
        if (preg_match('#^https?://#i', $href)) return $href;
        $b = parse_url($base);
        if (!$b || empty($b['scheme']) || empty($b['host'])) return null;
        $origin = $b['scheme'] . '://' . $b['host'];
        if ($href === '' || $href[0] === '?') return null;
        if ($href[0] === '/') return $origin . $href;
        $basePath = $b['path'] ?? '/';
        $basePath = preg_replace('#/[^/]*$#', '/', $basePath);
        return $origin . $basePath . $href;
    }

    private function fetchFeed(string $url): array {
        $body = $this->httpGet($url, 15);
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$xml) {
            $errs = array_map(fn($e) => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            throw new RuntimeException('XML parse error: ' . implode('; ', array_slice($errs, 0, 2)));
        }
        $items = [];
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $i) {
                $items[] = [
                    'title' => trim((string)$i->title),
                    'url' => trim((string)$i->link),
                    'guid' => trim((string)($i->guid ?: $i->link)),
                    'description' => trim((string)($i->description ?: '')),
                    'published' => trim((string)($i->pubDate ?: '')),
                ];
            }
        } elseif (isset($xml->entry)) {
            foreach ($xml->entry as $e) {
                $link = '';
                foreach ($e->link as $l) {
                    $rel = (string)$l['rel'];
                    if ($rel === '' || $rel === 'alternate') { $link = (string)$l['href']; break; }
                }
                $items[] = [
                    'title' => trim((string)$e->title),
                    'url' => trim($link),
                    'guid' => trim((string)($e->id ?: $link)),
                    'description' => trim((string)($e->summary ?: $e->content ?: '')),
                    'published' => trim((string)($e->published ?: $e->updated ?: '')),
                ];
            }
        }
        return $items;
    }

    private function alreadyImported(string $hash): bool {
        $stmt = $this->pdo->prepare('SELECT id FROM auto_imports WHERE guid_hash = ?');
        $stmt->execute([$hash]);
        return (bool)$stmt->fetch();
    }

    private function extractMainText(string $html): string {
        $html = preg_replace('#<(script|style|nav|footer|header|aside|form|noscript)\b[^>]*>.*?</\1>#si', ' ', $html);
        if (preg_match('#<article\b[^>]*>(.*?)</article>#si', $html, $m)) {
            $html = $m[1];
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return mb_substr(trim($text), 0, 8000);
    }

    private function summarize(string $sourceText, array $item, array $source): array {
        $apiKey = setting('openai_api_key');
        $model = setting('openai_model', 'gpt-4o-mini');
        $temp = (float)setting('openai_temperature', '0.4');
        $systemPrompt = setting('auto_prompt');

        // Lista kategorii do wyboru — model musi przypisać artykuł do JEDNEJ z nich.
        $cats = $this->pdo->query('SELECT name, description FROM categories ORDER BY sort_order, name')->fetchAll();
        $catLines = array_map(fn($c) => '- ' . $c['name'] . ($c['description'] ? ': ' . $c['description'] : ''), $cats);
        $catList = implode("\n", $catLines);
        $catNames = array_map(fn($c) => $c['name'], $cats);

        $catPrompt = "\n\nDostępne kategorie (wybierz DOKŁADNIE JEDNĄ pasującą i wpisz jej nazwę w pole \"category\"):\n" . $catList;

        $user = "Źródło: {$source['name']}\n"
              . "Oryginalny tytuł: {$item['title']}\n"
              . "URL źródła: {$item['url']}\n"
              . $catPrompt . "\n\n"
              . "Treść źródłowa:\n" . $sourceText;

        $payload = [
            'model' => $model,
            'temperature' => $temp,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new RuntimeException('OpenAI cURL: ' . $err);
        if ($code >= 400) throw new RuntimeException("OpenAI HTTP {$code}: " . substr($resp, 0, 300));

        $data = json_decode($resp, true);
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!$content) throw new RuntimeException('OpenAI: brak treści w odpowiedzi.');

        $parsed = json_decode($content, true);
        if (!is_array($parsed)) throw new RuntimeException('OpenAI: nie zwrócił JSON.');

        foreach (['title','content'] as $req) {
            if (empty($parsed[$req])) throw new RuntimeException("OpenAI: brak pola '{$req}'.");
        }

        // Normalizuj kategorię — model musi się zmieścić w istniejącej liście.
        $parsed['category'] = $this->normalizeCategory($parsed['category'] ?? '', $source, $catNames);
        return $parsed;
    }

    private function normalizeCategory(string $candidate, array $source, array $available): string {
        $cand = trim($candidate);
        if ($cand !== '') {
            $low = mb_strtolower($cand);
            foreach ($available as $name) {
                if (mb_strtolower($name) === $low) return $name;
            }
        }
        // Fallback do kategorii źródła (jeśli istnieje na liście) albo do domyślnej.
        if (!empty($source['category'])) {
            $low = mb_strtolower($source['category']);
            foreach ($available as $name) {
                if (mb_strtolower($name) === $low) return $name;
            }
        }
        $default = setting('auto_default_category', 'Aktualności');
        $low = mb_strtolower($default);
        foreach ($available as $name) {
            if (mb_strtolower($name) === $low) return $name;
        }
        return $available[0] ?? 'Aktualności';
    }

    private function savePost(array $gen, array $item, array $source): int {
        $title = trim($gen['title']);
        $subtitle = trim($gen['subtitle'] ?? '');
        $excerpt = trim($gen['excerpt'] ?? '');
        $content = $this->cleanHtml($gen['content']);
        $category = trim($gen['category'] ?? setting('auto_default_category', 'Aktualności'));
        $alt = trim($gen['image_alt'] ?? '');
        $keywords = trim($gen['keywords'] ?? '');
        $author = setting('auto_default_author', 'Redakcja AI');

        $sourceAttribution = sprintf(
            '<hr><p class="source-attribution"><small>Opracowanie redakcji na podstawie: <a href="%s" rel="nofollow noopener" target="_blank">%s</a> (%s).</small></p>',
            e($item['url']),
            e($item['title']),
            e($source['name'])
        );
        $content .= $sourceAttribution;

        $sourceAutoPublish = $source['auto_publish'];
        $globalAutoPublish = setting('auto_publish', '1') === '1';
        $shouldPublish = $sourceAutoPublish === null ? $globalAutoPublish : ((int)$sourceAutoPublish === 1);
        $status = $shouldPublish ? 'published' : 'draft';

        $slug = uniqueSlug(slugify($title));

        $stmt = $this->pdo->prepare("
            INSERT INTO posts (slug, title, subtitle, excerpt, content, featured_image_alt, category, author, meta_title, meta_description, meta_keywords, status, published_at)
            VALUES (:slug, :title, :subtitle, :excerpt, :content, :alt, :cat, :author, :meta_title, :meta_desc, :meta_kw, :status, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':slug' => $slug,
            ':title' => $title,
            ':subtitle' => $subtitle,
            ':excerpt' => $excerpt,
            ':content' => $content,
            ':alt' => $alt,
            ':cat' => $category,
            ':author' => $author,
            ':meta_title' => $title,
            ':meta_desc' => $excerpt,
            ':meta_kw' => $keywords,
            ':status' => $status,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function cleanHtml(string $html): string {
        $allowed = '<p><br><h2><h3><h4><strong><em><b><i><u><a><ul><ol><li><blockquote><code><pre><hr>';
        $clean = strip_tags($html, $allowed);
        $clean = preg_replace('#<a([^>]*)>#i', '<a$1 rel="nofollow noopener" target="_blank">', $clean);
        $clean = preg_replace('#\son\w+="[^"]*"#i', '', $clean);
        return $clean;
    }

    private function markImported(string $hash, int $sourceId, array $item, int $postId): void {
        $this->pdo->prepare('INSERT INTO auto_imports (source_id, external_url, external_guid, guid_hash, post_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$sourceId, $item['url'], $item['guid'], $hash, $postId]);
    }

    private function updateSource(int $id, ?string $error): void {
        $this->pdo->prepare('UPDATE sources SET last_fetched_at = CURRENT_TIMESTAMP, last_error = ? WHERE id = ?')
            ->execute([$error, $id]);
    }

    protected function httpGet(string $url, int $timeout = 15): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TheDailySignal/1.0; +' . BASE_URL . ')',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.7,pl;q=0.5',
            ],
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new RuntimeException("cURL {$url}: {$err}");
        if ($code >= 400) throw new RuntimeException("HTTP {$code} dla {$url}");
        return $body;
    }

    private function startRun(): void {
        $this->pdo->prepare('INSERT INTO auto_runs (status) VALUES (?)')->execute(['running']);
        $this->runId = (int)$this->pdo->lastInsertId();
    }

    private function finishRun(string $status, ?string $error = null): void {
        $this->pdo->prepare('UPDATE auto_runs SET finished_at = CURRENT_TIMESTAMP, status = ?, items_found = ?, items_imported = ?, items_skipped = ?, items_failed = ?, items_enqueued = ?, log = ?, error = ? WHERE id = ?')
            ->execute([$status, $this->found, $this->imported, $this->skipped, $this->failed, $this->enqueued, implode("\n", $this->log), $error, $this->runId]);
    }

    private function logLine(string $msg): void {
        $this->log[] = '[' . date('H:i:s') . '] ' . $msg;
    }

    private function result(): array {
        return [
            'run_id' => $this->runId,
            'found' => $this->found,
            'enqueued' => $this->enqueued,
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'log' => $this->log,
        ];
    }
}

function runAutoImport(?int $maxPosts = null, bool $force = false): array {
    $lockFile = sys_get_temp_dir() . '/daily-signal-auto-' . md5(__DIR__) . '.lock';
    $fh = fopen($lockFile, 'c+');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
        return ['error' => 'Inny run jest już aktywny.'];
    }
    try {
        return (new AutoImporter())->run($maxPosts, $force);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
