# SSD Fleet Forensics & Root-Cause Analysis Guide

Comprehensive guide for diagnosing premature SSD failures, PCIe bus drops, controller lockups, and workload exhaustion across edge inspection PCs.

---

## 1. Overview & Forensic Framework

The **SSD Forensics Toolset** collects, correlates, and analyzes low-level hardware telemetry, Windows kernel/storage logs, and application workload profiles to pinpoint the exact root cause of drive failures across production fleets:

```
                          ┌──────────────────────────┐
                          │   SSD Failure Triaging   │
                          └────────────┬─────────────┘
                                       │
         ┌──────────────────┬──────────┴───────────┬──────────────────┐
         ▼                  ▼                      ▼                  ▼
┌──────────────────┐ ┌──────────────┐     ┌──────────────────┐ ┌──────────────┐
│  Hypothesis A:   │ │Hypothesis B: │     │  Hypothesis C:   │ │Hypothesis D: │
│    NAND Wear     │ │PCIe/Bus Drop │     │ Thermal Stress   │ │Firmware Bug  │
├──────────────────┤ ├──────────────┤     ├──────────────────┤ ├──────────────┤
│• % Used >= 100%  │ │• Event 157   │     │• Warning/Crit    │ │• Clustered   │
│• Spare < Thresh  │ │• Event 129   │     │  Temp Time > 0   │  FW versions   │
│• Media Errs > 0  │ │• Unsafe Shut-│     │• Throttle events │ │• Low wear %  │
│• High TBW/Day    │ │  down surge  │     │• Hot chassis amb.│ │• Event 11/129│
└──────────────────┘ └──────────────┘     └──────────────────┘ └──────────────┘
```

### Core Diagnostic Modules
1. **Drive Hardware & SMART Telemetry (`smartctl -x -j`):** Model, Serial, Firmware Version, Data Units Written (TB), Percentage Used, Available Spare & Threshold, Critical Warning bitmask, Media/Integrity Errors, full NVMe error log (`smartctl -l error`), Unsafe Shutdowns, Power Cycles, Power On Hours, Composite Temp, and Warning/Critical Composite Temp Time.
2. **Windows Storage & Kernel Event Forensics:** Filters `System.evtx` for `stornvme`/`nvme` Event 11 & 129 (controller resets), `disk` Event 153 (retries), 51 (paging error), 157 (surprise removal / PCIe bus drop), 140 (flush failed), `Ntfs` Event 55 & 50 (corruption), `Kernel-Power` 41, `EventLog` 6008, and `BugCheck` 1001 (auto-decoded stop codes like `0x9F DRIVER_POWER_STATE_FAILURE`, `0x7A`, `0x7B`, `0xEF`, `0x124`).
3. **Partition Capacity & Over-Provisioning:** Audits volume space (flags partitions $\ge 85\%$ used) and verifies TRIM status (`fsutil behavior query DisableDeleteNotify`).
4. **Workload & Process Write Profiling:** Profiles per-process `WriteTransferCount` and `WriteOperationCount` via `Win32_Process`, alongside folder sizing of vision raw frame buffers and log directories.
5. **Cross-Fleet Clustering:** Detects failure clusters by SSD firmware lot, manufacturing lot, high unsafe shutdown ratios, or excessive TBW wear rates.

---

## 2. Toolset Architecture

| Script | Location | Role & Description |
| :--- | :--- | :--- |
| **`Invoke-SSDForensics.ps1`** | `scripts/Invoke-SSDForensics.ps1` | **Collector**: Runs locally or remotely on individual PCs. Automatically extracts telemetry, evaluates the root cause matrix, and saves results into `C:\Hardware\SSD_Forensics`. |
| **`Aggregate-SSDForensics.ps1`** | `scripts/Aggregate-SSDForensics.ps1` | **Aggregator**: Ingests JSON summaries from failed and healthy control stations, calculates cluster distributions, and generates the fleet-wide comparison matrix. |
| **`Deploy-SSDForensicsFleet.ps1`** | `scripts/Deploy-SSDForensicsFleet.ps1` | **Remote Orchestrator**: Pushes `Invoke-SSDForensics.ps1` over WinRM/SMB across plant edge PCs (`172.16.x.x`), pulls back diagnostic packages, and runs aggregation. |

---

## 3. How to Use the Forensic Scripts

### Scenario A: Diagnose a Single PC (Failed or Healthy Control)

Run directly on the affected station, a bench test PC with a suspect secondary drive (`pd1`), or a healthy control PC:

1. Open **PowerShell as Administrator**.
2. Run the collector:
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\scripts\Invoke-SSDForensics.ps1
   ```
3. All files are automatically saved to **`C:\Hardware\SSD_Forensics`**.
4. Open the generated report:
   ```powershell
   notepad C:\Hardware\SSD_Forensics\diagnostic_report_latest.md
   ```

#### Optional Parameters
```powershell
# Analyze the past 60 days of event logs (default: 30 days)
powershell -ExecutionPolicy Bypass -File .\scripts\Invoke-SSDForensics.ps1 -LogDays 60

# Export raw System.evtx file for deep offline forensic review
powershell -ExecutionPolicy Bypass -File .\scripts\Invoke-SSDForensics.ps1 -ExportEvtx

# Custom output destination
powershell -ExecutionPolicy Bypass -File .\scripts\Invoke-SSDForensics.ps1 -OutputDir "D:\ForensicsDump"
```

---

### Scenario B: Remote Fleet-Wide Execution

To run diagnostics across multiple stations over the plant network without logging into each machine manually:

1. Open **PowerShell as Administrator** on the plant server or engineer PC.
2. Run `Deploy-SSDForensicsFleet.ps1` with the target IP addresses:
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\scripts\Deploy-SSDForensicsFleet.ps1 -TargetIps @("172.16.199.2", "172.16.199.8", "172.16.199.18", "172.16.199.25")
   ```
3. The script will:
   - Deploy `Invoke-SSDForensics.ps1` to `\\<IP>\C$\Temp\SSD_Diagnostics`.
   - Execute the diagnostic suite remotely via WinRM.
   - Fetch all outputs into `C:\Hardware\SSD_Forensics`.
   - Run `Aggregate-SSDForensics.ps1` to produce the consolidated report.

---

### Scenario C: Cross-Fleet Comparison & Cluster Analysis

Once you have collected `telemetry_summary_*.json` files from failed and healthy control stations:

1. Place the `telemetry_summary_*.json` files into `C:\Hardware\SSD_Forensics`.
2. Run the aggregator:
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\scripts\Aggregate-SSDForensics.ps1
   ```
3. Open the cross-fleet analysis:
   - **Markdown Summary:** `C:\Hardware\SSD_Forensics\fleet_root_cause_analysis.md`
   - **CSV Matrix:** `C:\Hardware\SSD_Forensics\fleet_root_cause_matrix.csv`

---

## 4. Output Files in `C:\Hardware\SSD_Forensics`

| File Name | Format | Content & Purpose |
| :--- | :--- | :--- |
| **`diagnostic_report_latest.md`** | Markdown | Direct human-readable summary of the latest diagnostic run. |
| **`diagnostic_report_<HOSTNAME>.md`** | Markdown | Host-specific triage report with findings, metrics, and actionable recommendations. |
| **`telemetry_summary_latest.json`** | JSON | Standardized JSON composite for programmatic ingestion or dashboard import. |
| **`telemetry_summary_<HOSTNAME>.json`** | JSON | Host-specific full telemetry summary. |
| **`storage_events.csv`** | CSV | Filtered event log entries with timestamps, Event IDs, providers, and message text. |
| **`process_io_top25.csv`** | CSV | Top 25 processes sorted by `WriteTransferCount` with write operation counts and paths. |
| **`raw_smartctl_<DEVICE>.json`** | JSON | Raw output of `smartctl -x -j` including full NVMe health log fields. |
| **`nvme_error_log_<DEVICE>.txt`** | Text | Hardware internal NVMe error information log (`smartctl -l error`). |
| **`fleet_root_cause_analysis.md`** | Markdown | Cross-fleet cluster analysis (generated by aggregator). |
| **`fleet_root_cause_matrix.csv`** | CSV | Side-by-side drive comparison spreadsheet across all tested PCs. |

---

## 5. Diagnostic Triage Matrix (How to Interpret Results)

| Observed Metric / Symptom | Threshold / Pattern | Root Cause Diagnosis | Actionable Solution |
| :--- | :--- | :--- | :--- |
| **Unsafe Shutdown Ratio** | $\frac{\text{Unsafe}}{\text{PowerCycles}} \ge 20\%$ | **Dirty Power Cut / Brownout** | Check plant line AC power at shift changes or emergency stops; equip station cabinet with 24V-to-ATX buffer power supply or mini-UPS. |
| **BugCheck 1001** | `0x0000009F` (`DRIVER_POWER_STATE_FAILURE`) | **SSD Controller Power State Hang** | Disable PCIe Active State Power Management (ASPM) in BIOS; set Windows Power Plan PCIe Link State to "Off"; disable NVMe APST. |
| **Disk Surprise Removal** | `disk` **Event 157** $> 0$ | **PCIe Bus Disconnect / Electrical Drop** | Check M.2 screw torque, slot seating, and chassis vibration; inspect 3.3V rail voltage dips during high GPU capture bursts. |
| **Stornvme Reset** | `stornvme` **Event 129** $> 5$ or **Event 11** $> 0$ | **Controller Freeze / Firmware Bug** | Check firmware version clusters; upgrade SSD firmware via vendor utility (e.g. Micron Storage Executive / Hikvision tool). |
| **Endurance & Wear** | `percentage_used` $\ge 100\%$ or `available_spare` $\le$ threshold | **NAND Flash Wear-Out** | Replace SSD with enterprise high-DWPD drive; configure vision software to stream image circular buffers in RAM (ImDisk) instead of writing to disk. |
| **Thermal Excursion** | `critical_comp_error_temp_time` $> 0$ or temp $> 75^\circ\text{C}$ | **Thermal Throttling / Controller Overheat** | Clean chassis dust filters; replace chassis fans; install active aluminum M.2 heatsink away from camera card/GPU exhaust. |
| **Volume Utilization** | Disk Used $\ge 85\%$ | **Capacity Starvation / Write Amplification** | Purge old image/log archives; verify TRIM is enabled (`DisableDeleteNotify = 0`); maintain at least 15–20% free space. |

---

## 6. Best Practices for Production Monitoring

* **Do NOT run deep forensic collection on a 5-minute loop:**  
  Deep forensics scans 30 days of event logs, crawls directories, and writes multiple files to disk. Running it every 5 minutes causes recursive I/O spikes and accelerates SSD wear.
* **Use Tiered Telemetry:**
  - **Tier 1 (Continuous Every 5–15 mins):** Use `Push-Telemetry.ps1` (or `NGCTelemetryAgent.exe`), which queries `smartctl -a -j` in memory without writing local files.
  - **Tier 2 (Forensics On-Demand or on Anomaly):** Execute `Invoke-SSDForensics.ps1` when an anomaly flag is raised (e.g., Event 129/157 detected, temperature $>65^\circ\text{C}$, or media errors $>0$).
