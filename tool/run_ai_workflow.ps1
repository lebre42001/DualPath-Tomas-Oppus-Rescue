param(
    [ValidateSet('local-validation', 'v0-3-split', 'v0-3-train', 'v0-3-full', 'both')]
    [string]$Mode = 'both',

    [string]$PythonPath = 'C:\laragon\bin\python\python-3.13\python.exe'
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$aiServiceRoot = Join-Path $projectRoot 'ai_service'
$evaluateScript = Join-Path $aiServiceRoot 'tools\evaluate_local_validation.py'
$splitScript = Join-Path $aiServiceRoot 'tools\build_v0_3_candidate_split.py'
$trainScript = Join-Path $aiServiceRoot 'train.py'
$v03Config = Join-Path $aiServiceRoot 'configs\bontoc_southern_leyte_v0_3_candidate_template.yaml'
$localValidationManifest = Join-Path $aiServiceRoot 'datasets\bontoc_southern_leyte\local_validation\manifests\local_validation_manifest_template.csv'
$reviewedPoolManifest = Join-Path $aiServiceRoot 'datasets\bontoc_southern_leyte\v0_3_candidate\manifests\reviewed_pool_manifest_template.csv'

function Invoke-AiStep {
    param(
        [string]$Label,
        [string]$ScriptPath,
        [string[]]$Arguments = @()
    )

    Write-Host ''
    Write-Host ('=== ' + $Label + ' ===') -ForegroundColor Cyan
    Write-Host ('Python : ' + $PythonPath)
    Write-Host ('Script : ' + $ScriptPath)
    if ($Arguments.Count -gt 0) {
        Write-Host ('Args   : ' + ($Arguments -join ' '))
    }
    Push-Location $aiServiceRoot
    try {
        & $PythonPath $ScriptPath @Arguments
        if ($LASTEXITCODE -ne 0) {
            throw ($Label + ' failed with exit code ' + $LASTEXITCODE + '.')
        }
    }
    finally {
        Pop-Location
    }
}

if (-not (Test-Path $PythonPath)) {
    throw ('Python not found at ' + $PythonPath)
}

if (-not (Test-Path $evaluateScript)) {
    throw ('Local validation evaluator not found at ' + $evaluateScript)
}

if (-not (Test-Path $splitScript)) {
    throw ('v0.3 split builder not found at ' + $splitScript)
}

if (-not (Test-Path $trainScript)) {
    throw ('Training entrypoint not found at ' + $trainScript)
}

Write-Host 'Stitch AI workflow helper' -ForegroundColor Green
Write-Host ('Project root : ' + $projectRoot)
Write-Host ('AI service   : ' + $aiServiceRoot)
Write-Host ('Mode         : ' + $Mode)
Write-Host ''
Write-Host ('Local validation manifest : ' + $localValidationManifest)
Write-Host ('Reviewed pool manifest    : ' + $reviewedPoolManifest)
Write-Host ('v0.3 config               : ' + $v03Config)

switch ($Mode) {
    'local-validation' {
        Invoke-AiStep -Label 'Running local validation evaluator' -ScriptPath $evaluateScript
    }
    'v0-3-split' {
        Invoke-AiStep -Label 'Building v0.3 candidate split' -ScriptPath $splitScript
    }
    'v0-3-train' {
        Invoke-AiStep -Label 'Training v0.3 candidate model' -ScriptPath $trainScript -Arguments @('--config', $v03Config)
    }
    'v0-3-full' {
        Invoke-AiStep -Label 'Building v0.3 candidate split' -ScriptPath $splitScript
        Invoke-AiStep -Label 'Training v0.3 candidate model' -ScriptPath $trainScript -Arguments @('--config', $v03Config)
        Invoke-AiStep -Label 'Running local validation with v0.3 candidate config' -ScriptPath $evaluateScript -Arguments @('--config', $v03Config, '--output-prefix', 'v0_3_candidate_local_eval')
    }
    'both' {
        Invoke-AiStep -Label 'Running local validation evaluator' -ScriptPath $evaluateScript
        Invoke-AiStep -Label 'Building v0.3 candidate split' -ScriptPath $splitScript
    }
}

Write-Host ''
Write-Host 'AI workflow finished.' -ForegroundColor Green
Write-Host ('Local validation reports: ' + (Join-Path $aiServiceRoot 'datasets\bontoc_southern_leyte\local_validation\reports'))
Write-Host ('v0.3 split reports      : ' + (Join-Path $aiServiceRoot 'datasets\bontoc_southern_leyte\v0_3_candidate\reports'))
Write-Host ('Training reports         : ' + (Join-Path $aiServiceRoot 'artifacts\reports'))
Write-Host ('Checkpoints              : ' + (Join-Path $aiServiceRoot 'artifacts\checkpoints'))
