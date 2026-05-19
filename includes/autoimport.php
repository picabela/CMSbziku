<?php
/**
 * Auto-import pipeline:
 *   1. Pobiera RSS/Atom z wszystkich aktywnych źródeł.
 *   2. Deduplikacja po hash(GUID|URL).
 *   3. Dla nowych itemów pobiera pełną treść (jeśli się da), wycina tekst.
 *   4. Wysyła do OpenAI z promptem redakcyjnym, otrzymuje JSON.
 *   5. Zapisuje jako post + zapisuje rekord w auto_imports.
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

    public function __construct() {
        $this->pdo = db();
    }

    public function run(?int $maxPostsOverride = null): array {
        $maxPosts = $maxPostsOverride ?? (int)setting('auto_max_posts_per_run', '3');
        $this->startRun();

        try {
            if (setting('auto_enabled', '0') !== '1' && $maxPostsOverride === null) {
                $this->logLine('Auto-import jest wyłączony w ustawieniach. Pomijam.');
                $this->finishRun('disabled');
                return $this->result();
            }

            if (!setting('openai_api_key')) {
                throw new RuntimeException('Brak OpenAI API key w ustawieniach.');
            }

            $sources = $this->pdo->query('SELECT * FROM sources WHERE enabled = 1 ORDER BY id')->fetchAll();
            if (!$sources) {
                $this->logLine('Brak aktywnych źródeł.');
                $this->finishRun('idle');
                return $this->result();
            }

            $imported = 0;
            foreach ($sources as $source) {
                if ($imported >= $maxPosts) {
                    $this->logLine("Osiągnięto limit {$maxPosts} postów na ten run.");
                    break;
                }
                $remaining = $maxPosts - $imported;
                $perSource = min((int)$source['max_items_per_run'], $remaining);
                $imported += $this->processSource($source, $perSource);
            }

            $this->finishRun('success');
        } catch (Throwable $e) {
            $this->logLine('FATAL: ' . $e->getMessage());
            $this->finishRun('error', $e->getMessage());
        }
        setSetting('auto_last_run', date('Y-m-d H:i:s'));
        return $this->result();
    }

    private function processSource(array $source, int $maxItems): int {
        $this->logLine("→ Źródło #{$source['id']}: {$source['name']}");
        try {
            $items = $this->fetchFeed($source['feed_url']);
        } catch (Throwable $e) {
            $this->failed++;
            $this->updateSource($source['id'], $e->getMessage());
            $this->logLine("  ✗ Błąd feed: " . $e->getMessage());
            return 0;
        }

        $this->logLine('  Znaleziono ' . count($items) . ' itemów w feedzie.');
        $this->found += count($items);
        $imported = 0;

        foreach ($items as $item) {
            if ($imported >= $maxItems) break;
            try {
                $hash = hash('sha256', $item['guid'] ?: $item['url']);
                if ($this->alreadyImported($hash)) {
                    $this->skipped++;
                    continue;
                }
                $this->logLine("  · Importuję: " . substr($item['title'], 0, 70));
                $content = $this->fetchArticleText($item['url'], $item['description']);
                $generated = $this->summarize($content, $item, $source);
                $postId = $this->savePost($generated, $item, $source);
                $this->markImported($hash, $source['id'], $item, $postId);
                $imported++;
                $this->imported++;
                $this->logLine("    ✓ Post #{$postId}: " . $generated['title']);
            } catch (Throwable $e) {
                $this->failed++;
                $this->logLine("    ✗ " . $e->getMessage());
            }
        }
        $this->updateSource($source['id'], null);
        return $imported;
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

    private function fetchArticleText(string $url, string $fallback): string {
        try {
            $html = $this->httpGet($url, 20);
            $text = $this->extractMainText($html);
            if (mb_strlen($text) > 400) return $text;
        } catch (Throwable $e) {
            // fall back
        }
        return trim(strip_tags($fallback));
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

        $user = "Źródło: {$source['name']}\n"
              . "Oryginalny tytuł: {$item['title']}\n"
              . "URL źródła: {$item['url']}\n\n"
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
        return $parsed;
    }

    private function savePost(array $gen, array $item, array $source): int {
        $title = trim($gen['title']);
        $subtitle = trim($gen['subtitle'] ?? '');
        $excerpt = trim($gen['excerpt'] ?? '');
        $content = $this->cleanHtml($gen['content']);
        $category = trim($gen['category'] ?? ($source['category'] ?: setting('auto_default_category', 'Aktualności')));
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

    private function httpGet(string $url, int $timeout = 15): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'TheDailySignalBot/1.0 (+' . BASE_URL . ')',
            CURLOPT_HTTPHEADER => ['Accept: */*'],
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
        $this->pdo->prepare('UPDATE auto_runs SET finished_at = CURRENT_TIMESTAMP, status = ?, items_found = ?, items_imported = ?, items_skipped = ?, items_failed = ?, log = ?, error = ? WHERE id = ?')
            ->execute([$status, $this->found, $this->imported, $this->skipped, $this->failed, implode("\n", $this->log), $error, $this->runId]);
    }

    private function logLine(string $msg): void {
        $this->log[] = '[' . date('H:i:s') . '] ' . $msg;
    }

    private function result(): array {
        return [
            'run_id' => $this->runId,
            'found' => $this->found,
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'log' => $this->log,
        ];
    }
}

function runAutoImport(?int $maxPosts = null): array {
    $lockFile = sys_get_temp_dir() . '/daily-signal-auto-' . md5(__DIR__) . '.lock';
    $fh = fopen($lockFile, 'c+');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
        return ['error' => 'Inny run jest już aktywny.'];
    }
    try {
        return (new AutoImporter())->run($maxPosts);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
