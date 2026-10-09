param([string]$PgBin = 'C:\Program Files\PostgreSQL\18\bin')
$ErrorActionPreference = 'Stop'
$appRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$runtime = Join-Path $appRoot '.runtime'
$dataDir = Join-Path $runtime 'postgres'
New-Item -ItemType Directory -Path $runtime -Force | Out-Null
$php = (Get-Command php -ErrorAction Stop).Source
if (-not (Test-Path -LiteralPath (Join-Path $PgBin 'initdb.exe'))) { throw 'PostgreSQL binaries missing. Pass -PgBin with their folder.' }
if (-not (Test-Path -LiteralPath (Join-Path $appRoot 'vendor\autoload.php'))) { throw 'Run composer install in patient-monitor first.' }
function New-MonitorSecret {
    $bytes = New-Object byte[] 32
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
}
function Write-MonitorText([string]$path, [string]$value) {
    [IO.File]::WriteAllText($path, $value, [Text.UTF8Encoding]::new($false))
}
$bootstrapPasswordPath = Join-Path $runtime 'postgres-bootstrap-password.txt'
if (-not (Test-Path -LiteralPath $bootstrapPasswordPath)) {
    if (Test-Path -LiteralPath (Join-Path $dataDir 'PG_VERSION')) { throw 'Existing cluster credentials missing. No credentials were changed.' }
    Write-MonitorText $bootstrapPasswordPath (New-MonitorSecret)
}
$bootstrapPassword = [IO.File]::ReadAllText($bootstrapPasswordPath).Trim()
if (-not (Test-Path -LiteralPath (Join-Path $dataDir 'PG_VERSION'))) {
    & (Join-Path $PgBin 'initdb.exe') -D $dataDir -U monitor_bootstrap --encoding=UTF8 --locale=C --auth=scram-sha-256 "--pwfile=$bootstrapPasswordPath"
    if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL cluster initialization failed.' }
}
Write-MonitorText (Join-Path $runtime 'pg-bin.txt') $PgBin
& (Join-Path $PgBin 'pg_ctl.exe') status -D $dataDir *> $null
if ($LASTEXITCODE -ne 0) {
    if (Get-NetTCPConnection -LocalPort 55432 -State Listen -ErrorAction SilentlyContinue) { throw 'Port 55432 is in use by another process.' }
    & (Join-Path $PgBin 'pg_ctl.exe') -D $dataDir -l (Join-Path $runtime 'postgres.log') -o '-p 55432 -h 127.0.0.1' -w start
    if ($LASTEXITCODE -ne 0) { throw 'The local PostgreSQL cluster could not start.' }
}
$appPasswordPath = Join-Path $runtime 'postgres-app-password.txt'
if (-not (Test-Path -LiteralPath $appPasswordPath)) { Write-MonitorText $appPasswordPath (New-MonitorSecret) }
$appPassword = [IO.File]::ReadAllText($appPasswordPath).Trim()
$priorPassword = $env:PGPASSWORD
try {
    $env:PGPASSWORD = $bootstrapPassword
    $psql = Join-Path $PgBin 'psql.exe'
    $pgArgs = @('-h', '127.0.0.1', '-p', '55432', '-U', 'monitor_bootstrap', '-d', 'postgres', '-v', 'ON_ERROR_STOP=1', '-w')
    $roleExists = & $psql @pgArgs -tAc "SELECT 1 FROM pg_roles WHERE rolname='patient_monitor_app'"
    if ($LASTEXITCODE -ne 0) { throw 'Unable to connect to the isolated PostgreSQL cluster.' }
    if ($roleExists -ne '1') {
        "CREATE ROLE patient_monitor_app LOGIN PASSWORD '$appPassword';" | & $psql @pgArgs
        if ($LASTEXITCODE -ne 0) { throw 'Unable to create application role.' }
    }
    foreach ($database in @('patient_monitor', 'patient_monitor_test')) {
        $exists = & $psql @pgArgs -tAc "SELECT 1 FROM pg_database WHERE datname='$database'"
        if ($exists -ne '1') {
            & $psql @pgArgs -c "CREATE DATABASE $database OWNER patient_monitor_app;"
            if ($LASTEXITCODE -ne 0) { throw "Could not create $database." }
        }
    }
} finally { $env:PGPASSWORD = $priorPassword }
$envPath = Join-Path $appRoot '.env'
if (-not (Test-Path -LiteralPath $envPath)) {
    $appKey = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes((New-MonitorSecret).Substring(0,32)))
    $environment = @"
APP_NAME="Patient Watch"
APP_ENV=local
APP_KEY=base64:$appKey
APP_DEBUG=false
APP_URL=http://127.0.0.1:8085
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=55432
DB_DATABASE=patient_monitor
DB_USERNAME=patient_monitor_app
DB_PASSWORD=$appPassword
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
CACHE_STORE=file
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
LOG_CHANNEL=single
MAIL_MAILER=log
CAMERA_VERIFIER_URL=http://127.0.0.1:8000/verify-fall
CAMERA_VERIFIER_KEY=
"@
    Write-MonitorText $envPath $environment
}
$fastApiEnvPath = Join-Path (Split-Path $appRoot -Parent) '.env'
if (Test-Path -LiteralPath $fastApiEnvPath) {
    $fastApiLine = Select-String -LiteralPath $fastApiEnvPath -Pattern '^FALL_API_KEY=(.+)$' | Select-Object -First 1
    if ($fastApiLine) {
        $fastApiKey = $fastApiLine.Matches[0].Groups[1].Value.Trim().Trim('"')
        if ($fastApiKey.Length -ge 32) {
            $laravelEnv = [IO.File]::ReadAllText($envPath)
            if ($laravelEnv -match '(?m)^CAMERA_VERIFIER_KEY=.*$') {
                $laravelEnv = [Text.RegularExpressions.Regex]::Replace($laravelEnv, '(?m)^CAMERA_VERIFIER_KEY=.*$', 'CAMERA_VERIFIER_KEY='+$fastApiKey)
            } else {
                $laravelEnv += [Environment]::NewLine+'CAMERA_VERIFIER_KEY='+$fastApiKey+[Environment]::NewLine
            }
            Write-MonitorText $envPath $laravelEnv
        }
    }
}
$testEnv = @"
APP_NAME="Patient Watch Test"
APP_ENV=testing
APP_KEY=base64:$([Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes((New-MonitorSecret).Substring(0,32))))
APP_DEBUG=true
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=55432
DB_DATABASE=patient_monitor_test
DB_USERNAME=patient_monitor_app
DB_PASSWORD=$appPassword
SESSION_DRIVER=array
CACHE_STORE=array
QUEUE_CONNECTION=sync
"@
if (-not (Test-Path -LiteralPath (Join-Path $appRoot '.env.testing'))) { Write-MonitorText (Join-Path $appRoot '.env.testing') $testEnv }
Push-Location $appRoot
try {
    & $php -d extension=pdo_pgsql artisan package:discover --ansi
    if ($LASTEXITCODE -ne 0) { throw 'Laravel package discovery failed.' }
    & $php -d extension=pdo_pgsql artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'Database migration failed.' }
    & $php -d extension=pdo_pgsql artisan monitor:local-setup
    if ($LASTEXITCODE -ne 0) { throw 'Initial account creation failed.' }
} finally { Pop-Location }
Write-Host 'Setup complete. Start with .\scripts\start-local.ps1'
Write-Host 'Private logins: patient-monitor\.runtime\local-access.txt'
