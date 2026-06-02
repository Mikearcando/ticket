<?php

declare(strict_types=1);

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
}
require __DIR__ . '/src/App.php';

$recipient = $argv[1] ?? null;
if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Gebruik: php smtp_test.php ontvanger@example.nl\n");
    exit(1);
}

$app = new TicketSysteem\App(require __DIR__ . '/config/settings.php');
try {
    $app->sendTestMail($recipient);
    echo "SMTP-testmail verzonden naar {$recipient}" . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'SMTP-test mislukt: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
