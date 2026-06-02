<?php

declare(strict_types=1);

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
}
require __DIR__ . '/src/App.php';

$app = new TicketSysteem\App(require __DIR__ . '/config/settings.php');
$count = $app->runRetentionCleanup();

echo "Retentie-cleanup voltooid. Verwijderde gesloten tickets: {$count}" . PHP_EOL;
