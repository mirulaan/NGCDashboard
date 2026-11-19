<#
.SYNOPSIS
    SSD Fleet Telemetry Aggregator & Cross-Station Root Cause Analyzer
.DESCRIPTION
    Ingests telemetry JSON summaries from both failed and healthy control PCs,
    constructs a unified comparison matrix, calculates failure clustering across
    firmware versions / manufacturing lots, and produces forensic cross-fleet reports.
.PARAMETER InputDir
    Folder containing individual station 'telemetry_summary_*.json' files.
.PARAMETER OutputReport
    Output path for the consolidated markdown report (default: fleet_root_cause_analysis.md).
#>

[CmdletBinding()]
param(
    [string]$InputDir = "",
    [string]$OutputReport = ""
)

if ([string]::IsNullOrWhiteSpace($InputDir)) {
    if (Test-Path "C:\Hardware\SSD_Forensics") {
        $InputDir = "C:\Hardware\SSD_Forensics"
    } else {
        $InputDir = Join-Path $PSScriptRoot "..\storage\logs\ssd_diagnostics"
    }
}

if (-not (Test-Path $InputDir)) {
    Write-Error "Input directory not found: $InputDir"
    return
}

# Recursively locate all telemetry_summary_*.json files
$jsonFiles = Get-ChildItem -Path $InputDir -Filter "telemetry_summary_*.json" -Recurse -File

if (-not $jsonFiles -or $jsonFiles.Count -eq 0) {
    Write-Warning "No 'telemetry_summary_*.json' files found in $InputDir"
    return
}

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "   SSD Fleet Forensic Telemetry Aggregator" -ForegroundColor Cyan
Write-Host "   Processing $($jsonFiles.Count) station summaries..." -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

$fleetDrives = [System.Collections.Generic.List[PSObject]]::new()
$allHosts = [System.Collections.Generic.HashSet[string]]::new()

foreach ($f in $jsonFiles) {
    try {
        $content = Get-Content -Path $f.FullName -Raw -Encoding utf8 | ConvertFrom-Json
        $hostName = $content.Metadata.HostName
        $allHosts.Add($hostName) | Out-Null
        $diag = $content.Diagnosis.SuspectedPrimaryCause
        $conf = $content.Diagnosis.ConfidenceLevel
        $events = $content.EventCounters

        foreach ($d in $content.StorageDrives) {
            $record = [ordered]@{
                HostName              = $hostName
                CollectionTime        = $content.Metadata.CollectionDate
                Target                = $d.TargetDevice
                Model                 = $d.ModelNumber
                Serial                = $d.SerialNumber
                Firmware              = $d.FirmwareVersion
                CapacityGB            = $d.CapacityGB
                PercentageUsed        = $d.PercentageUsed
                AvailableSpare        = $d.AvailableSpare
                TBWritten             = $d.DataUnitsWrittenTB
                TBRead                = $d.DataUnitsReadTB
                CalculatedTBPerDay    = $d.CalculatedLifetimeTBPerDay
                MediaErrors           = $d.MediaAndDataErrors
                ErrorLogEntries       = $d.ErrorLogEntriesCount
                CompositeTempC        = $d.CompositeTempCelsius
                WarningTempMins       = $d.WarningTempTimeMinutes
                CriticalTempMins      = $d.CriticalTempTimeMinutes
                UnsafeShutdowns       = $d.UnsafeShutdowns
                PowerCycles           = $d.PowerCycles
                UnsafeRatioPct        = $d.UnsafeShutdownRatioPct
                PowerOnHours          = $d.PowerOnHours
                Event129_DeviceReset  = $events.Stornvme_129_DeviceReset
                Event11_ControllerErr = $events.Stornvme_11_ControllerError
                Event157_SurpriseDrop = $events.Disk_157_SurpriseRemoval
                Event153_IORetry      = $events.Disk_153_IORetry
                Event51_PagingErr     = $events.Disk_51_PagingError
                KernelPower41         = $events.KernelPower_41_UnexpectedReboot
                BSODCount             = $events.BugCheck_1001_BSOD
                Diagnosis             = $diag
                Confidence            = $conf
            }
            $fleetDrives.Add((New-Object PSObject -Property $record))
        }
    } catch {
        Write-Warning "Failed to parse $($f.FullName): $($_.Exception.Message)"
    }
}

# Export Fleet Comparison Matrix CSV
$csvPath = Join-Path $InputDir "fleet_root_cause_matrix.csv"
$fleetDrives | Export-Csv -Path $csvPath -NoTypeInformation -Encoding utf8
Write-Host "[+] Exported fleet matrix CSV: $csvPath" -ForegroundColor Green

# Perform Statistical & Cluster Analysis
$fwClusters = $fleetDrives | Group-Object Firmware | Select-Object Name, Count, @{N='Drives'; E={$_.Group.Model -join ', '}}
$modelClusters = $fleetDrives | Group-Object Model | Select-Object Name, Count, @{N='AvgUsedPct'; E={[math]::Round(($_.Group | Measure-Object PercentageUsed -Average).Average, 1)}}, @{N='TotalMediaErrors'; E={($_.Group | Measure-Object MediaErrors -Sum).Sum}}
$highUnsafeDrives = $fleetDrives | Where-Object { $_.UnsafeRatioPct -ge 20.0 }
$highWearDrives = $fleetDrives | Where-Object { $_.PercentageUsed -ge 80 }
$droppedBusDrives = $fleetDrives | Where-Object { $_.Event157_SurpriseDrop -gt 0 }

# Generate Markdown Analysis Report
if ([string]::IsNullOrWhiteSpace($OutputReport)) {
    $OutputReport = Join-Path $InputDir "fleet_root_cause_analysis.md"
}

$md = @"
# Cross-Fleet SSD Root Cause Analysis Report
**Generated:** $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  
**Total Analyzed Stations:** $($allHosts.Count)  
**Total Analyzed SSDs:** $($fleetDrives.Count)  

---

## 1. Fleet Hardware & Firmware Cluster Breakdown

### Firmware Version Distribution
| Firmware Version | Drive Count | Models Associated |
| :--- | :--- | :--- |
$($fwClusters | ForEach-Object { "| $($_.Name) | $($_.Count) | $($_.Drives) |" } | Out-String)

### Model Endurance & Health Aggregation
| Model Number | Count | Avg % Used | Total Media Errors |
| :--- | :--- | :--- | :--- |
$($modelClusters | ForEach-Object { "| $($_.Name) | $($_.Count) | $($_.AvgUsedPct)% | $($_.TotalMediaErrors) |" } | Out-String)

---

## 2. Anomaly Triage Findings

- **High Unsafe Shutdown Cluster (Unsafe Ratio >= 20%):** $($highUnsafeDrives.Count) drives
$($highUnsafeDrives | ForEach-Object { "  - Host: $($_.HostName), Drive: $($_.Target), Model: $($_.Model), Unsafe: $($_.UnsafeShutdowns)/$($_.PowerCycles) ($($_.UnsafeRatioPct)%)" } | Out-String)

- **Endurance & Wear-Out Cluster (% Used >= 80%):** $($highWearDrives.Count) drives
$($highWearDrives | ForEach-Object { "  - Host: $($_.HostName), Drive: $($_.Target), Model: $($_.Model), Used: $($_.PercentageUsed)%, TBW: $($_.TBWritten) TB" } | Out-String)

- **PCIe Bus Disconnect Cluster (Event 157 > 0):** $($droppedBusDrives.Count) drives
$($droppedBusDrives | ForEach-Object { "  - Host: $($_.HostName), Drive: $($_.Target), Drops: $($_.Event157_SurpriseDrop)" } | Out-String)

---

## 3. Comprehensive Drive Comparison Matrix
| Host | Target | Model | Firmware | Used % | Spare % | TBW | Media Errs | Temp | Unsafe % | Drops (157) | Resets (129) | BSODs | Diagnosis |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
$($fleetDrives | ForEach-Object { "| $($_.HostName) | $($_.Target) | $($_.Model) | $($_.Firmware) | $($_.PercentageUsed)% | $($_.AvailableSpare)% | $($_.TBWritten) TB | $($_.MediaErrors) | $($_.CompositeTempC)C | $($_.UnsafeRatioPct)% | $($_.Event157_SurpriseDrop) | $($_.Event129_DeviceReset) | $($_.BSODCount) | $($_.Diagnosis) |" } | Out-String)

---

## 4. Root Cause Recommendations & Next Steps
1. **For Drives with High Unsafe Shutdown Ratios (>20%):**
   - Check plant line AC power drops at shift changes or emergency stop (E-stop) triggers.
   - Install inline battery-buffered DIN-rail power supplies on industrial PC cabinets.
2. **For Controller / APST Hangs (0x9F BSOD / Event 129):**
   - Apply vendor firmware updates across identical firmware revisions.
   - Disable PCIe Active State Power Management (ASPM) in PC BIOS and Windows OS power profile.
3. **For High TBW / Wear Rates (>1 TB/day):**
   - Configure vision software to stream raw camera frame circular buffers in RAM instead of continuous uncompressed disk writes.
"@

$md | Out-File -FilePath $OutputReport -Encoding utf8
Write-Host "[+] Consolidated analysis markdown report saved to: $OutputReport" -ForegroundColor Green
Write-Host "==========================================================" -ForegroundColor Cyan
