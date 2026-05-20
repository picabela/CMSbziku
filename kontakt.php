<?php
require_once __DIR__ . '/includes/functions.php';

if (setting('contact_enabled', '1') !== '1') {
    http_response_code(404);
    include __DIR__ . '/includes/header.php';
    echo '<div class="empty"><h2>Formularz kontaktowy jest wyłączony.</h2></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$errors = [];
$success = false;
$values = ['name' => '', 'email' => '', 'message' => ''];

// Captcha — prosta arytmetyka, regeneruje się przy każdym GET-cie
if ($_SERVER['REQUEST_METHOD'] === 'GET' || empty($_SESSION['contact_captcha'])) {
    $a = random_int(2, 9);
    $b = random_int(2, 9);
    $_SESSION['contact_captcha'] = ['a' => $a, 'b' => $b, 'answer' => $a + $b];
}
$captcha = $_SESSION['contact_captcha'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.';
    }

    // Honeypot — ukryte pole, ludzie go nie wypełnią
    if (!empty($_POST['website'])) {
        // Cicho udajemy sukces — bot nie powinien wiedzieć
        $success = true;
    } else {
        $values['name'] = trim($_POST['name'] ?? '');
        $values['email'] = trim($_POST['email'] ?? '');
        $values['message'] = trim($_POST['message'] ?? '');
        $captchaAnswer = (int)($_POST['captcha'] ?? 0);

        // Rate limit: 1 wiadomość / 60s z tej sesji
        if (!empty($_SESSION['contact_last_send']) && time() - $_SESSION['contact_last_send'] < 60) {
            $errors[] = 'Za szybko. Poczekaj chwilę przed wysłaniem kolejnej wiadomości.';
        }

        if ($captchaAnswer !== (int)$captcha['answer']) {
            $errors[] = 'Wynik dodawania jest nieprawidłowy.';
        }
        if ($values['name'] === '' || mb_strlen($values['name']) > 100) $errors[] = 'Podaj imię (max 100 znaków).';
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Podaj prawidłowy e-mail.';
        if (mb_strlen($values['message']) < 10) $errors[] = 'Wiadomość jest za krótka.';
        if (mb_strlen($values['message']) > 5000) $errors[] = 'Wiadomość jest za długa (max 5000 znaków).';

        $to = setting('contact_email', '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adres odbiorcy nie jest skonfigurowany. Skontaktuj się przez inny kanał.';
        }

        if (!$errors) {
            $prefix = setting('contact_subject_prefix', '[The Daily Signal]');
            $subject = trim($prefix . ' Wiadomość od ' . $values['name']);
            $body = "Imię: {$values['name']}\nE-mail: {$values['email']}\nIP: " . ($_SERVER['REMOTE_ADDR'] ?? '-') . "\nData: " . date('Y-m-d H:i:s') . "\n\n--- Wiadomość ---\n{$values['message']}\n";

            $headers = [
                'From: ' . siteName() . ' <noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '>',
                'Reply-To: ' . $values['email'],
                'Content-Type: text/plain; charset=UTF-8',
                'X-Mailer: PHP/' . PHP_VERSION,
            ];

            // Bezpieczeństwo: odrzuć próby wstrzykiwania nagłówków
            foreach ([$values['name'], $values['email'], $subject] as $h) {
                if (preg_match('/[\r\n]/', $h)) {
                    $errors[] = 'Niedozwolone znaki.';
                    break;
                }
            }

            if (!$errors) {
                $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
                if ($sent) {
                    $success = true;
                    $_SESSION['contact_last_send'] = time();
                    unset($_SESSION['contact_captcha']);
                } else {
                    $errors[] = 'Wiadomość nie została wysłana. Spróbuj ponownie za chwilę.';
                }
            }
        }
    }

    // Regeneruj captchę po próbie
    if (!$success) {
        $a = random_int(2, 9); $b = random_int(2, 9);
        $_SESSION['contact_captcha'] = ['a' => $a, 'b' => $b, 'answer' => $a + $b];
        $captcha = $_SESSION['contact_captcha'];
    }
}

$pageTitle = 'Kontakt — ' . siteName();
$pageDescription = 'Skontaktuj się z redakcją ' . siteName() . '.';
$canonical = BASE_URL . '/kontakt';

include __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumbs" aria-label="Okruszki">
    <a href="<?= e(BASE_URL) ?>/">Strona główna</a>
    <span aria-hidden="true">/</span>
    <span>Kontakt</span>
</nav>

<article class="article">
    <header class="article__header">
        <h1 class="article__title">Kontakt</h1>
        <p class="article__subtitle">Masz pytanie lub uwagę? Napisz do redakcji.</p>
    </header>

    <?php if ($success): ?>
        <div class="contact-success">
            <strong>✓ Dziękujemy.</strong> Twoja wiadomość została wysłana. Odpowiemy najszybciej jak to możliwe.
        </div>
    <?php else: ?>
        <?php foreach ($errors as $err): ?>
            <p class="contact-error">⚠ <?= e($err) ?></p>
        <?php endforeach; ?>

        <form method="post" class="contact-form" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <!-- honeypot -->
            <div style="position:absolute;left:-9999px" aria-hidden="true">
                <label>Nie wypełniaj tego pola<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            <label>Imię
                <input type="text" name="name" required maxlength="100" value="<?= e($values['name']) ?>">
            </label>
            <label>E-mail
                <input type="email" name="email" required value="<?= e($values['email']) ?>">
            </label>
            <label>Wiadomość
                <textarea name="message" rows="7" required minlength="10" maxlength="5000"><?= e($values['message']) ?></textarea>
            </label>
            <label class="contact-form__captcha">
                Ile to jest <strong><?= (int)$captcha['a'] ?> + <?= (int)$captcha['b'] ?></strong>?
                <input type="number" name="captcha" required min="0" max="99" inputmode="numeric" autocomplete="off">
            </label>

            <button type="submit" class="btn btn--primary">Wyślij wiadomość</button>
        </form>
    <?php endif; ?>
</article>

<?php include __DIR__ . '/includes/footer.php';
