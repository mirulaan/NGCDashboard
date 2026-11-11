<#
.SYNOPSIS
    One-Click Build & Distribution Packager for NGC Telemetry Agent
.DESCRIPTION
    Executes on a development PC with .NET 8 SDK and internet connection.
    Builds a 100% self-contained single-file Windows x64 binary (no .NET runtime needed on target edge PCs).
    Places the resulting binary and compressed zip package into 'public/scripts/' so the offline Plant Server
    only needs to serve static files over IIS.
#>

[CmdletBinding()]
param(
    [string]$Configuration = "Release",
    [string]$Runtime = "win-x64",
    [string]$OutputDir = ""
)

if ([string]::IsNullOrWhiteSpace($OutputDir)) {
    $OutputDir = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot "..\public\scripts"))
}

$ErrorActionPreference = "Stop"

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "   NGC Telemetry Agent - Offline Distribution Builder     " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

# 1. Check for dotnet SDK
if (-not (Get-Command dotnet -ErrorAction SilentlyContinue)) {
    Write-Error "The .NET SDK ('dotnet') is not found on this machine. Please install .NET 8 SDK on your development PC from https://dotnet.microsoft.com/download/dotnet/8.0"
    Exit 1
}

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

# 3. Compile & Publish Self-Contained Executable
& dotnet publish $projectPath `
    -c $Configuration `
    -r $Runtime `
    --self-contained true `
    -p:PublishSingleFile=true `
    -p:IncludeNativeLibrariesForSelfExtract=true `
    -p:EnableCompressionInSingleFile=true `
    -o $stagingDir

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
    Remove-Item -Path $zipDestination -Force
}

Write-Host "Compressing distribution zip package..." -ForegroundColor Yellow
Compress-Archive -Path "$stagingDir\*" -DestinationPath $zipDestination -CompressionLevel Optimal

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
Write-Host "`nYou can now commit or copy these files directly to the offline Plant Server." -ForegroundColor Yellow
Write-Host "The offline IIS server does NOT need to run any compiler or build step." -ForegroundColor Yellow
