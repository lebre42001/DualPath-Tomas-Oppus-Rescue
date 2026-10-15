param(
  [switch]$SkipHealthChecks
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$runtimeDir = Join-Path $PSScriptRoot 'runtime'
New-Item -ItemType Directory -Force -Path $runtimeDir | Out-Null

function Get-LanIpAddress {
  $lanAddress = Get-NetIPConfiguration -ErrorAction SilentlyContinue |
    Where-Object {
      $_.NetAdapter.Status -eq 'Up' -and $_.IPv4DefaultGateway -and $_.IPv4Address -and
      $_.InterfaceAlias -notmatch 'Loopback|vEthernet|Virtual|VMware|Hyper-V|Bluetooth'
    } |
    Select-Object -First 1 -ExpandProperty IPv4Address |
    Select-Object -First 1 -ExpandProperty IPAddress

  if (-not $lanAddress) {
    $lanAddress = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
      Where-Object {
        $_.IPAddress -notmatch '^(127\.|169\.254\.)' -and
        $_.InterfaceAlias -notmatch 'Loopback|vEthernet|Virtual|VMware|Hyper-V|Bluetooth'
      } |
      Select-Object -First 1 -ExpandProperty IPAddress
  }

  if ($lanAddress) {
    return $lanAddress
  }

  return '127.0.0.1'
}

function Resolve-PhpPath {
  $candidates = @()

  $laragonPhp = @(Get-ChildItem 'C:\laragon\bin\php' -Recurse -Filter php.exe -ErrorAction SilentlyContinue |
    Sort-Object FullName -Descending |
    Select-Object -ExpandProperty FullName)

  if ($laragonPhp) {
    $candidates += $laragonPhp
  }

  $phpCommand = Get-Command php -ErrorAction SilentlyContinue
  if ($phpCommand) {
    $candidates += $phpCommand.Source
  }

  foreach ($candidate in ($candidates | Select-Object -Unique)) {
    try {
      $modules = & $candidate -m 2>$null
      if ($modules -contains 'pdo_mysql') {
        return $candidate
      }
    } catch {
    }
  }

  if ($candidates.Count -gt 0) {
    throw 'php.exe was found, but no PHP CLI with the pdo_mysql extension is available. Enable pdo_mysql in php.ini or use the Laragon PHP build.'
  }

  throw 'php.exe was not found. Install PHP or add it to PATH.'
}

function Resolve-PythonPath {
  $laragonPython = Get-ChildItem 'C:\laragon\bin\python' -Recurse -Filter python.exe -ErrorAction SilentlyContinue |
    Sort-Object FullName -Descending |
    Select-Object -First 1 -ExpandProperty FullName

  if ($laragonPython) {
    return $laragonPython
  }

  $pythonCommand = Get-Command python -CommandType Application -ErrorAction SilentlyContinue |
    Where-Object { $_.Source -and $_.Source -notmatch 'WindowsApps' } |
    Select-Object -First 1

  if ($pythonCommand) {
    return $pythonCommand.Source
  }

  throw 'python.exe was not found. Install Python or update this script path.'
}

function Get-ListeningProcessId {
  param(
    [Parameter(Mandatory = $true)][int]$Port
  )

  $connection = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
    Select-Object -First 1

  if ($connection) {
    return [int]$connection.OwningProcess
  }

  $line = netstat -ano | Select-String (":$Port") | Select-Object -First 1
  if (-not $line) {
    return $null
  }

  $parts = ($line.ToString() -replace '\s+', ' ').Trim().Split(' ')
  if ($parts.Length -lt 5) {
    return $null
  }

  return [int]$parts[-1]
}

function Find-ManagedProcesses {
  param(
    [Parameter(Mandatory = $true)][string[]]$CommandFragments,
    [Parameter(Mandatory = $true)][string]$ProcessName
  )

  return Get-CimInstance Win32_Process |
    Where-Object {
      if ($_.Name -ine $ProcessName) {
        return $false
      }

      $commandLine = $_.CommandLine

      if (-not $commandLine) {
        return $false
      }

      foreach ($fragment in $CommandFragments) {
        if ($commandLine -notlike "*$fragment*") {
          return $false
        }
      }

      return $true
    } |
    Sort-Object ProcessId
}

function Start-ManagedProcess {
  param(
    [Parameter(Mandatory = $true)][string]$Name,
    [Parameter(Mandatory = $true)][string]$FilePath,
    [Parameter(Mandatory = $true)][string[]]$Arguments,
    [Parameter(Mandatory = $true)][int]$Port,
    [Parameter(Mandatory = $true)][string]$WorkingDirectory,
    [Parameter(Mandatory = $true)][string[]]$CommandFragments,
    [Parameter(Mandatory = $true)][string]$ProcessName
  )

  $pidFile = Join-Path $runtimeDir "$Name.pid"
  $stdoutFile = Join-Path $runtimeDir "$Name.out.log"
  $stderrFile = Join-Path $runtimeDir "$Name.err.log"

  $listeningPid = Get-ListeningProcessId -Port $Port
  if ($listeningPid) {
    Set-Content -Path $pidFile -Value $listeningPid

    return [pscustomobject]@{
      Name = $Name
      Status = 'already_running'
      Pid = $listeningPid
      Stdout = $stdoutFile
      Stderr = $stderrFile
    }
  }

  $existingManagedProcesses = @(Find-ManagedProcesses -CommandFragments $CommandFragments -ProcessName $ProcessName)
  if ($existingManagedProcesses.Count -gt 0) {
    $primaryProcessId = [int]$existingManagedProcesses[0].ProcessId
    Set-Content -Path $pidFile -Value $primaryProcessId

    return [pscustomobject]@{
      Name = $Name
      Status = $(if ($existingManagedProcesses.Count -gt 1) { "already_running ($($existingManagedProcesses.Count) found)" } else { 'already_running' })
      Pid = $primaryProcessId
      Stdout = $stdoutFile
      Stderr = $stderrFile
    }
  }

  if (Test-Path $pidFile) {
    $existingPid = (Get-Content $pidFile -Raw).Trim()
    if ($existingPid) {
      $existingProcess = Get-Process -Id ([int]$existingPid) -ErrorAction SilentlyContinue
      if ($existingProcess) {
        return [pscustomobject]@{
          Name = $Name
          Status = 'already_running'
          Pid = $existingProcess.Id
          Stdout = $stdoutFile
          Stderr = $stderrFile
        }
      }
    }

    Remove-Item $pidFile -Force -ErrorAction SilentlyContinue
  }

  $process = Start-Process `
    -FilePath $FilePath `
    -ArgumentList $Arguments `
    -WorkingDirectory $WorkingDirectory `
    -RedirectStandardOutput $stdoutFile `
    -RedirectStandardError $stderrFile `
    -PassThru `
    -WindowStyle Hidden

  Set-Content -Path $pidFile -Value $process.Id

  return [pscustomobject]@{
    Name = $Name
    Status = 'started'
    Pid = $process.Id
    Stdout = $stdoutFile
    Stderr = $stderrFile
  }
}

function Wait-ForHttp {
  param(
    [Parameter(Mandatory = $true)][string]$Uri,
    [int]$TimeoutSeconds = 20
  )

  $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
  do {
    try {
      $response = Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 3
      return $response.StatusCode
    } catch {
      Start-Sleep -Milliseconds 700
    }
  } while ((Get-Date) -lt $deadline)

  return $null
}

function Wait-ForTcpPort {
  param(
    [Parameter(Mandatory = $true)][string]$TargetHost,
    [Parameter(Mandatory = $true)][int]$Port,
    [int]$TimeoutSeconds = 20
  )

  $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
  do {
    try {
      $client = New-Object System.Net.Sockets.TcpClient
      $async = $client.BeginConnect($TargetHost, $Port, $null, $null)
      if ($async.AsyncWaitHandle.WaitOne(1000, $false)) {
        $client.EndConnect($async)
        $client.Close()
        return $true
      }
      $client.Close()
    } catch {
    }

    Start-Sleep -Milliseconds 500
  } while ((Get-Date) -lt $deadline)

  return $false
}

function Set-EnvDefault {
  param(
    [Parameter(Mandatory = $true)][string]$Name,
    [Parameter(Mandatory = $true)][string]$Value
  )

  if ([string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($Name, 'Process'))) {
    Set-Item -Path "Env:$Name" -Value $Value
  }
}

$php = Resolve-PhpPath
$python = Resolve-PythonPath
$lanIp = Get-LanIpAddress
$mysqlRoot = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64'
$mysqlConfig = Join-Path $mysqlRoot 'my.ini'
$mysqlServer = Join-Path $mysqlRoot 'bin\mysqld.exe'

Set-EnvDefault -Name 'AI_SEVERITY_TIMEOUT' -Value '90'
Set-EnvDefault -Name 'AI_SEVERITY_RETRY_ATTEMPTS' -Value '2'
Set-EnvDefault -Name 'AI_SEVERITY_RETRY_DELAY_MS' -Value '1500'
Set-EnvDefault -Name 'AI_PRELOAD_MODELS' -Value 'true'
Set-EnvDefault -Name 'AI_SINGLE_MODEL_CACHE' -Value 'false'
Set-EnvDefault -Name 'AI_UNLOAD_AFTER_PREDICT' -Value 'false'
Set-EnvDefault -Name 'AI_PHOTO_RELEVANCE_REJECT_THRESHOLD' -Value '0.95'
Set-EnvDefault -Name 'AI_RELEVANCE_SEVERITY_OVERRIDE_ENABLED' -Value 'true'
Set-EnvDefault -Name 'AI_RELEVANCE_SEVERITY_OVERRIDE_THRESHOLD' -Value '0.85'
Set-EnvDefault -Name 'AI_RELEVANCE_SEVERITY_OVERRIDE_LABELS' -Value 'serious,fatal'

$textAiPython = Join-Path $root 'ai_service\.venv-xlmrt-fulltrain\Scripts\python.exe'

$services = @(
  @{
    Name = 'mysql'
    FilePath = $mysqlServer
    Arguments = @("--defaults-file=$mysqlConfig", '--console')
    Port = 3306
    WorkingDirectory = $mysqlRoot
    CommandFragments = @('mysqld', "--defaults-file=$mysqlConfig")
    ProcessName = 'mysqld.exe'
  },
  @{
    Name = 'laravel-serve'
    FilePath = $php
    Arguments = @('artisan', 'serve', '--host=0.0.0.0', '--port=8000')
    Port = 8000
    WorkingDirectory = Join-Path $root 'web_system'
    CommandFragments = @('artisan serve', '--host=0.0.0.0', '--port=8000')
    ProcessName = 'php.exe'
  },
  @{
    Name = 'reverb'
    FilePath = $php
    Arguments = @('artisan', 'reverb:start', '--host=0.0.0.0', '--port=8080')
    Port = 8080
    WorkingDirectory = Join-Path $root 'web_system'
    CommandFragments = @('reverb:start', '--host=0.0.0.0', '--port=8080')
    ProcessName = 'php.exe'
  },
  @{
    Name = 'ai-service'
    FilePath = $python
    Arguments = @('serve_api.py')
    Port = 8100
    WorkingDirectory = Join-Path $root 'ai_service'
    CommandFragments = @('serve_api.py')
    ProcessName = 'python.exe'
  }
)

if (Test-Path -LiteralPath $textAiPython) {
  $services += @{
    Name = 'ai-text-test'
    FilePath = $textAiPython
    Arguments = @('serve_text_test.py')
    Port = 8101
    WorkingDirectory = Join-Path $root 'ai_service'
    CommandFragments = @('serve_text_test.py')
    ProcessName = 'python.exe'
  }
}

$results = foreach ($service in $services) {
  Start-ManagedProcess @service
}

Write-Host ''
Write-Host 'Stitch services status'
Write-Host '----------------------'
foreach ($result in $results) {
  Write-Host ("{0,-14} {1,-16} PID {2}" -f $result.Name, $result.Status, $result.Pid)
}

if (-not $SkipHealthChecks) {
  $webStatus = Wait-ForHttp -Uri 'http://127.0.0.1:8000'
  $aiStatus = Wait-ForHttp -Uri 'http://127.0.0.1:8100/health' -TimeoutSeconds 120
  $textAiStatus = if (Test-Path -LiteralPath $textAiPython) {
    Wait-ForHttp -Uri 'http://127.0.0.1:8101/health/text' -TimeoutSeconds 30
  } else {
    $null
  }
  $mysqlReady = Wait-ForTcpPort -TargetHost '127.0.0.1' -Port 3306
  $reverbReady = Wait-ForTcpPort -TargetHost '127.0.0.1' -Port 8080

  Write-Host ''
  Write-Host 'Health checks'
  Write-Host '-------------'
  Write-Host ("MySQL   : {0}" -f ($(if ($mysqlReady) { 'Port 3306 ready' } else { 'Port 3306 not reachable yet' })))
  Write-Host ("Web     : {0}" -f ($(if ($webStatus) { "HTTP $webStatus" } else { 'Not reachable yet' })))
  Write-Host ("AI      : {0}" -f ($(if ($aiStatus) { "HTTP $aiStatus" } else { 'Not reachable yet' })))
  Write-Host ("Text AI : {0}" -f ($(if ($textAiStatus) { "HTTP $textAiStatus (isolated test API)" } elseif (-not (Test-Path -LiteralPath $textAiPython)) { 'Optional environment not installed' } else { 'Not reachable yet' })))
  Write-Host ("Reverb  : {0}" -f ($(if ($reverbReady) { 'Port 8080 ready' } else { 'Port 8080 not reachable yet' })))
}

Write-Host ''
Write-Host 'URLs'
Write-Host '----'
Write-Host 'Web UI     : http://127.0.0.1:8000'
Write-Host ("LAN Web UI : http://{0}:8000" -f $lanIp)
Write-Host 'AI Health  : http://127.0.0.1:8100/health'
Write-Host 'Text AI Lab: http://127.0.0.1:8000/admin/text-ai-test (admin login required)'
Write-Host 'Text API   : http://127.0.0.1:8101/health/text'
Write-Host 'Reverb     : http://127.0.0.1:8080'
Write-Host ''
Write-Host "Logs are stored in: $runtimeDir"
Write-Host "To stop everything, run: powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\\tool\\stop_stitch_services.ps1"
