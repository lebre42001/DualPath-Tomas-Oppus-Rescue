[CmdletBinding()]
param(
    [string]$JavaDirectory = $env:JAVA_HOME,
    [string]$SdkDirectory = $env:ANDROID_HOME
)

$ErrorActionPreference = 'Stop'
$repoDirectory = Split-Path -Parent $PSScriptRoot
$projectDirectory = Join-Path $repoDirectory 'android_app'
$runtimeDirectory = Join-Path $PSScriptRoot 'runtime'
$outputDirectory = Join-Path $runtimeDirectory 'android-release'
$signingDirectory = Join-Path $runtimeDirectory 'android-signing'
$keyFile = Join-Path $signingDirectory 'release.jks'
$credentialsFile = Join-Path $signingDirectory 'release-credentials.clixml'
$manifest = Get-Content -LiteralPath (Join-Path $projectDirectory 'twa-manifest.json') -Raw | ConvertFrom-Json

function Invoke-AndroidTool {
    param([string]$Executable, [string[]]$ToolArguments)
    # Windows PowerShell can turn native stderr warnings into error records.
    $ErrorActionPreference = 'Continue'
    & $Executable @ToolArguments
    $toolExitCode = $LASTEXITCODE
    if ($toolExitCode -ne 0) { throw "Android tool failed with exit code ${toolExitCode}: $Executable" }
}

if (!$JavaDirectory) {
    $jdkDirectory = Get-ChildItem -LiteralPath (Join-Path $runtimeDirectory 'android-tools/jdk17') -Directory |
        Sort-Object Name -Descending | Select-Object -First 1
    if ($jdkDirectory) { $JavaDirectory = $jdkDirectory.FullName }
}
if (!$SdkDirectory) { $SdkDirectory = Join-Path $runtimeDirectory 'android-tools/sdk' }
if (!$JavaDirectory) { throw 'Supply -JavaDirectory or JAVA_HOME pointing to JDK 17.' }
$buildToolsDirectory = Join-Path $SdkDirectory 'build-tools/36.0.0'
foreach ($requiredFile in @((Join-Path $JavaDirectory 'bin/java.exe'),
        (Join-Path $buildToolsDirectory 'zipalign.exe'),
        (Join-Path $buildToolsDirectory 'apksigner.bat'), $keyFile)) {
    if (!(Test-Path -LiteralPath $requiredFile)) { throw "Missing Android build prerequisite: $requiredFile" }
}

$previousJava = $env:JAVA_HOME
$previousSdk = $env:ANDROID_HOME
$previousGradle = $env:GRADLE_USER_HOME
$previousPassword = $env:RESCUE_ANDROID_STORE_PASSWORD
try {
    $env:JAVA_HOME = $JavaDirectory
    $env:ANDROID_HOME = $SdkDirectory
    if (!$env:GRADLE_USER_HOME) { $env:GRADLE_USER_HOME = Join-Path $runtimeDirectory 'android-tools/gradle-cache' }
    if (!$env:RESCUE_ANDROID_STORE_PASSWORD) {
        if (!(Test-Path -LiteralPath $credentialsFile)) { throw 'Supply RESCUE_ANDROID_STORE_PASSWORD or restore the Windows-encrypted signing credentials.' }
        $credentials = Import-Clixml -LiteralPath $credentialsFile
        $env:RESCUE_ANDROID_STORE_PASSWORD = $credentials.GetNetworkCredential().Password
    }
    New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null
    Push-Location $projectDirectory
    try {
        Invoke-AndroidTool -Executable '.\gradlew.bat' -ToolArguments @('assembleRelease', 'lintRelease', '--no-daemon', '--max-workers=2', '-Dorg.gradle.jvmargs=-Xmx1536m')
    } finally { Pop-Location }

    $unsignedApk = Join-Path $projectDirectory 'app/build/outputs/apk/release/app-release-unsigned.apk'
    $alignedApk = Join-Path $outputDirectory 'app-release-aligned.apk'
    $apkName = 'tomas-oppus-rescue-' + $manifest.appVersionName + '.apk'
    $signedApk = Join-Path $outputDirectory $apkName
    Invoke-AndroidTool -Executable (Join-Path $buildToolsDirectory 'zipalign.exe') -ToolArguments @('-p', '-f', '4', $unsignedApk, $alignedApk)
    Invoke-AndroidTool -Executable (Join-Path $buildToolsDirectory 'apksigner.bat') -ToolArguments @('sign', '--ks', $keyFile, '--ks-key-alias', $manifest.signingKey.alias,
        '--ks-pass', 'env:RESCUE_ANDROID_STORE_PASSWORD', '--key-pass', 'env:RESCUE_ANDROID_STORE_PASSWORD', '--out', $signedApk, $alignedApk)
    Invoke-AndroidTool -Executable (Join-Path $buildToolsDirectory 'apksigner.bat') -ToolArguments @('verify', '--verbose', '--print-certs', $signedApk)
    $apkHash = (Get-FileHash -LiteralPath $signedApk -Algorithm SHA256).Hash.ToLowerInvariant()
    [System.IO.File]::WriteAllText((Join-Path $outputDirectory ($apkName + '.sha256')), "$apkHash  $apkName`n")
    Write-Output "Verified APK: $signedApk"
    Write-Output "SHA256: $apkHash"
} finally {
    $env:JAVA_HOME = $previousJava
    $env:ANDROID_HOME = $previousSdk
    $env:GRADLE_USER_HOME = $previousGradle
    $env:RESCUE_ANDROID_STORE_PASSWORD = $previousPassword
}
