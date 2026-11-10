param([string]$Compiler = '')
$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
if (-not $Compiler) {
    $installed = Get-Command zig -ErrorAction SilentlyContinue
    $Compiler = if ($installed) { $installed.Source } else { Join-Path $repo 'tool/runtime/host-compiler/python/ziglang/zig.exe' }
}
$binary = Join-Path $repo 'tool/runtime/remote_restart_test.exe'
foreach ($testName in @('remote_restart_test', 'gateway_restart_test')) {
    $binary = Join-Path $repo "tool/runtime/$testName.exe"
    & $Compiler c++ -std=c++17 -I (Join-Path $PSScriptRoot 'tests/native') -I (Join-Path $PSScriptRoot 'Arduino/shared') -I (Join-Path $PSScriptRoot 'Arduino/GatewayNode1') (Join-Path $PSScriptRoot "tests/native/$testName.cpp") -lbcrypt -o $binary
    if ($LASTEXITCODE -ne 0) { throw "$testName compilation failed." }
    & $binary
    if ($LASTEXITCODE -ne 0) { throw "$testName regression failed." }
}
