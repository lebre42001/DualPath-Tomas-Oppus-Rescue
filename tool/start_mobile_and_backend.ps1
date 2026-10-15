param(
  [ValidateSet('windows', 'android-emulator', 'android-phone-usb', 'android-phone-wifi')]
  [string]$Target = 'android-phone-usb',
  [string]$DeviceId = '',
  [string]$HostIp = '',
  [switch]$SkipHealthChecks
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$backendScript = Join-Path $PSScriptRoot 'start_stitch_services.ps1'
$mobileHelper = Join-Path $root 'mobile_app\tool\run_stitch.ps1'
$mobileAppDir = Join-Path $root 'mobile_app'

if (-not (Test-Path $backendScript)) {
  throw 'start_stitch_services.ps1 was not found.'
}

if (-not (Test-Path $mobileHelper)) {
  throw 'mobile_app\\tool\\run_stitch.ps1 was not found.'
}

$backendArguments = @{}
if ($SkipHealthChecks) {
  $backendArguments['SkipHealthChecks'] = $true
}

& $backendScript @backendArguments

Write-Host ''
Write-Host 'Launching mobile app'
Write-Host '--------------------'

$mobileArguments = @{
  Target = $Target
}

if ($DeviceId) {
  $mobileArguments['DeviceId'] = $DeviceId
}

if ($HostIp) {
  $mobileArguments['HostIp'] = $HostIp
}

Push-Location $mobileAppDir
try {
  & $mobileHelper @mobileArguments
} finally {
  Pop-Location
}
