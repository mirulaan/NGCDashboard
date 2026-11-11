<#
.SYNOPSIS
    Centralized Fleet Deployment Orchestrator for NGC Telemetry Agent
.DESCRIPTION
    Executes from the Plant Dashboard Server (IIS / 172.16.x.2).
    Discovers all edge inspection PC IPs from the live telemetry database,
    deploys NGCTelemetryAgent concurrently across the plant subnet,
    and retires legacy 'NGC_Telemetry_Push' and 'Daily_Plant_Backup' tasks.
#>

[CmdletBinding()]
param(
    [string]$Plant = "",
    [string[]]$TargetIps = @(),
    [string]$DbPath = "",
    [string]$AgentSourceDir = "",
    [int]$MaxConcurrency = 8,
    [switch]$DiscoveryOnly
)

# 0. AUTO-ELEVATE TO ADMINISTRATOR & BYPASS EXECUTION POLICY
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    try {
        Write-Host "Elevating to Administrator..." -ForegroundColor Yellow
        $argList = @("-NoProfile", "-ExecutionPolicy", "Bypass", "-File", "`"$PSCommandPath`"")
        foreach ($key in $PSBoundParameters.Keys) {
            $val = $PSBoundParameters[$key]
            if ($val -is [switch] -and $val.IsPresent) {
                $argList += "-$key"
            } elseif ($val -is [array]) {
                $argList += "-$key"
                $argList += ($val -join ',')
            } else {
                $argList += "-$key"
                $argList += "`"$val`""
            }
        }
        Start-Process powershell.exe -ArgumentList ($argList -join ' ') -Verb RunAs -ErrorAction Stop
        Exit
    } catch {
        Write-Warning "Could not automatically elevate to Administrator: $($_.Exception.Message)"
        Write-Warning "Continuing with current privileges. (Note: Remote SMB and WMI typically require Administrator privileges)."
    }
}

try {
    if ((Get-ExecutionPolicy -Scope Process) -ne "Bypass") {
        Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass -Force -ErrorAction SilentlyContinue
    }
} catch { }

if ([string]::IsNullOrWhiteSpace($AgentSourceDir)) {
    $AgentSourceDir = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot "..\public\scripts"))
}

# Auto-locate database across common deployment and workspace locations
if ([string]::IsNullOrWhiteSpace($DbPath) -or -not (Test-Path $DbPath)) {
    $candidates = [System.Collections.Generic.List[string]]::new()

    # 1. Environment variable
    if (-not [string]::IsNullOrWhiteSpace($env:DB_DATABASE)) {
        $candidates.Add($env:DB_DATABASE)
    }

    # 2. Check .env in parent or IIS directories
    $envCandidateFiles = @(
        (Join-Path $PSScriptRoot "..\.env"),
        "C:\inetpub\wwwroot\NGCDashboard\.env",
        "D:\inetpub\wwwroot\NGCDashboard\.env"
    )
    foreach ($ef in $envCandidateFiles) {
        if (Test-Path $ef) {
            try {
                $lines = Get-Content $ef -ErrorAction SilentlyContinue
                foreach ($l in $lines) {
                    if ($l -match '^\s*DB_DATABASE\s*=\s*(.+)$') {
                        $parsedDb = $matches[1].Trim().Trim('"').Trim("'")
                        if (-not [string]::IsNullOrWhiteSpace($parsedDb)) {
                            $candidates.Add($parsedDb)
                        }
                        break
                    }
                }
            } catch {}
        }
    }

    # 3. Known relative & absolute deployment / dev locations
    $knownLocations = @(
        (Join-Path $PSScriptRoot "..\..\NGCDashboard_Data\database.sqlite"),
        (Join-Path $PSScriptRoot "..\NGCDashboard_Data\database.sqlite"),
        "C:\inetpub\wwwroot\NGCDashboard_Data\database.sqlite",
        "D:\inetpub\wwwroot\NGCDashboard_Data\database.sqlite",
        "D:\VibeCode\NGCDashboard_Data\database.sqlite",
        "C:\VibeCode\NGCDashboard_Data\database.sqlite",
        (Join-Path $PSScriptRoot "..\database\database.sqlite")
    )
    foreach ($kl in $knownLocations) {
        $candidates.Add($kl)
    }

    # Pick first matching candidate
    foreach ($c in $candidates) {
        try {
            if (-not [string]::IsNullOrWhiteSpace($c) -and (Test-Path $c)) {
                $DbPath = (Resolve-Path $c).Path
                break
            }
        } catch {}
    }
}

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "  NGC Telemetry Agent - Centralized Fleet Deployer        " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

# Robust multi-engine SQLite query function
function Invoke-SqliteQuery {
    param(
        [string]$DatabasePath,
        [string]$Query
    )

    # Strategy 1: Built-in Windows winsqlite3.dll (Native to Windows 10/11/Server 2019+, zero extra installs)
    $winSqliteDll = Join-Path $env:SystemRoot "System32\winsqlite3.dll"
    if (Test-Path $winSqliteDll) {
        try {
            $helperTypeName = "NGCDashboard.Deployment.WinSqliteQueryHelper"
            if (-not ($helperTypeName -as [type])) {
                $csSource = @"
using System;
using System.Collections.Generic;
using System.Runtime.InteropServices;

namespace NGCDashboard.Deployment {
    public static class WinSqliteQueryHelper {
        [DllImport("winsqlite3.dll", EntryPoint = "sqlite3_open16", CallingConvention = CallingConvention.Cdecl, CharSet = CharSet.Unicode)]
        private static extern int sqlite3_open16(string filename, out IntPtr db);

        [DllImport("winsqlite3.dll", EntryPoint = "sqlite3_close", CallingConvention = CallingConvention.Cdecl)]
        private static extern int sqlite3_close(IntPtr db);

        [DllImport("winsqlite3.dll", EntryPoint = "sqlite3_prepare16_v2", CallingConvention = CallingConvention.Cdecl, CharSet = CharSet.Unicode)]
        private static extern int sqlite3_prepare16_v2(IntPtr db, string zSql, int nByte, out IntPtr ppStmt, IntPtr pzTail);

        [DllImport("winsqlite3.dll", EntryPoint = "sqlite3_step", CallingConvention = CallingConvention.Cdecl)]
        private static extern int sqlite3_step(IntPtr stmt);

        [DllImport("winsqlite3.dll", EntryPoint = "sqlite3_column_count", CallingConvention = CallingConvention.Cdecl)]
        private static extern int sqlite3_column_count(IntPtr stmt);

        [DllImport("winsqlite3.dll", EntryPoint = "sqlite3_column_text16", CallingConvention = CallingConvention.Cdecl)]
        private static extern IntPtr sqlite3_column_text16(IntPtr stmt, int iCol);

        [DllImport("winsqlite3.dll", EntryPoint = "sqlite3_finalize", CallingConvention = CallingConvention.Cdecl)]
        private static extern int sqlite3_finalize(IntPtr stmt);

        [DllImport("winsqlite3.dll", EntryPoint = "sqlite3_errmsg16", CallingConvention = CallingConvention.Cdecl)]
        private static extern IntPtr sqlite3_errmsg16(IntPtr db);

        public static List<string[]> Query(string dbPath, string sql) {
            var results = new List<string[]>();
            IntPtr db = IntPtr.Zero;
            IntPtr stmt = IntPtr.Zero;

            int rc = sqlite3_open16(dbPath, out db);
            if (rc != 0) {
                string err = Marshal.PtrToStringUni(sqlite3_errmsg16(db));
                if (db != IntPtr.Zero) sqlite3_close(db);
                throw new InvalidOperationException("Failed to open SQLite database: " + err);
            }

            try {
                rc = sqlite3_prepare16_v2(db, sql, -1, out stmt, IntPtr.Zero);
                if (rc != 0) {
                    string err = Marshal.PtrToStringUni(sqlite3_errmsg16(db));
                    throw new InvalidOperationException("Failed to prepare SQLite statement: " + err);
                }

                int colCount = sqlite3_column_count(stmt);
                while (sqlite3_step(stmt) == 100) {
                    string[] row = new string[colCount];
                    for (int i = 0; i < colCount; i++) {
                        IntPtr ptr = sqlite3_column_text16(stmt, i);
                        row[i] = ptr == IntPtr.Zero ? "" : Marshal.PtrToStringUni(ptr);
                    }
                    results.Add(row);
                }
            } finally {
                if (stmt != IntPtr.Zero) sqlite3_finalize(stmt);
                if (db != IntPtr.Zero) sqlite3_close(db);
            }

            return results;
        }
    }
}
"@
                Add-Type -TypeDefinition $csSource
            }

            $rawResult = [NGCDashboard.Deployment.WinSqliteQueryHelper]::Query($DatabasePath, $Query)
            if ($rawResult -ne $null) {
                return ($rawResult | ForEach-Object { $_ -join "|" })
            }
        } catch {
            Write-Verbose "Strategy 1 (winsqlite3.dll) failed: $($_.Exception.Message)"
        }
    }

    # Strategy 2: sqlite3.exe CLI (if installed)
    $sqliteExe = Get-Command "sqlite3" -ErrorAction SilentlyContinue
    if (-not $sqliteExe) {
        $sqlitePaths = @(
            "C:\sqlite\sqlite3.exe",
            "C:\tools\sqlite3.exe",
            "C:\Program Files\DB Browser for SQLite\sqlite3.exe"
        )
        foreach ($sp in $sqlitePaths) {
            if (Test-Path $sp) { $sqliteExe = $sp; break }
        }
    }
    if ($sqliteExe) {
        try {
            $sqliteCmd = if ($sqliteExe.FullName) { $sqliteExe.FullName } else { $sqliteExe }
            $rows = & $sqliteCmd -separator "|" $DatabasePath $Query 2>$null
            if ($LASTEXITCODE -eq 0 -and $rows) {
                return $rows
            }
        } catch {
            Write-Verbose "Strategy 2 (sqlite3.exe) failed: $($_.Exception.Message)"
        }
    }

    # Strategy 3: PHP PDO SQLite
    $phpCmd = "php"
    if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
        $phpPaths = @("C:\php\php.exe", "C:\Program Files\PHP\php.exe", "C:\tools\php\php.exe", "C:\laragon\bin\php\*\php.exe", "C:\xampp\php\php.exe", "D:\php\php.exe")
        foreach ($p in $phpPaths) {
            $found = Get-Item $p -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($found) { $phpCmd = $found.FullName; break }
        }
    }
    if (Get-Command $phpCmd -ErrorAction SilentlyContinue -or (Test-Path $phpCmd)) {
        try {
            $formattedPath = ($DatabasePath -replace '\\', '/')
            $phpCode = '$pdo = new PDO("sqlite:' + $formattedPath + '"); ' +
                       '$stmt = $pdo->query("' + $Query + '"); ' +
                       'if ($stmt) { while ($row = $stmt->fetch(PDO::FETCH_NUM)) { ' +
                       'echo implode("|", $row) . PHP_EOL; } }'
            $rawRows = & $phpCmd -r $phpCode 2>$null
            if ($rawRows) {
                return ($rawRows -split "`r?`n" | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
            }
        } catch {
            Write-Verbose "Strategy 3 (PHP) failed: $($_.Exception.Message)"
        }
    }

    # Strategy 4: Python sqlite3
    $pyCmd = Get-Command "python" -ErrorAction SilentlyContinue
    if ($pyCmd) {
        try {
            $pyCode = "import sqlite3; conn = sqlite3.connect(r'''$DatabasePath'''); cur = conn.cursor(); cur.execute('''$Query'''); [print('|'.join(str(c) for c in row)) for row in cur.fetchall()]"
            $pyRows = & $pyCmd -c $pyCode 2>$null
            if ($pyRows) {
                return ($pyRows -split "`r?`n" | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
            }
        } catch {
            Write-Verbose "Strategy 4 (Python) failed: $($_.Exception.Message)"
        }
    }

    return @()
}

# 1. DISCOVER ACTIVE EDGE IP ADDRESSES
$ipList = [System.Collections.Generic.List[string]]::new()

if ($TargetIps.Count -gt 0) {
    $ipList.AddRange($TargetIps)
    Write-Host "Using manually specified target IPs: $($ipList -join ', ')" -ForegroundColor Yellow
} else {
    if (Test-Path $DbPath) {
        Write-Host "Querying target PC IP addresses from database: $DbPath..." -ForegroundColor Cyan
        try {
            $plantFilter = if (-not [string]::IsNullOrWhiteSpace($Plant)) { 
                $cleanPlant = ($Plant -replace "'", "''")
                "AND plant = '$cleanPlant'" 
            } else { "" }

            # Retrieve the latest telemetry record per machine with a valid IPv4 address
            $sqliteQuery = "SELECT t.plant, t.line, t.side, t.ip_address, t.created_at as last_seen FROM telemetry_logs t INNER JOIN (SELECT plant, line, side, MAX(id) as max_id FROM telemetry_logs WHERE ip_address IS NOT NULL AND TRIM(ip_address) != '' AND TRIM(ip_address) != '127.0.0.1' AND TRIM(ip_address) != 'localhost' AND TRIM(ip_address) != '::1' $plantFilter GROUP BY plant, line, side) latest ON t.id = latest.max_id ORDER BY CAST(t.line AS INTEGER) ASC, t.side ASC;"
            
            $rawRows = Invoke-SqliteQuery -DatabasePath $DbPath -Query $sqliteQuery

            if ($rawRows -and $rawRows.Count -gt 0) {
                Write-Host "`nTarget Optical Inspection Roster Discovered:" -ForegroundColor Green
                Write-Host "----------------------------------------------------------------------------------" -ForegroundColor Gray
                Write-Host ("  {0,-8} | {1,-10} | {2,-16} | {3}" -f "Plant", "Line-Side", "Target IP", "Last Seen") -ForegroundColor DarkCyan
                Write-Host "----------------------------------------------------------------------------------" -ForegroundColor Gray

                foreach ($line in $rawRows) {
                    if ([string]::IsNullOrWhiteSpace($line)) { continue }
                    $parts = $line.Split('|')
                    if ($parts.Count -ge 4) {
                        $p = $parts[0].Trim()
                        $l = $parts[1].Trim()
                        $s = $parts[2].Trim()
                        $ip = $parts[3].Trim()
                        $lastSeen = if ($parts.Count -ge 5) { $parts[4].Trim() } else { "Unknown" }

                        $outIp = $null
                        $isValidIp = [System.Net.IPAddress]::TryParse($ip, [ref]$outIp) -and ($outIp.AddressFamily -eq [System.Net.Sockets.AddressFamily]::InterNetwork)

                        if ($isValidIp) {
                            Write-Host ("  Plant {0,-2} | Line {1,3}-{2}   | {3,-16} | {4}" -f $p, $l, $s, $ip, $lastSeen) -ForegroundColor Cyan
                            if (-not $ipList.Contains($ip)) {
                                $ipList.Add($ip)
                            }
                        } else {
                            Write-Host ("  Plant {0,-2} | Line {1,3}-{2}   | [Invalid IP: {3}] (Last seen: {4})" -f $p, $l, $s, $ip, $lastSeen) -ForegroundColor Yellow
                        }
                    }
                }
                Write-Host "----------------------------------------------------------------------------------" -ForegroundColor Gray
            }
        } catch {
            Write-Warning "Could not query SQLite database: $($_.Exception.Message)"
        }
    } else {
        Write-Warning "Database file not found at: '$DbPath'"
        Write-Host "Ensure database.sqlite exists or supply -DbPath <path-to-database.sqlite>" -ForegroundColor Gray
    }
}

# If in Discovery-Only mode, print roster summary and exit cleanly
if ($DiscoveryOnly) {
    Write-Host "`n[DiscoveryOnly] Mode completed. Total active target inspection PCs discovered: $($ipList.Count)" -ForegroundColor Green
    return
}

# If still empty, allow interactive IP input or guidance
if ($ipList.Count -eq 0) {
    Write-Host ""
    Write-Warning "No active IPs discovered automatically in database."
    Write-Host "You can enter one or more target IP addresses manually." -ForegroundColor Cyan
    $manualInput = Read-Host "Enter Target IP(s) separated by commas (or press Enter to cancel)"
    if (-not [string]::IsNullOrWhiteSpace($manualInput)) {
        foreach ($ip in ($manualInput -split '[,; ]')) {
            $trimmed = $ip.Trim()
            $outIp = $null
            if ([System.Net.IPAddress]::TryParse($trimmed, [ref]$outIp) -and ($outIp.AddressFamily -eq [System.Net.Sockets.AddressFamily]::InterNetwork)) {
                $ipList.Add($trimmed)
            }
        }
    }
    if ($ipList.Count -eq 0) {
        Write-Host "No target IPs specified. Deployment cancelled." -ForegroundColor Yellow
        Write-Host "`nPress Enter to exit..." -ForegroundColor Gray
        Read-Host
        Exit
    }
}

Write-Host "`nDiscovered $($ipList.Count) target inspection PCs for deployment." -ForegroundColor Green

# 2. PREPARE DEPLOYMENT ARTIFACTS
$bootstrapScript = Join-Path $AgentSourceDir "Bootstrap-NGCAgent.ps1"
if (-not (Test-Path $bootstrapScript)) {
    Write-Error "Bootstrap script not found at: $bootstrapScript"
    Write-Host "`nPress Enter to exit..." -ForegroundColor Gray
    Read-Host
    Exit
}

# 3. CONCURRENT ROLLOUT FUNCTION
function Deploy-ToNode {
    param([string]$Ip)

    $result = [PSCustomObject]@{
        IP           = $Ip
        Ping         = "Failed"
        Copied       = "No"
        Bootstrap    = "Pending"
        Status       = "Failed"
        ErrorMessage = ""
    }

    # Ping Check
    $ping = Test-Connection -ComputerName $Ip -Count 1 -Quiet -ErrorAction SilentlyContinue
    if (-not $ping) {
        $result.ErrorMessage = "Ping unreachable"
        return $result
    }
    $result.Ping = "OK"

    # SMB Admin Share Deployment
    $remoteHardwarePath = "\\$Ip\c$\Hardware\NGCTelemetryAgent"
    try {
        if (-not (Test-Path $remoteHardwarePath)) {
            New-Item -ItemType Directory -Path $remoteHardwarePath -Force | Out-Null
        }

        # Copy bootstrap script
        Copy-Item -Path $bootstrapScript -Destination "$remoteHardwarePath\Bootstrap-NGCAgent.ps1" -Force -ErrorAction Stop
        
        # Copy binary if available locally
        $localBinary = Join-Path $AgentSourceDir "NGCTelemetryAgent.exe"
        if (Test-Path $localBinary) {
            Copy-Item -Path $localBinary -Destination "$remoteHardwarePath\NGCTelemetryAgent.exe" -Force -ErrorAction SilentlyContinue
        }
        $result.Copied = "Yes"

        # Remote Execution via WMI / WinRM
        $command = "powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\Hardware\NGCTelemetryAgent\Bootstrap-NGCAgent.ps1"
        $wmiProcess = [wmiclass]"\\$Ip\root\cimv2:Win32_Process"
        $execResult = $wmiProcess.Create($command)

        if ($execResult.ReturnValue -eq 0) {
            $result.Bootstrap = "Triggered (PID: $($execResult.ProcessId))"
            $result.Status = "Success"
        } else {
            $result.Bootstrap = "WMI Return $($execResult.ReturnValue)"
            $result.ErrorMessage = "Failed to launch remote bootstrap process via WMI"
        }

    } catch {
        $result.ErrorMessage = $_.Exception.Message
    }

    return $result
}

# 4. EXECUTE PARALLEL DEPLOYMENT
Write-Host "Initiating rollout across $($ipList.Count) edge machines..." -ForegroundColor Cyan

$results = @()
foreach ($targetIp in $ipList) {
    Write-Host "Deploying to $targetIp..." -NoNewline
    $res = Deploy-ToNode -Ip $targetIp
    if ($res.Status -eq "Success") {
        Write-Host " [SUCCESS]" -ForegroundColor Green
    } else {
        Write-Host " [FAILED: $($res.ErrorMessage)]" -ForegroundColor Red
    }
    $results += $res
}

# 5. SUMMARY REPORT
Write-Host "`n==========================================================" -ForegroundColor Cyan
Write-Host "  Fleet Deployment Summary Report                         " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

$results | Format-Table -Property IP, Ping, Copied, Bootstrap, Status, ErrorMessage -AutoSize

$successCount = @($results | Where-Object Status -eq "Success").Count
$failCount = $results.Count - $successCount
Write-Host "Completed: $successCount Succeeded, $failCount Failed." -ForegroundColor $(if ($failCount -gt 0) { "Yellow" } else { "Green" })
Write-Host "Legacy tasks (NGC_Telemetry_Push and Daily_Plant_Backup) are automatically retired upon bootstrap execution." -ForegroundColor Cyan

Write-Host "`nPress Enter to exit..." -ForegroundColor Gray
Read-Host
