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
C:\inetpub\wwwroot\
├── NGCDashboard_Data\                 <-- STATEFUL DATA (Created if missing; never overwritten during updates)
│   ├── database.sqlite                <-- Persistent SQLite database (Plant telemetry & records)
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
Point the database connection to the external persistent path using forward slashes (`/`):
```ini
DB_CONNECTION=sqlite
DB_DATABASE=C:/inetpub/wwwroot/NGCDashboard_Data/database.sqlite
```
*Note: Using forward slashes prevents backslash escape issues across PHP and Windows path parsers.*

### 3.2 File System & IIS Permissions
SQLite creates temporary journal/lock files (`-wal` and `-shm`) in the same directory as the database file. When deploying, `C:\inetpub\wwwroot\NGCDashboard_Data` may not exist yet, so it must be created, and the IIS worker process identity must be granted **Modify (M)** permissions on the folder:
```powershell
# 1. Create directory if it does not exist
if (-not (Test-Path "C:\inetpub\wwwroot\NGCDashboard_Data")) {
    New-Item -ItemType Directory -Force -Path "C:\inetpub\wwwroot\NGCDashboard_Data"
}

# 2. Grant IIS modify permissions
icacls "C:\inetpub\wwwroot\NGCDashboard_Data" /grant "IIS_IUSRS:(OI)(CI)M" /grant "IUSR:(OI)(CI)M"
```

---

## 4. Step-by-Step Data Migration (Zero Data Loss)

To migrate an existing SQLite database without losing any historical data:

1. **Create the external data folder (if not already created)**:
   ```powershell
   if (-not (Test-Path "C:\inetpub\wwwroot\NGCDashboard_Data")) {
       New-Item -ItemType Directory -Force -Path "C:\inetpub\wwwroot\NGCDashboard_Data"
   }
   ```

2. **Grant IIS permissions**:
   ```powershell
   icacls "C:\inetpub\wwwroot\NGCDashboard_Data" /grant "IIS_IUSRS:(OI)(CI)M" /grant "IUSR:(OI)(CI)M"
   ```

3. **Migrate existing database file**:
   ```powershell
   if ((Test-Path "C:\inetpub\wwwroot\NGCDashboard\database\database.sqlite") -and -not (Test-Path "C:\inetpub\wwwroot\NGCDashboard_Data\database.sqlite")) {
       Copy-Item "C:\inetpub\wwwroot\NGCDashboard\database\database.sqlite" -Destination "C:\inetpub\wwwroot\NGCDashboard_Data\database.sqlite" -Force
   }
   ```

4. **Update `.env` in `C:\inetpub\wwwroot\NGCDashboard`**:
   Set `DB_DATABASE=C:/inetpub/wwwroot/NGCDashboard_Data/database.sqlite`.

5. **Verify connection and data integrity**:
   ```powershell
   Set-Location "C:\inetpub\wwwroot\NGCDashboard"
   php artisan migrate:status
   php artisan db:show
   ```
   *Expected result: Database shows connected to `C:/inetpub/wwwroot/NGCDashboard_Data/database.sqlite` and all migrations show `[Ran]`.*

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

### 5.2 Automated Update Script (`scripts/Update-NGCDashboard.ps1`)

To update plant PCs safely without manual drag-and-drop errors, run the automated script (`scripts/Update-NGCDashboard.ps1`):

```powershell
<#
.SYNOPSIS
    Automated, safe deployment script for NGC Dashboard on Windows IIS Plant Hosts.
.DESCRIPTION
    Deploys or updates the stateless application code into IIS wwwroot, creates and secures
    the persistent external database directory if missing, and preserves machine-specific
    configuration (.env), telemetry data (database.sqlite), and application storage.
#>
param (
    [string]$Source = "D:\Acer-File\Training\VibeCode\NGCDashboard",
    [string]$Destination = "C:\inetpub\wwwroot\NGCDashboard",
    [string]$DataPath = "C:\inetpub\wwwroot\NGCDashboard_Data",
    [string]$AppPoolName = "NGCDashboard"
)

# 1. Create persistent data directory if missing & assign IIS permissions
if (-not (Test-Path $DataPath)) {
    New-Item -ItemType Directory -Force -Path $DataPath
}
icacls $DataPath /grant "IIS_IUSRS:(OI)(CI)M" /grant "IUSR:(OI)(CI)M"

# 2. Seed / migrate database if target doesn't exist
$targetDb = Join-Path $DataPath "database.sqlite"
$destDb = Join-Path $Destination "database\database.sqlite"
if (-not (Test-Path $targetDb) -and (Test-Path $destDb)) {
    Copy-Item $destDb -Destination $targetDb -Force
}

# 3. Configure .env in destination
$destEnv = Join-Path $Destination ".env"
$formattedDbPath = ($targetDb -replace '\\', '/')
if (Test-Path $destEnv) {
    $envContent = Get-Content $destEnv -Raw
    if ($envContent -match "(?m)^DB_DATABASE=.*") {
        $envContent = $envContent -replace "(?m)^DB_DATABASE=.*", "DB_DATABASE=$formattedDbPath"
    } else {
        $envContent = $envContent -replace "(?m)^DB_CONNECTION=sqlite", "DB_CONNECTION=sqlite`r`nDB_DATABASE=$formattedDbPath"
    }
    Set-Content -Path $destEnv -Value $envContent -NoNewline
}

# 4. Maintenance mode
Set-Location $Destination
php artisan down --retry=60

# 5. Robocopy stateless application code
$robocopyArgs = @(
    $Source,
    $Destination,
    "/E",
    "/XF", ".env", "*.sqlite", "*.sqlite-wal", "*.sqlite-shm",
    "/XD", "storage", ".git", "node_modules", "NGCDashboard_Data",
    "/R:2", "/W:1", "/NP"
)
& robocopy.exe @robocopyArgs
if ($LASTEXITCODE -ge 8) { throw "Robocopy error: $LASTEXITCODE" }

# 6. Database migrations & cache optimization
php artisan migrate --force
php artisan optimize:clear
php artisan optimize

# 7. Bring application back online & recycle IIS AppPool
php artisan up
Restart-WebAppPool -Name $AppPoolName -ErrorAction SilentlyContinue
```

---

## 6. Long-Term Scalability: Local MySQL / MariaDB

If plant requirements grow (higher telemetry frequency, multi-user concurrent writes, or plant-wide centralization):
- Install a lightweight **MySQL 8.0** or **MariaDB** service on the plant host.
- Set `DB_CONNECTION=mysql` in `.env`.
- Database data will reside entirely inside MySQL server storage, ensuring 100% physical separation from the application code folder.
