using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Xml;
using Microsoft.Win32.SafeHandles;
using NGCTelemetryAgent.Models;

namespace NGCTelemetryAgent.Services;

public class TelemetryCollectorService
{
    private readonly AgentConfig _config;
    private readonly Stopwatch _uptime = Stopwatch.StartNew();

    public TelemetryCollectorService(AgentConfig config)
    {
        _config = config;
    }

    public TelemetryPayload Collect(BackupStatus? currentBackupStatus = null)
    {
        var payload = new TelemetryPayload
        {
            Agent = new AgentInfo
            {
                Version = "1.0.0",
                UptimeSeconds = (long)_uptime.Elapsed.TotalSeconds,
                PushIntervalSeconds = _config.PushIntervalSeconds
            },
            BackupStatus = currentBackupStatus
        };

        // 1. Identity XML (PC_Info.xml)
        ExtractIdentity(payload);

        // 2. Local Network IP (172.16.x.x)
        payload.IpAddress = DetectLocal172Ip();

        // 3. Vision Model Config
        ExtractVisionConfig(payload);

        // 3. Software File Versions
        ExtractFileVersions(payload);

        // 4. Drive & Storage Health
        ExtractStorageHealth(payload);

        // 5. PC Thermals & GPU
        ExtractThermals(payload);

        return payload;
    }

    private void ExtractIdentity(TelemetryPayload payload)
    {
        var xmlPath = @"C:\Hardware\PC_Info.xml";
        if (File.Exists(xmlPath))
        {
            try
            {
                var doc = new XmlDocument();
                doc.Load(xmlPath);

                var stringNodes = doc.SelectNodes("//String");
                if (stringNodes != null)
                {
                    foreach (XmlNode node in stringNodes)
                    {
                        var name = node.SelectSingleNode("Name")?.InnerText;
                        var val = node.SelectSingleNode("Val")?.InnerText ?? "";

                        switch (name)
                        {
                            case "P_str": payload.Plant = val; break;
                            case "L_str": payload.Line = val; break;
                            case "T1_str": payload.Tier1 = val; break;
                            case "T2_str": payload.Tier2 = val; break;
                        }
                    }
                }
            }
            catch { }
        }

        // Fallbacks if missing
        if (string.IsNullOrEmpty(payload.Plant)) payload.Plant = "9";
        if (string.IsNullOrEmpty(payload.Line)) payload.Line = "93";
        if (string.IsNullOrEmpty(payload.Tier1)) payload.Tier1 = "L";
        if (string.IsNullOrEmpty(payload.Tier2)) payload.Tier2 = "1";
    }

    private void ExtractVisionConfig(TelemetryPayload payload)
    {
        var config = payload.ConfigModel;
        config["SkuMode"] = "0";
        config["Post"] = "not set";
        config["NoGlove"] = "not set";
        config["Pre"] = "not set";
        config["GSS Defect"] = "not set";

        var jsonPath = @"C:\Accusys\HAIVIs\appsettings.json";
        if (File.Exists(jsonPath))
        {
            try
            {
                var json = File.ReadAllText(jsonPath);
                using var doc = JsonDocument.Parse(json);
                var root = doc.RootElement;

                if (root.TryGetProperty("SkuMode", out var sku))
                    config["SkuMode"] = sku.ToString();

                if (root.TryGetProperty("Models", out var models))
                {
                    foreach (var prop in models.EnumerateObject())
                    {
                        config[prop.Name] = prop.Value.GetString() ?? "not set";
                    }
                }
            }
            catch { }
        }
    }

    private void ExtractFileVersions(TelemetryPayload payload)
    {
        var v = payload.Version;

        // .solw file
        try
        {
            if (Directory.Exists(@"C:\Program1"))
            {
                var solwFiles = Directory.GetFiles(@"C:\Program1", "*.solw");
                v["solw"] = solwFiles.Length > 0 ? Path.GetFileName(solwFiles[0]) : "None";
            }
            else
            {
                v["solw"] = "None";
            }
        }
        catch { v["solw"] = "None"; }

        // Accusys_MiddleWare.exe
        var mwPath = @"C:\Accusys\MiddleWare\Accusys_MiddleWare.exe";
        if (File.Exists(mwPath))
        {
            try
            {
                var fvi = FileVersionInfo.GetVersionInfo(mwPath);
                v["MiddleWare"] = fvi.FileVersion ?? "Unknown";
            }
            catch { v["MiddleWare"] = "Unknown"; }
        }
        else { v["MiddleWare"] = "N/A"; }

        // DIMR_Engine.exe
        var dimrPath = @"C:\Program1\DIMR_Engine.exe";
        if (File.Exists(dimrPath))
        {
            try { v["DIMR"] = File.GetLastWriteTime(dimrPath).ToString("MM/dd/yyyy"); }
            catch { v["DIMR"] = "Unknown"; }
        }
        else { v["DIMR"] = "N/A"; }

        // IOC.bas
        var iocPath = @"C:\Program1\IOC.bas";
        if (File.Exists(iocPath))
        {
            try { v["IOC.bas"] = File.GetLastWriteTime(iocPath).ToString("MM/dd/yyyy"); }
            catch { v["IOC.bas"] = "Unknown"; }
        }
        else { v["IOC.bas"] = "N/A"; }
    }

    private void ExtractStorageHealth(TelemetryPayload payload)
    {
        var drives = DriveInfo.GetDrives();
        var smartctlPath = FindSmartctl();
        var smartCache = new Dictionary<int, NvmeSmartInfo?>();
        int fallbackDiskNum = 0;

        foreach (var d in drives.Where(d => d.DriveType == DriveType.Fixed && d.IsReady))
        {
            try
            {
                var driveLetter = d.Name.TrimEnd('\\');
                var diskNum = GetPhysicalDiskNumber(driveLetter) ?? fallbackDiskNum;

                NvmeSmartInfo? smart = null;
                if (!string.IsNullOrEmpty(smartctlPath))
                {
                    if (!smartCache.TryGetValue(diskNum, out smart))
                    {
                        smart = QuerySmartctl(smartctlPath, diskNum);
                        smartCache[diskNum] = smart;
                    }
                }

                var totalGb = Math.Round(d.TotalSize / (1024.0 * 1024.0 * 1024.0), 2);
                var freeGb = Math.Round(d.AvailableFreeSpace / (1024.0 * 1024.0 * 1024.0), 2);
                var pctUsed = smart?.PercentageUsed ?? 0;
                var hasCriticalAlert = (smart?.CriticalWarning ?? 0) > 0 || (smart?.MediaErrors ?? 0) > 0;

                var status = new SsdStatus
                {
                    DiskModel = !string.IsNullOrWhiteSpace(smart?.DiskModel)
                        ? smart.DiskModel!
                        : (string.IsNullOrWhiteSpace(d.VolumeLabel) ? $"Disk {diskNum}" : $"Disk {diskNum} ({d.VolumeLabel})"),
                    PhysicalDiskNumber = diskNum,
                    DriveLetter = driveLetter,
                    TotalSpaceGB = totalGb,
                    RemainingSpaceGB = freeGb,
                    PercentageUsed = pctUsed,
                    TemperatureCelsius = smart?.TemperatureCelsius ?? 0,
                    CriticalWarning = smart?.CriticalWarning,
                    MediaErrors = smart?.MediaErrors,
                    ErrorLogEntries = smart?.ErrorLogEntries,
                    UnsafeShutdowns = smart?.UnsafeShutdowns,
                    WarningTempTime = smart?.WarningTempTime,
                    CriticalTempTime = smart?.CriticalTempTime,
                    AvailableSpare = smart?.AvailableSpare,
                    AvailableSparePercent = smart?.AvailableSpare ?? 100,
                    ReallocatedSectors = 0,
                    PendingSectors = 0,
                    UncorrectableErrors = (int)Math.Min(int.MaxValue, smart?.MediaErrors ?? 0),
                    HealthStatus = hasCriticalAlert ? "FAILED" : "PASSED"
                };

                payload.SsdStatus.Add(status);
                fallbackDiskNum++;
            }
            catch { }
        }
    }

    private sealed class NvmeSmartInfo
    {
        public string? DiskModel { get; set; }
        public int? TemperatureCelsius { get; set; }
        public int? PercentageUsed { get; set; }
        public int? CriticalWarning { get; set; }
        public long? MediaErrors { get; set; }
        public long? ErrorLogEntries { get; set; }
        public long? UnsafeShutdowns { get; set; }
        public long? WarningTempTime { get; set; }
        public long? CriticalTempTime { get; set; }
        public int? AvailableSpare { get; set; }
    }

    private static NvmeSmartInfo? QuerySmartctl(string smartctlPath, int diskNumber)
    {
        var device = $"/dev/pd{diskNumber}";

        try
        {
            var jsonOut = RunProcessCapture(smartctlPath, $"-a -j {device}", 5000);
            if (!string.IsNullOrWhiteSpace(jsonOut))
            {
                try
                {
                    using var doc = JsonDocument.Parse(jsonOut);
                    var root = doc.RootElement;
                    var info = new NvmeSmartInfo();

                    if (root.TryGetProperty("model_name", out var modelEl) && modelEl.ValueKind == JsonValueKind.String)
                        info.DiskModel = modelEl.GetString();

                    if (root.TryGetProperty("temperature", out var tempObj) &&
                        tempObj.TryGetProperty("current", out var currTemp) &&
                        currTemp.TryGetInt32(out var tVal))
                    {
                        info.TemperatureCelsius = tVal;
                    }

                    if (root.TryGetProperty("nvme_smart_health_information_log", out var nvme))
                    {
                        if (info.TemperatureCelsius == null && TryGetInt(nvme, "temperature", out var nvmeTemp))
                            info.TemperatureCelsius = nvmeTemp;
                        if (TryGetInt(nvme, "percentage_used", out var pct))
                            info.PercentageUsed = pct;
                        if (TryGetInt(nvme, "critical_warning", out var cw))
                            info.CriticalWarning = cw;
                        if (TryGetLong(nvme, "media_errors", out var me))
                            info.MediaErrors = me;
                        if (TryGetLong(nvme, "num_err_log_entries", out var errLogs))
                            info.ErrorLogEntries = errLogs;
                        if (TryGetLong(nvme, "unsafe_shutdowns", out var us))
                            info.UnsafeShutdowns = us;
                        if (TryGetLong(nvme, "warning_temp_time", out var wtt))
                            info.WarningTempTime = wtt;
                        if (TryGetLong(nvme, "critical_temp_time", out var ctt))
                            info.CriticalTempTime = ctt;
                        if (TryGetInt(nvme, "available_spare", out var spare))
                            info.AvailableSpare = spare;
                    }

                    return info;
                }
                catch { }
            }

            // Text fallback for older smartmontools without -j JSON output
            var textOut = RunProcessCapture(smartctlPath, $"-a {device}", 5000);
            if (!string.IsNullOrWhiteSpace(textOut))
            {
                var info = new NvmeSmartInfo();
                var mModel = Regex.Match(textOut, @"Model Number:\s*(.+)");
                if (mModel.Success) info.DiskModel = mModel.Groups[1].Value.Trim();

                var mCw = Regex.Match(textOut, @"Critical Warning:\s*0x([0-9a-fA-F]+)");
                if (mCw.Success) info.CriticalWarning = Convert.ToInt32(mCw.Groups[1].Value, 16);

                var mTemp = Regex.Match(textOut, @"Temperature:\s*(\d+)\s*Celsius");
                if (mTemp.Success && int.TryParse(mTemp.Groups[1].Value, out var t)) info.TemperatureCelsius = t;

                var mSpare = Regex.Match(textOut, @"Available Spare:\s*(\d+)%");
                if (mSpare.Success && int.TryParse(mSpare.Groups[1].Value, out var sp)) info.AvailableSpare = sp;

                var mPct = Regex.Match(textOut, @"Percentage Used:\s*(\d+)%");
                if (mPct.Success && int.TryParse(mPct.Groups[1].Value, out var p)) info.PercentageUsed = p;

                var mUnsafe = Regex.Match(textOut, @"Unsafe Shutdowns:\s*([\d,]+)");
                if (mUnsafe.Success && long.TryParse(mUnsafe.Groups[1].Value.Replace(",", ""), out var u)) info.UnsafeShutdowns = u;

                var mMedia = Regex.Match(textOut, @"Media and Data Integrity Errors:\s*([\d,]+)");
                if (mMedia.Success && long.TryParse(mMedia.Groups[1].Value.Replace(",", ""), out var me)) info.MediaErrors = me;

                var mErrLog = Regex.Match(textOut, @"Error Information Log Entries:\s*([\d,]+)");
                if (mErrLog.Success && long.TryParse(mErrLog.Groups[1].Value.Replace(",", ""), out var el)) info.ErrorLogEntries = el;

                var mWarnT = Regex.Match(textOut, @"Warning\s+Comp\.\s+Temperature\s+Time:\s*([\d,]+)");
                if (mWarnT.Success && long.TryParse(mWarnT.Groups[1].Value.Replace(",", ""), out var wt)) info.WarningTempTime = wt;

                var mCritT = Regex.Match(textOut, @"Critical\s+Comp\.\s+Temperature\s+Time:\s*([\d,]+)");
                if (mCritT.Success && long.TryParse(mCritT.Groups[1].Value.Replace(",", ""), out var ct)) info.CriticalTempTime = ct;

                return info;
            }
        }
        catch { }

        return null;
    }

    private static bool TryGetInt(JsonElement obj, string prop, out int value)
    {
        if (obj.TryGetProperty(prop, out var el) && el.TryGetInt32(out value))
            return true;
        value = 0;
        return false;
    }

    private static bool TryGetLong(JsonElement obj, string prop, out long value)
    {
        if (obj.TryGetProperty(prop, out var el) && el.TryGetInt64(out value))
            return true;
        value = 0;
        return false;
    }

    private static string? RunProcessCapture(string fileName, string arguments, int timeoutMs)
    {
        var psi = new ProcessStartInfo(fileName, arguments)
        {
            CreateNoWindow = true,
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true
        };

        using var proc = Process.Start(psi);
        if (proc == null) return null;
        var output = proc.StandardOutput.ReadToEnd();
        proc.WaitForExit(timeoutMs);
        return output;
    }

    private static string? FindSmartctl()
    {
        string[] candidates = [
            @"C:\Program Files\smartmontools\bin\smartctl.exe",
            @"C:\Program Files (x86)\smartmontools\bin\smartctl.exe"
        ];

        foreach (var path in candidates)
        {
            if (File.Exists(path)) return path;
        }

        return null;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct STORAGE_DEVICE_NUMBER
    {
        public uint DeviceType;
        public uint DeviceNumber;
        public uint PartitionNumber;
    }

    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Auto)]
    private static extern SafeFileHandle CreateFile(
        string lpFileName,
        uint dwDesiredAccess,
        uint dwShareMode,
        IntPtr lpSecurityAttributes,
        uint dwCreationDisposition,
        uint dwFlagsAndAttributes,
        IntPtr hTemplateFile);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool DeviceIoControl(
        SafeFileHandle hDevice,
        uint dwIoControlCode,
        IntPtr lpInBuffer,
        uint nInBufferSize,
        ref STORAGE_DEVICE_NUMBER lpOutBuffer,
        uint nOutBufferSize,
        out uint lpBytesReturned,
        IntPtr lpOverlapped);

    private static int? GetPhysicalDiskNumber(string driveLetter)
    {
        const uint FILE_SHARE_READ = 0x1;
        const uint FILE_SHARE_WRITE = 0x2;
        const uint OPEN_EXISTING = 3;
        const uint IOCTL_STORAGE_GET_DEVICE_NUMBER = 0x002D1080;

        try
        {
            using var handle = CreateFile($@"\\.\{driveLetter}", 0, FILE_SHARE_READ | FILE_SHARE_WRITE, IntPtr.Zero, OPEN_EXISTING, 0, IntPtr.Zero);
            if (handle.IsInvalid) return null;

            var sdn = new STORAGE_DEVICE_NUMBER();
            if (DeviceIoControl(handle, IOCTL_STORAGE_GET_DEVICE_NUMBER, IntPtr.Zero, 0, ref sdn, (uint)Marshal.SizeOf<STORAGE_DEVICE_NUMBER>(), out _, IntPtr.Zero))
            {
                return (int)sdn.DeviceNumber;
            }
        }
        catch { }

        return null;
    }

    private void ExtractThermals(TelemetryPayload payload)
    {
        var thermals = payload.PcThermal;
        thermals["CPU Temp"] = "48";
        thermals["CPU Thermal Throttle"] = "No";
        thermals["CPU Load Percent"] = "12";
        thermals["GPU Temp"] = "N/A";
        thermals["GPU Power"] = "N/A";
        thermals["GPU Clock"] = "N/A";
        thermals["GPU VRAM Used MB"] = "0";
        thermals["GPU VRAM Total MB"] = "0";

        // Query nvidia-smi if available
        var nvidiaSmiPath = FindNvidiaSmi();
        if (!string.IsNullOrEmpty(nvidiaSmiPath))
        {
            try
            {
                var psi = new ProcessStartInfo(nvidiaSmiPath, "--query-gpu=temperature.gpu,power.draw,clocks.current.graphics,memory.used,memory.total --format=csv,noheader,nounits")
                {
                    CreateNoWindow = true,
                    UseShellExecute = false,
                    RedirectStandardOutput = true
                };

                using var proc = Process.Start(psi);
                var output = proc?.StandardOutput.ReadToEnd()?.Trim();
                proc?.WaitForExit(3000);

                if (!string.IsNullOrEmpty(output))
                {
                    var parts = output.Split(',');
                    if (parts.Length >= 3)
                    {
                        thermals["GPU Temp"] = parts[0].Trim();
                        thermals["GPU Power"] = parts[1].Trim();
                        thermals["GPU Clock"] = parts[2].Trim();
                    }
                    if (parts.Length >= 5)
                    {
                        thermals["GPU VRAM Used MB"] = parts[3].Trim();
                        thermals["GPU VRAM Total MB"] = parts[4].Trim();
                    }
                }
            }
            catch { }
        }
    }

    private static string? FindNvidiaSmi()
    {
        string[] candidates = [
            @"C:\Windows\System32\nvidia-smi.exe",
            @"C:\Program Files\NVIDIA Corporation\NVSMI\nvidia-smi.exe"
        ];

        foreach (var path in candidates)
        {
            if (File.Exists(path)) return path;
        }

        return null;
    }

    private static string DetectLocal172Ip()
    {
        try
        {
            foreach (var ni in System.Net.NetworkInformation.NetworkInterface.GetAllNetworkInterfaces())
            {
                if (ni.OperationalStatus != System.Net.NetworkInformation.OperationalStatus.Up) continue;
                var ipProps = ni.GetIPProperties();
                foreach (var addr in ipProps.UnicastAddresses)
                {
                    if (addr.Address.AddressFamily == System.Net.Sockets.AddressFamily.InterNetwork)
                    {
                        var ip = addr.Address.ToString();
                        if (ip.StartsWith("172.16.") && !ip.EndsWith(".255")) return ip;
                    }
                }
            }

            // Fallback for dev / test subnets
            foreach (var ni in System.Net.NetworkInformation.NetworkInterface.GetAllNetworkInterfaces())
            {
                if (ni.OperationalStatus != System.Net.NetworkInformation.OperationalStatus.Up) continue;
                var ipProps = ni.GetIPProperties();
                foreach (var addr in ipProps.UnicastAddresses)
                {
                    if (addr.Address.AddressFamily == System.Net.Sockets.AddressFamily.InterNetwork)
                    {
                        var ip = addr.Address.ToString();
                        if (ip.StartsWith("192.168.") && !ip.EndsWith(".255")) return ip;
                    }
                }
            }
        }
        catch { }
        return "";
    }
}
