# 1. AUTO-ELEVATE TO ADMINISTRATOR
if (-Not ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Start-Process powershell -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`"" -Verb RunAs
    Exit
}

# 2. DETERMINE TARGET IP ADDRESS
$ipAddress = ""
if (Test-Path "D:\Acer-File") {
    $ipAddress = "192.168.100.243"
} else {
    # Find any local IP starting with 172.16.
    $localIp = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object IPAddress -match "^172\.16\.").IPAddress | Select-Object -First 1
    if ($localIp) {
        # Replace whatever the last number is with .2 (e.g., 172.16.50.150 -> 172.16.50.2)
        $ipAddress = $localIp -replace '\.\d+$', '.2'
    } else {
        Write-Host "ERROR: Could not determine target IP address. No 172.16.x.x network found." -ForegroundColor Red
        Pause
        Exit
    }
}
$apiUrl = "http://$ipAddress/api/system-status"
Write-Host "Target API URL: $apiUrl" -ForegroundColor Cyan

# 3. EXTRACT XML DATA (PC_Info.xml)
$plant = ""; $line = ""; $tier1 = ""; $tier2 = ""
$xmlPath = "C:\Hardware\PC_Info.xml"
if (Test-Path $xmlPath) {
    # Strip the XML namespace to make parsing much easier
    $xmlContent = (Get-Content $xmlPath) -replace 'xmlns="[^"]+"', ''
    [xml]$xml = $xmlContent
    
    $strings = $xml.LVData.Cluster.String
    
    # ADDED [string] CASTING HERE:
    $plant = [string]($strings | Where-Object Name -eq 'P_str').Val
    $line  = [string]($strings | Where-Object Name -eq 'L_str').Val
    $tier1 = [string]($strings | Where-Object Name -eq 'T1_str').Val
    $tier2 = [string]($strings | Where-Object Name -eq 'T2_str').Val
}
Write-Host "Done EXTRACT XML DATA (PC_Info.xml)" -ForegroundColor Cyan

# 4. EXTRACT CONFIG MODEL
$configModel = [ordered]@{}
$jsonAppSettingsPath = "C:\Accusys\HAIVIs\appsettings.json"
$txtModelPath        = "C:\Accusys\vmaimodel.txt"

$jsonSuccess = $false

# Helper function to replace empty values with "not set"
function Format-EmptyModel ($val) {
    $strVal = [string]$val
    if ([string]::IsNullOrWhiteSpace($strVal)) {
        return "not set"
    }
    return $strVal
}

if (Test-Path $jsonAppSettingsPath) {
    try {
        # Read and parse JSON content
        $jsonObj = Get-Content -Path $jsonAppSettingsPath -Raw | ConvertFrom-Json
        
        if ($jsonObj -and $jsonObj.skuType) {
            # Extract SkuMode
            $skuMode         = Format-EmptyModel $jsonObj.skuMode

            # Extract standard color models and apply empty check
            $postModel       = Format-EmptyModel $jsonObj.skuType.color.POST_TOP_1.objectDetectionDL.dlModel
            $noGloveModel    = Format-EmptyModel $jsonObj.skuType.color.POST_TOP_1.resizeRotatedBlob.noGloveDlModel
            $preModel        = Format-EmptyModel $jsonObj.skuType.color.PRE_TOP.objectDetectionDL.dlModel
            $gssDefectModel  = Format-EmptyModel $jsonObj.skuType.color.GSS_TOP.defectDL.dlModel
            $gssMachineModel = Format-EmptyModel $jsonObj.skuType.color.GSS_TOP.machineModel.dlModel

            # Extract black SKU models and apply empty check
            $postBlack       = Format-EmptyModel $jsonObj.skuType.black.POST_TOP_1.objectDetectionDL.dlModel
            $noGloveBlack    = Format-EmptyModel $jsonObj.skuType.black.POST_TOP_1.resizeRotatedBlob.noGloveDlModel
            $preBlack        = Format-EmptyModel $jsonObj.skuType.black.PRE_TOP.objectDetectionDL.dlModel
            $gssBlack        = Format-EmptyModel $jsonObj.skuType.black.GSS_TOP.defectDL.dlModel

            $configModel = [ordered]@{
                "SkuMode"       = $skuMode
                "Post"          = $postModel
                "NoGlove"       = $noGloveModel
                "Pre"           = $preModel
                "GSS Defect"    = $gssDefectModel
                "Post Black"    = $postBlack
                "NoGlove Black" = $noGloveBlack
                "Pre Black"     = $preBlack
                "GSS Black"     = $gssBlack
                "GSS Machine"   = $gssMachineModel
            }
            $jsonSuccess = $true
        }
    } catch {
        Write-Host "WARNING: Failed to parse appsettings.json. Falling back to vmaimodel.txt..." -ForegroundColor Yellow
    }
}

# Fallback to vmaimodel.txt if JSON doesn't exist or failed to load
if (-not $jsonSuccess -and (Test-Path $txtModelPath)) {
    $models = Get-Content $txtModelPath | ForEach-Object { [string]$_ }
    
    $configModel = [ordered]@{
        "SkuMode"       = Format-EmptyModel $models[9]
        "Post"          = Format-EmptyModel $models[1]
        "NoGlove"       = Format-EmptyModel $models[2]
        "Pre"           = Format-EmptyModel $models[3]
        "GSS Defect"    = Format-EmptyModel $models[4]
        "Post Black"    = Format-EmptyModel $models[5]
        "NoGlove Black" = Format-EmptyModel $models[6]
        "Pre Black"     = Format-EmptyModel $models[7]
        "GSS Black"     = Format-EmptyModel $models[8]
        "GSS Machine"   = Format-EmptyModel $models[10] # Assuming line 11 in text file
    }
}

Write-Host "Done EXTRACT CONFIG MODEL" -ForegroundColor Cyan

# 5. EXTRACT VERSIONS AND MOD DATES
$mwPath = "C:\Accusys\Accusys_MiddleWare\Accusys_MiddleWare.exe"
$mwVersion = if (Test-Path $mwPath) { (Get-Item $mwPath).VersionInfo.FileVersion } else { "-" }

$dimrPath = "C:\Accusys\DIMR_Engine\DIMR_Engine.exe"
$dimrDate = if (Test-Path $dimrPath) { (Get-Item $dimrPath).LastWriteTime.ToString("dd/MM/yyyy") } else { "-" }

$iocPath = "C:\Hardware\IOC.bas"
$iocDate = if (Test-Path $iocPath) { (Get-Item $iocPath).LastWriteTime.ToString("dd/MM/yyyy") } else { "-" }

# --- NEW: EXTRACT .SOLW FILE INFO ---
$solwPath = "C:\Accusys\Accusys_MiddleWare"
# The @() ensures it is always treated as an array, even if only 1 file is found
$solwFiles = @(Get-ChildItem -Path $solwPath -Filter "*.solw" -ErrorAction SilentlyContinue)

if ($solwFiles.Count -eq 1) {
    $solwStatus = $solwFiles[0].Name
} elseif ($solwFiles.Count -gt 1) {
    # Uses the first found file, and calculates how many extra files exist
    $extraCount = $solwFiles.Count - 1
    $solwStatus = "$($solwFiles[0].Name) > $extraCount"
} else {
    $solwStatus = "Not Found"
}

$versionObj = [ordered]@{
    "solw"       = $solwStatus
    "MiddleWare" = $mwVersion
    "DIMR"       = $dimrDate
    "IOC.bas"    = $iocDate
}
Write-Host "Done EXTRACT VERSIONS AND MOD DATES" -ForegroundColor Cyan

# 6. EXTRACT SSD STATUS (Your script)
$allVolumesData = @()
$nvmeDisks = Get-Disk | Where-Object BusType -eq 'NVMe'
$smartctlpth = "C:\Program Files\smartmontools\bin\smartctl.exe"

foreach ($disk in $nvmeDisks) {
    $physicalDrive = "/dev/pd$($disk.Number)"
    $smartData = & $smartctlpth -a -j $physicalDrive | ConvertFrom-Json
    
    $temperature = $smartData.temperature.current
    $percentageUsed = $smartData.nvme_smart_health_information_log.percentage_used

    $partitions = Get-Partition -DiskNumber $disk.Number | Where-Object DriveLetter
    foreach ($part in $partitions) {
        $vol = Get-Volume -Partition $part
        if ($vol) {
            # CRITICAL FIX: Cast every hardware property to primitive types to strip WMI metadata
            $volumeObject = [ordered]@{
                DiskModel          = [string]$disk.FriendlyName
                PhysicalDiskNumber = [int]$disk.Number
                DriveLetter        = [string]"$($vol.DriveLetter):"
                TotalSpaceGB       = [math]::Round($vol.Size / 1GB, 2)
                RemainingSpaceGB   = [math]::Round($vol.SizeRemaining / 1GB, 2)
                TemperatureCelsius = [int]$temperature
                PercentageUsed     = [int]$percentageUsed
            }
            $allVolumesData += New-Object PSObject -Property $volumeObject
        }
    }
}
$ssdStatus = $allVolumesData | Sort-Object DriveLetter
Write-Host "Done EXTRACT SSD STATUS" -ForegroundColor Cyan

# 7. ASSEMBLE FINAL JSON PAYLOAD
$payload = [ordered]@{
    "Plant"        = $plant
    "Line"         = $line
    "Tier 1"       = $tier1
    "Tier 2"       = $tier2
    "Version"      = $versionObj
    "Config Model" = $configModel
    "SSD_Status"   = $ssdStatus
}
Write-Host "Done ASSEMBLE FINAL JSON PAYLOAD" -ForegroundColor Cyan

# Convert to JSON (Depth 10 ensures nested arrays don't get truncated)
$jsonPayload = $payload | ConvertTo-Json -Depth 10

Write-Host "Payload Generated Successfully:" -ForegroundColor Green
Write-Host $jsonPayload

# 8. PUSH TO SERVER (Native PowerShell, no curl.exe needed)
try {
    $response = Invoke-RestMethod -Uri $apiUrl -Method Post -Body $jsonPayload -ContentType "application/json"
    Write-Host "Server Response: " -ForegroundColor Green
    Write-Host ($response | ConvertTo-Json)
} catch {
    Write-Host "Failed to send data to server. Error:" -ForegroundColor Red
    Write-Host $_.Exception.Message
}

Start-Sleep -Seconds 5