# ==========================================
# 1. Configuration & Variables
# ==========================================
$sftpIp = "172.16.207.14"
$sftpPort = "22"
$sftpUser = "svc_otsftp"
$sftpPass = "z#&A2#@VEAq&krt"

$backupRoot = "C:\Backup"
$xmlPath = "C:\Hardware\PC_Info.xml"
$logFile = "C:\Backup\BackupLog.txt"

# Ensure the temp backup directory exists
if (-not (Test-Path -Path $backupRoot)) {
    New-Item -ItemType Directory -Path $backupRoot | Out-Null
}

# --- Logging Function ---
function Write-Log {
    param(
        [string]$Message,
        [string]$Level = "INFO"
    )
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $logEntry = "[$timestamp] [$Level] $Message"
    Add-Content -Path $logFile -Value $logEntry
    Write-Host $logEntry
}

Write-Log "--- Starting Daily Backup Routine ---"

try {
    # ==========================================
    # 2. Parse LabVIEW XML for Variables
    # ==========================================
    if (-not (Test-Path -Path $xmlPath)) {
        throw "XML configuration file not found at $xmlPath"
    }

    [xml]$xmlData = Get-Content -Path $xmlPath

    $plantStr = ($xmlData.LVData.Cluster.String | Where-Object { $_.Name -eq 'P_str' }).Val
    $lineStr  = ($xmlData.LVData.Cluster.String | Where-Object { $_.Name -eq 'L_str' }).Val
    $tierStr  = ($xmlData.LVData.Cluster.String | Where-Object { $_.Name -eq 'T1_str' }).Val

    $tierPrefix = $tierStr.Substring(0,1)
    $zipFileName = "$lineStr$tierPrefix.zip"
    $zipFilePath = Join-Path -Path $backupRoot -ChildPath $zipFileName

    Write-Log "Parsed XML - Plant: $plantStr, Line: $lineStr, Tier: $tierStr. Target ZIP: $zipFileName"

    # ==========================================
    # 3. Stage Files for Zipping
    # ==========================================
    $stagingDir = Join-Path -Path $backupRoot -ChildPath "Staging_$lineStr"
    if (Test-Path -Path $stagingDir) { Remove-Item -Path $stagingDir -Recurse -Force }
    New-Item -ItemType Directory -Path $stagingDir | Out-Null

    Write-Log "Staging files into temporary directory..."

    # --- Copy C:\Accusys (Excluding HAIVIs\log folder) ---
    $accusysDest = Join-Path -Path $stagingDir -ChildPath "Accusys"
    New-Item -ItemType Directory -Path $accusysDest | Out-Null
    
    if (Test-Path "C:\Accusys") {
        if (Test-Path "C:\Accusys\HAIVIs") {
            Write-Log "C:\Accusys\HAIVIs found. Proceeding to copy C:\Accusys while excluding the large HAIVIs\log directory..." "INFO"
        } else {
            Write-Log "C:\Accusys\HAIVIs not found. Proceeding to copy C:\Accusys..." "INFO"
        }
        
        # Using robocopy for fast, memory-efficient directory exclusion
        # /E: Copy Subdirectories, including empty ones
        # /XD: Exclude Directory
        # /R:0 /W:0: Retry 0 times, Wait 0 seconds (prevents hanging on locked files)
        # /NFL /NDL /NJH /NJS: Suppress standard robocopy console output
        $roboArgs = @("C:\Accusys", $accusysDest, "/E", "/XD", "C:\Accusys\HAIVIs\log", "/R:0", "/W:0", "/NFL", "/NDL", "/NJH", "/NJS")
        & robocopy $roboArgs | Out-Null
        
        # Robocopy uses unique exit codes. Anything less than 8 is considered successful.
        if ($LASTEXITCODE -ge 8) {
            Write-Log "Robocopy encountered an error while copying C:\Accusys (Exit Code: $LASTEXITCODE)" "ERROR"
        }
    } else { 
        Write-Log "C:\Accusys not found. Skipping." "WARNING" 
    }

    # --- Copy C:\Hardware ---
    if (Test-Path "C:\Hardware") {
        Copy-Item -Path "C:\Hardware" -Destination $stagingDir -Recurse -Force
    } else { Write-Log "C:\Hardware not found. Skipping." "WARNING" }

    # --- Copy C:\Program1 (Entire Folder) ---
    if (Test-Path "C:\Program1") {
        Copy-Item -Path "C:\Program1" -Destination $stagingDir -Recurse -Force
    } else { Write-Log "C:\Program1 not found. Skipping." "WARNING" }

    # --- Copy C:\inetpub\wwwroot\NGCDashboard (Optional Folder) ---
    $dashboardPath = "C:\inetpub\wwwroot\NGCDashboard"
    if (Test-Path $dashboardPath) {
        Copy-Item -Path $dashboardPath -Destination $stagingDir -Recurse -Force
        Write-Log "C:\inetpub\wwwroot\NGCDashboard found and staged." "INFO"
    } else { 
        Write-Log "C:\inetpub\wwwroot\NGCDashboard not found. Skipping." "INFO" 
    }

    # ==========================================
    # 4. Compress the Directory (Throttled for E-Cores)
    # ==========================================
    $currentProcess = [System.Diagnostics.Process]::GetCurrentProcess()
    $originalPriority = $currentProcess.PriorityClass
    $originalAffinity = $currentProcess.ProcessorAffinity

    try {
        $currentProcess.PriorityClass = [System.Diagnostics.ProcessPriorityClass]::Idle
        $currentProcess.ProcessorAffinity = [System.IntPtr]::new(1) 

        if (Test-Path -Path $zipFilePath) { Remove-Item -Path $zipFilePath -Force }
        
        Write-Log "Zipping files in background (throttled)..."
        Compress-Archive -Path "$stagingDir\*" -DestinationPath $zipFilePath -Force
        Write-Log "Zip file created successfully at $zipFilePath"

    } finally {
        $currentProcess.PriorityClass = $originalPriority
        $currentProcess.ProcessorAffinity = $originalAffinity
    }

    # Clean up the staging directory
    Remove-Item -Path $stagingDir -Recurse -Force

    # ==========================================
    # 5. SFTP Transfer (Using WinSCP)
    # ==========================================
    $remoteDir = "/BACKUP/Plant_$plantStr"
    $winscpScriptPath = Join-Path -Path $backupRoot -ChildPath "sftp_script.txt"

    $winscpScript = @"
option batch continue
option confirm off
open sftp://${sftpUser}:${sftpPass}@${sftpIp}:${sftpPort} -hostkey="*"
mkdir "$remoteDir"
option batch abort
put "$zipFilePath" "$remoteDir/"
exit
"@

    $winscpScript | Out-File -FilePath $winscpScriptPath -Encoding ASCII
    $winscpPath = "C:\Program Files (x86)\WinSCP\WinSCP.com"

    if (Test-Path $winscpPath) {
        Write-Log "Initiating SFTP transfer to $sftpIp..."
        $process = Start-Process -FilePath $winscpPath -ArgumentList "/script=`"$winscpScriptPath`"" -Wait -NoNewWindow -PassThru
        
        # ==========================================
        # 6. Post-Transfer Cleanup
        # ==========================================
        if ($process.ExitCode -eq 0) {
            Write-Log "SFTP transfer successful. Removing local .zip file."
            Remove-Item -Path $zipFilePath -Force
        } else {
            Write-Log "SFTP transfer failed (Exit Code: $($process.ExitCode)). Local .zip file retained for manual inspection." "ERROR"
        }
    } else {
        Write-Log "WinSCP.com not found at $winscpPath. Please verify your WinSCP installation path." "ERROR"
    }

    if (Test-Path $winscpScriptPath) { Remove-Item -Path $winscpScriptPath -Force }

    Write-Log "--- Daily Backup Routine Finished ---"

} catch {
    # If a major error occurs anywhere in the script, it gets caught and logged here
    Write-Log "A fatal error occurred: $_" "ERROR"
    Write-Log "--- Daily Backup Routine Failed ---" "ERROR"
}