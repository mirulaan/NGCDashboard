<#
.SYNOPSIS
    Remote Fleet Orchestrator for SSD Forensics Collection
.DESCRIPTION
    Deploys and executes Invoke-SSDForensics.ps1 across a list of target IPs
    or production lines, retrieves the resulting forensic JSON/CSV archives,
    and triggers Aggregate-SSDForensics.ps1 to generate a cross-station RCA matrix.
    Uses WMI + SMB Admin shares (identical zero-prompt architecture to Deploy-AgentFleet.ps1).
.PARAMETER TargetIps
    Array of target PC IP addresses or hostnames.
.PARAMETER OutputDir
    Centralized collection folder for fleet telemetry (default: C:\Hardware\SSD_Forensics).
.PARAMETER TimeoutSeconds
    Maximum time in seconds to wait for each remote node to complete execution (default: 45).
#>

[CmdletBinding()]
param(
    [string[]]$TargetIps = @(),
    [string]$OutputDir = "C:\Hardware\SSD_Forensics",
    [int]$TimeoutSeconds = 45
)

# 0. AUTO-ELEVATE TO ADMINISTRATOR & BYPASS EXECUTION POLICY
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    try {
        Write-Host "Elevating to Administrator..." -ForegroundColor Yellow
        $argList = @("-NoProfile", "-ExecutionPolicy", "Bypass", "-File", "`"$PSCommandPath`"")
        foreach ($key in $PSBoundParameters.Keys) {
            $val = $PSBoundParameters[$key]
            if ($val -is [switch] -and $val.IsPresent) {
                $argList += "-$key"
            } elseif ($val -is [array]) {
                $argList += "-$key"
                $argList += ($val -join ',')
            } else {
                $argList += "-$key"
                $argList += "`"$val`""
            }
        }
        Start-Process powershell.exe -ArgumentList ($argList -join ' ') -Verb RunAs -ErrorAction Stop
        Exit
    } catch {
        Write-Warning "Could not automatically elevate to Administrator: $($_.Exception.Message)"
        Write-Warning "Continuing with current privileges. (Note: Remote SMB and WMI typically require Administrator privileges)."
    }
}

if ([string]::IsNullOrWhiteSpace($OutputDir)) {
    $OutputDir = "C:\Hardware\SSD_Forensics"
}
if (-not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}
$OutputDir = (Resolve-Path $OutputDir).Path

$collectorScript = Join-Path $PSScriptRoot "Invoke-SSDForensics.ps1"
if (-not (Test-Path $collectorScript)) {
    Write-Error "Could not find collector script at: $collectorScript"
    return
}

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "   SSD Fleet Remote Forensic Deployment Orchestrator      " -ForegroundColor Cyan
Write-Host "   Targets: $($TargetIps.Count) stations                   " -ForegroundColor Cyan
Write-Host "   Central Depot: $OutputDir                              " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

# Detect all local machine IPs to prevent network loopbacks
$localIps = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
$localIps.Add("127.0.0.1") | Out-Null
$localIps.Add("localhost") | Out-Null
$localIps.Add("::1") | Out-Null
$localIps.Add($env:COMPUTERNAME) | Out-Null

try {
    $netIps = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue).IPAddress
    foreach ($nip in $netIps) {
        $localIps.Add($nip) | Out-Null
    }
} catch {
    # Fallback to WMI if Get-NetIPAddress unavailable
    $wmiIps = Get-CimInstance Win32_NetworkAdapterConfiguration -ErrorAction SilentlyContinue |
              Where-Object { $_.IPEnabled -and $_.IPAddress } |
              ForEach-Object { $_.IPAddress }
    foreach ($wip in $wmiIps) {
        $localIps.Add($wip) | Out-Null
    }
}

if ($TargetIps.Count -eq 0) {
    Write-Host "No target IPs specified. Running local collection on $env:COMPUTERNAME..." -ForegroundColor Yellow
    & $collectorScript -OutputDir $OutputDir
} else {
    foreach ($ip in $TargetIps) {
        $ip = $ip.Trim()
        if ([string]::IsNullOrWhiteSpace($ip)) { continue }

        $isLocal = $localIps.Contains($ip)

        if ($isLocal) {
            Write-Host "`n[*] [$ip] Detected as LOCAL HOST ($env:COMPUTERNAME). Executing locally..." -ForegroundColor Green
            & $collectorScript -OutputDir $OutputDir
            continue
        }

        Write-Host "`n[*] [$ip] Deploying forensic collector to remote station..." -ForegroundColor Cyan

        # 1. Ping Check
        $ping = Test-Connection -ComputerName $ip -Count 1 -Quiet -ErrorAction SilentlyContinue
        if (-not $ping) {
            Write-Warning "   [-] Station $ip is unreachable (Ping failed). Skipping."
            continue
        }

        # 2. SMB Admin Share Copy
        $remoteDir = "\\$ip\C$\Hardware\SSD_Forensics"
        try {
            if (-not (Test-Path $remoteDir)) {
                New-Item -ItemType Directory -Path $remoteDir -Force -ErrorAction Stop | Out-Null
            }
            Copy-Item -Path $collectorScript -Destination "$remoteDir\Invoke-SSDForensics.ps1" -Force -ErrorAction Stop
            Write-Host "   [+] Collector staged at $remoteDir\Invoke-SSDForensics.ps1" -ForegroundColor Green
        } catch {
            Write-Warning "   [-] Failed to access SMB admin share (\\$ip\C$\): $($_.Exception.Message)"
            continue
        }

        # 3. WMI Remote Execution (Zero GUI credential prompts)
        try {
            $preRunTime = Get-Date
            Write-Host "   [*] Launching collector via WMI (Win32_Process)..." -ForegroundColor Yellow
            $command = "powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\Hardware\SSD_Forensics\Invoke-SSDForensics.ps1"
            
            $wmiProcess = [wmiclass]"\\$ip\root\cimv2:Win32_Process"
            $execResult = $wmiProcess.Create($command)

            if ($execResult.ReturnValue -ne 0) {
                Write-Warning "   [-] WMI process creation failed with return code: $($execResult.ReturnValue)"
                continue
            }

            Write-Host "   [+] Process spawned successfully (PID: $($execResult.ProcessId)). Waiting for diagnostics to finish..." -ForegroundColor Green

            # 4. Wait / Poll for Results File Generation
            $elapsed = 0
            $foundReport = $false
            while ($elapsed -lt $TimeoutSeconds) {
                Start-Sleep -Seconds 2
                $elapsed += 2

                # Look for updated diagnostic report or telemetry summary
                $remoteSummary = Get-ChildItem -Path $remoteDir -Filter "telemetry_summary_*.json" -File -ErrorAction SilentlyContinue |
                                 Where-Object { $_.LastWriteTime -ge $preRunTime } |
                                 Select-Object -First 1

                if ($remoteSummary) {
                    $foundReport = $true
                    break
                }
            }

            if ($foundReport) {
                Write-Host "   [+] Execution completed in ${elapsed}s! Fetching results..." -ForegroundColor Green
                Get-ChildItem -Path $remoteDir -File -ErrorAction SilentlyContinue | ForEach-Object {
                    Copy-Item -Path $_.FullName -Destination $OutputDir -Force
                }
                Write-Host "   [+] Copied forensic artifacts into $OutputDir" -ForegroundColor Green
            } else {
                Write-Warning "   [-] Timed out after ${TimeoutSeconds}s waiting for diagnostics to finish on $ip."
                # Attempt best-effort copy of whatever files exist
                Copy-Item -Path "$remoteDir\*" -Destination $OutputDir -Recurse -Force -ErrorAction SilentlyContinue
            }

        } catch {
            Write-Warning "   [-] Remote execution error on ${ip}: $($_.Exception.Message)"
        }
    }
}

# Run Centralized Aggregator
Write-Host "`n[*] Aggregating fleet telemetry across collected stations..." -ForegroundColor Yellow
$aggregatorScript = Join-Path $PSScriptRoot "Aggregate-SSDForensics.ps1"
if (Test-Path $aggregatorScript) {
    & $aggregatorScript -InputDir $OutputDir
}

Write-Host "`n[+] Fleet forensics run complete. Results available in: $OutputDir" -ForegroundColor Green
