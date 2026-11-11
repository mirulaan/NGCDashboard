# NGC Telemetry Agent - Deployment & Rollout Guide
## Zero-Touch Migration from `Push-Telemetry.ps1` & `DailyBackup.ps1` to Unified Agent

---

## 1. Overview & Architecture Transition

This guide details the deployment of **NGC Telemetry Agent** across optical inspection edge PCs in Plants 7, 8, and 9.

### What is Changing?

| Component | Legacy Setup (Before) | Unified Agent (After) |
| :--- | :--- | :--- |
| **Telemetry Collector** | `Push-Telemetry.ps1` via Task Scheduler (`NGC_Telemetry_Push`) | Built-in background service in `NGCTelemetryAgent.exe` |
| **Hardware Sensors** | Spawns `HWiNFO64.exe` + registry polling (`HKCU:\Software\HWiNFO64\VSB`) | Native in-process monitoring via `LibreHardwareMonitorLib` & IOCTLs |
| **Daily Plant Backup** | Separate `DailyBackup.ps1` via Task Scheduler (`Daily_Plant_Backup`) | Built-in throttled background service in `NGCTelemetryAgent.exe` |
| **Backup Schedule** | Inconsistent or unmonitored | **Daily at 6:00 PM (`18:00:00`)** with shift-change alignment |
| **Backup Throttling** | Basic PowerShell priority | CPU `Idle` priority + locked to Single Core / E-Core (`Affinity = 1`) |
| **Dashboard Visibility** | Local text file only (`C:\Backup\BackupLog.txt`) | Live telemetry payload sent to `/api/system-status` with web UI badges |
| **Deployment Effort** | Manual visits PC-by-PC ❌ | **Zero-Touch Fleet Rollout from Server** (No manual visits) ✅ |

---

## 2. Phase 1: Plant Dashboard Server (IIS) Preparation

Perform these steps on the **Plant Dashboard Server** (e.g., `172.16.50.2` or `192.168.100.243`):

### Step 1.1: Pull Latest Repository & Update IIS
Open PowerShell as Administrator:
```powershell
cd C:\inetpub\wwwroot\NGCDashboard

# Pull latest code from main
git pull origin main

# Execute automated stateless deployment pipeline
powershell.exe -ExecutionPolicy Bypass -File .\scripts\Update-NGCDashboard.ps1
```
*(This preserves the database in `C:\inetpub\wwwroot\NGCDashboard_Data\`, syncs code, runs migrations, rebuilds framework cache, and recycles the IIS AppPool.)*

### Step 1.2: Offline Agent Distribution (No Server Build Needed)
The Plant Dashboard Server is an **air-gapped / offline machine** and does **NOT** compile binaries or require .NET SDK installation. 

The standalone binary package (`NGCTelemetryAgent.zip` and `NGCTelemetryAgent.exe`) is **pre-built** on your internet-connected development PC or automatically built via GitHub Actions and delivered in `public/scripts/`.

#### How to build on your dev PC (run ONCE on development machine):
```powershell
cd agent
.\Build-AgentPackage.ps1
```
*(This automatically runs `dotnet publish` with `--self-contained true`, generates `public\scripts\NGCTelemetryAgent.zip` and `NGCTelemetryAgent.exe`, requiring zero .NET runtime on the edge PCs.)*

Once the project files are pulled or copied to the offline Plant Server, the files will already be located at:
- `C:\inetpub\wwwroot\NGCDashboard\public\scripts\NGCTelemetryAgent.zip`
- `C:\inetpub\wwwroot\NGCDashboard\public\scripts\NGCTelemetryAgent.exe`

### Step 1.3: Verify Web Distribution Accessibility
Test that the deployment artifacts are reachable over HTTP:
```powershell
# Test Bootstrap script download
Invoke-WebRequest -Uri "http://localhost/scripts/Bootstrap-NGCAgent.ps1" -Method Head

# Test Agent zip download
Invoke-WebRequest -Uri "http://localhost/scripts/NGCTelemetryAgent.zip" -Method Head
```
Both commands must return **HTTP 200 OK**.

---

## 3. Phase 2: Fleet Rollout to Edge Inspection PCs

You do **NOT** need to visit each edge PC individually. Choose one of the rollout methods below:

### Method A: Centralized Fleet Rollout (Recommended - Zero Manual PC Visits)
Execute the fleet deployer from the **Plant Dashboard Server**. It reads all edge PC IP addresses from the live SQLite database and deploys in parallel:

```powershell
# Run from Plant Server as Administrator:
cd C:\inetpub\wwwroot\NGCDashboard\scripts

# Roll out to all active machines in Plant 9:
.\Deploy-AgentFleet.ps1 -Plant "9"

# Or specify target IPs explicitly:
.\Deploy-AgentFleet.ps1 -TargetIps "172.16.50.101","172.16.50.102","172.16.50.103"
```

**What the deployer does automatically on each machine:**
1. Tests network ping reachability.
2. Connects via SMB admin share (`\\<IP>\c$\Hardware\NGCTelemetryAgent\`).
3. Copies `Bootstrap-NGCAgent.ps1` and `NGCTelemetryAgent.exe`.
4. Executes the bootstrap installer silently via WMI.
5. Decommissions both legacy scheduled tasks (`NGC_Telemetry_Push` and `Daily_Plant_Backup`).
6. Terminates obsolete `HWiNFO64.exe` processes.
7. Prints a real-time summary table on your screen.

---

### Method B: Single Pilot PC Test (Line 93-L Validation)
To validate on a single pilot machine before fleet-wide rollout:

1. Open PowerShell as Administrator on the target edge PC (or via remote PowerShell).
2. Execute the one-liner bootstrap command:
```powershell
Invoke-WebRequest -Uri "http://172.16.50.2/scripts/Bootstrap-NGCAgent.ps1" -OutFile "$env:TEMP\bootstrap.ps1" -UseBasicParsing; & "$env:TEMP\bootstrap.ps1"
```

---

## 4. Configuration Reference (`config.json`)

The agent automatically creates and reads `C:\Hardware\NGCTelemetryAgent\config.json`:

```json
{
  "ServerUrl": "http://172.16.50.2/api/system-status",
  "PushIntervalSeconds": 300,
  "BackupScheduleTime": "18:00:00",
  "SftpHost": "172.16.207.14",
  "SftpPort": 22,
  "SftpUser": "svc_otsftp",
  "SftpPass": "z#&A2#@VEAq&krt",
  "AutoStart": true,
  "StartMinimized": true
}
```

### Parameter Details:
- **`ServerUrl`**: Ingestion API endpoint for telemetry logs and backup status reports.
- **`PushIntervalSeconds`**: Telemetry reporting frequency (Default: `300` seconds / 5 minutes).
- **`BackupScheduleTime`**: Daily backup trigger time (Default: `"18:00:00"` / **6:00 PM**).
- **`SftpHost` / `SftpPort`**: Central SFTP backup server (`172.16.207.14:22`).
- **`SftpUser` / `SftpPass`**: SFTP credentials (`svc_otsftp`).

---

## 5. Daily Plant Backup Subsystem

### 5.1 Staged Files & Exclusion Rules
Every day at **6:00 PM**, the agent stages and compresses:
1. **`C:\Accusys\`**: Software configuration and vision models.
   - **Exclusion**: The massive `HAIVIs\log` directory is strictly excluded via Robocopy to save 10–30 GB per machine.
2. **`C:\Hardware\`**: Hardware profiles and `PC_Info.xml`.
3. **`C:\Program1\`**: Inspection binaries, IOC scripts, and model recipes.
4. **`C:\inetpub\wwwroot\NGCDashboard\`**: Included if the node is a server host.

### 5.2 CPU Throttling (Zero AI Vision Frame Drops)
During zip compression, the agent automatically:
- Lowers process priority to `ProcessPriorityClass.Idle`.
- Sets CPU affinity to single core (`ProcessorAffinity = (IntPtr)1` / Core 0 / E-Core).
- Restores normal priority and full core affinity immediately upon completion.

### 5.3 SFTP Upload Destination
- **Remote Path**: `/BACKUP/Plant_{Plant}/{Line}{TierPrefix}.zip`
- **Example**: Line `93`, Tier `L` in Plant `9` uploads to `/BACKUP/Plant_9/93L.zip`.
- **Cleanup**: The local `.zip` file is automatically deleted upon successful SFTP transfer to preserve disk space.

---

## 6. Verification & Health Checks

### Check 1: System Tray Indicator (Edge PC)
1. In the Windows taskbar system tray (bottom-right), locate the **Shield** icon.
2. Right-click the icon to open the context menu:
   - `NGC Telemetry Agent v1.0.0` (Header)
   - `Telemetry: Connected (5:45 PM)`
   - `Backup: Scheduled (6:00 PM)`
   - `[ Force Telemetry Push ]` (Triggers instant sync)
   - `[ Run Daily Backup Now ]` (Triggers on-demand backup)

### Check 2: Task Scheduler Retirement (Edge PC)
Open PowerShell on the edge PC and run:
```powershell
Get-ScheduledTask -TaskName "NGC_Telemetry*", "Daily_Plant*"
```
- **`NGC_Telemetry_Push`**: Removed / Unregistered.
- **`Daily_Plant_Backup`**: Removed / Unregistered.
- **`NGC_Telemetry_Agent`**: Active (Runs `NGCTelemetryAgent.exe --minimized` at logon).

### Check 3: Web Dashboard (NGC Server)
Navigate to `http://<server-ip>/`:
1. **Fleet View**: Machine status shows **Green (Online)**.
2. **Node Profile View**: Displays `Agent Version: 1.0.0` and latest `Backup Status: Success (6:12 PM)`.

---

## 7. Troubleshooting

| Issue | Root Cause | Resolution |
| :--- | :--- | :--- |
| **Agent fails to download during bootstrap** | Port 80 blocked or invalid server IP | Ensure edge PC can reach `http://<server-ip>/scripts/NGCTelemetryAgent.zip` in browser or `curl`. |
| **`SFTP transfer failed` in backup log** | Port 22 unreachable or credentials invalid | Verify connection to `172.16.207.14:22` from the edge PC using PowerShell `Test-NetConnection 172.16.207.14 -Port 22`. |
| **WinSCP.com not found** | WinSCP not installed at standard path | Install WinSCP to `C:\Program Files (x86)\WinSCP\WinSCP.com`. |
| **Legacy task still running** | Decommission command lacked elevation | Run `schtasks.exe /Delete /TN "NGC_Telemetry_Push" /F` from elevated Administrator prompt. |
| **GPU metrics show N/A** | NVIDIA driver path not standard | Ensure `nvidia-smi.exe` exists in `C:\Windows\System32` or `C:\Program Files\NVIDIA Corporation\NVSMI`. |
