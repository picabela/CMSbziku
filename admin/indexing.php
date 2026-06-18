<?php
$adminTitle = 'Szybkie indeksowanie';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/indexing.php';

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

    /* --- Zapisz ustawienia WebSub (HUB) --- */
    if ($action === 'save_websub') {
        setSetting('websub_enabled', isset($_POST['websub_enabled']) ? '1' : '0');
        $hub = trim($_POST['websub_hub_url'] ?? '');
        setSetting('websub_hub_url', $hub !== '' ? $hub : 'https://pubsubhubbub.appspot.com/');
        setSetting('websub_feed_url', trim($_POST['websub_feed_url'] ?? ''));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Ustawienia WebSub zapisane.'];
        header('Location: indexing.php?tab=websub#websub'); exit;
    }

    /* --- Ręczny ping huba --- */
    if ($action === 'websub_ping') {
        $r = websubPublish();
        _indexingLog($r['feed'] ?? websubFeedUrl(), 'WebSub', $r['ok'], $r['msg']);
        $_SESSION['flash'] = ['type' => $r['ok'] ? 'success' : 'error',
            'msg' => ($r['ok'] ? '✓ Hub powiadomiony. ' : '✗ Ping huba nieudany. ') . $r['msg']];
        header('Location: indexing.php?tab=websub#websub'); exit;
    }

    /* --- Zapisz ustawienia monitoringu (GSC URL Inspection) --- */
    if ($action === 'save_gsc') {
        setSetting('gsc_inspection_enabled', isset($_POST['gsc_enabled']) ? '1' : '0');
        setSetting('gsc_monitor_auto', isset($_POST['gsc_monitor_auto']) ? '1' : '0');
        setSetting('gsc_site_url', trim($_POST['gsc_site_url'] ?? ''));
        setSetting('gsc_check_interval_minutes', (string)max(5, (int)($_POST['gsc_check_interval_minutes'] ?? 180)));
        setSetting('gsc_first_check_delay_min', (string)max(0, (int)($_POST['gsc_first_check_delay_min'] ?? 120)));
        setSetting('gsc_recheck_hours', (string)max(1, (int)($_POST['gsc_recheck_hours'] ?? 12)));
        setSetting('gsc_batch_per_run', (string)max(1, (int)($_POST['gsc_batch_per_run'] ?? 20)));
        setSetting('gsc_daily_quota', (string)max(1, (int)($_POST['gsc_daily_quota'] ?? 1800)));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Ustawienia monitoringu zapisane.'];
        header('Location: indexing.php?tab=monitor#monitor'); exit;
    }

    /* --- Test połączenia z GSC (inspekcja URL bazowego) --- */
    if ($action === 'gsc_test') {
        if (gscSiteUrl() === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Najpierw podaj property (siteUrl) i zapisz.'];
        } else {
            $res = gscInspectUrl(siteBaseUrl() . '/');
            $_SESSION['flash'] = !empty($res['ok'])
                ? ['type' => 'success', 'msg' => '✓ Połączenie OK. Strona główna: verdict=' . ($res['verdict'] ?? '—') . ', ' . ($res['coverage_state'] ?? '—')]
                : ['type' => 'error', 'msg' => '✗ ' . ($res['error'] ?? 'Błąd nieznany')];
        }
        header('Location: indexing.php?tab=monitor#monitor'); exit;
    }

    /* --- Uruchom turę monitoringu teraz --- */
    if ($action === 'gsc_check_now') {
        if (!gscInspectionEnabled() || gscSiteUrl() === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Włącz monitoring i podaj property przed sprawdzaniem.'];
        } else {
            $mon = gscScheduledMonitor(true);
            if (empty($mon['checked'])) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nie wykonano: ' . ($mon['reason'] ?? '—')];
            } else {
                $_SESSION['flash'] = ['type' => 'success',
                    'msg' => "Sprawdzono {$mon['checked_count']} URL(i). PASS: " . ($mon['pass'] ?? 0) . ', błędy: ' . ($mon['errors'] ?? 0) . '. Zużyto puli: ' . ($mon['quota_used'] ?? '?') . '/' . ($mon['quota_limit'] ?? '?') . '.'];
            }
        }
        header('Location: indexing.php?tab=monitor#monitor'); exit;
    }

    /* --- Sprawdź pojedynczy URL teraz --- */
    if ($action === 'gsc_check_one') {
        $u = trim($_POST['url'] ?? '');
        if ($u === '' || gscSiteUrl() === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Brak URL lub property.'];
        } else {
            $res = indexStatusCheckUrl($u);
            $_SESSION['flash'] = !empty($res['ok'])
                ? ['type' => 'success', 'msg' => 'verdict=' . ($res['verdict'] ?? '—') . ' · ' . ($res['coverage_state'] ?? '—')]
                : ['type' => 'error', 'msg' => '✗ ' . ($res['error'] ?? 'Błąd')];
        }
        header('Location: indexing.php?tab=monitor#monitor'); exit;
    }

    /* --- Wyczyść monitoring --- */
    if ($action === 'clear_monitor') {
        indexStatusClear();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Dane monitoringu wyczyszczone.'];
        header('Location: indexing.php?tab=monitor#monitor'); exit;
    }

    /* --- Zgłoś ponownie wszystkie nieudane --- */
    if ($action === 'resubmit_failed') {
        if (!indexingAnyEnabled()) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Żaden kanał indeksowania nie jest włączony.'];
            header('Location: indexing.php?tab=history'); exit;
        }
        $failed = indexingFailedUrls();
        if (empty($failed)) {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Brak nieudanych zgłoszeń do ponowienia.'];
        } else {
            $ok = $err = 0;
            foreach ($failed as $row) {
                $res = indexingSubmitUrl($row['url']);
                foreach ($res as $r) { $r['ok'] ? $ok++ : $err++; }
            }
            $_SESSION['flash'] = ['type' => $err === 0 ? 'success' : 'error',
                'msg' => 'Ponowiono ' . count($failed) . ' URL(i). Sukces: ' . $ok . ', Błędy: ' . $err . '.'];
        }
        header('Location: indexing.php?tab=history'); exit;
    }

    /* --- Zgłoś ponownie zaznaczone URL-e --- */
    if ($action === 'resubmit_selected') {
        if (!indexingAnyEnabled()) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Żaden kanał indeksowania nie jest włączony.'];
            header('Location: indexing.php?tab=history'); exit;
        }
        $urls = array_filter(array_map('trim', (array)($_POST['resubmit_urls'] ?? [])));
        if (empty($urls)) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nie zaznaczono żadnego URL.'];
        } else {
            $ok = $err = 0;
            foreach ($urls as $url) {
                $res = indexingSubmitUrl($url);
                foreach ($res as $r) { $r['ok'] ? $ok++ : $err++; }
            }
            $_SESSION['flash'] = ['type' => $err === 0 ? 'success' : 'error',
                'msg' => 'Ponowiono ' . count($urls) . ' URL(i). Sukces: ' . $ok . ', Błędy: ' . $err . '.'];
        }
        header('Location: indexing.php?tab=history'); exit;
    }

    /* --- Wyczyść log --- */
    if ($action === 'clear_log') {
        indexingClearLog();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Historia wyczyszczona.'];
        header('Location: indexing.php?tab=history'); exit;
    }
}

$googleKeyExists = is_file(indexingGoogleKeyPath());
$googleKeyData   = indexingGoogleKeyData();
$indexNowKey     = indexingIndexNowKey();
$log             = indexingGetLog(150);
$failedUrls      = indexingFailedUrls();
$monSummary      = indexStatusSummary();
$monRows         = indexStatusList(300);
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
            'websub'   => 'WebSub (HUB)',
            'google'   => 'Google API',
            'indexnow' => 'IndexNow',
            'monitor'  => 'Monitoring (' . (int)$monSummary['pass'] . '/' . (int)$monSummary['total'] . ')',
            'general'  => 'Automatyzacja',
            'history'  => 'Historia (' . count($log) . ')' . (count($failedUrls) ? ' ⚠' : ''),
        ] as $t => $label): ?>
            <a href="?tab=<?= $t ?>#<?= $t ?>" id="tab-<?= $t ?>"
               style="padding:.6rem 1.1rem;font-size:.9rem;font-weight:500;text-decoration:none;border-bottom:2px solid <?= $tab === $t ? '#2540b8' : 'transparent' ?>;color:<?= $tab === $t ? '#2540b8' : '#6b7280' ?>;margin-bottom:-2px">
                <?= e($label) ?>
                <?php if ($t === 'google'): ?>
                    <span style="font-size:.65rem;vertical-align:middle;margin-left:3px"><?= $googleKeyData ? '✅' : '⚠️' ?></span>
                <?php elseif ($t === 'indexnow'): ?>
                    <span style="font-size:.65rem;vertical-align:middle;margin-left:3px"><?= $indexNowKey ? '✅' : '⚠️' ?></span>
                <?php elseif ($t === 'websub'): ?>
                    <span style="font-size:.65rem;vertical-align:middle;margin-left:3px"><?= websubEnabled() ? '✅' : '' ?></span>
                <?php elseif ($t === 'monitor'): ?>
                    <span style="font-size:.65rem;vertical-align:middle;margin-left:3px"><?= gscInspectionEnabled() ? '✅' : '' ?></span>
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

    <?php /* ========== WEBSUB (HUB) ========== */ elseif ($tab === 'websub'): ?>
    <section class="settings-card" id="websub">
        <h2 style="margin-top:0">WebSub / PubSubHubbub — push przez HUB</h2>

        <details style="margin-bottom:1.5rem;background:#f8f9ff;border:1px solid #dde3ff;border-radius:6px;padding:.8rem 1rem">
            <summary style="cursor:pointer;font-weight:600;color:#2540b8">Jak to działa i dlaczego warto?</summary>
            <p style="margin:.8rem 0 .5rem">WebSub to mechanizm <strong>push</strong>. Zamiast czekać, aż Googlebot sam zajrzy, przy publikacji pingujemy hub, a hub natychmiast mówi subskrybentom (m.in. Google): „feed się zmienił, pobierz go ponownie". To standardowy duet z <strong>News Sitemap</strong> dla wydawców i znacznie skuteczniejszy dla newsów niż Indexing API (który oficjalnie obsługuje tylko oferty pracy i transmisje).</p>
            <ol style="padding-left:1.3rem;line-height:1.9">
                <li>Feed RSS (<code><?= e(websubFeedUrl()) ?></code>) deklaruje hub i samego siebie — robi to CMS automatycznie, gdy WebSub jest włączony.</li>
                <li>Po publikacji artykułu CMS wysyła <code>POST hub.mode=publish&amp;hub.url=&lt;FEED&gt;</code> do huba (kanał „WebSub" w Historii).</li>
                <li>Pingujemy <strong>URL FEEDU</strong>, nie pojedynczego artykułu — hub mówi tylko „ten feed się zmienił". Nowy artykuł jest już w feedzie (feed jest dynamiczny), więc kolejność jest poprawna.</li>
                <li>Sukces huba to zwykle HTTP 204. Hub <em>sygnalizuje</em> — Google i tak stosuje własne filtry jakości.</li>
            </ol>
            <p style="margin:.5rem 0 0;font-size:.85rem;color:#6b7280">Walidację implementacji sprawdzisz na <a href="https://websub.rocks/" target="_blank" rel="noopener">websub.rocks ↗</a>. Feed musi być publicznie pobieralny (200 OK, niezablokowany w robots.txt).</p>
        </details>

        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_websub">

            <fieldset class="radio-group">
                <legend>Włączenie</legend>
                <label class="checkbox">
                    <input type="checkbox" name="websub_enabled" value="1" <?= websubEnabled() ? 'checked' : '' ?>>
                    Włącz WebSub — pinguj hub przy publikacji (wymaga włączonej „Automatyzacji")
                </label>
            </fieldset>

            <label>URL huba
                <input type="text" name="websub_hub_url" value="<?= e(setting('websub_hub_url', 'https://pubsubhubbub.appspot.com/')) ?>"
                    placeholder="https://pubsubhubbub.appspot.com/" style="font-family:ui-monospace,monospace">
                <span class="hint">Domyślnie publiczny hub Google (darmowy). Alternatywy: Superfeedr, własny hub.</span>
            </label>

            <label>URL feedu (opcjonalnie)
                <input type="text" name="websub_feed_url" value="<?= e(setting('websub_feed_url', '')) ?>"
                    placeholder="<?= e(rtrim(siteBaseUrl(),'/')) ?>/feed.php" style="font-family:ui-monospace,monospace">
                <span class="hint">Puste = automatycznie <code><?= e(websubFeedUrl()) ?></code>. Zmień tylko jeśli masz osobny feed newsowy.</span>
            </label>

            <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem">
                <button type="submit" class="btn btn--primary">Zapisz</button>
                <button type="submit" form="form-websub-ping" class="btn">Pingnij hub teraz (test)</button>
            </div>
        </form>
        <form id="form-websub-ping" method="post" style="display:none">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="websub_ping">
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

    <?php /* ========== MONITORING (GSC URL INSPECTION) ========== */ elseif ($tab === 'monitor'): ?>
    <section class="settings-card" id="monitor">
        <h2 style="margin-top:0">Monitoring indeksacji (Google URL Inspection API)</h2>

        <details style="margin-bottom:1.25rem;background:#f8f9ff;border:1px solid #dde3ff;border-radius:6px;padding:.8rem 1rem">
            <summary style="cursor:pointer;font-weight:600;color:#2540b8">Jak to działa, limity i konfiguracja</summary>
            <p style="margin:.8rem 0 .5rem">Po pewnym czasie od publikacji CMS odpytuje Google, czy dany URL jest w indeksie, i loguje stan (pomiar <strong>time-to-index</strong>, wykrywanie problemów). Używa tego samego klucza konta serwisowego co Google API, ale z osobnym zakresem <code>webmasters.readonly</code>.</p>
            <ul style="padding-left:1.3rem;line-height:1.9">
                <li><strong>Osobna pula limitów:</strong> 2000 zapytań/dobę i 600/min na property — <em>nie</em> zżera limitu 200/dobę Indexing API.</li>
                <li>Konto serwisowe musi być dodane jako <strong>właściciel</strong> property w Search Console (to samo co przy Google API).</li>
                <li>Logika: pierwszy check dopiero po opóźnieniu (domyślnie 2h), ponawianie co kilka godzin, URL-e oznaczone <strong>PASS</strong> nie są już sprawdzane (oszczędność puli).</li>
                <li>Odczytujemy: <code>verdict</code> (PASS = w indeksie), <code>coverageState</code>, <code>lastCrawlTime</code>, oraz robots/indexing/pageFetch do diagnozy.</li>
            </ul>
        </details>

        <?php $gscReady = $googleKeyData && gscSiteUrl() !== ''; ?>
        <?php if (!$googleKeyData): ?>
            <div class="flash flash--error" style="margin-bottom:1rem">Brak klucza konta serwisowego. Wgraj go w zakładce <a href="?tab=google">Google API</a> — ten sam klucz obsługuje monitoring.</div>
        <?php endif; ?>

        <!-- Podsumowanie -->
        <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem">
            <?php
            $cards = [
                ['W indeksie (PASS)', (int)$monSummary['pass'], '#16a34a'],
                ['Oczekuje', (int)$monSummary['pending'], '#d97706'],
                ['Problem', (int)$monSummary['fail'], '#dc2626'],
                ['Monitorowane', (int)$monSummary['total'], '#2540b8'],
            ];
            foreach ($cards as $c): ?>
                <div style="flex:1;min-width:120px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:.75rem 1rem">
                    <div style="font-size:1.5rem;font-weight:700;color:<?= $c[2] ?>"><?= $c[1] ?></div>
                    <div style="font-size:.8rem;color:#6b7280"><?= e($c[0]) ?></div>
                </div>
            <?php endforeach; ?>
            <div style="flex:1;min-width:140px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:.75rem 1rem">
                <div style="font-size:1.5rem;font-weight:700;color:#111">
                    <?= $monSummary['avg_ttiminutes'] !== null ? e(_fmtTti((int)$monSummary['avg_ttiminutes'])) : '—' ?>
                </div>
                <div style="font-size:.8rem;color:#6b7280">Śr. czas do indeksacji</div>
            </div>
        </div>

        <!-- Konfiguracja -->
        <form method="post" class="settings-form" style="margin-bottom:1.5rem">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_gsc">

            <fieldset class="radio-group">
                <legend>Włączenie</legend>
                <label class="checkbox">
                    <input type="checkbox" name="gsc_enabled" value="1" <?= gscInspectionEnabled() ? 'checked' : '' ?>>
                    Włącz monitoring indeksacji
                </label>
                <label class="checkbox">
                    <input type="checkbox" name="gsc_monitor_auto" value="1" <?= gscMonitorAutoEnabled() ? 'checked' : '' ?>>
                    Automatyczne sprawdzanie przy cronie (auto-import)
                </label>
            </fieldset>

            <label>Property w Search Console (siteUrl)
                <input type="text" name="gsc_site_url" value="<?= e(gscSiteUrl()) ?>"
                    placeholder="sc-domain:<?= e(parse_url(siteBaseUrl(), PHP_URL_HOST) ?: 'przyklad.pl') ?>" style="font-family:ui-monospace,monospace">
                <span class="hint">Property domenowa: <code>sc-domain:twojadomena.pl</code> · Prefiks URL: <code><?= e(rtrim(siteBaseUrl(),'/')) ?>/</code></span>
            </label>

            <div style="display:flex;gap:1rem;flex-wrap:wrap">
                <label style="flex:1;min-width:160px">Opóźnienie 1. checku (min)
                    <input type="number" name="gsc_first_check_delay_min" value="<?= e(setting('gsc_first_check_delay_min','120')) ?>" min="0">
                </label>
                <label style="flex:1;min-width:160px">Ponawianie co (godz.)
                    <input type="number" name="gsc_recheck_hours" value="<?= e(setting('gsc_recheck_hours','12')) ?>" min="1">
                </label>
                <label style="flex:1;min-width:160px">Tura crona co (min)
                    <input type="number" name="gsc_check_interval_minutes" value="<?= e(setting('gsc_check_interval_minutes','180')) ?>" min="5">
                </label>
                <label style="flex:1;min-width:160px">URL-i na turę
                    <input type="number" name="gsc_batch_per_run" value="<?= e(setting('gsc_batch_per_run','20')) ?>" min="1">
                </label>
                <label style="flex:1;min-width:160px">Miękki limit dzienny
                    <input type="number" name="gsc_daily_quota" value="<?= e(setting('gsc_daily_quota','1800')) ?>" min="1" max="2000">
                </label>
            </div>

            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-top:.5rem">
                <button type="submit" class="btn btn--primary">Zapisz</button>
                <?php if ($googleKeyData): ?>
                    <button type="submit" form="form-gsc-test" class="btn">Testuj połączenie</button>
                    <button type="submit" form="form-gsc-check" class="btn" <?= $gscReady ? '' : 'disabled' ?>>↻ Sprawdź teraz (tura)</button>
                <?php endif; ?>
                <span class="hint" style="margin:0">Pula dziś: <strong><?= gscQuotaUsed() ?></strong>/<?= gscQuotaLimit() ?></span>
            </div>
        </form>
        <form id="form-gsc-test" method="post" style="display:none">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="gsc_test">
        </form>
        <form id="form-gsc-check" method="post" style="display:none">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="gsc_check_now">
        </form>

        <!-- Tabela stanu -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;gap:.5rem">
            <h3 style="margin:0">Stan per URL (<?= count($monRows) ?>)</h3>
            <?php if ($monRows): ?>
            <form method="post" onsubmit="return confirm('Wyczyścić dane monitoringu?')">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="clear_monitor">
                <button type="submit" class="btn">Wyczyść</button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (!$monRows): ?>
            <p class="hint">Brak danych. Monitorowane URL-e pojawią się po publikacji (gdy monitoring włączony) lub po pierwszej turze sprawdzania.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.82rem">
                <thead>
                    <tr style="background:#f3f4f6;text-align:left">
                        <th style="padding:.5rem .75rem">Artykuł / URL</th>
                        <th style="padding:.5rem .75rem;white-space:nowrap">Verdict</th>
                        <th style="padding:.5rem .75rem">Stan pokrycia</th>
                        <th style="padding:.5rem .75rem;white-space:nowrap">Ostatni crawl</th>
                        <th style="padding:.5rem .75rem;white-space:nowrap">Time-to-index</th>
                        <th style="padding:.5rem .75rem;white-space:nowrap">Checki</th>
                        <th style="padding:.5rem .75rem"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($monRows as $r):
                    $v = $r['verdict'] ?? '';
                    [$vColor, $vLabel] = $v === 'PASS' ? ['#16a34a', '✓ PASS']
                        : (($v === 'FAIL' || $v === 'PARTIAL') ? ['#dc2626', '✗ ' . $v]
                        : ['#9ca3af', $r['last_checked_at'] ? ($v ?: 'NEUTRAL') : 'nie sprawdzono']);
                    $tti = (!empty($r['published_at']) && !empty($r['indexed_at']))
                        ? _fmtTti((int)round((strtotime($r['indexed_at']) - strtotime($r['published_at'])) / 60)) : '—';
                ?>
                    <tr style="border-top:1px solid #e5e7eb">
                        <td style="padding:.4rem .75rem;max-width:320px">
                            <?php if (!empty($r['post_title'])): ?>
                                <div style="font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:320px"><?= e($r['post_title']) ?></div>
                            <?php endif; ?>
                            <a href="<?= e($r['url']) ?>" target="_blank" rel="noopener" style="color:#2540b8;word-break:break-all;font-size:.76rem"><?= e($r['url']) ?></a>
                            <?php if (!empty($r['last_error'])): ?><div style="color:#dc2626;font-size:.72rem"><?= e(mb_substr($r['last_error'],0,120)) ?></div><?php endif; ?>
                        </td>
                        <td style="padding:.4rem .75rem;white-space:nowrap;font-weight:600;color:<?= $vColor ?>"><?= e($vLabel) ?></td>
                        <td style="padding:.4rem .75rem;color:#374151;max-width:200px"><?= e($r['coverage_state'] ?? '—') ?></td>
                        <td style="padding:.4rem .75rem;white-space:nowrap;color:#6b7280"><?= e($r['last_crawl_time'] ? substr($r['last_crawl_time'],0,10) : '—') ?></td>
                        <td style="padding:.4rem .75rem;white-space:nowrap"><?= e($tti) ?></td>
                        <td style="padding:.4rem .75rem;text-align:center;color:#6b7280"><?= (int)$r['checks_count'] ?></td>
                        <td style="padding:.4rem .5rem">
                            <?php if ($gscReady): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="gsc_check_one">
                                <input type="hidden" name="url" value="<?= e($r['url']) ?>">
                                <button type="submit" class="btn" style="padding:.2rem .5rem;font-size:.75rem" title="Sprawdź teraz">↻</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
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

        <?php if (!$log): ?>
            <h2 style="margin-top:0">Historia zgłoszeń</h2>
            <p class="hint">Brak wpisów. Historia pojawi się po pierwszym zgłoszeniu URL.</p>
        <?php else: ?>

        <?php /* Baner z nieudanymi + szybki przycisk */ ?>
        <?php if ($failedUrls): ?>
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:.75rem 1rem;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
                <span style="color:#b91c1c;font-weight:600">
                    ✗ <?= count($failedUrls) ?> URL<?= count($failedUrls) > 1 ? '-i' : '' ?> z ostatnim nieudanym zgłoszeniem
                    <?php if (in_array(true, array_map(fn($r) => str_contains((string)$r['response'], '429') || str_contains((string)$r['response'], 'quota'), $failedUrls))): ?>
                        — prawdopodobnie przekroczono limit dzienny Google (200 URL/dobę)
                    <?php endif; ?>
                </span>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="resubmit_failed">
                    <button type="submit" class="btn btn--primary"
                        onclick="return confirm('Zgłosić ponownie <?= count($failedUrls) ?> nieudanych URL(i)?')"
                        <?= indexingAnyEnabled() ? '' : 'disabled title="Włącz kanał indeksowania"' ?>>
                        ↻ Zgłoś ponownie wszystkie nieudane
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <form method="post" id="history-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="resubmit_selected">

            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
                <h2 style="margin:0">Historia zgłoszeń</h2>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                    <?php if ($failedUrls): ?>
                        <button type="submit" class="btn" id="resubmit-sel-btn" disabled
                            <?= indexingAnyEnabled() ? '' : 'title="Włącz kanał indeksowania"' ?>>
                            ↻ Zgłoś zaznaczone (<span id="sel-count">0</span>)
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn" form="" onclick="submitClearLog()">Wyczyść historię</button>
                </div>
            </div>

            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.82rem">
                    <thead>
                        <tr style="background:#f3f4f6;text-align:left">
                            <?php if ($failedUrls): ?><th style="padding:.5rem .5rem;width:2rem"></th><?php endif; ?>
                            <th style="padding:.5rem .75rem;white-space:nowrap">Data</th>
                            <th style="padding:.5rem .75rem;white-space:nowrap">Kanał</th>
                            <th style="padding:.5rem .75rem;white-space:nowrap">Status</th>
                            <th style="padding:.5rem .75rem">URL</th>
                            <th style="padding:.5rem .75rem">Odpowiedź</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Zbierz URL-e nieudane (ostatni wpis = błąd) dla szybkiego sprawdzania
                        $failedUrlSet = array_flip(array_column($failedUrls, 'url'));
                        ?>
                        <?php foreach ($log as $row):
                            $isFailed = !$row['ok'] && isset($failedUrlSet[$row['url']]);
                        ?>
                            <tr style="border-top:1px solid #e5e7eb;<?= $isFailed ? 'background:#fff8f8' : '' ?>">
                                <?php if ($failedUrls): ?>
                                    <td style="padding:.4rem .5rem;text-align:center">
                                        <?php if ($isFailed): ?>
                                            <input type="checkbox" name="resubmit_urls[]"
                                                value="<?= e($row['url']) ?>"
                                                class="resubmit-check"
                                                title="Zaznacz do ponownego zgłoszenia"
                                                style="cursor:pointer">
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td style="padding:.4rem .75rem;white-space:nowrap;color:#6b7280"><?= e(substr($row['created_at'], 0, 16)) ?></td>
                                <td style="padding:.4rem .75rem;white-space:nowrap;font-weight:500"><?= e($row['method']) ?></td>
                                <td style="padding:.4rem .75rem;white-space:nowrap">
                                    <?php if ($row['ok']): ?>
                                        <span style="color:#16a34a;font-weight:600">✓ OK</span>
                                    <?php else: ?>
                                        <span style="color:#dc2626;font-weight:600" title="<?= e($row['response']) ?>">✗ Błąd</span>
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
        </form>

        <?php endif; ?>
    </section>

    <form id="form-clear-log" method="post" style="display:none">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="clear_log">
    </form>
    <script>
    function submitClearLog() {
        if (confirm('Wyczyścić całą historię zgłoszeń?')) document.getElementById('form-clear-log').submit();
    }
    (function(){
        const checks = document.querySelectorAll('.resubmit-check');
        const btn    = document.getElementById('resubmit-sel-btn');
        const cnt    = document.getElementById('sel-count');
        if (!btn || !checks.length) return;
        function refresh() {
            const n = document.querySelectorAll('.resubmit-check:checked').length;
            cnt.textContent = n;
            btn.disabled = n === 0 <?= indexingAnyEnabled() ? '' : '|| true' ?>;
        }
        checks.forEach(c => c.addEventListener('change', refresh));
    })();
    </script>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php';
