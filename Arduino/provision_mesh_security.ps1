param([switch]$Rotate)
$ErrorActionPreference = 'Stop'
# This private backup is excluded from Git. Re-running preserves deployed keys.
$backup = Join-Path $PSScriptRoot '..\tool\runtime\mesh-security-keys.json'
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $backup) | Out-Null
function New-Key {
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
}
if ((Test-Path -LiteralPath $backup) -and -not $Rotate) {
    $keys = Get-Content -LiteralPath $backup -Raw | ConvertFrom-Json
} else {
    if ($Rotate -and (Test-Path -LiteralPath $backup)) {
        Copy-Item -LiteralPath $backup -Destination ($backup + '.' + (Get-Date -Format 'yyyyMMddHHmmss') + '.bak')
    }
    $keys = [ordered]@{ group = (New-Key); nodes = @((1..10 | ForEach-Object { New-Key })) }
    [IO.File]::WriteAllText($backup, ($keys | ConvertTo-Json -Depth 5), [Text.UTF8Encoding]::new($false))
}
function Key-Initializer([string]$hex) {
    if ($hex -notmatch '^[a-f0-9]{64}$') { throw 'Invalid mesh key format.' }
    return '{' + (([regex]::Matches($hex, '..') | ForEach-Object { '0x' + $_.Value }) -join ',') + '}'
}
if ($keys.group -notmatch '^[a-f0-9]{64}$' -or @($keys.nodes).Count -ne 10) { throw 'Invalid private mesh key backup.' }
foreach ($node in 1..10) {
    [string[]]$dirs = if ($node -eq 1) { @((Join-Path $PSScriptRoot 'Arduino\GatewayNode1')) }
        else { @((Join-Path $PSScriptRoot "Arduino\FieldNodes\NODE_${node}_Field_Node")) }
    if ($node -eq 2) { $dirs += Join-Path $PSScriptRoot 'Arduino\FieldNode' }
    $header = "#pragma once`n// LOCAL PRIVATE KEYS. Never share or commit this file.`n#define MESH_THIS_NODE $node`n"
    $header += 'static const uint8_t MESH_GROUP_KEY[32] = ' + (Key-Initializer $keys.group) + ";`n"
    if ($node -eq 1) {
        $header += "static const uint8_t MESH_ORIGIN_KEYS[10][32] = {`n"
        $header += (($keys.nodes | ForEach-Object { Key-Initializer $_ }) -join ",`n") + "};`n"
        $header += "static const uint8_t* meshOriginKey(int n) { return n >= 1 && n <= 10 ? MESH_ORIGIN_KEYS[n-1] : nullptr; }`n"
    } else {
        $header += 'static const uint8_t MESH_OWN_KEY[32] = ' + (Key-Initializer $keys.nodes[$node-1]) + ";`n"
        $header += "static const uint8_t* meshOriginKey(int n) { return n == MESH_THIS_NODE ? MESH_OWN_KEY : nullptr; }`n"
    }
    foreach ($dir in $dirs) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
        [IO.File]::WriteAllText((Join-Path $dir 'mesh_secrets.h'), $header, [Text.UTF8Encoding]::new($false))
    }
}
Write-Host 'Private mesh keys provisioned locally for NODE_1 through NODE_10. Existing keys preserved unless -Rotate was used.'
