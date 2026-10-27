param(
    [string]$TemplatePath = (Join-Path $PSScriptRoot 'Arduino\FieldNode\FieldNode.ino'),
    [string]$OutputRoot = (Join-Path $PSScriptRoot 'Arduino\FieldNodes')
)

$ErrorActionPreference = 'Stop'

# Check configuration headers without ever printing their contents or secrets.
# A sketch pasted into a secrets header can recursively include itself.
function Assert-FirmwareHeader {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [string[]]$IncludeStack = @()
    )

    $resolvedPath = (Resolve-Path -LiteralPath $Path).Path
    if ($IncludeStack -contains $resolvedPath) {
        throw "Circular header include detected at $resolvedPath. Restore the configuration header; do not paste sketch code into it."
    }

    $header = Get-Content -Raw -LiteralPath $resolvedPath
    if ($header -notmatch '(?m)^\s*#pragma\s+once\s*$' -or
        $header -match '\bvoid\s+(setup|loop)\s*\(') {
        throw "Invalid firmware header: $resolvedPath. Headers need #pragma once and must not contain setup() or loop()."
    }

    $nextStack = @($IncludeStack) + $resolvedPath
    foreach ($include in [regex]::Matches($header, '(?m)^\s*#\s*include\s*"([^"]+)"')) {
        $includedPath = Join-Path (Split-Path -Parent $resolvedPath) $include.Groups[1].Value
        if (-not (Test-Path -LiteralPath $includedPath)) {
            throw "Missing local header referenced by $resolvedPath."
        }
        Assert-FirmwareHeader -Path $includedPath -IncludeStack $nextStack
    }

}

# Field APs are open; remove only the two retired password-header filenames.
# Gateway credentials are separate and are never passed to this cleanup.
function Remove-FieldNodePasswordHeaders {
    param([Parameter(Mandatory = $true)][string]$SketchDirectory)
    foreach ($name in @('field_node_secrets.h', 'field_node_secrets.example.h')) {
        $path = Join-Path $SketchDirectory $name
        if (Test-Path -LiteralPath $path) {
            Remove-Item -LiteralPath $path -Force
        }
    }
}

if (-not (Test-Path -LiteralPath $TemplatePath)) {
    throw "Field-node template not found: $TemplatePath"
}

$template = Get-Content -Raw -LiteralPath $TemplatePath
$nodeNumberPattern = 'const int NODE_NUMBER = 2;'
$templateDirectory = Split-Path -Parent $TemplatePath
$sharedDirectory = Join-Path $PSScriptRoot 'Arduino\shared'

# Validate configured source headers before overwriting any generated file.
# Shared battery headers are checked below in their uploadable sketch folders.
$sourceDirectories = @($templateDirectory, (Join-Path $PSScriptRoot 'Arduino\GatewayNode1'))
foreach ($sourceDirectory in $sourceDirectories) {
    foreach ($header in Get-ChildItem -LiteralPath $sourceDirectory -Filter '*.h' -File) {
        if ($header.Name -in @('field_node_secrets.h', 'field_node_secrets.example.h')) { continue }
        Assert-FirmwareHeader -Path $header.FullName
    }
}

if ([regex]::Matches($template, [regex]::Escape($nodeNumberPattern)).Count -ne 1) {
    throw 'Expected exactly one NODE_NUMBER declaration in the field-node template.'
}

Remove-FieldNodePasswordHeaders -SketchDirectory $templateDirectory

# Keep the measurement implementation identical in every uploadable sketch.
# Preserve each board's wiring/calibration configuration on regeneration.
$hardwareDirectories = @($templateDirectory, (Join-Path $PSScriptRoot 'Arduino\GatewayNode1'))
$hardwareDirectories += 2..10 | ForEach-Object { Join-Path $OutputRoot "NODE_$($_)_Field_Node" }
foreach ($hardwareDirectory in $hardwareDirectories) {
    New-Item -ItemType Directory -Force -Path $hardwareDirectory | Out-Null
    Copy-Item -LiteralPath (Join-Path $sharedDirectory 'battery_monitor.h') -Destination (Join-Path $hardwareDirectory 'battery_monitor.h') -Force
    $hardwareConfig = Join-Path $hardwareDirectory 'battery_config.h'
    if (-not (Test-Path -LiteralPath $hardwareConfig)) {
        Copy-Item -LiteralPath (Join-Path $sharedDirectory 'battery_config.example.h') -Destination $hardwareConfig
    }
}

foreach ($nodeNumber in 2..10) {
    $sketchName = "NODE_${nodeNumber}_Field_Node"
    $sketchDirectory = Join-Path $OutputRoot $sketchName
    $sketchPath = Join-Path $sketchDirectory "$sketchName.ino"
    New-Item -ItemType Directory -Force -Path $sketchDirectory | Out-Null

    $nodeSource = $template.Replace(
        $nodeNumberPattern,
        "const int NODE_NUMBER = $nodeNumber;"
    )

    [IO.File]::WriteAllText($sketchPath, $nodeSource, [Text.UTF8Encoding]::new($false))

    Remove-FieldNodePasswordHeaders -SketchDirectory $sketchDirectory

    foreach ($header in Get-ChildItem -LiteralPath $sketchDirectory -Filter '*.h' -File) {
        Assert-FirmwareHeader -Path $header.FullName
    }

    Write-Host "Generated $sketchPath"
}
