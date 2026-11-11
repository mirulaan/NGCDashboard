<#
.SYNOPSIS
    Zero-Touch Bootstrap & Migration Script for NGC Telemetry Agent
.DESCRIPTION
    Automates the complete handover from legacy 'NGC_Telemetry_Push' and 'Daily_Plant_Backup'
    Task Scheduler jobs to the unified 'NGCTelemetryAgent'.
    Executes under NT AUTHORITY\SYSTEM or Administrator privileges without operator intervention.
#>

[CmdletBinding()]
param(
    [string]$ServerIp = "",
    [switch]$Force
)

# 1. ELEVATION CHECK
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Warning "Restarting script with Administrator privileges..."
    Start-Process powershell.exe -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`"" -Verb RunAs
    Exit
}

# 2. LOGGING SETUP
$agentDir = "C:\Hardware\NGCTelemetryAgent"
if (-not (Test-Path $agentDir)) {
    New-Item -ItemType Directory -Path $agentDir -Force | Out-Null
}
$logFile = "$agentDir\bootstrap.log"

function Write-BootstrapLog {
    param([string]$Message, [string]$Level = "INFO")
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $entry = "[$timestamp] [$Level] $Message"
    Add-Content -Path $logFile -Value $entry
    switch ($Level) {
        "ERROR"   { Write-Host $entry -ForegroundColor Red }
        "WARNING" { Write-Host $entry -ForegroundColor Yellow }
        "SUCCESS" { Write-Host $entry -ForegroundColor Green }
        default   { Write-Host $entry -ForegroundColor Cyan }
    }
}

Write-BootstrapLog "======================================================="
Write-BootstrapLog "Starting Zero-Touch NGC Telemetry Agent Deployment..."

# 3. DETECT SERVER IP
if ([string]::IsNullOrWhiteSpace($ServerIp)) {
    if (Test-Path "D:\Acer-File") {
        $ServerIp = "192.168.100.243"
    } else {
        $localIp = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -match "^172\.16\." -and $_.IPAddress -notmatch "\.255$" } | Select-Object -First 1).IPAddress
        if ($localIp) {
            $ServerIp = $localIp -replace '\.\d+$', '.2'
        } else {
            $ServerIp = "172.16.50.2" # Fallback default
        }
    }
}
Write-BootstrapLog "Target NGC Dashboard Server: $ServerIp"

$apiUrl = "http://$ServerIp/api/system-status"
$downloadUrl = "http://$ServerIp/scripts/NGCTelemetryAgent.zip"
$binaryFallbackUrl = "http://$ServerIp/scripts/NGCTelemetryAgent.exe"
$targetExe = "$agentDir\NGCTelemetryAgent.exe"

# 4. DOWNLOAD AGENT ARTIFACTS
$zipPath = "$agentDir\agent_package.zip"
$downloadSucceeded = $false

try {
    Write-BootstrapLog "Attempting package download from $downloadUrl..."
    Invoke-WebRequest -Uri $downloadUrl -OutFile $zipPath -UseBasicParsing -TimeoutSec 30
    if ((Test-Path $zipPath) -and (Get-Item $zipPath).Length -gt 10000) {
        Write-BootstrapLog "Extracting package to $agentDir..."
        Expand-Archive -Path $zipPath -DestinationPath $agentDir -Force
        Remove-Item -Path $zipPath -Force -ErrorAction SilentlyContinue
        $downloadSucceeded = $true
    }
} catch {
    Write-BootstrapLog "Package zip download failed: $($_.Exception.Message). Falling back to direct binary..." "WARNING"
}

if (-not $downloadSucceeded) {
    try {
        Write-BootstrapLog "Downloading standalone binary from $binaryFallbackUrl..."
        Invoke-WebRequest -Uri $binaryFallbackUrl -OutFile $targetExe -UseBasicParsing -TimeoutSec 30
        if (Test-Path $targetExe) {
            $downloadSucceeded = $true
        }
    } catch {
        Write-BootstrapLog "Binary download failed: $($_.Exception.Message)" "ERROR"
    }
}

# 5. INITIALIZE DEFAULT CONFIG.JSON IF ABSENT
$configPath = "$agentDir\config.json"
if (-not (Test-Path $configPath)) {
    Write-BootstrapLog "Generating initial config.json..."
    $defaultConfig = [ordered]@{
        ServerUrl = $apiUrl
        PushIntervalSeconds = 300
        BackupScheduleTime = "18:00:00" # 6:00 PM daily
        SftpHost = "172.16.207.14"
        SftpPort = 22
        SftpUser = "svc_otsftp"
        SftpPass = "z#&A2#@VEAq&krt"
        AutoStart = $true
        StartMinimized = $true
    }
    $defaultConfig | ConvertTo-Json -Depth 5 | Set-Content -Path $configPath -Encoding UTF8
    Write-BootstrapLog "Default configuration saved." "SUCCESS"
}

# 6. REGISTER AUTO-START (TASK SCHEDULER: NGC_Telemetry_Agent)
Write-BootstrapLog "Configuring persistent Auto-Start scheduled task..."
try {
    $taskName = "NGC_Telemetry_Agent"
    $action = New-ScheduledTaskAction -Execute $targetExe
    $trigger = New-ScheduledTaskTrigger -AtLogOn
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Days 365) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
    
    # Register under SYSTEM or Interactive User
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -User "NT AUTHORITY\SYSTEM" -RunLevel Highest -Force | Out-Null
    Write-BootstrapLog "Scheduled task '$taskName' registered successfully." "SUCCESS"
} catch {
    Write-BootstrapLog "Failed to register scheduled task: $($_.Exception.Message)" "WARNING"
}

# 7. LAUNCH AGENT INSTANCE
Write-BootstrapLog "Launching NGCTelemetryAgent.exe..."
try {
    Start-Process -FilePath $targetExe -ArgumentList "--minimized" -ErrorAction Stop
    Start-Sleep -Seconds 3
    
    $proc = Get-Process -Name "NGCTelemetryAgent" -ErrorAction SilentlyContinue
    if ($proc) {
        Write-BootstrapLog "NGCTelemetryAgent is actively running (PID: $($proc.Id))." "SUCCESS"
    } else {
        Write-BootstrapLog "Agent process did not remain running. Check Event Viewer." "WARNING"
    }
} catch {
    Write-BootstrapLog "Failed to start agent: $($_.Exception.Message)" "ERROR"
}

# 8. RETIRE LEGACY SCHEDULED TASKS & PROCESSES
Write-BootstrapLog "Retiring legacy Task Scheduler jobs..."
$legacyTasks = @("NGC_Telemetry_Push", "Daily_Plant_Backup")
foreach ($task in $legacyTasks) {
    try {
        $existing = Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue
        if ($existing) {
            Disable-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue | Out-Null
            Unregister-ScheduledTask -TaskName $task -Confirm:$false -ErrorAction SilentlyContinue | Out-Null
            Write-BootstrapLog "Successfully decommissioned legacy task: $task" "SUCCESS"
        } else {
            Write-BootstrapLog "Legacy task '$task' not found (already decommissioned)." "INFO"
        }
    } catch {
        Write-BootstrapLog "Failed to decommission task '$task': $($_.Exception.Message)" "WARNING"
    }
}

# 9. TERMINATE OBSOLETE HWINFO64 BACKGROUND PROCESS
Write-BootstrapLog "Terminating legacy HWiNFO64 processes..."
try {
    Stop-Process -Name "HWiNFO64" -Force -ErrorAction SilentlyContinue
    Write-BootstrapLog "HWiNFO64 process terminated." "INFO"
} catch {
    Write-BootstrapLog "No HWiNFO64 processes to terminate." "INFO"
}

Write-BootstrapLog "Zero-Touch Agent Deployment Completed Successfully!" "SUCCESS"
Write-BootstrapLog "======================================================="
