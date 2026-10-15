param(
    [ValidateSet('stable-resnet', 'lightweight-mobilenet')]
    [string]$Profile = 'stable-resnet',

    [string]$PythonPath = 'C:\laragon\bin\python\python-3.13\python.exe',

    [string]$RunDate = (Get-Date -Format 'yyyy_MM_dd'),

    [switch]$PromoteActiveConfigs
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$aiServiceRoot = Join-Path $projectRoot 'ai_service'
$trainScript = Join-Path $aiServiceRoot 'train.py'
$severityEvalScript = Join-Path $aiServiceRoot 'tools\evaluate_local_validation.py'
$relevanceEvalScript = Join-Path $aiServiceRoot 'tools\evaluate_photo_relevance_gate.py'

if ($Profile -eq 'lightweight-mobilenet') {
    $severityConfig = Join-Path $aiServiceRoot 'configs\bontoc_southern_leyte_recommended_severity_mobilenet.yaml'
    $relevanceConfig = Join-Path $aiServiceRoot 'configs\bontoc_southern_leyte_recommended_photo_relevance_mobilenet.yaml'
}
else {
    $severityConfig = Join-Path $aiServiceRoot 'configs\bontoc_southern_leyte_accuracy_candidate.yaml'
    $relevanceConfig = Join-Path $aiServiceRoot 'configs\bontoc_southern_leyte_photo_relevance.yaml'
}

function Invoke-AiStep {
    param(
        [Parameter(Mandatory = $true)][string]$Label,
        [Parameter(Mandatory = $true)][string[]]$Arguments
    )

    Write-Host ''
    Write-Host "=== $Label ===" -ForegroundColor Cyan
    Push-Location $aiServiceRoot
    try {
        & $PythonPath @Arguments
        if ($LASTEXITCODE -ne 0) {
            throw "$Label failed with exit code $LASTEXITCODE."
        }
    }
    finally {
        Pop-Location
    }
}

if (-not (Test-Path $PythonPath)) {
    throw "Python not found at $PythonPath"
}

foreach ($requiredPath in @($trainScript, $severityEvalScript, $relevanceEvalScript, $severityConfig, $relevanceConfig)) {
    if (-not (Test-Path $requiredPath)) {
        throw "Required AI workflow file not found: $requiredPath"
    }
}

Write-Host 'Stitch recommended AI training workflow' -ForegroundColor Green
Write-Host "Project root : $projectRoot"
Write-Host "AI service   : $aiServiceRoot"
Write-Host "Python       : $PythonPath"
Write-Host "Profile      : $Profile"
Write-Host "Run date     : $RunDate"
Write-Host "Severity cfg : $severityConfig"
Write-Host "Gate cfg     : $relevanceConfig"

Invoke-AiStep `
    -Label 'Training photo relevance gate' `
    -Arguments @($trainScript, '--config', $relevanceConfig)

Invoke-AiStep `
    -Label 'Evaluating dummy-photo rejection gate' `
    -Arguments @(
        $relevanceEvalScript,
        '--config',
        $relevanceConfig,
        '--output-prefix',
        "recommended_${Profile}_photo_gate_$RunDate"
    )

Invoke-AiStep `
    -Label 'Training severity classifier' `
    -Arguments @($trainScript, '--config', $severityConfig)

Invoke-AiStep `
    -Label 'Evaluating severity on local validation set' `
    -Arguments @(
        $severityEvalScript,
        '--config',
        $severityConfig,
        '--output-prefix',
        "recommended_${Profile}_severity_local_eval_$RunDate"
    )

if ($PromoteActiveConfigs) {
    $severityRelative = (Resolve-Path -Path $severityConfig).Path.Replace((Resolve-Path -Path $aiServiceRoot).Path + '\', '')
    $relevanceRelative = (Resolve-Path -Path $relevanceConfig).Path.Replace((Resolve-Path -Path $aiServiceRoot).Path + '\', '')

    Set-Content -Path (Join-Path $aiServiceRoot 'active_config.txt') -Value $severityRelative -Encoding ASCII
    Set-Content -Path (Join-Path $aiServiceRoot 'active_photo_relevance_config.txt') -Value $relevanceRelative -Encoding ASCII

    Write-Host ''
    Write-Host 'Active AI config pointers updated locally.' -ForegroundColor Yellow
    Write-Host 'Only deploy after reviewing metrics and uploading matching checkpoints to Render/R2/GitHub Releases.'
}

Write-Host ''
Write-Host 'Recommended AI workflow finished.' -ForegroundColor Green
Write-Host "Training reports: $(Join-Path $aiServiceRoot 'artifacts\reports')"
Write-Host "Local validation reports: $(Join-Path $aiServiceRoot 'datasets\bontoc_southern_leyte\local_validation\reports')"
Write-Host ''
Write-Host 'Promotion rule: do not promote if dummy false accepts exist or fatal recall/local validation is weak.' -ForegroundColor Yellow
