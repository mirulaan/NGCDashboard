using System.Diagnostics;

namespace NGCTelemetryAgent.Services;

public static class TaskSchedulerManager
{
    private const string LegacyTelemetryTask = "NGC_Telemetry_Push";
    private const string LegacyBackupTask = "Daily_Plant_Backup";
    private const string AgentTaskName = "NGC_Telemetry_Agent";

    public static void RetireLegacyTasks(Action<string>? log = null)
    {
        string[] tasks = [LegacyTelemetryTask, LegacyBackupTask];

        foreach (var task in tasks)
        {
            try
            {
                var psi = new ProcessStartInfo("schtasks.exe", $"/Delete /TN \"{task}\" /F")
                {
                    CreateNoWindow = true,
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true
                };

                using var proc = Process.Start(psi);
                proc?.WaitForExit(3000);

                if (proc?.ExitCode == 0)
                {
                    log?.Invoke($"Legacy task '{task}' retired successfully.");
                }
            }
            catch (Exception ex)
            {
                log?.Invoke($"Could not delete legacy task '{task}': {ex.Message}");
            }
        }

        // Kill legacy HWiNFO64 processes if running
        try
        {
            foreach (var p in Process.GetProcessesByName("HWiNFO64"))
            {
                p.Kill();
                log?.Invoke("Legacy HWiNFO64 process terminated.");
            }
        }
        catch { }
    }

    public static bool RegisterAutoStart(string exePath, Action<string>? log = null)
    {
        try
        {
            var args = $"/Create /TN \"{AgentTaskName}\" /TR \"\\\"{exePath}\\\" --minimized\" /SC ONLOGON /RL HIGHEST /F";
            var psi = new ProcessStartInfo("schtasks.exe", args)
            {
                CreateNoWindow = true,
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true
            };

            using var proc = Process.Start(psi);
            proc?.WaitForExit(5000);

            if (proc?.ExitCode == 0)
            {
                log?.Invoke($"Agent scheduled task '{AgentTaskName}' registered successfully.");
                return true;
            }
        }
        catch (Exception ex)
        {
            log?.Invoke($"Failed to register agent scheduled task: {ex.Message}");
        }

        return false;
    }
}
