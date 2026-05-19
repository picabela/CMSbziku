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
            seedData($pdo);
        }
    }
    return $pdo;
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
    ");

    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute(['admin_password_hash']);
    if (!$stmt->fetch()) {
        $hash = password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')
            ->execute(['admin_password_hash', $hash]);
    }
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
