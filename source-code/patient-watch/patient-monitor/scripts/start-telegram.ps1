param([switch]$CheckOnly)

$ErrorActionPreference = 'Stop'
$appRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$artisan = Join-Path $appRoot 'artisan'
$runtime = Join-Path $appRoot '.runtime'
$php = (Get-Command php -ErrorAction Stop).Source
$stamp = [DateTime]::Now.ToString('yyyyMMdd-HHmmss-fff')
if (-not (Test-Path -LiteralPath $runtime)) {
    [void](New-Item -ItemType Directory -Path $runtime)
}

function Invoke-LocalCheck([string]$name, [string[]]$commandArgs) {
    $process = Start-Process -FilePath $php -ArgumentList $commandArgs `
        -WorkingDirectory $appRoot -WindowStyle Hidden -Wait -PassThru `
        -RedirectStandardOutput (Join-Path $runtime ("telegram-start-$name-$stamp.log")) `
        -RedirectStandardError (Join-Path $runtime ("telegram-start-$name-$stamp-error.log"))
    if ($process.ExitCode -ne 0) {
        throw "Telegram $name check failed. Review the local startup logs before starting services."
    }
}

# These checks use the current Laravel database configuration and send no messages.
Invoke-LocalCheck 'config' @('-d', 'extension=pdo_pgsql', ('"' + $artisan + '"'), 'telegram:check', '--configured')
Invoke-LocalCheck 'database' @('-d', 'extension=pdo_pgsql', ('"' + $artisan + '"'), 'migrate:status', '--no-ansi')

$phpProcesses = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'")
$otherPollers = @($phpProcesses | Where-Object {
    $_.CommandLine -and $_.CommandLine.Contains('telegram:poll') -and
    -not $_.CommandLine.Contains($artisan)
})
if ($otherPollers.Count -gt 0) {
    throw 'Another Telegram listener was found. Check its project before starting a duplicate.'
}
$poller = $phpProcesses | Where-Object {
    $_.CommandLine -and $_.CommandLine.Contains($artisan) -and $_.CommandLine.Contains('telegram:poll')
} | Select-Object -First 1
$worker = $phpProcesses | Where-Object {
    $_.CommandLine -and $_.CommandLine.Contains($artisan) -and
    $_.CommandLine.Contains('queue:work') -and $_.CommandLine.Contains('--queue=notifications')
} | Select-Object -First 1

if ($CheckOnly) {
    Write-Host 'Local Telegram settings and current database are ready.'
    Write-Host ('Telegram listener running: ' + [bool]$poller)
    Write-Host ('Notification worker running: ' + [bool]$worker)
    return
}

if (-not $poller) {
    $poller = Start-Process -FilePath $php `
        -ArgumentList @('-d', 'extension=pdo_pgsql', ('"' + $artisan + '"'), 'telegram:poll') `
        -WorkingDirectory $appRoot -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $runtime ("telegram-poller-$stamp.log")) `
        -RedirectStandardError (Join-Path $runtime ("telegram-poller-$stamp-error.log"))
}
$pollerId = if ($poller -is [Diagnostics.Process]) { $poller.Id } else { $poller.ProcessId }
[IO.File]::WriteAllText((Join-Path $runtime 'telegram-poller.pid'), [string]$pollerId)

if (-not $worker) {
    $worker = Start-Process -FilePath $php `
        -ArgumentList @('-d', 'extension=pdo_pgsql', ('"' + $artisan + '"'), 'queue:work', '--queue=notifications', '--sleep=1', '--tries=1', '--timeout=25') `
        -WorkingDirectory $appRoot -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $runtime ("sms-worker-$stamp.log")) `
        -RedirectStandardError (Join-Path $runtime ("sms-worker-$stamp-error.log"))
}
$workerId = if ($worker -is [Diagnostics.Process]) { $worker.Id } else { $worker.ProcessId }
[IO.File]::WriteAllText((Join-Path $runtime 'sms-worker.pid'), [string]$workerId)
Start-Sleep -Seconds 1
if (-not (Get-Process -Id $pollerId -ErrorAction SilentlyContinue) -or
    -not (Get-Process -Id $workerId -ErrorAction SilentlyContinue)) {
    throw 'A Telegram service stopped during startup. Review the timestamped runtime logs.'
}
Write-Host 'Telegram listener and notification worker started.'
Write-Host 'Pending Start messages will be processed. Blocked incident alerts require review and retry in the dashboard.'
