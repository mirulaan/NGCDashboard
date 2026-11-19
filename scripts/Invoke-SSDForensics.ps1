<#
.SYNOPSIS
    SSD Fleet Root-Cause Diagnostics & Telemetry Collector
.DESCRIPTION
    Collects structured telemetry across NVMe/SATA SSDs, Windows kernel/storage logs,
    application workload write rates, and over-provisioning states to isolate
    the root cause of SSD failures (wear-out, controller bus drop, thermal throttling,
    power loss, or firmware bugs).
.PARAMETER OutputDir
    Destination folder for collected telemetry JSON, CSV, and diagnostic reports.
.PARAMETER LogDays
    Number of days of event log history to analyze (default: 30 days).
.PARAMETER ExportEvtx
    Switch to export raw System.evtx log for deep forensic analysis.
#>

[CmdletBinding()]
param(
    [string]$OutputDir = "C:\Hardware\SSD_Forensics",
    [int]$LogDays = 30,
    [switch]$ExportEvtx
)

# 0. Ensure Admin Privileges
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Warning "WARNING: Not running as Administrator. SMART NVMe pass-through and Event Logs require elevated privileges."
}

# 1. Setup Output Directory
$hostName = $env:COMPUTERNAME
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
if ([string]::IsNullOrWhiteSpace($OutputDir)) {
    $OutputDir = "C:\Hardware\SSD_Forensics"
}
if (-not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}
$OutputDir = (Resolve-Path $OutputDir).Path

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "   SSD Fleet Forensic Telemetry Collector" -ForegroundColor Cyan
Write-Host "   Host: $hostName | Timestamp: $timestamp" -ForegroundColor Cyan
Write-Host "   Output Directory: $OutputDir" -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

# 2. Locate smartctl
$smartctlPath = "C:\Program Files\smartmontools\bin\smartctl.exe"
if (-not (Test-Path $smartctlPath)) {
    $smartctlPath = "C:\Program Files (x86)\smartmontools\bin\smartctl.exe"
}
if (-not (Test-Path $smartctlPath)) {
    $cmd = Get-Command smartctl -ErrorAction SilentlyContinue
    if ($cmd) { $smartctlPath = $cmd.Source }
}

$hasSmartctl = ($null -ne $smartctlPath -and (Test-Path $smartctlPath))
if ($hasSmartctl) {
    Write-Host "[+] Found smartctl at: $smartctlPath" -ForegroundColor Green
} else {
    Write-Warning "[-] smartctl not found in standard paths or PATH. Limited to WMI/CIM telemetry."
}

# -------------------------------------------------------------
# MODULE 1: Drive Hardware & SMART / NVMe Telemetry
# -------------------------------------------------------------
Write-Host "`n[*] Module 1: Extracting Drive Hardware & SMART Telemetry..." -ForegroundColor Yellow

$disksData = [System.Collections.Generic.List[PSObject]]::new()
$scannedTargets = @()

if ($hasSmartctl) {
    # Scan available targets via smartctl --scan
    $scanOutput = & $smartctlPath --scan 2>$null
    foreach ($line in $scanOutput) {
        if ($line -match '^(\S+)\s+(-d\s+\S+)?') {
            $deviceTarget = $matches[1]
            $deviceType = $matches[2]
            $scannedTargets += @{ Target = $deviceTarget; TypeFlag = $deviceType }
        }
    }
}

# Also ensure physical drive numbers 0..7 are checked if scan missed them
0..7 | ForEach-Object {
    $pd = "pd$_"
    if (-not ($scannedTargets | Where-Object { $_.Target -eq $pd -or $_.Target -eq "/dev/$pd" })) {
        $scannedTargets += @{ Target = $pd; TypeFlag = "" }
    }
}

$processedSerials = [System.Collections.Generic.HashSet[string]]::new()

foreach ($item in $scannedTargets) {
    $target = $item.Target
    $targetArgs = @("-x", "-j", $target)
    if (-not [string]::IsNullOrWhiteSpace($item.TypeFlag)) {
        $flags = $item.TypeFlag -split '\s+'
        $targetArgs = $flags + $targetArgs
    }

    try {
        $rawJsonText = & $smartctlPath $targetArgs 2>$null
        if (-not $rawJsonText) { continue }
        
        $jsonStr = $rawJsonText -join "`n"
        $smartObj = $jsonStr | ConvertFrom-Json -ErrorAction SilentlyContinue
        if (-not $smartObj -or -not $smartObj.model_name) { continue }

        $serial = [string]$smartObj.serial_number
        if ($processedSerials.Contains($serial)) { continue }
        $processedSerials.Add($serial) | Out-Null

        # Save individual raw json
        $safeTargetName = ($target -replace '[\\/:]', '_')
        $rawDumpPath = Join-Path $OutputDir "raw_smartctl_${safeTargetName}.json"
        $jsonStr | Out-File -FilePath $rawDumpPath -Encoding utf8

        # Extract NVMe Error Log
        $errorLogText = & $smartctlPath -l error $target 2>$null
        $errorLogDumpPath = Join-Path $OutputDir "nvme_error_log_${safeTargetName}.txt"
        $errorLogText | Out-File -FilePath $errorLogDumpPath -Encoding utf8

        # Identity
        $model = [string]$smartObj.model_name
        $firmware = [string]$smartObj.firmware_version
        $pciVendor = [string]$smartObj.nvme_pci_vendor.id
        $controllerId = [string]$smartObj.nvme_controller_id
        $nvmeVersion = [string]$smartObj.nvme_version

        # NVMe SMART Health Log
        $nvmeLog = $smartObj.nvme_smart_health_information_log
        $dataUnitsWritten = if ($nvmeLog.data_units_written) { [double]$nvmeLog.data_units_written } else { 0 }
        $dataUnitsRead = if ($nvmeLog.data_units_read) { [double]$nvmeLog.data_units_read } else { 0 }
        
        # 1 Data Unit = 1000 * 512 bytes = 512,000 bytes = ~500 KB
        $tbWritten = [math]::Round(($dataUnitsWritten * 512000) / 1TB, 3)
        $tbRead = [math]::Round(($dataUnitsRead * 512000) / 1TB, 3)
        $pctUsed = if ($null -ne $nvmeLog.percentage_used) { [int]$nvmeLog.percentage_used } else { -1 }
        $availSpare = if ($null -ne $nvmeLog.available_spare) { [int]$nvmeLog.available_spare } else { -1 }
        $spareThreshold = if ($null -ne $nvmeLog.available_spare_threshold) { [int]$nvmeLog.available_spare_threshold } else { -1 }

        # Failure & Error Counters
        $critWarningRaw = if ($null -ne $nvmeLog.critical_warning) { [int]$nvmeLog.critical_warning } else { 0 }
        $critWarningHex = "0x{0:X2}" -f $critWarningRaw
        $critFlags = [ordered]@{
            SpareBelowThreshold = [bool]($critWarningRaw -band 0x01)
            TemperatureExceeded = [bool]($critWarningRaw -band 0x02)
            ReliabilityDegraded = [bool]($critWarningRaw -band 0x04)
            MediaReadOnly       = [bool]($critWarningRaw -band 0x08)
            VolatileBackupFail  = [bool]($critWarningRaw -band 0x10)
        }

        $mediaErrors = if ($null -ne $nvmeLog.media_errors) { [int64]$nvmeLog.media_errors } else { 0 }
        $numErrLogEntries = if ($null -ne $nvmeLog.num_err_log_entries) { [int64]$nvmeLog.num_err_log_entries } else { 0 }

        # Power & Thermal History
        $unsafeShutdowns = if ($null -ne $nvmeLog.unsafe_shutdowns) { [int64]$nvmeLog.unsafe_shutdowns } else { 0 }
        $powerCycles = if ($null -ne $nvmeLog.power_cycles) { [int64]$nvmeLog.power_cycles } else { 0 }
        $powerOnHours = if ($null -ne $nvmeLog.power_on_hours) { [int64]$nvmeLog.power_on_hours } else { 0 }
        
        $compositeTemp = 0
        if ($smartObj.temperature -and $smartObj.temperature.current) {
            $compositeTemp = [int]$smartObj.temperature.current
        } elseif ($nvmeLog.temperature) {
            $compositeTemp = [int]$nvmeLog.temperature
        }
        $warningTempTime = if ($null -ne $nvmeLog.warning_temp_time) { [int64]$nvmeLog.warning_temp_time } else { 0 }
        $criticalTempTime = if ($null -ne $nvmeLog.critical_comp_error_temp_time) { [int64]$nvmeLog.critical_comp_error_temp_time } else { 0 }

        # Calculate write rate (TB/day) over drive lifetime
        $pohDays = if ($powerOnHours -gt 0) { $powerOnHours / 24.0 } else { 0 }
        $tbPerDay = if ($pohDays -gt 0) { [math]::Round($tbWritten / $pohDays, 4) } else { 0 }
        $unsafeRatio = if ($powerCycles -gt 0) { [math]::Round(($unsafeShutdowns / $powerCycles) * 100, 1) } else { 0 }

        $diskRecord = [ordered]@{
            TargetDevice              = $target
            ModelNumber               = $model
            SerialNumber              = $serial
            FirmwareVersion           = $firmware
            PciVendorId               = $pciVendor
            ControllerId              = $controllerId
            NvmeVersion               = $nvmeVersion
            CapacityGB                = [math]::Round(($smartObj.user_capacity.bytes / 1GB), 2)
            DataUnitsWrittenTB        = $tbWritten
            DataUnitsReadTB           = $tbRead
            PercentageUsed            = $pctUsed
            AvailableSpare            = $availSpare
            AvailableSpareThreshold   = $spareThreshold
            CriticalWarningHex        = $critWarningHex
            CriticalWarningDecoded    = $critFlags
            MediaAndDataErrors        = $mediaErrors
            ErrorLogEntriesCount      = $numErrLogEntries
            UnsafeShutdowns           = $unsafeShutdowns
            PowerCycles               = $powerCycles
            UnsafeShutdownRatioPct    = $unsafeRatio
            PowerOnHours              = $powerOnHours
            CompositeTempCelsius      = $compositeTemp
            WarningTempTimeMinutes    = $warningTempTime
            CriticalTempTimeMinutes   = $criticalTempTime
            CalculatedLifetimeTBPerDay= $tbPerDay
        }

        $disksData.Add((New-Object PSObject -Property $diskRecord))
        Write-Host "   [+] Found $($target): $model ($serial), FW: $firmware, Used: $pctUsed%, TBW: $tbWritten TB, MediaErrors: $mediaErrors, Temp: ${compositeTemp}C" -ForegroundColor Green
    } catch {
        Write-Warning "   [-] Error reading SMART from target $($target): $($_.Exception.Message)"
    }
}

# -------------------------------------------------------------
# MODULE 2: Windows System & Storage Event Logs
# -------------------------------------------------------------
Write-Host "`n[*] Module 2: Querying Windows Storage & Kernel Event Logs (Past $LogDays days)..." -ForegroundColor Yellow

$cutoffDate = (Get-Date).AddDays(-$LogDays)
$eventsList = [System.Collections.Generic.List[PSObject]]::new()
$eventCounts = [ordered]@{
    Stornvme_11_ControllerError   = 0
    Stornvme_129_DeviceReset       = 0
    Disk_153_IORetry               = 0
    Disk_51_PagingError            = 0
    Disk_157_SurpriseRemoval       = 0
    Disk_140_FlushFailed           = 0
    Ntfs_55_Corruption             = 0
    Ntfs_50_DelayedWriteLost       = 0
    KernelPower_41_UnexpectedReboot= 0
    EventLog_6008_UnexpectedShutdown= 0
    BugCheck_1001_BSOD             = 0
}

# Query Target Providers
$storageProviders = @("stornvme", "nvme", "disk", "Ntfs", "Microsoft-Windows-Kernel-Power", "EventLog", "Microsoft-Windows-WER-SystemErrorReporting", "BugCheck")

try {
    $filterXml = @"
<QueryList>
  <Query Id="0" Path="System">
    <Select Path="System">
      *[System[
        (
          (Provider[@Name='stornvme'] and (EventID=11 or EventID=129)) or
          (Provider[@Name='nvme'] and (EventID=11 or EventID=129)) or
          (Provider[@Name='disk'] and (EventID=153 or EventID=51 or EventID=157 or EventID=140)) or
          (Provider[@Name='Ntfs'] and (EventID=55 or EventID=50)) or
          (Provider[@Name='Microsoft-Windows-Kernel-Power'] and (EventID=41)) or
          (Provider[@Name='EventLog'] and (EventID=6008)) or
          (Provider[@Name='Microsoft-Windows-WER-SystemErrorReporting' or Provider[@Name='BugCheck']] and (EventID=1001))
        )
        and TimeCreated[@SystemTime &gt;= '$($cutoffDate.ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ss.000Z"))']
      ]]
    </Select>
  </Query>
</QueryList>
"@

    $rawEvents = Get-WinEvent -FilterXml $filterXml -ErrorAction SilentlyContinue
    if ($rawEvents) {
        foreach ($evt in $rawEvents) {
            $pName = $evt.ProviderName
            $eId = $evt.Id
            $msg = ($evt.Message -replace "[\r\n\t]+", " ").Trim()

            $bugcheckName = ""
            if ($pName -match 'stornvme|nvme' -and $eId -eq 11)  { $eventCounts.Stornvme_11_ControllerError++ }
            elseif ($pName -match 'stornvme|nvme' -and $eId -eq 129) { $eventCounts.Stornvme_129_DeviceReset++ }
            elseif ($pName -eq 'disk' -and $eId -eq 153) { $eventCounts.Disk_153_IORetry++ }
            elseif ($pName -eq 'disk' -and $eId -eq 51)  { $eventCounts.Disk_51_PagingError++ }
            elseif ($pName -eq 'disk' -and $eId -eq 157) { $eventCounts.Disk_157_SurpriseRemoval++ }
            elseif ($pName -eq 'disk' -and $eId -eq 140) { $eventCounts.Disk_140_FlushFailed++ }
            elseif ($pName -eq 'Ntfs' -and $eId -eq 55)  { $eventCounts.Ntfs_55_Corruption++ }
            elseif ($pName -eq 'Ntfs' -and $eId -eq 50)  { $eventCounts.Ntfs_50_DelayedWriteLost++ }
            elseif ($pName -match 'Kernel-Power' -and $eId -eq 41) { $eventCounts.KernelPower_41_UnexpectedReboot++ }
            elseif ($pName -eq 'EventLog' -and $eId -eq 6008) { $eventCounts.EventLog_6008_UnexpectedShutdown++ }
            elseif ($eId -eq 1001) { 
                $eventCounts.BugCheck_1001_BSOD++ 
                if ($msg -match '0x[0-9a-fA-F]{8}') {
                    $code = $matches[0].ToLower()
                    $knownCodes = @{
                        "0x0000009f" = "DRIVER_POWER_STATE_FAILURE"
                        "0x0000007a" = "KERNEL_DATA_INPAGE_ERROR"
                        "0x0000007b" = "INACCESSIBLE_BOOT_DEVICE"
                        "0x000000ef" = "CRITICAL_PROCESS_DIED"
                        "0x00000124" = "WHEA_UNCORRECTABLE_ERROR"
                        "0x000000d1" = "DRIVER_IRQL_NOT_LESS_OR_EQUAL"
                        "0x0000001a" = "MEMORY_MANAGEMENT"
                        "0x00000050" = "PAGE_FAULT_IN_NONPAGED_AREA"
                    }
                    if ($knownCodes.ContainsKey($code)) {
                        $bugcheckName = "$($knownCodes[$code]) ($code)"
                    } else {
                        $bugcheckName = $code
                    }
                }
            }

            $eventsList.Add((New-Object PSObject -Property ([ordered]@{
                TimeCreated   = $evt.TimeCreated.ToString("yyyy-MM-dd HH:mm:ss")
                ProviderName  = $pName
                EventId       = $eId
                Level         = $evt.LevelDisplayName
                BugCheckName  = $bugcheckName
                Message       = $msg
            })))
        }
    }
} catch {
    Write-Warning "[-] Error retrieving event logs: $($_.Exception.Message)"
}

# Export Events to CSV
$eventsCsvPath = Join-Path $OutputDir "storage_events.csv"
$eventsList | Export-Csv -Path $eventsCsvPath -NoTypeInformation -Encoding utf8
Write-Host "   [+] Extracted $($eventsList.Count) critical storage/kernel events." -ForegroundColor Green
foreach ($key in $eventCounts.Keys) {
    if ($eventCounts[$key] -gt 0) {
        Write-Host "       - $key : $($eventCounts[$key])" -ForegroundColor Red
    }
}

# Raw EVTX backup if requested
if ($ExportEvtx) {
    Write-Host "   [*] Exporting raw System.evtx log..." -ForegroundColor Yellow
    $evtxPath = Join-Path $OutputDir "System_Export.evtx"
    try {
        & wevtutil epl System $evtxPath /ow:true
        Write-Host "   [+] Exported raw EVTX to $evtxPath" -ForegroundColor Green
    } catch {
        Write-Warning "   [-] Failed to export raw evtx: $_"
    }
}

# -------------------------------------------------------------
# MODULE 3: Partition Free Space, Over-Provisioning & TRIM
# -------------------------------------------------------------
Write-Host "`n[*] Module 3: Storage Partition Capacity & Over-Provisioning..." -ForegroundColor Yellow

$volumesData = [System.Collections.Generic.List[PSObject]]::new()
$volumes = Get-Volume -ErrorAction SilentlyContinue | Where-Object { $_.DriveType -eq 'Fixed' -and $_.Size -gt 0 }

foreach ($vol in $volumes) {
    $totalGB = [math]::Round($vol.Size / 1GB, 2)
    $freeGB = [math]::Round($vol.SizeRemaining / 1GB, 2)
    $usedGB = [math]::Round(($vol.Size - $vol.SizeRemaining) / 1GB, 2)
    $pctUsed = [math]::Round(($usedGB / $totalGB) * 100, 1)
    $isExhausted = ($pctUsed -ge 85.0)

    $volRecord = [ordered]@{
        DriveLetter         = "$($vol.DriveLetter):"
        FileSystemLabel     = $vol.FileSystemLabel
        FileSystem          = $vol.FileSystem
        TotalSpaceGB        = $totalGB
        UsedSpaceGB         = $usedGB
        FreeSpaceGB         = $freeGB
        PercentUsed         = $pctUsed
        IsOverEightyFivePct = $isExhausted
    }
    $volumesData.Add((New-Object PSObject -Property $volRecord))
    $color = if ($isExhausted) { "Red" } else { "Green" }
    Write-Host "   [+] Drive $($vol.DriveLetter): $usedGB GB / $totalGB GB used ($pctUsed%). Free: $freeGB GB" -ForegroundColor $color
}

# Query TRIM (DisableDeleteNotify: 0 = TRIM Enabled, 1 = TRIM Disabled)
$trimStatus = "Unknown"
try {
    $fsutilOut = & fsutil behavior query DisableDeleteNotify 2>$null
    if ($fsutilOut -match 'DisableDeleteNotify\s*=\s*0') {
        $trimStatus = "Enabled (Optimal)"
    } elseif ($fsutilOut -match 'DisableDeleteNotify\s*=\s*1') {
        $trimStatus = "Disabled (Warning: Garbage collection degradation)"
    } else {
        $trimStatus = ($fsutilOut -join "; ").Trim()
    }
} catch {
    $trimStatus = "Error querying fsutil"
}
Write-Host "   [+] TRIM Behavior: $trimStatus" -ForegroundColor Green

# -------------------------------------------------------------
# MODULE 4: Application Workload & Process I/O Snapshot
# -------------------------------------------------------------
Write-Host "`n[*] Module 4: Workload & Process I/O Profiling..." -ForegroundColor Yellow

$topProcesses = [System.Collections.Generic.List[PSObject]]::new()
try {
    $procs = Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | 
             Where-Object { $null -ne $_.WriteTransferCount -and $_.WriteTransferCount -gt 0 } | 
             Sort-Object -Property WriteTransferCount -Descending | 
             Select-Object -First 25

    foreach ($p in $procs) {
        $writeMB = [math]::Round($p.WriteTransferCount / 1MB, 2)
        $writeGB = [math]::Round($p.WriteTransferCount / 1GB, 3)
        $readMB = [math]::Round($p.ReadTransferCount / 1MB, 2)
        $writeOps = [int64]$p.WriteOperationCount
        $readOps = [int64]$p.ReadOperationCount

        $topProcesses.Add((New-Object PSObject -Property ([ordered]@{
            ProcessName         = $p.Name
            ProcessId           = [int]$p.ProcessId
            WriteTransferMB     = $writeMB
            WriteTransferGB     = $writeGB
            ReadTransferMB      = $readMB
            WriteOperationCount = $writeOps
            ReadOperationCount  = $readOps
            ExecutablePath      = [string]$p.ExecutablePath
        })))
    }
} catch {
    Write-Warning "[-] Error sampling process I/O: $($_.Exception.Message)"
}

$procCsvPath = Join-Path $OutputDir "process_io_top25.csv"
$topProcesses | Export-Csv -Path $procCsvPath -NoTypeInformation -Encoding utf8
Write-Host "   [+] Process I/O profile saved to $procCsvPath" -ForegroundColor Green
if ($topProcesses.Count -gt 0) {
    Write-Host "       Top Writing Process: $($topProcesses[0].ProcessName) (PID: $($topProcesses[0].ProcessId), $($topProcesses[0].WriteTransferMB) MB written, $($topProcesses[0].WriteOperationCount) write ops)" -ForegroundColor Cyan
}

# Check heavy storage paths and directory sizes
$heavyPaths = @(
    "C:\pagefile.sys",
    "C:\swapfile.sys",
    "C:\Accusys",
    "C:\Hardware\monitor",
    "C:\Logs",
    "D:\Logs",
    "C:\Users\Default\AppData\Local\Temp",
    "C:\Windows\Minidump"
)
$pathUsage = [System.Collections.Generic.List[PSObject]]::new()
foreach ($hp in $heavyPaths) {
    if (Test-Path $hp) {
        try {
            $item = Get-Item $hp -Force -ErrorAction SilentlyContinue
            $sizeMB = 0
            $isDir = $item.PSIsContainer
            if ($isDir) {
                $subFiles = Get-ChildItem -Path $hp -Recurse -File -Force -ErrorAction SilentlyContinue
                if ($subFiles) {
                    $totalBytes = ($subFiles | Measure-Object -Property Length -Sum).Sum
                    $sizeMB = [math]::Round($totalBytes / 1MB, 2)
                }
            } else {
                $sizeMB = if ($item.Length) { [math]::Round($item.Length / 1MB, 2) } else { 0 }
            }

            $pathUsage.Add((New-Object PSObject -Property ([ordered]@{
                Path          = $hp
                IsDirectory   = $isDir
                SizeMB        = $sizeMB
                LastWriteTime = $item.LastWriteTime.ToString("yyyy-MM-dd HH:mm:ss")
            })))
            Write-Host "   [+] Path $hp : $sizeMB MB (Modified: $($item.LastWriteTime))" -ForegroundColor Gray
        } catch { }
    }
}

# -------------------------------------------------------------
# MODULE 5: Automated Root Cause Triaging & Scoring
# -------------------------------------------------------------
Write-Host "`n[*] Module 5: Evaluating Root Cause Classification Matrix..." -ForegroundColor Yellow

$triagingResults = [ordered]@{
    SuspectedPrimaryCause = "Inconclusive / Healthy"
    ConfidenceLevel       = "Low"
    Findings              = [System.Collections.Generic.List[string]]::new()
    Remediation           = [System.Collections.Generic.List[string]]::new()
}

$flagNandWear = $false
$flagBusDrop = $false
$flagControllerReset = $false
$flagThermal = $false
$flagPower = $false
$flagCapacity = $false
$flagPowerStateHang = $false

# Check BugCheck events
$bsodEvents = $eventsList | Where-Object { $_.EventId -eq 1001 }
if ($bsodEvents) {
    foreach ($b in $bsodEvents) {
        $triagingResults.Findings.Add("BSOD BUGCHECK: Crash logged: $($b.BugCheckName).")
        if ($b.BugCheckName -match 'DRIVER_POWER_STATE_FAILURE|0x0000009f') {
            $flagPowerStateHang = $true
        }
    }
}

foreach ($d in $disksData) {
    if ($d.PercentageUsed -ge 95 -or $d.AvailableSpare -le $d.AvailableSpareThreshold) {
        $flagNandWear = $true
        $triagingResults.Findings.Add("NAND WEAR EXHAUSTION: Drive $($d.TargetDevice) percentage used is $($d.PercentageUsed)% (Available Spare: $($d.AvailableSpare)%).")
    }
    if ($d.MediaAndDataErrors -gt 0) {
        $triagingResults.Findings.Add("MEDIA CORRUPTION: Drive $($d.TargetDevice) logged $($d.MediaAndDataErrors) media/data integrity errors.")
    }
    if ($d.CriticalTempTimeMinutes -gt 0 -or $d.WarningTempTimeMinutes -gt 60 -or $d.CompositeTempCelsius -ge 75) {
        $flagThermal = $true
        $triagingResults.Findings.Add("THERMAL THROTTLING: Drive $($d.TargetDevice) logged $($d.WarningTempTimeMinutes)m warning / $($d.CriticalTempTimeMinutes)m critical temp excursion time. Current: $($d.CompositeTempCelsius)C.")
    }
    if ($d.UnsafeShutdownRatioPct -ge 20.0 -and $d.UnsafeShutdowns -gt 15) {
        $flagPower = $true
        $triagingResults.Findings.Add("DIRTY POWER LOSS: Drive $($d.TargetDevice) has an unsafe shutdown ratio of $($d.UnsafeShutdownRatioPct)% ($($d.UnsafeShutdowns) unsafe shutdowns out of $($d.PowerCycles) cycles).")
    }
}

if ($eventCounts.Disk_157_SurpriseRemoval -gt 0) {
    $flagBusDrop = $true
    $triagingResults.Findings.Add("PCIE BUS DISCONNECT: Detected $($eventCounts.Disk_157_SurpriseRemoval) Event 157 'Disk has been surprise removed' events.")
}
if ($eventCounts.Stornvme_129_DeviceReset -gt 5 -or $eventCounts.Stornvme_11_ControllerError -gt 0) {
    $flagControllerReset = $true
    $triagingResults.Findings.Add("CONTROLLER TIMEOUT/LOCKUP: Detected $($eventCounts.Stornvme_129_DeviceReset) Event 129 device resets and $($eventCounts.Stornvme_11_ControllerError) Event 11 controller errors.")
}
foreach ($v in $volumesData) {
    if ($v.IsOverEightyFivePct) {
        $flagCapacity = $true
        $triagingResults.Findings.Add("CAPACITY STARVATION: Volume $($v.DriveLetter) is running at $($v.PercentUsed)% full. Severe write amplification and TRIM stall risk.")
    }
}

# Determine diagnosis
if ($flagNandWear) {
    $triagingResults.SuspectedPrimaryCause = "Premature NAND Wear-Out (End of Flash Endurance)"
    $triagingResults.ConfidenceLevel = "High"
    $triagingResults.Remediation.Add("Replace SSD with higher TBW / enterprise rated endurance drive (DWPD >= 1.0).")
    $triagingResults.Remediation.Add("Divert computer vision raw frame logging or camera buffer dumps from SSD to RAM disk (ImDisk) or secondary NVMe.")
} elseif ($flagPowerStateHang -or ($flagPower -and $flagControllerReset)) {
    $triagingResults.SuspectedPrimaryCause = "Controller Power State Transition Hang / Unsafe Shutdown Lockup (0x9F DRIVER_POWER_STATE_FAILURE)"
    $triagingResults.ConfidenceLevel = "High"
    $triagingResults.Remediation.Add("Disable PCIe ASPM (Active State Power Management) in BIOS and set Windows Power Plan PCIe Link State to 'Off'.")
    $triagingResults.Remediation.Add("Disable NVMe Autonomous Power State Transitions (APST) to prevent low-power sleep entry stalls.")
    $triagingResults.Remediation.Add("Update SSD controller firmware across the affected batch (specifically for drives showing high unsafe shutdown ratios).")
    $triagingResults.Remediation.Add("Equip station with inline mini-UPS / 24V-to-ATX buffer power supply to prevent dirty drops during AC power interruptions.")
} elseif ($flagBusDrop) {
    $triagingResults.SuspectedPrimaryCause = "PCIe Bus Disconnect / Electrical Drop (Event 157)"
    $triagingResults.ConfidenceLevel = "High"
    $triagingResults.Remediation.Add("Check M.2 physical slot seating, thermal pad thickness, and vibration in machine chassis.")
    $triagingResults.Remediation.Add("Inspect 3.3V power rail stability during high GPU/Camera workload bursts.")
    $triagingResults.Remediation.Add("Disable PCIe ASPM / PCIe Link State Power Management in BIOS and Windows Power Plan (set to 'Off').")
} elseif ($flagControllerReset) {
    $triagingResults.SuspectedPrimaryCause = "SSD Controller Hang / Firmware Lockup (Event 129 / 11)"
    $triagingResults.ConfidenceLevel = "High"
    $triagingResults.Remediation.Add("Cross-reference SSD firmware version across fleet and update to vendor patch (e.g., Micron/Hikvision FW update).")
    $triagingResults.Remediation.Add("Verify StorNVMe driver version and adjust registry timeout 'NumberOfRequests' or disable APST.")
} elseif ($flagThermal) {
    $triagingResults.SuspectedPrimaryCause = "Thermal Throttling / Controller Overheat Shutdown"
    $triagingResults.ConfidenceLevel = "High"
    $triagingResults.Remediation.Add("Install active M.2 heatsink or reposition drive away from camera capture card / GPU exhaust.")
    $triagingResults.Remediation.Add("Clean chassis intake dust filters and replace failing chassis cooling fans.")
} elseif ($flagPower) {
    $triagingResults.SuspectedPrimaryCause = "External Dirty Power Outages / Brownouts"
    $triagingResults.ConfidenceLevel = "Medium"
    $triagingResults.Remediation.Add("Equip station with inline mini-UPS / 24V-to-ATX buffer power supply to ensure clean Windows ACPI shutdown.")
} elseif ($flagCapacity) {
    $triagingResults.SuspectedPrimaryCause = "Write Amplification & TRIM Starvation (>85% Full)"
    $triagingResults.ConfidenceLevel = "Medium"
    $triagingResults.Remediation.Add("Purge log and video archives; maintain at least 15-20% unallocated space for SSD internal wear leveling.")
} else {
    $triagingResults.SuspectedPrimaryCause = "Healthy Baseline / No Critical Hardware Fault Detected"
    $triagingResults.ConfidenceLevel = "High"
    $triagingResults.Remediation.Add("System can serve as a Healthy Control Station for comparative baseline calibration.")
}

Write-Host "`n>>> DIAGNOSTIC CONCLUSION: $($triagingResults.SuspectedPrimaryCause) (Confidence: $($triagingResults.ConfidenceLevel))" -ForegroundColor Cyan

# -------------------------------------------------------------
# MODULE 6: Save Master Composite JSON & Markdown Report
# -------------------------------------------------------------
$masterReport = [ordered]@{
    Metadata = [ordered]@{
        HostName         = $hostName
        Timestamp        = $timestamp
        CollectionDate   = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
        LogAnalysisDays  = $LogDays
    }
    Diagnosis        = $triagingResults
    StorageDrives    = $disksData
    EventCounters    = $eventCounts
    VolumeStatus     = $volumesData
    TrimBehavior     = $trimStatus
    TopIOProcesses   = $topProcesses
    TrackedPathUsage = $pathUsage
}

$masterJsonPath = Join-Path $OutputDir "telemetry_summary_${hostName}.json"
$masterReport | ConvertTo-Json -Depth 6 | Out-File -FilePath $masterJsonPath -Encoding utf8
Write-Host "`n[+] Master telemetry JSON saved to: $masterJsonPath" -ForegroundColor Green

# Generate Markdown Diagnostic Report
$mdReportPath = Join-Path $OutputDir "diagnostic_report_${hostName}.md"
$mdContent = @"
# SSD Forensic Diagnostic Report: $hostName
**Timestamp:** $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  
**Triage Conclusion:** $($triagingResults.SuspectedPrimaryCause)  
**Confidence:** $($triagingResults.ConfidenceLevel)  

## 1. Key Forensic Findings
$($triagingResults.Findings | ForEach-Object { "- $_" } | Out-String)

## 2. Drive SMART & Telemetry Overview
| Target | Model | Serial | Firmware | Capacity | Used % | Spare % | TBW | Media Errs | Temp | Unsafe Shutdowns |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
$($disksData | ForEach-Object { "| $($_.TargetDevice) | $($_.ModelNumber) | $($_.SerialNumber) | $($_.FirmwareVersion) | $($_.CapacityGB) GB | $($_.PercentageUsed)% | $($_.AvailableSpare)% | $($_.DataUnitsWrittenTB) TB | $($_.MediaAndDataErrors) | $($_.CompositeTempCelsius)C | $($_.UnsafeShutdowns)/$($_.PowerCycles) ($($_.UnsafeShutdownRatioPct)%) |" } | Out-String)

## 3. Storage & Kernel Event Log Summary (Past $LogDays Days)
- **stornvme 11 (Controller Error):** $($eventCounts.Stornvme_11_ControllerError)
- **stornvme 129 (Device Reset):** $($eventCounts.Stornvme_129_DeviceReset)
- **disk 153 (I/O Retry):** $($eventCounts.Disk_153_IORetry)
- **disk 51 (Paging Error):** $($eventCounts.Disk_51_PagingError)
- **disk 157 (Surprise Removed):** $($eventCounts.Disk_157_SurpriseRemoval)
- **disk 140 (Flush Failed):** $($eventCounts.Disk_140_FlushFailed)
- **Ntfs 55 (Filesystem Corrupt):** $($eventCounts.Ntfs_55_Corruption)
- **Kernel-Power 41 (Dirty Reboot):** $($eventCounts.KernelPower_41_UnexpectedReboot)
- **EventLog 6008 (Unexpected Shutdown):** $($eventCounts.EventLog_6008_UnexpectedShutdown)
- **BugCheck 1001 (BSOD):** $($eventCounts.BugCheck_1001_BSOD)

## 4. Volume Capacity & Free Space
| Drive | Label | Total (GB) | Used (GB) | Free (GB) | Used % | Status |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
$($volumesData | ForEach-Object { "| $($_.DriveLetter) | $($_.FileSystemLabel) | $($_.TotalSpaceGB) | $($_.UsedSpaceGB) | $($_.FreeSpaceGB) | $($_.PercentUsed)% | $(if($_.IsOverEightyFivePct){'CRITICAL (>85%)'}else{'OK'}) |" } | Out-String)

## 5. Top 5 Writing Processes
| Process | PID | Write Transfer (MB) | Write Operations | Path |
| :--- | :--- | :--- | :--- | :--- |
$($topProcesses | Select-Object -First 5 | ForEach-Object { "| $($_.ProcessName) | $($_.ProcessId) | $($_.WriteTransferMB) MB | $($_.WriteOperationCount) | $($_.ExecutablePath) |" } | Out-String)

## 6. Recommended Action Plan
$($triagingResults.Remediation | ForEach-Object { "1. $_" } | Out-String)
"@

$mdContent | Out-File -FilePath $mdReportPath -Encoding utf8
Write-Host "[+] Forensic Markdown report saved to: $mdReportPath" -ForegroundColor Green

# Ensure fixed copies in C:\Hardware\SSD_Forensics
$standardDir = "C:\Hardware\SSD_Forensics"
if (-not (Test-Path $standardDir)) {
    New-Item -ItemType Directory -Path $standardDir -Force | Out-Null
}

$cReportHost = Join-Path $standardDir "diagnostic_report_${hostName}.md"
$cReportLatest = Join-Path $standardDir "diagnostic_report_latest.md"
$cJsonHost = Join-Path $standardDir "telemetry_summary_${hostName}.json"
$cJsonLatest = Join-Path $standardDir "telemetry_summary_latest.json"

$mdContent | Out-File -FilePath $cReportHost -Encoding utf8
$mdContent | Out-File -FilePath $cReportLatest -Encoding utf8
$masterReport | ConvertTo-Json -Depth 6 | Out-File -FilePath $cJsonHost -Encoding utf8
$masterReport | ConvertTo-Json -Depth 6 | Out-File -FilePath $cJsonLatest -Encoding utf8

Write-Host "[+] Automatically placed report in: $cReportHost" -ForegroundColor Cyan
Write-Host "[+] Latest report accessible at:    $cReportLatest" -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

return $masterReport
