param(
  [ValidateSet('windows', 'android-emulator', 'android-phone-usb', 'android-phone-wifi')]
  [string]$Target = 'windows',
  [string]$DeviceId = '',
  [string]$HostIp = ''
)

$ErrorActionPreference = 'Stop'

function Resolve-FlutterPath {
  $defaultFlutter = 'C:\laragon\flutter\flutter\bin\flutter.bat'
  if (Test-Path $defaultFlutter) {
    return $defaultFlutter
  }

  $flutterCommand = Get-Command flutter -ErrorAction SilentlyContinue
  if ($flutterCommand) {
    return $flutterCommand.Source
  }

  throw 'flutter.bat was not found. Install Flutter or update this script path.'
}

function Resolve-AdbPath {
  $adbCommand = Get-Command adb -ErrorAction SilentlyContinue
  if ($adbCommand) {
    return $adbCommand.Source
  }

  $localAdb = Join-Path $env:LOCALAPPDATA 'Android\Sdk\platform-tools\adb.exe'
  if (Test-Path $localAdb) {
    return $localAdb
  }

  throw 'adb.exe was not found. Install Android platform-tools or add adb to PATH.'
}

function Resolve-LanIpv4 {
  $address = Get-NetIPAddress -AddressFamily IPv4 |
    Where-Object {
      $_.IPAddress -ne '127.0.0.1' -and
      $_.IPAddress -notlike '169.254.*' -and
      $_.InterfaceAlias -notmatch 'Loopback|Virtual|Bluetooth|vEthernet'
    } |
    Select-Object -First 1 -ExpandProperty IPAddress

  if (-not $address) {
    throw 'No LAN IPv4 address was detected for Android phone Wi-Fi mode.'
  }

  return $address
}

$flutter = Resolve-FlutterPath
$apiUrl = ''
$arguments = @('run')

switch ($Target) {
  'windows' {
    $apiUrl = 'http://127.0.0.1:8000/api/v1'
    $arguments += @('-d', 'windows')
  }
  'android-emulator' {
    $apiUrl = 'http://10.0.2.2:8000/api/v1'
  }
  'android-phone-usb' {
    $adb = Resolve-AdbPath
    & $adb reverse tcp:8000 tcp:8000 | Out-Null
    $apiUrl = 'http://127.0.0.1:8000/api/v1'
  }
  'android-phone-wifi' {
    if (-not $HostIp) {
      $HostIp = Resolve-LanIpv4
    }

    $apiUrl = "http://$HostIp:8000/api/v1"
  }
}

$arguments += "--dart-define=STITCH_API_BASE_URL=$apiUrl"

if ($DeviceId) {
  $arguments += @('-d', $DeviceId)
}

Write-Host "Target   : $Target"
Write-Host "API Host : $apiUrl"
if ($DeviceId) {
  Write-Host "Device   : $DeviceId"
}

& $flutter @arguments
exit $LASTEXITCODE
