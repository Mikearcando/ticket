<?php

declare(strict_types=1);

$settings = require __DIR__ . '/config/settings.php';
$db = $settings['db'];
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']);
$pdo = new PDO($dsn, $db['username'], $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$categories = [
    ['Hardware', 'Werkplekken, printers en randapparatuur'],
    ['Software', 'Applicaties, licenties en foutmeldingen'],
    ['Netwerk', 'Internet, wifi en verbindingen'],
    ['Overig', 'Algemene supportvragen'],
];
foreach ($categories as [$name, $description]) {
    $stmt = $pdo->prepare('INSERT IGNORE INTO categories (name, description) VALUES (?, ?)');
    $stmt->execute([$name, $description]);
}

$sla = [
    ['laag', 16, 120],
    ['normaal', 8, 48],
    ['hoog', 4, 8],
    ['kritiek', 1, 4],
];
foreach ($sla as [$priority, $first, $resolution]) {
    $stmt = $pdo->prepare('INSERT INTO sla_policies (priority, first_response_hours, resolution_hours) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE first_response_hours=VALUES(first_response_hours), resolution_hours=VALUES(resolution_hours)');
    $stmt->execute([$priority, $first, $resolution]);
}

$templates = [
    'ticket_created' => ['Ticket {{ ticket.number }} ontvangen', '<p>Beste {{ customer.name }},</p><p>Uw ticket {{ ticket.number }} is ontvangen.</p><p><a href="{{ ticket.link }}">Bekijk ticket</a></p>'],
    'ticket_assigned' => ['Ticket {{ ticket.number }} toegewezen', '<p>Ticket {{ ticket.number }} is aan u toegewezen.</p>'],
    'reply_from_agent' => ['Nieuwe reactie op {{ ticket.number }}', '<p>Er is een nieuwe reactie geplaatst op {{ ticket.number }}.</p><p><a href="{{ ticket.link }}">Bekijk ticket</a></p>'],
    'reply_from_customer' => ['Klantreactie op {{ ticket.number }}', '<p>De klant heeft gereageerd op {{ ticket.number }}.</p>'],
    'status_changed' => ['Status gewijzigd: {{ ticket.number }}', '<p>De status van {{ ticket.number }} is gewijzigd naar {{ ticket.status }}.</p>'],
    'sla_warning' => ['SLA-waarschuwing {{ ticket.number }}', '<p>Ticket {{ ticket.number }} nadert de SLA-deadline.</p>'],
    'sla_breach' => ['SLA overschreden {{ ticket.number }}', '<p>Ticket {{ ticket.number }} heeft de SLA overschreden.</p>'],
    'ticket_closed' => ['Ticket gesloten {{ ticket.number }}', '<p>Ticket {{ ticket.number }} is gesloten.</p>'],
];
foreach ($templates as $event => [$subject, $body]) {
    $stmt = $pdo->prepare('INSERT INTO email_templates (event_type, subject, body_html) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE subject=VALUES(subject), body_html=VALUES(body_html)');
    $stmt->execute([$event, $subject, $body]);
}

$adminName = env_value('DEFAULT_ADMIN_NAME', 'Admin');
$adminEmail = env_value('DEFAULT_ADMIN_EMAIL', 'admin@example.nl');
$adminPassword = env_value('DEFAULT_ADMIN_PASSWORD', 'ChangeMe123!');
$stmt = $pdo->prepare('INSERT IGNORE INTO users (name, email, password_hash, role) VALUES (?, ?, ?, "admin")');
$stmt->execute([$adminName, $adminEmail, password_hash((string) $adminPassword, PASSWORD_BCRYPT)]);

echo "Seed voltooid. Admin: {$adminEmail}" . PHP_EOL;
