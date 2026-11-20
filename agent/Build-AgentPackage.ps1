<#
.SYNOPSIS
    One-Click Build & Distribution Packager for NGC Telemetry Agent
.DESCRIPTION
    Builds a 100% self-contained single-file Windows x64 binary (no .NET runtime needed on target edge PCs).
    Runs 100% OFFLINE without any internet connection (all framework references are built-in, zero NuGet packages).
    Places the resulting binary and compressed zip package into 'public/scripts/' on the Plant Server or dev PC.
#>

[CmdletBinding()]
param(
    [string]$Configuration = "Release",
    [string]$Runtime = "win-x64",
    [string]$OutputDir = ""
)

# 0. Ensure ExecutionPolicy is bypassed for this process
try {
    if ((Get-ExecutionPolicy -Scope Process) -ne "Bypass") {
        Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass -Force -ErrorAction SilentlyContinue
    }
} catch { }

if ([string]::IsNullOrWhiteSpace($OutputDir)) {
    $OutputDir = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot "..\public\scripts"))
}

$ErrorActionPreference = "Stop"

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "   NGC Telemetry Agent - Offline Distribution Builder     " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

# 1. Check for dotnet SDK
if (-not (Get-Command dotnet -ErrorAction SilentlyContinue)) {
    Write-Error "The .NET SDK ('dotnet') is not found on this machine. Please install .NET 9 SDK (offline installer available at https://dotnet.microsoft.com/download/dotnet/9.0)."
    Exit 1
}

# 2. Terminate any running local agent before compiling to prevent file locking
Get-Process -Name "NGCTelemetryAgent" -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue

$dotnetVersion = & dotnet --version
Write-Host "Found .NET SDK: $dotnetVersion" -ForegroundColor Green

# 2. Resolve Paths
$projectPath = "$PSScriptRoot\NGCTelemetryAgent\NGCTelemetryAgent.csproj"
if (-not (Test-Path $projectPath)) {
    Write-Error "Project file not found at: $projectPath"
    Exit 1
}

$stagingDir = Join-Path $OutputDir "agent_build"
$zipDestination = Join-Path $OutputDir "NGCTelemetryAgent.zip"
$standaloneExeDestination = Join-Path $OutputDir "NGCTelemetryAgent.exe"

# Clean previous staging
if (Test-Path $stagingDir) {
    Remove-Item -Path $stagingDir -Recurse -Force
}
New-Item -ItemType Directory -Path $stagingDir -Force | Out-Null

Write-Host "Building self-contained single-file binary for $Runtime ($Configuration)..." -ForegroundColor Yellow

$tempBin = Join-Path $stagingDir "bld_bin"
$tempObj = Join-Path $stagingDir "bld_obj"

# 3. Compile & Publish Self-Contained Executable (Isolate build output to avoid file locks)
& dotnet publish $projectPath `
    -c $Configuration `
    -r $Runtime `
    --self-contained true `
    -p:PublishSingleFile=true `
    -p:IncludeNativeLibrariesForSelfExtract=true `
    -p:EnableCompressionInSingleFile=true `
    -p:BaseOutputPath="$tempBin\" `
    -p:IntermediateOutputPath="$tempObj\" `
    -o $stagingDir

# Clean intermediate compile artifacts from staging before packaging
Remove-Item -Path $tempBin, $tempObj -Recurse -Force -ErrorAction SilentlyContinue

if ($LASTEXITCODE -ne 0) {
    Write-Error "dotnet publish failed with exit code $LASTEXITCODE."
    Exit $LASTEXITCODE
}

# 4. Verify Built Binary
$builtExe = Join-Path $stagingDir "NGCTelemetryAgent.exe"
if (-not (Test-Path $builtExe)) {
    Write-Error "Published executable not found at: $builtExe"
    Exit 1
}

$fileSizeMB = [math]::Round(((Get-Item $builtExe).Length / 1MB), 2)
Write-Host "Successfully compiled standalone binary ($fileSizeMB MB): $builtExe" -ForegroundColor Green

# 5. Copy Standalone Binary to public/scripts/
Copy-Item -Path $builtExe -Destination $standaloneExeDestination -Force
Write-Host "Copied binary to: $standaloneExeDestination" -ForegroundColor Cyan

# 6. Compress into Distribution Zip
if (Test-Path $zipDestination) {
    Remove-Item -Path $zipDestination -Force -ErrorAction SilentlyContinue
}

Write-Host "Compressing distribution zip package..." -ForegroundColor Yellow
$compressSuccess = $false
for ($attempt = 1; $attempt -le 5; $attempt++) {
    try {
        Start-Sleep -Milliseconds (600 * $attempt)
        Compress-Archive -Path "$stagingDir\*" -DestinationPath $zipDestination -CompressionLevel Optimal
        $compressSuccess = $true
        break
    } catch {
        Write-Warning "Compress-Archive attempt $attempt locked. Retrying in 1s..."
        Start-Sleep -Seconds 1
    }
}
if (-not $compressSuccess) {
    Write-Warning "Zip packaging skipped due to file lock. Standalone binary is available at $standaloneExeDestination."
}

$zipSizeMB = [math]::Round(((Get-Item $zipDestination).Length / 1MB), 2)
Write-Host "Successfully generated distribution package ($zipSizeMB MB): $zipDestination" -ForegroundColor Green

# 7. Clean Staging
Remove-Item -Path $stagingDir -Recurse -Force -ErrorAction SilentlyContinue

Write-Host "`n==========================================================" -ForegroundColor Cyan
Write-Host "   Offline Distribution Build Complete!                   " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "Artifacts ready in 'public/scripts/':" -ForegroundColor Green
Write-Host "  1. $zipDestination" -ForegroundColor White
Write-Host "  2. $standaloneExeDestination" -ForegroundColor White
Write-Host "`nBuild complete. Artifacts are ready in 'public/scripts/'." -ForegroundColor Yellow
Write-Host "This build runs 100% offline without needing internet access." -ForegroundColor Yellow
