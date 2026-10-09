$ErrorActionPreference = 'Stop'
$appRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$runtime = Join-Path $appRoot '.runtime'
foreach ($kind in @('web', 'worker', 'sms-worker', 'telegram-poller')) {
    $pidPath = Join-Path $runtime ($kind + '.pid')
    if (Test-Path -LiteralPath $pidPath) {
        $processId = [int][IO.File]::ReadAllText($pidPath)
        $info = Get-CimInstance Win32_Process -Filter "ProcessId=$processId"
        $expected = if ($kind -eq 'web') { Join-Path $appRoot 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php' } else { Join-Path $appRoot 'artisan' }
        if ($info -and $info.CommandLine.Contains($expected)) { Stop-Process -Id $processId }
    }
}
$binFile = Join-Path $runtime 'pg-bin.txt'
if (Test-Path -LiteralPath $binFile) {
    $pgBin = [IO.File]::ReadAllText($binFile).Trim()
    $dataDir = Join-Path $runtime 'postgres'
    & (Join-Path $pgBin 'pg_ctl.exe') status -D $dataDir *> $null
    if ($LASTEXITCODE -eq 0) { & (Join-Path $pgBin 'pg_ctl.exe') -D $dataDir -m fast -w stop }
}
Write-Host 'Project services stopped. PostgreSQL data and existing Windows services are preserved.'
