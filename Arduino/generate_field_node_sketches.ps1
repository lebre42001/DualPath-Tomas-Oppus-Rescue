param(
    [string]$TemplatePath = (Join-Path $PSScriptRoot 'Arduino\FieldNode\FieldNode.ino'),
    [string]$OutputRoot = (Join-Path $PSScriptRoot 'Arduino\FieldNodes')
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $TemplatePath)) {
    throw "Field-node template not found: $TemplatePath"
}

$template = Get-Content -Raw -LiteralPath $TemplatePath
$nodeNumberPattern = 'const int NODE_NUMBER = 2;'
$templateDirectory = Split-Path -Parent $TemplatePath
$exampleSecretsPath = Join-Path $templateDirectory 'field_node_secrets.example.h'
$localSecretsPath = Join-Path $templateDirectory 'field_node_secrets.h'

if (-not $template.Contains($nodeNumberPattern)) {
    throw 'Expected NODE_NUMBER declaration was not found in the field-node template.'
}

if (-not (Test-Path -LiteralPath $exampleSecretsPath)) {
    throw "Field-node example secrets file not found: $exampleSecretsPath"
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

    Copy-Item -LiteralPath $exampleSecretsPath -Destination (Join-Path $sketchDirectory 'field_node_secrets.example.h') -Force
    if (Test-Path -LiteralPath $localSecretsPath) {
        Copy-Item -LiteralPath $localSecretsPath -Destination (Join-Path $sketchDirectory 'field_node_secrets.h') -Force
    }

    Write-Host "Generated $sketchPath"
}
