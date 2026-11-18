# 1. AUTO-ELEVATE TO ADMINISTRATOR
if (-Not ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Start-Process powershell -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`"" -Verb RunAs
    Exit
}

# 2. DETERMINE TARGET IP ADDRESS & LOCAL PC IP (172.16.x.x)
$pcIp = ""
$ipAddress = ""
if (Test-Path "D:\Acer-File") {
    $ipAddress = "192.168.100.243"
    $pcIp = "192.168.100.243"
} else {
    # Specifically find local IP starting with 172.16. (ignoring camera/isolated NICs and broadcast)
    $localIpObj = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | 
        Where-Object { $_.IPAddress -match "^172\.16\." -and $_.IPAddress -notmatch "\.255$" } | 
        Select-Object -First 1

    if ($localIpObj) {
        $pcIp = $localIpObj.IPAddress
        # Replace whatever the last number is with .2 (e.g., 172.16.50.150 -> 172.16.50.2)
        $ipAddress = $pcIp -replace '\.\d+$', '.2'
    } else {
        # Fallback to WMI if Get-NetIPAddress is unavailable
        $wmiIps = Get-CimInstance Win32_NetworkAdapterConfiguration -ErrorAction SilentlyContinue |
            Where-Object { $_.IPEnabled -and $_.IPAddress } |
            ForEach-Object { $_.IPAddress } |
            Where-Object { $_ -match "^172\.16\." -and $_ -notmatch "\.255$" }

        if ($wmiIps) {
            $pcIp = [string]($wmiIps | Select-Object -First 1)
            $ipAddress = $pcIp -replace '\.\d+$', '.2'
        } else {
            Write-Host "ERROR: Could not determine target IP address. No 172.16.x.x network found." -ForegroundColor Red
            Pause
            Exit
        }
    }
}
$apiUrl = "http://$ipAddress/api/system-status"
Write-Host "Detected Edge PC IP: $pcIp" -ForegroundColor Green
Write-Host "Target API URL:     $apiUrl" -ForegroundColor Cyan


# ====================================================================
# 3. ENSURE HWINFO64 IS INSTALLED, CONFIGURED & RUNNING (RUN ONCE)
# ====================================================================
$monitorDir = "C:\Hardware\monitor"
$markerFile = "$monitorDir\hwinfo_v2_setup.done"

if (-not (Test-Path $markerFile)) {
    Write-Host "New HWiNFO64 configuration required. Initializing automated setup..." -ForegroundColor Yellow
    
    # Force close existing processes
    Write-Host "Force closing HWiNFO64..." -ForegroundColor Cyan
    Stop-Process -Name "HWiNFO64" -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2

    # Create directory if missing
    if (-not (Test-Path $monitorDir)) {
        New-Item -ItemType Directory -Force -Path $monitorDir | Out-Null
    }

    # Delete old files
    Write-Host "Deleting old files..." -ForegroundColor Cyan
    Remove-Item -Path "$monitorDir\HWiNFO64.exe" -Force -ErrorAction SilentlyContinue
    Remove-Item -Path "$monitorDir\HWiNFO64.ini" -Force -ErrorAction SilentlyContinue
    Remove-Item -Path "$monitorDir\HWiNFO64_settings.reg" -Force -ErrorAction SilentlyContinue

    try {
        $scriptsUrl = "http://$ipAddress/scripts"
        $zipFile = "$monitorDir\HWiNFO64.zip"

        # Download & Extract
        Write-Host "Downloading updated HWiNFO64.zip from server..." -ForegroundColor Cyan
        Invoke-WebRequest -Uri "$scriptsUrl/HWiNFO64.zip" -OutFile $zipFile -UseBasicParsing
        
        Write-Host "Extracting new files..." -ForegroundColor Cyan
        Expand-Archive -Path $zipFile -DestinationPath $monitorDir -Force
        Remove-Item -Path $zipFile -Force

        # Apply Reg
        Write-Host "Applying NEW HWiNFO Registry Settings..." -ForegroundColor Cyan
        Start-Process "reg.exe" -ArgumentList "import `"$monitorDir\HWiNFO64_settings.reg`"" -Wait -WindowStyle Hidden

        # Start HWiNFO64
        Write-Host "Starting HWiNFO64..." -ForegroundColor Cyan
        Start-Process "$monitorDir\HWiNFO64.exe" -WindowStyle Hidden
        
        # Wait for sensors to populate registry
        Start-Sleep -Seconds 5
        
        # Create marker file to prevent looping every 10 minutes
        New-Item -Path $markerFile -ItemType File -Force | Out-Null
        Write-Host "HWiNFO64 updated and started successfully." -ForegroundColor Green

    } catch {
        Write-Host "ERROR: Failed to download, extract, or start HWiNFO64." -ForegroundColor Red
        Write-Host $_.Exception.Message -ForegroundColor Red
    }
} else {
    # Marker exists, meaning it is successfully installed. Just ensure it is running.
    $hwinfoProcess = Get-Process "HWiNFO64" -ErrorAction SilentlyContinue
    if (-not $hwinfoProcess) {
        Write-Host "HWiNFO64 is configured but not running. Starting it now..." -ForegroundColor Yellow
        if (Test-Path "$monitorDir\HWiNFO64.exe") {
            Start-Process "$monitorDir\HWiNFO64.exe" -WindowStyle Hidden
            Start-Sleep -Seconds 5
        }
    } else {
        Write-Host "HWiNFO64 is actively running." -ForegroundColor Green
    }
}


# 4. EXTRACT XML DATA (PC_Info.xml)
$plant = ""; $line = ""; $tier1 = ""; $tier2 = ""
$xmlPath = "C:\Hardware\PC_Info.xml"
if (Test-Path $xmlPath) {
    # Strip the XML namespace to make parsing much easier
    $xmlContent = (Get-Content $xmlPath) -replace 'xmlns="[^"]+"', ''
    [xml]$xml = $xmlContent
    
    $strings = $xml.LVData.Cluster.String
    
    $plant = [string]($strings | Where-Object Name -eq 'P_str').Val
    $line  = [string]($strings | Where-Object Name -eq 'L_str').Val
    $tier1 = [string]($strings | Where-Object Name -eq 'T1_str').Val
    $tier2 = [string]($strings | Where-Object Name -eq 'T2_str').Val
}
Write-Host "Done EXTRACT XML DATA (PC_Info.xml)" -ForegroundColor Cyan


# 5. EXTRACT CONFIG MODEL
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
        $jsonObj = Get-Content -Path $jsonAppSettingsPath -Raw | ConvertFrom-Json
        
        if ($jsonObj -and $jsonObj.skuType) {
            $configModel = [ordered]@{
                "SkuMode"       = Format-EmptyModel $jsonObj.skuType.skuMode
                "Post"          = Format-EmptyModel $jsonObj.skuType.color.POST_TOP_1.objectDetectionDL.dlModel
                "NoGlove"       = Format-EmptyModel $jsonObj.skuType.color.POST_TOP_1.resizeRotatedBlob.noGloveDlModel
                "Pre"           = Format-EmptyModel $jsonObj.skuType.color.PRE_TOP.objectDetectionDL.dlModel
                "GSS Defect"    = Format-EmptyModel $jsonObj.skuType.color.GSS_TOP.defectDL.dlModel
                "Post Black"    = Format-EmptyModel $jsonObj.skuType.black.POST_TOP_1.objectDetectionDL.dlModel
                "NoGlove Black" = Format-EmptyModel $jsonObj.skuType.black.POST_TOP_1.resizeRotatedBlob.noGloveDlModel
                "Pre Black"     = Format-EmptyModel $jsonObj.skuType.black.PRE_TOP.objectDetectionDL.dlModel
                "GSS Black"     = Format-EmptyModel $jsonObj.skuType.black.GSS_TOP.defectDL.dlModel
                "GSS Machine"   = Format-EmptyModel $jsonObj.skuType.color.GSS_TOP.machineModel.dlModel
            }
            $jsonSuccess = $true
        }
    } catch {
        Write-Host "WARNING: Failed to parse appsettings.json. Falling back to vmaimodel.txt..." -ForegroundColor Yellow
    }
}

if (-not $jsonSuccess -and (Test-Path $txtModelPath)) {
    $models = Get-Content $txtModelPath | ForEach-Object { [string]$_ }
    
    $configModel = [ordered]@{
        "SkuMode"       = "0" # Fallback if using txt models
        "Post"          = Format-EmptyModel $models[1]
        "NoGlove"       = Format-EmptyModel $models[2]
        "Pre"           = Format-EmptyModel $models[3]
        "GSS Defect"    = Format-EmptyModel $models[4]
        "Post Black"    = Format-EmptyModel $models[5]
        "NoGlove Black" = Format-EmptyModel $models[6]
        "Pre Black"     = Format-EmptyModel $models[7]
        "GSS Black"     = Format-EmptyModel $models[8]
        "GSS Machine"   = Format-EmptyModel $models[10]
    }
}
Write-Host "Done EXTRACT CONFIG MODEL" -ForegroundColor Cyan


# 6. EXTRACT VERSIONS AND MOD DATES
$mwPath = "C:\Accusys\Accusys_MiddleWare\Accusys_MiddleWare.exe"
$mwVersion = if (Test-Path $mwPath) { (Get-Item $mwPath).VersionInfo.FileVersion } else { "-" }

$dimrPath = "C:\Accusys\DIMR_Engine\DIMR_Engine.exe"
$dimrDate = if (Test-Path $dimrPath) { (Get-Item $dimrPath).LastWriteTime.ToString("dd/MM/yyyy") } else { "-" }

$iocPath = "C:\Hardware\IOC.bas"
$iocDate = if (Test-Path $iocPath) { (Get-Item $iocPath).LastWriteTime.ToString("dd/MM/yyyy") } else { "-" }

$solwPath = "C:\Accusys\Accusys_MiddleWare"
$solwFiles = @(Get-ChildItem -Path $solwPath -Filter "*.solw" -ErrorAction SilentlyContinue)

if ($solwFiles.Count -eq 1) {
    $solwStatus = $solwFiles[0].Name
} elseif ($solwFiles.Count -gt 1) {
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


# 7. EXTRACT SSD STATUS (Full NVMe SMART Telemetry via smartctl -a)
$allVolumesData = @()
$nvmeDisks = Get-Disk | Where-Object BusType -eq 'NVMe'
if (-not $nvmeDisks) {
    # Fallback to any disk with volumes if NVMe bus type is not explicitly matched
    $nvmeDisks = Get-Disk | Where-Object Number -ne $null
}

$smartctlpth = "C:\Program Files\smartmontools\bin\smartctl.exe"
if (-not (Test-Path $smartctlpth)) {
    $smartctlpth = "C:\Program Files (x86)\smartmontools\bin\smartctl.exe"
}
if (-not (Test-Path $smartctlpth)) {
    $smartCmd = Get-Command smartctl -ErrorAction SilentlyContinue
    if ($smartCmd) { $smartctlpth = $smartCmd.Source }
}

foreach ($disk in $nvmeDisks) {
    $temperature = 0
    $percentageUsed = 0
    $criticalWarning = $null
    $mediaErrors = $null
    $errorLogEntries = $null
    $unsafeShutdowns = $null
    $warningTempTime = $null
    $criticalTempTime = $null
    $availableSpare = $null

    if ($smartctlpth -and (Test-Path $smartctlpth)) {
        try {
            $physicalDrive = "/dev/pd$($disk.Number)"
            $smartOutput = & $smartctlpth -a -j $physicalDrive 2>$null
            $parsedJson = $false

            if ($smartOutput) {
                try {
                    $smartData = ($smartOutput -join "`n") | ConvertFrom-Json
                    if ($smartData) {
                        $parsedJson = $true
                        if ($null -ne $smartData.temperature -and $null -ne $smartData.temperature.current) {
                            $temperature = [int]$smartData.temperature.current
                        }

                        $nvmeLog = $smartData.nvme_smart_health_information_log
                        if ($null -ne $nvmeLog) {
                            if ($temperature -eq 0 -and $null -ne $nvmeLog.temperature) {
                                $temperature = [int]$nvmeLog.temperature
                            }
                            if ($null -ne $nvmeLog.percentage_used) {
                                $percentageUsed = [int]$nvmeLog.percentage_used
                            }
                            if ($null -ne $nvmeLog.critical_warning) {
                                $criticalWarning = [int]$nvmeLog.critical_warning
                            }
                            if ($null -ne $nvmeLog.media_errors) {
                                $mediaErrors = [int64]$nvmeLog.media_errors
                            }
                            if ($null -ne $nvmeLog.num_err_log_entries) {
                                $errorLogEntries = [int64]$nvmeLog.num_err_log_entries
                            }
                            if ($null -ne $nvmeLog.unsafe_shutdowns) {
                                $unsafeShutdowns = [int64]$nvmeLog.unsafe_shutdowns
                            }
                            if ($null -ne $nvmeLog.warning_temp_time) {
                                $warningTempTime = [int64]$nvmeLog.warning_temp_time
                            }
                            if ($null -ne $nvmeLog.critical_temp_time) {
                                $criticalTempTime = [int64]$nvmeLog.critical_temp_time
                            }
                            if ($null -ne $nvmeLog.available_spare) {
                                $availableSpare = [int]$nvmeLog.available_spare
                            }
                        }
                    }
                } catch {
                    $parsedJson = $false
                }
            }

            # Text fallback for older smartmontools versions without -j JSON output
            if (-not $parsedJson) {
                $textOut = (& $smartctlpth -a $physicalDrive 2>$null) -join "`n"
                if ($textOut) {
                    if ($textOut -match 'Critical Warning:\s*0x([0-9a-fA-F]+)') { $criticalWarning = [Convert]::ToInt32($Matches[1], 16) }
                    if ($textOut -match 'Temperature:\s*(\d+)\s*Celsius') { $temperature = [int]$Matches[1] }
                    if ($textOut -match 'Available Spare:\s*(\d+)%') { $availableSpare = [int]$Matches[1] }
                    if ($textOut -match 'Percentage Used:\s*(\d+)%') { $percentageUsed = [int]$Matches[1] }
                    if ($textOut -match 'Unsafe Shutdowns:\s*([\d,]+)') { $unsafeShutdowns = [int64]($Matches[1] -replace ',', '') }
                    if ($textOut -match 'Media and Data Integrity Errors:\s*([\d,]+)') { $mediaErrors = [int64]($Matches[1] -replace ',', '') }
                    if ($textOut -match 'Error Information Log Entries:\s*([\d,]+)') { $errorLogEntries = [int64]($Matches[1] -replace ',', '') }
                    if ($textOut -match 'Warning\s+Comp\.\s+Temperature\s+Time:\s*([\d,]+)') { $warningTempTime = [int64]($Matches[1] -replace ',', '') }
                    if ($textOut -match 'Critical\s+Comp\.\s+Temperature\s+Time:\s*([\d,]+)') { $criticalTempTime = [int64]($Matches[1] -replace ',', '') }
                }
            }
        } catch {
            Write-Host "Warning: smartctl failed to read disk $($disk.Number): $_" -ForegroundColor Yellow
        }
    }

    $partitions = Get-Partition -DiskNumber $disk.Number -ErrorAction SilentlyContinue | Where-Object DriveLetter
    foreach ($part in $partitions) {
        $vol = Get-Volume -Partition $part -ErrorAction SilentlyContinue
        if ($vol -and $vol.Size -gt 0) {
            $volumeObject = [ordered]@{
                DiskModel          = [string]$disk.FriendlyName
                PhysicalDiskNumber = [int]$disk.Number
                DriveLetter        = [string]"$($vol.DriveLetter):"
                TotalSpaceGB       = [math]::Round($vol.Size / 1GB, 2)
                RemainingSpaceGB   = [math]::Round($vol.SizeRemaining / 1GB, 2)
                TemperatureCelsius = [int]$temperature
                PercentageUsed     = [int]$percentageUsed
                CriticalWarning    = $criticalWarning
                MediaErrors        = $mediaErrors
                ErrorLogEntries    = $errorLogEntries
                UnsafeShutdowns    = $unsafeShutdowns
                WarningTempTime    = $warningTempTime
                CriticalTempTime   = $criticalTempTime
                AvailableSpare     = $availableSpare
            }
            $allVolumesData += New-Object PSObject -Property $volumeObject
        }
    }
}
$ssdStatus = @($allVolumesData | Sort-Object DriveLetter)
Write-Host "Done EXTRACT SSD STATUS" -ForegroundColor Cyan


# 8. EXTRACT PC THERMAL & GPU STATUS
$cpuTemp = "N/A"; $cpuThrottle = "N/A"
$gpuTemp = "N/A"; $gpuPower = "N/A"; $gpuClock = "N/A"

# 1. EXTRACT CPU VIA HWINFO REGISTRY
$regPath = "HKCU:\Software\HWiNFO64\VSB"
$vsbKey = Get-ItemProperty -Path $regPath -ErrorAction SilentlyContinue

if ($null -ne $vsbKey) {
    for ($i = 0; $i -lt 150; $i++) {
        $labelProperty = "Label$i"
        $valueProperty = "Value$i"

        if ($vsbKey.PSObject.Properties.Match($labelProperty).Count -gt 0) {
            $currentLabel = $vsbKey.$labelProperty
            $currentValue = $vsbKey.$valueProperty

            if ($currentLabel -eq "CPU Package") { $cpuTemp = $currentValue -replace '[^\d\.]','' }
            if ($currentLabel -eq "Core Thermal Throttling") { $cpuThrottle = $currentValue }
        }
    }
}

# 2. EXTRACT GPU VIA NATIVE NVIDIA-SMI (Bypasses HWiNFO entirely)
$nvidiaSmi = "C:\Windows\System32\nvidia-smi.exe"
if (-not (Test-Path $nvidiaSmi)) {
    $nvidiaSmi = "C:\Program Files\NVIDIA Corporation\NVSMI\nvidia-smi.exe"
}
if (-not (Test-Path $nvidiaSmi)) {
    $cmd = Get-Command nvidia-smi -ErrorAction SilentlyContinue
    if ($cmd) { $nvidiaSmi = $cmd.Source }
}

if (Test-Path $nvidiaSmi) {
    try {
        # Queries exactly Temp, Power(W), and Clock(MHz) instantly
        $gpuData = & $nvidiaSmi --query-gpu=temperature.gpu,power.draw,clocks.gr --format=csv,noheader,nounits
        if ($gpuData) {
            $gpuLine = ($gpuData | Select-Object -First 1)
            $gpuParts = $gpuLine -split ','
            if ($gpuParts.Count -ge 3) {
                $gpuTemp = $gpuParts[0].Trim()
                $gpuPower = $gpuParts[1].Trim()
                $gpuClock = $gpuParts[2].Trim()
            }
        }
    } catch {
        Write-Host "NVIDIA-SMI failed to read GPU data: $_" -ForegroundColor Yellow
    }
}

$pcThermal = @{
    "CPU Temp" = $cpuTemp
    "CPU Thermal Throttle" = $cpuThrottle
    "GPU Temp" = $gpuTemp
    "GPU Power" = $gpuPower
    "GPU Clock" = $gpuClock
}
Write-Host "Done EXTRACT CPU & GPU THERMAL STATUS" -ForegroundColor Cyan


# 9. ASSEMBLE FINAL JSON PAYLOAD
$payload = [ordered]@{
    "Plant"        = $plant
    "Line"         = $line
    "Tier 1"       = $tier1
    "Tier 2"       = $tier2
    "IpAddress"    = $pcIp
    "Version"      = $versionObj
    "Config Model" = $configModel
    "SSD_Status"   = $ssdStatus
    "PC Thermal"   = $pcThermal
}
Write-Host "Done ASSEMBLE FINAL JSON PAYLOAD" -ForegroundColor Cyan


# Convert to JSON (Depth 10 ensures nested arrays/objects don't get truncated)
$jsonPayload = $payload | ConvertTo-Json -Depth 10

Write-Host "Payload Generated Successfully:" -ForegroundColor Green
Write-Host $jsonPayload


# 10. PUSH TO SERVER
if ([string]::IsNullOrWhiteSpace($plant) -or [string]::IsNullOrWhiteSpace($line) -or [string]::IsNullOrWhiteSpace($tier1)) {
    Write-Host "WARNING: Required identity fields (Plant, Line, Tier 1) are missing or empty from PC_Info.xml. Server will reject this payload with HTTP 422." -ForegroundColor Yellow
}

try {
    $response = Invoke-RestMethod -Uri $apiUrl -Method Post -Body $jsonPayload -ContentType "application/json"
    Write-Host "Server Response: " -ForegroundColor Green
    Write-Host ($response | ConvertTo-Json)
} catch {
    Write-Host "Failed to send data to server. Error:" -ForegroundColor Red
    Write-Host $_.Exception.Message
}

Start-Sleep -Seconds 5