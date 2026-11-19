<#
.SYNOPSIS
    Remote Fleet Orchestrator for SSD Forensics Collection
.DESCRIPTION
    Deploys and executes Invoke-SSDForensics.ps1 across a list of target IPs
    or production lines, retrieves the resulting forensic JSON/CSV archives,
    and triggers Aggregate-SSDForensics.ps1 to generate a cross-station RCA matrix.
.PARAMETER TargetIps
    Array of target PC IP addresses or hostnames.
.PARAMETER OutputDir
    Centralized collection folder for fleet telemetry.
.PARAMETER Credential
    PSCredential for remote administration (optional if running in domain/trusted admin context).
#>

[CmdletBinding()]
param(
    [string[]]$TargetIps = @(),
    [string]$OutputDir = "",
    [System.Management.Automation.PSCredential]$Credential = $null
)

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

# If no target IPs provided, offer local execution or prompt
if ($TargetIps.Count -eq 0) {
    Write-Host "No remote IPs specified. Collecting local machine ($env:COMPUTERNAME) telemetry..." -ForegroundColor Yellow
    & $collectorScript -OutputDir $OutputDir
} else {
    foreach ($ip in $TargetIps) {
        Write-Host "`n[*] Deploying forensic collector to $ip..." -ForegroundColor Cyan
        try {
            $destShare = "\\$ip\C$\Temp\SSD_Diagnostics"
            if (-not (Test-Path $destShare)) {
                New-Item -ItemType Directory -Path $destShare -Force -ErrorAction Stop | Out-Null
            }
            Copy-Item -Path $collectorScript -Destination "$destShare\Invoke-SSDForensics.ps1" -Force
            
            # Execute remotely via Invoke-Command (WinRM)
            Write-Host "   [*] Executing collector remotely on $ip..." -ForegroundColor Yellow
            $sb = {
                powershell.exe -ExecutionPolicy Bypass -File "C:\Temp\SSD_Diagnostics\Invoke-SSDForensics.ps1" -OutputDir "C:\Temp\SSD_Diagnostics\Output"
            }
            
            if ($Credential) {
                Invoke-Command -ComputerName $ip -Credential $Credential -ScriptBlock $sb -ErrorAction Stop
            } else {
                Invoke-Command -ComputerName $ip -ScriptBlock $sb -ErrorAction Stop
            }

            # Pull back generated results
            $remoteOutput = "\\$ip\C$\Temp\SSD_Diagnostics\Output"
            if (Test-Path $remoteOutput) {
                Write-Host "   [+] Fetching results from $ip..." -ForegroundColor Green
                Copy-Item -Path "$remoteOutput\*" -Destination $OutputDir -Recurse -Force
            }
        } catch {
            Write-Warning "   [-] Failed to collect from ${ip}: $($_.Exception.Message)"
        }
    }
}

# Run Centralized Aggregator
Write-Host "`n[*] Aggregating fleet telemetry..." -ForegroundColor Yellow
$aggregatorScript = Join-Path $PSScriptRoot "Aggregate-SSDForensics.ps1"
if (Test-Path $aggregatorScript) {
    & $aggregatorScript -InputDir $OutputDir
}

Write-Host "`n[+] Fleet forensics run complete. Results available in: $OutputDir" -ForegroundColor Green
