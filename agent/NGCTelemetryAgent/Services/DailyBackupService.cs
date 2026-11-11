using System.Diagnostics;
using System.IO.Compression;
using System.Xml;
using NGCTelemetryAgent.Models;

namespace NGCTelemetryAgent.Services;

public class DailyBackupService
{
    private readonly AgentConfig _config;
    private readonly Action<string>? _logger;
    private readonly object _lock = new();
    private bool _isBackingUp;

    public BackupStatus CurrentStatus { get; private set; } = new();

    public DailyBackupService(AgentConfig config, Action<string>? logger = null)
    {
        _config = config;
        _logger = logger;
    }

    private void Log(string message, string level = "INFO")
    {
        var msg = $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss}] [{level}] [Backup] {message}";
        _logger?.Invoke(msg);
    }

    public async Task<bool> RunBackupAsync(bool isManual = false)
    {
        lock (_lock)
        {
            if (_isBackingUp)
            {
                Log("Backup is already in progress. Skipping trigger.", "WARNING");
                return false;
            }
            _isBackingUp = true;
        }

        var stopwatch = Stopwatch.StartNew();
        CurrentStatus.Status = "RUNNING";
        CurrentStatus.ErrorMessage = null;

        string backupRoot = @"C:\Backup";
        string xmlPath = @"C:\Hardware\PC_Info.xml";

        try
        {
            Log($"--- Starting Daily Plant Backup Routine (Manual: {isManual}) ---");

            if (!Directory.Exists(backupRoot))
            {
                Directory.CreateDirectory(backupRoot);
            }

            // 1. Parse XML Identity
            string plant = "9";
            string line = "93";
            string tier = "L";

            if (File.Exists(xmlPath))
            {
                var xmlDoc = new XmlDocument();
                xmlDoc.Load(xmlPath);

                var stringNodes = xmlDoc.SelectNodes("//String");
                if (stringNodes != null)
                {
                    foreach (XmlNode node in stringNodes)
                    {
                        var name = node.SelectSingleNode("Name")?.InnerText;
                        var val = node.SelectSingleNode("Val")?.InnerText ?? "";
                        if (name == "P_str") plant = val;
                        else if (name == "L_str") line = val;
                        else if (name == "T1_str") tier = val;
                    }
                }
            }
            else
            {
                Log($"Identity XML not found at {xmlPath}, using defaults.", "WARNING");
            }

            var tierPrefix = !string.IsNullOrEmpty(tier) ? tier.Substring(0, 1) : "L";
            var zipFileName = $"{line}{tierPrefix}.zip";
            var zipFilePath = Path.Combine(backupRoot, zipFileName);
            var stagingDir = Path.Combine(backupRoot, $"Staging_{line}");

            Log($"Target archive: {zipFileName} for Plant: {plant}, Line: {line}, Tier: {tier}");

            // 2. Prepare staging directory
            if (Directory.Exists(stagingDir))
            {
                Directory.Delete(stagingDir, true);
            }
            Directory.CreateDirectory(stagingDir);

            // 3. Stage Accusys (Excluding HAIVIs\log folder via Robocopy)
            if (Directory.Exists(@"C:\Accusys"))
            {
                var accusysDest = Path.Combine(stagingDir, "Accusys");
                Directory.CreateDirectory(accusysDest);

                var roboArgs = $"\"C:\\Accusys\" \"{accusysDest}\" /E /XD \"C:\\Accusys\\HAIVIs\\log\" /R:0 /W:0 /NFL /NDL /NJH /NJS";
                Log("Executing Robocopy staging for C:\\Accusys (excluding HAIVIs\\log)...");

                var psi = new ProcessStartInfo("robocopy.exe", roboArgs)
                {
                    CreateNoWindow = true,
                    UseShellExecute = false
                };
                using var p = Process.Start(psi);
                p?.WaitForExit();
            }

            // Stage Hardware
            if (Directory.Exists(@"C:\Hardware"))
            {
                CopyDirectory(@"C:\Hardware", Path.Combine(stagingDir, "Hardware"));
            }

            // Stage Program1
            if (Directory.Exists(@"C:\Program1"))
            {
                CopyDirectory(@"C:\Program1", Path.Combine(stagingDir, "Program1"));
            }

            // Stage NGCDashboard (optional server folder)
            if (Directory.Exists(@"C:\inetpub\wwwroot\NGCDashboard"))
            {
                CopyDirectory(@"C:\inetpub\wwwroot\NGCDashboard", Path.Combine(stagingDir, "NGCDashboard"));
            }

            // 4. Compress Directory with CPU Throttling (Idle Priority + Single Core Affinity)
            Log("Compressing staged files into archive with CPU throttling...");
            var currentProc = Process.GetCurrentProcess();
            var origPriority = currentProc.PriorityClass;
            var origAffinity = currentProc.ProcessorAffinity;

            try
            {
                currentProc.PriorityClass = ProcessPriorityClass.Idle;
                currentProc.ProcessorAffinity = (IntPtr)1; // Single core to avoid interfering with AI models

                if (File.Exists(zipFilePath))
                {
                    File.Delete(zipFilePath);
                }

                ZipFile.CreateFromDirectory(stagingDir, zipFilePath, CompressionLevel.Fastest, false);
            }
            finally
            {
                currentProc.PriorityClass = origPriority;
                currentProc.ProcessorAffinity = origAffinity;
            }

            // Clean staging folder
            if (Directory.Exists(stagingDir))
            {
                Directory.Delete(stagingDir, true);
            }

            var zipInfo = new FileInfo(zipFilePath);
            double zipSizeMb = Math.Round(zipInfo.Length / (1024.0 * 1024.0), 2);
            Log($"Created zip archive: {zipFileName} ({zipSizeMb} MB)");

            // 5. Transfer over SFTP using WinSCP or script
            var remoteDir = $"/BACKUP/Plant_{plant}";
            var sftpSuccess = await TransferViaWinScpAsync(zipFilePath, remoteDir, backupRoot);

            stopwatch.Stop();
            var duration = (int)stopwatch.Elapsed.TotalSeconds;

            if (sftpSuccess)
            {
                CurrentStatus.Status = "SUCCESS";
                CurrentStatus.LastBackupTime = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss");
                CurrentStatus.ZipSizeMB = zipSizeMb;
                CurrentStatus.DurationSeconds = duration;
                CurrentStatus.RemotePath = $"{remoteDir}/{zipFileName}";
                CurrentStatus.ErrorMessage = null;

                // Delete local zip on success to save disk space
                if (File.Exists(zipFilePath))
                {
                    File.Delete(zipFilePath);
                }

                Log($"Daily Plant Backup completed successfully in {duration} seconds.", "SUCCESS");
                return true;
            }
            else
            {
                CurrentStatus.Status = "FAILED";
                CurrentStatus.ErrorMessage = "SFTP upload failed. Local zip retained for inspection.";
                Log("SFTP transfer failed. Local archive preserved.", "ERROR");
                return false;
            }
        }
        catch (Exception ex)
        {
            stopwatch.Stop();
            CurrentStatus.Status = "FAILED";
            CurrentStatus.ErrorMessage = ex.Message;
            Log($"Fatal error in Daily Backup: {ex.Message}", "ERROR");
            return false;
        }
        finally
        {
            lock (_lock)
            {
                _isBackingUp = false;
            }
        }
    }

    private Task<bool> TransferViaWinScpAsync(string zipFilePath, string remoteDir, string backupRoot)
    {
        return Task.Run(() =>
        {
            var winscpScriptPath = Path.Combine(backupRoot, "sftp_script.txt");
            var scriptContent = $@"option batch continue
option confirm off
open sftp://{_config.SftpUser}:{_config.SftpPass}@{_config.SftpHost}:{_config.SftpPort} -hostkey=""*""
mkdir ""{remoteDir}""
option batch abort
put ""{zipFilePath}"" ""{remoteDir}/""
exit
";
            File.WriteAllText(winscpScriptPath, scriptContent);

            var winscpExe = @"C:\Program Files (x86)\WinSCP\WinSCP.com";
            if (!File.Exists(winscpExe))
            {
                Log($"WinSCP.com not found at {winscpExe}.", "WARNING");
                return false;
            }

            var psi = new ProcessStartInfo(winscpExe, $"/script=\"{winscpScriptPath}\"")
            {
                CreateNoWindow = true,
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true
            };

            using var proc = Process.Start(psi);
            proc?.WaitForExit(600000); // 10 minutes timeout

            var exitCode = proc?.ExitCode ?? -1;

            if (File.Exists(winscpScriptPath))
            {
                File.Delete(winscpScriptPath);
            }

            return exitCode == 0;
        });
    }

    private static void CopyDirectory(string sourceDir, string targetDir)
    {
        Directory.CreateDirectory(targetDir);
        foreach (var file in Directory.GetFiles(sourceDir))
        {
            var dest = Path.Combine(targetDir, Path.GetFileName(file));
            File.Copy(file, dest, true);
        }
        foreach (var dir in Directory.GetDirectories(sourceDir))
        {
            var dest = Path.Combine(targetDir, Path.GetFileName(dir));
            CopyDirectory(dir, dest);
        }
    }
}
