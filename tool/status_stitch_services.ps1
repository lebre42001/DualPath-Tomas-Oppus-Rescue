param()

$ErrorActionPreference = 'Stop'

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
$serviceDefinitions = @(
  @{
    Name = 'mysql'
    Port = 3306
    CommandFragments = @('mysqld')
    ProcessName = 'mysqld.exe'
  },
  @{
    Name = 'laravel-serve'
    Port = 8000
    CommandFragments = @('artisan serve', '--host=0.0.0.0', '--port=8000')
    ProcessName = 'php.exe'
  },
  @{
    Name = 'reverb'
    Port = 8080
    CommandFragments = @('reverb:start', '--host=0.0.0.0', '--port=8080')
    ProcessName = 'php.exe'
  },
  @{
    Name = 'ai-service'
    Port = 8100
    CommandFragments = @('serve_api.py')
    ProcessName = 'python.exe'
  },
  @{
    Name = 'ai-text-test'
    Port = 8101
    CommandFragments = @('serve_text_test.py')
    ProcessName = 'python.exe'
  }
)

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

function Get-ManagedServiceState {
  param(
    [Parameter(Mandatory = $true)][string]$Name,
    [Parameter(Mandatory = $true)][int]$Port,
    [Parameter(Mandatory = $true)][string[]]$CommandFragments,
    [Parameter(Mandatory = $true)][string]$ProcessName
  )

  $pidFile = Join-Path $runtimeDir "$Name.pid"
  $listeningPid = Get-ListeningProcessId -Port $Port

  if ($listeningPid) {
    Set-Content -Path $pidFile -Value $listeningPid

    return [pscustomobject]@{
      Name = $Name
      State = 'running'
      Pid = $listeningPid
    }
  }

  $matchedProcesses = @(Find-ManagedProcesses -CommandFragments $CommandFragments -ProcessName $ProcessName)

  if ($matchedProcesses.Count -gt 0) {
    $primaryPid = [int]$matchedProcesses[0].ProcessId
    Set-Content -Path $pidFile -Value $primaryPid

    return [pscustomobject]@{
      Name = $Name
      State = $(if ($matchedProcesses.Count -gt 1) { "running ($($matchedProcesses.Count) found)" } else { 'running' })
      Pid = $primaryPid
    }
  }

  if (-not (Test-Path $pidFile)) {
    return [pscustomobject]@{
      Name = $Name
      State = 'not_started'
      Pid = $null
    }
  }

  $pidValue = (Get-Content $pidFile -Raw).Trim()

  if (-not $pidValue) {
    return [pscustomobject]@{
      Name = $Name
      State = 'pid_missing'
      Pid = $null
    }
  }

  $process = Get-Process -Id ([int]$pidValue) -ErrorAction SilentlyContinue

  return [pscustomobject]@{
    Name = $Name
    State = $(if ($process) { 'running' } else { 'stopped' })
    Pid = $(if ($process) { $process.Id } else { [int]$pidValue })
  }
}

function Get-HttpStatus {
  param(
    [Parameter(Mandatory = $true)][string]$Uri
  )

  try {
    $response = Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 4
    return "HTTP $($response.StatusCode)"
  } catch {
    return 'offline'
  }
}

function Get-TcpStatus {
  param(
    [Parameter(Mandatory = $true)][string]$TargetHost,
    [Parameter(Mandatory = $true)][int]$Port
  )

  try {
    $client = New-Object System.Net.Sockets.TcpClient
    $async = $client.BeginConnect($TargetHost, $Port, $null, $null)
    if ($async.AsyncWaitHandle.WaitOne(1500, $false)) {
      $client.EndConnect($async)
      $client.Close()
      return 'ready'
    }

    $client.Close()
    return 'offline'
  } catch {
    return 'offline'
  }
}

Write-Host ''
Write-Host 'Stitch backend status'
Write-Host '---------------------'

foreach ($definition in $serviceDefinitions) {
  $service = Get-ManagedServiceState -Name $definition.Name -Port $definition.Port -CommandFragments $definition.CommandFragments -ProcessName $definition.ProcessName
  $pidText = if ($service.Pid) { "PID $($service.Pid)" } else { 'PID n/a' }
  Write-Host ("{0,-14} {1,-12} {2}" -f $service.Name, $service.State, $pidText)
}

Write-Host ''
Write-Host 'Health checks'
Write-Host '-------------'
Write-Host ("MySQL   : {0}" -f (Get-TcpStatus -TargetHost '127.0.0.1' -Port 3306))
Write-Host ("Web     : {0}" -f (Get-HttpStatus -Uri 'http://127.0.0.1:8000'))
Write-Host ("AI      : {0}" -f (Get-HttpStatus -Uri 'http://127.0.0.1:8100/health'))
Write-Host ("Text AI : {0}" -f (Get-HttpStatus -Uri 'http://127.0.0.1:8101/health/text'))
Write-Host ("Reverb  : {0}" -f (Get-TcpStatus -TargetHost '127.0.0.1' -Port 8080))
Write-Host ''
Write-Host 'URLs'
Write-Host '----'
Write-Host 'Web UI     : http://127.0.0.1:8000'
Write-Host ("LAN Web UI : http://{0}:8000" -f (Get-LanIpAddress))
Write-Host 'Text AI Lab: http://127.0.0.1:8000/admin/text-ai-test (admin login required)'
Write-Host ''
Write-Host "Runtime files: $runtimeDir"
