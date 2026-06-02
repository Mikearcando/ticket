<?php

declare(strict_types=1);

$settings = require __DIR__ . '/../config/settings.php';
$db = $settings['db'];
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']);
$pdo = new PDO($dsn, $db['username'], $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$target = (int) ($argv[1] ?? 1000);
$categoryId = (int) $pdo->query('SELECT id FROM categories WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
if ($categoryId < 1) {
    throw new RuntimeException('Geen actieve categorie gevonden.');
}

$existing = (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
if ($existing >= $target) {
    echo "Performance seed overgeslagen. Tickets: {$existing}" . PHP_EOL;
    exit;
}

$priorities = ['laag', 'normaal', 'hoog', 'kritiek'];
$statuses = ['nieuw', 'open', 'in_behandeling', 'wachtend_op_klant'];
$stmt = $pdo->prepare('INSERT INTO tickets (ticket_number, subject, description, status, priority, category_id, assigned_to, customer_name, customer_email, customer_token, sla_deadline, created_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), DATE_SUB(NOW(), INTERVAL ? MINUTE))');

$pdo->beginTransaction();
for ($i = $existing + 1; $i <= $target; $i++) {
    $year = date('Y');
    $number = sprintf('TKT-%s-%06d', $year, $i);
    $priority = $priorities[$i % count($priorities)];
    $status = $statuses[$i % count($statuses)];
    $deadlineHours = match ($priority) {
        'kritiek' => 4,
        'hoog' => 8,
        'normaal' => 48,
        default => 120,
    };
    $stmt->execute([
        $number,
        'Performance ticket ' . $i,
        'Automatisch aangemaakt voor performance-test.',
        $status,
        $priority,
        $categoryId,
        'Perf Klant ' . $i,
        'perf' . $i . '@example.nl',
        bin2hex(random_bytes(32)),
        $deadlineHours,
        $i,
    ]);
}
$pdo->commit();

echo "Performance tickets aanwezig: {$target}" . PHP_EOL;
