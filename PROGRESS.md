# NGC Dashboard - Project Status & Progress Log

## Project Overview
**NGC Dashboard** is an on-premise industrial IoT and manufacturing line monitoring system designed for visual/optical inspection plants (such as glove manufacturing lines). It collects real-time hardware vitals (NVMe SSD wear, CPU/GPU thermals), AI defect detection model states (Accusys HAIVIs), and software versioning from edge inspection PCs, displays them on an operations dashboard, and enables remote model updates via SFTP and an external Config API.

* **Tech Stack**: Laravel 13.8, PHP 8.3+, Tailwind CSS v4 (local offline standalone distribution), Chart.js, SQLite/MySQL, Flysystem SFTP.
* **Edge Agent**: Windows PowerShell 5.1+, `HWiNFO64`, `smartmontools` (`smartctl.exe`), `nvidia-smi`.
* **Topology**: Plant-level monitoring (Plants 7, 8, 9) with dual-side (Left/Right) inspection lines per plant.

---

## Progress & Change Log

### [2026-11-11] - Windows Telemetry Agent Core, Zero-Touch Fleet Rollout & Daily Backup Consolidation

#### 1. Zero-Touch Fleet Rollout Architecture (No PC-by-PC Manual Visits)
- **Problem Addressed**: Solved the operational challenge of deploying the new agent across 50+ edge PCs across Plants 7, 8, and 9 without visiting machines 1 by 1.
- **OTA Trojan Handover via Existing Task Scheduler**: Designed an automated upgrade path where the active `NGC_Telemetry_Push` task (which already runs every 5–15 minutes as `NT AUTHORITY\SYSTEM`) fetches `Bootstrap-NGCAgent.ps1`, downloads the agent package, installs it, and silently transfers ownership.
- **Centralized Fleet Orchestrator (`scripts/Deploy-AgentFleet.ps1`)**: Built a server-side PowerShell orchestrator that queries all active edge IP addresses from `telemetry_logs`, connects across the local subnet via SMB/Admin share or WMI/WinRM, pushes the agent, and verifies rollout in parallel.
- **Automated Task Scheduler Retirement**: On startup or install, the agent automatically deletes the legacy scheduled tasks `NGC_Telemetry_Push` and `Daily_Plant_Backup` via `schtasks.exe /Delete /TN ... /F`, and terminates leftover `HWiNFO64.exe` processes.

#### 2. Native Daily Plant Backup Consolidation
- **Legacy Script Integration**: Consolidated all functionality of `scripts/DailyBackup.ps1` natively into `NGCTelemetryAgent.Services.DailyBackupService`.
- **E-Core CPU Throttling**: Protects 24/7 live AI camera inspection models (`Accusys HAIVIs`) by lowering process priority to `ProcessPriorityClass.Idle` and pinning compression affinity to Core 0 (`(IntPtr)1`) during zip creation.
- **Directory Staging & Exclusion**: Replicated Robocopy staging of `C:\Accusys` (strictly excluding `HAIVIs\log` to save 10–30 GB of redundant logs), `C:\Hardware`, `C:\Program1`, and `C:\inetpub\wwwroot\NGCDashboard`.
- **SFTP Transport Engine**: Automates archive generation (`{Line}{TierPrefix}.zip`) and upload to `172.16.207.14:22` (`/BACKUP/Plant_{Plant}/`) with automatic cleanup.
- **Live Telemetry Backup Health**: Directly injects `Backup_Status` (`LastBackupTime`, `Status`, `ZipSizeMB`, `DurationSeconds`, `RemotePath`, `ErrorMessage`) into the telemetry payload sent to `POST /api/system-status`, enabling web dashboard visibility and eliminating silent backup failures.
- **On-Demand & Scheduled Triggers**: Configured daily cron runner (02:00 AM) and interactive "Run Daily Backup Now" button in the Windows System Tray menu.

#### 3. Core C# .NET 8 Agent Implementation (`agent/NGCTelemetryAgent`)
- **Modern .NET 8 LTS WinForms Architecture**: Built with native BCL standard libraries (0 external nuget dependencies for zero supply-chain risk and offline readiness).
- **Domain Models**:
  - `AgentConfig.cs`: Persisted JSON settings (`config.json`) for server URL, push interval, backup schedule, and SFTP credentials.
  - `BackupStatus.cs`: Comprehensive backup execution telemetry model.
  - `TelemetryPayload.cs`: Complete schema matching `POST /api/system-status`.
- **Engine Services**:
  - `TelemetryCollectorService.cs`: Gathers `PC_Info.xml`, `HAIVIs` model configs, file versions (`MiddleWare`, `DIMR`, `IOC.bas`, `*.solw`), disk health, and GPU metrics via `nvidia-smi`.
  - `DailyBackupService.cs`: Thread-safe backup execution with CPU throttling and SFTP transfer.
  - `TelemetryDispatcherService.cs`: Resilient HTTP client with retry loops and automatic failure logging.
  - `TaskSchedulerManager.cs`: Handles `--install` auto-start registration and legacy task decommissioning.
  - `TrayApplicationContext.cs`: Windows System Tray (`NotifyIcon`) with live status indicators, force push, and backup triggers.

#### 4. Architecture Plan & Documentation Update (`PLAN.md`)
- Overhauled `PLAN.md` with:
  - Detailed Zero-Touch Fleet Rollout Strategy (Methods A & B).
  - Complete Daily Plant Backup Subsystem specification and throttling parameters.
  - Consolidated dual-layer process architecture.
  - Enriched API contract and database migration schema for backup tracking.

---

### [2026-11-11] - State Decoupling & Automated IIS Deployment Pipeline

#### 1. Stateful Database Decoupling
- **Decoupled Architecture**: Separated the persistent SQLite database from the stateless application codebase by relocating the database to `C:\inetpub\wwwroot\NGCDashboard_Data\database.sqlite`.
- **Zero Data Loss Migration**: Successfully migrated the live 48.7 MB plant database (containing all telemetry logs, SSD logs, and user tables) to `C:\inetpub\wwwroot\NGCDashboard_Data`.
- **IIS Security & Access Control**: Configured directory ACLs with `icacls "C:\inetpub\wwwroot\NGCDashboard_Data" /grant "IIS_IUSRS:(OI)(CI)M" /grant "IUSR:(OI)(CI)M"` to guarantee write-ahead logging (`-wal` and `-shm`) permissions for IIS worker processes.

#### 2. Environment Configuration
- Updated `C:\inetpub\wwwroot\NGCDashboard\.env` with `DB_DATABASE=C:/inetpub/wwwroot/NGCDashboard_Data/database.sqlite`.
- Updated `.env.example` in the repository so all future clones and plant setups default to the external data directory pattern.

#### 3. Automated Safe Deployment Script (`scripts/Update-NGCDashboard.ps1`)
- **Automated Directory Provisioning**: Detects if `NGCDashboard_Data` exists upon deployment; if absent, automatically creates it and assigns the necessary IIS ACLs.
- **Initial Database Seeding/Migration**: Automatically migrates any legacy database from the project root or seeds a new database if the persistent folder is empty.
- **Stateless Robocopy Synchronization**: Synchronizes project code while strictly preserving `.env`, `*.sqlite*`, `storage/`, `.git/`, and `node_modules/`.
- **Zero-Downtime Maintenance Flow**: Wraps deployments in `php artisan down` / `php artisan up`, executes `php artisan migrate --force`, re-optimizes framework caches (`optimize:clear`, `optimize`), and automatically recycles the IIS Application Pool (`NGCDashboard`).
- **End-to-End Validation**: Executed script end-to-end; verified database connectivity via `php artisan db:show` and confirmed all migrations remain intact.

#### 4. Documentation
- Updated `docs/DATABASE_DEPLOYMENT_PLAN.md` and added root `DATABASE_DEPLOYMENT_PLAN.md` with actual production paths (`C:\inetpub\wwwroot\NGCDashboard` and `C:\inetpub\wwwroot\NGCDashboard_Data`), permissions commands, and deployment instructions.

---

### [2026-11-10] - IIS Server Setup & Deployment Readiness

#### 1. Runtime Environment & PHP Extensions
- Enabled `curl`, `zip`, and `sockets` extensions in `php.ini` to support Composer package management, external API calls, and Flysystem SFTP adapter connectivity.

#### 2. Dependencies & Application Bootstrap
- Installed full PHP dependencies via Composer (`composer install`) and initialized application encryption key (`php artisan key:generate`).
- Initialized SQLite database (`database/database.sqlite`), executed all migration schemas (users, cache, jobs, personal access tokens, telemetry logs, and SSD logs), and ran seeders for default operator and admin roles.

#### 3. Frontend Compilation
- Installed Node dependencies and compiled production asset manifests and CSS/JS bundles via Vite (`npm run build`).

#### 4. IIS Integration & Verification
- Recycled IIS Application Pool (`NGCDashboard`) and verified `200 OK` responses on root dashboard (`/`) and authentication endpoints (`/login`).

---

### [2026-11-09] - Bug Fixes & Reliability Enhancements

#### 1. Edge Telemetry Collector (`public/scripts/Push-Telemetry.ps1`)
- **Multi-Path GPU Discovery**: Added multi-path fallback searching for `nvidia-smi.exe` across `C:\Windows\System32\nvidia-smi.exe`, `C:\Program Files\NVIDIA Corporation\NVSMI\nvidia-smi.exe`, and system `$env:PATH`. Fixes missing GPU telemetry ("N/A") on 64-bit systems.
- **Guarded Storage Health Check**: Wrapped `smartctl` execution in guarded try/catch blocks and safe null-checking. Added fallback to general physical volume query if NVMe bus type is not explicitly reported.
- **Single-Disk Array Preservation**: Enforced explicit array wrapping `@(...)` on `$ssdStatus` to prevent PowerShell pipeline flattening single-disk outputs into single objects, ensuring valid array payloads for the ingestion API.
- **Pre-Flight Validation Alert**: Added check for required XML fields (`Plant`, `Line`, `Tier 1`) before dispatching payloads, preventing unhandled HTTP 422 errors.

#### 2. Web Routing & Fallback Order (`routes/web.php`)
- **Route Order Correction**: Relocated `Route::fallback(...)` to the end of the file. Previously, placing it at the top level risked intercepting valid subsequent routes (`/login`, `/logout`, `/sftp`, `/settings`, `/api/sftp/backups/{plant}`).

#### 3. API Cleanups & Namespace Resolution (`routes/api.php`)
- **Namespace Casing**: Corrected lowercase `use App\http\Controllers\testController;` to PSR-4 compliant `use App\Http\Controllers\testController;`, eliminating fatal class loading issues on case-sensitive platforms.
- **Dead Route Removal**: Removed obsolete, non-functional `Route::get('/api/sftp/files')` which had missing controller imports, nonexistent method calls (`getSftpFiles`), and double-prefixed paths. Active endpoint is properly scoped under web routes.

#### 4. Telemetry State Reconstruction (`app/Http/Controllers/DashboardController.php`)
- **Fleet PC Thermal Persistence**: Updated `fleet()` database fallback logic to reconstruct `PC Thermal` metrics (CPU temperature, CPU thermal throttling, GPU temperature, GPU clock, and GPU power). Thermals now persist even when in-memory cache expires or nodes are offline.
- **Node Profile Offline Restoration**: In `show()`, added database fallback logic (`TelemetryLog::with('ssdLogs')`) when the in-memory cache key is missing. Rather than rendering empty dummy states, the node profile now displays the latest known software versions, SKU modes, AI models, and SSD statistics.

#### 5. Environment Configuration (`.env.example`)
- Documented application-specific environment variables for SFTP connectivity (`SFTP_HOST`, `SFTP_USERNAME`, `SFTP_PASSWORD`, `SFTP_PORT`, `SFTP_MAIN_DIR`) and node configuration forwarding (`CONFIG_API_URL`).

---

## Current Status
- **Backend Application**: Stable. Routes, controller logic, and state fallbacks verified.
- **Edge Collector Script**: Syntax validated (`[System.Management.Automation.Language.Parser]::ParseFile` returned 0 errors).
- **Offline Readiness**: Web assets (Tailwind CSS, Plus Jakarta Sans fonts, Chart.js) are self-contained in `public/` for air-gapped industrial plant networks.

---

## Roadmap & Recommended Next Steps
1. **Telemetry Ingest Security**: Add an API token or pre-shared key header validation to `POST /api/system-status` to authenticate edge nodes on the plant subnet.
2. **Database Query Optimization**: Optimize the 24-node lookup loop in `DashboardController::fleet()` into a single subquery or batch query to eliminate N+1 database queries.
3. **Automated Queue Worker**: Ensure background queue runner is configured (e.g. via Task Scheduler or Supervisor) for `FetchSftpBackups` jobs.
