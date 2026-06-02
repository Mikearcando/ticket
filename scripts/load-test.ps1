param(
    [string]$BaseUrl = "http://127.0.0.1:8080",
    [int]$TargetTickets = 10000,
    [int]$ConcurrentRequests = 50,
    [int]$MaxTotalMilliseconds = 120000
)

$ErrorActionPreference = "Stop"

php .\scripts\seed-performance.php $TargetTickets | Out-Host

$jobs = @()
$elapsed = Measure-Command {
    for ($i = 1; $i -le $ConcurrentRequests; $i++) {
        $jobs += Start-Job -ScriptBlock {
            param($Url)
            try {
                $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
                $login = Invoke-WebRequest -Uri "$Url/login" -WebSession $session -UseBasicParsing
                $csrf = [regex]::Match($login.Content, 'name="_csrf" value="([^"]+)"').Groups[1].Value
                if (-not $csrf) {
                    return "FAIL no-csrf"
                }
                $dashboard = Invoke-WebRequest -Uri "$Url/login" -Method Post -WebSession $session -UseBasicParsing -Body @{
                    _csrf = $csrf
                    email = "admin@example.nl"
                    password = "ChangeMe123!"
                }
                if ($dashboard.Content -notmatch "Dashboard") {
                    return "FAIL login"
                }
                $response = Invoke-WebRequest -Uri "$Url/tickets" -WebSession $session -UseBasicParsing
                if ($response.StatusCode -ne 200 -or $response.Content -notmatch "Ticketoverzicht") {
                    return "FAIL status=$($response.StatusCode) match=$($response.Content -match 'Ticketoverzicht') body=$($response.Content.Substring(0, [Math]::Min(80, $response.Content.Length)))"
                }
                return "OK"
            } catch {
                return "FAIL exception=$($_.Exception.Message)"
            }
        } -ArgumentList $BaseUrl
    }
    $jobs | Wait-Job | Out-Null
}

$results = @()
foreach ($job in $jobs) {
    $results += Receive-Job $job
    Remove-Job $job
}

$failures = $results | Where-Object { $_ -notmatch "^OK$" }
if ($failures.Count -gt 0) {
    $failures | Select-Object -First 5 | ForEach-Object { Write-Output $_ }
    throw "$($failures.Count) loadtest request(s) faalden"
}

$ms = [math]::Round($elapsed.TotalMilliseconds, 1)
Write-Output "load-test-ms=$ms"
if ($ms -gt $MaxTotalMilliseconds) {
    throw "Loadtest duurde ${ms}ms, limiet is ${MaxTotalMilliseconds}ms"
}
Write-Output "load-test-ok"
