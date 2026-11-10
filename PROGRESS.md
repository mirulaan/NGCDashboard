# NGC Dashboard - Project Status & Progress Log

## Project Overview
**NGC Dashboard** is an on-premise industrial IoT and manufacturing line monitoring system designed for visual/optical inspection plants (such as glove manufacturing lines). It collects real-time hardware vitals (NVMe SSD wear, CPU/GPU thermals), AI defect detection model states (Accusys HAIVIs), and software versioning from edge inspection PCs, displays them on an operations dashboard, and enables remote model updates via SFTP and an external Config API.

* **Tech Stack**: Laravel 13.8, PHP 8.3+, Tailwind CSS v4 (local offline standalone distribution), Chart.js, SQLite/MySQL, Flysystem SFTP.
* **Edge Agent**: Windows PowerShell 5.1+, `HWiNFO64`, `smartmontools` (`smartctl.exe`), `nvidia-smi`.
* **Topology**: Plant-level monitoring (Plants 7, 8, 9) with dual-side (Left/Right) inspection lines per plant.

---

## Progress & Change Log

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
