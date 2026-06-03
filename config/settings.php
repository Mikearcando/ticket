<?php

declare(strict_types=1);

function env_value(string $key, ?string $default = null): ?string
{
    static $loaded = false;
    if (!$loaded) {
        $path = dirname(__DIR__) . '/.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$name, $value] = explode('=', $line, 2);
                $value = trim($value);
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                $name = trim($name);
                if (!isset($_ENV[$name]) && getenv($name) === false) {
                    $_ENV[$name] = $value;
                }
            }
        }
        $loaded = true;
    }

    $value = $_ENV[$key] ?? getenv($key);
    return $value === false || $value === null || $value === '' ? $default : (string) $value;
}

return [
    'app_name' => env_value('APP_NAME', 'Ticket Systeem'),
    'app_url' => rtrim((string) env_value('APP_URL', 'http://127.0.0.1:8080'), '/'),
    'app_env' => env_value('APP_ENV', 'production'),
    'db' => [
        'host' => env_value('DB_HOST', '127.0.0.1'),
        'port' => env_value('DB_PORT', '3306'),
        'database' => env_value('DB_DATABASE', 'ticket_systeem'),
        'username' => env_value('DB_USERNAME', 'root'),
        'password' => env_value('DB_PASSWORD', ''),
    ],
    'mail' => [
        'from' => env_value('MAIL_FROM', 'noreply@example.nl'),
        'from_name' => env_value('MAIL_FROM_NAME', 'Supportdesk'),
        'smtp_host' => env_value('SMTP_HOST', ''),
        'smtp_port' => env_value('SMTP_PORT', '587'),
        'smtp_username' => env_value('SMTP_USERNAME', ''),
        'smtp_password' => env_value('SMTP_PASSWORD', ''),
        'smtp_encryption' => env_value('SMTP_ENCRYPTION', 'tls'),
    ],
    'imap' => [
        'mailbox' => env_value('IMAP_MAILBOX', ''),
        'username' => env_value('IMAP_USERNAME', ''),
        'password' => env_value('IMAP_PASSWORD', ''),
        'default_category_id' => env_value('IMAP_DEFAULT_CATEGORY_ID', ''),
    ],
    'ad' => [
        'host' => env_value('AD_HOST', ''),
        'port' => env_value('AD_PORT', '636'),
        'use_tls' => env_value('AD_USE_TLS', 'ldaps'),
        'base_dn' => env_value('AD_BASE_DN', ''),
        'bind_dn' => env_value('AD_BIND_DN', ''),
        'bind_password' => env_value('AD_BIND_PASSWORD', ''),
        'user_filter' => env_value('AD_USER_FILTER', '(&(objectClass=user)(sAMAccountName={username}))'),
        'group_viewer' => env_value('AD_GROUP_VIEWER', ''),
        'group_agent' => env_value('AD_GROUP_AGENT', ''),
        'group_manager' => env_value('AD_GROUP_MANAGER', ''),
        'group_admin' => env_value('AD_GROUP_ADMIN', ''),
        'tls_require_cert' => env_value('AD_TLS_REQUIRE_CERT', 'demand'),
        'tls_cacertfile' => env_value('AD_TLS_CACERTFILE', ''),
        'tls_cacertdir' => env_value('AD_TLS_CACERTDIR', ''),
        'network_timeout' => env_value('AD_NETWORK_TIMEOUT', '5'),
    ],
    'storage_path' => dirname(__DIR__) . '/storage/attachments',
    'data_retention_days' => max(365, (int) env_value('DATA_RETENTION_DAYS', '365')),
];
