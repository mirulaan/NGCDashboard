using System;
using System.Drawing;
using System.Windows.Forms;
using NGCTelemetryAgent.Models;
using NGCTelemetryAgent.Services;

namespace NGCTelemetryAgent.UI;

public class TrayApplicationContext : ApplicationContext
{
    private readonly AgentConfig _config;
    private readonly TelemetryCollectorService _collector;
    private readonly TelemetryDispatcherService _dispatcher;
    private readonly DailyBackupService _backupService;
    private readonly Action<string> _fileLogger;
    private readonly Action<string> _combinedLogger;
    private readonly Action<AgentConfig> _saveConfigAction;
    private readonly NotifyIcon _trayIcon;
    private readonly MainWindowForm _mainWindow;
    private readonly System.Windows.Forms.Timer _scheduleTimer;
    private readonly ToolStripMenuItem _backupMenuItem;
    private DateTime _lastBackupDay = DateTime.MinValue;

    public TrayApplicationContext(
        AgentConfig config,
        TelemetryCollectorService collector,
        TelemetryDispatcherService dispatcher,
        DailyBackupService backupService,
        Action<string> fileLogger,
        Action<AgentConfig> saveConfigAction)
    {
        _config = config;
        _collector = collector;
        _dispatcher = dispatcher;
        _backupService = backupService;
        _fileLogger = fileLogger;
        _saveConfigAction = saveConfigAction;

        _trayIcon = new NotifyIcon
        {
            Icon = SystemIcons.Shield,
            Text = "NGC Edge Telemetry Agent",
            Visible = true
        };

        _combinedLogger = msg =>
        {
            _fileLogger(msg);
            _mainWindow?.AppendLog(msg);
        };

        // Create Main Window (can be shown or kept hidden in tray)
        _mainWindow = new MainWindowForm(
            _config,
            _collector,
            _dispatcher,
            _backupService,
            _combinedLogger,
            _saveConfigAction,
            _trayIcon);

        // Double-click tray icon opens console
        _trayIcon.DoubleClick += (s, e) => _mainWindow.ShowAndBringToFront();

        // Initialize Context Menu
        var contextMenu = new ContextMenuStrip();

        var openConsoleItem = new ToolStripMenuItem("Open Monitoring Console", null, (s, e) => _mainWindow.ShowAndBringToFront())
        {
            Font = new Font(SystemFonts.DefaultFont, FontStyle.Bold)
        };

        var titleItem = new ToolStripMenuItem("NGC Telemetry Agent v1.0.2") { Enabled = false, Font = new Font(SystemFonts.DefaultFont, FontStyle.Italic) };
        var statusItem = new ToolStripMenuItem("Telemetry: Connected");

        var scheduleDisplay = TimeSpan.TryParse(_config.BackupScheduleTime, out var ts)
            ? DateTime.Today.Add(ts).ToString("h:mm tt")
            : _config.BackupScheduleTime;
        _backupMenuItem = new ToolStripMenuItem($"Backup: Scheduled ({scheduleDisplay})");

        var pushNowItem = new ToolStripMenuItem("Force Telemetry Push", null, async (s, e) =>
        {
            _combinedLogger("Operator triggered: Force Telemetry Push");
            statusItem.Text = "Telemetry: Pushing...";
            var ok = await _dispatcher.PushNowAsync();
            statusItem.Text = ok ? $"Telemetry: Connected ({DateTime.Now:h:mm tt})" : "Telemetry: Push Failed";
        });

        var backupNowItem = new ToolStripMenuItem("Run Daily Backup Now", null, async (s, e) =>
        {
            _combinedLogger("Operator triggered: Run Daily Backup Now");
            _backupMenuItem.Text = "Backup: Running...";
            var ok = await _backupService.RunBackupAsync(isManual: true);
            _backupMenuItem.Text = ok ? $"Backup: Success ({DateTime.Now:h:mm tt})" : "Backup: Failed";
        });

        var exitItem = new ToolStripMenuItem("Exit Agent", null, (s, e) =>
        {
            var confirm = MessageBox.Show(
                "Are you sure you want to stop the NGC Telemetry Agent?\nContinuous monitoring and scheduled shift backups will be paused.",
                "Confirm Exit",
                MessageBoxButtons.YesNo,
                MessageBoxIcon.Warning);

            if (confirm == DialogResult.Yes)
            {
                _dispatcher.Stop();
                _trayIcon.Visible = false;
                _mainWindow.AllowActualClose = true;
                _mainWindow.Close();
                Application.Exit();
            }
        });

        contextMenu.Items.AddRange(new ToolStripItem[]
        {
            openConsoleItem,
            new ToolStripSeparator(),
            titleItem,
            statusItem,
            _backupMenuItem,
            new ToolStripSeparator(),
            pushNowItem,
            backupNowItem,
            new ToolStripSeparator(),
            exitItem
        });

        _trayIcon.ContextMenuStrip = contextMenu;

        // Daily Backup Schedule Timer (checks every 60 seconds)
        _scheduleTimer = new System.Windows.Forms.Timer { Interval = 60000 };
        _scheduleTimer.Tick += async (s, e) => await CheckDailyBackupScheduleAsync();
        _scheduleTimer.Start();

        // If StartMinimized is false, show main window on initial launch
        if (!_config.StartMinimized)
        {
            _mainWindow.Show();
        }
    }

    private async Task CheckDailyBackupScheduleAsync()
    {
        var now = DateTime.Now;
        if (now.Date == _lastBackupDay) return;

        if (TimeSpan.TryParse(_config.BackupScheduleTime, out var targetTime))
        {
            if (now.TimeOfDay >= targetTime)
            {
                _lastBackupDay = now.Date;
                _combinedLogger($"Daily backup schedule triggered for target time {_config.BackupScheduleTime}");
                _backupMenuItem.Text = "Backup: Running...";
                var ok = await _backupService.RunBackupAsync(isManual: false);
                _backupMenuItem.Text = ok ? $"Backup: Success ({DateTime.Now:h:mm tt})" : "Backup: Failed";
            }
        }
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing)
        {
            _scheduleTimer?.Dispose();
            _trayIcon?.Dispose();
            _mainWindow?.Dispose();
        }
        base.Dispose(disposing);
    }
}
