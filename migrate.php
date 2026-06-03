<?php

declare(strict_types=1);

$settings = require __DIR__ . '/config/settings.php';
$db = $settings['db'];
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']);
$pdo = new PDO($dsn, $db['username'], $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (filename VARCHAR(255) PRIMARY KEY, executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$migrations = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($migrations, SORT_STRING);
foreach ($migrations as $migration) {
    $filename = basename($migration);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE filename = ?');
    $stmt->execute([$filename]);
    if ((int) $stmt->fetchColumn() > 0) {
        echo 'Migratie overgeslagen: ' . $filename . PHP_EOL;
        continue;
    }

    $sql = file_get_contents($migration);
    if ($sql === false) {
        throw new RuntimeException("Kan migratie niet lezen: {$migration}");
    }
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
    $done = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
    $done->execute([$filename]);
    echo 'Migratie uitgevoerd: ' . $filename . PHP_EOL;
}
