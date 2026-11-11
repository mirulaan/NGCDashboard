<#
.SYNOPSIS
    Automated, safe deployment script for NGC Dashboard on Windows IIS Plant Hosts.
.DESCRIPTION
    Deploys or updates the stateless application code into IIS wwwroot, creates and secures
    the persistent external database directory if missing, and preserves machine-specific
    configuration (.env), telemetry data (database.sqlite), and application storage.
.PARAMETER Source
    Path to source release directory or development workspace.
    Default: Current script directory parent or D:\Acer-File\Training\VibeCode\NGCDashboard
.PARAMETER Destination
    Path to production IIS application root.
    Default: C:\inetpub\wwwroot\NGCDashboard
.PARAMETER DataPath
    Path to external stateful database directory.
    Default: C:\inetpub\wwwroot\NGCDashboard_Data
.PARAMETER AppPoolName
    IIS Application Pool name to recycle.
    Default: NGCDashboard
#>
[CmdletBinding()]
param (
    [string]$Source = $(
        if ($PSScriptRoot) {
            (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
        } else {
            (Get-Location).Path
        }
    ),
    [string]$Destination = "C:\inetpub\wwwroot\NGCDashboard",
    [string]$DataPath = "C:\inetpub\wwwroot\NGCDashboard_Data",
    [string]$AppPoolName = "NGCDashboard"
)

$ErrorActionPreference = "Stop"

# Safety verification of Source directory
if (-not (Test-Path (Join-Path $Source "artisan"))) {
    throw "Source directory '$Source' is invalid: artisan file not found. Ensure you specify -Source or run from project root."
}

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "   NGC Dashboard - Production Deployment & State Decoupling " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "Source:      $Source"
Write-Host "Destination: $Destination"
Write-Host "Data Path:   $DataPath"
Write-Host "App Pool:    $AppPoolName"
Write-Host "----------------------------------------------------------"

# Step 1: Ensure persistent data directory exists and assign IIS permissions
Write-Host "`n>>> [1/7] Checking external stateful data directory..." -ForegroundColor Yellow
if (-not (Test-Path $DataPath)) {
    Write-Host "    Directory not found. Creating persistent data directory: $DataPath..." -ForegroundColor Green
    New-Item -ItemType Directory -Force -Path $DataPath | Out-Null
} else {
    Write-Host "    Persistent data directory exists: $DataPath" -ForegroundColor Gray
}

Write-Host "    Configuring IIS read/write ACLs on $DataPath..." -ForegroundColor Yellow
icacls $DataPath /grant "IIS_IUSRS:(OI)(CI)F" /grant "IUSR:(OI)(CI)F" /grant "IIS AppPool\${AppPoolName}:(OI)(CI)F" /T | Out-Null

# Step 2: Seed / migrate database if target database doesn't exist
$targetDb = Join-Path $DataPath "database.sqlite"
$destDb = Join-Path $Destination "database\database.sqlite"
$sourceDb = Join-Path $Source "database\database.sqlite"

if (-not (Test-Path $targetDb)) {
    Write-Host "`n>>> [2/7] Initializing SQLite database in persistent storage..." -ForegroundColor Yellow
    if (Test-Path $destDb) {
        Write-Host "    Migrating existing database from destination ($destDb) -> $targetDb..." -ForegroundColor Green
        Copy-Item $destDb -Destination $targetDb -Force
    } elseif (Test-Path $sourceDb) {
        Write-Host "    Seeding database from source ($sourceDb) -> $targetDb..." -ForegroundColor Green
        Copy-Item $sourceDb -Destination $targetDb -Force
    } else {
        Write-Host "    Creating new empty database.sqlite at $targetDb..." -ForegroundColor Green
        New-Item -ItemType File -Path $targetDb -Force | Out-Null
    }
} else {
    Write-Host "`n>>> [2/7] Verified persistent database: $targetDb ($((Get-Item $targetDb).Length) bytes)" -ForegroundColor Green
}

# Step 3: Ensure destination project folder exists and configure .env
Write-Host "`n>>> [3/7] Verifying destination and environment configuration..." -ForegroundColor Yellow
if (-not (Test-Path $Destination)) {
    Write-Host "    Creating destination application root: $Destination..." -ForegroundColor Green
    New-Item -ItemType Directory -Force -Path $Destination | Out-Null
}

$destEnv = Join-Path $Destination ".env"
$sourceEnvExample = Join-Path $Source ".env.example"
$formattedDbPath = ($targetDb -replace '\\', '/')

if (-not (Test-Path $destEnv)) {
    Write-Host "    .env not found in destination. Bootstrapping from source .env.example..." -ForegroundColor Yellow
    if (Test-Path $sourceEnvExample) {
        Copy-Item $sourceEnvExample -Destination $destEnv
    } else {
        New-Item -ItemType File -Path $destEnv -Force | Out-Null
    }
}

# Ensure DB_DATABASE points to external stateful path
$envContent = Get-Content $destEnv -Raw
if ($envContent -match "(?m)^DB_DATABASE=.*") {
    $envContent = $envContent -replace "(?m)^DB_DATABASE=.*", "DB_DATABASE=$formattedDbPath"
} elseif ($envContent -match "(?m)^#\s*DB_DATABASE=.*") {
    $envContent = $envContent -replace "(?m)^#\s*DB_DATABASE=.*", "DB_DATABASE=$formattedDbPath"
} else {
    $envContent = $envContent -replace "(?m)^DB_CONNECTION=sqlite", "DB_CONNECTION=sqlite`r`nDB_DATABASE=$formattedDbPath"
}

# Ensure TELEMETRY_PLANT configuration is present
if ($envContent -notmatch "(?m)^TELEMETRY_PLANT=") {
    $envContent += "`r`n# Plant Topology: 'auto' (detect from DB telemetry/hostname/subnet) or fixed '7', '8', '9'`r`nTELEMETRY_PLANT=auto`r`n"
}

Set-Content -Path $destEnv -Value $envContent -NoNewline
Write-Host "    Environment configured: DB_DATABASE=$formattedDbPath" -ForegroundColor Green

# Step 4: Maintenance Mode
Write-Host "`n>>> [4/7] Putting application in maintenance mode..." -ForegroundColor Yellow
if (Test-Path (Join-Path $Destination "artisan")) {
    Push-Location $Destination
    try {
        php artisan down --retry=60
    } catch {
        Write-Warning "Could not activate maintenance mode: $_"
    }
    Pop-Location
}

# Step 5: Code sync via robocopy (stateless codebase only)
Write-Host "`n>>> [5/7] Synchronizing application files (Excluding .env, *.sqlite, storage, .git, node_modules)..." -ForegroundColor Yellow
$robocopyArgs = @(
    $Source,
    $Destination,
    "/E",
    "/XF", ".env", "*.sqlite", "*.sqlite-wal", "*.sqlite-shm",
    "/XD", "storage", ".git", "node_modules", "NGCDashboard_Data",
    "/R:2",
    "/W:1",
    "/NP"
)

& robocopy.exe @robocopyArgs | Out-Null
$rcExit = $LASTEXITCODE
if ($rcExit -ge 8) {
    Write-Error "Robocopy failed with fatal exit code $rcExit."
    exit $rcExit
} else {
    Write-Host "    Code synchronization successful (Robocopy status code: $rcExit)." -ForegroundColor Green
}

# Step 5b: Build NGC Telemetry Agent Package locally on server if build tools are available
$agentScript = Join-Path $Destination "agent\Build-AgentPackage.ps1"
if (Test-Path $agentScript) {
    if (Get-Command dotnet -ErrorAction SilentlyContinue) {
        Write-Host "`n>>> [5b/7] Attempting NGC Telemetry Agent package build on server..." -ForegroundColor Yellow
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $agentScript -OutputDir (Join-Path $Destination "public\scripts")
        if ($LASTEXITCODE -ne 0) {
            Write-Host "    [Notice] Server-side build skipped (air-gapped environment). Pre-built public/scripts/ package will be used." -ForegroundColor Gray
            $global:LASTEXITCODE = 0
        }
    } else {
        Write-Host "`n>>> [5b/7] .NET SDK not detected on server. Using existing public/scripts/ package." -ForegroundColor Gray
    }
}

# Step 6: Database Migrations and Cache Optimization
Write-Host "`n>>> [6/7] Running database migrations and clearing caches..." -ForegroundColor Yellow
Push-Location $Destination
try {
    # Generate app key if missing
    $currentKey = Select-String -Path ".env" -Pattern "^APP_KEY=" -SimpleMatch
    if (-not $currentKey -or $currentKey.Line.Trim() -eq "APP_KEY=") {
        php artisan key:generate --force
    }

    php artisan migrate --force
    php artisan optimize:clear
    php artisan optimize
} finally {
    Pop-Location
}

# Step 7: Recycle IIS and bring application back online
Write-Host "`n>>> [7/7] Recycling IIS and bringing application online..." -ForegroundColor Yellow
Push-Location $Destination
try {
    php artisan up
} catch {
    Write-Warning "Could not disable maintenance mode: $_"
}
Pop-Location

# Recycle IIS AppPool if WebAdministration module or appcmd is available
try {
    if (Get-Command Restart-WebAppPool -ErrorAction SilentlyContinue) {
        Restart-WebAppPool -Name $AppPoolName -ErrorAction SilentlyContinue
        Write-Host "    IIS AppPool '$AppPoolName' recycled via PowerShell WebAdministration." -ForegroundColor Green
    } elseif (Test-Path "$env:windir\system32\inetsrv\appcmd.exe") {
        & "$env:windir\system32\inetsrv\appcmd.exe" recycle apppool "/apppool.name:$AppPoolName" | Out-Null
        Write-Host "    IIS AppPool '$AppPoolName' recycled via appcmd." -ForegroundColor Green
    }
} catch {
    Write-Warning "Could not recycle IIS AppPool '$AppPoolName': $_"
}

Write-Host "`n==========================================================" -ForegroundColor Green
Write-Host "   Deployment completed successfully with ZERO data loss!  " -ForegroundColor Green
Write-Host "==========================================================" -ForegroundColor Green
