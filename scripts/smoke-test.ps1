param(
    [string]$BaseUrl = "http://127.0.0.1:8080"
)

$ErrorActionPreference = "Stop"

function Get-Csrf($Content) {
    $match = [regex]::Match($Content, 'name="_csrf" value="([^"]+)"')
    if (-not $match.Success) {
        throw "CSRF token niet gevonden"
    }
    return $match.Groups[1].Value
}

$publicSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$page = Invoke-WebRequest -Uri "$BaseUrl/" -WebSession $publicSession -UseBasicParsing
foreach ($header in @("X-Frame-Options", "X-Content-Type-Options", "Content-Security-Policy")) {
    if (-not $page.Headers[$header]) {
        throw "Security header ontbreekt: $header"
    }
}
$csrf = Get-Csrf $page.Content
$categorySelect = [regex]::Match($page.Content, '<select[^>]*name="category_id"[^>]*>(.*?)</select>').Groups[1].Value
$categoryId = [regex]::Match($categorySelect, '<option value="(\d+)"').Groups[1].Value
if (-not $categoryId) {
    throw "Geen actieve categorie gevonden"
}

$ticket = Invoke-WebRequest -Uri "$BaseUrl/ticket" -Method Post -WebSession $publicSession -UseBasicParsing -Body @{
    _csrf = $csrf
    customer_name = "Smoke Klant"
    customer_email = "smoke-klant@example.nl"
    subject = "Smoke test ticket"
    category_id = $categoryId
    priority = "kritiek"
    description = "Automatische MVP-rooktest"
    website = ""
}
if ($ticket.Content -notmatch 'TKT-\d{4}-\d{6}') {
    throw "Ticketaanmaak gaf geen geldig ticketnummer"
}
$portalPath = ([regex]::Match($ticket.BaseResponse.ResponseUri.AbsoluteUri, '/ticket/[a-f0-9]{64}')).Value
if (-not $portalPath) {
    $portalPath = ([regex]::Match($ticket.Content, '/ticket/[a-f0-9]{64}')).Value
}
if (-not $portalPath) {
    $token = docker compose exec -T mysql mysql -uticket_user -pticket_pass ticket_systeem -N -e "SELECT customer_token FROM tickets WHERE subject = 'Smoke test ticket' ORDER BY id DESC LIMIT 1"
    $token = ($token | Select-Object -Last 1).Trim()
    if ($token -match '^[a-f0-9]{64}$') {
        $portalPath = "/ticket/$token"
    }
}
if (-not $portalPath) {
    throw "Klantportaal-token niet gevonden"
}
$portalCsrf = Get-Csrf $ticket.Content
$portalReply = Invoke-WebRequest -Uri "$BaseUrl$portalPath/reply" -Method Post -WebSession $publicSession -UseBasicParsing -Body @{
    _csrf = $portalCsrf
    body = "Klantreactie smoke test"
}
if ($portalReply.Content -notmatch "Klantreactie smoke test") {
    throw "Klantportaalreactie faalde"
}

$adminSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$agentEmail = "smoke-agent-$suffix@example.nl"
$login = Invoke-WebRequest -Uri "$BaseUrl/login" -WebSession $adminSession -UseBasicParsing
$csrf = Get-Csrf $login.Content
$dashboard = Invoke-WebRequest -Uri "$BaseUrl/login" -Method Post -WebSession $adminSession -UseBasicParsing -Body @{
    _csrf = $csrf
    email = "admin@example.nl"
    password = "ChangeMe123!"
}
if ($dashboard.Content -notmatch "Dashboard") {
    throw "Admin-login mislukt"
}

$today = (Get-Date).ToString("yyyy-MM-dd")
$tickets = Invoke-WebRequest -Uri "$BaseUrl/tickets?date_from=$today&date_to=$today&priority=kritiek" -WebSession $adminSession -UseBasicParsing
if ($tickets.Content -notmatch "Smoke test ticket") {
    throw "Ticketfilter op datum/prioriteit faalde"
}

$users = Invoke-WebRequest -Uri "$BaseUrl/admin/users" -WebSession $adminSession -UseBasicParsing
$csrf = Get-Csrf $users.Content
$users = Invoke-WebRequest -Uri "$BaseUrl/admin/users" -Method Post -WebSession $adminSession -UseBasicParsing -Body @{
    _csrf = $csrf
    name = "Smoke Agent"
    email = $agentEmail
    role = "agent"
    password = "AgentPass123!"
}
if ($users.Content -notmatch [regex]::Escape($agentEmail)) {
    throw "Gebruiker aanmaken faalde"
}

$categories = Invoke-WebRequest -Uri "$BaseUrl/admin/categories/$categoryId" -WebSession $adminSession -UseBasicParsing
$csrf = Get-Csrf $categories.Content
$categories = Invoke-WebRequest -Uri "$BaseUrl/admin/categories/$categoryId" -Method Post -WebSession $adminSession -UseBasicParsing -Body @{
    _csrf = $csrf
    name = "Smoke Categorie"
    description = "Gewijzigd door smoke test"
    is_active = "1"
}
if ($categories.Content -notmatch "Smoke Categorie") {
    throw "Categorie bijwerken faalde"
}

$detail = Invoke-WebRequest -Uri "$BaseUrl/tickets/1" -WebSession $adminSession -UseBasicParsing
$csrf = Get-Csrf $detail.Content
$detail = Invoke-WebRequest -Uri "$BaseUrl/tickets/1/status" -Method Post -WebSession $adminSession -UseBasicParsing -Body @{
    _csrf = $csrf
    _method = "PATCH"
    status = "open"
}
if ($detail.Content -notmatch "open") {
    throw "Statuswijziging faalde"
}

$audit = Invoke-WebRequest -Uri "$BaseUrl/admin/audit" -WebSession $adminSession -UseBasicParsing
if ($audit.Content -notmatch "Auditlog" -or $audit.Content -notmatch "status_changed") {
    throw "Auditlogpagina faalde"
}

$forgot = Invoke-WebRequest -Uri "$BaseUrl/password/forgot" -WebSession $adminSession -UseBasicParsing
$csrfForgot = Get-Csrf $forgot.Content
$forgot = Invoke-WebRequest -Uri "$BaseUrl/password/forgot" -Method Post -WebSession $adminSession -UseBasicParsing -Body @{
    _csrf = $csrfForgot
    email = "admin@example.nl"
}
if ($forgot.Content -notmatch "resetlink") {
    throw "Wachtwoordreset-flow faalde"
}

$sla = docker compose exec -T app php sla_check.php
if ($LASTEXITCODE -ne 0 -or $sla -notmatch "SLA-check voltooid") {
    throw "SLA-check faalde"
}

$mailRows = docker compose exec -T mysql mysql -uticket_user -pticket_pass ticket_systeem -N -e "SELECT COUNT(*) FROM mail_log WHERE event_type IN ('ticket_created','reply_from_customer','password_reset')"
if ([int]($mailRows | Select-Object -Last 1) -lt 3) {
    throw "Mail-log verificatie faalde"
}

Write-Output "smoke-test-ok"
