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
    private const ROLES = [
        'viewer' => 'Viewer',
        'agent' => 'Agent',
        'manager' => 'Manager',
        'admin' => 'Admin',
    ];
    private const ROLE_LEVELS = [
        'viewer' => 10,
        'agent' => 20,
        'manager' => 30,
        'admin' => 40,
    ];
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
                $method === 'GET' && $path === '/knowledge-base' => $this->knowledgeBase(),
                $method === 'GET' && preg_match('#^/knowledge-base/([a-z0-9-]+)$#', $path, $m) => $this->knowledgeArticle($m[1]),
                $method === 'POST' && $path === '/ticket' => $this->createTicket(),
                $method === 'GET' && preg_match('#^/ticket/([a-f0-9]{64})$#', $path, $m) => $this->customerPortal($m[1]),
                $method === 'POST' && preg_match('#^/ticket/([a-f0-9]{64})/reply$#', $path, $m) => $this->customerReply($m[1]),
                $method === 'GET' && preg_match('#^/csat/([a-f0-9]{64})$#', $path, $m) => $this->csatForm($m[1]),
                $method === 'POST' && preg_match('#^/csat/([a-f0-9]{64})$#', $path, $m) => $this->saveCsat($m[1]),
                $method === 'POST' && $path === '/theme' => $this->setTheme(),
                $method === 'GET' && preg_match('#^/attachments/(\d+)$#', $path, $m) => $this->downloadAttachment((int) $m[1]),
                $method === 'GET' && $path === '/login' => $this->loginForm(),
                $method === 'POST' && $path === '/login' => $this->login(),
                $method === 'GET' && $path === '/ad/password' => $this->adPasswordForm(),
                $method === 'POST' && $path === '/ad/password' => $this->changeAdPassword(),
                $method === 'POST' && $path === '/logout' => $this->logout(),
                $method === 'GET' && $path === '/profile' => $this->profileForm(),
                $method === 'POST' && $path === '/profile' => $this->saveProfile(),
                $method === 'GET' && $path === '/password/forgot' => $this->forgotForm(),
                $method === 'POST' && $path === '/password/forgot' => $this->forgotPassword(),
                $method === 'GET' && preg_match('#^/password/reset/([a-f0-9]{64})$#', $path, $m) => $this->resetForm($m[1]),
                $method === 'POST' && preg_match('#^/password/reset/([a-f0-9]{64})$#', $path, $m) => $this->resetPassword($m[1]),
                $method === 'GET' && $path === '/dashboard' => $this->dashboard(),
                $method === 'GET' && $path === '/tickets' => $this->tickets(),
                $method === 'GET' && $path === '/tickets/new' => $this->publicCreate(),
                $method === 'GET' && preg_match('#^/tickets/(\d+)$#', $path, $m) => $this->ticketDetail((int) $m[1]),
                $method === 'POST' && preg_match('#^/tickets/(\d+)/reply$#', $path, $m) => $this->agentReply((int) $m[1]),
                $method === 'PATCH' && preg_match('#^/tickets/(\d+)/status$#', $path, $m) => $this->changeStatus((int) $m[1]),
                $method === 'PATCH' && preg_match('#^/tickets/(\d+)/assign$#', $path, $m) => $this->assignTicket((int) $m[1]),
                $method === 'POST' && preg_match('#^/tickets/(\d+)/time$#', $path, $m) => $this->addTimeEntry((int) $m[1]),
                $method === 'POST' && $path === '/tickets/bulk' => $this->bulkTickets(),
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
                $method === 'GET' && $path === '/admin/export' => $this->exportTickets(),
                $method === 'GET' && $path === '/admin/audit' => $this->adminAudit(),
                $method === 'GET' && $path === '/admin/knowledge-base' => $this->adminKnowledge(),
                $method === 'POST' && $path === '/admin/knowledge-base' => $this->saveKnowledge(),
                $method === 'GET' && preg_match('#^/admin/knowledge-base/(\d+)$#', $path, $m) => $this->editKnowledge((int) $m[1]),
                $method === 'POST' && preg_match('#^/admin/knowledge-base/(\d+)$#', $path, $m) => $this->updateKnowledge((int) $m[1]),
                $method === 'GET' && $path === '/admin/webhooks' => $this->adminWebhooks(),
                $method === 'POST' && $path === '/admin/webhooks' => $this->saveWebhook(),
                $method === 'POST' && preg_match('#^/admin/webhooks/(\d+)/toggle$#', $path, $m) => $this->toggleWebhook((int) $m[1]),
                $method === 'GET' && $path === '/admin/config' => $this->adminConfig(),
                $method === 'POST' && $path === '/admin/config' => $this->saveConfig(),
                $method === 'POST' && $path === '/admin/ad/test' => $this->testAdConnection(),
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

    public function runImapIntake(): int
    {
        $imap = $this->settings['imap'] ?? [];
        if (!function_exists('imap_open')) {
            throw new \RuntimeException('PHP-extensie imap is niet geinstalleerd.');
        }
        if (($imap['mailbox'] ?? '') === '' || ($imap['username'] ?? '') === '') {
            throw new \RuntimeException('IMAP_MAILBOX en IMAP_USERNAME zijn verplicht.');
        }
        $box = @imap_open((string) $imap['mailbox'], (string) $imap['username'], (string) ($imap['password'] ?? ''));
        if (!$box) {
            throw new \RuntimeException('IMAP-connectie mislukt: ' . (imap_last_error() ?: 'onbekend'));
        }
        $categoryId = (int) (($imap['default_category_id'] ?? '') ?: $this->defaultCategoryId());
        $processed = 0;
        foreach (imap_search($box, 'UNSEEN') ?: [] as $msgNo) {
            $header = imap_headerinfo($box, $msgNo);
            $messageId = trim((string) ($header->message_id ?? 'imap-' . $msgNo . '-' . time()));
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM inbound_mail_log WHERE message_id = ?');
            $stmt->execute([$messageId]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }
            $subject = imap_utf8((string) ($header->subject ?? 'E-mail ticket'));
            $from = $header->from[0] ?? null;
            $email = $from ? (($from->mailbox ?? 'unknown') . '@' . ($from->host ?? 'localhost')) : 'unknown@example.local';
            $name = $from ? trim((string) (($from->personal ?? '') ?: $email)) : $email;
            $body = trim((string) imap_fetchbody($box, $msgNo, '1'));
            if ($body === '') {
                $body = trim((string) imap_body($box, $msgNo));
            }
            $this->db->beginTransaction();
            $number = $this->nextTicketNumber();
            $token = bin2hex(random_bytes(32));
            $deadline = $this->deadlineFor('normaal');
            $firstResponseDeadline = $this->firstResponseDeadlineFor('normaal');
            $insert = $this->db->prepare('INSERT INTO tickets (ticket_number, subject, description, status, priority, category_id, customer_name, customer_email, customer_token, sla_deadline, first_response_deadline) VALUES (?, ?, ?, "nieuw", "normaal", ?, ?, ?, ?, ?, ?)');
            $insert->execute([$number, mb_substr($subject, 0, 255), $body, $categoryId, mb_substr($name, 0, 100), mb_substr($email, 0, 150), $token, $deadline, $firstResponseDeadline]);
            $ticketId = (int) $this->db->lastInsertId();
            $this->db->prepare('INSERT INTO inbound_mail_log (message_id, ticket_id, subject, sender) VALUES (?, ?, ?, ?)')->execute([$messageId, $ticketId, $subject, $email]);
            $this->audit($ticketId, null, 'IMAP', 'ticket_created_from_email', ['message_id' => $messageId]);
            $this->db->commit();
            imap_setflag_full($box, (string) $msgNo, '\\Seen');
            $processed++;
        }
        imap_close($box);
        return $processed;
    }

    private function knowledgeBase(): void
    {
        $q = trim((string) $this->query('q'));
        $where = 'WHERE k.is_published = 1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (k.title LIKE ? OR k.body LIKE ?)';
            $params = ['%' . $q . '%', '%' . $q . '%'];
        }
        $stmt = $this->db->prepare('SELECT k.*, c.name category_name FROM knowledge_articles k LEFT JOIN categories c ON c.id = k.category_id ' . $where . ' ORDER BY c.name, k.title');
        $stmt->execute($params);
        $rows = '';
        foreach ($stmt->fetchAll() as $article) {
            $rows .= '<article class="panel"><h2><a href="/knowledge-base/' . $this->e($article['slug']) . '">' . $this->e($article['title']) . '</a></h2><p>' . $this->e($article['category_name'] ?? 'Algemeen') . '</p><p>' . $this->e(mb_substr(strip_tags((string) $article['body']), 0, 180)) . '</p></article>';
        }
        $body = '<section class="hero"><div><h1>Kennisbank</h1><p>Antwoorden en werkinstructies gekoppeld aan supportcategorieen.</p></div><a class="button" href="/">Ticket aanmaken</a></section>';
        $body .= '<form class="panel filters" method="get"><input name="q" placeholder="Zoek in kennisbank" value="' . $this->e($q) . '"><button class="button">Zoeken</button></form>';
        $body .= $rows ?: '<section class="panel"><p>Geen artikelen gevonden.</p></section>';
        $this->layout('Kennisbank', $body, false);
    }

    private function knowledgeArticle(string $slug): void
    {
        $stmt = $this->db->prepare('SELECT k.*, c.name category_name FROM knowledge_articles k LEFT JOIN categories c ON c.id = k.category_id WHERE k.slug = ? AND k.is_published = 1');
        $stmt->execute([$slug]);
        $article = $stmt->fetch();
        if (!$article) {
            $this->notFound();
        }
        $body = '<section class="hero"><div><h1>' . $this->e($article['title']) . '</h1><p>' . $this->e($article['category_name'] ?? 'Algemeen') . '</p></div><a class="button secondary" href="/knowledge-base">Terug</a></section>';
        $body .= '<article class="panel article-body">' . nl2br($this->e((string) $article['body'])) . '</article>';
        $this->layout((string) $article['title'], $body, false);
    }

    private function publicCreate(array $errors = []): void
    {
        $user = $this->currentUser();
        $categories = $this->db->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
        $name = (string) ($_POST['customer_name'] ?? ($user['name'] ?? ''));
        $email = (string) ($_POST['customer_email'] ?? ($user['email'] ?? ''));
        $subject = (string) ($_POST['subject'] ?? '');
        $description = (string) ($_POST['description'] ?? '');
        $categoryId = (string) ($_POST['category_id'] ?? '');
        $priority = in_array($_POST['priority'] ?? 'normaal', self::PRIORITIES, true) ? (string) ($_POST['priority'] ?? 'normaal') : 'normaal';
        $action = $user ? '<a class="button" href="#ticket-form">Nieuw ticket</a>' : '<a class="button secondary" href="/login">Agent login</a>';
        $body = '<section class="hero"><div><h1>Selfservice portaal</h1><p>Maak een ticket aan, volg de voortgang via uw ticketlink en voeg later aanvullende informatie toe.</p></div>' . $action . '</section>';
        $body .= '<section class="portal-grid"><div class="panel"><h2>Ticket aanmaken</h2><p>Beschrijf de vraag, kies een categorie en voeg eventueel een bijlage toe.</p></div><div class="panel"><h2>Ticket volgen</h2><p>Na indienen ontvangt u een persoonlijke link naar het klantportaal.</p></div><div class="panel"><h2>Ingelogd werken</h2><p>Agents en admins kunnen vanuit de navigatie direct een nieuw ticket starten.</p></div></section>';
        $body .= $this->errors($errors) . '<form class="panel form-grid" method="post" action="/ticket" enctype="multipart/form-data">' . $this->csrf();
        $body .= '<h2 id="ticket-form" class="wide">Nieuw ticket</h2>';
        $body .= '<label>Naam<input name="customer_name" aria-label="Naam" required maxlength="100" value="' . $this->e($name) . '"></label><label>E-mail<input type="email" name="customer_email" aria-label="E-mail" required maxlength="150" value="' . $this->e($email) . '"></label>';
        $body .= '<label>Onderwerp<input name="subject" aria-label="Onderwerp" required maxlength="255" value="' . $this->e($subject) . '"></label><label>Categorie<select name="category_id" aria-label="Categorie" required>' . $this->options($categories, 'id', 'name', $categoryId) . '</select></label>';
        $body .= '<label>Prioriteit<select name="priority" aria-label="Prioriteit">' . $this->simpleOptions(self::PRIORITIES, $priority) . '</select></label><label>Bijlage<input type="file" name="attachment" aria-label="Bijlage" accept=".png,.jpg,.jpeg,.pdf,.zip,.log"></label>';
        $body .= '<label class="wide">Omschrijving<textarea name="description" aria-label="Omschrijving" rows="7" required>' . $this->e($description) . '</textarea></label><input class="hp" name="website" aria-label="Website" tabindex="-1" autocomplete="off">';
        $body .= '<div class="wide actions"><button class="button" type="submit">Ticket versturen</button></div></form>';
        $this->layout('Selfservice portaal', $body, false);
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

    private function setTheme(): void
    {
        $this->verifyCsrf();
        $next = ($_COOKIE['theme'] ?? '') === 'dark' ? 'light' : 'dark';
        setcookie('theme', $next, [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $this->redirect((string) ($_SERVER['HTTP_REFERER'] ?? '/'));
    }

    private function loginForm(array $errors = []): void
    {
        $body = '<section class="auth"><form class="panel" method="post" action="/login">' . $this->csrf() . '<h1>Agent login</h1>' . $this->errors($errors);
        $body .= '<label>E-mail of domeinaccount<input name="email" required></label><label>Wachtwoord<input type="password" name="password" required></label>';
        $body .= '<div class="actions"><button class="button">Inloggen</button><a href="/password/forgot">Wachtwoord vergeten</a><a href="/ad/password">AD wachtwoord wijzigen</a></div></form></section>';
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
        $adUser = $this->attemptAdLogin($email, (string) ($_POST['password'] ?? ''));
        if ($adUser) {
            $this->recordLoginAttempt($email, true);
            $_SESSION['user_id'] = (int) $adUser['id'];
            $this->db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$adUser['id']]);
            $this->redirect('/dashboard');
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

    private function adPasswordForm(array $errors = [], string $message = ''): void
    {
        $body = '<section class="auth"><form class="panel" method="post" action="/ad/password">' . $this->csrf() . '<h1>AD wachtwoord wijzigen</h1>' . $this->errors($errors);
        if ($message !== '') {
            $body .= '<div class="notice">' . $this->e($message) . '</div>';
        }
        $body .= '<label>Domeinaccount<input name="username" required autocomplete="username"></label>';
        $body .= '<label>Huidig wachtwoord<input type="password" name="current_password" required autocomplete="current-password"></label>';
        $body .= '<label>Nieuw wachtwoord<input type="password" name="new_password" required minlength="10" autocomplete="new-password"></label>';
        $body .= '<div class="actions"><button class="button">Wijzigen</button><a href="/login">Terug naar login</a></div></form></section>';
        $this->layout('AD wachtwoord wijzigen', $body, false);
    }

    private function changeAdPassword(): void
    {
        $this->verifyCsrf();
        $username = trim((string) ($_POST['username'] ?? ''));
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        if ($username === '' || strlen($new) < 10) {
            $this->adPasswordForm(['Gebruik een domeinaccount en een nieuw wachtwoord van minimaal 10 tekens.']);
            return;
        }
        [$ok, $category] = $this->adChangePassword($username, $current, $new);
        $this->safeAudit('ad_password_change_' . ($ok ? 'success' : 'failed'), ['actor' => $username, 'result' => $category]);
        if (!$ok) {
            $this->adPasswordForm(['AD-wachtwoord wijzigen mislukt: ' . $category]);
            return;
        }
        $this->adPasswordForm([], 'Wachtwoord gewijzigd.');
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
        $user = $this->requireUser();
        $ticket = $this->ticket($id);
        $body = '<section class="hero"><div><h1>' . $this->e($ticket['ticket_number']) . '</h1><p>' . $this->e($ticket['subject']) . '</p></div>' . $this->badge($ticket['status']) . '</section>';
        $body .= $this->ticketSummary($ticket, true);
        if ($this->hasMinimumRole($user, 'agent')) {
            $body .= $this->agentActions($ticket);
        }
        $body .= $this->timeline($id, $this->replies($id, true), true);
        if ($this->hasMinimumRole($user, 'agent')) {
            $body .= $this->timeEntriesPanel($id);
            $body .= '<form class="panel" method="post" action="/tickets/' . $id . '/reply" enctype="multipart/form-data">' . $this->csrf() . '<h2>Reactie toevoegen</h2>';
            $body .= '<label>Bericht<textarea name="body" rows="5" required></textarea></label><label class="check"><input type="checkbox" name="is_internal" value="1"> Interne notitie</label><label>Bijlage<input type="file" name="attachment" accept=".png,.jpg,.jpeg,.pdf,.zip,.log"></label><div class="actions"><button class="button">Plaatsen</button></div></form>';
        }
        $this->layout('Ticketdetail', $body);
    }

    private function agentReply(int $id): void
    {
        $user = $this->requireMinimumRole('agent');
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

    private function bulkTickets(): void
    {
        $user = $this->requireMinimumRole('agent');
        $this->verifyCsrf();
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ticket_ids'] ?? []))));
        if (!$ids) {
            $this->redirect('/tickets');
        }
        $status = (string) ($_POST['bulk_status'] ?? '');
        $assignedTo = ($_POST['bulk_assigned_to'] ?? '') === '' ? null : (int) $_POST['bulk_assigned_to'];
        foreach ($ids as $id) {
            $ticket = $this->ticket($id);
            if ($status !== '' && in_array($status, self::TRANSITIONS[$ticket['status']] ?? [], true)) {
                $closed = $status === 'gesloten' ? ', closed_at = NOW()' : '';
                $this->db->prepare('UPDATE tickets SET status = ?' . $closed . ' WHERE id = ?')->execute([$status, $id]);
                $this->audit($id, (int) $user['id'], $user['name'], 'bulk_status_changed', ['from' => $ticket['status'], 'to' => $status]);
                if ($status === 'gesloten') {
                    $closedTicket = $this->ticket($id);
                    $closedTicket['csat_link'] = $this->ensureCsatSurvey($id);
                    $this->notify('ticket_closed', [$closedTicket['customer_email']], $closedTicket);
                }
            }
            if ($assignedTo !== null) {
                $this->db->prepare('UPDATE tickets SET assigned_to = ? WHERE id = ?')->execute([$assignedTo, $id]);
                $this->audit($id, (int) $user['id'], $user['name'], 'bulk_assigned', ['assigned_to' => $assignedTo]);
            }
        }
        $this->redirect('/tickets');
    }

    private function changeStatus(int $id): void
    {
        $user = $this->requireMinimumRole('agent');
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
        if ($next === 'gesloten') {
            $updated['csat_link'] = $this->ensureCsatSurvey($id);
        }
        $this->notify($next === 'gesloten' ? 'ticket_closed' : 'status_changed', array_unique([$updated['customer_email'], ...$this->agentAndAdminEmails($updated)]), $updated);
        $this->redirect('/tickets/' . $id);
    }

    private function addTimeEntry(int $id): void
    {
        $user = $this->requireMinimumRole('agent');
        $this->verifyCsrf();
        $minutes = max(1, (int) ($_POST['minutes'] ?? 0));
        $note = trim((string) ($_POST['note'] ?? ''));
        $this->db->prepare('INSERT INTO ticket_time_entries (ticket_id, user_id, minutes, note) VALUES (?, ?, ?, ?)')->execute([$id, $user['id'], $minutes, $note]);
        $this->audit($id, (int) $user['id'], $user['name'], 'time_logged', ['minutes' => $minutes]);
        $this->redirect('/tickets/' . $id);
    }

    private function csatForm(string $token, array $errors = []): void
    {
        $survey = $this->csatByToken($token);
        $ticket = $this->ticket((int) $survey['ticket_id']);
        $body = '<section class="auth"><form class="panel" method="post" action="/csat/' . $this->e($token) . '">' . $this->csrf();
        $body .= '<h1>Tevredenheid</h1><p>' . $this->e($ticket['ticket_number']) . ' - ' . $this->e($ticket['subject']) . '</p>' . $this->errors($errors);
        if ($survey['submitted_at']) {
            $body .= '<div class="notice">Bedankt, uw beoordeling is al ontvangen.</div></form></section>';
            $this->layout('CSAT', $body, false);
            return;
        }
        $body .= '<label>Score<select name="score" required>' . $this->simpleOptions(['1', '2', '3', '4', '5'], '5') . '</select></label>';
        $body .= '<label>Toelichting<textarea name="comment" rows="4"></textarea></label><div class="actions"><button class="button">Versturen</button></div></form></section>';
        $this->layout('CSAT', $body, false);
    }

    private function saveCsat(string $token): void
    {
        $this->verifyCsrf();
        $survey = $this->csatByToken($token);
        if ($survey['submitted_at']) {
            $this->csatForm($token);
            return;
        }
        $score = (int) ($_POST['score'] ?? 0);
        if ($score < 1 || $score > 5) {
            $this->csatForm($token, ['Kies een score van 1 tot en met 5.']);
            return;
        }
        $this->db->prepare('UPDATE csat_surveys SET score = ?, comment = ?, submitted_at = NOW() WHERE token = ?')->execute([$score, trim((string) ($_POST['comment'] ?? '')), $token]);
        $this->audit((int) $survey['ticket_id'], null, 'Klant', 'csat_submitted', ['score' => $score]);
        $this->csatForm($token);
    }

    private function assignTicket(int $id): void
    {
        $user = $this->requireMinimumRole('agent');
        $this->verifyCsrf();
        $agent = ($_POST['assigned_to'] ?? '') === '' ? null : (int) $_POST['assigned_to'];
        if ($agent !== null) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1 AND role IN ("agent","manager","admin")');
            $stmt->execute([$agent]);
            if ((int) $stmt->fetchColumn() === 0) {
                $agent = null;
            }
        }
        $this->db->prepare('UPDATE tickets SET assigned_to = ? WHERE id = ?')->execute([$agent, $id]);
        $this->audit($id, (int) $user['id'], $user['name'], 'assigned', ['assigned_to' => $agent]);
        $ticket = $this->ticket($id);
        if ($agent) {
            $stmt = $this->db->prepare('SELECT email FROM users WHERE id = ? AND is_active = 1 AND role IN ("agent","manager","admin")');
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
        $users = $this->db->query('SELECT * FROM users ORDER BY FIELD(role,"admin","manager","agent","viewer"), name')->fetchAll();
        $rows = '';
        foreach ($users as $u) {
            $rows .= '<tr><td><a href="/admin/users/' . (int) $u['id'] . '">' . $this->e($u['name']) . '</a></td><td>' . $this->e($u['email']) . '</td><td>' . $this->e($this->roleLabel((string) $u['role'])) . '</td><td>' . ($u['is_active'] ? 'Actief' : 'Inactief') . '</td></tr>';
        }
        $body = '<section class="hero"><div><h1>Gebruikersbeheer</h1><p>Rollen, accounts en toegang beheren.</p></div></section>' . $this->errors($errors) . '<form class="panel form-grid" method="post">' . $this->csrf();
        $body .= '<label>Naam<input name="name" required></label><label>E-mail<input type="email" name="email" required></label><label>Rol<select name="role">' . $this->roleOptions('agent') . '</select></label><label>Wachtwoord<input type="password" name="password" required minlength="10"></label><div class="wide actions"><button class="button">Gebruiker toevoegen</button></div></form>';
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
        $role = $this->validRole((string) ($_POST['role'] ?? 'agent'));
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
        $body .= '<label>Rol<select name="role">' . $this->roleOptions((string) $user['role']) . '</select></label><label>Nieuw wachtwoord<input type="password" name="password" minlength="10" placeholder="Leeg laten om niet te wijzigen"></label>';
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
        $role = $this->validRole((string) ($_POST['role'] ?? 'agent'));
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
        $this->requireMinimumRole('manager');
        $avg = $this->db->query('SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) FROM tickets WHERE closed_at IS NOT NULL')->fetchColumn();
        $avgFirst = $this->db->query('SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) / 60 FROM tickets WHERE first_response_at IS NOT NULL')->fetchColumn();
        $slaOk = $this->db->query('SELECT ROUND(100 * SUM(sla_deadline IS NULL OR closed_at IS NULL OR closed_at <= sla_deadline) / GREATEST(COUNT(*),1), 1) FROM tickets')->fetchColumn();
        $timeTotal = $this->db->query('SELECT COALESCE(SUM(minutes),0) FROM ticket_time_entries')->fetchColumn();
        $csatAvg = $this->db->query('SELECT AVG(score) FROM csat_surveys WHERE submitted_at IS NOT NULL')->fetchColumn();
        $rows = '';
        foreach ($this->db->query('SELECT COALESCE(u.name, "Niet toegewezen") agent, COUNT(t.id) total FROM tickets t LEFT JOIN users u ON u.id=t.assigned_to GROUP BY agent ORDER BY total DESC') as $r) {
            $rows .= '<tr><td>' . $this->e($r['agent']) . '</td><td>' . (int) $r['total'] . '</td></tr>';
        }
        $body = '<section class="hero"><div><h1>Rapportages</h1><p>KPI’s voor servicekwaliteit.</p></div><div class="actions"><a class="button secondary" href="/admin/export?format=csv">CSV</a><a class="button secondary" href="/admin/export?format=pdf">PDF</a></div></section>';
        $body .= '<section class="kpis"><div><b>' . $this->countWhere('status NOT IN ("opgelost","gesloten")') . '</b><span>Open tickets</span></div><div><b>' . round((float) $avgFirst, 1) . '</b><span>Gem. eerste reactietijd uren</span></div><div><b>' . round((float) $avg, 1) . '</b><span>Gem. afhandeltijd uren</span></div><div><b>' . $slaOk . '%</b><span>SLA-naleving</span></div><div><b>' . round(((int) $timeTotal) / 60, 1) . '</b><span>Gelogde uren</span></div><div><b>' . round((float) $csatAvg, 1) . '</b><span>Gem. CSAT</span></div></section>';
        $body .= '<table class="table"><tr><th>Agent</th><th>Tickets</th></tr>' . $rows . '</table>';
        $this->layout('Rapportages', $body);
    }

    private function exportTickets(): void
    {
        $this->requireMinimumRole('manager');
        $tickets = $this->filteredTickets($_GET + ['limit' => '1000']);
        $format = (string) ($this->query('format') ?? 'csv');
        if ($format === 'pdf') {
            $text = "Ticketrapport\n\n";
            foreach ($tickets as $ticket) {
                $text .= $ticket['ticket_number'] . ' | ' . $ticket['status'] . ' | ' . $ticket['priority'] . ' | ' . $ticket['subject'] . "\n";
            }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="ticketrapport.pdf"');
            echo $this->simplePdf($text);
            exit;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tickets.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ticketnummer', 'status', 'prioriteit', 'categorie', 'agent', 'klant', 'email', 'aangemaakt']);
        foreach ($tickets as $ticket) {
            fputcsv($out, [$ticket['ticket_number'], $ticket['status'], $ticket['priority'], $ticket['category_name'], $ticket['agent_name'], $ticket['customer_name'], $ticket['customer_email'], $ticket['created_at']]);
        }
        exit;
    }

    private function adminAudit(): void
    {
        $this->requireMinimumRole('manager');
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

    private function adminKnowledge(array $errors = []): void
    {
        $this->requireAdmin();
        $categories = $this->db->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
        $rows = '';
        foreach ($this->db->query('SELECT k.*, c.name category_name FROM knowledge_articles k LEFT JOIN categories c ON c.id = k.category_id ORDER BY k.updated_at DESC') as $article) {
            $rows .= '<tr><td><a href="/admin/knowledge-base/' . (int) $article['id'] . '">' . $this->e($article['title']) . '</a></td><td>' . $this->e($article['category_name'] ?? 'Algemeen') . '</td><td>' . ($article['is_published'] ? 'Gepubliceerd' : 'Concept') . '</td></tr>';
        }
        $body = '<section class="hero"><div><h1>Kennisbankbeheer</h1><p>FAQ-artikelen voor selfservice en categorieen.</p></div><a class="button secondary" href="/knowledge-base">Publiek bekijken</a></section>' . $this->errors($errors);
        $body .= '<form class="panel form-grid" method="post">' . $this->csrf() . '<label>Titel<input name="title" required></label><label>Categorie<select name="category_id"><option value="">Algemeen</option>' . $this->options($categories, 'id', 'name') . '</select></label><label class="wide">Artikel<textarea name="body" rows="8" required></textarea></label><label class="check wide"><input type="checkbox" name="is_published" value="1" checked> Publiceren</label><div class="actions wide"><button class="button">Artikel toevoegen</button></div></form>';
        $body .= '<table class="table"><tr><th>Titel</th><th>Categorie</th><th>Status</th></tr>' . ($rows ?: '<tr><td colspan="3">Geen artikelen.</td></tr>') . '</table>';
        $this->layout('Kennisbankbeheer', $body);
    }

    private function saveKnowledge(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($title === '' || $body === '') {
            $this->adminKnowledge(['Titel en artikeltekst zijn verplicht.']);
            return;
        }
        $slug = $this->uniqueSlug($title);
        $categoryId = ($_POST['category_id'] ?? '') === '' ? null : (int) $_POST['category_id'];
        $this->db->prepare('INSERT INTO knowledge_articles (category_id, title, slug, body, is_published) VALUES (?, ?, ?, ?, ?)')->execute([$categoryId, $title, $slug, $body, isset($_POST['is_published']) ? 1 : 0]);
        $this->redirect('/admin/knowledge-base');
    }

    private function editKnowledge(int $id, array $errors = []): void
    {
        $this->requireAdmin();
        $stmt = $this->db->prepare('SELECT * FROM knowledge_articles WHERE id = ?');
        $stmt->execute([$id]);
        $article = $stmt->fetch();
        if (!$article) {
            $this->notFound();
        }
        $categories = $this->db->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
        $checked = $article['is_published'] ? ' checked' : '';
        $body = '<section class="hero"><div><h1>Artikel bewerken</h1><p>' . $this->e($article['slug']) . '</p></div><a class="button secondary" href="/admin/knowledge-base">Terug</a></section>' . $this->errors($errors);
        $body .= '<form class="panel form-grid" method="post">' . $this->csrf() . '<label>Titel<input name="title" required value="' . $this->e($article['title']) . '"></label><label>Categorie<select name="category_id"><option value="">Algemeen</option>' . $this->options($categories, 'id', 'name', (string) ($article['category_id'] ?? '')) . '</select></label><label class="wide">Artikel<textarea name="body" rows="10" required>' . $this->e($article['body']) . '</textarea></label><label class="check wide"><input type="checkbox" name="is_published" value="1"' . $checked . '> Publiceren</label><div class="actions wide"><button class="button">Opslaan</button></div></form>';
        $this->layout('Artikel bewerken', $body);
    }

    private function updateKnowledge(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($title === '' || $body === '') {
            $this->editKnowledge($id, ['Titel en artikeltekst zijn verplicht.']);
            return;
        }
        $categoryId = ($_POST['category_id'] ?? '') === '' ? null : (int) $_POST['category_id'];
        $this->db->prepare('UPDATE knowledge_articles SET category_id = ?, title = ?, body = ?, is_published = ? WHERE id = ?')->execute([$categoryId, $title, $body, isset($_POST['is_published']) ? 1 : 0, $id]);
        $this->redirect('/admin/knowledge-base');
    }

    private function adminWebhooks(array $errors = []): void
    {
        $this->requireAdmin();
        $rows = '';
        foreach ($this->db->query('SELECT * FROM webhook_endpoints ORDER BY created_at DESC') as $hook) {
            $rows .= '<tr><td>' . $this->e($hook['name']) . '<small>' . $this->e($hook['url']) . '</small></td><td>' . $this->e($hook['events']) . '</td><td>' . ($hook['is_active'] ? 'Actief' : 'Uit') . '</td><td><form method="post" action="/admin/webhooks/' . (int) $hook['id'] . '/toggle">' . $this->csrf() . '<button class="button secondary">Toggle</button></form></td></tr>';
        }
        $body = '<section class="hero"><div><h1>Webhooks</h1><p>Teams/Slack of andere HTTP-endpoints bij ticket-events.</p></div></section>' . $this->errors($errors);
        $body .= '<form class="panel form-grid" method="post">' . $this->csrf() . '<label>Naam<input name="name" required></label><label>URL<input name="url" required placeholder="https://..."></label><label class="wide">Events<input name="events" value="*" placeholder="*, ticket_created, status_changed"></label><div class="actions wide"><button class="button">Webhook toevoegen</button></div></form>';
        $body .= '<table class="table"><tr><th>Endpoint</th><th>Events</th><th>Status</th><th></th></tr>' . ($rows ?: '<tr><td colspan="4">Geen webhooks.</td></tr>') . '</table>';
        $this->layout('Webhooks', $body);
    }

    private function saveWebhook(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();
        $name = trim((string) ($_POST['name'] ?? ''));
        $url = trim((string) ($_POST['url'] ?? ''));
        if ($name === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->adminWebhooks(['Naam en geldige webhook-URL zijn verplicht.']);
            return;
        }
        $this->db->prepare('INSERT INTO webhook_endpoints (name, url, events) VALUES (?, ?, ?)')->execute([$name, $url, trim((string) ($_POST['events'] ?? '*')) ?: '*']);
        $this->redirect('/admin/webhooks');
    }

    private function toggleWebhook(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();
        $this->db->prepare('UPDATE webhook_endpoints SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        $this->redirect('/admin/webhooks');
    }

    private function adminConfig(): void
    {
        $user = $this->requireMinimumRole('manager');
        $mail = $this->settings['mail'];
        $db = $this->settings['db'];
        $imap = $this->settings['imap'] ?? [];
        $ad = $this->settings['ad'] ?? [];
        $storagePath = (string) $this->settings['storage_path'];
        $rows = [
            ['Applicatienaam', (string) $this->settings['app_name']],
            ['Publieke URL', (string) $this->settings['app_url']],
            ['Omgeving', (string) $this->settings['app_env']],
            ['Database', (string) $db['database'] . ' @ ' . (string) $db['host'] . ':' . (string) $db['port']],
            ['SMTP', ((string) $mail['smtp_host'] !== '' ? (string) $mail['smtp_host'] . ':' . (string) $mail['smtp_port'] : 'Niet geconfigureerd')],
            ['IMAP intake', ((string) ($imap['mailbox'] ?? '') !== '' ? 'Geconfigureerd' : 'Niet geconfigureerd')],
            ['AD/LDAPS', ((string) ($ad['host'] ?? '') !== '' ? (string) $ad['host'] . ':' . (string) $ad['port'] : 'Niet geconfigureerd')],
            ['Afzender', (string) $mail['from_name'] . ' <' . (string) $mail['from'] . '>'],
            ['Dataretentie', (int) $this->settings['data_retention_days'] . ' dagen'],
            ['Bijlagenmap', $storagePath . (is_writable($storagePath) ? ' (schrijfbaar)' : ' (niet schrijfbaar)')],
            ['PHP-versie', PHP_VERSION],
            ['Uploadlimiet', (string) ini_get('upload_max_filesize')],
        ];
        $tableRows = '';
        foreach ($rows as [$label, $value]) {
            $tableRows .= '<tr><th>' . $this->e($label) . '</th><td>' . $this->e($value) . '</td></tr>';
        }
        $body = '<section class="hero"><div><h1>Configuratie</h1><p>Runtime-instellingen en systeemstatus zonder geheime waarden.</p></div></section>';
        if ($this->query('saved') === '1') {
            $body .= '<div class="notice">Configuratie opgeslagen. Herstart de applicatiecontainer of webserver als de runtime deze waarden al had geladen.</div>';
        }
        $body .= '<table class="table config-table">' . $tableRows . '</table>';
        if (($user['role'] ?? '') === 'admin') {
            $body .= $this->configForm();
        } else {
            $body .= '<section class="panel"><h2>Wijzigen</h2><p>Alleen admins kunnen configuratie via de web-UI aanpassen.</p></section>';
        }
        $this->layout('Configuratie', $body);
    }

    private function saveConfig(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $values = [
            'APP_NAME' => trim((string) ($_POST['APP_NAME'] ?? '')),
            'APP_URL' => rtrim(trim((string) ($_POST['APP_URL'] ?? '')), '/'),
            'APP_ENV' => trim((string) ($_POST['APP_ENV'] ?? 'production')),
            'DB_HOST' => trim((string) ($_POST['DB_HOST'] ?? '')),
            'DB_PORT' => trim((string) ($_POST['DB_PORT'] ?? '')),
            'DB_DATABASE' => trim((string) ($_POST['DB_DATABASE'] ?? '')),
            'DB_USERNAME' => trim((string) ($_POST['DB_USERNAME'] ?? '')),
            'MAIL_FROM' => trim((string) ($_POST['MAIL_FROM'] ?? '')),
            'MAIL_FROM_NAME' => trim((string) ($_POST['MAIL_FROM_NAME'] ?? '')),
            'SMTP_HOST' => trim((string) ($_POST['SMTP_HOST'] ?? '')),
            'SMTP_PORT' => trim((string) ($_POST['SMTP_PORT'] ?? '')),
            'SMTP_USERNAME' => trim((string) ($_POST['SMTP_USERNAME'] ?? '')),
            'SMTP_ENCRYPTION' => trim((string) ($_POST['SMTP_ENCRYPTION'] ?? 'tls')),
            'IMAP_MAILBOX' => trim((string) ($_POST['IMAP_MAILBOX'] ?? '')),
            'IMAP_USERNAME' => trim((string) ($_POST['IMAP_USERNAME'] ?? '')),
            'IMAP_DEFAULT_CATEGORY_ID' => trim((string) ($_POST['IMAP_DEFAULT_CATEGORY_ID'] ?? '')),
            'AD_HOST' => trim((string) ($_POST['AD_HOST'] ?? '')),
            'AD_PORT' => trim((string) ($_POST['AD_PORT'] ?? '636')),
            'AD_USE_TLS' => trim((string) ($_POST['AD_USE_TLS'] ?? 'ldaps')),
            'AD_BASE_DN' => trim((string) ($_POST['AD_BASE_DN'] ?? '')),
            'AD_BIND_DN' => trim((string) ($_POST['AD_BIND_DN'] ?? '')),
            'AD_USER_FILTER' => trim((string) ($_POST['AD_USER_FILTER'] ?? '(&(objectClass=user)(sAMAccountName={username}))')),
            'AD_GROUP_VIEWER' => trim((string) ($_POST['AD_GROUP_VIEWER'] ?? '')),
            'AD_GROUP_AGENT' => trim((string) ($_POST['AD_GROUP_AGENT'] ?? '')),
            'AD_GROUP_MANAGER' => trim((string) ($_POST['AD_GROUP_MANAGER'] ?? '')),
            'AD_GROUP_ADMIN' => trim((string) ($_POST['AD_GROUP_ADMIN'] ?? '')),
            'DATA_RETENTION_DAYS' => (string) max(365, (int) ($_POST['DATA_RETENTION_DAYS'] ?? 365)),
        ];

        $errors = [];
        if ($values['APP_NAME'] === '') {
            $errors[] = 'Applicatienaam is verplicht.';
        }
        if ($values['APP_URL'] === '' || !filter_var($values['APP_URL'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Publieke URL moet een geldige URL zijn.';
        }
        if (!in_array($values['APP_ENV'], ['local', 'staging', 'production'], true)) {
            $errors[] = 'Omgeving moet local, staging of production zijn.';
        }
        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'SMTP_PORT'] as $key) {
            if ($values[$key] === '') {
                $errors[] = $key . ' is verplicht.';
            }
        }
        foreach (['DB_PORT', 'SMTP_PORT', 'AD_PORT'] as $key) {
            if (!ctype_digit($values[$key]) || (int) $values[$key] < 1 || (int) $values[$key] > 65535) {
                $errors[] = $key . ' moet een poortnummer tussen 1 en 65535 zijn.';
            }
        }
        if ($values['MAIL_FROM'] === '' || !filter_var($values['MAIL_FROM'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'MAIL_FROM moet een geldig e-mailadres zijn.';
        }
        if (!in_array($values['SMTP_ENCRYPTION'], ['', 'tls', 'ssl'], true)) {
            $errors[] = 'SMTP_ENCRYPTION moet leeg, tls of ssl zijn.';
        }
        if (!in_array($values['AD_USE_TLS'], ['ldaps', 'starttls'], true)) {
            $errors[] = 'AD_USE_TLS moet ldaps of starttls zijn.';
        }
        if ($errors) {
            $this->adminConfigWithErrors($errors);
            return;
        }

        $env = $this->readEnvFile();
        foreach ($values as $key => $value) {
            $env[$key] = $value;
        }
        foreach (['DB_PASSWORD', 'SMTP_PASSWORD', 'IMAP_PASSWORD', 'AD_BIND_PASSWORD'] as $secretKey) {
            $posted = (string) ($_POST[$secretKey] ?? '');
            if (isset($_POST['clear_' . $secretKey])) {
                $env[$secretKey] = '';
            } elseif ($posted !== '') {
                $env[$secretKey] = $posted;
            }
        }
        foreach (['THEME_BRAND', 'THEME_ACCENT', 'THEME_DARK'] as $settingKey) {
            $this->setAppSetting(strtolower($settingKey), trim((string) ($_POST[$settingKey] ?? '')));
        }

        $this->writeEnvFile($env);
        $this->redirect('/admin/config?saved=1');
    }

    private function adminConfigWithErrors(array $errors): void
    {
        $this->requireAdmin();
        $body = '<section class="hero"><div><h1>Configuratie</h1><p>Controleer de invoer en probeer opnieuw.</p></div></section>';
        $body .= $this->errors($errors);
        $body .= $this->configForm($_POST);
        $this->layout('Configuratie', $body);
    }

    private function configForm(array $source = []): string
    {
        $db = $this->settings['db'];
        $mail = $this->settings['mail'];
        $imap = $this->settings['imap'] ?? [];
        $ad = $this->settings['ad'] ?? [];
        $value = fn (string $key, string $fallback): string => $this->e((string) ($source[$key] ?? $fallback));
        $env = $value('APP_ENV', (string) $this->settings['app_env']);
        $enc = $value('SMTP_ENCRYPTION', (string) $mail['smtp_encryption']);
        $envOptions = $this->simpleOptions(['local', 'staging', 'production'], $env);
        $encOptions = $this->simpleOptions(['', 'tls', 'ssl'], $enc);

        $body = '<form class="panel form-grid" method="post" action="/admin/config">' . $this->csrf();
        $body .= '<h2 class="wide">Configuratie wijzigen</h2>';
        $body .= '<p class="wide">Waarden worden opgeslagen in `.env`. Laat wachtwoordvelden leeg om de huidige secret te behouden.</p>';
        $body .= '<label>Applicatienaam<input name="APP_NAME" required value="' . $value('APP_NAME', (string) $this->settings['app_name']) . '"></label>';
        $body .= '<label>Publieke URL<input name="APP_URL" required value="' . $value('APP_URL', (string) $this->settings['app_url']) . '"></label>';
        $body .= '<label>Omgeving<select name="APP_ENV">' . $envOptions . '</select></label>';
        $body .= '<label>Dataretentie dagen<input type="number" min="365" name="DATA_RETENTION_DAYS" value="' . $value('DATA_RETENTION_DAYS', (string) $this->settings['data_retention_days']) . '"></label>';

        $body .= '<h3 class="wide">Database</h3>';
        $body .= '<label>Host<input name="DB_HOST" required value="' . $value('DB_HOST', (string) $db['host']) . '"></label>';
        $body .= '<label>Poort<input name="DB_PORT" required inputmode="numeric" value="' . $value('DB_PORT', (string) $db['port']) . '"></label>';
        $body .= '<label>Database<input name="DB_DATABASE" required value="' . $value('DB_DATABASE', (string) $db['database']) . '"></label>';
        $body .= '<label>Gebruiker<input name="DB_USERNAME" required value="' . $value('DB_USERNAME', (string) $db['username']) . '"></label>';
        $body .= '<label>Wachtwoord<input type="password" name="DB_PASSWORD" autocomplete="new-password" placeholder="Leeg laten om te behouden"></label>';
        $body .= '<label class="check"><input type="checkbox" name="clear_DB_PASSWORD" value="1"> Databasewachtwoord wissen</label>';

        $body .= '<h3 class="wide">E-mail</h3>';
        $body .= '<label>Afzender e-mail<input type="email" name="MAIL_FROM" required value="' . $value('MAIL_FROM', (string) $mail['from']) . '"></label>';
        $body .= '<label>Afzender naam<input name="MAIL_FROM_NAME" value="' . $value('MAIL_FROM_NAME', (string) $mail['from_name']) . '"></label>';
        $body .= '<label>SMTP host<input name="SMTP_HOST" value="' . $value('SMTP_HOST', (string) $mail['smtp_host']) . '"></label>';
        $body .= '<label>SMTP poort<input name="SMTP_PORT" required inputmode="numeric" value="' . $value('SMTP_PORT', (string) $mail['smtp_port']) . '"></label>';
        $body .= '<label>SMTP gebruiker<input name="SMTP_USERNAME" value="' . $value('SMTP_USERNAME', (string) $mail['smtp_username']) . '"></label>';
        $body .= '<label>SMTP encryptie<select name="SMTP_ENCRYPTION">' . $encOptions . '</select></label>';
        $body .= '<label>SMTP wachtwoord<input type="password" name="SMTP_PASSWORD" autocomplete="new-password" placeholder="Leeg laten om te behouden"></label>';
        $body .= '<label class="check"><input type="checkbox" name="clear_SMTP_PASSWORD" value="1"> SMTP-wachtwoord wissen</label>';
        $body .= '<h3 class="wide">IMAP intake</h3>';
        $body .= '<label>Mailbox string<input name="IMAP_MAILBOX" value="' . $value('IMAP_MAILBOX', (string) ($imap['mailbox'] ?? '')) . '" placeholder="{imap.example.nl:993/imap/ssl}INBOX"></label>';
        $body .= '<label>IMAP gebruiker<input name="IMAP_USERNAME" value="' . $value('IMAP_USERNAME', (string) ($imap['username'] ?? '')) . '"></label>';
        $body .= '<label>Standaard categorie ID<input name="IMAP_DEFAULT_CATEGORY_ID" value="' . $value('IMAP_DEFAULT_CATEGORY_ID', (string) ($imap['default_category_id'] ?? '')) . '"></label>';
        $body .= '<label>IMAP wachtwoord<input type="password" name="IMAP_PASSWORD" autocomplete="new-password" placeholder="Leeg laten om te behouden"></label>';
        $body .= '<label class="check"><input type="checkbox" name="clear_IMAP_PASSWORD" value="1"> IMAP-wachtwoord wissen</label>';
        $body .= '<h3 class="wide">AD/LDAPS</h3>';
        $body .= '<label>AD host<input name="AD_HOST" value="' . $value('AD_HOST', (string) ($ad['host'] ?? '')) . '"></label>';
        $body .= '<label>AD poort<input name="AD_PORT" inputmode="numeric" value="' . $value('AD_PORT', (string) ($ad['port'] ?? '636')) . '"></label>';
        $body .= '<label>TLS<select name="AD_USE_TLS">' . $this->simpleOptions(['ldaps', 'starttls'], $value('AD_USE_TLS', (string) ($ad['use_tls'] ?? 'ldaps'))) . '</select></label>';
        $body .= '<label>Base DN<input name="AD_BASE_DN" value="' . $value('AD_BASE_DN', (string) ($ad['base_dn'] ?? '')) . '"></label>';
        $body .= '<label>Bind DN<input name="AD_BIND_DN" value="' . $value('AD_BIND_DN', (string) ($ad['bind_dn'] ?? '')) . '"></label>';
        $body .= '<label>Bind wachtwoord<input type="password" name="AD_BIND_PASSWORD" autocomplete="new-password" placeholder="Leeg laten om te behouden"></label>';
        $body .= '<label>User filter<input name="AD_USER_FILTER" value="' . $value('AD_USER_FILTER', (string) ($ad['user_filter'] ?? '')) . '"></label>';
        $body .= '<label>Viewer groep DN<input name="AD_GROUP_VIEWER" value="' . $value('AD_GROUP_VIEWER', (string) ($ad['group_viewer'] ?? '')) . '"></label>';
        $body .= '<label>Agent groep DN<input name="AD_GROUP_AGENT" value="' . $value('AD_GROUP_AGENT', (string) ($ad['group_agent'] ?? '')) . '"></label>';
        $body .= '<label>Manager groep DN<input name="AD_GROUP_MANAGER" value="' . $value('AD_GROUP_MANAGER', (string) ($ad['group_manager'] ?? '')) . '"></label>';
        $body .= '<label>Admin groep DN<input name="AD_GROUP_ADMIN" value="' . $value('AD_GROUP_ADMIN', (string) ($ad['group_admin'] ?? '')) . '"></label>';
        $body .= '<label class="check wide"><input type="checkbox" name="clear_AD_BIND_PASSWORD" value="1"> AD bind-wachtwoord wissen</label>';
        $body .= '<h3 class="wide">Thema</h3>';
        $body .= '<label>Brandkleur<input name="THEME_BRAND" value="' . $this->e($this->appSetting('theme_brand', '')) . '" placeholder="#0f6d7a"></label>';
        $body .= '<label>Accentkleur<input name="THEME_ACCENT" value="' . $this->e($this->appSetting('theme_accent', '')) . '" placeholder="#b7791f"></label>';
        $body .= '<label>Darkmode standaard<select name="THEME_DARK">' . $this->simpleOptions(['', '1'], $this->appSetting('theme_dark', '')) . '</select></label>';
        $body .= '<div class="wide actions"><button class="button">Configuratie opslaan</button></div></form>';
        $body .= '<form class="panel" method="post" action="/admin/ad/test">' . $this->csrf() . '<h2>AD-connectietest</h2><p>Test bind/search zonder wachtwoorden te tonen.</p><button class="button secondary">Test AD-connectie</button></form>';

        return $body;
    }

    private function testAdConnection(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();
        [$ok, $message] = $this->adConnectionTest();
        $this->safeAudit('ad_connection_test', ['result' => $ok ? 'ok' : $message]);
        $this->layout('AD-connectietest', '<section class="hero"><div><h1>AD-connectietest</h1><p>' . $this->e($ok ? 'ok' : $message) . '</p></div><a class="button" href="/admin/config">Terug</a></section>');
    }

    private function filters(): string
    {
        $agents = $this->db->query('SELECT id, name FROM users WHERE is_active = 1 AND role IN ("agent","manager","admin") ORDER BY name')->fetchAll();
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
        $limit = min(1000, max(1, (int) ($input['limit'] ?? 200)));
        $sql .= ' ORDER BY FIELD(t.priority,"kritiek","hoog","normaal","laag"), COALESCE(t.sla_deadline, t.created_at), t.created_at DESC LIMIT ' . $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function ticketTable(array $tickets, bool $actions): string
    {
        $rows = '';
        foreach ($tickets as $t) {
            $select = $actions ? '<td><input type="checkbox" name="ticket_ids[]" value="' . (int) $t['id'] . '" aria-label="Selecteer ticket"></td>' : '';
            $rows .= '<tr>' . $select . '<td><a href="/tickets/' . (int) $t['id'] . '">' . $this->e($t['ticket_number']) . '</a><small>' . $this->e($t['subject']) . '</small></td><td>' . $this->badge($t['status']) . '</td><td>' . $this->priority($t['priority']) . '</td><td>' . $this->e($t['category_name'] ?? '') . '</td><td>' . $this->e($t['agent_name'] ?? 'Niet toegewezen') . '</td><td>' . $this->sla($t) . '</td></tr>';
        }
        $head = ($actions ? '<th></th>' : '') . '<th>Ticket</th><th>Status</th><th>Prioriteit</th><th>Categorie</th><th>Agent</th><th>SLA</th>';
        $emptyColspan = $actions ? 7 : 6;
        $table = '<table class="table"><tr>' . $head . '</tr>' . ($rows ?: '<tr><td colspan="' . $emptyColspan . '">Geen tickets gevonden.</td></tr>') . '</table>';
        if (!$actions) {
            return $table;
        }
        $agents = $this->db->query('SELECT id, name FROM users WHERE is_active = 1 AND role IN ("agent","manager","admin") ORDER BY name')->fetchAll();
        $bulk = '<form class="panel quick" method="post" action="/tickets/bulk">' . $this->csrf();
        $bulk .= '<label>Status<select name="bulk_status"><option value="">Niet wijzigen</option>' . $this->simpleOptions(self::STATUSES, '') . '</select></label>';
        $bulk .= '<label>Toewijzen<select name="bulk_assigned_to"><option value="">Niet wijzigen</option>' . $this->options($agents, 'id', 'name') . '</select></label>';
        $bulk .= '<button class="button">Bulkactie uitvoeren</button>' . $table . '</form>';
        return $bulk;
    }

    private function agentActions(array $ticket): string
    {
        $agents = $this->db->query('SELECT id, name FROM users WHERE is_active = 1 AND role IN ("agent","manager","admin") ORDER BY name')->fetchAll();
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
        $nav = '<a class="skip-link" href="#main">Skip to main content</a><nav aria-label="Hoofdnavigatie"><a href="/">' . $this->e($this->settings['app_name']) . '</a><div><a href="/knowledge-base">Kennisbank</a>';
        if ($user) {
            $nav .= '<a href="/dashboard">Dashboard</a><a href="/tickets">Tickets</a><a href="/tickets/new">Nieuw ticket</a><a href="/profile">Profiel</a>';
            if ($this->hasMinimumRole($user, 'manager')) {
                $nav .= '<a href="/admin/reports">Rapportages</a><a href="/admin/audit">Auditlog</a><a href="/admin/config">Config</a>';
            }
            if ($user['role'] === 'admin') {
                $nav .= '<a href="/admin/users">Gebruikers</a><a href="/admin/categories">Categorieën</a><a href="/admin/sla">SLA</a><a href="/admin/templates">Templates</a><a href="/admin/knowledge-base">KB beheer</a><a href="/admin/webhooks">Webhooks</a>';
            }
            $nav .= '<form method="post" action="/theme">' . $this->csrf() . '<button title="Thema wisselen">Thema</button></form>';
            $nav .= '<form method="post" action="/logout">' . $this->csrf() . '<button>Uitloggen</button></form>';
        } else {
            $nav .= '<form method="post" action="/theme">' . $this->csrf() . '<button title="Thema wisselen">Thema</button></form>';
            $nav .= '<a href="/login">Login</a>';
        }
        $nav .= '</div></nav>';
        $theme = ($_COOKIE['theme'] ?? $this->appSetting('theme_dark', '')) === 'dark' || ($_COOKIE['theme'] ?? '') === 'dark' ? ' class="theme-dark"' : '';
        $brand = $this->appSetting('theme_brand', '');
        $accent = $this->appSetting('theme_accent', '');
        $style = '';
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $brand)) {
            $style .= '--brand:' . $brand . ';';
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $style .= '--accent:' . $accent . ';';
        }
        $styleAttr = $style !== '' ? ' style="' . $this->e($style) . '"' : '';
        echo '<!doctype html><html lang="nl"' . $theme . $styleAttr . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $this->e($title) . '</title><link rel="stylesheet" href="/assets/css/app.css"></head><body>' . $nav . '<main id="main" tabindex="-1">' . $body . '</main><script src="/assets/js/app.js"></script></body></html>';
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

    private function requireMinimumRole(string $role): array
    {
        $user = $this->requireUser();
        if (!$this->hasMinimumRole($user, $role)) {
            http_response_code(403);
            $this->layout('Geen toegang', '<section class="panel danger"><h1>Geen toegang</h1></section>');
            exit;
        }
        return $user;
    }

    private function hasMinimumRole(array $user, string $role): bool
    {
        return (self::ROLE_LEVELS[(string) ($user['role'] ?? 'viewer')] ?? 0) >= (self::ROLE_LEVELS[$role] ?? 999);
    }

    private function validRole(string $role): string
    {
        return array_key_exists($role, self::ROLES) ? $role : 'agent';
    }

    private function roleLabel(string $role): string
    {
        return self::ROLES[$role] ?? $role;
    }

    private function roleOptions(string $selected): string
    {
        $out = '';
        foreach (self::ROLES as $value => $label) {
            $out .= '<option value="' . $this->e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        return $out;
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

    private function readEnvFile(): array
    {
        $path = dirname(__DIR__) . '/.env';
        $values = [];
        if (!is_file($path)) {
            return $values;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '') {
                continue;
            }
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }
            $values[$key] = $value;
        }

        return $values;
    }

    private function writeEnvFile(array $values): void
    {
        $path = dirname(__DIR__) . '/.env';
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException('.env-map kon niet worden benaderd.');
        }
        if (is_file($path) && !is_writable($path)) {
            throw new \RuntimeException('.env-bestand is niet schrijfbaar.');
        }

        $orderedKeys = [
            'APP_NAME',
            'APP_URL',
            'APP_ENV',
            'DB_HOST',
            'DB_PORT',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
            'MAIL_FROM',
            'MAIL_FROM_NAME',
            'SMTP_HOST',
            'SMTP_PORT',
            'SMTP_USERNAME',
            'SMTP_PASSWORD',
            'SMTP_ENCRYPTION',
            'IMAP_MAILBOX',
            'IMAP_USERNAME',
            'IMAP_PASSWORD',
            'IMAP_DEFAULT_CATEGORY_ID',
            'AD_HOST',
            'AD_PORT',
            'AD_USE_TLS',
            'AD_BASE_DN',
            'AD_BIND_DN',
            'AD_BIND_PASSWORD',
            'AD_USER_FILTER',
            'AD_GROUP_VIEWER',
            'AD_GROUP_AGENT',
            'AD_GROUP_MANAGER',
            'AD_GROUP_ADMIN',
            'DEFAULT_ADMIN_NAME',
            'DEFAULT_ADMIN_EMAIL',
            'DEFAULT_ADMIN_PASSWORD',
            'DATA_RETENTION_DAYS',
        ];

        $lines = [];
        foreach ($orderedKeys as $key) {
            if (array_key_exists($key, $values)) {
                $lines[] = $key . '=' . $this->formatEnvValue((string) $values[$key]);
                unset($values[$key]);
            }
        }
        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z0-9_]+$/', (string) $key) === 1) {
                $lines[] = (string) $key . '=' . $this->formatEnvValue((string) $value);
            }
        }

        $tmp = tempnam($dir, '.env.');
        if ($tmp === false || file_put_contents($tmp, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('.env kon niet worden geschreven.');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('.env kon niet worden vervangen.');
        }
    }

    private function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z0-9_@.\\/:+-]+$/', $value) === 1) {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function appSetting(string $key, string $default = ''): string
    {
        try {
            $stmt = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value === false || $value === null || $value === '' ? $default : (string) $value;
        } catch (Throwable) {
            return $default;
        }
    }

    private function setAppSetting(string $key, string $value): void
    {
        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            return;
        }
        try {
            $this->db->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$key, $value]);
        } catch (Throwable) {
            // Migratie 006 moet bestaan voor thema-instellingen; config opslaan mag niet blokkeren.
        }
    }

    private function safeAudit(string $action, array $details = []): void
    {
        try {
            $ticketId = (int) ($this->db->query('SELECT id FROM tickets ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
            if ($ticketId > 0) {
                $user = $this->currentUser();
                $this->audit($ticketId, $user['id'] ?? null, $user['name'] ?? 'Systeem', $action, $details);
            }
        } catch (Throwable) {
            // Systeemevents zonder ticket mogen de hoofdactie niet blokkeren.
        }
    }

    private function ensureCsatSurvey(int $ticketId): string
    {
        $stmt = $this->db->prepare('SELECT token FROM csat_surveys WHERE ticket_id = ? LIMIT 1');
        $stmt->execute([$ticketId]);
        $token = $stmt->fetchColumn();
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            $this->db->prepare('INSERT INTO csat_surveys (ticket_id, token, sent_at) VALUES (?, ?, NOW())')->execute([$ticketId, $token]);
        } else {
            $this->db->prepare('UPDATE csat_surveys SET sent_at = COALESCE(sent_at, NOW()) WHERE token = ?')->execute([$token]);
        }
        return rtrim((string) $this->settings['app_url'], '/') . '/csat/' . $token;
    }

    private function csatByToken(string $token): array
    {
        $stmt = $this->db->prepare('SELECT * FROM csat_surveys WHERE token = ?');
        $stmt->execute([$token]);
        $survey = $stmt->fetch();
        if (!$survey) {
            $this->notFound();
        }
        return $survey;
    }

    private function timeEntriesPanel(int $ticketId): string
    {
        $rows = '';
        $total = 0;
        $stmt = $this->db->prepare('SELECT te.*, u.name user_name FROM ticket_time_entries te JOIN users u ON u.id = te.user_id WHERE te.ticket_id = ? ORDER BY te.created_at DESC');
        $stmt->execute([$ticketId]);
        foreach ($stmt->fetchAll() as $entry) {
            $total += (int) $entry['minutes'];
            $rows .= '<tr><td>' . $this->e($entry['created_at']) . '</td><td>' . $this->e($entry['user_name']) . '</td><td>' . (int) $entry['minutes'] . '</td><td>' . $this->e((string) $entry['note']) . '</td></tr>';
        }
        $body = '<section class="panel"><h2>Tijdregistratie</h2><p>Totaal: ' . round($total / 60, 2) . ' uur</p>';
        $body .= '<form class="quick" method="post" action="/tickets/' . $ticketId . '/time">' . $this->csrf() . '<label>Minuten<input type="number" min="1" name="minutes" required></label><label>Notitie<input name="note"></label><button class="button">Tijd boeken</button></form>';
        $body .= '<table class="table"><tr><th>Tijd</th><th>Gebruiker</th><th>Minuten</th><th>Notitie</th></tr>' . ($rows ?: '<tr><td colspan="4">Nog geen tijd geboekt.</td></tr>') . '</table></section>';
        return $body;
    }

    private function simplePdf(string $text): string
    {
        $safe = str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $text);
        $stream = "BT /F1 10 Tf 40 790 Td ";
        foreach (explode("\n", $safe) as $line) {
            $stream .= '(' . mb_substr($line, 0, 120) . ') Tj 0 -14 Td ';
        }
        $stream .= 'ET';
        $objects = [
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj',
            '4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj',
            '5 0 obj << /Length ' . strlen($stream) . ' >> stream' . "\n" . $stream . "\nendstream endobj",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object . "\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function uniqueSlug(string $title): string
    {
        $ascii = function_exists('iconv') ? (iconv('UTF-8', 'ASCII//TRANSLIT', $title) ?: $title) : $title;
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
        $base = $base !== '' ? $base : 'artikel';
        $slug = $base;
        $i = 2;
        while (true) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM knowledge_articles WHERE slug = ?');
            $stmt->execute([$slug]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $i++;
        }
    }

    private function defaultCategoryId(): int
    {
        return (int) ($this->db->query('SELECT id FROM categories WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 1);
    }

    private function attemptAdLogin(string $email, string $password): ?array
    {
        $ad = $this->settings['ad'] ?? [];
        if (($ad['host'] ?? '') === '' || $password === '' || !function_exists('ldap_connect')) {
            return null;
        }
        [$ok, $data] = $this->adBindAndSearch($email, $password);
        if (!$ok || !is_array($data)) {
            $this->safeAudit('ad_login_failed', ['actor' => $email, 'result' => is_string($data) ? $data : 'failed']);
            return null;
        }
        $role = $this->adRoleFromGroups($data['groups'] ?? []);
        if ($role === null) {
            $this->safeAudit('ad_login_failed', ['actor' => $email, 'result' => 'no_group_mapping']);
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            $this->db->prepare('UPDATE users SET name = ?, role = ?, is_active = 1 WHERE id = ?')->execute([$data['name'] ?: $email, $role, $user['id']]);
            $stmt->execute([$email]);
            $user = $stmt->fetch();
        } else {
            $this->db->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')->execute([$data['name'] ?: $email, $email, password_hash(bin2hex(random_bytes(20)), PASSWORD_BCRYPT), $role]);
            $stmt->execute([$email]);
            $user = $stmt->fetch();
        }
        $this->safeAudit('ad_login_success', ['actor' => $email, 'role' => $role]);
        return $user ?: null;
    }

    private function adConnectionTest(): array
    {
        if (!function_exists('ldap_connect')) {
            return [false, 'ldap_extension_missing'];
        }
        $ad = $this->settings['ad'] ?? [];
        if (($ad['host'] ?? '') === '' || ($ad['bind_dn'] ?? '') === '') {
            return [false, 'not_configured'];
        }
        $conn = $this->ldapConnection();
        if (!$conn) {
            return [false, 'connection_failed'];
        }
        if (!@ldap_bind($conn, (string) $ad['bind_dn'], (string) ($ad['bind_password'] ?? ''))) {
            return [false, 'bind_failed'];
        }
        return [true, 'ok'];
    }

    private function adBindAndSearch(string $username, string $password): array
    {
        $ad = $this->settings['ad'] ?? [];
        $conn = $this->ldapConnection();
        if (!$conn) {
            return [false, 'connection_failed'];
        }
        if (($ad['bind_dn'] ?? '') !== '' && !@ldap_bind($conn, (string) $ad['bind_dn'], (string) ($ad['bind_password'] ?? ''))) {
            return [false, 'service_bind_failed'];
        }
        $account = str_contains($username, '@') ? strstr($username, '@', true) : $username;
        $filter = str_replace(['{username}', '{email}'], [ldap_escape((string) $account, '', LDAP_ESCAPE_FILTER), ldap_escape($username, '', LDAP_ESCAPE_FILTER)], (string) ($ad['user_filter'] ?? '(sAMAccountName={username})'));
        $search = @ldap_search($conn, (string) ($ad['base_dn'] ?? ''), $filter, ['dn', 'cn', 'mail', 'memberOf']);
        if (!$search) {
            return [false, 'user_search_failed'];
        }
        $entries = ldap_get_entries($conn, $search);
        if (($entries['count'] ?? 0) < 1) {
            return [false, 'user_not_found'];
        }
        $dn = (string) $entries[0]['dn'];
        if (!@ldap_bind($conn, $dn, $password)) {
            return [false, 'user_bind_failed'];
        }
        $groups = [];
        foreach (($entries[0]['memberof'] ?? []) as $key => $value) {
            if (is_int($key)) {
                $groups[] = (string) $value;
            }
        }
        return [true, ['dn' => $dn, 'name' => (string) ($entries[0]['cn'][0] ?? $username), 'groups' => $groups]];
    }

    private function adChangePassword(string $username, string $current, string $new): array
    {
        $ad = $this->settings['ad'] ?? [];
        if (($ad['use_tls'] ?? 'ldaps') === '' || !in_array($ad['use_tls'], ['ldaps', 'starttls'], true)) {
            return [false, 'tls_required'];
        }
        if (!function_exists('ldap_connect')) {
            return [false, 'ldap_extension_missing'];
        }
        [$ok, $data] = $this->adBindAndSearch($username, $current);
        if (!$ok || !is_array($data)) {
            return [false, is_string($data) ? $data : 'bind_failed'];
        }
        $conn = $this->ldapConnection();
        if (!$conn || !@ldap_bind($conn, (string) $data['dn'], $current)) {
            return [false, 'bind_failed'];
        }
        $encoded = mb_convert_encoding('"' . $new . '"', 'UTF-16LE', 'UTF-8');
        if (!@ldap_modify($conn, (string) $data['dn'], ['unicodePwd' => $encoded])) {
            return [false, 'policy_violation'];
        }
        return [true, 'ok'];
    }

    private function ldapConnection()
    {
        $ad = $this->settings['ad'] ?? [];
        $scheme = ($ad['use_tls'] ?? 'ldaps') === 'ldaps' ? 'ldaps://' : 'ldap://';
        $conn = @ldap_connect($scheme . (string) ($ad['host'] ?? ''), (int) ($ad['port'] ?? 636));
        if (!$conn) {
            return false;
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        if (($ad['use_tls'] ?? '') === 'starttls' && !@ldap_start_tls($conn)) {
            return false;
        }
        return $conn;
    }

    private function adRoleFromGroups(array $groups): ?string
    {
        $ad = $this->settings['ad'] ?? [];
        foreach (['admin', 'manager', 'agent', 'viewer'] as $role) {
            $dn = (string) ($ad['group_' . $role] ?? '');
            if ($dn !== '' && in_array(strtolower($dn), array_map('strtolower', $groups), true)) {
                return $role;
            }
        }
        return null;
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
        $this->dispatchWebhooks($event, $ticket);
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
            '{{ csat.link }}' => $ticket['csat_link'] ?? '',
        ];
        return strtr($text, $vars);
    }

    private function dispatchWebhooks(string $event, array $ticket): void
    {
        try {
            $hooks = $this->db->query('SELECT * FROM webhook_endpoints WHERE is_active = 1')->fetchAll();
        } catch (Throwable) {
            return;
        }
        foreach ($hooks as $hook) {
            $events = array_map('trim', explode(',', (string) $hook['events']));
            if (!in_array('*', $events, true) && !in_array($event, $events, true)) {
                continue;
            }
            $payload = json_encode([
                'event' => $event,
                'ticket' => [
                    'number' => $ticket['ticket_number'] ?? '',
                    'subject' => $ticket['subject'] ?? '',
                    'status' => $ticket['status'] ?? '',
                    'priority' => $ticket['priority'] ?? '',
                    'url' => rtrim((string) $this->settings['app_url'], '/') . '/tickets/' . ($ticket['id'] ?? ''),
                ],
            ]);
            $status = null;
            $error = null;
            try {
                $ch = curl_init((string) $hook['url']);
                if ($ch === false) {
                    throw new \RuntimeException('curl_init_failed');
                }
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                ]);
                curl_exec($ch);
                $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: null;
                $error = curl_error($ch) ?: null;
                curl_close($ch);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
            $this->db->prepare('INSERT INTO webhook_logs (endpoint_id, event_type, status_code, error) VALUES (?, ?, ?, ?)')->execute([$hook['id'], $event, $status, $error]);
        }
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
