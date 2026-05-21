<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$base = BASE_URL;
?>
User-agent: *
Allow: /
Disallow: /admin/
Disallow: /api/
Disallow: /cron/
Disallow: /includes/
Disallow: /data/
Disallow: /bin/
Disallow: /themes/*.php
Disallow: /*?*

# AI crawlers — explicitly allowed for GEO (Generative Engine Optimization)
User-agent: GPTBot
Allow: /

User-agent: ChatGPT-User
Allow: /

User-agent: OAI-SearchBot
Allow: /

User-agent: ClaudeBot
Allow: /

User-agent: Claude-Web
Allow: /

User-agent: anthropic-ai
Allow: /

User-agent: PerplexityBot
Allow: /

User-agent: Perplexity-User
Allow: /

User-agent: Google-Extended
Allow: /

User-agent: GoogleOther
Allow: /

User-agent: Applebot-Extended
Allow: /

User-agent: Bytespider
Allow: /

User-agent: CCBot
Allow: /

User-agent: cohere-ai
Allow: /

# Sitemaps i metadane
Sitemap: <?= $base ?>/sitemap.xml

# Wskazania dla LLM
# Pełny llms.txt: <?= $base ?>/llms.txt
# RSS:           <?= $base ?>/feed.php
