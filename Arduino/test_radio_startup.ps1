param([string]$Compiler = '')
$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
if (-not $Compiler) {
    $installed = Get-Command zig -ErrorAction SilentlyContinue
    $Compiler = if ($installed) { $installed.Source } else { Join-Path $repo 'tool/runtime/host-compiler/python/ziglang/zig.exe' }
}
$runtime = Join-Path $repo 'tool/runtime'
[IO.Directory]::CreateDirectory($runtime) | Out-Null
foreach ($profile in 0..1) {
    $binary = Join-Path $runtime "radio_startup_profile$profile.exe"
    & $Compiler c++ -std=c++17 "-DAKSYRES_RADIO_PROFILE=$profile" -I (Join-Path $PSScriptRoot 'tests/native') -I (Join-Path $PSScriptRoot 'Arduino/shared') (Join-Path $PSScriptRoot 'tests/native/radio_profile_test.cpp') -o $binary
    if ($LASTEXITCODE -ne 0) { throw "Radio profile $profile compilation failed." }
    & $binary
    if ($LASTEXITCODE -ne 0) { throw "Radio profile $profile test failed." }
}
$binary = Join-Path $runtime 'radio_startup_port.exe'
& $Compiler c++ -std=c++17 -I (Join-Path $PSScriptRoot 'tests/native') -I (Join-Path $PSScriptRoot 'Arduino/shared') (Join-Path $PSScriptRoot 'tests/native/mesh_sx127x_test.cpp') -lbcrypt -o $binary
if ($LASTEXITCODE -ne 0) { throw 'Radio port compilation failed.' }
& $binary
if ($LASTEXITCODE -ne 0) { throw 'Radio port regression failed.' }
