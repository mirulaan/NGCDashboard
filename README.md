# NGC Dashboard

[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat&logo=php&logoColor=white)](https://www.php.net/)
[![Laravel Framework](https://img.shields.io/badge/Laravel-13.8-FF2D20?style=flat&logo=laravel&logoColor=white)](https://laravel.com/)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-v4.0-06B6D4?style=flat&logo=tailwindcss&logoColor=white)](https://tailwindcss.com/)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Network Ready](https://img.shields.io/badge/Network-Air--Gapped%20%2F%20On--Premise-success)](#air-gapped--offline-readiness)

An on-premise industrial IoT and manufacturing line monitoring platform tailored for visual and optical inspection plants (e.g., glove manufacturing lines). 

**NGC Dashboard** aggregates real-time hardware telemetry (NVMe SSD wear and health, CPU and GPU thermals, power draw), AI defect detection model configurations (Accusys HAIVIs), and vision software versioning from edge inspection PCs. It visualizes plant health on an interactive operations dashboard and enables remote model updates via SFTP and an external Configuration API.

---

## Table of Contents

- [Key Features](#key-features)
- [System Architecture](#system-architecture)
- [Accurate Tech Stack](#accurate-tech-stack)
- [Prerequisites](#prerequisites)
- [Installation Steps](#installation-steps)
- [Plant Topology Configuration](#plant-topology-configuration)
- [Edge Agent Deployment (`Push-Telemetry.ps1`)](#edge-agent-deployment-push-telemetryps1)
- [Usage Guide](#usage-guide)
  - [1. Fleet Overview Dashboard](#1-fleet-overview-dashboard)
  - [2. Node Deep-Dive & 30-Day Trendlines](#2-node-deep-dive--30-day-trendlines)
  - [3. Remote Model Updates](#3-remote-model-updates)
  - [4. SFTP File Explorer & Backups](#4-sftp-file-explorer--backups)
  - [5. User Settings & Security](#5-user-settings--security)
- [API Reference](#api-reference)
- [Air-Gapped & Offline Readiness](#air-gapped--offline-readiness)
- [Troubleshooting & Maintenance](#troubleshooting--maintenance)

---

## Key Features

* **Fleet-Wide Telemetry Monitoring**: Real-time matrix view of plant inspection lines across dual-side (Left/Right) topologies with a 30-minute heartbeat rule for automatic Online/Offline status classification.
* **Hardware Vitals Tracking**:
  * **NVMe SSD Endurance**: Queries wear percentage, controller temperatures, total space, and remaining capacity via `smartctl` SMART health logs.
  * **CPU Thermals**: Extracts package temperature and core thermal throttling indicators via HWiNFO64 registry integration.
  * **GPU Metrics**: Queries real-time GPU temperature, clock speed (MHz), and board power draw (W) directly via `nvidia-smi`.
* **AI Defect Detection Model Auditing**: Tracks active SKU mode (Color, Black, White) and live deep learning models (`Post`, `NoGlove`, `Pre`, `GSS Defect`, `GSS Machine`, and black variants) from Accusys HAIVIs configuration files.
* **Inspection Software Version Audit**: Audits installed versions and modification dates for `Accusys_MiddleWare`, `DIMR_Engine`, `IOC.bas`, and `.solw` solution files.
* **Remote Model Configuration Dispatch**: Authenticated engineers can browse newer model weights hosted on an SFTP storage server and push configuration updates to edge nodes through an external Config API.
* **SFTP Backup Verification**: Monitors the presence, size, and timestamp of line backup archives stored on the central SFTP server (`/BACKUP/Plant_{plant}/`).
* **Air-Gapped Operation**: All styles, icons, scripts (Chart.js, Tailwind bundle), and web fonts (Plus Jakarta Sans) are self-contained in `public/`—no external CDN or internet connection is required.

---

## System Architecture

```
                             +-----------------------------------------------+
                             |             Edge Inspection PCs               |
                             |   Lines (e.g. 93-104) Dual-Side (Left/Right)  |
                             |                                               |
                             |  * C:\Hardware\PC_Info.xml (Plant/Line/Side)  |
                             |  * HWiNFO64 (CPU Temp & Thermal Throttling)   |
                             |  * smartctl.exe (NVMe Wear % & Thermals)      |
                             |  * nvidia-smi.exe (GPU Temp, Power, Clock)    |
                             |  * Accusys HAIVIs (appsettings.json / models) |
                             |                                               |
                             |     Script: public/scripts/Push-Telemetry.ps1 |
                             +-----------------------+-----------------------+
                                                     |
                                            HTTP POST JSON
                                       /api/system-status
                                                     |
                                                     v
+----------------------------------------------------+----------------------------------------------------+
|                                         NGC Dashboard Server                                            |
|                                                                                                         |
|   +--------------------------+    +--------------------------+    +---------------------------------+   |
|   |   Laravel 13.8 Backend   |    |    Database & Cache      |    |      Frontend Interface         |   |
|   |                          |    |                          |    |                                 |   |
|   | * DashboardController    |--->| * SQLite / MySQL         |--->| * Fleet Matrix (fleet.blade)    |   |
|   | * AuthController         |    | * TelemetryLog & SsdLog  |    | * Node Profile (node.blade)     |   |
|   | * FetchSftpBackups (Job) |    | * In-Memory Plant Matrix |    | * Chart.js 30-Day Wear Trends   |   |
|   +--------------------------+    +--------------------------+    +---------------------------------+   |
|                |                                                                                        |
+----------------|----------------------------------------------------------------------------------------+
                 |
                 +-------------------+--------------------+
                 |                                        |
                 v SFTP (SSH Port 22)                     v HTTP POST (JSON Payload)
+------------------------------------+   +------------------------------------+
|          SFTP Storage Host         |   |       External Config API          |
|                                    |   |         (CONFIG_API_URL)           |
| * /Software/Model/ (DL Models)     |   |                                    |
| * /BACKUP/Plant_{N}/ (Line Backups)|   | * Forwards new model config to     |
+------------------------------------+   |   designated edge inspection PC    |
                                         +------------------------------------+
```

---

## Accurate Tech Stack

### Backend Application
* **Framework**: Laravel 13.8
* **Runtime**: PHP 8.3+
* **Authentication**: Laravel Session & Cookie-based Authentication (`AuthController`), Laravel Sanctum 4.0
* **Storage & SFTP**: Flysystem SFTP adapter (`league/flysystem-sftp-v3`) for remote model discovery and backup verification
* **Database Layer**: SQLite (default zero-configuration embedded engine) with support for MySQL / MariaDB / PostgreSQL
* **Cache & Queues**: Database driver cache (`plant_matrix` real-time store) and database queue runner for asynchronous background SFTP polling (`FetchSftpBackups`)
* **Developer Tools & Testing**: Pest PHP 4.7 (`pestphp/pest-plugin-laravel`), Laravel Pint, Laravel Pail, and Laravel Boost

### Frontend Interface
* **Templates**: Laravel Blade Engine
* **CSS Framework**: Tailwind CSS v4 (`@tailwindcss/vite` build pipeline + local standalone offline distribution in `public/js/tailwindcss.js`)
* **Visualization**: Chart.js v4 with `chartjs-plugin-trendline` (bundled locally in `public/js/`)
* **Typography**: Plus Jakarta Sans & Instrument Sans (locally hosted in `public/fonts/` and `public/css/fonts.css`)
* **Build System**: Vite 8.0 & `laravel-vite-plugin` 3.1

### Edge Telemetry Agent
* **Script Engine**: Windows PowerShell 5.1+
* **Hardware Sensors**:
  * **HWiNFO64 v8.x**: Automated extraction of CPU package temperatures and thermal throttling flags from registry hive `HKCU:\Software\HWiNFO64\VSB`
  * **smartmontools (`smartctl.exe`)**: NVMe SMART health attributes (`percentage_used`, controller thermals, remaining space)
  * **NVIDIA System Management Interface (`nvidia-smi.exe`)**: Native GPU telemetry (`temperature.gpu`, `power.draw`, `clocks.gr`)
* **Inspection Suite**: Accusys HAIVIs vision platform (`appsettings.json`, `vmaimodel.txt`, `Accusys_MiddleWare.exe`, `DIMR_Engine.exe`, `IOC.bas`, `*.solw`)

---

## Prerequisites

Before deploying the NGC Dashboard, ensure the host server satisfies:

1. **PHP 8.3 or higher** with the following extensions enabled in `php.ini`:
   * `pdo_sqlite` (or `pdo_mysql` if using MySQL)
   * `mbstring`, `openssl`, `curl`, `fileinfo`, `xml`, `zip`
   * `sockets` (required for SFTP connectivity via Flysystem)
2. **Composer 2.x**
3. **Node.js 20.x or higher** & **npm** (for building frontend assets)
4. **Web Server**: Microsoft IIS (configured via `public/web.config`), Nginx, or Apache (configured via `public/.htaccess`)
5. *(Optional)* **SFTP Server**: SSH/SFTP service running on port 22 holding AI model weights and inspection backups

---

## Installation Steps

### 1. Clone the Repository
```bash
git clone https://github.com/your-organization/NGCDashboard.git
cd NGCDashboard
```

### 2. Install PHP Dependencies
```bash
composer install --no-interaction --prefer-dist --optimize-autoloader
```

### 3. Configure the Environment File
Copy the sample environment file to `.env`:
```powershell
# Windows PowerShell
Copy-Item .env.example .env
```
Generate an application encryption key:
```bash
php artisan key:generate
```

### 4. Configure Application Environment Variables
Edit `.env` to match your plant network configuration:

```ini
APP_NAME="NGC Dashboard"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://192.168.100.243  # IP or domain of your dashboard server

# Database Configuration (Default: SQLite)
DB_CONNECTION=sqlite
# For MySQL / MariaDB:
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=ngc_dashboard
# DB_USERNAME=dbuser
# DB_PASSWORD=dbpassword

# Cache & Queues
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=database

# Central SFTP Storage (AI Models & Plant Backups)
SFTP_HOST=192.168.1.100
SFTP_USERNAME=sftp_operator
SFTP_PASSWORD="YourStrongSftpPassword"
SFTP_PORT=22
SFTP_MAIN_DIR=/Software/Model

# Edge Node Remote Dispatch API
CONFIG_API_URL="http://192.168.1.50:8080/api/node/update"
```

### 5. Run Database Migrations and Seeders
Initialize the database tables and create the default operator and administrator accounts:
```bash
# Creates database/database.sqlite if missing and executes migrations
php artisan migrate --seed
```

#### Default Credentials Created by Seeder:
| Username | Role | Default Password | Initial Purpose |
| :--- | :--- | :--- | :--- |
| `admin` | Plant Operator | `admin123` | Model editing and backup monitoring |
| `superadmin` | Administrator | `superadmin123` | System administration & user password management |

> [!CAUTION]
> Log in immediately upon first boot and update these default passwords via the **Settings** menu (`/settings`).

### 6. Install & Compile Frontend Assets
```bash
npm install
npm run build
```

### 7. Run the Application

#### Development Mode:
Run all background services simultaneously (server, queue worker, log tailing, Vite):
```bash
composer run dev
```
Or run the built-in PHP development server:
```bash
php artisan serve --host=0.0.0.0 --port=80
```

#### Production Mode (IIS / Windows Server):
* Point IIS Website Physical Path to `D:\VibeCode\NGCDashboard\public`.
* Ensure `web.config` is active (URL rewrite module and `.ps1` MIME map are already declared in `public/web.config`).
* Ensure IIS Application Pool identity has read/write permissions to `storage/` and `database/`.

#### Start the Background Queue Worker:
To process background SFTP backup scans (`FetchSftpBackups`):
```bash
php artisan queue:work --tries=3 --timeout=90
```
*(On Windows, register this command as a Windows Service using NSSM or Windows Task Scheduler).*

---

## Plant Topology & Auto-Detection Configuration

The dashboard automatically determines which plant and line topology to display without requiring code edits or commenting out blocks.

### 1. Automatic Plant Detection (Default)

By default, the server runs in **auto-detection mode** (`TELEMETRY_PLANT=auto` in `.env`). It dynamically detects the manufacturing plant based on:

1. **Incoming Telemetry Activity**: Checks the SQLite/MySQL database (`telemetry_logs`) for the plant actively logging data from edge PCs (most frequent in the last 7 days or latest received record).
2. **Server Hostname**: Detects plant patterns in the Windows computer name (e.g., `Plant7`, `P8`, `Plant-9`).
3. **Network IPv4 Subnet**: Matches the server's local IP address against known plant subnets configured in `config/telemetry.php`.
4. **Fallback**: Gracefully falls back to Plant 9 if no signals are found on a fresh server.

### 2. Manual Environment Override (`.env`)

To lock a plant server permanently to a specific plant without auto-detection, set `TELEMETRY_PLANT` in `.env`:

```ini
# Auto-detect (default):
TELEMETRY_PLANT=auto

# Or explicitly lock to a plant:
# TELEMETRY_PLANT=7   # Lines 73-78
# TELEMETRY_PLANT=8   # Lines 83-92, 105
# TELEMETRY_PLANT=9   # Lines 93-104
```

Because `.env` is stateful and preserved across automated deployments (`Update-NGCDashboard.ps1`), this setting will never be overwritten.

### 3. Interactive Web Plant Switcher

Technicians and operators can also inspect different plants on the fly:
* Click **7**, **8**, or **9** in the dashboard header to instantly view that plant's lines.
* Click **AUTO** to return to automatic detection mode.

### 4. Plant Topology Reference

| Plant | Line Range | Sides | Total Inspection Nodes |
| :--- | :--- | :--- | :--- |
| **Plant 7** | Lines 73 – 78 | L / R | 12 nodes (6 lines × 2) |
| **Plant 8** | Lines 83 – 92, 105 | L / R | 22 nodes (11 lines × 2) |
| **Plant 9** | Lines 93 – 104 | L / R | 24 nodes (12 lines × 2) |

---

## Edge Agent Deployment (`Push-Telemetry.ps1`)

The edge script runs on each inspection PC to gather machine vitals and transmit them back to the dashboard server.

### Edge PC Prerequisites
1. **Windows 10 / 11 64-bit** with PowerShell 5.1+.
2. **Identification XML**: `C:\Hardware\PC_Info.xml` containing the line definition:
   ```xml
   <LVData>
     <Cluster>
       <String><Name>P_str</Name><Val>9</Val></String>
       <String><Name>L_str</Name><Val>93</Val></String>
       <String><Name>T1_str</Name><Val>L</Val></String>
       <String><Name>T2_str</Name><Val>1</Val></String>
     </Cluster>
   </LVData>
   ```
3. **NVMe SMART Utility**: `smartmontools` installed at `C:\Program Files\smartmontools\bin\smartctl.exe`.
4. **NVIDIA GPU Drivers**: `nvidia-smi.exe` located in `System32`, `NVSMI`, or system `$PATH`.

### Automated Installation via Agent Script
1. Deploy `Push-Telemetry.ps1` from the dashboard server's `public/scripts/` directory to the edge machine (e.g., `C:\Scripts\Push-Telemetry.ps1`).
2. The script automatically auto-elevates to Administrator privileges upon execution.
3. On its first execution, the script automatically:
   * Downloads `HWiNFO64.zip` from `http://<server-ip>/scripts/HWiNFO64.zip`.
   * Unpacks it into `C:\Hardware\monitor\`.
   * Applies the required registry configuration (`HWiNFO64_settings.reg`) to output sensors to `HKCU:\Software\HWiNFO64\VSB`.
   * Launches `HWiNFO64.exe` silently in the background.
   * Generates a marker file `hwinfo_v2_setup.done` to avoid repeated setups.

### Running with Windows Task Scheduler
Create a Windows scheduled task to execute the script every 10–15 minutes:
```powershell
$Action = New-ScheduledTaskAction -Execute "PowerShell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -File C:\Scripts\Push-Telemetry.ps1"
$Trigger = New-ScheduledTaskTrigger -AtStartup
$Settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
Register-ScheduledTask -TaskName "NGC_Telemetry_Push" -Action $Action -Trigger $Trigger -Settings $Settings -User "NT AUTHORITY\SYSTEM" -RunLevel Highest
```

---

## Usage Guide

### 1. Fleet Overview Dashboard
* Navigate to `http://<your-server-ip>/`.
* **Matrix Grid**: Displays all lines configured for the active plant, split into **Left (L)** and **Right (R)** inspection stations.
* **Status Indicator**:
  * **Green (Online)**: Received a telemetry packet within the last 30 minutes.
  * **Red (Offline)**: No telemetry received within 30 minutes. Even when offline, the dashboard reconstructs the latest known disk wear, CPU/GPU thermals, and software versions from the database.
* **Sync Backups Button**: Top-right button triggers an asynchronous scan of the SFTP `/BACKUP/Plant_{N}/` folder, showing whether an archive exists for each node.

### 2. Node Deep-Dive & 30-Day Trendlines
* Click any node card from the fleet grid to open `/node/{plant}/{line}/{side}`.
* **Hardware Thermals**:
  * Displays **CPU Package Temperature** and highlights if **Core Thermal Throttling** is active.
  * Displays **GPU Core Temperature**, **Power Draw (W)**, and **Clock Frequency (MHz)**.
* **Storage Life & Wear**:
  * Displays NVMe drive letter, model name, controller temperature, and total / available gigabytes.
  * Displays SMART **Percentage Used** wear indicator.
  * Features an interactive **Chart.js 30-Day SSD Wear History Chart** with linear trend projection to forecast disk failure.
* **Software Version Audit**:
  * Displays active `.solw` solution file, `MiddleWare` binary version, and compile timestamps for `DIMR_Engine` and `IOC.bas`.
* **Deep Learning Model Matrix**:
  * Highlights current SKU mode badge: **Color Mode (0)**, **Black Mode (1)**, or **White Mode (2)**.
  * Tabulates active models for `Post`, `NoGlove`, `Pre`, `GSS Defect`, `GSS Machine`, and their black counterparts.
* **Historical Event Table**:
  * Displays a chronological audit table of the 10 most recent telemetry check-ins.

### 3. Remote Model Updates
1. Click **Login** in the top navigation bar and log in with your credentials (`admin` / `admin123`).
2. Navigate to the desired node profile page.
3. Click the **Edit Configuration** button.
4. The dashboard queries the SFTP server via `/api/sftp/config-options/{plant}` and populates dropdown menus with the 10 most recent model weights found in `/Software/Model/`.
5. Select the target models and SKU mode.
6. Click **Save & Dispatch**: The dashboard constructs a standardized payload and forwards it via HTTP POST to the external `CONFIG_API_URL` to update the inspection PC.

### 4. SFTP File Explorer & Backups
* Navigate to `/sftp` while logged in to access the built-in SFTP file explorer.
* Allows operators to inspect subdirectories, verify uploaded `.zip` archives, review modified dates, and confirm AI model file integrity directly on the SFTP host.

### 5. User Settings & Security
* Navigate to `/settings` while authenticated.
* Update existing passwords by supplying your current password and confirming the new password (minimum 8 characters).

---

## API Reference

### 1. Ingest Edge Telemetry
* **Method**: `POST`
* **Endpoint**: `/api/system-status`
* **Content-Type**: `application/json`

#### Request Payload Schema:
```json
{
  "Plant": "9",
  "Line": "93",
  "Tier 1": "L",
  "Tier 2": "1",
  "Version": {
    "solw": "DefaultSolution.solw",
    "MiddleWare": "2.4.1.0",
    "DIMR": "08/11/2026",
    "IOC.bas": "08/11/2026"
  },
  "Config Model": {
    "SkuMode": "0",
    "Post": "Post_v12.dl",
    "NoGlove": "NoGlove_v4.dl",
    "Pre": "Pre_v3.dl",
    "GSS Defect": "GSS_v8.dl",
    "Post Black": "PostBlack_v2.dl",
    "NoGlove Black": "NoGloveBlack_v1.dl",
    "Pre Black": "PreBlack_v1.dl",
    "GSS Black": "GSSBlack_v2.dl",
    "GSS Machine": "Machine_v5.dl"
  },
  "SSD_Status": [
    {
      "DiskModel": "Samsung SSD 980 PRO 1TB",
      "PhysicalDiskNumber": 0,
      "DriveLetter": "C:",
      "TotalSpaceGB": 931.51,
      "RemainingSpaceGB": 642.10,
      "TemperatureCelsius": 42,
      "PercentageUsed": 14
    }
  ],
  "PC Thermal": {
    "CPU Temp": "54",
    "CPU Thermal Throttle": "No",
    "GPU Temp": "48",
    "GPU Power": "65",
    "GPU Clock": "1650"
  }
}
```

#### Response:
* **HTTP 200 OK**:
  ```json
  {
    "message": "Node 9-93-L updated and logged successfully"
  }
  ```
* **HTTP 422 Unprocessable Content**: Returned if required identity fields (`Plant`, `Line`, `Tier 1`) are missing.

---

### 2. Fetch SFTP Model Options
* **Method**: `GET`
* **Endpoint**: `/api/sftp/config-options/{plant}`
* **Auth**: Required (`auth` session cookie)
* **Response**: JSON map of model categories to lists of available filenames sorted by newest modified timestamp.

---

### 3. Fetch SFTP Line Backups
* **Method**: `GET`
* **Endpoint**: `/api/sftp/backups/{plant}`
* **Response**:
  ```json
  {
    "status": "success",
    "data": [
      {
        "filename": "93L.zip",
        "size": "45.12 MB",
        "changed_date": "2026-11-09 14:22:10"
      }
    ]
  }
  ```

---

### 4. Dispatch Node Config Update
* **Method**: `POST`
* **Endpoint**: `/node/update-config`
* **Auth**: Required (`auth` session cookie)
* **Form Parameters**: `config_payload` (JSON string containing updated model parameters)
* **Behavior**: Forwards payload to `CONFIG_API_URL` and redirects back with success or error notifications.

---

## Air-Gapped & Offline Readiness

Industrial production facilities frequently isolate manufacturing lines from external public networks. The NGC Dashboard is strictly designed for offline resilience:

* **No CDN Dependencies**: Stylesheet bundles, typography, and script libraries are stored locally:
  * `public/js/tailwindcss.js`
  * `public/js/chart.js` & `public/js/chartjs-plugin-trendline.js`
  * `public/css/fonts.css` & `public/fonts/` (Plus Jakarta Sans)
* **Offline Fallback Architecture**: If edge inspection PCs lose network connectivity or shut down during changeovers, the controller fallback system reconstructs node metrics, drive wear, and model states from historical database records rather than showing blank dashboards.

---

## Troubleshooting & Maintenance

### 1. Edge Node Displays "Offline" on Dashboard
* Verify edge PC network route can reach dashboard server IP on port 80/443:
  ```powershell
  Test-NetConnection -ComputerName <Server-IP> -Port 80
  ```
* Ensure `C:\Hardware\PC_Info.xml` exists on the edge PC and contains valid `P_str`, `L_str`, and `T1_str` tags.
* Run `C:\Scripts\Push-Telemetry.ps1` manually in an administrative PowerShell terminal to review verbose terminal output.

### 2. GPU Telemetry Reports "N/A"
* Confirm NVIDIA driver is installed on the edge PC.
* Verify `nvidia-smi` is accessible:
  ```powershell
  & "C:\Windows\System32\nvidia-smi.exe" --query-gpu=temperature.gpu,power.draw,clocks.gr --format=csv,noheader,nounits
  ```

### 3. CPU Temperature Reports "N/A"
* Confirm HWiNFO64 is running on the edge PC.
* Check if registry entries are actively writing to `HKCU:\Software\HWiNFO64\VSB`.
* Re-run setup by removing `C:\Hardware\monitor\hwinfo_v2_setup.done` and executing `Push-Telemetry.ps1`.

### 4. SFTP Connection Timeouts
* Test SFTP connectivity from the server:
  ```bash
  ssh -p <SFTP_PORT> <SFTP_USERNAME>@<SFTP_HOST>
  ```
* Verify `SFTP_HOST`, `SFTP_USERNAME`, `SFTP_PASSWORD`, and `SFTP_PORT` in `.env`.
* Ensure PHP has the `sockets` extension enabled in `php.ini`.

### 5. Clear Application Caches
When updating code, routes, or plant topologies:
```bash
php artisan optimize:clear
```

---

## License

NGC Dashboard is open-source software licensed under the [MIT License](LICENSE).
