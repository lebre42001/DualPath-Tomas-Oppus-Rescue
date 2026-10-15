param(
    [string]$ConfigPath,
    [switch]$ShowCurrent
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$aiServiceRoot = Join-Path $projectRoot 'ai_service'
$pointerPath = Join-Path $aiServiceRoot 'active_config.txt'

if ($ShowCurrent) {
    if (-not (Test-Path $pointerPath)) {
        throw ('Active config pointer not found at ' + $pointerPath)
    }

    $current = (Get-Content -Raw $pointerPath).Trim()
    Write-Host ('Current active AI config: ' + $current) -ForegroundColor Green
    exit 0
}

if (-not $ConfigPath) {
    throw 'Provide -ConfigPath or use -ShowCurrent.'
}

$normalizedRelative = $ConfigPath.Replace('/', '\').Trim()
$absoluteConfig = Join-Path $aiServiceRoot $normalizedRelative

if (-not (Test-Path $absoluteConfig)) {
    throw ('Config file not found: ' + $absoluteConfig)
}

$normalizedPointer = $normalizedRelative.Replace('\', '/')
Set-Content -LiteralPath $pointerPath -Value ($normalizedPointer + [Environment]::NewLine) -Encoding UTF8

Write-Host 'Active AI config pointer updated.' -ForegroundColor Green
Write-Host ('New config: ' + $normalizedPointer)
Write-Host 'Restart the AI service so the change takes effect.'
