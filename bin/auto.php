<?php
/**
 * CLI runner: php bin/auto.php [--max=3]
 * Do uruchomienia z crontab/systemd timer.
 *
 * Przykład crontab (co 30 minut):
 *   STAR/30 * * * * /usr/bin/php /var/www/daily-signal/bin/auto.php >> /var/log/daily-signal.log 2>&1
 * (zastąp STAR znakiem gwiazdki).
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Tylko CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/autoimport.php';

$opts = getopt('', ['max::']);
$max = isset($opts['max']) ? (int)$opts['max'] : null;

$result = runAutoImport($max);
echo "Auto-import zakończony.\n";
echo "Run ID:   {$result['run_id']}\n";
echo "Found:    {$result['found']}\n";
echo "Imported: {$result['imported']}\n";
echo "Skipped:  {$result['skipped']}\n";
echo "Failed:   {$result['failed']}\n";
if (!empty($result['log'])) {
    echo "\n--- log ---\n" . implode("\n", $result['log']) . "\n";
}
