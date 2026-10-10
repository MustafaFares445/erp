param(
    [ValidateSet('snapshot','verify','prepare','provision-admin','serve','stop','status')][string] $Action = 'verify',
    [ValidatePattern('^worker-0[1-8]$')][string] $Worker = 'worker-01',
    [string] $BindHost = '127.0.0.1'
)
$ErrorActionPreference = 'Stop'
$scriptHome = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$insideWorker = ($scriptHome -match '[\\/]tmp[\\/]qa-workspaces[\\/]worker-0[1-8]$')
if ($insideWorker -and $Action -eq 'snapshot') { throw 'Create snapshots only from the source checkout.' }
$source = if ($insideWorker) { (Resolve-Path (Join-Path $scriptHome '..\..\..')).Path } else { $scriptHome }
$workspace = Join-Path $source "tmp\qa-workspaces\$Worker"
$index = [int]$Worker.Substring(7)
$proxyPort = 8110 + $index
$port = 9110 + $index
$db = Join-Path $workspace 'tmp\e2e.sqlite'
$pidFile = Join-Path $workspace 'tmp\php-server.pid'
$secretFile = Join-Path $workspace '.qa-admin-password'
$logDir = Join-Path $workspace 'tmp\qa-logs'

function New-Secret([int] $Length) {
    $bytes = New-Object byte[] $Length
    $random = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $random.GetBytes($bytes) } finally { $random.Dispose() }
    return [Convert]::ToBase64String($bytes)
}

function Set-WorkerEnvironment {
    $env:APP_ENV = 'e2e'
    $env:APP_URL = "http://127.0.0.1:$proxyPort"
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = $db.Replace('\', '/')
    $env:DB_URL = ''
    $env:DATABASE_URL = ''
    # Other Laravel connection profiles must not be able to reach local MySQL.
    $env:DB_HOST = '127.0.0.1'
    $env:DB_PORT = '1'
    $env:DB_USERNAME = 'qa_forbidden'
    $env:DB_PASSWORD = (New-Secret 32)
    $env:APP_KEY = ((Get-Content (Join-Path $workspace '.env') | Where-Object { $_.StartsWith('APP_KEY=') } | Select-Object -First 1) -replace '^APP_KEY=', '')
    $env:DB_FOREIGN_KEYS = 'true'
    $env:CACHE_STORE = 'file'
    $env:SESSION_DRIVER = 'file'
    $env:SESSION_COOKIE = "ierp_qa_$($Worker.Replace('-','_'))"
    $env:QUEUE_CONNECTION = 'sync'
    $env:MAIL_MAILER = 'array'
    $env:BROADCAST_CONNECTION = 'null'
    $env:PULSE_ENABLED = 'false'
    $env:TELESCOPE_ENABLED = 'false'
    $env:NIGHTWATCH_ENABLED = 'false'
    $env:EMPLOYEES_TRANSCRIBE_DRIVER = 'fake'
    $env:STRIPE_KEY = ''
    $env:STRIPE_SECRET = ''
    $env:STRIPE_WEBHOOK_SECRET = ''
}

function Assert-Workspace {
    if (-not (Test-Path (Join-Path $workspace '.env'))) { throw "Missing isolated snapshot: $workspace" }
    if (-not (Test-Path $db)) { throw "Missing isolated database: $db" }
    if (Test-Path (Join-Path $workspace 'bootstrap\cache\config.php')) { throw 'Cached config is forbidden for QA.' }
    $expected = (Resolve-Path $workspace).Path
    if ($expected -eq $source -or -not $expected.StartsWith((Join-Path $source 'tmp\qa-workspaces\'), [System.StringComparison]::OrdinalIgnoreCase)) {
        throw 'Workspace path validation failed.'
    }
    Set-Location $workspace
    Set-WorkerEnvironment
    & php (Join-Path $workspace 'scripts\qa-guard.php') $workspace $db
    if ($LASTEXITCODE -ne 0) { throw 'Effective SQLite connection validation failed.' }
}

if ($Action -eq 'snapshot') {
    if (Test-Path $workspace) { throw "Snapshot already exists; refusing to overwrite: $workspace" }
    New-Item -ItemType Directory -Path $workspace -Force | Out-Null
    $excludeDirs = @(
        (Join-Path $source '.git'), (Join-Path $source 'tmp'),
        (Join-Path $source 'vendor'), (Join-Path $source 'node_modules'),
        (Join-Path $source 'storage'), (Join-Path $source '.e2e'),
        (Join-Path $source 'user-guide\node_modules'),
        (Join-Path $source 'user-guide\.vitepress\cache'),
        (Join-Path $source 'user-guide\.vitepress\dist'),
        (Join-Path $source '.claude\worktrees'),
        (Join-Path $source 'public\guide')
    )
    & robocopy $source $workspace /E /XJ /R:1 /W:1 /NP /NFL /NDL /NJH /NJS /XD $excludeDirs /XF '.env*'
    if ($LASTEXITCODE -gt 7) { throw "Snapshot copy failed (robocopy $LASTEXITCODE)." }
    foreach ($sentinel in @('AGENTS.md','app\Models\User.php','user-guide\scripts\capture-screenshots.mjs','Docs\onboarding\USER_GUIDE_MAINTENANCE.md','.agents\skills\e2e\SKILL.md','tests\admin-access.e2e.ts','scripts\qa-guard.php')) {
        $from = Join-Path $source $sentinel
        $to = Join-Path $workspace $sentinel
        if (-not (Test-Path $to) -or (Get-FileHash $from).Hash -ne (Get-FileHash $to).Hash) {
            throw "Incomplete or inconsistent snapshot at $sentinel"
        }
    }
    New-Item -ItemType Junction -Path (Join-Path $workspace 'vendor') -Target (Join-Path $source 'vendor') | Out-Null
    New-Item -ItemType Junction -Path (Join-Path $workspace 'node_modules') -Target (Join-Path $source 'node_modules') | Out-Null
    foreach ($dir in @('tmp','tmp\qa-logs','storage\framework\cache\data','storage\framework\sessions','storage\framework\views','storage\logs','bootstrap\cache','.e2e\logs','.e2e\artifacts','tmp\staged-screenshots')) {
        New-Item -ItemType Directory -Path (Join-Path $workspace $dir) -Force | Out-Null
    }
    New-Item -ItemType File -Path $db -Force | Out-Null
    $key = New-Secret 32
    $lines = @(
        'APP_NAME="IERP QA Worker"', 'APP_ENV=e2e', 'APP_DEBUG=true',
        "APP_KEY=base64:$key", "APP_URL=http://127.0.0.1:$proxyPort",
        'DB_CONNECTION=sqlite', "DB_DATABASE=$($db.Replace('\','/'))", 'DB_URL=',
        'DB_HOST=127.0.0.1', 'DB_PORT=1', 'DB_USERNAME=qa_forbidden', 'DB_PASSWORD=disabled',
        'DB_FOREIGN_KEYS=true', 'CACHE_STORE=file', 'SESSION_DRIVER=file',
        "SESSION_COOKIE=ierp_qa_$($Worker.Replace('-','_'))",
        'QUEUE_CONNECTION=sync', 'MAIL_MAILER=array', 'BROADCAST_CONNECTION=null',
        'LOG_CHANNEL=single', 'FILESYSTEM_DISK=local', 'STRIPE_KEY=',
        'STRIPE_SECRET=', 'STRIPE_WEBHOOK_SECRET=', 'EMPLOYEES_TRANSCRIBE_DRIVER=fake',
        'PULSE_ENABLED=false', 'TELESCOPE_ENABLED=false', 'NIGHTWATCH_ENABLED=false'
    )
    [System.IO.File]::WriteAllLines((Join-Path $workspace '.env'), $lines, (New-Object System.Text.UTF8Encoding($false)))
    Write-Output "SNAPSHOT_READY workspace=$workspace proxyPort=$proxyPort phpPort=$port (tracked, modified, untracked files copied)"
    exit 0
}

if ($Action -eq 'stop' -or $Action -eq 'status') {
    if (-not (Test-Path $pidFile)) {
        if ($Action -eq 'status') { Write-Output 'SERVER_NOT_RUNNING'; exit 0 }
        Write-Output 'NO_OWNED_SERVER'; exit 0
    }
    $serverPid = [int](Get-Content $pidFile -Raw).Trim()
    $proc = Get-CimInstance Win32_Process -Filter "ProcessId=$serverPid" -ErrorAction SilentlyContinue
    if ($null -eq $proc) {
        Remove-Item $pidFile -Force
        Write-Output 'STALE_SERVER_PID_CLEARED'
        exit 0
    }
    $signature = "-S $($BindHost):$port"
    if ($proc.Name -ne 'php.exe' -or -not $proc.CommandLine.Contains($signature) -or
        -not $proc.CommandLine.Contains((Join-Path $workspace 'public'))) {
        throw "Refusing to stop unowned PID $serverPid."
    }
    if ($Action -eq 'status') { Write-Output "OWNED_SERVER_RUNNING pid=$serverPid port=$port"; exit 0 }
    Stop-Process -Id $serverPid -Force
    Remove-Item $pidFile -Force
    Write-Output "OWNED_SERVER_STOPPED pid=$serverPid"
    exit 0
}

Assert-Workspace
if ($Action -eq 'verify') { Write-Output "WORKER_SAFE workspace=$workspace proxyPort=$proxyPort phpPort=$port"; exit 0 }

if ($Action -eq 'prepare') {
    if (Test-Path $pidFile) { throw 'Stop the worker server before initializing its database.' }
    & php artisan migrate:fresh --force --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Isolated migration failed.' }
    Assert-Workspace
    & php artisan db:seed '--class=Database\Seeders\Demo\DemoMonthSeeder' --force --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Isolated demo seed failed.' }
    Assert-Workspace
    & $PSCommandPath -Action provision-admin -Worker $Worker -BindHost $BindHost
    if ($LASTEXITCODE -ne 0) { throw 'Worker administrator provisioning failed.' }
    Write-Output "DEMO_READY database=$db (worker administrator provisioned privately)"
    exit 0
}

if ($Action -eq 'provision-admin') {
    if (Test-Path $pidFile) { throw 'Stop worker PHP before admin provisioning.' }
    $secretDir = Join-Path $workspace 'tmp\qa-secrets'
    New-Item -ItemType Directory -Path $secretDir -Force | Out-Null
    $passwordFile = Join-Path $secretDir 'admin-password'
    if (-not (Test-Path $passwordFile)) {
        $randomValue = New-Secret 48
        [System.IO.File]::WriteAllText($passwordFile, $randomValue, (New-Object System.Text.UTF8Encoding($false)))
    }
    & php (Join-Path $workspace 'scripts\qa-provision-admin.php')
    if ($LASTEXITCODE -ne 0) { throw 'Worker administrator rotation failed.' }
    Write-Output 'WORKER_ADMIN_READY'
    exit 0
}

if ($Action -eq 'serve') {
    if (Test-Path $pidFile) { throw 'Worker PHP server PID exists. Stop or inspect first.' }
    if (@(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue).Count -ne 0) {
        throw "Port $port occupied; refusing to attach or replace another process."
    }
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    $php = (Get-Command php -ErrorAction Stop).Source
    $router = (Resolve-Path (Join-Path $workspace 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php')).Path
    $public = Join-Path $workspace 'public'
    $started = Start-Process -FilePath $php -ArgumentList @('-S', "$($BindHost):$port", '-t', ('"' + $public + '"'), ('"' + $router + '"')) -WorkingDirectory $public -RedirectStandardOutput (Join-Path $logDir 'php-out.log') -RedirectStandardError (Join-Path $logDir 'php-err.log') -PassThru -WindowStyle Hidden
    [System.IO.File]::WriteAllText($pidFile, [string]$started.Id)
    Write-Output "OWNED_SERVER_STARTED pid=$($started.Id) port=$port"
    exit 0
}
