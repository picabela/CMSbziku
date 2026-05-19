# The Daily Signal — minimalistyczny CMS gazetowy

Lekka, czarno-biała gazeta online z wbudowanym CMS-em, zaprojektowana z myślą o
komfortowym czytaniu na **czytnikach ebook** (Kindle, Kobo, PocketBook), tabletach
i przeglądarkach desktopowych. Estetyka inspirowana klasyczną prasą drukowaną
spotyka się tu z nowoczesnymi, prostokątnymi kafelkami i pełną responsywnością.

> Demo treści: kilka aktualnych newsów ze świata **GEO / SEO** (Generative Engine
> Optimization, Google AI Overviews, E-E-A-T, Core Web Vitals, Schema.org 2026
> i in.) zasilanych z lokalnej bazy SQLite.

## Spis treści

1. [Funkcje](#funkcje)
2. [Stack technologiczny](#stack-technologiczny)
3. [Instalacja](#instalacja)
4. [Pierwsze logowanie](#pierwsze-logowanie)
5. [Struktura projektu](#struktura-projektu)
6. [Model bazy danych](#model-bazy-danych)
7. [SEO / GEO — co jest wdrożone](#seo--geo--co-jest-wdrożone)
8. [Dostępność i czytniki ebook](#dostępność-i-czytniki-ebook)
9. [Bezpieczeństwo](#bezpieczeństwo)
10. [Rozwój i rozbudowa w przyszłości](#rozwój-i-rozbudowa-w-przyszłości)
11. [Licencja](#licencja)

---

## Funkcje

### Frontend (czytnik)
- Czarno-biała, gazetowa typografia (Playfair Display + Source Serif 4 + Inter).
- Responsywna siatka prostokątnych kafelków (`auto-fill, minmax(280px, 1fr)`).
- Lead article z dużym tytułem i pełnym podtytułem.
- Dropcap, justowanie i hyphenation w artykułach — czyta się jak gazetę.
- Tryb ciemny via `prefers-color-scheme: dark` (czytniki nocą).
- Strony kategorii z paginacją.
- Powiązane artykuły, breadcrumbs, RSS, sitemap.
- Optymalizacje typograficzne dla ekranów e-ink (większa interlinia).
- Wsparcie `prefers-reduced-motion` i `print`.

### CMS (panel redakcji)
- Logowanie hasłem dostępu (zmienialne z panelu).
- Lista artykułów z akcjami: edycja / podgląd / usunięcie.
- Nowoczesny edytor WYSIWYG (**Quill 1.3** — nagłówki, listy, cytaty, kod, linki,
  obrazy, wyrównanie).
- Upload **zdjęć wyróżniających** (JPG / PNG / WebP / SVG / GIF, do 8 MB) wraz
  z osobnym polem `alt` (SEO + dostępność).
- Pola SEO per artykuł: meta title, meta description, keywords, własny slug.
- Status `published / draft` + niestandardowa data publikacji.
- Autouzupełnianie kategorii (datalist).
- Tokeny CSRF dla wszystkich formularzy.
- Hasła hashowane przez `password_hash()` (bcrypt).

### Auto-import AI 🤖
- **Samouzupełniające się artykuły** — aplikacja sama szuka świeżych wiadomości
  ze świata SEO / GEO / ADS / AI, streszcza je przez LLM i publikuje.
- **Dwa typy źródeł** (`/admin/sources.php`):
  - **RSS / Atom** — klasyczne feedy.
  - **HTML listing** — strony bez RSS (np. Google Search Central, Anthropic
    News, Search Engine Land). Importer wchodzi na stronę z nagłówkami,
    wyciąga linki do artykułów, a potem dla każdego pobiera pełną treść.
- **Selektor linków per źródło** — CSS (`article h2 a`) lub XPath
  (`//article//h2/a`). Pusty → heurystyka (`<article>`, `<h2>`, `<h3>`).
- **Filtr wieku artykułu** — globalny i opcjonalnie per źródło. Domyślnie
  „od 3 dni do dzisiaj". Artykuły **bez wykrytej daty publikacji są zawsze
  pomijane** — zero starych newsów na stronie.
- Detekcja daty z: `<meta property="article:published_time">`,
  `<meta itemprop="datePublished">`, JSON-LD (`NewsArticle`/`Article`/
  `BlogPosting`, także w `@graph`), `<time datetime>`.
- **Deduplikacja** po `sha256(GUID|URL)` — ten sam news nigdy się
  nie powtórzy, nawet w innym źródle.
- 9 pre-seedowanych źródeł (Search Engine Journal, Moz, Ahrefs, OpenAI News,
  Google Search Central [HTML], Search Engine Land [HTML], Search Engine
  Roundtable [HTML], WordStream [HTML], Anthropic News [HTML]).
- **Streszczanie przez OpenAI** (`/admin/auto.php`) — model, temperatura, prompt
  redakcyjny i wszystkie parametry konfigurowalne z UI. Domyślny model:
  `gpt-4o-mini` (tani i szybki).
- **Prompt redakcyjny** zmusza model do zwrócenia JSON-a z polami
  `title / subtitle / excerpt / content (HTML) / category / keywords / image_alt`
  — gotowe do wrzutki w bazę.
- **Sanityzacja HTML** wygenerowanego — `strip_tags` na białej liście,
  `rel="nofollow noopener"` na linkach, automatyczne usuwanie `on*` handlerów.
- **Atrybucja źródła** doklejana na końcu artykułu (link `nofollow noopener`).
- **Deduplikacja** po `sha256(GUID|URL)` w tabeli `auto_imports` — ten sam
  news nie zostanie zaimportowany dwa razy.
- **Cron-friendly endpoint** `/cron/run.php?token=...` — token w UI, można
  rotować. Lock plikowy zapobiega nakładającym się runom.
- **Alternatywnie CLI**: `php bin/auto.php [--max=3]`.
- **Pełny log** każdego uruchomienia (`/admin/runs.php`) — co znaleziono,
  zaimportowano, pominięto, gdzie wyleciał błąd.
- **Tryb auto-publish** globalny + override per źródło (publikuj/draft).

### SEO / GEO
- **Przyjazne URL-e**: `/slug-artykulu`, `/kategoria/seo`.
- Kanoniczny URL, `<title>`, meta description per strona.
- Open Graph + Twitter Card.
- **JSON-LD** dla `WebSite` (z `SearchAction`), `NewsArticle`, `BreadcrumbList`,
  `CollectionPage`, `Organization`.
- `sitemap.xml` (dynamiczny), `robots.txt`, RSS 2.0.
- Robots: jawne `Allow` dla `GPTBot`, `ClaudeBot`, `PerplexityBot`,
  `Google-Extended` — gotowe pod **GEO**.
- `alt` na każdym obrazie, semantyczne `<article>`, `<time datetime>`,
  `<nav aria-label>`, `<figure>/<figcaption>`.
- Microdata `itemscope`/`itemprop` na artykule.
- Hreflang/locale (`pl`, `pl_PL`).
- Headery: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`.

---

## Stack technologiczny

| Warstwa | Technologia |
| --- | --- |
| Backend | PHP ≥ 7.4 (działa też na PHP 8.x) |
| Baza | SQLite via PDO (zero konfiguracji) |
| Frontend | HTML5 + CSS3 (grid, custom properties) |
| Edytor | Quill 1.3 (CDN) |
| Serwer | Apache z `mod_rewrite` (lub Nginx + odpowiednia konfiguracja) |

Brak zewnętrznych zależności PHP — nie potrzeba Composera ani Node.js.

---

## Instalacja

### Wymagania
- PHP 7.4+ z rozszerzeniami `pdo_sqlite` i `gd` (opcjonalne, dla przyszłych
  transformacji obrazów).
- Apache z `mod_rewrite` **lub** PHP wbudowany serwer (dev).
- Możliwość zapisu do katalogów `data/` i `uploads/`.

### Krok po kroku
```bash
git clone <repo> daily-signal
cd daily-signal

# Uprawnienia (Linux/macOS)
chmod -R 775 data uploads

# Lokalny serwer deweloperski
php -S localhost:8000

# Lub przez Apache: skopiuj do DocumentRoot
```

Otwórz `http://localhost:8000/` — baza zostanie utworzona automatycznie,
a 6 przykładowych artykułów GEO/SEO zostanie wstrzykniętych.

### Lokalny serwer wbudowany PHP a friendly URLs

Wbudowany serwer (`php -S`) nie obsługuje `.htaccess`. Dla rozwoju lokalnego
użyj routera:

```bash
php -S localhost:8000 router.php
```

Wystarczy stworzyć plik `router.php` (opcjonalny, do dev):
```php
<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri !== '/' && file_exists(__DIR__ . $uri)) return false;
if (preg_match('#^/kategoria/([a-z0-9-]+)/?$#', $uri, $m)) { $_GET['kategoria']=$m[1]; require 'index.php'; return; }
if (preg_match('#^/([a-z0-9-]+)/?$#', $uri, $m)) { $_GET['slug']=$m[1]; require 'article.php'; return; }
require 'index.php';
```

Na produkcji wystarczy Apache + dołączony `.htaccess`.

---

## Pierwsze logowanie

1. Wejdź na `/admin/login.php`.
2. Domyślne hasło: **`admin123`**.
3. **Natychmiast** zmień je w `Ustawienia → Zmiana hasła dostępu`.

---

## Struktura projektu

```
.
├── index.php              # Strona główna (lista + paginacja, kategorie)
├── article.php            # Pojedynczy artykuł
├── sitemap.php            # Dynamiczny sitemap.xml
├── feed.php               # RSS 2.0
├── robots.txt             # Reguły dla crawlerów (w tym AI bots)
├── .htaccess              # Friendly URLs + bezpieczeństwo + cache
├── README.md
│
├── includes/              # Logika serwerowa (zablokowana z zewnątrz)
│   ├── config.php
│   ├── db.php             # Schemat + seed
│   ├── functions.php
│   ├── auth.php
│   ├── header.php
│   └── footer.php
│
├── admin/                 # CMS
│   ├── login.php
│   ├── logout.php
│   ├── index.php          # Lista artykułów
│   ├── edit.php           # Edytor (Quill)
│   ├── delete.php
│   ├── sources.php        # CRUD źródeł RSS
│   ├── source-edit.php
│   ├── auto.php           # Ustawienia auto-importu (klucz OpenAI, prompt…)
│   ├── runs.php           # Log uruchomień
│   ├── settings.php       # Zmiana hasła
│   ├── _layout.php
│   └── _footer.php
│
├── cron/
│   └── run.php            # Token-protected endpoint dla zewnętrznego crona
├── bin/
│   └── auto.php           # CLI runner (cron/systemd)
│
├── assets/
│   ├── css/
│   │   ├── style.css      # Newspaper style
│   │   └── admin.css
│   └── images/            # Logo, favicon, OG default
│
├── uploads/               # Zdjęcia wyróżniające
└── data/
    └── database.sqlite    # Tworzona przy pierwszym uruchomieniu
```

---

## Model bazy danych

Schemat zdefiniowany w `includes/db.php`. Tworzony przy pierwszym żądaniu.

### Tabela `posts`

| Kolumna | Typ | Opis |
| --- | --- | --- |
| `id` | INTEGER PK AI | identyfikator |
| `slug` | TEXT UNIQUE | przyjazny URL |
| `title` | TEXT | tytuł |
| `subtitle` | TEXT | podtytuł (lead) |
| `excerpt` | TEXT | zajawka na listach |
| `content` | TEXT | HTML z edytora |
| `featured_image` | TEXT | nazwa pliku w `uploads/` |
| `featured_image_alt` | TEXT | tekst alternatywny |
| `category` | TEXT | kategoria (np. GEO, SEO) |
| `author` | TEXT | autor |
| `meta_title` | TEXT | SEO title |
| `meta_description` | TEXT | SEO description |
| `meta_keywords` | TEXT | słowa kluczowe |
| `status` | TEXT | `published` / `draft` |
| `published_at` | DATETIME | data publikacji |
| `updated_at` | DATETIME | data ostatniej zmiany |
| `created_at` | DATETIME | data utworzenia |

Indeksy: `slug`, `status`, `published_at`, `category`.

### Tabela `sources`
Źródła zasilające auto-import:
`id`, `name`, `feed_url`, `site_url`, `category`, `language`,
`source_type` (`rss` / `html`), `link_selector` (CSS lub XPath, dla html),
`max_items_per_run`, `max_age_days` (NULL=globalny),
`auto_publish` (NULL=dziedzicz globalny), `enabled`,
`last_fetched_at`, `last_error`, `created_at`.

### Tabela `auto_imports`
Deduplikacja: `guid_hash` (UNIQUE, sha256 z GUID albo URL), `source_id`,
`external_url`, `external_guid`, `post_id`, `imported_at`.

### Tabela `auto_runs`
Historia uruchomień scheduler-a: `started_at`, `finished_at`, `status`
(`running / success / error / disabled / idle`), `items_found`,
`items_imported`, `items_skipped`, `items_failed`, `log`, `error`.

### Tabela `settings`
Klucz-wartość, przechowuje m.in. hash hasła administratora
(`admin_password_hash`). Łatwo rozszerzalna — np. o `site_name`, `tagline`,
`analytics_id`, `mail_from`.

---

## SEO / GEO — co jest wdrożone

| Element | Gdzie |
| --- | --- |
| Tytuły, opisy, kanoniczne URL | `includes/header.php` |
| Open Graph + Twitter | `includes/header.php` |
| JSON-LD `WebSite` + `SearchAction` | `includes/header.php` |
| JSON-LD `NewsArticle` + `BreadcrumbList` | `article.php` |
| JSON-LD `CollectionPage` | `index.php` |
| Microdata na artykule | `article.php` |
| Sitemap XML | `sitemap.php` (rewrite na `/sitemap.xml`) |
| RSS 2.0 | `feed.php` |
| Robots + AI bot whitelist | `robots.txt` |
| Przyjazne URL | `.htaccess` |
| `<img alt>`, `<figcaption>`, `loading="lazy"`, `width/height` | wszędzie |
| Headery bezpieczeństwa + cache | `.htaccess` |
| `hreflang`, `lang`, `locale` | `header.php` |

---

## Dostępność i czytniki ebook

- Semantyczne HTML5 (`<header>`, `<nav>`, `<main>`, `<article>`, `<aside>`,
  `<footer>`).
- Skip link, `aria-label` na nawigacji, `aria-current` na paginacji.
- Kontrast czarnego druku na lekko kremowym tle (`#f7f5f0`) — komfort
  porównywalny z papierem.
- Wszystkie obrazy mają `alt` (puste, jeśli dekoracyjne).
- Większa interlinia (`1.75`) na urządzeniach bez hovera (typowe dla czytników).
- Wsparcie `prefers-color-scheme: dark` — wieczorne czytanie.
- Wszystkie style działają z wyłączonym JS (Quill jest tylko w panelu).

---

## Auto-import AI — workflow

```
┌──────────────┐    ┌──────────────┐    ┌──────────────┐    ┌──────────────┐
│  Cron HTTP   │───▶│ Fetch RSS    │───▶│ Deduplicate  │───▶│ Fetch HTML   │
│  /cron/run   │    │ (każde       │    │ (sha256 GUID)│    │ artykułu     │
└──────────────┘    │  źródło)     │    └──────────────┘    └──────────────┘
                    └──────────────┘                                │
                                                                    ▼
┌──────────────┐    ┌──────────────┐    ┌──────────────┐    ┌──────────────┐
│ Sanityzacja  │◀───│ JSON →       │◀───│  OpenAI Chat │◀───│ Wycięcie     │
│ HTML + atrybu│    │ post fields  │    │ Completions  │    │ tekstu       │
│ -cja źródła  │    │              │    │ (json mode)  │    │ głównego     │
└──────────────┘    └──────────────┘    └──────────────┘    └──────────────┘
        │
        ▼
┌──────────────┐    ┌──────────────┐
│ Zapis do     │───▶│ Zapis        │
│ posts        │    │ auto_imports │
│ (published   │    │ (dedup row)  │
│  lub draft)  │    └──────────────┘
└──────────────┘
```

### Pierwsze uruchomienie

1. Wejdź w **Auto-import → ustawienia**.
2. Wklej klucz OpenAI (`sk-…`). Domyślny model `gpt-4o-mini`.
3. Zaznacz **Auto-import włączony**, ewentualnie odznacz **Publikuj od razu**.
4. Zweryfikuj listę źródeł (`/admin/sources.php`) — wyłącz te, których nie chcesz.
5. Kliknij **Uruchom teraz** (test z `max=1`) — w 30-90 s powinien pojawić się
   pierwszy auto-artykuł.
6. Skopiuj URL crona z UI i wklej do crontab hostingu:

   ```cron
   0 * * * * curl -s "https://twoja-domena.pl/cron/run.php?token=XXX" > /dev/null
   ```

   …albo bez HTTP, prosto z CLI:

   ```cron
   */60 * * * * /usr/bin/php /var/www/daily-signal/bin/auto.php >> /var/log/daily-signal.log 2>&1
   ```

7. To wszystko. Strona uzupełnia się sama.

### Bezpieczeństwo automatu

- Endpoint cron-a wymaga tokenu (`hash_equals`), rotowalnego z UI.
- Token i klucz OpenAI nigdy nie pojawiają się w logach.
- Lock plikowy zapobiega nakładającym się uruchomieniom.
- Każdy run jest atomowo logowany, błędy nie zatrzymują całego procesu —
  jedno padnięte źródło nie blokuje pozostałych.
- HTML wygenerowany przez LLM przechodzi przez `strip_tags` z białą listą.
- Linki dostają `rel="nofollow noopener"` (chroni domenę przed leaking PageRank
  do wątpliwych źródeł).
- Domyślny user-agent `TheDailySignalBot/1.0` + BASE_URL — etyczny crawling.

### Pomysły na dalszy rozwój auto-importu

- **Generowanie obrazu wyróżniającego** przez DALL·E / `gpt-image-1`.
- **Tłumaczenie wieloma językami** — feed angielski + warianty `pl/de/es`.
- **Klasyfikacja zaawansowana** — drugi pass na model przypisujący tagi.
- **Plagiat-check** — embedding similarity vs poprzednie posty (anty-duplikat).
- **Slack/email digest** po każdym runie.
- **Per-source prompt override** — inne źródła wymagają innego tonu.
- **Web scraping (nie tylko RSS)** — np. Reddit r/SEO, Hacker News.

## Bezpieczeństwo

- Hasła hashowane (`password_hash()` / `password_verify()`).
- CSRF tokens na wszystkich formularzach.
- `session_regenerate_id` po logowaniu.
- Walidacja typów plików i rozmiarów uploadów.
- Escape HTML przez funkcję `e()`.
- Prepared statements (PDO) — odporność na SQL injection.
- `.htaccess` blokuje `data/`, `includes/`, `*.sqlite`, `*.env`.
- Security headers (XCTO, XFO, Referrer-Policy, Permissions-Policy).

> ⚠️ Edytor Quill zapisuje surowy HTML. To celowy kompromis dla użyteczności
> w środowisku redakcyjnym z zaufanymi autorami. W środowisku z wieloma
> redaktorami warto dodać sanitizer (np. HTMLPurifier).

---

## Rozwój i rozbudowa w przyszłości

Architektura została pomyślana jako **fundament**. Naturalne kierunki rozwoju:

### Łatwe (1–2h)
- Wyszukiwarka (`/szukaj?q=…`) — `LIKE` po `title`, `content`, `excerpt`.
- Wielokrotne kategorie / tagi (osobna tabela `tags` + `post_tags`).
- Logo użytkownika i edytowalna nazwa serwisu z `settings`.
- Sanityzacja HTML edytora (HTMLPurifier).
- Pliki cookies banner + polityka prywatności.
- Komentarze (osobna tabela `comments` + moderacja).

### Średnie (pół dnia – dzień)
- Wielu użytkowników z rolami (tabela `users`, role: `admin`, `editor`,
  `author`).
- Galerie i media library (tabela `media`).
- Wersjonowanie wpisów (tabela `post_revisions`).
- Newsletter (integracja z Mailerlite/Buttondown).
- Optymalizacja obrazów (WebP, srcset, lazy hero).
- Tryb AMP / czysty widok do druku (mamy `@media print`).

### Większe
- Migracja na PostgreSQL/MySQL (zmiana DSN i kilka detali SQLite).
- API REST (`/api/posts`) — bazując na PDO i tych samych funkcjach z
  `functions.php`.
- Headless front (Next.js / Astro) podpięty pod API.
- Cache (file/Redis) dla list i artykułów.
- AI summary per artykuł (generowanie excerpta przez Claude API).
- Statyczna generacja (export do plików HTML dla maksymalnej wydajności).

### Konwencje, które ułatwiają rozbudowę
- Cała logika dostępu do danych w `includes/functions.php` — łatwo podmienić.
- Schema migrowana w jednym miejscu (`initSchema`) — łatwo dodać kolumny
  (użyj `ALTER TABLE` w nowej migracji).
- Konfiguracja w jednym pliku (`includes/config.php`).
- Brak frameworka — każdy skrypt jest zrozumiały w 5 minut.

---

## Licencja

MIT. Możesz używać komercyjnie, modyfikować i rozpowszechniać.

---

**Miłego pisania!** Jeśli zbudujesz coś ciekawego, daj znać.
