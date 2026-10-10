param(
  [string] $BindHost = '0.0.0.0',
  [switch] $Stop
)
$ErrorActionPreference = 'Stop'
Set-Location 'C:\laragon\www\ierp-new'
$port = 8098

if ($Stop) {
  # Only stop a PHP built-in server created for this project and host/port.
  $owned = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
    Where-Object {
      $_.CommandLine -like "*-S ${BindHost}:${port}*" -and
      $_.CommandLine -like '*ierp-new*' -and
      $_.CommandLine -like '*server.php*'
    })
  foreach ($item in $owned) {
    Stop-Process -Id $item.ProcessId -Force -ErrorAction SilentlyContinue
  }
  exit 0
}

$php = (Get-Command php -ErrorAction Stop).Source
$router = (Resolve-Path 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php').Path
$public = (Resolve-Path 'public').Path
# Laravel's router script expects index.php in the process working directory.
Set-Location $public
& $php -S "${BindHost}:${port}" -t $public $router
exit $LASTEXITCODE
