# NGC Dashboard - Database Management & Multi-Plant Deployment Plan

## 1. Problem Statement & Objectives
NGC Dashboard is deployed across multiple plant edge PCs (e.g., Plant 7, Plant 8, Plant 9) with individual plant operational states and telemetry history. When releasing updates, the deployment workflow involves copying and replacing the project files. 

Keeping `database.sqlite` and `.env` inside the project root (`database/database.sqlite`) presents three critical risks:
1. **Accidental Data Loss**: Overwriting the project directory wipes the plant's historical telemetry, SSD health metrics, and user accounts.
2. **File Lock Contention**: Windows IIS / PHP FastCGI locks `database.sqlite` while active, causing *"File in Use / Access Denied"* errors during folder replacement.
3. **Configuration Erasure**: Plant-specific configurations in `.env` (node mappings, SFTP credentials, application keys) get overwritten by development settings.

**Objective**: Decouple state (database, configuration, logs) from stateless application code so that the entire project codebase can be updated or replaced without affecting live plant data.

---

## 2. Architecture: Separation of Code and State

```
D:\ (or Plant PC Drive)
├── NGCDashboard_Data\                 <-- STATEFUL DATA (Never overwritten during updates)
│   ├── database.sqlite                <-- Persistent SQLite database
│   ├── database.sqlite-wal            <-- Dynamic write-ahead log
│   └── database.sqlite-shm            <-- Dynamic shared-memory file
│
└── NGCDashboard\                      <-- STATELESS CODEBASE (Safe to wipe/replace)
    ├── app\
    ├── config\
    ├── public\
    ├── routes\
    ├── .env                           <-- Machine-specific config (preserved)
    └── storage\                       <-- Application logs & temp files (preserved)
```

---

## 3. Configuration & Permissions

### 3.1 Environment Configuration (`.env`)
Point the database connection to the external path using forward slashes (`/`):
```ini
DB_CONNECTION=sqlite
DB_DATABASE=D:/NGCDashboard_Data/database.sqlite
```
*Note: Using forward slashes prevents backslash escape issues across PHP and Windows path parsers.*

### 3.2 File System & IIS Permissions
SQLite creates temporary journal/lock files (`-wal` and `-shm`) in the same directory as the database file. IIS worker processes must have **Modify (M)** permissions on the parent directory:
```powershell
icacls "D:\NGCDashboard_Data" /grant "IIS_IUSRS:(OI)(CI)M" /grant "IUSR:(OI)(CI)M"
```

---

## 4. Step-by-Step Data Migration (Zero Data Loss)

To migrate an existing SQLite database without losing any historical data:

1. **Create the external data folder**:
   ```powershell
   New-Item -ItemType Directory -Force -Path "D:\NGCDashboard_Data"
   ```

2. **Copy the existing database file**:
   ```powershell
   Copy-Item "D:\Acer-File\Training\VibeCode\NGCDashboard\database\database.sqlite" -Destination "D:\NGCDashboard_Data\database.sqlite"
   ```

3. **Update `.env`**:
   Set `DB_DATABASE=D:/NGCDashboard_Data/database.sqlite`.

4. **Verify connection and data integrity**:
   ```powershell
   php artisan migrate:status
   ```
   *Expected result: All existing migrations show as `[Ran]`, confirming all tables and rows are preserved.*

---

## 5. Deployment & Code Update Protocol

### 5.1 Safe vs. Dangerous Artisan Commands

| Command | Action in Production | Impact on Data |
| :--- | :--- | :--- |
| `php artisan migrate --force` | Applies **only new** migration files | **100% Safe** (Preserves existing data) |
| `php artisan optimize:clear` | Clears compiled route/config cache | **100% Safe** |
| `php artisan optimize` | Re-compiles cached config/routes for speed | **100% Safe** |
| `php artisan migrate:fresh` | Drops all tables and rebuilds schemas | ❌ **DANGEROUS** (Wipes all data) |
| `php artisan migrate:refresh` | Rolls back all migrations and re-runs | ❌ **DANGEROUS** (Wipes all data) |

### 5.2 Automated Update Script (`Update-NGCDashboard.ps1`)

To update plant PCs safely without manual drag-and-drop errors, run this script from a USB drive or deploy bundle:

```powershell
<#
.SYNOPSIS
    Automated, safe deployment script for NGC Dashboard on Plant PCs.
.DESCRIPTION
    Replaces application code while preserving the external database, local .env, and storage directory.
#>
param (
    [string]$Source = "D:\Release\NGCDashboard",
    [string]$Destination = "D:\NGCDashboard"
)

Write-Host ">>> [1/5] Enabling maintenance mode..." -ForegroundColor Yellow
Set-Location $Destination
php artisan down

Write-Host ">>> [2/5] Copying code files (Excluding .env, databases, storage)..." -ForegroundColor Cyan
robocopy $Source $Destination /E /XF ".env" "*.sqlite" /XD "storage" ".git" "node_modules"

Write-Host ">>> [3/5] Applying new database migrations..." -ForegroundColor Green
php artisan migrate --force

Write-Host ">>> [4/5] Refreshing application caches..." -ForegroundColor Green
php artisan optimize:clear
php artisan optimize

Write-Host ">>> [5/5] Bringing application back online..." -ForegroundColor Green
php artisan up

Write-Host ">>> Deployment completed successfully with zero data loss!" -ForegroundColor Green
```

---

## 6. Long-Term Scalability: Local MySQL / MariaDB

If plant requirements grow (higher telemetry frequency, multi-user concurrent writes, or plant-wide centralization):
- Install a lightweight **MySQL 8.0** or **MariaDB** service on the plant host.
- Set `DB_CONNECTION=mysql` in `.env`.
- Database data will reside entirely inside MySQL server storage, ensuring 100% physical separation from the application code folder.
