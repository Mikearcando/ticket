<?php

declare(strict_types=1);

if (!in_array('--yes', $argv, true)) {
    fwrite(STDERR, "This command deletes local runtime/test data. Re-run with --yes to continue.\n");
    fwrite(STDERR, "It keeps the configured admin account, default categories, SLA policies and email templates.\n");
    exit(1);
}

$settings = require dirname(__DIR__) . '/config/settings.php';
$db = $settings['db'];
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']);
$pdo = new PDO($dsn, $db['username'], $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$adminEmail = env_value('DEFAULT_ADMIN_EMAIL', 'admin@example.nl') ?: 'admin@example.nl';
$adminName = env_value('DEFAULT_ADMIN_NAME', 'Admin') ?: 'Admin';
$adminPassword = env_value('DEFAULT_ADMIN_PASSWORD', '') ?: '';
$appEnv = (string) ($settings['app_env'] ?? 'production');

$pdo->beginTransaction();
try {
    foreach ([
        'webhook_logs',
        'inbound_mail_log',
        'mail_log',
        'login_attempts',
        'password_resets',
        'system_audit_log',
        'audit_log',
        'attachments',
        'ticket_time_entries',
        'ticket_replies',
        'csat_surveys',
        'tickets',
        'ticket_sequences',
        'knowledge_articles',
    ] as $table) {
        if (tableExists($pdo, $table)) {
            $pdo->exec("DELETE FROM {$table}");
        }
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$adminEmail]);
    $adminId = $stmt->fetchColumn();
    if (!$adminId) {
        if (!isProductionAdminPasswordAllowed($adminPassword, $appEnv)) {
            throw new RuntimeException('DEFAULT_ADMIN_PASSWORD must be a real temporary password of at least 10 characters when creating the admin account.');
        }
        $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, is_active) VALUES (?, ?, ?, "admin", 1)');
        $insert->execute([$adminName, $adminEmail, password_hash($adminPassword, PASSWORD_BCRYPT)]);
        $adminId = (int) $pdo->lastInsertId();
    }

    $pdo->prepare('UPDATE users SET name = ?, role = "admin", is_active = 1 WHERE id = ?')->execute([$adminName, $adminId]);
    $pdo->prepare('DELETE FROM users WHERE id <> ?')->execute([$adminId]);
    $pdo->exec('DELETE FROM categories');

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$pdo->exec('ALTER TABLE categories AUTO_INCREMENT = 1');
$categories = [
    ['Hardware', 'Werkplekken, printers en randapparatuur'],
    ['Software', 'Applicaties, licenties en foutmeldingen'],
    ['Netwerk', 'Internet, wifi en verbindingen'],
    ['Overig', 'Algemene supportvragen'],
];
$categoryStmt = $pdo->prepare('INSERT INTO categories (name, description, is_active) VALUES (?, ?, 1)');
foreach ($categories as [$name, $description]) {
    $categoryStmt->execute([$name, $description]);
}

foreach ([
    'tickets',
    'ticket_replies',
    'attachments',
    'audit_log',
    'mail_log',
    'login_attempts',
    'password_resets',
    'ticket_time_entries',
    'csat_surveys',
    'inbound_mail_log',
    'system_audit_log',
    'knowledge_articles',
] as $table) {
    resetAutoIncrement($pdo, $table);
}

clearAttachmentStorage((string) $settings['storage_path']);

echo "Production cleanup complete.\n";
echo "Kept admin: {$adminEmail}\n";
echo "Default categories reset: 4\n";
echo "Tickets and runtime logs removed.\n";

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function resetAutoIncrement(PDO $pdo, string $table): void
{
    if (!tableExists($pdo, $table)) {
        return;
    }
    $pdo->exec("ALTER TABLE {$table} AUTO_INCREMENT = 1");
}

function clearAttachmentStorage(string $path): void
{
    if ($path === '' || !is_dir($path)) {
        return;
    }
    $realPath = realpath($path);
    $expectedSuffix = str_replace('\\', '/', 'storage/attachments');
    if ($realPath === false || !str_ends_with(str_replace('\\', '/', $realPath), $expectedSuffix)) {
        throw new RuntimeException('Refusing to clear unexpected attachment path: ' . $path);
    }
    foreach (scandir($realPath) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        removePath($realPath . DIRECTORY_SEPARATOR . $entry);
    }
}

function removePath(string $path): void
{
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            removePath($path . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($path);
        return;
    }
    unlink($path);
}

function isProductionAdminPasswordAllowed(string $password, string $appEnv): bool
{
    if (strlen($password) < 10) {
        return false;
    }
    if ($appEnv !== 'production') {
        return true;
    }

    return !in_array($password, [
        'ChangeMe123!',
        'tijdelijk-sterk-wachtwoord',
        'vervang-door-sterk-tijdelijk-wachtwoord',
    ], true);
}
