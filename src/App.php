<?php

declare(strict_types=1);

namespace TicketSysteem;

use PDO;
use Throwable;

final class App
{
    private PDO $db;
    private array $settings;
    private const STATUSES = ['nieuw', 'open', 'in_behandeling', 'wachtend_op_klant', 'opgelost', 'gesloten'];
    private const PRIORITIES = ['laag', 'normaal', 'hoog', 'kritiek'];
    private const TRANSITIONS = [
        'nieuw' => ['open', 'in_behandeling', 'gesloten'],
        'open' => ['in_behandeling', 'wachtend_op_klant', 'opgelost', 'gesloten'],
        'in_behandeling' => ['wachtend_op_klant', 'opgelost', 'gesloten'],
        'wachtend_op_klant' => ['open', 'in_behandeling', 'opgelost', 'gesloten'],
        'opgelost' => ['open', 'gesloten'],
        'gesloten' => ['open'],
    ];

    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->sendSecurityHeaders();
        $db = $settings['db'];
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']);
        $this->db = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
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
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function run(): void
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            if ($method === 'POST' && isset($_POST['_method'])) {
                $method = strtoupper((string) $_POST['_method']);
            }

            match (true) {
                $method === 'GET' && $path === '/' => $this->publicCreate(),
                $method === 'POST' && $path === '/ticket' => $this->createTicket(),
                $method === 'GET' && preg_match('#^/ticket/([a-f0-9]{64})$#', $path, $m) => $this->customerPortal($m[1]),
                $method === 'POST' && preg_match('#^/ticket/([a-f0-9]{64})/reply$#', $path, $m) => $this->customerReply($m[1]),
                $method === 'GET' && preg_match('#^/attachments/(\d+)$#', $path, $m) => $this->downloadAttachment((int) $m[1]),
                $method === 'GET' && $path === '/login' => $this->loginForm(),
                $method === 'POST' && $path === '/login' => $this->login(),
                $method === 'POST' && $path === '/logout' => $this->logout(),
                $method === 'GET' && $path === '/profile' => $this->profileForm(),
                $method === 'POST' && $path === '/profile' => $this->saveProfile(),
                $method === 'GET' && $path === '/password/forgot' => $this->forgotForm(),
                $method === 'POST' && $path === '/password/forgot' => $this->forgotPassword(),
                $method === 'GET' && preg_match('#^/password/reset/([a-f0-9]{64})$#', $path, $m) => $this->resetForm($m[1]),
                $method === 'POST' && preg_match('#^/password/reset/([a-f0-9]{64})$#', $path, $m) => $this->resetPassword($m[1]),
                $method === 'GET' && $path === '/dashboard' => $this->dashboard(),
                $method === 'GET' && $path === '/tickets' => $this->tickets(),
                $method === 'GET' && preg_match('#^/tickets/(\d+)$#', $path, $m) => $this->ticketDetail((int) $m[1]),
                $method === 'POST' && preg_match('#^/tickets/(\d+)/reply$#', $path, $m) => $this->agentReply((int) $m[1]),
                $method === 'PATCH' && preg_match('#^/tickets/(\d+)/status$#', $path, $m) => $this->changeStatus((int) $m[1]),
                $method === 'PATCH' && preg_match('#^/tickets/(\d+)/assign$#', $path, $m) => $this->assignTicket((int) $m[1]),
                $method === 'GET' && $path === '/admin/users' => $this->adminUsers(),
                $method === 'POST' && $path === '/admin/users' => $this->saveUser(),
                $method === 'GET' && preg_match('#^/admin/users/(\d+)$#', $path, $m) => $this->editUserForm((int) $m[1]),
                $method === 'POST' && preg_match('#^/admin/users/(\d+)$#', $path, $m) => $this->updateUser((int) $m[1]),
                $method === 'GET' && $path === '/admin/categories' => $this->adminCategories(),
                $method === 'POST' && $path === '/admin/categories' => $this->saveCategory(),
                $method === 'GET' && preg_match('#^/admin/categories/(\d+)$#', $path, $m) => $this->editCategoryForm((int) $m[1]),
                $method === 'POST' && preg_match('#^/admin/categories/(\d+)$#', $path, $m) => $this->updateCategory((int) $m[1]),
                $method === 'GET' && $path === '/admin/sla' => $this->adminSla(),
                $method === 'POST' && $path === '/admin/sla' => $this->saveSla(),
                $method === 'GET' && $path === '/admin/templates' => $this->adminTemplates(),
                $method === 'POST' && $path === '/admin/templates' => $this->saveTemplate(),
                $method === 'GET' && $path === '/admin/reports' => $this->adminReports(),
                $method === 'GET' && $path === '/admin/audit' => $this->adminAudit(),
                default => $this->notFound(),
            };
        } catch (Throwable $e) {
            http_response_code(500);
            error_log($e->getMessage());
            $details = ($this->settings['app_env'] ?? 'production') === 'local' ? '<p>' . $this->e($e->getMessage()) . '</p>' : '<p>Er is een technische fout opgetreden. Controleer de serverlog.</p>';
            $this->layout('Fout', '<section class="panel danger"><h1>Serverfout</h1>' . $details . '</section>');
        }
    }

    private function sendSecurityHeaders(): void
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

    public function runSlaCheck(): int
    {
        $sent = 0;
        $tickets = $this->db->query('SELECT t.*, c.name category_name, u.name agent_name FROM tickets t JOIN categories c ON c.id=t.category_id LEFT JOIN users u ON u.id=t.assigned_to WHERE t.status NOT IN ("opgelost","gesloten") AND t.sla_deadline IS NOT NULL')->fetchAll();
        foreach ($tickets as $ticket) {
            $created = strtotime((string) $ticket['created_at']);
            $deadline = strtotime((string) $ticket['sla_deadline']);
            $now = time();
            if (!$ticket['first_response_at'] && $ticket['first_response_deadline']) {
                $firstDeadline = strtotime((string) $ticket['first_response_deadline']);
                $firstTotal = max(1, $firstDeadline - $created);
                $firstElapsedRatio = ($now - $created) / $firstTotal;
                if ($now >= $firstDeadline && !$ticket['first_response_breach_sent_at']) {
                    $this->notify('sla_breach', $this->agentAndAdminEmails($ticket), $ticket);
                    $this->audit((int) $ticket['id'], null, 'Systeem', 'first_response_sla_breach', ['deadline' => $ticket['first_response_deadline']]);
                    $this->db->prepare('UPDATE tickets SET first_response_breach_sent_at = NOW() WHERE id = ?')->execute([$ticket['id']]);
                    $sent++;
                } elseif ($firstElapsedRatio >= 0.8 && !$ticket['first_response_warning_sent_at']) {
                    $this->notify('sla_warning', $this->agentAndAdminEmails($ticket), $ticket);
                    $this->audit((int) $ticket['id'], null, 'Systeem', 'first_response_sla_warning', ['deadline' => $ticket['first_response_deadline'], 'elapsed_ratio' => round($firstElapsedRatio, 2)]);
                    $this->db->prepare('UPDATE tickets SET first_response_warning_sent_at = NOW() WHERE id = ?')->execute([$ticket['id']]);
                    $sent++;
                }
            }
            $total = max(1, $deadline - $created);
            $elapsedRatio = ($now - $created) / $total;
            if ($now >= $deadline && !$ticket['sla_breach_sent_at']) {
                $this->notify('sla_breach', $this->agentAndAdminEmails($ticket), $ticket);
                $this->audit((int) $ticket['id'], null, 'Systeem', 'sla_breach', ['deadline' => $ticket['sla_deadline']]);
                $this->db->prepare('UPDATE tickets SET sla_breach_sent_at = NOW() WHERE id = ?')->execute([$ticket['id']]);
                $sent++;
                continue;
            }
            if ($elapsedRatio >= 0.8 && !$ticket['sla_warning_sent_at']) {
                $this->notify('sla_warning', $this->agentAndAdminEmails($ticket), $ticket);
                $this->audit((int) $ticket['id'], null, 'Systeem', 'sla_warning', ['deadline' => $ticket['sla_deadline'], 'elapsed_ratio' => round($elapsedRatio, 2)]);
                $this->db->prepare('UPDATE tickets SET sla_warning_sent_at = NOW() WHERE id = ?')->execute([$ticket['id']]);
                $sent++;
            }
        }
        return $sent;
    }

    public function runRetentionCleanup(): int
    {
        $days = max(365, (int) ($this->settings['data_retention_days'] ?? 365));
        $stmt = $this->db->query('SELECT id FROM tickets WHERE status = "gesloten" AND closed_at IS NOT NULL AND closed_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)');
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($ids as $id) {
            $dir = $this->settings['storage_path'] . '/' . $id;
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
            $delete = $this->db->prepare('DELETE FROM tickets WHERE id = ?');
            $delete->execute([$id]);
        }
        return count($ids);
    }

    public function sendTestMail(string $recipient): bool
    {
        [$sent, $error] = $this->deliverMail($recipient, 'Ticket Systeem SMTP-test', '<p>Deze testmail bevestigt dat SMTP is geconfigureerd.</p>');
        $this->db->prepare('INSERT INTO mail_log (event_type, recipient, subject, body_html, sent_at, error) VALUES ("smtp_test", ?, "Ticket Systeem SMTP-test", "<p>Deze testmail bevestigt dat SMTP is geconfigureerd.</p>", ' . ($sent ? 'NOW()' : 'NULL') . ', ?)')->execute([$recipient, $error]);
        if (!$sent) {
            throw new \RuntimeException((string) $error);
        }
        return true;
    }

    private function publicCreate(array $errors = []): void
    {
        $categories = $this->db->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
        $body = '<section class="hero"><div><h1>Supportticket aanmaken</h1><p>Stuur uw vraag naar de servicedesk. U ontvangt direct een link om de voortgang te volgen.</p></div><a class="button secondary" href="/login">Agent login</a></section>';
        $body .= $this->errors($errors) . '<form class="panel form-grid" method="post" action="/ticket" enctype="multipart/form-data">' . $this->csrf();
        $body .= '<label>Naam<input name="customer_name" aria-label="Naam" required maxlength="100"></label><label>E-mail<input type="email" name="customer_email" aria-label="E-mail" required maxlength="150"></label>';
        $body .= '<label>Onderwerp<input name="subject" aria-label="Onderwerp" required maxlength="255"></label><label>Categorie<select name="category_id" aria-label="Categorie" required>' . $this->options($categories, 'id', 'name') . '</select></label>';
        $body .= '<label>Prioriteit<select name="priority" aria-label="Prioriteit">' . $this->priorityOptions() . '</select></label><label>Bijlage<input type="file" name="attachment" aria-label="Bijlage" accept=".png,.jpg,.jpeg,.pdf,.zip,.log"></label>';
        $body .= '<label class="wide">Omschrijving<textarea name="description" aria-label="Omschrijving" rows="7" required></textarea></label><input class="hp" name="website" aria-label="Website" tabindex="-1" autocomplete="off">';
        $body .= '<div class="wide actions"><button class="button" type="submit">Ticket versturen</button></div></form>';
        $this->layout('Ticket aanmaken', $body, false);
    }

    private function createTicket(): void
    {
        $this->verifyCsrf();
        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            $this->redirect('/');
        }
        $required = ['customer_name', 'customer_email', 'subject', 'description', 'category_id'];
        $errors = $this->validate($required);
        if (!filter_var($_POST['customer_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Vul een geldig e-mailadres in.';
        }
        if (!$this->validCategory((int) ($_POST['category_id'] ?? 0))) {
            $errors[] = 'Kies een geldige categorie.';
        }
        $priority = in_array($_POST['priority'] ?? 'normaal', self::PRIORITIES, true) ? $_POST['priority'] : 'normaal';
        if ($errors) {
            $this->publicCreate($errors);
            return;
        }

        $this->db->beginTransaction();
        $number = $this->nextTicketNumber();
        $token = bin2hex(random_bytes(32));
        $deadline = $this->deadlineFor($priority);
        $firstResponseDeadline = $this->firstResponseDeadlineFor($priority);
        try {
            $stmt = $this->db->prepare('INSERT INTO tickets (ticket_number, subject, description, status, priority, category_id, customer_name, customer_email, customer_token, sla_deadline, first_response_deadline) VALUES (?, ?, ?, "nieuw", ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$number, trim($_POST['subject']), trim($_POST['description']), $priority, (int) $_POST['category_id'], trim($_POST['customer_name']), trim($_POST['customer_email']), $token, $deadline, $firstResponseDeadline]);
            $ticketId = (int) $this->db->lastInsertId();
            $this->storeAttachment($ticketId, null);
            $this->audit($ticketId, null, trim((string) $_POST['customer_name']), 'ticket_created', ['status' => 'nieuw']);
            $ticket = $this->ticket($ticketId);
            $this->notify('ticket_created', [$ticket['customer_email'], ...$this->adminEmails()], $ticket);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            $this->publicCreate([$e->getMessage()]);
            return;
        }
        $this->redirect('/ticket/' . $token);
    }

    private function customerPortal(string $token, array $errors = []): void
    {
        $ticket = $this->ticketByToken($token);
        $replies = $this->replies((int) $ticket['id'], false);
        $body = '<section class="hero"><div><h1>' . $this->e($ticket['ticket_number']) . '</h1><p>' . $this->e($ticket['subject']) . '</p></div>' . $this->badge($ticket['status']) . '</section>';
        $body .= $this->ticketSummary($ticket, false) . $this->timeline((int) $ticket['id'], $replies, false);
        if ($ticket['status'] !== 'gesloten') {
            $body .= $this->errors($errors) . '<form class="panel" method="post" action="/ticket/' . $this->e($token) . '/reply" enctype="multipart/form-data">' . $this->csrf();
            $body .= '<label>Reactie<textarea name="body" rows="5" required></textarea></label><label>Bijlage<input type="file" name="attachment" accept=".png,.jpg,.jpeg,.pdf,.zip,.log"></label><div class="actions"><button class="button">Reactie plaatsen</button></div></form>';
        }
        $this->layout('Klantportaal', $body, false);
    }

    private function customerReply(string $token): void
    {
        $this->verifyCsrf();
        $ticket = $this->ticketByToken($token);
        $errors = $this->validate(['body']);
        if ($errors) {
            $this->customerPortal($token, $errors);
            return;
        }
        $stmt = $this->db->prepare('INSERT INTO ticket_replies (ticket_id, author_name, body, is_internal) VALUES (?, ?, ?, 0)');
        $stmt->execute([$ticket['id'], $ticket['customer_name'], trim($_POST['body'])]);
        $replyId = (int) $this->db->lastInsertId();
        $this->storeAttachment((int) $ticket['id'], $replyId);
        $this->audit((int) $ticket['id'], null, $ticket['customer_name'], 'reply_added', ['internal' => false]);
        $this->notify('reply_from_customer', $this->agentAndAdminEmails($ticket), $ticket);
        $this->redirect('/ticket/' . $token);
    }

    private function loginForm(array $errors = []): void
    {
        $body = '<section class="auth"><form class="panel" method="post" action="/login">' . $this->csrf() . '<h1>Agent login</h1>' . $this->errors($errors);
        $body .= '<label>E-mail<input type="email" name="email" required></label><label>Wachtwoord<input type="password" name="password" required></label>';
        $body .= '<div class="actions"><button class="button">Inloggen</button><a href="/password/forgot">Wachtwoord vergeten</a></div></form></section>';
        $this->layout('Login', $body, false);
    }

    private function login(): void
    {
        $this->verifyCsrf();
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($this->isLoginThrottled($email)) {
            $this->loginForm(['Te veel mislukte pogingen. Probeer het over 15 minuten opnieuw.']);
            return;
        }
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
            $this->recordLoginAttempt($email, false);
            $this->loginForm(['Ongeldige inloggegevens.']);
            return;
        }
        $this->recordLoginAttempt($email, true);
        $_SESSION['user_id'] = (int) $user['id'];
        $this->db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
        $this->redirect('/dashboard');
    }

    private function isLoginThrottled(string $email): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip_address = ? AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
        $stmt->execute([$email, $this->clientIp()]);
        return (int) $stmt->fetchColumn() >= 5;
    }

    private function recordLoginAttempt(string $email, bool $success): void
    {
        $this->db->prepare('INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)')->execute([$email, $this->clientIp(), $success ? 1 : 0]);
        if ($success) {
            $this->db->prepare('DELETE FROM login_attempts WHERE email = ? AND ip_address = ?')->execute([$email, $this->clientIp()]);
        }
    }

    private function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    private function logout(): void
    {
        $this->verifyCsrf();
        session_destroy();
        $this->redirect('/login');
    }

    private function forgotForm(array $messages = []): void
    {
        $body = '<section class="auth"><form class="panel" method="post" action="/password/forgot">' . $this->csrf() . '<h1>Wachtwoord herstellen</h1>' . $this->errors($messages);
        $body .= '<label>E-mail<input type="email" name="email" required></label><div class="actions"><button class="button">Resetlink sturen</button></div></form></section>';
        $this->layout('Wachtwoord herstellen', $body, false);
    }

    private function profileForm(array $messages = []): void
    {
        $user = $this->requireUser();
        $checked = $user['notify_on_assignment'] ? ' checked' : '';
        $body = '<section class="hero"><div><h1>Profiel</h1><p>Naam, wachtwoord en notificatievoorkeuren.</p></div></section>';
        $body .= '<form class="panel form-grid" method="post" action="/profile">' . $this->csrf() . $this->errors($messages);
        $body .= '<label>Naam<input name="name" required value="' . $this->e($user['name']) . '"></label><label>E-mail<input type="email" value="' . $this->e($user['email']) . '" disabled></label>';
        $body .= '<label class="wide">Nieuw wachtwoord<input type="password" name="password" minlength="10" placeholder="Leeg laten om niet te wijzigen"></label>';
        $body .= '<label class="wide check"><input type="checkbox" name="notify_on_assignment" value="1"' . $checked . '> Mail mij bij toewijzing</label>';
        $body .= '<div class="wide actions"><button class="button">Profiel opslaan</button></div></form>';
        $this->layout('Profiel', $body);
    }

    private function saveProfile(): void
    {
        $user = $this->requireUser();
        $this->verifyCsrf();
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $this->profileForm(['Naam is verplicht.']);
            return;
        }
        $notify = isset($_POST['notify_on_assignment']) ? 1 : 0;
        $password = (string) ($_POST['password'] ?? '');
        if ($password !== '') {
            if (strlen($password) < 10) {
                $this->profileForm(['Gebruik minimaal 10 tekens voor een nieuw wachtwoord.']);
                return;
            }
            $this->db->prepare('UPDATE users SET name = ?, notify_on_assignment = ?, password_hash = ? WHERE id = ?')->execute([$name, $notify, password_hash($password, PASSWORD_BCRYPT), $user['id']]);
        } else {
            $this->db->prepare('UPDATE users SET name = ?, notify_on_assignment = ? WHERE id = ?')->execute([$name, $notify, $user['id']]);
        }
        $this->profileForm(['Profiel opgeslagen.']);
    }

    private function forgotPassword(): void
    {
        $this->verifyCsrf();
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
        $stmt->execute([trim((string) ($_POST['email'] ?? ''))]);
        if ($user = $stmt->fetch()) {
            $token = bin2hex(random_bytes(32));
            $this->db->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE))')->execute([$user['id'], $token]);
            $this->logMail('password_reset', $user['email'], 'Wachtwoord resetten', '<p>Reset: <a href="' . $this->settings['app_url'] . '/password/reset/' . $token . '">link</a></p>');
        }
        $this->forgotForm(['Als het adres bekend is, is een resetlink gelogd/verzonden.']);
    }

    private function resetForm(string $token, array $errors = []): void
    {
        $body = '<section class="auth"><form class="panel" method="post" action="/password/reset/' . $this->e($token) . '">' . $this->csrf() . '<h1>Nieuw wachtwoord</h1>' . $this->errors($errors);
        $body .= '<label>Wachtwoord<input type="password" name="password" required minlength="10"></label><div class="actions"><button class="button">Opslaan</button></div></form></section>';
        $this->layout('Nieuw wachtwoord', $body, false);
    }

    private function resetPassword(string $token): void
    {
        $this->verifyCsrf();
        if (strlen((string) ($_POST['password'] ?? '')) < 10) {
            $this->resetForm($token, ['Gebruik minimaal 10 tekens.']);
            return;
        }
        $stmt = $this->db->prepare('SELECT * FROM password_resets WHERE token = ? AND used_at IS NULL AND expires_at > NOW()');
        $stmt->execute([$token]);
        $reset = $stmt->fetch();
        if (!$reset) {
            $this->resetForm($token, ['Deze resetlink is verlopen of gebruikt.']);
            return;
        }
        $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash((string) $_POST['password'], PASSWORD_BCRYPT), $reset['user_id']]);
        $this->db->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$reset['id']]);
        $this->loginForm(['Wachtwoord gewijzigd. Log opnieuw in.']);
    }

    private function dashboard(): void
    {
        $user = $this->requireUser();
        $mine = $this->countWhere('assigned_to = ? AND status NOT IN ("opgelost","gesloten")', [$user['id']]);
        $new = $this->countWhere('status = "nieuw"');
        $waiting = $this->countWhere('status = "wachtend_op_klant"');
        $open = $this->countWhere('status NOT IN ("opgelost","gesloten")');
        $body = '<section class="hero"><div><h1>Dashboard</h1><p>Werkvoorraad en SLA-signalen</p></div><a class="button" href="/tickets">Tickets</a></section>';
        $body .= '<section class="kpis"><div><b>' . $mine . '</b><span>Mijn open tickets</span></div><div><b>' . $new . '</b><span>Onbehandeld</span></div><div><b>' . $waiting . '</b><span>Wachtend op klant</span></div><div><b>' . $open . '</b><span>Totaal open</span></div></section>';
        $body .= $this->ticketTable($this->filteredTickets(['assigned_to' => (string) $user['id'], 'open_only' => '1']), true);
        $this->layout('Dashboard', $body);
    }

    private function tickets(): void
    {
        $this->requireUser();
        $body = '<section class="hero"><div><h1>Ticketoverzicht</h1><p>Filter, zoek en handel tickets direct af.</p></div><a class="button" href="/dashboard">Dashboard</a></section>';
        $body .= $this->filters();
        $body .= $this->ticketTable($this->filteredTickets($_GET), true);
        $this->layout('Tickets', $body);
    }

    private function ticketDetail(int $id): void
    {
        $this->requireUser();
        $ticket = $this->ticket($id);
        $body = '<section class="hero"><div><h1>' . $this->e($ticket['ticket_number']) . '</h1><p>' . $this->e($ticket['subject']) . '</p></div>' . $this->badge($ticket['status']) . '</section>';
        $body .= $this->ticketSummary($ticket, true);
        $body .= $this->agentActions($ticket);
        $body .= $this->timeline($id, $this->replies($id, true), true);
        $body .= '<form class="panel" method="post" action="/tickets/' . $id . '/reply" enctype="multipart/form-data">' . $this->csrf() . '<h2>Reactie toevoegen</h2>';
        $body .= '<label>Bericht<textarea name="body" rows="5" required></textarea></label><label class="check"><input type="checkbox" name="is_internal" value="1"> Interne notitie</label><label>Bijlage<input type="file" name="attachment" accept=".png,.jpg,.jpeg,.pdf,.zip,.log"></label><div class="actions"><button class="button">Plaatsen</button></div></form>';
        $this->layout('Ticketdetail', $body);
    }

    private function agentReply(int $id): void
    {
        $user = $this->requireUser();
        $this->verifyCsrf();
        if (trim((string) ($_POST['body'] ?? '')) === '') {
            $this->redirect('/tickets/' . $id);
        }
        $internal = isset($_POST['is_internal']) ? 1 : 0;
        $stmt = $this->db->prepare('INSERT INTO ticket_replies (ticket_id, user_id, author_name, body, is_internal) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$id, $user['id'], $user['name'], trim($_POST['body']), $internal]);
        $replyId = (int) $this->db->lastInsertId();
        $this->storeAttachment($id, $replyId);
        $this->audit($id, (int) $user['id'], $user['name'], 'reply_added', ['internal' => (bool) $internal]);
        $ticket = $this->ticket($id);
        if (!$ticket['first_response_at'] && !$internal) {
            $this->db->prepare('UPDATE tickets SET first_response_at = NOW() WHERE id = ?')->execute([$id]);
        }
        if (!$internal) {
            $this->notify('reply_from_agent', [$ticket['customer_email']], $ticket);
        }
        $this->redirect('/tickets/' . $id);
    }

    private function changeStatus(int $id): void
    {
        $user = $this->requireUser();
        $this->verifyCsrf();
        $ticket = $this->ticket($id);
        $next = (string) ($_POST['status'] ?? '');
        if (!in_array($next, self::TRANSITIONS[$ticket['status']] ?? [], true)) {
            $this->redirect('/tickets/' . $id);
        }
        $closed = $next === 'gesloten' ? ', closed_at = NOW()' : '';
        $this->db->prepare('UPDATE tickets SET status = ?' . $closed . ' WHERE id = ?')->execute([$next, $id]);
        $this->audit($id, (int) $user['id'], $user['name'], 'status_changed', ['from' => $ticket['status'], 'to' => $next]);
        $updated = $this->ticket($id);
        $this->notify($next === 'gesloten' ? 'ticket_closed' : 'status_changed', array_unique([$updated['customer_email'], ...$this->agentAndAdminEmails($updated)]), $updated);
        $this->redirect('/tickets/' . $id);
    }

    private function assignTicket(int $id): void
    {
        $user = $this->requireUser();
        $this->verifyCsrf();
        $agent = ($_POST['assigned_to'] ?? '') === '' ? null : (int) $_POST['assigned_to'];
        $this->db->prepare('UPDATE tickets SET assigned_to = ? WHERE id = ?')->execute([$agent, $id]);
        $this->audit($id, (int) $user['id'], $user['name'], 'assigned', ['assigned_to' => $agent]);
        $ticket = $this->ticket($id);
        if ($agent) {
            $stmt = $this->db->prepare('SELECT email FROM users WHERE id = ? AND is_active = 1');
            $stmt->execute([$agent]);
            if ($email = $stmt->fetchColumn()) {
                $this->notify('ticket_assigned', [(string) $email], $ticket);
            }
        }
        $this->redirect('/tickets/' . $id);
    }

    private function downloadAttachment(int $id): void
    {
        $stmt = $this->db->prepare('SELECT a.*, t.customer_token, r.is_internal FROM attachments a JOIN tickets t ON t.id = a.ticket_id LEFT JOIN ticket_replies r ON r.id = a.reply_id WHERE a.id = ?');
        $stmt->execute([$id]);
        $attachment = $stmt->fetch();
        if (!$attachment) {
            $this->notFound();
        }

        $token = (string) ($_GET['token'] ?? '');
        if ($token !== '') {
            if (!hash_equals((string) $attachment['customer_token'], $token) || (int) ($attachment['is_internal'] ?? 0) === 1) {
                http_response_code(403);
                $this->layout('Geen toegang', '<section class="panel danger"><h1>Geen toegang</h1></section>', false);
                return;
            }
        } else {
            $this->requireUser();
        }

        if (!is_file((string) $attachment['filepath'])) {
            $this->notFound();
        }
        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Length: ' . (string) $attachment['filesize']);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string) $attachment['filename']) . '"');
        readfile((string) $attachment['filepath']);
        exit;
    }


    private function adminUsers(array $errors = []): void
    {
        $this->requireAdmin();
        $users = $this->db->query('SELECT * FROM users ORDER BY role DESC, name')->fetchAll();
        $rows = '';
        foreach ($users as $u) {
            $rows .= '<tr><td><a href="/admin/users/' . (int) $u['id'] . '">' . $this->e($u['name']) . '</a></td><td>' . $this->e($u['email']) . '</td><td>' . $this->e($u['role']) . '</td><td>' . ($u['is_active'] ? 'Actief' : 'Inactief') . '</td></tr>';
        }
        $body = '<section class="hero"><div><h1>Gebruikersbeheer</h1><p>Agents en admins beheren.</p></div></section>' . $this->errors($errors) . '<form class="panel form-grid" method="post">' . $this->csrf();
        $body .= '<label>Naam<input name="name" required></label><label>E-mail<input type="email" name="email" required></label><label>Rol<select name="role"><option>agent</option><option>admin</option></select></label><label>Wachtwoord<input type="password" name="password" required minlength="10"></label><div class="wide actions"><button class="button">Gebruiker toevoegen</button></div></form>';
        $body .= '<table class="table"><tr><th>Naam</th><th>E-mail</th><th>Rol</th><th>Status</th></tr>' . $rows . '</table>';
        $this->layout('Gebruikers', $body);
    }

    private function saveUser(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
            $this->adminUsers(['Naam, geldig e-mailadres en wachtwoord van minimaal 10 tekens zijn verplicht.']);
            return;
        }
        $role = in_array($_POST['role'] ?? 'agent', ['agent', 'admin'], true) ? $_POST['role'] : 'agent';
        try {
            $this->db->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT), $role]);
        } catch (Throwable) {
            $this->adminUsers(['Dit e-mailadres bestaat al.']);
            return;
        }
        $this->redirect('/admin/users');
    }

    private function editUserForm(int $id, array $errors = []): void
    {
        $this->requireAdmin();
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            $this->notFound();
        }
        $active = $user['is_active'] ? ' checked' : '';
        $notify = $user['notify_on_assignment'] ? ' checked' : '';
        $body = '<section class="hero"><div><h1>Gebruiker bewerken</h1><p>' . $this->e($user['email']) . '</p></div><a class="button secondary" href="/admin/users">Terug</a></section>';
        $body .= '<form class="panel form-grid" method="post" action="/admin/users/' . (int) $user['id'] . '">' . $this->csrf() . $this->errors($errors);
        $body .= '<label>Naam<input name="name" required value="' . $this->e($user['name']) . '"></label><label>E-mail<input type="email" name="email" required value="' . $this->e($user['email']) . '"></label>';
        $body .= '<label>Rol<select name="role">' . $this->simpleOptions(['agent', 'admin'], (string) $user['role']) . '</select></label><label>Nieuw wachtwoord<input type="password" name="password" minlength="10" placeholder="Leeg laten om niet te wijzigen"></label>';
        $body .= '<label class="wide check"><input type="checkbox" name="is_active" value="1"' . $active . '> Account actief</label>';
        $body .= '<label class="wide check"><input type="checkbox" name="notify_on_assignment" value="1"' . $notify . '> Mail bij toewijzing</label>';
        $body .= '<div class="wide actions"><button class="button">Wijzigingen opslaan</button></div></form>';
        $this->layout('Gebruiker bewerken', $body);
    }

    private function updateUser(int $id): void
    {
        $admin = $this->requireAdmin();
        $this->verifyCsrf();
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) {
            $this->notFound();
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = in_array($_POST['role'] ?? 'agent', ['agent', 'admin'], true) ? (string) $_POST['role'] : 'agent';
        $active = isset($_POST['is_active']) ? 1 : 0;
        $notify = isset($_POST['notify_on_assignment']) ? 1 : 0;
        $errors = [];
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Naam en geldig e-mailadres zijn verplicht.';
        }
        if ((int) $target['id'] === (int) $admin['id'] && ($active === 0 || $role !== 'admin')) {
            $errors[] = 'U kunt uw eigen actieve adminrechten niet verwijderen.';
        }
        if (($active === 0 || $role !== 'admin') && $target['role'] === 'admin' && (int) $target['is_active'] === 1) {
            $activeAdmins = $this->db->query('SELECT COUNT(*) FROM users WHERE role = "admin" AND is_active = 1')->fetchColumn();
            if ((int) $activeAdmins <= 1) {
                $errors[] = 'Er moet minimaal een actieve admin overblijven.';
            }
        }
        $password = (string) ($_POST['password'] ?? '');
        if ($password !== '' && strlen($password) < 10) {
            $errors[] = 'Gebruik minimaal 10 tekens voor een nieuw wachtwoord.';
        }
        if ($errors) {
            $this->editUserForm($id, $errors);
            return;
        }
        if ($password !== '') {
            $this->db->prepare('UPDATE users SET name = ?, email = ?, role = ?, is_active = ?, notify_on_assignment = ?, password_hash = ? WHERE id = ?')->execute([$name, $email, $role, $active, $notify, password_hash($password, PASSWORD_BCRYPT), $id]);
        } else {
            $this->db->prepare('UPDATE users SET name = ?, email = ?, role = ?, is_active = ?, notify_on_assignment = ? WHERE id = ?')->execute([$name, $email, $role, $active, $notify, $id]);
        }
        $this->redirect('/admin/users');
    }

    private function adminCategories(): void
    {
        $this->requireAdmin();
        $rows = '';
        foreach ($this->db->query('SELECT * FROM categories ORDER BY name') as $c) {
            $rows .= '<tr><td><a href="/admin/categories/' . (int) $c['id'] . '">' . $this->e($c['name']) . '</a></td><td>' . $this->e((string) $c['description']) . '</td><td>' . ($c['is_active'] ? 'Actief' : 'Inactief') . '</td></tr>';
        }
        $body = '<section class="hero"><div><h1>Categorieën</h1><p>Minimaal één actieve categorie is nodig voor ticketaanmaak.</p></div></section><form class="panel form-grid" method="post">' . $this->csrf();
        $body .= '<label>Naam<input name="name" required></label><label>Omschrijving<input name="description"></label><div class="actions"><button class="button">Toevoegen</button></div></form><table class="table"><tr><th>Naam</th><th>Omschrijving</th><th>Status</th></tr>' . $rows . '</table>';
        $this->layout('Categorieën', $body);
    }

    private function saveCategory(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();
        $this->db->prepare('INSERT INTO categories (name, description) VALUES (?, ?)')->execute([trim($_POST['name']), trim((string) ($_POST['description'] ?? ''))]);
        $this->redirect('/admin/categories');
    }

    private function editCategoryForm(int $id, array $errors = []): void
    {
        $this->requireAdmin();
        $stmt = $this->db->prepare('SELECT * FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        $category = $stmt->fetch();
        if (!$category) {
            $this->notFound();
        }
        $active = $category['is_active'] ? ' checked' : '';
        $body = '<section class="hero"><div><h1>Categorie bewerken</h1><p>' . $this->e($category['name']) . '</p></div><a class="button secondary" href="/admin/categories">Terug</a></section>';
        $body .= '<form class="panel form-grid" method="post" action="/admin/categories/' . (int) $category['id'] . '">' . $this->csrf() . $this->errors($errors);
        $body .= '<label>Naam<input name="name" required value="' . $this->e($category['name']) . '"></label><label>Omschrijving<input name="description" value="' . $this->e((string) $category['description']) . '"></label>';
        $body .= '<label class="wide check"><input type="checkbox" name="is_active" value="1"' . $active . '> Categorie actief</label>';
        $body .= '<div class="wide actions"><button class="button">Categorie opslaan</button></div></form>';
        $this->layout('Categorie bewerken', $body);
    }

    private function updateCategory(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();
        $stmt = $this->db->prepare('SELECT * FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        $category = $stmt->fetch();
        if (!$category) {
            $this->notFound();
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $active = isset($_POST['is_active']) ? 1 : 0;
        $errors = [];
        if ($name === '') {
            $errors[] = 'Naam is verplicht.';
        }
        if ($active === 0 && (int) $category['is_active'] === 1) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM categories WHERE is_active = 1 AND id <> ?');
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() < 1) {
                $errors[] = 'Er moet minimaal één actieve categorie overblijven.';
            }
        }
        if ($errors) {
            $this->editCategoryForm($id, $errors);
            return;
        }
        $this->db->prepare('UPDATE categories SET name = ?, description = ?, is_active = ? WHERE id = ?')->execute([$name, trim((string) ($_POST['description'] ?? '')), $active, $id]);
        $this->redirect('/admin/categories');
    }

    private function adminSla(): void
    {
        $this->requireAdmin();
        $rows = '';
        foreach ($this->db->query('SELECT * FROM sla_policies ORDER BY FIELD(priority,"kritiek","hoog","normaal","laag")') as $s) {
            $rows .= '<tr><td>' . $this->e($s['priority']) . '</td><td><input type="number" name="first_response_hours[' . $this->e($s['priority']) . ']" value="' . (int) $s['first_response_hours'] . '"></td><td><input type="number" name="resolution_hours[' . $this->e($s['priority']) . ']" value="' . (int) $s['resolution_hours'] . '"></td></tr>';
        }
        $body = '<section class="hero"><div><h1>SLA-instellingen</h1><p>Eerste reactie en oplostijd per prioriteit.</p></div></section><form class="panel" method="post">' . $this->csrf() . '<table class="table"><tr><th>Prioriteit</th><th>Eerste reactie uren</th><th>Oplostijd uren</th></tr>' . $rows . '</table><div class="actions"><button class="button">Opslaan</button></div></form>';
        $this->layout('SLA', $body);
    }

    private function saveSla(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();
        foreach (self::PRIORITIES as $priority) {
            $first = max(1, (int) ($_POST['first_response_hours'][$priority] ?? 1));
            $resolution = max($first, (int) ($_POST['resolution_hours'][$priority] ?? $first));
            $this->db->prepare('UPDATE sla_policies SET first_response_hours = ?, resolution_hours = ? WHERE priority = ?')->execute([$first, $resolution, $priority]);
        }
        $this->redirect('/admin/sla');
    }

    private function adminTemplates(): void
    {
        $this->requireAdmin();
        $body = '<section class="hero"><div><h1>E-mailsjablonen</h1><p>Variabelen: {{ ticket.number }}, {{ ticket.subject }}, {{ ticket.status }}, {{ ticket.link }}, {{ customer.name }}, {{ site.name }}.</p></div></section><form class="stack" method="post">' . $this->csrf();
        foreach ($this->db->query('SELECT * FROM email_templates ORDER BY event_type') as $t) {
            $body .= '<fieldset class="panel"><legend>' . $this->e($t['event_type']) . '</legend><input type="hidden" name="event_type[]" value="' . $this->e($t['event_type']) . '"><label>Onderwerp<input name="subject[' . $this->e($t['event_type']) . ']" value="' . $this->e($t['subject']) . '"></label><label>HTML<textarea name="body_html[' . $this->e($t['event_type']) . ']" rows="4">' . $this->e($t['body_html']) . '</textarea></label></fieldset>';
        }
        $body .= '<div class="actions"><button class="button">Sjablonen opslaan</button></div></form>';
        $this->layout('Sjablonen', $body);
    }

    private function saveTemplate(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();
        foreach ($_POST['event_type'] ?? [] as $event) {
            $this->db->prepare('UPDATE email_templates SET subject = ?, body_html = ? WHERE event_type = ?')->execute([$_POST['subject'][$event] ?? '', $_POST['body_html'][$event] ?? '', $event]);
        }
        $this->redirect('/admin/templates');
    }

    private function adminReports(): void
    {
        $this->requireAdmin();
        $avg = $this->db->query('SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) FROM tickets WHERE closed_at IS NOT NULL')->fetchColumn();
        $avgFirst = $this->db->query('SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) / 60 FROM tickets WHERE first_response_at IS NOT NULL')->fetchColumn();
        $slaOk = $this->db->query('SELECT ROUND(100 * SUM(sla_deadline IS NULL OR closed_at IS NULL OR closed_at <= sla_deadline) / GREATEST(COUNT(*),1), 1) FROM tickets')->fetchColumn();
        $rows = '';
        foreach ($this->db->query('SELECT COALESCE(u.name, "Niet toegewezen") agent, COUNT(t.id) total FROM tickets t LEFT JOIN users u ON u.id=t.assigned_to GROUP BY agent ORDER BY total DESC') as $r) {
            $rows .= '<tr><td>' . $this->e($r['agent']) . '</td><td>' . (int) $r['total'] . '</td></tr>';
        }
        $body = '<section class="hero"><div><h1>Rapportages</h1><p>KPI’s voor servicekwaliteit.</p></div></section>';
        $body .= '<section class="kpis"><div><b>' . $this->countWhere('status NOT IN ("opgelost","gesloten")') . '</b><span>Open tickets</span></div><div><b>' . round((float) $avgFirst, 1) . '</b><span>Gem. eerste reactietijd uren</span></div><div><b>' . round((float) $avg, 1) . '</b><span>Gem. afhandeltijd uren</span></div><div><b>' . $slaOk . '%</b><span>SLA-naleving</span></div></section>';
        $body .= '<table class="table"><tr><th>Agent</th><th>Tickets</th></tr>' . $rows . '</table>';
        $this->layout('Rapportages', $body);
    }

    private function adminAudit(): void
    {
        $this->requireAdmin();
        $where = [];
        $params = [];
        if (($this->query('ticket') ?? '') !== '') {
            $where[] = 't.ticket_number LIKE ?';
            $params[] = '%' . $this->query('ticket') . '%';
        }
        if (($this->query('action') ?? '') !== '') {
            $where[] = 'a.action = ?';
            $params[] = $this->query('action');
        }
        $sql = 'SELECT a.*, t.ticket_number FROM audit_log a JOIN tickets t ON t.id = a.ticket_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY a.created_at DESC LIMIT 300';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $rows .= '<tr><td>' . $this->e($row['created_at']) . '</td><td><a href="/tickets/' . (int) $row['ticket_id'] . '">' . $this->e($row['ticket_number']) . '</a></td><td>' . $this->e($row['actor_name']) . '</td><td>' . $this->e($row['action']) . '</td><td><code>' . $this->e((string) $row['details']) . '</code></td></tr>';
        }
        $body = '<section class="hero"><div><h1>Auditlog</h1><p>Statuswijzigingen, toewijzingen, reacties en SLA-events.</p></div></section>';
        $body .= '<form class="panel filters" method="get"><input name="ticket" placeholder="Ticketnummer" value="' . $this->e((string) $this->query('ticket')) . '"><select name="action"><option value="">Alle acties</option>' . $this->simpleOptions(['ticket_created', 'status_changed', 'assigned', 'reply_added', 'sla_warning', 'sla_breach'], (string) $this->query('action')) . '</select><button class="button">Filter</button></form>';
        $body .= '<table class="table"><tr><th>Tijd</th><th>Ticket</th><th>Actor</th><th>Actie</th><th>Details</th></tr>' . ($rows ?: '<tr><td colspan="5">Geen auditregels gevonden.</td></tr>') . '</table>';
        $this->layout('Auditlog', $body);
    }

    private function filters(): string
    {
        $agents = $this->db->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();
        $categories = $this->db->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
        return '<form class="panel filters" method="get"><input name="q" placeholder="Zoek ticketnummer of trefwoord" value="' . $this->e((string) ($_GET['q'] ?? '')) . '"><select name="status"><option value="">Alle statussen</option>' . $this->simpleOptions(self::STATUSES, (string) ($_GET['status'] ?? '')) . '</select><select name="priority"><option value="">Alle prioriteiten</option>' . $this->simpleOptions(self::PRIORITIES, (string) ($_GET['priority'] ?? '')) . '</select><select name="category_id"><option value="">Alle categorieën</option>' . $this->options($categories, 'id', 'name', (string) ($_GET['category_id'] ?? '')) . '</select><select name="assigned_to"><option value="">Alle agents</option>' . $this->options($agents, 'id', 'name', (string) ($_GET['assigned_to'] ?? '')) . '</select><input type="date" name="date_from" value="' . $this->e((string) ($_GET['date_from'] ?? '')) . '"><input type="date" name="date_to" value="' . $this->e((string) ($_GET['date_to'] ?? '')) . '"><button class="button">Filter</button></form>';
    }

    private function filteredTickets(array $input): array
    {
        $where = [];
        $params = [];
        foreach (['status', 'priority', 'category_id', 'assigned_to'] as $field) {
            if (($input[$field] ?? '') !== '') {
                $where[] = "t.{$field} = ?";
                $params[] = $input[$field];
            }
        }
        if (($input['open_only'] ?? '') === '1') {
            $where[] = 't.status NOT IN ("opgelost","gesloten")';
        }
        if (($input['date_from'] ?? '') !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input['date_from'])) {
            $where[] = 'DATE(t.created_at) >= ?';
            $params[] = $input['date_from'];
        }
        if (($input['date_to'] ?? '') !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input['date_to'])) {
            $where[] = 'DATE(t.created_at) <= ?';
            $params[] = $input['date_to'];
        }
        if (($input['q'] ?? '') !== '') {
            $where[] = '(t.ticket_number LIKE ? OR t.subject LIKE ? OR t.description LIKE ? OR t.customer_email LIKE ?)';
            $q = '%' . $input['q'] . '%';
            array_push($params, $q, $q, $q, $q);
        }
        $sql = 'SELECT t.*, c.name category_name, u.name agent_name FROM tickets t JOIN categories c ON c.id=t.category_id LEFT JOIN users u ON u.id=t.assigned_to';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY FIELD(t.priority,"kritiek","hoog","normaal","laag"), COALESCE(t.sla_deadline, t.created_at), t.created_at DESC LIMIT 200';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function ticketTable(array $tickets, bool $actions): string
    {
        $rows = '';
        foreach ($tickets as $t) {
            $rows .= '<tr><td><a href="/tickets/' . (int) $t['id'] . '">' . $this->e($t['ticket_number']) . '</a><small>' . $this->e($t['subject']) . '</small></td><td>' . $this->badge($t['status']) . '</td><td>' . $this->priority($t['priority']) . '</td><td>' . $this->e($t['category_name'] ?? '') . '</td><td>' . $this->e($t['agent_name'] ?? 'Niet toegewezen') . '</td><td>' . $this->sla($t) . '</td></tr>';
        }
        return '<table class="table"><tr><th>Ticket</th><th>Status</th><th>Prioriteit</th><th>Categorie</th><th>Agent</th><th>SLA</th></tr>' . ($rows ?: '<tr><td colspan="6">Geen tickets gevonden.</td></tr>') . '</table>';
    }

    private function agentActions(array $ticket): string
    {
        $agents = $this->db->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();
        return '<section class="panel quick"><form method="post" action="/tickets/' . (int) $ticket['id'] . '/status">' . $this->csrf() . '<input type="hidden" name="_method" value="PATCH"><label>Status<select name="status">' . $this->simpleOptions(self::TRANSITIONS[$ticket['status']] ?? [], '') . '</select></label><button class="button">Wijzig</button></form><form method="post" action="/tickets/' . (int) $ticket['id'] . '/assign">' . $this->csrf() . '<input type="hidden" name="_method" value="PATCH"><label>Agent<select name="assigned_to"><option value="">Niet toegewezen</option>' . $this->options($agents, 'id', 'name', (string) ($ticket['assigned_to'] ?? '')) . '</select></label><button class="button">Toewijzen</button></form></section>';
    }

    private function ticketSummary(array $ticket, bool $agent = false): string
    {
        $attachments = $this->attachments((int) $ticket['id'], $agent);
        $files = '';
        foreach ($attachments as $a) {
            $url = '/attachments/' . (int) $a['id'] . ($agent ? '' : '?token=' . $this->e($ticket['customer_token']));
            $files .= '<li><a href="' . $url . '">' . $this->e($a['filename']) . '</a> <small>' . round((int) $a['filesize'] / 1024, 1) . ' KB</small></li>';
        }
        return '<section class="panel detail"><div><b>Klant</b><span>' . $this->e($ticket['customer_name']) . '<br>' . $this->e($ticket['customer_email']) . '</span></div><div><b>Prioriteit</b><span>' . $this->priority($ticket['priority']) . '</span></div><div><b>Categorie</b><span>' . $this->e($ticket['category_name'] ?? '') . '</span></div><div><b>SLA</b><span>' . $this->sla($ticket) . '</span></div><div class="wide"><b>Omschrijving</b><p>' . nl2br($this->e($ticket['description'])) . '</p></div>' . ($files ? '<div class="wide"><b>Bijlagen</b><ul>' . $files . '</ul></div>' : '') . '</section>';
    }

    private function timeline(int $ticketId, array $replies, bool $showInternal): string
    {
        $events = [];
        foreach ($this->db->query('SELECT * FROM audit_log WHERE ticket_id = ' . (int) $ticketId . ' ORDER BY created_at') as $a) {
            $events[] = ['time' => $a['created_at'], 'html' => '<div class="event audit"><b>' . $this->e($a['actor_name']) . '</b><span>' . $this->e($a['action']) . '</span><small>' . $this->e($a['created_at']) . '</small></div>'];
        }
        foreach ($replies as $r) {
            if (!$showInternal && $r['is_internal']) {
                continue;
            }
            $events[] = ['time' => $r['created_at'], 'html' => '<div class="event ' . ($r['is_internal'] ? 'internal' : '') . '"><b>' . $this->e($r['author_name']) . ($r['is_internal'] ? ' · intern' : '') . '</b><p>' . nl2br($this->e($r['body'])) . '</p><small>' . $this->e($r['created_at']) . '</small></div>'];
        }
        usort($events, fn ($a, $b) => strcmp($a['time'], $b['time']));
        return '<section class="panel"><h2>Tijdlijn</h2><div class="timeline">' . implode('', array_column($events, 'html')) . '</div></section>';
    }

    private function layout(string $title, string $body, bool $private = true): void
    {
        $user = $this->currentUser();
        $nav = '<a class="skip-link" href="#main">Skip to main content</a><nav aria-label="Hoofdnavigatie"><a href="/">' . $this->e($this->settings['app_name']) . '</a><div>';
        if ($user) {
            $nav .= '<a href="/dashboard">Dashboard</a><a href="/tickets">Tickets</a><a href="/profile">Profiel</a>';
            if ($user['role'] === 'admin') {
                $nav .= '<a href="/admin/users">Gebruikers</a><a href="/admin/categories">Categorieën</a><a href="/admin/sla">SLA</a><a href="/admin/templates">Templates</a><a href="/admin/reports">Rapportages</a><a href="/admin/audit">Auditlog</a>';
            }
            $nav .= '<form method="post" action="/logout">' . $this->csrf() . '<button>Uitloggen</button></form>';
        } else {
            $nav .= '<a href="/login">Login</a>';
        }
        $nav .= '</div></nav>';
        echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $this->e($title) . '</title><link rel="stylesheet" href="/assets/css/app.css"></head><body>' . $nav . '<main id="main" tabindex="-1">' . $body . '</main><script src="/assets/js/app.js"></script></body></html>';
    }

    private function requireUser(): array
    {
        $user = $this->currentUser();
        if (!$user) {
            $this->redirect('/login');
        }
        return $user;
    }

    private function requireAdmin(): array
    {
        $user = $this->requireUser();
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            $this->layout('Geen toegang', '<section class="panel danger"><h1>Geen toegang</h1></section>');
            exit;
        }
        return $user;
    }

    private function currentUser(): ?array
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    }

    private function verifyCsrf(): void
    {
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['_csrf'] ?? ''))) {
            throw new \RuntimeException('Ongeldige CSRF-token.');
        }
    }

    private function csrf(): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->e((string) $_SESSION['csrf']) . '">';
    }

    private function validate(array $fields): array
    {
        $errors = [];
        foreach ($fields as $field) {
            if (trim((string) ($_POST[$field] ?? '')) === '') {
                $errors[] = 'Veld "' . $field . '" is verplicht.';
            }
        }
        return $errors;
    }

    private function query(string $key): ?string
    {
        $value = $_GET[$key] ?? null;
        return is_string($value) ? trim($value) : null;
    }

    private function storeAttachment(int $ticketId, ?int $replyId): void
    {
        if (!isset($_FILES['attachment']) || ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return;
        }
        if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload mislukt.');
        }
        if ((int) $_FILES['attachment']['size'] > 10 * 1024 * 1024) {
            throw new \RuntimeException('Bijlage mag maximaal 10 MB zijn.');
        }
        $allowed = ['image/png', 'image/jpeg', 'application/pdf', 'application/zip', 'text/plain', 'application/octet-stream'];
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($_FILES['attachment']['tmp_name']) ?: 'application/octet-stream';
        $ext = strtolower(pathinfo((string) $_FILES['attachment']['name'], PATHINFO_EXTENSION));
        if (!in_array($mime, $allowed, true) || !in_array($ext, ['png', 'jpg', 'jpeg', 'pdf', 'zip', 'log'], true)) {
            throw new \RuntimeException('Bestandstype niet toegestaan.');
        }
        $dir = $this->settings['storage_path'] . '/' . $ticketId;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = bin2hex(random_bytes(12)) . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $_FILES['attachment']['name']);
        $path = $dir . '/' . $name;
        if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $path)) {
            throw new \RuntimeException('Bijlage kon niet worden opgeslagen.');
        }
        $this->db->prepare('INSERT INTO attachments (ticket_id, reply_id, filename, filepath, filesize, mime_type) VALUES (?, ?, ?, ?, ?, ?)')->execute([$ticketId, $replyId, (string) $_FILES['attachment']['name'], $path, (int) $_FILES['attachment']['size'], $mime]);
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function nextTicketNumber(): string
    {
        $year = (int) date('Y');
        $maxStmt = $this->db->prepare('SELECT MAX(CAST(SUBSTRING(ticket_number, 10) AS UNSIGNED)) FROM tickets WHERE ticket_number LIKE ?');
        $maxStmt->execute(["TKT-{$year}-%"]);
        $initialNext = ((int) $maxStmt->fetchColumn()) + 1;
        $this->db->prepare('INSERT IGNORE INTO ticket_sequences (year, next_number) VALUES (?, ?)')->execute([$year, $initialNext]);
        $stmt = $this->db->prepare('SELECT next_number FROM ticket_sequences WHERE year = ? FOR UPDATE');
        $stmt->execute([$year]);
        $number = (int) $stmt->fetchColumn();
        $this->db->prepare('UPDATE ticket_sequences SET next_number = next_number + 1 WHERE year = ?')->execute([$year]);
        return sprintf('TKT-%s-%06d', $year, $number);
    }

    private function deadlineFor(string $priority): ?string
    {
        $stmt = $this->db->prepare('SELECT resolution_hours FROM sla_policies WHERE priority = ?');
        $stmt->execute([$priority]);
        $hours = (int) ($stmt->fetchColumn() ?: 48);
        return date('Y-m-d H:i:s', time() + ($hours * 3600));
    }

    private function firstResponseDeadlineFor(string $priority): ?string
    {
        $stmt = $this->db->prepare('SELECT first_response_hours FROM sla_policies WHERE priority = ?');
        $stmt->execute([$priority]);
        $hours = (int) ($stmt->fetchColumn() ?: 8);
        return date('Y-m-d H:i:s', time() + ($hours * 3600));
    }

    private function ticket(int $id): array
    {
        $stmt = $this->db->prepare('SELECT t.*, c.name category_name, u.name agent_name FROM tickets t JOIN categories c ON c.id=t.category_id LEFT JOIN users u ON u.id=t.assigned_to WHERE t.id = ?');
        $stmt->execute([$id]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            $this->notFound();
        }
        return $ticket;
    }

    private function ticketByToken(string $token): array
    {
        $stmt = $this->db->prepare('SELECT t.*, c.name category_name, u.name agent_name FROM tickets t JOIN categories c ON c.id=t.category_id LEFT JOIN users u ON u.id=t.assigned_to WHERE t.customer_token = ?');
        $stmt->execute([$token]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            $this->notFound();
        }
        return $ticket;
    }

    private function replies(int $ticketId, bool $includeInternal): array
    {
        $sql = 'SELECT * FROM ticket_replies WHERE ticket_id = ?' . ($includeInternal ? '' : ' AND is_internal = 0') . ' ORDER BY created_at';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll();
    }

    private function attachments(int $ticketId, bool $includeInternal = true): array
    {
        $sql = 'SELECT a.* FROM attachments a LEFT JOIN ticket_replies r ON r.id = a.reply_id WHERE a.ticket_id = ?';
        if (!$includeInternal) {
            $sql .= ' AND (a.reply_id IS NULL OR r.is_internal = 0)';
        }
        $sql .= ' ORDER BY a.uploaded_at';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll();
    }

    private function validCategory(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM categories WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    private function countWhere(string $where, array $params = []): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM tickets WHERE ' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function audit(int $ticketId, ?int $actorId, string $actor, string $action, array $details = []): void
    {
        $this->db->prepare('INSERT INTO audit_log (ticket_id, actor_id, actor_name, action, details) VALUES (?, ?, ?, ?, ?)')->execute([$ticketId, $actorId, $actor, $action, json_encode($details)]);
    }

    private function notify(string $event, array $recipients, array $ticket): void
    {
        foreach (array_filter(array_unique($recipients)) as $recipient) {
            $stmt = $this->db->prepare('SELECT * FROM email_templates WHERE event_type = ?');
            $stmt->execute([$event]);
            $template = $stmt->fetch() ?: ['subject' => $event . ' {{ ticket.number }}', 'body_html' => '<p>{{ ticket.number }}</p>'];
            $subject = $this->renderMail($template['subject'], $ticket);
            $body = $this->renderMail($template['body_html'], $ticket);
            $this->logMail($event, (string) $recipient, $subject, $body);
        }
    }

    private function logMail(string $event, string $recipient, string $subject, string $body): void
    {
        [$sent, $error] = $this->deliverMail($recipient, $subject, $body);
        $this->db->prepare('INSERT INTO mail_log (event_type, recipient, subject, body_html, sent_at, error) VALUES (?, ?, ?, ?, ' . ($sent ? 'NOW()' : 'NULL') . ', ?)')->execute([$event, $recipient, $subject, $body, $error]);
    }

    private function deliverMail(string $recipient, string $subject, string $body): array
    {
        $mail = $this->settings['mail'];
        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            return [false, 'PHPMailer niet geinstalleerd; mail alleen gelogd. Draai composer install.'];
        }
        if (($mail['smtp_host'] ?? '') === '') {
            return [false, 'SMTP_HOST ontbreekt; mail alleen gelogd.'];
        }

        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->CharSet = 'UTF-8';
            $mailer->isSMTP();
            $mailer->Host = (string) $mail['smtp_host'];
            $mailer->Port = (int) $mail['smtp_port'];
            if (($mail['smtp_username'] ?? '') !== '') {
                $mailer->SMTPAuth = true;
                $mailer->Username = (string) $mail['smtp_username'];
                $mailer->Password = (string) $mail['smtp_password'];
            }
            if (($mail['smtp_encryption'] ?? '') !== '') {
                $mailer->SMTPSecure = (string) $mail['smtp_encryption'];
            }
            $mailer->setFrom((string) $mail['from'], (string) $mail['from_name']);
            $mailer->addAddress($recipient);
            $mailer->isHTML(true);
            $mailer->Subject = $subject;
            $mailer->Body = $body;
            $mailer->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body)));
            $mailer->send();
            return [true, null];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    private function renderMail(string $text, array $ticket): string
    {
        $vars = [
            '{{ ticket.number }}' => $ticket['ticket_number'] ?? '',
            '{{ ticket.subject }}' => $ticket['subject'] ?? '',
            '{{ ticket.status }}' => $ticket['status'] ?? '',
            '{{ ticket.link }}' => $this->settings['app_url'] . '/ticket/' . ($ticket['customer_token'] ?? ''),
            '{{ customer.name }}' => $ticket['customer_name'] ?? '',
            '{{ agent.name }}' => $ticket['agent_name'] ?? '',
            '{{ site.name }}' => $this->settings['app_name'],
            '{{ site.url }}' => $this->settings['app_url'],
        ];
        return strtr($text, $vars);
    }

    private function adminEmails(): array
    {
        return $this->db->query('SELECT email FROM users WHERE role = "admin" AND is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
    }

    private function agentAndAdminEmails(array $ticket): array
    {
        $emails = $this->adminEmails();
        if ($ticket['assigned_to']) {
            $stmt = $this->db->prepare('SELECT email FROM users WHERE id = ? AND is_active = 1');
            $stmt->execute([$ticket['assigned_to']]);
            if ($email = $stmt->fetchColumn()) {
                $emails[] = (string) $email;
            }
        }
        return $emails;
    }

    private function options(array $rows, string $valueKey, string $labelKey, string $selected = ''): string
    {
        $out = '';
        foreach ($rows as $row) {
            $value = (string) $row[$valueKey];
            $out .= '<option value="' . $this->e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . $this->e((string) $row[$labelKey]) . '</option>';
        }
        return $out;
    }

    private function simpleOptions(array $values, string $selected): string
    {
        return implode('', array_map(fn ($v) => '<option value="' . $this->e($v) . '"' . ($v === $selected ? ' selected' : '') . '>' . $this->e($v) . '</option>', $values));
    }

    private function priorityOptions(): string
    {
        return $this->simpleOptions(self::PRIORITIES, 'normaal');
    }

    private function badge(string $status): string
    {
        return '<span class="badge ' . $this->e($status) . '">' . $this->e(str_replace('_', ' ', $status)) . '</span>';
    }

    private function priority(string $priority): string
    {
        return '<span class="priority p-' . $this->e($priority) . '">' . $this->e($priority) . '</span>';
    }

    private function sla(array $ticket): string
    {
        if (!$ticket['sla_deadline']) {
            return '<span class="sla ok">Geen SLA</span>';
        }
        $deadline = strtotime((string) $ticket['sla_deadline']);
        $created = strtotime((string) $ticket['created_at']);
        $total = max(1, $deadline - $created);
        $left = $deadline - time();
        $ratio = $left / $total;
        $class = $left < 0 ? 'breach' : ($ratio < .25 ? 'warn' : 'ok');
        return '<span class="sla ' . $class . '">' . $this->e(date('d-m H:i', $deadline)) . '</span>';
    }

    private function errors(array $errors): string
    {
        return $errors ? '<div class="notice">' . implode('<br>', array_map(fn ($e) => $this->e((string) $e), $errors)) . '</div>' : '';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }

    private function notFound(): never
    {
        http_response_code(404);
        $this->layout('Niet gevonden', '<section class="panel danger"><h1>Niet gevonden</h1></section>', false);
        exit;
    }
}
