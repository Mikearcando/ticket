param(
    [string]$BaseUrl = "http://127.0.0.1:8080",
    [int]$TargetTickets = 1000,
    [int]$MaxMilliseconds = 1500
)

$ErrorActionPreference = "Stop"

php .\scripts\seed-performance.php $TargetTickets | Out-Host

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$login = Invoke-WebRequest -Uri "$BaseUrl/login" -WebSession $session -UseBasicParsing
$csrf = [regex]::Match($login.Content, 'name="_csrf" value="([^"]+)"').Groups[1].Value
if (-not $csrf) {
    throw "Geen CSRF-token op loginpagina"
}

$dashboard = Invoke-WebRequest -Uri "$BaseUrl/login" -Method Post -WebSession $session -UseBasicParsing -Body @{
    _csrf = $csrf
    email = "admin@example.nl"
    password = "ChangeMe123!"
}
if ($dashboard.Content -notmatch "Dashboard") {
    throw "Login mislukt"
}

$elapsed = Measure-Command {
    $response = Invoke-WebRequest -Uri "$BaseUrl/tickets" -WebSession $session -UseBasicParsing
    if ($response.StatusCode -ne 200 -or $response.Content -notmatch "Ticketoverzicht") {
        throw "Ticketoverzicht gaf geen geldige response"
    }
}

$ms = [math]::Round($elapsed.TotalMilliseconds, 1)
Write-Output "tickets-page-ms=$ms"
if ($ms -gt $MaxMilliseconds) {
    throw "Ticketoverzicht duurde ${ms}ms, limiet is ${MaxMilliseconds}ms"
}
Write-Output "performance-test-ok"
