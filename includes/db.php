<?php
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $isNew = !file_exists(DB_PATH);
        if ($isNew) {
            @mkdir(dirname(DB_PATH), 0775, true);
        }
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        initSchema($pdo);
        if ($isNew) {
            seedCategories($pdo);
            seedData($pdo);
            seedSources($pdo);
        }
    }
    return $pdo;
}

function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void {
    $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll();
    foreach ($cols as $c) {
        if ($c['name'] === $column) return;
    }
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}

function initSchema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT UNIQUE NOT NULL,
            title TEXT NOT NULL,
            subtitle TEXT,
            excerpt TEXT,
            content TEXT NOT NULL,
            featured_image TEXT,
            featured_image_alt TEXT,
            category TEXT DEFAULT 'Aktualności',
            author TEXT DEFAULT 'Redakcja',
            meta_title TEXT,
            meta_description TEXT,
            meta_keywords TEXT,
            status TEXT DEFAULT 'published',
            published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );

        CREATE INDEX IF NOT EXISTS idx_posts_slug ON posts(slug);
        CREATE INDEX IF NOT EXISTS idx_posts_status ON posts(status);
        CREATE INDEX IF NOT EXISTS idx_posts_published ON posts(published_at);
        CREATE INDEX IF NOT EXISTS idx_posts_category ON posts(category);

        CREATE TABLE IF NOT EXISTS sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            feed_url TEXT NOT NULL,
            site_url TEXT,
            category TEXT,
            language TEXT DEFAULT 'en',
            max_items_per_run INTEGER DEFAULT 2,
            auto_publish INTEGER DEFAULT NULL,
            enabled INTEGER DEFAULT 1,
            last_fetched_at DATETIME,
            last_error TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS auto_imports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_id INTEGER,
            external_url TEXT,
            external_guid TEXT,
            guid_hash TEXT UNIQUE NOT NULL,
            post_id INTEGER,
            imported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE SET NULL,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE SET NULL
        );
        CREATE INDEX IF NOT EXISTS idx_imports_hash ON auto_imports(guid_hash);

        CREATE TABLE IF NOT EXISTS auto_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME,
            status TEXT DEFAULT 'running',
            items_found INTEGER DEFAULT 0,
            items_imported INTEGER DEFAULT 0,
            items_skipped INTEGER DEFAULT 0,
            items_failed INTEGER DEFAULT 0,
            items_enqueued INTEGER DEFAULT 0,
            log TEXT,
            error TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_runs_started ON auto_runs(started_at);

        CREATE TABLE IF NOT EXISTS auto_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_id INTEGER,
            external_url TEXT NOT NULL,
            external_guid TEXT,
            guid_hash TEXT UNIQUE NOT NULL,
            title TEXT,
            description TEXT,
            published_ts INTEGER,
            status TEXT DEFAULT 'pending',
            attempts INTEGER DEFAULT 0,
            max_attempts INTEGER DEFAULT 3,
            next_attempt_at DATETIME,
            error TEXT,
            post_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE SET NULL
        );
        CREATE INDEX IF NOT EXISTS idx_queue_status ON auto_queue(status);
        CREATE INDEX IF NOT EXISTS idx_queue_next ON auto_queue(next_attempt_at);
        CREATE INDEX IF NOT EXISTS idx_queue_hash ON auto_queue(guid_hash);

        CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            slug TEXT UNIQUE NOT NULL,
            description TEXT,
            sort_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            slug TEXT UNIQUE NOT NULL,
            usage_count INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_tags_slug ON tags(slug);

        CREATE TABLE IF NOT EXISTS pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT UNIQUE NOT NULL,
            title TEXT NOT NULL,
            content TEXT NOT NULL DEFAULT '',
            meta_title TEXT,
            meta_description TEXT,
            meta_keywords TEXT,
            status TEXT DEFAULT 'published',
            sort_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_pages_slug ON pages(slug);

        CREATE TABLE IF NOT EXISTS post_tags (
            post_id INTEGER NOT NULL,
            tag_id INTEGER NOT NULL,
            PRIMARY KEY (post_id, tag_id),
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
            FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_post_tags_post ON post_tags(post_id);
        CREATE INDEX IF NOT EXISTS idx_post_tags_tag ON post_tags(tag_id);

        CREATE TABLE IF NOT EXISTS post_ratings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            ip_hash TEXT NOT NULL,
            rating INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(post_id, ip_hash),
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_ratings_post ON post_ratings(post_id);

        CREATE TABLE IF NOT EXISTS post_categories (
            post_id  INTEGER NOT NULL,
            cat_name TEXT    NOT NULL,
            PRIMARY KEY (post_id, cat_name),
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_post_cats_post ON post_categories(post_id);
        CREATE INDEX IF NOT EXISTS idx_post_cats_cat  ON post_categories(cat_name);

        CREATE TABLE IF NOT EXISTS indexing_log (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            url        TEXT    NOT NULL,
            method     TEXT    NOT NULL,
            ok         INTEGER NOT NULL DEFAULT 0,
            response   TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_indexing_log_created ON indexing_log(created_at);
    ");

    // Lekkie migracje dla istniejących instalacji
    addColumnIfMissing($pdo, 'sources', 'source_type', "TEXT DEFAULT 'rss'");
    addColumnIfMissing($pdo, 'sources', 'link_selector', "TEXT");
    addColumnIfMissing($pdo, 'sources', 'max_age_days', "INTEGER");
    addColumnIfMissing($pdo, 'sources', 'date_selector', "TEXT");     // CSS/XPath elementu z datą na stronie artykułu
    addColumnIfMissing($pdo, 'sources', 'content_selector', "TEXT");  // CSS/XPath kontenera treści artykułu
    addColumnIfMissing($pdo, 'auto_runs', 'items_enqueued', "INTEGER DEFAULT 0");
    addColumnIfMissing($pdo, 'posts', 'source_attribution', "TEXT");
    addColumnIfMissing($pdo, 'posts', 'tldr', "TEXT");
    addColumnIfMissing($pdo, 'posts', 'show_toc', "INTEGER");  // NULL = global, 0/1 = override
    addColumnIfMissing($pdo, 'posts', 'nofollow_links', "INTEGER DEFAULT 0");  // 1 = wszystkie linki wych. w artykule nofollow
    addColumnIfMissing($pdo, 'posts', 'faq_json', "TEXT");  // JSON [{q,a}] — sekcja FAQ + FAQPage schema

    // Seed domyślnych kategorii (jeśli pusto)
    if ((int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 0) {
        seedCategories($pdo);
    }

    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute(['admin_password_hash']);
    if (!$stmt->fetch()) {
        $hash = password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')
            ->execute(['admin_password_hash', $hash]);
    }

    $defaults = [
        'auto_enabled' => '0',
        'auto_token' => bin2hex(random_bytes(16)),
        'auto_interval_minutes' => '60',
        'auto_max_posts_per_run' => '3',
        'auto_max_age_days' => '3',
        'auto_posts_per_tick' => '2',
        'auto_discovery_interval_minutes' => '60',
        'auto_publish' => '1',
        'openai_api_key' => '',
        'openai_model' => 'gpt-4o-mini',
        'openai_temperature' => '0.4',
        'auto_target_language' => 'pl',
        'auto_default_category' => 'Aktualności',
        'auto_default_author' => 'Redakcja AI',
        'auto_prompt' => "Jesteś dziennikarzem branżowym piszącym po polsku dla minimalistycznej gazety online o SEO, GEO, reklamie cyfrowej (ADS) i AI.\n\nNa podstawie poniższego artykułu źródłowego napisz oryginalne, ciekawe streszczenie po polsku — w formie samodzielnego newsa redakcyjnego, nie kopiując zdań ze źródła. Tekst powinien:\n- mieć ok. 300–500 słów,\n- być zwięzły, informacyjny i konkretny,\n- używać prostego języka, krótkich akapitów <p>,\n- zawierać 1-2 śródtytuły <h2> oraz listę <ul> jeśli to naturalne,\n- nie zaczynać od „W artykule…\", „Według…\" — pisz wprost,\n- na końcu dodać akapit „Dlaczego to ważne\" w 2-3 zdaniach.\n\nZwróć WYŁĄCZNIE poprawny JSON o strukturze:\n{\n  \"title\": \"chwytliwy tytuł po polsku, max 80 znaków\",\n  \"subtitle\": \"krótki podtytuł po polsku, max 140 znaków\",\n  \"excerpt\": \"zajawka 1-2 zdania po polsku, max 220 znaków\",\n  \"content\": \"treść w prostym HTML (<p>, <h2>, <ul>, <li>, <strong>, <em>, <blockquote>)\",\n  \"category\": \"nazwa kategorii GŁÓWNEJ z podanej listy\",\n  \"extra_categories\": [\"opcjonalnie dodatkowe pasujące kategorie z listy — patrz instrukcja w wiadomości użytkownika\"],\n  \"keywords\": \"5-7 słów kluczowych po polsku, przecinki\",\n  \"image_alt\": \"opis sugerowanego obrazu po polsku, max 120 znaków\",\n  \"tags\": [\"tablica nazw firm/marek/produktów występujących w tekście, max 3\"],\n  \"tldr\": \"2-3 zdania streszczenia (TL;DR) na sam początek — kluczowy fakt + dlaczego ważne, do 280 znaków, idealne do cytowania przez AI\"\n}",
        'auto_last_run' => '',
        // Tożsamość strony — edytowalne z panelu (nadpisują stałe z config.php)
        'site_name' => '',
        'site_tagline' => '',
        'site_logo' => '',
        'site_favicon' => '',
        // Indeksowanie URL (Google Indexing API + IndexNow)
        'indexing_google_enabled'    => '0',
        'indexing_indexnow_enabled'  => '0',
        'indexing_indexnow_key'      => '',
        'indexing_auto_on_publish'   => '0',
        // Sprawdzanie aktualizacji przy okazji crona auto-importu
        'update_check_enabled'        => '1',   // sprawdzaj wersję podczas cronu
        'update_auto_install'         => '0',   // instaluj automatycznie (tylko gdy włączone)
        'update_check_interval_hours' => '6',   // jak często odpytywać GitHuba
        'update_check_last_ts'        => '0',
        'update_check_last_error'     => '',
        'update_available_version'    => '',
        'update_available_notes'      => '',
        'update_auto_last_result'     => '',
        // Top notice (czytelnie wymyślony przekaz)
        'top_notice_enabled' => '1',
        'top_notice_text' => 'Same fakty, bez lania wody. Czytaj wygodnie na ebooku, tablecie lub w przerwie na kawę.',
        // Kontakt
        'contact_enabled' => '1',
        'contact_email' => '',
        'contact_subject_prefix' => '[bziku CMS]',
        // Tagi
        'auto_max_tags' => '3',
        'tag_label' => 'Tagi',
        // Stopka źródła (szablon — zmiany dotyczą tylko nowych publikacji)
        'source_attribution_template' => 'Opracowanie redakcji na podstawie źródła: {url} ({source}).',
        // Data publikacji jak w oryginale
        'auto_keep_original_date' => '0',
        // Limit treści pobieranej do streszczenia (znaki)
        'auto_content_max_chars' => '30000',
        // Przedział dat dla discovery (alternatywa dla max_age_days)
        'auto_date_range_enabled' => '0',
        'auto_date_from' => '',
        'auto_date_to' => '',
        // Kategorie na artykuł
        'max_categories_per_post' => '2',
        // Prompty AI — puste = użyj wbudowanego domyślnego (edytowalne w zakładce Prompty)
        'auto_prompt_category' => '',
        'auto_prompt_tags' => '',
        'theme_ai_prompt' => '',
        // Stopka: ile elementów pokazać
        'footer_tags_count' => '20',
        'footer_categories_count' => '8',
        // Custom code (dla GTM, analityki, weryfikacji itp.)
        'custom_head_code' => '',
        'custom_body_start_code' => '',
        'custom_body_end_code' => '',
        'gtm_id' => '',
        'ga4_id' => '',
        'gsc_verification' => '',
        'bing_verification' => '',
        'facebook_pixel_id' => '',
        // Motyw
        'active_theme' => 'classic',
        // Menu — JSON arrays of {type, target, label}
        'header_menu_items' => '',
        'footer_menu_items' => '',
        // Per-theme color overrides — JSON {slug: {var: value}}
        'theme_color_overrides' => '',
        // GEO/SEO
        'toc_enabled_global' => '1',
        'auto_generate_tldr' => '1',
        'auto_internal_links' => '1',
        'auto_article_links' => '1',     // linkowanie tekstu do innych artykułów (po tagach/kategoriach)
        'auto_article_links_max' => '4', // max linków do artykułów w jednym tekście
        'outbound_nofollow' => '0',      // globalnie: linki wychodzące jako nofollow (domyślnie dofollow)
        'news_sitemap_enabled' => '1',   // Google News Sitemap (/sitemap_news.xml)
        'webp_conversion' => '1',
        'reading_progress_bar' => '1',
        'critical_css_inline' => '1',
        // Ratings
        'ratings_enabled' => '1',
        // Cache busting
        'cache_version' => '1',
        // Masthead — edycja "Wydanie cyfrowe"
        'masthead_edition_enabled' => '1',
        'masthead_edition_text' => 'Wydanie cyfrowe',
        // RODO / Cookie consent
        'rodo_enabled' => '0',
        'rodo_consent_mode_v2' => '1',
        'rodo_banner_position' => 'bottom',
        'rodo_banner_style' => 'modal',
        'rodo_banner_title' => 'Szanujemy Twoją prywatność',
        'rodo_banner_text' => 'Używamy plików cookie, by strona działała sprawnie i lepiej dopasowywała się do Twoich potrzeb. Część z nich jest niezbędna do działania serwisu, inne pomagają nam ulepszać treści i mierzyć efektywność. Wybierz, na co się zgadzasz — w każdej chwili możesz zmienić zdanie.',
        'rodo_show_logo' => '1',
        'rodo_consent_lifetime_days' => '365',
        'rodo_auto_generate_policy' => '1',
        'rodo_company_form' => 'individual',  // individual | company
        'rodo_company_name' => '',
        'rodo_company_address' => '',
        'rodo_company_email' => '',
        'rodo_company_nip' => '',
        'rodo_dpo_contact' => '',
        'rodo_show_company_data' => '0',  // czy w polityce ujawniać dane firmy
        'rodo_categories' => '',  // JSON, fallback do domyślnych jeśli puste
        'rodo_color_primary' => '#2540b8',
        'rodo_accept_all_text' => 'Akceptuję wszystkie',
        'rodo_accept_selected_text' => 'Zapisz mój wybór',
        'rodo_reject_text' => 'Tylko niezbędne',
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
    foreach ($defaults as $k => $v) $stmt->execute([$k, $v]);

    // Jednorazowa migracja: jeśli teksty RODO są równe starym cookiebot-like defaultom,
    // podmień na nowe (przyjazne) wartości. Nie ruszamy jeśli user już zmienił.
    $rodoMigrations = [
        'rodo_banner_title' => [
            'old' => 'Niniejsza strona korzysta z plików cookie',
            'new' => 'Szanujemy Twoją prywatność',
        ],
        'rodo_banner_text' => [
            'old' => 'Wykorzystujemy pliki cookie do spersonalizowania treści i reklam, aby oferować funkcje społecznościowe i analizować ruch w naszej witrynie. Informacje o tym, jak korzystasz z naszej witryny, udostępniamy partnerom społecznościowym, reklamowym i analitycznym. Partnerzy mogą połączyć te informacje z innymi danymi otrzymanymi od Ciebie lub uzyskanymi podczas korzystania z ich usług.',
            'new' => 'Używamy plików cookie, by strona działała sprawnie i lepiej dopasowywała się do Twoich potrzeb. Część z nich jest niezbędna do działania serwisu, inne pomagają nam ulepszać treści i mierzyć efektywność. Wybierz, na co się zgadzasz — w każdej chwili możesz zmienić zdanie.',
        ],
        'rodo_accept_all_text' => ['old' => 'Zezwól na wszystkie', 'new' => 'Akceptuję wszystkie'],
        'rodo_accept_selected_text' => ['old' => 'Zezwól na wybór', 'new' => 'Zapisz mój wybór'],
        'rodo_reject_text' => ['old' => 'Odmowa', 'new' => 'Tylko niezbędne'],
    ];
    $sel = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $upd = $pdo->prepare('UPDATE settings SET value = ? WHERE key = ?');
    foreach ($rodoMigrations as $k => $m) {
        $sel->execute([$k]);
        $cur = $sel->fetchColumn();
        if ($cur === $m['old']) $upd->execute([$m['new'], $k]);
    }
}

function seedCategories(PDO $pdo): void {
    $defaults = [
        ['SEO',          'seo',          'Klasyczne pozycjonowanie i optymalizacja pod wyszukiwarki.'],
        ['GEO',          'geo',          'Generative Engine Optimization — widoczność w AI: ChatGPT, Perplexity, AI Overviews.'],
        ['ADS',          'ads',          'Reklamy płatne: Google Ads, Meta Ads, programmatic, retargeting.'],
        ['AI',           'ai',           'Sztuczna inteligencja w marketingu i nowe modele językowe.'],
        ['Technical SEO','technical-seo','Performance, Core Web Vitals, dane strukturalne, indeksacja.'],
        ['Aktualności',  'aktualnosci',  'Wszystko, co nie pasuje do wyspecjalizowanych kategorii.'],
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO categories (name, slug, description, sort_order) VALUES (?, ?, ?, ?)');
    foreach ($defaults as $i => $c) $stmt->execute([$c[0], $c[1], $c[2], $i * 10]);
}

function seedSources(PDO $pdo): void {
    // [name, feed_url, site_url, category, source_type, link_selector]
    $defaults = [
        ['Search Engine Journal', 'https://www.searchenginejournal.com/feed/', 'https://www.searchenginejournal.com/', 'SEO', 'rss', null],
        ['Moz Blog', 'https://moz.com/blog/feed', 'https://moz.com/blog', 'SEO', 'rss', null],
        ['Ahrefs Blog', 'https://ahrefs.com/blog/feed/', 'https://ahrefs.com/blog/', 'SEO', 'rss', null],
        ['OpenAI News', 'https://openai.com/news/rss.xml', 'https://openai.com/news/', 'AI', 'rss', null],
        // Bez RSS — listing HTML:
        ['Google Search Central', 'https://developers.google.com/search/blog', 'https://developers.google.com/search/blog', 'SEO', 'html', null],
        ['Search Engine Land', 'https://searchengineland.com/library/seo', 'https://searchengineland.com/', 'SEO', 'html', null],
        ['Search Engine Roundtable', 'https://www.seroundtable.com/', 'https://www.seroundtable.com/', 'SEO', 'html', null],
        ['WordStream Blog', 'https://www.wordstream.com/blog', 'https://www.wordstream.com/blog', 'ADS', 'html', null],
        ['Anthropic News', 'https://www.anthropic.com/news', 'https://www.anthropic.com/', 'AI', 'html', null],
    ];
    $stmt = $pdo->prepare('INSERT INTO sources (name, feed_url, site_url, category, source_type, link_selector, max_items_per_run, enabled) VALUES (?, ?, ?, ?, ?, ?, 2, 1)');
    foreach ($defaults as $s) $stmt->execute($s);
}

function seedData(PDO $pdo): void {
    $posts = [
        [
            'slug' => 'czym-jest-geo-generative-engine-optimization',
            'title' => 'Czym jest GEO? Optymalizacja pod silniki generatywne wkracza do mainstreamu',
            'subtitle' => 'Po SEO przyszedł czas na GEO — nową dyscyplinę widoczności w erze AI.',
            'excerpt' => 'GEO, czyli Generative Engine Optimization, to nowa dyscyplina marketingu, która odpowiada na rosnące znaczenie wyszukiwarek opartych o sztuczną inteligencję, takich jak ChatGPT, Perplexity czy Google AI Overviews.',
            'category' => 'GEO',
            'featured_image_alt' => 'Czarno-biała grafika przedstawiająca sieć neuronową symbolizującą GEO',
            'content' => "<p><strong>GEO (Generative Engine Optimization)</strong> to zbiór praktyk, które pozwalają stronom internetowym pojawiać się jako źródła w odpowiedziach generowanych przez modele językowe. W przeciwieństwie do klasycznego SEO, którego celem jest pozycja w wynikach wyszukiwania, GEO koncentruje się na tym, by treść była cytowana w odpowiedziach AI.</p><h2>Dlaczego GEO jest ważne?</h2><p>Według najnowszych raportów Gartnera, do końca 2026 roku ponad 25% ruchu z wyszukiwarek tradycyjnych przeniesie się do interfejsów konwersacyjnych. Marki, które nie zaczną optymalizować pod kątem AI, mogą stracić znaczącą część widoczności.</p><h2>Kluczowe elementy GEO</h2><ul><li>Cytowalne fragmenty — krótkie, faktograficzne stwierdzenia</li><li>Dane strukturalne (Schema.org)</li><li>Autorytet i E-E-A-T</li><li>Świeżość treści i regularne aktualizacje</li><li>Klarowna struktura nagłówków</li></ul><blockquote>GEO to nie zastępstwo SEO, ale jego naturalna ewolucja w świecie generatywnej AI.</blockquote>",
        ],
        [
            'slug' => 'google-ai-overviews-zmiany-2026',
            'title' => 'Google AI Overviews w 2026 — co się zmieniło i jak na tym zarabiać',
            'subtitle' => 'Nowe formaty odpowiedzi AI wymagają nowych strategii contentowych.',
            'excerpt' => 'Po dwóch latach od premiery, Google AI Overviews stały się dominującym formatem w wynikach wyszukiwania na rynkach anglojęzycznych. Sprawdzamy, jak adaptować strategię contentową.',
            'category' => 'SEO',
            'featured_image_alt' => 'Zrzut ekranu wyników wyszukiwania Google z odpowiedzią AI',
            'content' => "<p>Google AI Overviews to format, który <strong>zmienił zasady gry</strong> w wyszukiwarce. W 2026 roku pojawiają się one już dla ponad 60% zapytań informacyjnych.</p><h2>Jakie zmiany zaszły w 2026 roku?</h2><ol><li>AI Overviews pojawiają się także w wynikach mobilnych w pełnej formie</li><li>Cytowania źródeł są bardziej widoczne i klikalne</li><li>Wprowadzono nowy format „Follow-up questions”</li><li>Wyniki są personalizowane na podstawie historii wyszukiwań</li></ol><h2>Jak optymalizować pod AI Overviews?</h2><p>Kluczem jest tworzenie treści, które bezpośrednio odpowiadają na pytania użytkowników. Twórz krótkie, klarowne akapity, używaj list i tabel, i pamiętaj o danych strukturalnych typu <code>FAQPage</code> oraz <code>HowTo</code>.</p>",
        ],
        [
            'slug' => 'eeat-w-erze-ai-jak-budowac-autorytet',
            'title' => 'E-E-A-T w erze AI — jak budować autorytet, gdy wszyscy publikują treści generatywne',
            'subtitle' => 'Doświadczenie i ekspertyza stają się ważniejsze niż kiedykolwiek.',
            'excerpt' => 'Gdy każdy może wygenerować artykuł w 30 sekund, prawdziwe doświadczenie i ekspertyza stają się najcenniejszą walutą w SEO. Oto jak je pokazać.',
            'category' => 'SEO',
            'featured_image_alt' => 'Stara maszyna do pisania z arkuszem papieru — symbol autorytetu',
            'content' => "<p><strong>E-E-A-T</strong> (Experience, Expertise, Authoritativeness, Trustworthiness) to framework Google, który zyskał ogromne znaczenie wraz z eksplozją treści generowanych przez AI.</p><h2>Cztery filary E-E-A-T</h2><h3>1. Doświadczenie (Experience)</h3><p>Pokaż, że masz osobiste doświadczenie z tematem. Zdjęcia własnych testów, screeny z narzędzi, case studies.</p><h3>2. Ekspertyza (Expertise)</h3><p>Biogramy autorów, linki do publikacji, certyfikaty.</p><h3>3. Autorytatywność (Authoritativeness)</h3><p>Wzmianki w branżowych mediach, backlinks z autorytatywnych domen.</p><h3>4. Wiarygodność (Trustworthiness)</h3><p>Transparentne dane kontaktowe, polityka prywatności, recenzje użytkowników.</p>",
        ],
        [
            'slug' => 'perplexity-vs-google-walka-o-przyszlosc-wyszukiwania',
            'title' => 'Perplexity vs Google — walka o przyszłość wyszukiwania nabiera tempa',
            'subtitle' => 'Konwersacyjne wyszukiwarki zaczynają realnie zagrażać dominacji Google.',
            'excerpt' => 'Perplexity AI przekroczyło 100 milionów aktywnych użytkowników miesięcznie. Czy to początek końca dominacji Google?',
            'category' => 'GEO',
            'featured_image_alt' => 'Szachownica z dwoma figurami króla symbolizująca rywalizację',
            'content' => "<p>W maju 2026 roku <strong>Perplexity AI</strong> ogłosiło przekroczenie 100 milionów MAU. Choć to wciąż ułamek użytkowników Google, tempo wzrostu jest bezprecedensowe.</p><h2>Co odróżnia Perplexity?</h2><ul><li>Każda odpowiedź zawiera klikalne źródła</li><li>Brak reklam (na razie)</li><li>Tryb akademicki i finansowy</li><li>API dla deweloperów</li></ul><h2>Jak optymalizować pod Perplexity?</h2><p>Perplexity preferuje świeże, faktograficzne treści z wyraźnie zaznaczonymi datami i źródłami. Strony z aktualnymi statystykami i danymi badawczymi pojawiają się jako źródła znacznie częściej.</p>",
        ],
        [
            'slug' => 'core-web-vitals-2026-inp-zastapil-fid',
            'title' => 'Core Web Vitals w 2026 — INP definitywnie zastąpił FID',
            'subtitle' => 'Interaction to Next Paint to nowy król wskaźników UX.',
            'excerpt' => 'Po pełnej migracji w marcu 2024 roku, INP stał się standardowym wskaźnikiem responsywności. Sprawdzamy, jak go optymalizować w 2026 roku.',
            'category' => 'Technical SEO',
            'featured_image_alt' => 'Wskaźnik prędkości na desce rozdzielczej',
            'content' => "<p><strong>INP (Interaction to Next Paint)</strong> mierzy czas reakcji strony na interakcje użytkownika. Dobry wynik to mniej niż 200 ms.</p><h2>Najczęstsze przyczyny słabego INP</h2><ol><li>Ciężkie skrypty JavaScript</li><li>Synchroniczne wywołania API</li><li>Brak debouncingu w eventach</li><li>Renderowanie blokujące główny wątek</li></ol><h2>Jak to naprawić?</h2><p>Wykorzystaj <code>requestIdleCallback</code>, dziel długie zadania na mniejsze (<code>scheduler.yield()</code>), używaj Web Workers dla ciężkich obliczeń.</p>",
        ],
        [
            'slug' => 'schema-org-2026-nowe-typy-dla-ai',
            'title' => 'Schema.org w 2026 — nowe typy danych strukturalnych zoptymalizowane pod AI',
            'subtitle' => 'Nowe schematy ułatwiają silnikom generatywnym cytowanie Twojej strony.',
            'excerpt' => 'Schema.org wprowadziło w 2026 roku zestaw nowych typów danych zaprojektowanych specjalnie dla wyszukiwarek opartych na AI. Oto przegląd najważniejszych.',
            'category' => 'Technical SEO',
            'featured_image_alt' => 'Diagram struktury danych z połączonymi węzłami',
            'content' => "<p>Schema.org pozostaje fundamentem zarówno SEO, jak i GEO. W 2026 roku dodano kilka nowych typów istotnych dla widoczności w AI.</p><h2>Nowe typy schematów</h2><ul><li><code>FactCheck</code> — weryfikacja faktów</li><li><code>ExpertAnswer</code> — odpowiedź eksperta</li><li><code>DataCitation</code> — cytowanie źródeł danych</li><li><code>AIContentDisclosure</code> — informacja o użyciu AI</li></ul><h2>Praktyczne wdrożenie</h2><p>Implementuj JSON-LD w sekcji <code>&lt;head&gt;</code>. Testuj walidatorem Google Rich Results Test i Schema Markup Validator.</p>",
        ],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO posts (slug, title, subtitle, excerpt, content, category, featured_image_alt, meta_title, meta_description, meta_keywords, published_at)
        VALUES (:slug, :title, :subtitle, :excerpt, :content, :category, :alt, :meta_title, :meta_description, :meta_keywords, :published_at)
    ");

    $daysAgo = 0;
    foreach ($posts as $post) {
        $published = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
        $stmt->execute([
            ':slug' => $post['slug'],
            ':title' => $post['title'],
            ':subtitle' => $post['subtitle'],
            ':excerpt' => $post['excerpt'],
            ':content' => $post['content'],
            ':category' => $post['category'],
            ':alt' => $post['featured_image_alt'],
            ':meta_title' => $post['title'],
            ':meta_description' => $post['excerpt'],
            ':meta_keywords' => strtolower($post['category']) . ', seo, geo, ai, marketing',
            ':published_at' => $published,
        ]);
        $daysAgo += 2;
    }
}
