<?php

declare(strict_types=1);

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
}
require __DIR__ . '/src/Installer.php';

TicketSysteem\Installer::runCli();
