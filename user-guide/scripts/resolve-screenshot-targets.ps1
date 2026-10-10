$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$db = Join-Path $root 'tmp\user-guide-demo.sqlite'
Set-Location $root
if (-not (Test-Path $db)) { throw 'Missing tmp/user-guide-demo.sqlite. Run docs:demo:prepare first.' }
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
php user-guide\scripts\resolve-capture-targets.php --write
exit $LASTEXITCODE
