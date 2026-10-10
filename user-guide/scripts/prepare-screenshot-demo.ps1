$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$db = Join-Path $root 'tmp\user-guide-demo.sqlite'
Set-Location $root
New-Item -ItemType Directory -Path (Split-Path $db) -Force | Out-Null
if (-not (Test-Path $db)) { New-Item -ItemType File -Path $db -Force | Out-Null }
# Never delete or reset the SQLite file before effective connection validation.
$env:APP_ENV = 'guide-qa'
$env:APP_URL = 'http://127.0.0.1:8011'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $db
$env:DB_FOREIGN_KEYS = 'true'
$env:CACHE_STORE = 'array'
$env:SESSION_DRIVER = 'file'
$env:SESSION_COOKIE = 'ierp_guide_demo_session'
$env:QUEUE_CONNECTION = 'sync'
$env:MAIL_MAILER = 'array'
$env:BROADCAST_CONNECTION = 'null'
$env:PULSE_ENABLED = 'false'
$env:TELESCOPE_ENABLED = 'false'
$env:NIGHTWATCH_ENABLED = 'false'
$env:EMPLOYEES_TRANSCRIBE_DRIVER = 'fake'
php scripts\qa-guard.php $root $db
if ($LASTEXITCODE -ne 0) { throw 'Screenshot demo SQLite guard failed.' }
# Guard validated the exact path; migrate:fresh rebuilds this file in place.
Write-Host "Preparing isolated documentation database: $db"
php artisan migrate:fresh --force
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
php artisan db:seed --class='Database\Seeders\Demo\DemoMonthSeeder' --force
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
php user-guide\scripts\resolve-capture-targets.php --write
exit $LASTEXITCODE
