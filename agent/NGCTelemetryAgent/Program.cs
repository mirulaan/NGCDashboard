using System.Diagnostics;
using System.Text.Json;
using System.Windows.Forms;
using NGCTelemetryAgent.Models;
using NGCTelemetryAgent.Services;
using NGCTelemetryAgent.UI;

namespace NGCTelemetryAgent;

internal static class Program
{
    private static readonly string AppDir = AppDomain.CurrentDomain.BaseDirectory;
    private static readonly string ConfigPath = Path.Combine(AppDir, "config.json");
    private static readonly string LogPath = Path.Combine(AppDir, "agent.log");

    [STAThread]
    static async Task Main(string[] args)
    {
        ApplicationConfiguration.Initialize();

        // 1. Load or Generate Configuration
        var config = LoadConfiguration();

        // 2. Parse Command-Line Flags
        if (args.Contains("--install"))
        {
            Log("Running installer setup (--install)...");
            var exePath = Process.GetCurrentProcess().MainModule?.FileName ?? "NGCTelemetryAgent.exe";
            TaskSchedulerManager.RegisterAutoStart(exePath, Log);
            TaskSchedulerManager.RetireLegacyTasks(Log);

            // Trigger initial telemetry test push
            var collector = new TelemetryCollectorService(config);
            var backup = new DailyBackupService(config, Log);
            var dispatcher = new TelemetryDispatcherService(config, collector, backup, Log);
            await dispatcher.PushNowAsync();

            Log("Installation completed. Exiting installer process.");
            return;
        }

        if (args.Contains("--backup-now"))
        {
            Log("CLI flag --backup-now received. Running Daily Backup...");
            var backupService = new DailyBackupService(config, Log);
            var success = await backupService.RunBackupAsync(isManual: true);
            Environment.Exit(success ? 0 : 1);
            return;
        }

        // 3. Normal Daemon / Tray Execution
        Log("=================================================");
        Log("Starting NGC Telemetry Agent v1.0.2...");

        // Ensure legacy tasks are retired upon first normal launch
        TaskSchedulerManager.RetireLegacyTasks(Log);

        var telemetryCollector = new TelemetryCollectorService(config);
        var dailyBackup = new DailyBackupService(config, Log);
        var telemetryDispatcher = new TelemetryDispatcherService(config, telemetryCollector, dailyBackup, Log);

        telemetryDispatcher.Start();

        var trayContext = new TrayApplicationContext(config, telemetryCollector, telemetryDispatcher, dailyBackup, Log, SaveConfiguration);
        Application.Run(trayContext);
    }

    private static void SaveConfiguration(AgentConfig config)
    {
        try
        {
            var json = JsonSerializer.Serialize(config, new JsonSerializerOptions { WriteIndented = true });
            File.WriteAllText(ConfigPath, json);
            Log("Agent configuration successfully saved to config.json");
        }
        catch (Exception ex)
        {
            Log($"Failed to save config.json: {ex.Message}");
        }
    }

    private static AgentConfig LoadConfiguration()
    {
        try
        {
            if (File.Exists(ConfigPath))
            {
                var json = File.ReadAllText(ConfigPath);
                var cfg = JsonSerializer.Deserialize<AgentConfig>(json);
                if (cfg != null) return cfg;
            }
        }
        catch (Exception ex)
        {
            Log($"Failed to read config.json, using defaults: {ex.Message}");
        }

        var defaultConfig = new AgentConfig();
        try
        {
            var json = JsonSerializer.Serialize(defaultConfig, new JsonSerializerOptions { WriteIndented = true });
            File.WriteAllText(ConfigPath, json);
        }
        catch { }

        return defaultConfig;
    }

    private static void Log(string message)
    {
        var entry = $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss}] {message}";
        try
        {
            File.AppendAllText(LogPath, entry + Environment.NewLine);
        }
        catch { }
        Console.WriteLine(entry);
    }
}
