<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$settings = require __DIR__ . '/config/settings.php';
$app = new TicketSysteem\App($settings);
$count = $app->runImapIntake();

echo "IMAP intake verwerkt: {$count}" . PHP_EOL;
