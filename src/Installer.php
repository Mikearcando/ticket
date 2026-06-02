<?php

declare(strict_types=1);

namespace TicketSysteem;

use PDO;

final class Installer
{
    public static function installedPath(): string
    {
        return dirname(__DIR__) . '/.installed';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::installedPath());
    }

    public static function runCli(): void
    {
        if (self::isInstalled()) {
            echo "Installatie is al voltooid. Verwijder .installed alleen handmatig als u bewust opnieuw wilt installeren.\n";
            return;
        }
        echo "Ticket Systeem installatie-wizard\n\n";
        $values = [
            'APP_NAME' => self::ask('Applicatienaam', 'Ticket Systeem'),
            'APP_URL' => self::ask('Publieke URL', 'http://127.0.0.1:8080'),
            'APP_ENV' => self::ask('Omgeving', 'production'),
            'DB_HOST' => self::ask('Database host', '127.0.0.1'),
            'DB_PORT' => self::ask('Database poort', '3306'),
            'DB_DATABASE' => self::ask('Database naam', 'ticket_systeem'),
            'DB_USERNAME' => self::ask('Database gebruiker', 'ticket_user'),
            'DB_PASSWORD' => self::ask('Database wachtwoord', ''),
            'MAIL_FROM' => self::ask('Afzender e-mail', 'noreply@example.nl'),
            'MAIL_FROM_NAME' => self::ask('Afzender naam', 'Supportdesk'),
            'SMTP_HOST' => self::ask('SMTP host (leeg = alleen loggen)', ''),
            'SMTP_PORT' => self::ask('SMTP poort', '587'),
            'SMTP_USERNAME' => self::ask('SMTP gebruiker', ''),
            'SMTP_PASSWORD' => self::ask('SMTP wachtwoord', ''),
            'SMTP_ENCRYPTION' => self::ask('SMTP encryptie', 'tls'),
            'DEFAULT_ADMIN_NAME' => self::ask('Admin naam', 'Admin'),
            'DEFAULT_ADMIN_EMAIL' => self::ask('Admin e-mail', 'admin@example.nl'),
            'DEFAULT_ADMIN_PASSWORD' => self::ask('Admin wachtwoord', 'ChangeMe123!'),
            'DATA_RETENTION_DAYS' => self::ask('Dataretentie gesloten tickets in dagen', '365'),
        ];
        self::install($values);
        echo "\nInstallatie voltooid. Login: {$values['DEFAULT_ADMIN_EMAIL']}\n";
    }

    public static function handleWeb(): void
    {
        self::sendSecurityHeaders();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        $_SESSION['installer_csrf'] ??= bin2hex(random_bytes(32));

        if (self::isInstalled()) {
            self::render('Installatie gesloten', '<section class="panel"><h1>Installatie is al voltooid</h1><p>Verwijder `.installed` alleen handmatig als u bewust opnieuw wilt installeren.</p><a class="button" href="/login">Naar login</a></section>');
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                if (!hash_equals((string) $_SESSION['installer_csrf'], (string) ($_POST['_csrf'] ?? ''))) {
                    throw new \RuntimeException('Ongeldige CSRF-token.');
                }
                $values = self::webValues();
                self::install($values);
                self::render('Installatie voltooid', '<section class="panel"><h1>Installatie voltooid</h1><p>Het systeem is geconfigureerd en de eerste admin is aangemaakt.</p><a class="button" href="/login">Inloggen</a></section>');
            } catch (\Throwable $e) {
                self::renderForm([$e->getMessage()]);
            }
            return;
        }

        self::renderForm();
    }

    private static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    }

    public static function install(array $values): void
    {
        $values['DATA_RETENTION_DAYS'] = (string) max(365, (int) ($values['DATA_RETENTION_DAYS'] ?? 365));
        self::validate($values);
        self::checkStorage();
        self::writeEnv($values);
        $pdo = self::pdo($values);
        self::migrate($pdo);
        self::seed($pdo, $values);
        file_put_contents(self::installedPath(), 'installed_at=' . date('c') . PHP_EOL);
    }

    public static function pdo(array $values): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $values['DB_HOST'], $values['DB_PORT'], $values['DB_DATABASE']);
        return new PDO($dsn, $values['DB_USERNAME'], $values['DB_PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (filename VARCHAR(255) PRIMARY KEY, executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        foreach (glob(dirname(__DIR__) . '/migrations/*.sql') as $migration) {
            $filename = basename($migration);
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE filename = ?');
            $stmt->execute([$filename]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }
            $sql = (string) file_get_contents($migration);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                $pdo->exec($statement);
            }
            $done = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
            $done->execute([$filename]);
        }
    }

    public static function seed(PDO $pdo, array $values): void
    {
        foreach ([['Hardware', 'Werkplekken, printers en randapparatuur'], ['Software', 'Applicaties, licenties en foutmeldingen'], ['Netwerk', 'Internet, wifi en verbindingen'], ['Overig', 'Algemene supportvragen']] as [$name, $description]) {
            $stmt = $pdo->prepare('INSERT IGNORE INTO categories (name, description) VALUES (?, ?)');
            $stmt->execute([$name, $description]);
        }
        foreach ([['laag', 16, 120], ['normaal', 8, 48], ['hoog', 4, 8], ['kritiek', 1, 4]] as [$priority, $first, $resolution]) {
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
        $stmt = $pdo->prepare('INSERT IGNORE INTO users (name, email, password_hash, role) VALUES (?, ?, ?, "admin")');
        $stmt->execute([$values['DEFAULT_ADMIN_NAME'], $values['DEFAULT_ADMIN_EMAIL'], password_hash((string) $values['DEFAULT_ADMIN_PASSWORD'], PASSWORD_BCRYPT)]);
    }

    private static function ask(string $label, string $default): string
    {
        $answer = readline($label . " [{$default}]: ");
        return trim($answer) === '' ? $default : trim($answer);
    }

    private static function writeEnv(array $values): void
    {
        $lines = [];
        foreach ($values as $key => $value) {
            $escaped = str_replace('"', '\"', (string) $value);
            $lines[] = $key . '="' . $escaped . '"';
        }
        file_put_contents(dirname(__DIR__) . '/.env', implode(PHP_EOL, $lines) . PHP_EOL);
    }

    private static function webValues(): array
    {
        $keys = ['APP_NAME', 'APP_URL', 'APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'MAIL_FROM', 'MAIL_FROM_NAME', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_ENCRYPTION', 'DEFAULT_ADMIN_NAME', 'DEFAULT_ADMIN_EMAIL', 'DEFAULT_ADMIN_PASSWORD', 'DATA_RETENTION_DAYS'];
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = trim((string) ($_POST[$key] ?? ''));
        }
        if ($values['DB_HOST'] === '' || $values['DB_DATABASE'] === '' || $values['DB_USERNAME'] === '' || !filter_var($values['DEFAULT_ADMIN_EMAIL'], FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Databasegegevens en geldig admin e-mailadres zijn verplicht.');
        }
        self::validate($values);
        return $values;
    }

    private static function validate(array $values): void
    {
        if (strlen((string) ($values['DEFAULT_ADMIN_PASSWORD'] ?? '')) < 10) {
            throw new \RuntimeException('Admin wachtwoord moet minimaal 10 tekens hebben.');
        }
        if ((int) ($values['DATA_RETENTION_DAYS'] ?? 365) < 365) {
            throw new \RuntimeException('Dataretentie moet minimaal 365 dagen zijn.');
        }
    }

    private static function checkStorage(): void
    {
        $path = dirname(__DIR__) . '/storage/attachments';
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
        if (!is_writable($path)) {
            throw new \RuntimeException('storage/attachments is niet schrijfbaar.');
        }
    }

    private static function renderForm(array $errors = []): void
    {
        $defaults = [
            'APP_NAME' => 'Ticket Systeem', 'APP_URL' => 'http://127.0.0.1:8080', 'APP_ENV' => 'production',
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'ticket_systeem', 'DB_USERNAME' => 'ticket_user',
            'MAIL_FROM' => 'noreply@example.nl', 'MAIL_FROM_NAME' => 'Supportdesk', 'SMTP_PORT' => '587', 'SMTP_ENCRYPTION' => 'tls',
            'DEFAULT_ADMIN_NAME' => 'Admin', 'DEFAULT_ADMIN_EMAIL' => 'admin@example.nl', 'DATA_RETENTION_DAYS' => '365',
        ];
        $fields = '';
        foreach ($defaults as $key => $value) {
            $type = str_contains($key, 'PASSWORD') ? 'password' : 'text';
            $fields .= '<label>' . self::e($key) . '<input type="' . $type . '" name="' . self::e($key) . '" aria-label="' . self::e($key) . '" value="' . self::e((string) ($_POST[$key] ?? $value)) . '"></label>';
        }
        foreach (['DB_PASSWORD', 'SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'DEFAULT_ADMIN_PASSWORD'] as $key) {
            $type = str_contains($key, 'PASSWORD') ? 'password' : 'text';
            $fields .= '<label>' . self::e($key) . '<input type="' . $type . '" name="' . self::e($key) . '" aria-label="' . self::e($key) . '" value="' . self::e((string) ($_POST[$key] ?? '')) . '"></label>';
        }
        $notice = $errors ? '<div class="notice">' . implode('<br>', array_map([self::class, 'e'], $errors)) . '</div>' : '';
        $csrf = htmlspecialchars((string) ($_SESSION['installer_csrf'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        self::render('Installatie', '<section class="hero"><div><h1>Installatie</h1><p>Configureer database, SMTP en eerste admin.</p></div></section>' . $notice . '<form class="panel form-grid" method="post"><input type="hidden" name="_csrf" value="' . $csrf . '">' . $fields . '<div class="wide actions"><button class="button">Installeren</button></div></form>');
    }

    private static function render(string $title, string $body): void
    {
        echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e($title) . '</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><a class="skip-link" href="#main">Skip to main content</a><nav aria-label="Installatie"><a href="/install">Installatie</a></nav><main id="main" tabindex="-1">' . $body . '</main></body></html>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
