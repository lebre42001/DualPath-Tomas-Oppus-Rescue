param(
    [string]$PythonPath = 'C:\laragon\bin\python\python-3.13\python.exe',
    [string]$RunDate = (Get-Date -Format 'yyyy_MM_dd')
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$aiServiceRoot = Join-Path $projectRoot 'ai_service'
$trainScript = Join-Path $aiServiceRoot 'train.py'
$localValidationScript = Join-Path $aiServiceRoot 'tools\evaluate_local_validation.py'
$severityCandidateConfig = Join-Path $aiServiceRoot 'configs\bontoc_southern_leyte_accuracy_candidate.yaml'
$relevanceCandidateConfig = Join-Path $aiServiceRoot 'configs\bontoc_southern_leyte_photo_relevance_accuracy_candidate.yaml'
$productionConfig = Join-Path $aiServiceRoot 'configs\bontoc_southern_leyte_production_candidate_external.yaml'

function Invoke-AiAccuracyStep {
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

foreach ($requiredPath in @($trainScript, $localValidationScript, $severityCandidateConfig, $relevanceCandidateConfig, $productionConfig)) {
    if (-not (Test-Path $requiredPath)) {
        throw "Required AI workflow file not found: $requiredPath"
    }
}

Write-Host 'Stitch AI accuracy workflow' -ForegroundColor Green
Write-Host "Project root : $projectRoot"
Write-Host "AI service   : $aiServiceRoot"
Write-Host "Python       : $PythonPath"
Write-Host "Run date     : $RunDate"

Invoke-AiAccuracyStep `
    -Label 'Training severity accuracy candidate' `
    -Arguments @($trainScript, '--config', $severityCandidateConfig)

Invoke-AiAccuracyStep `
    -Label 'Training photo relevance / dummy-photo candidate' `
    -Arguments @($trainScript, '--config', $relevanceCandidateConfig)

Invoke-AiAccuracyStep `
    -Label 'Evaluating severity candidate on local validation set' `
    -Arguments @(
        $localValidationScript,
        '--config',
        $severityCandidateConfig,
        '--output-prefix',
        "accuracy_candidate_local_eval_$RunDate"
    )

Invoke-AiAccuracyStep `
    -Label 'Evaluating current production candidate on local validation set' `
    -Arguments @(
        $localValidationScript,
        '--config',
        $productionConfig,
        '--output-prefix',
        "production_candidate_local_eval_$RunDate"
    )

Write-Host ''
Write-Host 'AI accuracy workflow finished.' -ForegroundColor Green
Write-Host "Training reports: $(Join-Path $aiServiceRoot 'artifacts\reports')"
Write-Host "Local validation reports: $(Join-Path $aiServiceRoot 'datasets\bontoc_southern_leyte\local_validation\reports')"
Write-Host ''
Write-Host 'Review the metrics before promoting any checkpoint. Do not promote if local validation does not improve.' -ForegroundColor Yellow
