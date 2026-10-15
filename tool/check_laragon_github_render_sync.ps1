param()

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$expectedRemote = 'https://github.com/Chuan2018-dev/BontocRescue.git'

function Test-UrlHealthy {
    param(
        [Parameter(Mandatory = $true)][string]$Uri
    )

    try {
        $response = Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 4
        return [pscustomobject]@{
            Uri = $Uri
            Ok = $true
            Status = $response.StatusCode
        }
    } catch {
        return [pscustomobject]@{
            Uri = $Uri
            Ok = $false
            Status = 'unreachable'
        }
    }
}

Write-Host ''
Write-Host 'Laragon -> GitHub -> Render Sync Readiness'
Write-Host '----------------------------------------'
Write-Host "Repo root: $repoRoot"
Write-Host ''

$gitDirectory = Join-Path $repoRoot '.git'
$isGitRepo = Test-Path $gitDirectory

Write-Host "Git initialized: $isGitRepo"

if ($isGitRepo) {
    $remoteOutput = git -C $repoRoot remote get-url origin 2>$null
    if ($LASTEXITCODE -eq 0 -and $remoteOutput) {
        Write-Host "Origin remote: $remoteOutput"
    } else {
        Write-Host 'Origin remote: missing'
    }
} else {
    Write-Host 'Origin remote: not available yet'
}

Write-Host ''
Write-Host 'Required files'
Write-Host '--------------'

$requiredPaths = @(
    'README.md',
    'render.yaml',
    'DEPLOYMENT_RENDER_RAILWAY.md',
    'web_system\artisan',
    'web_system\package.json',
    'ai_service\train.py',
    'tool\start_stitch_services.ps1',
    'tool\stop_stitch_services.ps1'
)

foreach ($relativePath in $requiredPaths) {
    $fullPath = Join-Path $repoRoot $relativePath
    Write-Host ("{0,-45} {1}" -f $relativePath, $(if (Test-Path $fullPath) { 'OK' } else { 'MISSING' }))
}

Write-Host ''
Write-Host 'Live local checks'
Write-Host '-----------------'

$checks = @(
    'http://127.0.0.1:8000/login',
    'http://127.0.0.1:8100/health'
)

foreach ($check in $checks) {
    $result = Test-UrlHealthy -Uri $check
    Write-Host ("{0,-35} {1}" -f $result.Uri, $(if ($result.Ok) { "OK ($($result.Status))" } else { 'UNREACHABLE' }))
}

Write-Host ''
Write-Host 'Recommended next commands'
Write-Host '-------------------------'

if (-not $isGitRepo) {
    Write-Host 'git init'
    Write-Host 'git branch -M main'
    Write-Host "git remote add origin $expectedRemote"
    Write-Host 'git fetch origin'
    Write-Host 'git checkout -b laragon-sync-YYYY-MM-DD'
} else {
    Write-Host 'git fetch origin'
    Write-Host 'git checkout -b laragon-sync-YYYY-MM-DD'
}

Write-Host 'git add .'
Write-Host 'git status'
Write-Host 'git commit -m "Sync Laragon source-of-truth updates"'
Write-Host 'git push -u origin laragon-sync-YYYY-MM-DD'
Write-Host ''
Write-Host 'Open the full guide:'
Write-Host 'LARAGON_GITHUB_RENDER_SYNC_PATH.md'
