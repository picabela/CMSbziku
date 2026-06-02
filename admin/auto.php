<?php
$adminTitle = 'Auto-import AI';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$lastResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $keys = ['auto_enabled','auto_interval_minutes','auto_posts_per_tick','auto_discovery_interval_minutes','auto_max_age_days','auto_publish',
                 'openai_api_key','openai_model','openai_temperature',
                 'auto_target_language','auto_default_category','auto_default_author',
                 'auto_max_tags','source_attribution_template','auto_keep_original_date','auto_content_max_chars',
                 'auto_date_range_enabled','auto_date_from','auto_date_to'];
        foreach ($keys as $k) {
            $v = $_POST[$k] ?? '';
            if (in_array($k, ['auto_enabled','auto_publish','auto_keep_original_date','auto_date_range_enabled'], true)) {
                $v = isset($_POST[$k]) ? '1' : '0';
            }
            setSetting($k, (string)$v);
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Ustawienia zapisane.'];
        header('Location: auto.php'); exit;
    }

    if ($action === 'rotate_token') {
        setSetting('auto_token', bin2hex(random_bytes(16)));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Wygenerowano nowy token.'];
        header('Location: auto.php'); exit;
    }

    if ($action === 'run_now') {
        require_once __DIR__ . '/../includes/autoimport.php';
        $max = (int)($_POST['max'] ?? 2);
        $mode = $_POST['mode'] ?? 'bg';

        if ($mode === 'sync' || $mode === 'debug') {
            $lastResult = runAutoImport($max, true, $mode === 'debug');
        } else {
            $spawn = spawnAutoImportInBackground($max, true);
            if (isset($spawn['callback'])) {
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Wystartowane w tle. Zajrzyj do logu za chwilę.'];
                $spawn['callback'](); // fastcgi_finish_request + run
                exit;
            }
            if (!empty($spawn['error'])) {
                // Fallback synchronous if no background method available
                $lastResult = runAutoImport($max, true);
            } else {
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Run wystartowany w tle (' . $spawn['method'] . '). Sprawdź log za chwilę.'];
                header('Location: runs.php');
                exit;
            }
        }
    }
}

$cronUrl = BASE_URL . '/cron/run.php?token=' . urlencode(setting('auto_token'));
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Auto-import AI</h1>
        <div>
            <a href="sources.php" class="btn">Źródła</a>
            <a href="categories.php" class="btn">Kategorie</a>
            <a href="queue.php" class="btn">Kolejka</a>
            <a href="runs.php" class="btn">Log uruchomień</a>
        </div>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <?php if ($lastResult): ?>
        <div class="flash flash--<?= !empty($lastResult['error']) ? 'error' : 'success' ?>">
            Run zakończony.
            Znalezione: <?= (int)($lastResult['found'] ?? 0) ?>,
            do kolejki: <?= (int)($lastResult['enqueued'] ?? 0) ?>,
            opublikowane: <strong><?= (int)($lastResult['imported'] ?? 0) ?></strong>,
            pominięte: <?= (int)($lastResult['skipped'] ?? 0) ?>,
            błędy: <?= (int)($lastResult['failed'] ?? 0) ?>.
            <?php if (!empty($lastResult['error'])): ?> <strong><?= e($lastResult['error']) ?></strong><?php endif; ?>
        </div>
        <?php if (!empty($lastResult['log'])): ?>
            <pre class="log-box"><?= e(implode("\n", $lastResult['log'])) ?></pre>
        <?php endif; ?>

        <?php if (!empty($lastResult['items_trace'])): ?>
            <div class="settings-card">
                <h2>🔬 Pełne logi procesu — krok po kroku</h2>
                <p class="hint">Dla każdego przetwarzanego artykułu: konfiguracja źródła, fetch, detekcja daty, ekstrakcja treści, dokładne prompty do OpenAI, surowa odpowiedź modelu, finalny zapis.</p>
                <?php foreach ($lastResult['items_trace'] as $idx => $trace): ?>
                    <details class="trace-item" <?= $idx === 0 ? 'open' : '' ?>>
                        <summary>
                            <span class="trace-item__num">#<?= $idx + 1 ?></span>
                            <span class="trace-item__title"><?= e(mb_substr($trace['title'] ?? '(brak tytułu)', 0, 100)) ?></span>
                            <span class="pill pill--<?= e($trace['steps']['result']['status'] ?? 'pending') ?>"><?= e($trace['steps']['result']['status'] ?? '?') ?></span>
                        </summary>
                        <div class="trace-body">
                            <p class="trace-meta">URL: <code><?= e($trace['external_url'] ?? '') ?></code><br>
                            Queue ID: #<?= (int)($trace['queue_id'] ?? 0) ?></p>

                            <?php foreach ($trace['steps'] as $stepKey => $stepData): ?>
                                <details class="trace-step" open>
                                    <summary><strong><?= e($stepKey) ?></strong></summary>
                                    <?php if ($stepKey === '5_openai_request' && is_array($stepData)): ?>
                                        <div class="trace-kv">
                                            <div><span class="trace-k">Endpoint:</span> <code><?= e($stepData['endpoint']) ?></code></div>
                                            <div><span class="trace-k">Model:</span> <code><?= e($stepData['model']) ?></code></div>
                                            <div><span class="trace-k">Temperature:</span> <code><?= e((string)$stepData['temperature']) ?></code></div>
                                            <div><span class="trace-k">Dostępne kategorie:</span> <code><?= e(implode(', ', $stepData['available_categories'] ?? [])) ?></code></div>
                                        </div>
                                        <h4>System prompt</h4>
                                        <pre class="trace-pre"><?= e($stepData['system_prompt']) ?></pre>
                                        <h4>User prompt (treść wysłana do modelu)</h4>
                                        <pre class="trace-pre"><?= e($stepData['user_prompt']) ?></pre>
                                    <?php elseif ($stepKey === '6_openai_response' && is_array($stepData)): ?>
                                        <div class="trace-kv">
                                            <div><span class="trace-k">HTTP status:</span> <code><?= e((string)$stepData['http_status']) ?></code></div>
                                            <div><span class="trace-k">Tokeny:</span> <code><?= e(json_encode($stepData['usage'] ?? null)) ?></code></div>
                                            <div><span class="trace-k">Kategoria zwrócona:</span> <code><?= e($stepData['category_raw']) ?></code> → znormalizowana: <strong><?= e($stepData['category_normalized']) ?></strong></div>
                                        </div>
                                        <h4>Raw JSON z OpenAI</h4>
                                        <pre class="trace-pre"><?= e($stepData['raw_json']) ?></pre>
                                        <h4>Sparsowane pola</h4>
                                        <pre class="trace-pre"><?= e(json_encode($stepData['parsed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                                    <?php elseif ($stepKey === '3_date_extraction' && is_array($stepData)): ?>
                                        <div class="trace-kv">
                                            <div><span class="trace-k">RSS data:</span> <code><?= e((string)($stepData['rss_ts'] ?? '—')) ?></code></div>
                                            <div><span class="trace-k">HTML data:</span> <code><?= e((string)($stepData['html_ts'] ?? '—')) ?></code></div>
                                            <div><span class="trace-k">Użyta data:</span> <strong><?= e((string)($stepData['effective_ts'] ?? '—')) ?></strong> (<?= e($stepData['source_used']) ?>)</div>
                                        </div>
                                        <h4>Próby ekstrakcji daty</h4>
                                        <ol class="trace-attempts">
                                            <?php foreach ($stepData['attempts'] as $a): ?>
                                                <li><strong><?= e($a['signal']) ?></strong>
                                                <?php if (isset($a['value'])): ?> — wartość: <code><?= e($a['value']) ?></code><?php endif; ?>
                                                <?php if (!empty($a['parsed'])): ?> ✓ sparsowano: <code><?= e($a['parsed']) ?></code><?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ol>
                                    <?php elseif ($stepKey === '4_content_extraction' && is_array($stepData)): ?>
                                        <div class="trace-kv">
                                            <div><span class="trace-k">Źródło treści:</span> <code><?= e($stepData['source']) ?></code></div>
                                            <div><span class="trace-k">Długość:</span> <code><?= (int)$stepData['length_chars'] ?> znaków</code></div>
                                        </div>
                                        <h4>Podgląd treści (max 600 znaków)</h4>
                                        <pre class="trace-pre"><?= e($stepData['preview']) ?></pre>
                                    <?php else: ?>
                                        <pre class="trace-pre"><?= e(is_string($stepData) ? $stepData : json_encode($stepData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                                    <?php endif; ?>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="settings-card">
        <h2>Uruchom teraz</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="run_now">
            <label>Ile artykułów wygenerować?
                <input type="number" name="max" min="1" max="10" value="2">
            </label>
            <fieldset class="radio-group">
                <legend>Tryb</legend>
                <label class="checkbox"><input type="radio" name="mode" value="bg" checked> W tle <small class="hint">(zalecane do produkcji — strona nie zawiesi się, postęp w „Log uruchomień")</small></label>
                <label class="checkbox"><input type="radio" name="mode" value="sync"> Synchronicznie <small class="hint">(czekaj na wynik — pokaże live log poniżej)</small></label>
                <label class="checkbox"><input type="radio" name="mode" value="debug"> 🔬 Z pełnymi logami procesu <small class="hint">(każdy krok dla każdego artykułu — prompty, treść, dane, OpenAI request/response)</small></label>
            </fieldset>
            <button class="btn btn--primary" type="submit">Uruchom</button>
        </form>
    </div>

    <div class="settings-card">
        <h2>Cron — token i URL</h2>
        <p>Ustaw <strong>cron na swoim hostingu</strong> (w panelu hostido: <em>Cron / Zadania harmonogramu</em>), by ten URL był wywoływany cyklicznie:</p>
        <code class="code-block"><?= e($cronUrl) ?></code>
        <p class="hint">Przykładowa linia w crontab (co 15 min):<br>
            <code>*/15 * * * * curl -s "<?= e($cronUrl) ?>" &gt; /dev/null</code></p>
        <p class="hint"><strong>Ważne:</strong> cron działa na serwerze hostingu — niezależnie od tego, czy masz włączoną przeglądarkę, komputer czy telefon. Po jednorazowym ustawieniu możesz spać spokojnie, hosting sam odpala zadanie o wskazanych godzinach.</p>
        <form method="post" style="margin-top:1rem">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="rotate_token">
            <button class="btn" type="submit" onclick="return confirm('Wygenerować nowy token? Stary przestanie działać.');">Rotuj token</button>
        </form>
    </div>

    <form method="post" class="settings-card">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">

        <h2>Status</h2>
        <label class="checkbox"><input type="checkbox" name="auto_enabled" value="1" <?= setting('auto_enabled') === '1' ? 'checked' : '' ?>> Auto-import włączony</label>
        <label class="checkbox"><input type="checkbox" name="auto_publish" value="1" <?= setting('auto_publish', '1') === '1' ? 'checked' : '' ?>> Publikuj wygenerowane artykuły od razu (inaczej draft)</label>

        <h2 style="margin-top:1.5rem">Harmonogram</h2>
        <label>Interwał (minuty) — tylko informacyjnie, faktyczny harmonogram ustaw w cron
            <input type="number" name="auto_interval_minutes" min="5" value="<?= e(setting('auto_interval_minutes', '60')) ?>">
        </label>
        <label>Postów na tick (max liczba artykułów wygenerowanych przy jednym uruchomieniu)
            <input type="number" name="auto_posts_per_tick" min="1" max="20" value="<?= e(setting('auto_posts_per_tick', '2')) ?>">
            <small class="hint">Kluczowy parametr kontroli kosztów i czasu. Reszta kandydatów czeka w kolejce do następnego ticku.</small>
        </label>
        <label>Discovery — odpytuj feedy nie częściej niż co (minuty)
            <input type="number" name="auto_discovery_interval_minutes" min="5" max="1440" value="<?= e(setting('auto_discovery_interval_minutes', '60')) ?>">
            <small class="hint">Mniejsza wartość = szybciej łapiesz świeże newsy, ale więcej zapytań do źródeł.</small>
        </label>
        <fieldset class="radio-group">
            <legend>Filtr daty publikacji źródła</legend>
            <label>Tryb A — Max wiek artykułu (dni)
                <input type="number" name="auto_max_age_days" min="1" max="365" value="<?= e(setting('auto_max_age_days', '3')) ?>">
                <small class="hint">Szukaj artykułów od X dni do dzisiaj. Używane gdy przedział dat poniżej jest WYŁĄCZONY. Artykuły bez wykrytej daty publikacji są zawsze pomijane.</small>
            </label>
            <label class="checkbox"><input type="checkbox" name="auto_date_range_enabled" value="1" <?= setting('auto_date_range_enabled', '0') === '1' ? 'checked' : '' ?>> <strong>Tryb B — Użyj zamiast tego przedziału dat</strong></label>
            <div class="form-row form-row--2">
                <label>Od (data)
                    <input type="date" name="auto_date_from" value="<?= e(setting('auto_date_from', '')) ?>">
                </label>
                <label>Do (data — pusto = dziś)
                    <input type="date" name="auto_date_to" value="<?= e(setting('auto_date_to', '')) ?>">
                </label>
            </div>
            <p class="hint">Tryb B nadpisuje Tryb A jeśli zaznaczony i "Od" jest wypełnione. Pojedyncze źródło z własnym max_age_days nadpisuje Tryb A.</p>
        </fieldset>

        <h2 style="margin-top:1.5rem">Treść i ekstrakcja</h2>
        <label>Limit znaków treści wysyłanej do AI (max długość promptu)
            <input type="number" name="auto_content_max_chars" min="500" max="200000" step="500" value="<?= e(setting('auto_content_max_chars', '30000')) ?>">
            <small class="hint">Jeśli artykuł jest krótszy — bierzemy ile jest. Większy limit = lepszy kontekst, ale więcej tokenów. gpt-4o-mini ma okno 128k tokenów (~400k znaków), 30 000 znaków to bezpieczny optymalny default.</small>
        </label>

        <h2 style="margin-top:1.5rem">Data publikacji nowych artykułów</h2>
        <label class="checkbox"><input type="checkbox" name="auto_keep_original_date" value="1" <?= setting('auto_keep_original_date', '0') === '1' ? 'checked' : '' ?>> Publikuj z datą oryginalnego artykułu (źródła)</label>
        <p class="hint">Domyślnie wyłączone — publikacja z chwilą dodania. Włącz, by data publikacji odpowiadała oryginalnej dacie ze źródła.</p>

        <h2 style="margin-top:1.5rem">Stopka źródła pod artykułem</h2>
        <label>Szablon (placeholder-y: <code>{url}</code>, <code>{source}</code>)
            <textarea name="source_attribution_template" rows="2"><?= e(setting('source_attribution_template', 'Opracowanie redakcji na podstawie źródła: {url} ({source}).')) ?></textarea>
            <small class="hint">Zmiany dotyczą tylko NOWYCH publikacji — istniejące artykuły mają stopkę już zapisaną w treści.</small>
        </label>

        <h2 style="margin-top:1.5rem">Tagi (firmy i marki)</h2>
        <label>Maksymalna liczba tagów na artykuł
            <input type="number" name="auto_max_tags" min="0" max="10" value="<?= e(setting('auto_max_tags', '3')) ?>">
            <small class="hint">AI wyciągnie z tekstu nazwy firm/marek/produktów (np. Google, Bing, Perplexity, ChatGPT). 0 = wyłączone. Istniejące tagi są ponownie użyte — nie tworzymy duplikatów. <a href="tags.php">Zarządzaj tagami</a>.</small>
        </label>

        <h2 style="margin-top:1.5rem">OpenAI</h2>
        <label>API key
            <span class="input-with-eye">
                <input type="password" name="openai_api_key" id="openai_api_key" value="<?= e(setting('openai_api_key', '')) ?>" placeholder="sk-...">
                <button type="button" class="eye-btn" onclick="togglePass('openai_api_key', this)" aria-label="Pokaż / ukryj hasło" title="Pokaż / ukryj">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </span>
        </label>
        <script>
        function togglePass(id, btn) {
            const i = document.getElementById(id);
            const showing = i.type === 'text';
            i.type = showing ? 'password' : 'text';
            btn.classList.toggle('eye-btn--active', !showing);
        }
        </script>
        <label>Model
            <input type="text" name="openai_model" value="<?= e(setting('openai_model', 'gpt-4o-mini')) ?>">
        </label>
        <label>Temperatura (0.0 – 1.5)
            <input type="number" step="0.1" min="0" max="1.5" name="openai_temperature" value="<?= e(setting('openai_temperature', '0.4')) ?>">
        </label>

        <h2 style="margin-top:1.5rem">Domyślne wartości</h2>
        <label>Język docelowy<input type="text" name="auto_target_language" value="<?= e(setting('auto_target_language', 'pl')) ?>"></label>
        <label>Domyślna kategoria<input type="text" name="auto_default_category" value="<?= e(setting('auto_default_category', 'Aktualności')) ?>"></label>
        <label>Domyślny autor<input type="text" name="auto_default_author" value="<?= e(setting('auto_default_author', 'Redakcja AI')) ?>"></label>

        <h2 style="margin-top:1.5rem">Prompty AI</h2>
        <p class="hint">Prompt redakcyjny (system), instrukcja wyboru kategorii i wyciągania tagów są teraz edytowalne w jednym miejscu: <a href="prompts.php"><strong>zakładka Prompty</strong></a>.</p>

        <button type="submit" class="btn btn--primary">Zapisz ustawienia</button>
    </form>

    <div class="settings-card">
        <h2>Ostatni run</h2>
        <p><?= setting('auto_last_run') ? 'Zakończony: <strong>' . e(setting('auto_last_run')) . '</strong>' : 'Brak — nic jeszcze nie uruchomiono.' ?></p>
    </div>
</div>
<?php require __DIR__ . '/_footer.php';
