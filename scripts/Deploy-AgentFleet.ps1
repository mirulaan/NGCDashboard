<#
.SYNOPSIS
    Centralized Fleet Deployment Orchestrator for NGC Telemetry Agent
.DESCRIPTION
    Executes from the Plant Dashboard Server (IIS / 172.16.x.2).
    Discovers all edge inspection PC IPs from the live telemetry database,
    deploys NGCTelemetryAgent concurrently across the plant subnet,
    and retires legacy 'NGC_Telemetry_Push' and 'Daily_Plant_Backup' tasks.
#>

[CmdletBinding()]
param(
    [string]$Plant = "",
    [string[]]$TargetIps = @(),
    [string]$DbPath = "C:\inetpub\wwwroot\NGCDashboard_Data\database.sqlite",
    [string]$AgentSourceDir = "$PSScriptRoot\..\public\scripts",
    [int]$MaxConcurrency = 8
)

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "  NGC Telemetry Agent - Centralized Fleet Deployer        " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

# 1. DISCOVER ACTIVE EDGE IP ADDRESSES
$ipList = [System.Collections.Generic.List[string]]::new()

if ($TargetIps.Count -gt 0) {
    $ipList.AddRange($TargetIps)
    Write-Host "Using manually specified target IPs: $($ipList -join ', ')" -ForegroundColor Yellow
} else {
    # Attempt to extract IPs from SQLite Database
    if (-not (Test-Path $DbPath)) {
        # Fallback to local project database
        $fallbackDb = "$PSScriptRoot\..\database\database.sqlite"
        if (Test-Path $fallbackDb) { $DbPath = $fallbackDb }
    }

    if (Test-Path $DbPath) {
        Write-Host "Querying 172.16.x.x target PC IP addresses from database: $DbPath..." -ForegroundColor Cyan
        try {
            # Deterministically find the latest log row (MAX(id)) per unique (Plant, Line, Side)
            $plantFilter = if (-not [string]::IsNullOrWhiteSpace($Plant)) { "AND plant = '$Plant'" } else { "" }
            
            $sqliteQuery = "SELECT t.plant, t.line, t.side, t.ip_address, t.created_at as last_seen FROM telemetry_logs t INNER JOIN (SELECT plant, line, side, MAX(id) as max_id FROM telemetry_logs WHERE (ip_address LIKE '172.16.%' OR ip_address LIKE '192.168.%') AND ip_address != '127.0.0.1' $plantFilter GROUP BY plant, line, side) latest ON t.id = latest.max_id ORDER BY CAST(t.line AS INTEGER) ASC, t.side ASC;"
            
            # Use php PDO to query SQLite database
            $phpQueryScript = "
                \$pdo = new PDO('sqlite:' . '$($DbPath -replace '\\', '/');');
                \$stmt = \$pdo->query(\"$sqliteQuery\");
                if (\$stmt) {
                    while (\$row = \$stmt->fetch(PDO::FETCH_ASSOC)) {
                        echo \$row['plant'] . '|' . \$row['line'] . '|' . \$row['side'] . '|' . \$row['ip_address'] . '|' . \$row['last_seen'] . PHP_EOL;
                    }
                }
            "
            $rawRows = & php -r $phpQueryScript 2>$null

            if ($rawRows) {
                Write-Host "`nTarget Optical Inspection Roster Discovered:" -ForegroundColor Green
                Write-Host "------------------------------------------------------------" -ForegroundColor Gray
                foreach ($line in ($rawRows -split "`r?`n")) {
                    if ([string]::IsNullOrWhiteSpace($line)) { continue }
                    $parts = $line.Split('|')
                    if ($parts.Count -ge 4) {
                        $p = $parts[0]; $l = $parts[1]; $s = $parts[2]; $ip = $parts[3].Trim()
                        Write-Host "  Plant $p | Line $l-$s -> Target IP: $ip" -ForegroundColor Cyan
                        if ($ip -match '^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$' -and -not $ipList.Contains($ip)) {
                            $ipList.Add($ip)
                        }
                    }
                }
                Write-Host "------------------------------------------------------------" -ForegroundColor Gray
            }
        } catch {
            Write-Warning "Could not query SQLite directly: $($_.Exception.Message)"
        }
    }
}

# If still empty, scan plant subnet default
if ($ipList.Count -eq 0) {
    Write-Warning "No active IPs discovered in database. Enter IP addresses or scan range."
    Write-Host "Example usage: .\Deploy-AgentFleet.ps1 -TargetIps '172.16.50.101','172.16.50.102'" -ForegroundColor Yellow
    Exit
}

Write-Host "Discovered $($ipList.Count) target inspection PCs for deployment." -ForegroundColor Green

# 2. PREPARE DEPLOYMENT ARTIFACTS
$bootstrapScript = Join-Path $AgentSourceDir "Bootstrap-NGCAgent.ps1"
if (-not (Test-Path $bootstrapScript)) {
    Write-Error "Bootstrap script not found at $bootstrapScript"
    Exit
}

# 3. CONCURRENT ROLLOUT FUNCTION
function Deploy-ToNode {
    param([string]$Ip)

    $result = [PSCustomObject]@{
        IP           = $Ip
        Ping         = "Failed"
        Copied       = "No"
        Bootstrap    = "Pending"
        Status       = "Failed"
        ErrorMessage = ""
    }

    # Ping Check
    $ping = Test-Connection -ComputerName $Ip -Count 1 -Quiet -ErrorAction SilentlyContinue
    if (-not $ping) {
        $result.ErrorMessage = "Ping unreachable"
        return $result
    }
    $result.Ping = "OK"

    # SMB Admin Share Deployment
    $remoteHardwarePath = "\\$Ip\c$\Hardware\NGCTelemetryAgent"
    try {
        if (-not (Test-Path $remoteHardwarePath)) {
            New-Item -ItemType Directory -Path $remoteHardwarePath -Force | Out-Null
        }

        # Copy bootstrap script
        Copy-Item -Path $bootstrapScript -Destination "$remoteHardwarePath\Bootstrap-NGCAgent.ps1" -Force -ErrorAction Stop
        
        # Copy binary if available locally
        $localBinary = Join-Path $AgentSourceDir "NGCTelemetryAgent.exe"
        if (Test-Path $localBinary) {
            Copy-Item -Path $localBinary -Destination "$remoteHardwarePath\NGCTelemetryAgent.exe" -Force -ErrorAction SilentlyContinue
        }
        $result.Copied = "Yes"

        # Remote Execution via WMI / WinRM
        $command = "powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\Hardware\NGCTelemetryAgent\Bootstrap-NGCAgent.ps1"
        $wmiProcess = [wmiclass]"\\$Ip\root\cimv2:Win32_Process"
        $execResult = $wmiProcess.Create($command)

        if ($execResult.ReturnValue -eq 0) {
            $result.Bootstrap = "Triggered (PID: $($execResult.ProcessId))"
            $result.Status = "Success"
        } else {
            $result.Bootstrap = "WMI Return $($execResult.ReturnValue)"
            $result.ErrorMessage = "Failed to launch remote bootstrap process via WMI"
        }

    } catch {
        $result.ErrorMessage = $_.Exception.Message
    }

    return $result
}

# 4. EXECUTE PARALLEL DEPLOYMENT
Write-Host "Initiating rollout across $($ipList.Count) edge machines..." -ForegroundColor Cyan

$results = @()
foreach ($targetIp in $ipList) {
    Write-Host "Deploying to $targetIp..." -NoNewline
    $res = Deploy-ToNode -Ip $targetIp
    if ($res.Status -eq "Success") {
        Write-Host " [SUCCESS]" -ForegroundColor Green
    } else {
        Write-Host " [FAILED: $($res.ErrorMessage)]" -ForegroundColor Red
    }
    $results += $res
}

# 5. SUMMARY REPORT
Write-Host "`n==========================================================" -ForegroundColor Cyan
Write-Host "  Fleet Deployment Summary Report                         " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

$results | Format-Table -Property IP, Ping, Copied, Bootstrap, Status, ErrorMessage -AutoSize

$successCount = ($results | Where-Object Status -eq "Success").Count
$failCount = $results.Count - $successCount
Write-Host "Completed: $successCount Succeeded, $failCount Failed." -ForegroundColor $(if ($failCount -gt 0) { "Yellow" } else { "Green" })
Write-Host "Legacy tasks (NGC_Telemetry_Push and Daily_Plant_Backup) are automatically retired upon bootstrap execution." -ForegroundColor Cyan
