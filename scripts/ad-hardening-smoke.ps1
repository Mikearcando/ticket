param(
    [string]$BaseUrl = "http://127.0.0.1:8081"
)

$ErrorActionPreference = "Stop"

function Get-Csrf($Content) {
    $match = [regex]::Match($Content, 'name="_csrf" value="([^"]+)"')
    if (-not $match.Success) {
        throw "CSRF token niet gevonden"
    }
    return $match.Groups[1].Value
}

function Query-Db([string]$Sql) {
    docker compose exec -T mysql mysql -uticket_user -pticket_pass ticket_systeem -N -e $Sql
}

$schema = Query-Db "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='auth_source'; SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='external_auth_id'; SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='uq_users_external_auth'; SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='login_attempts' AND COLUMN_NAME='auth_source'; SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_audit_log'; SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='csat_surveys' AND INDEX_NAME='uq_csat_ticket';"
$schemaValues = @($schema | Where-Object { $_ -match '^\d+$' } | ForEach-Object { [int] $_ })
if ($schemaValues.Count -lt 6 -or ($schemaValues | Where-Object { $_ -lt 1 }).Count -gt 0) {
    throw "AD-hardening schema ontbreekt"
}

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$login = Invoke-WebRequest -Uri "$BaseUrl/login" -WebSession $session -UseBasicParsing
$csrf = Get-Csrf $login.Content
$dashboard = Invoke-WebRequest -Uri "$BaseUrl/login" -Method Post -WebSession $session -UseBasicParsing -Body @{
    _csrf = $csrf
    email = "admin@example.nl"
    password = "ChangeMe123!"
}
if ($dashboard.Content -notmatch "Dashboard") {
    throw "Admin-login mislukt"
}

$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$adEmail = "ad-smoke-$suffix@example.nl"
$adExternalId = "CN=AD Smoke $suffix,DC=example,DC=invalid"
Query-Db "INSERT INTO users (name, email, password_hash, auth_source, external_auth_id, role, is_active) SELECT 'AD Smoke', '$adEmail', password_hash, 'ad', '$adExternalId', 'agent', 1 FROM users WHERE email='admin@example.nl' ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), auth_source='ad', external_auth_id=VALUES(external_auth_id), is_active=1;"
$adSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$adLogin = Invoke-WebRequest -Uri "$BaseUrl/login" -WebSession $adSession -UseBasicParsing
$csrf = Get-Csrf $adLogin.Content
$blocked = Invoke-WebRequest -Uri "$BaseUrl/login" -Method Post -WebSession $adSession -UseBasicParsing -Body @{
    _csrf = $csrf
    email = $adEmail
    password = "ChangeMe123!"
}
if ($blocked.Content -notmatch "Gebruik uw domeinaccount") {
    throw "AD-account kon lokale fallback gebruiken"
}
$fallbackAudit = Query-Db "SELECT COUNT(*) FROM system_audit_log WHERE action='ad_local_fallback_blocked' AND details LIKE '%$adEmail%';"
if ([int](($fallbackAudit | Select-Object -Last 1).Trim()) -lt 1) {
    throw "Geblokkeerde AD-local fallback is niet geaudit"
}

$config = Invoke-WebRequest -Uri "$BaseUrl/admin/config" -WebSession $session -UseBasicParsing
foreach ($required in @("AD/LDAPS", "AD_HOST", "AD_PORT", "AD_USE_TLS", "AD_BASE_DN", "AD_BIND_DN", "AD_TLS_REQUIRE_CERT", "/admin/ad/test")) {
    if ($config.Content -notmatch [regex]::Escape($required)) {
        throw "AD-config oppervlak ontbreekt: $required"
    }
}
if ($config.Content -match 'name="AD_BIND_PASSWORD"[^>]*value=') {
    throw "AD_BIND_PASSWORD wordt met waarde gerenderd"
}

$csrf = Get-Csrf $config.Content
$invalid = Invoke-WebRequest -Uri "$BaseUrl/admin/config" -Method Post -WebSession $session -UseBasicParsing -Body @{
    _csrf = $csrf
    APP_NAME = "Ticket Systeem"
    APP_URL = $BaseUrl
    APP_ENV = "local"
    DB_HOST = "mysql"
    DB_PORT = "3306"
    DB_DATABASE = "ticket_systeem"
    DB_USERNAME = "ticket_user"
    MAIL_FROM = "noreply@example.nl"
    MAIL_FROM_NAME = "Supportdesk"
    SMTP_HOST = ""
    SMTP_PORT = "587"
    SMTP_USERNAME = ""
    SMTP_ENCRYPTION = "tls"
    IMAP_MAILBOX = ""
    IMAP_USERNAME = ""
    IMAP_DEFAULT_CATEGORY_ID = ""
    AD_HOST = ""
    AD_PORT = "70000"
    AD_USE_TLS = "plain"
    AD_BASE_DN = ""
    AD_BIND_DN = ""
    AD_USER_FILTER = "(&(objectClass=user)(sAMAccountName={username}))"
    AD_GROUP_VIEWER = ""
    AD_GROUP_AGENT = ""
    AD_GROUP_MANAGER = ""
    AD_GROUP_ADMIN = ""
    AD_TLS_REQUIRE_CERT = "demand"
    AD_TLS_CACERTFILE = ""
    AD_TLS_CACERTDIR = ""
    AD_NETWORK_TIMEOUT = "5"
    DATA_RETENTION_DAYS = "365"
}
if ($invalid.Content -notmatch "AD_PORT moet een poortnummer tussen 1 en 65535 zijn" -or $invalid.Content -notmatch "AD_USE_TLS moet ldaps of starttls zijn") {
    throw "AD-config validatie faalde"
}

$config = Invoke-WebRequest -Uri "$BaseUrl/admin/config" -WebSession $session -UseBasicParsing
$csrf = Get-Csrf $config.Content
$adTest = Invoke-WebRequest -Uri "$BaseUrl/admin/ad/test" -Method Post -WebSession $session -UseBasicParsing -Body @{ _csrf = $csrf }
if ($adTest.Content -notmatch "latency_ms" -or $adTest.Content -notmatch "bind_status" -or $adTest.Content -notmatch "search_status" -or $adTest.Content -notmatch "error_category") {
    throw "AD-connectietest toont geen veilige statusvelden"
}

$auditCount = Query-Db "SELECT COUNT(*) FROM system_audit_log WHERE action='ad_connection_test';"
if ([int](($auditCount | Select-Object -Last 1).Trim()) -lt 1) {
    throw "AD-connectietest is niet in system_audit_log vastgelegd"
}

$adPasswordSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$adPassword = Invoke-WebRequest -Uri "$BaseUrl/ad/password" -WebSession $adPasswordSession -UseBasicParsing
$csrf = Get-Csrf $adPassword.Content
$before = Query-Db "SELECT COUNT(*) FROM system_audit_log WHERE action='ad_password_change_failed';"
$result = Invoke-WebRequest -Uri "$BaseUrl/ad/password" -Method Post -WebSession $adPasswordSession -UseBasicParsing -Body @{
    _csrf = $csrf
    username = "someone@example.local"
    current_password = "old-password"
    new_password = "short"
}
if ($result.Content -notmatch "minimaal 10 tekens") {
    throw "AD-wachtwoordvalidatie faalde"
}
$after = Query-Db "SELECT COUNT(*) FROM system_audit_log WHERE action='ad_password_change_failed';"
if ([int](($after | Select-Object -Last 1).Trim()) -ne [int](($before | Select-Object -Last 1).Trim())) {
    throw "AD-wachtwoordvalidatie schreef toch audit vóór LDAP-pad"
}

Write-Host "ad-hardening-smoke-ok"
