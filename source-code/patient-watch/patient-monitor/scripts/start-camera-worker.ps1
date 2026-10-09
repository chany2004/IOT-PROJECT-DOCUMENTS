param([string]$PhpPath)
$ErrorActionPreference = 'Stop'
$appRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$runtime = Join-Path $appRoot '.runtime'
$artisan = Join-Path $appRoot 'artisan'
if (-not (Test-Path -LiteralPath $artisan) -or -not (Test-Path -LiteralPath $runtime)) {
    throw 'Run local setup first. The existing application and database are required.'
}
if (-not $PhpPath) { $PhpPath = (Get-Command php -ErrorAction Stop).Source }
if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw 'PHP executable was not found.' }

# Discover the real process instead of relying on a stale PID file. Do not
# start Telegram/SMS workers or a second consumer of this capture queue.
$captureWorkers = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
    Where-Object {
        $_.CommandLine -and $_.CommandLine.Contains($artisan) -and
        $_.CommandLine -match '\bqueue:work\b' -and
        ($_.CommandLine -notmatch '--queue(?:=|\s)' -or
            $_.CommandLine -match '--queue(?:=|\s)default(?:\s|$)')
    })
if ($captureWorkers.Count -gt 1) { throw 'Multiple capture workers exist. Review them before starting another.' }
if ($captureWorkers.Count -eq 1) {
    [IO.File]::WriteAllText((Join-Path $runtime 'worker.pid'), [string]$captureWorkers[0].ProcessId)
    Write-Host ('Camera queue worker is already running (PID {0}).' -f $captureWorkers[0].ProcessId)
    return
}

# Enable GD in this process even when php.ini does not load it. It validates
# complete JPEGs; removing validation would hide corrupt camera frames.
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss-fff'
$workerLog = Join-Path $runtime ('camera-worker-' + $stamp + '.log')
$workerErrorLog = Join-Path $runtime ('camera-worker-' + $stamp + '-error.log')
$worker = Start-Process -FilePath $PhpPath -ArgumentList @(
    '-d', 'extension=pdo_pgsql', '-d', 'extension=gd',
    ('"' + $artisan + '"'), 'queue:work', '--queue=default',
    '--sleep=1', '--tries=1', '--timeout=70'
) -WorkingDirectory $appRoot -WindowStyle Hidden -PassThru `
    -RedirectStandardOutput $workerLog -RedirectStandardError $workerErrorLog
Start-Sleep -Milliseconds 700
$worker.Refresh()
if ($worker.HasExited) { throw 'Camera worker did not stay running. Review its private runtime logs.' }
[IO.File]::WriteAllText((Join-Path $runtime 'worker.pid'), [string]$worker.Id)
Write-Host ('Camera queue worker started (PID {0}); JPEG validation enabled.' -f $worker.Id)
