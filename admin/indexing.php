<?php
$adminTitle = 'Szybkie indeksowanie';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../includes/indexing.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ===== Obsługa POST ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';

    /* --- Zapisz ustawienia Google --- */
    if ($action === 'save_google') {
        setSetting('indexing_google_enabled', isset($_POST['google_enabled']) ? '1' : '0');

        if (!empty($_FILES['google_key_file']['name']) && $_FILES['google_key_file']['error'] === UPLOAD_ERR_OK) {
            $raw  = file_get_contents($_FILES['google_key_file']['tmp_name']);
            $data = json_decode($raw ?: '', true);
            if (!is_array($data) || empty($data['private_key']) || empty($data['client_email'])) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nieprawidłowy plik JSON — brak private_key lub client_email.'];
            } else {
                @mkdir(dirname(indexingGoogleKeyPath()), 0775, true);
                file_put_contents(indexingGoogleKeyPath(), $raw);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Klucz Google JSON wgrany. Ustawienia Google zapisane.'];
            }
        }

        if (isset($_POST['remove_google_key'])) {
            @unlink(indexingGoogleKeyPath());
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Klucz Google JSON usunięty.'];
        }

        if (!isset($_SESSION['flash'])) {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Ustawienia Google zapisane.'];
        }
        header('Location: indexing.php#google'); exit;
    }

    /* --- Zapisz ustawienia IndexNow --- */
    if ($action === 'save_indexnow') {
        setSetting('indexing_indexnow_enabled', isset($_POST['indexnow_enabled']) ? '1' : '0');

        $newKey = trim($_POST['indexnow_key'] ?? '');
        $oldKey = indexingIndexNowKey();

        if ($newKey !== '' && $newKey !== $oldKey) {
            // usuń stary plik klucza
            if ($oldKey !== '') indexingDeleteIndexNowKeyFile($oldKey);
            setSetting('indexing_indexnow_key', $newKey);
            $written = indexingWriteIndexNowKeyFile($newKey);
            if (!$written) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Ustawienia zapisane, ale nie udało się zapisać pliku klucza na dysku (sprawdź uprawnienia).'];
                header('Location: indexing.php#indexnow'); exit;
            }
        } elseif ($newKey === '' && $oldKey !== '') {
            indexingDeleteIndexNowKeyFile($oldKey);
            setSetting('indexing_indexnow_key', '');
        }

        if (isset($_POST['generate_key'])) {
            $gen = bin2hex(random_bytes(16));
            if ($oldKey !== '') indexingDeleteIndexNowKeyFile($oldKey);
            setSetting('indexing_indexnow_key', $gen);
            indexingWriteIndexNowKeyFile($gen);
        }

        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Ustawienia IndexNow zapisane.'];
        header('Location: indexing.php#indexnow'); exit;
    }

    /* --- Zapisz ustawienia ogólne --- */
    if ($action === 'save_general') {
        setSetting('indexing_auto_on_publish', isset($_POST['auto_on_publish']) ? '1' : '0');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Ustawienia ogólne zapisane.'];
        header('Location: indexing.php#general'); exit;
    }

    /* --- Wyślij URL ręcznie --- */
    if ($action === 'submit_urls') {
        $raw  = trim($_POST['urls'] ?? '');
        $urls = array_filter(array_map('trim', explode("\n", $raw)));
        if (empty($urls)) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Podaj co najmniej jeden URL.'];
        } elseif (!indexingGoogleEnabled() && !indexingIndexNowEnabled()) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Żaden kanał indeksowania nie jest włączony. Skonfiguruj najpierw Google API lub IndexNow.'];
        } else {
            $ok = $err = 0;
            foreach ($urls as $url) {
                $res = indexingSubmitUrl($url);
                foreach ($res as $r) { $r['ok'] ? $ok++ : $err++; }
            }
            $_SESSION['flash'] = ['type' => $err === 0 ? 'success' : 'error',
                'msg' => "Wysłano " . count($urls) . " URL(i). Sukces: $ok, Błędy: $err."];
        }
        header('Location: indexing.php#console'); exit;
    }

    /* --- Testuj połączenie Google --- */
    if ($action === 'test_google') {
        $token = indexingGoogleGetToken();
        if ($token) {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Połączenie z Google API OK — token pobrany pomyślnie.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nie udało się pobrać tokenu. Sprawdź klucz JSON i uprawnienia konta serwisowego.'];
        }
        header('Location: indexing.php#google'); exit;
    }

    /* --- Wyczyść log --- */
    if ($action === 'clear_log') {
        indexingClearLog();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Historia wyczyszczona.'];
        header('Location: indexing.php#history'); exit;
    }
}

$googleKeyExists = is_file(indexingGoogleKeyPath());
$googleKeyData   = indexingGoogleKeyData();
$indexNowKey     = indexingIndexNowKey();
$log             = indexingGetLog(150);
$tab = $_GET['tab'] ?? (isset($_GET['#']) ? '' : 'console');
?>

<div class="admin-page">
    <div class="admin-page__head">
        <h1>Szybkie indeksowanie URL</h1>
        <p class="hint" style="margin-top:0.25rem">Zgłaszaj nowe artykuły bezpośrednio do Google i wyszukiwarek obsługujących IndexNow — bez czekania na crawl.</p>
    </div>

    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <!-- Tabs -->
    <nav class="tabs" style="display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:1.5rem">
        <?php foreach ([
            'console'  => 'Konsola',
            'google'   => 'Google API',
            'indexnow' => 'IndexNow',
            'general'  => 'Automatyzacja',
            'history'  => 'Historia (' . count($log) . ')',
        ] as $t => $label): ?>
            <a href="?tab=<?= $t ?>#<?= $t ?>" id="tab-<?= $t ?>"
               style="padding:.6rem 1.1rem;font-size:.9rem;font-weight:500;text-decoration:none;border-bottom:2px solid <?= $tab === $t ? '#2540b8' : 'transparent' ?>;color:<?= $tab === $t ? '#2540b8' : '#6b7280' ?>;margin-bottom:-2px">
                <?= e($label) ?>
                <?php if ($t === 'google'): ?>
                    <span style="font-size:.65rem;vertical-align:middle;margin-left:3px"><?= $googleKeyData ? '✅' : '⚠️' ?></span>
                <?php elseif ($t === 'indexnow'): ?>
                    <span style="font-size:.65rem;vertical-align:middle;margin-left:3px"><?= $indexNowKey ? '✅' : '⚠️' ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php /* ========== KONSOLA ========== */ if ($tab === 'console'): ?>
    <section class="settings-card" id="console">
        <h2 style="margin-top:0">Wyślij URL do indeksowania</h2>
        <?php if (!indexingGoogleEnabled() && !indexingIndexNowEnabled()): ?>
            <div class="flash flash--error" style="margin-bottom:1rem">Żaden kanał nie jest włączony. Skonfiguruj <a href="?tab=google">Google API</a> lub <a href="?tab=indexnow">IndexNow</a> przed wysyłaniem.</div>
        <?php endif; ?>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="submit_urls">
            <label>URLe do zgłoszenia (jeden na linię, max 100)
                <textarea name="urls" rows="6" placeholder="https://twojadomena.pl/nowy-artykul&#10;https://twojadomena.pl/strona/o-nas" style="font-family:ui-monospace,monospace;font-size:.85rem"></textarea>
            </label>
            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.5rem">
                <button type="submit" class="btn btn--primary">Wyślij do API</button>
                <span class="hint" style="margin:0">
                    Kanały:
                    <?php if (indexingGoogleEnabled()): ?><span style="color:#16a34a">✓ Google</span><?php else: ?><span style="color:#9ca3af">✗ Google</span><?php endif; ?>
                    <?php if (indexingIndexNowEnabled()): ?><span style="color:#16a34a;margin-left:.5rem">✓ IndexNow</span><?php else: ?><span style="color:#9ca3af;margin-left:.5rem">✗ IndexNow</span><?php endif; ?>
                </span>
            </div>
        </form>
    </section>

    <?php /* ========== GOOGLE API ========== */ elseif ($tab === 'google'): ?>
    <section class="settings-card" id="google">
        <h2 style="margin-top:0">Google Instant Indexing API</h2>

        <details style="margin-bottom:1.5rem;background:#f8f9ff;border:1px solid #dde3ff;border-radius:6px;padding:.8rem 1rem">
            <summary style="cursor:pointer;font-weight:600;color:#2540b8">Instrukcja konfiguracji (krok po kroku)</summary>
            <ol style="margin:.8rem 0 0;padding-left:1.3rem;line-height:2">
                <li>Wejdź na <strong>console.cloud.google.com</strong> → utwórz lub wybierz projekt.</li>
                <li>Włącz <strong>Indexing API</strong>: menu Interfejsy API i usługi → Biblioteka → wyszukaj „Indexing API" → Włącz.</li>
                <li>Utwórz konto serwisowe: Interfejsy API → Dane uwierzytelniające → Utwórz dane → Konto usługi. Nadaj dowolną nazwę.</li>
                <li>Wejdź w konto serwisowe → zakładka <strong>Klucze</strong> → Dodaj klucz → Utwórz nowy klucz → <strong>JSON</strong> → Pobierz plik.</li>
                <li>Wejdź w <strong>Google Search Console</strong> → Ustawienia → Użytkownicy i uprawnienia → Dodaj użytkownika. Wpisz adres e-mail konta serwisowego (jest w pobranym JSON jako <code>client_email</code>). Uprawnienie: <strong>Właściciel</strong>.</li>
                <li>Wgraj pobrany plik JSON poniżej i kliknij Zapisz.</li>
                <li>Kliknij „Testuj połączenie" — powinno się pojawić ✓ OK.</li>
            </ol>
            <p style="margin:.5rem 0 0;font-size:.85rem;color:#6b7280">⚠️ Limit Google: 200 URL/dobę. Plik JSON jest przechowywany w <code>data/</code> — poza zasięgiem przeglądarki.</p>
        </details>

        <form method="post" enctype="multipart/form-data" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_google">

            <fieldset class="radio-group">
                <legend>Włączenie</legend>
                <label class="checkbox">
                    <input type="checkbox" name="google_enabled" value="1" <?= indexingGoogleEnabled() ? 'checked' : '' ?>>
                    Włącz Google Indexing API
                </label>
            </fieldset>

            <fieldset class="radio-group">
                <legend>Klucz JSON konta serwisowego</legend>
                <?php if ($googleKeyData): ?>
                    <p style="color:#16a34a;margin:.25rem 0">✓ Klucz wgrany — konto: <code><?= e($googleKeyData['client_email']) ?></code></p>
                    <label class="checkbox"><input type="checkbox" name="remove_google_key" value="1"> Usuń klucz JSON</label>
                    <p style="margin:.5rem 0 0">
                <?php elseif ($googleKeyExists): ?>
                    <p style="color:#dc2626;margin:.25rem 0">⚠ Plik klucza istnieje, ale wygląda na uszkodzony (brak private_key/client_email).</p>
                    <label class="checkbox"><input type="checkbox" name="remove_google_key" value="1"> Usuń i wgraj poprawny</label>
                    <p style="margin:.5rem 0 0">
                <?php else: ?>
                    <p style="color:#dc2626;margin:.25rem 0">✗ Brak klucza — API nie będzie działać.</p>
                <?php endif; ?>
                <label>Wgraj plik JSON
                    <input type="file" name="google_key_file" accept="application/json,.json">
                </label>
            </fieldset>

            <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem">
                <button type="submit" class="btn btn--primary">Zapisz</button>
                <?php if ($googleKeyData): ?>
                    <button type="submit" form="form-test-google" class="btn">Testuj połączenie</button>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($googleKeyData): ?>
        <form id="form-test-google" method="post" style="display:none">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="test_google">
        </form>
        <?php endif; ?>
    </section>

    <?php /* ========== INDEXNOW ========== */ elseif ($tab === 'indexnow'): ?>
    <section class="settings-card" id="indexnow">
        <h2 style="margin-top:0">IndexNow</h2>

        <details style="margin-bottom:1.5rem;background:#f8f9ff;border:1px solid #dde3ff;border-radius:6px;padding:.8rem 1rem">
            <summary style="cursor:pointer;font-weight:600;color:#2540b8">Czym jest IndexNow i jak go skonfigurować?</summary>
            <p style="margin:.8rem 0 .5rem">IndexNow to otwarty protokół wspierany przez Bing, Yandex i inne wyszukiwarki (nie Google — do Google użyj zakładki Google API). Zgłaszasz URL jednym żądaniem do <code>api.indexnow.org</code>, a mechanizm sam dystrybuuje informację do partnerów.</p>
            <ol style="padding-left:1.3rem;line-height:2">
                <li>Kliknij <strong>Generuj klucz</strong> (lub wpisz własny — dowolny ciąg liter i cyfr).</li>
                <li>CMS automatycznie zapisze plik weryfikacyjny <code>{klucz}.txt</code> w korzeniu serwisu (wymagane przez IndexNow).</li>
                <li>Sprawdź, czy plik jest dostępny: <code><?= e(BASE_URL) ?>/<em>twój-klucz</em>.txt</code></li>
                <li>Włącz IndexNow i zacznij zgłaszać URLe.</li>
            </ol>
            <p style="margin:.5rem 0 0;font-size:.85rem;color:#6b7280">IndexNow nie ma limitu dziennego. Odpowiedź HTTP 202 = sukces (URL przyjęty do przetworzenia).</p>
        </details>

        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_indexnow">

            <fieldset class="radio-group">
                <legend>Włączenie</legend>
                <label class="checkbox">
                    <input type="checkbox" name="indexnow_enabled" value="1" <?= indexingIndexNowEnabled() ? 'checked' : '' ?>>
                    Włącz IndexNow
                </label>
            </fieldset>

            <label>Klucz API
                <div style="display:flex;gap:.5rem;align-items:center">
                    <input type="text" name="indexnow_key" value="<?= e($indexNowKey) ?>" placeholder="np. a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4"
                        style="font-family:ui-monospace,monospace;flex:1">
                    <button type="submit" name="generate_key" value="1" class="btn" onclick="return confirm('Wygenerować nowy klucz? Stary plik .txt zostanie usunięty.')">Generuj klucz</button>
                </div>
            </label>

            <?php if ($indexNowKey): ?>
                <p class="hint">Plik weryfikacyjny:
                    <a href="<?= e(BASE_URL . '/' . $indexNowKey . '.txt') ?>" target="_blank" rel="noopener">
                        <?= e(BASE_URL . '/' . $indexNowKey . '.txt') ?> ↗
                    </a>
                    <?php $keyFileExists = is_file(__DIR__ . '/../' . $indexNowKey . '.txt'); ?>
                    <?= $keyFileExists ? '<span style="color:#16a34a">✓ plik istnieje</span>' : '<span style="color:#dc2626">✗ plik nie istnieje — sprawdź uprawnienia</span>' ?>
                </p>
            <?php endif; ?>

            <div style="margin-top:.5rem">
                <button type="submit" class="btn btn--primary">Zapisz</button>
            </div>
        </form>
    </section>

    <?php /* ========== AUTOMATYZACJA ========== */ elseif ($tab === 'general'): ?>
    <section class="settings-card" id="general">
        <h2 style="margin-top:0">Automatyczne indeksowanie przy publikacji</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_general">

            <fieldset class="radio-group">
                <legend>Automatyzacja</legend>
                <label class="checkbox">
                    <input type="checkbox" name="auto_on_publish" value="1" <?= indexingAutoEnabled() ? 'checked' : '' ?>>
                    Automatycznie zgłaszaj URL gdy artykuł lub strona są <strong>opublikowane</strong>
                </label>
                <p class="hint">Dotyczy: nowych artykułów z edytora, artykułów z auto-importu oraz stron. URL jest wysyłany do wszystkich włączonych kanałów (Google i/lub IndexNow) natychmiast po zapisaniu ze statusem „opublikowany".</p>
            </fieldset>

            <button type="submit" class="btn btn--primary">Zapisz</button>
        </form>
    </section>

    <?php /* ========== HISTORIA ========== */ elseif ($tab === 'history'): ?>
    <section class="settings-card" id="history">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
            <h2 style="margin:0">Historia zgłoszeń</h2>
            <?php if ($log): ?>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="clear_log">
                    <button type="submit" class="btn" onclick="return confirm('Wyczyścić całą historię?')">Wyczyść</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if (!$log): ?>
            <p class="hint">Brak wpisów. Historia pojawi się po pierwszym zgłoszeniu URL.</p>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.82rem">
                    <thead>
                        <tr style="background:#f3f4f6;text-align:left">
                            <th style="padding:.5rem .75rem;white-space:nowrap">Data</th>
                            <th style="padding:.5rem .75rem;white-space:nowrap">Kanał</th>
                            <th style="padding:.5rem .75rem;white-space:nowrap">Status</th>
                            <th style="padding:.5rem .75rem">URL</th>
                            <th style="padding:.5rem .75rem">Odpowiedź</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log as $row): ?>
                            <tr style="border-top:1px solid #e5e7eb">
                                <td style="padding:.4rem .75rem;white-space:nowrap;color:#6b7280"><?= e(substr($row['created_at'], 0, 16)) ?></td>
                                <td style="padding:.4rem .75rem;white-space:nowrap;font-weight:500"><?= e($row['method']) ?></td>
                                <td style="padding:.4rem .75rem;white-space:nowrap">
                                    <?php if ($row['ok']): ?>
                                        <span style="color:#16a34a;font-weight:600">✓ OK</span>
                                    <?php else: ?>
                                        <span style="color:#dc2626;font-weight:600">✗ Błąd</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:.4rem .75rem;word-break:break-all;max-width:300px">
                                    <a href="<?= e($row['url']) ?>" target="_blank" rel="noopener" style="color:#2540b8"><?= e($row['url']) ?></a>
                                </td>
                                <td style="padding:.4rem .75rem;color:#6b7280;max-width:280px;word-break:break-all;font-family:ui-monospace,monospace;font-size:.78rem"><?= e($row['response']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php';
