param([switch]$Lan)
$ErrorActionPreference = 'Stop'
$appRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$runtime = Join-Path $appRoot '.runtime'
if (-not (Test-Path -LiteralPath (Join-Path $runtime 'pg-bin.txt'))) { throw 'Run scripts\setup-local.ps1 first.' }
$pgBin = [IO.File]::ReadAllText((Join-Path $runtime 'pg-bin.txt')).Trim()
$dataDir = Join-Path $runtime 'postgres'
& (Join-Path $pgBin 'pg_ctl.exe') status -D $dataDir *> $null
if ($LASTEXITCODE -ne 0) {
    if (Get-NetTCPConnection -LocalPort 55432 -State Listen -ErrorAction SilentlyContinue) { throw 'Another process occupies port 55432.' }
    & (Join-Path $pgBin 'pg_ctl.exe') -D $dataDir -l (Join-Path $runtime 'postgres.log') -o '-p 55432 -h 127.0.0.1' -w start
    if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL startup failed.' }
}
$php = (Get-Command php -ErrorAction Stop).Source
$projectRoot = Split-Path $appRoot -Parent
$python = Join-Path $projectRoot '.venv\Scripts\python.exe'
$verifierEnv = Join-Path $projectRoot '.env'
$verifierPidPath = Join-Path $runtime 'verifier.pid'
$verifierRunning = $false
if (Test-Path -LiteralPath $verifierPidPath) {
    $verifierId = [int][IO.File]::ReadAllText($verifierPidPath)
    $verifierInfo = Get-CimInstance Win32_Process -Filter "ProcessId=$verifierId"
    $verifierRunning = $verifierInfo -and $verifierInfo.CommandLine.Contains('uvicorn') -and $verifierInfo.CommandLine.Contains('backend.app:app')
}
if (-not $verifierRunning) {
    $verifierListener = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue
    if ($verifierListener) {
        $verifierOwner = Get-CimInstance Win32_Process -Filter "ProcessId=$($verifierListener[0].OwningProcess)"
        $verifierRunning = $verifierOwner -and $verifierOwner.CommandLine.Contains('backend.app:app')
        if ($verifierRunning) {
            [IO.File]::WriteAllText($verifierPidPath, [string]$verifierOwner.ProcessId)
        } else {
            Write-Warning 'Port 8000 is occupied by another process. Automated pose verification was not started.'
        }
    }
}
if (-not $verifierRunning -and -not $verifierListener) {
    if ((Test-Path -LiteralPath $python) -and (Test-Path -LiteralPath $verifierEnv)) {
        $verifier = Start-Process -FilePath $python -ArgumentList @('-m', 'uvicorn', 'backend.app:app', '--env-file', ('"' + $verifierEnv + '"'), '--host', '127.0.0.1', '--port', '8000') -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtime 'verifier.log') -RedirectStandardError (Join-Path $runtime 'verifier-error.log')
        [IO.File]::WriteAllText($verifierPidPath, [string]$verifier.Id)
        Write-Host 'Starting local camera pose verifier on http://127.0.0.1:8000'
    } else {
        Write-Warning 'Python environment or verifier .env is missing. Camera results will require human review.'
    }
}
$bind = if ($Lan) { '0.0.0.0' } else { '127.0.0.1' }
$router = Join-Path $appRoot 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
$webListener = Get-NetTCPConnection -LocalPort 8085 -State Listen -ErrorAction SilentlyContinue
if ($webListener) {
    $owner = Get-CimInstance Win32_Process -Filter "ProcessId = $($webListener[0].OwningProcess)"
    if (-not $owner -or -not $owner.CommandLine.Contains($router)) { throw 'Port 8085 is in use by a different application.' }
    $needsGdRestart = -not $owner.CommandLine.Contains('extension=gd')
    if (($Lan -and $webListener[0].LocalAddress -eq '127.0.0.1') -or $needsGdRestart) {
        if ($webListener[0].LocalAddress -eq '0.0.0.0') { $bind = '0.0.0.0' }
        Stop-Process -Id $owner.ProcessId -ErrorAction Stop
        $webListener = $null
        Write-Host 'Restarting this project web server with JPEG validation enabled.'
    } else {
        Write-Host 'Patient Watch web server is already running.'
    }
}
if (-not $webListener) {
    $web = Start-Process -FilePath $php -ArgumentList @('-d', 'extension=pdo_pgsql', '-d', 'extension=gd', '-S', ('{0}:8085' -f $bind), ('"' + $router + '"')) -WorkingDirectory (Join-Path $appRoot 'public') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtime 'web.log') -RedirectStandardError (Join-Path $runtime 'web-error.log')
    [IO.File]::WriteAllText((Join-Path $runtime 'web.pid'), [string]$web.Id)
}
& (Join-Path $PSScriptRoot 'start-camera-worker.ps1') -PhpPath $php
Write-Host 'Open http://127.0.0.1:8085'
$smsWorkerPidPath = Join-Path $runtime 'sms-worker.pid'
$smsWorkerRunning = $false
if (Test-Path -LiteralPath $smsWorkerPidPath) {
    $smsWorkerId = [int][IO.File]::ReadAllText($smsWorkerPidPath)
    $smsWorkerInfo = Get-CimInstance Win32_Process -Filter "ProcessId=$smsWorkerId"
    $smsWorkerRunning = $smsWorkerInfo -and $smsWorkerInfo.CommandLine.Contains((Join-Path $appRoot 'artisan')) -and $smsWorkerInfo.CommandLine.Contains('--queue=notifications')
}
if (-not $smsWorkerRunning) {
    # Keep confirmed-fall notifications independent of slow camera capture jobs.
    $smsWorker = Start-Process -FilePath $php -ArgumentList @('-d', 'extension=pdo_pgsql', ('"' + (Join-Path $appRoot 'artisan') + '"'), 'queue:work', '--queue=notifications', '--sleep=1', '--tries=1', '--timeout=25') -WorkingDirectory $appRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtime 'sms-worker.log') -RedirectStandardError (Join-Path $runtime 'sms-worker-error.log')
    [IO.File]::WriteAllText($smsWorkerPidPath, [string]$smsWorker.Id)
}
# Windows PowerShell treats native stderr as a terminating error under Stop.
# Capture PHP startup warnings separately so missing bot settings are harmless.
$telegramConfigCheck = Start-Process -FilePath $php -ArgumentList @(('"' + (Join-Path $appRoot 'artisan') + '"'), 'telegram:check', '--configured') -WorkingDirectory $appRoot -WindowStyle Hidden -Wait -PassThru -RedirectStandardOutput (Join-Path $runtime 'telegram-config-check.log') -RedirectStandardError (Join-Path $runtime 'telegram-config-check-error.log')
if ($telegramConfigCheck.ExitCode -eq 0) {
    $telegramPidPath = Join-Path $runtime 'telegram-poller.pid'
    $telegramRunning = $false
    if (Test-Path -LiteralPath $telegramPidPath) {
        $telegramId = [int][IO.File]::ReadAllText($telegramPidPath)
        $telegramInfo = Get-CimInstance Win32_Process -Filter "ProcessId=$telegramId"
        $telegramRunning = $telegramInfo -and $telegramInfo.CommandLine.Contains((Join-Path $appRoot 'artisan')) -and $telegramInfo.CommandLine.Contains('telegram:poll')
    }
    if (-not $telegramRunning) {
        $telegram = Start-Process -FilePath $php -ArgumentList @('-d', 'extension=pdo_pgsql', ('"' + (Join-Path $appRoot 'artisan') + '"'), 'telegram:poll') -WorkingDirectory $appRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtime 'telegram-poller.log') -RedirectStandardError (Join-Path $runtime 'telegram-poller-error.log')
        [IO.File]::WriteAllText($telegramPidPath, [string]$telegram.Id)
    }
} else {
    Write-Host 'Telegram setup pending: add bot token and username to patient-monitor\.env, then restart services.'
}
Write-Host 'Credentials: patient-monitor\.runtime\local-access.txt'
if ($Lan) { Write-Host 'LAN mode: devices use http://YOUR_PC_LAN_IP:8085/api/v1/events' }
