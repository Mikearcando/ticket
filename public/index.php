<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
}
require dirname(__DIR__) . '/src/Installer.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/install') {
    TicketSysteem\Installer::handleWeb();
    exit;
}

require dirname(__DIR__) . '/src/App.php';

$app = new TicketSysteem\App(require dirname(__DIR__) . '/config/settings.php');
$app->run();
