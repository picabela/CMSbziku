# Changelog

## 1.1.0 — 2026-06-12

### Nowości
- **Zakładka Autorzy** w panelu admin — pełny CRUD: dodawanie, edycja, usuwanie autorów
  - Rozwijane karty (`<details>`) — przejrzysty UI nawet przy wielu autorach
  - Per autor: imię, bio, opcjonalne zdjęcie (JPG/PNG/WebP, auto-konwersja do WebP, max 4 MB), email, URL
  - Toggle „aktywny" — sterujący czy bio pojawi się w stopce artykułu
  - Data utworzenia + licznik przypisanych artykułów
  - Globalny toggle „Pokaż stopkę autora" — nadrzędny przełącznik
  - Domyślny autor (auto-przypisywany do nowych artykułów)
- **Filtry w liście artykułów** (admin → Artykuły):
  - Zakres dat (od / do)
  - „Ostatnie X dni" (domyślnie 3)
  - Licznik wszystkich opublikowanych / szkiców / łącznie
- **Hurtowa akcja „Przypisz autora"** w zaznaczonych artykułach
- **Edycja artykułu** — dropdown wyboru autora z bazy (z fallbackiem do pola tekstowego)
- **Stopka autora w artykule** — zdjęcie, bio, linki (active + globalny toggle)

### Schema / migracje (idempotent)
- Nowa tabela `authors` (name, slug, bio, photo, email, url, active, sort_order)
- Nowa kolumna `posts.author_id` (FK do `authors.id`, nullable)
- Nowe settings: `authors_footer_enabled`, `default_author_id`

### CSS
- `.author-card` (admin) — expandable cards z badges aktywny/nieaktywny/domyślny
- `.admin-stats`, `.admin-filters` — pasek statystyk i filtrów w liście artykułów
- `.author-footer` — responsywna stopka autora we wszystkich 6 motywach
  (broadsheet, bulletin, classic, gazette, modern, tribune)

## 1.0.0
- Pierwsza wersja CMS bziku z systemem auto-aktualizacji z GitHuba
