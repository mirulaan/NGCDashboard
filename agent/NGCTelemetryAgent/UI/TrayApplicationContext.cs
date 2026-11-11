using System.Drawing;
using System.Windows.Forms;
using NGCTelemetryAgent.Models;
using NGCTelemetryAgent.Services;

namespace NGCTelemetryAgent.UI;

public class TrayApplicationContext : ApplicationContext
{
    private readonly AgentConfig _config;
    private readonly TelemetryDispatcherService _dispatcher;
    private readonly DailyBackupService _backupService;
    private readonly Action<string> _logger;
    private readonly NotifyIcon _trayIcon;
    private readonly System.Windows.Forms.Timer _scheduleTimer;
    private DateTime _lastBackupDay = DateTime.MinValue;

    public TrayApplicationContext(
        AgentConfig config,
        TelemetryDispatcherService dispatcher,
        DailyBackupService backupService,
        Action<string> logger)
    {
        _config = config;
        _dispatcher = dispatcher;
        _backupService = backupService;
        _logger = logger;

        _trayIcon = new NotifyIcon
        {
            Icon = SystemIcons.Shield,
            Text = "NGC Edge Telemetry Agent",
            Visible = true
        };

        // Initialize Context Menu
        var contextMenu = new ContextMenuStrip();
        
        var titleItem = new ToolStripMenuItem("NGC Telemetry Agent v1.0.0") { Enabled = false, Font = new Font(SystemFonts.DefaultFont, FontStyle.Bold) };
        var statusItem = new ToolStripMenuItem("Telemetry: Connected");
        var backupItem = new ToolStripMenuItem("Backup: Idle");

        var pushNowItem = new ToolStripMenuItem("Force Telemetry Push", null, async (s, e) =>
        {
            _logger("Operator triggered: Force Telemetry Push");
            await _dispatcher.PushNowAsync();
        });

        var backupNowItem = new ToolStripMenuItem("Run Daily Backup Now", null, async (s, e) =>
        {
            _logger("Operator triggered: Run Daily Backup Now");
            await _backupService.RunBackupAsync(isManual: true);
        });

        var exitItem = new ToolStripMenuItem("Exit Agent", null, (s, e) =>
        {
            var confirm = MessageBox.Show(
                "Are you sure you want to stop the NGC Telemetry Agent?\nMonitoring and automated backups will be paused.",
                "Confirm Exit",
                MessageBoxButtons.YesNo,
                MessageBoxIcon.Warning);

            if (confirm == DialogResult.Yes)
            {
                _dispatcher.Stop();
                _trayIcon.Visible = false;
                Application.Exit();
            }
        });

        contextMenu.Items.AddRange(new ToolStripItem[]
        {
            titleItem,
            statusItem,
            backupItem,
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
                _logger($"Daily backup schedule triggered for target time {_config.BackupScheduleTime}");
                await _backupService.RunBackupAsync(isManual: false);
            }
        }
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing)
        {
            _scheduleTimer.Dispose();
            _trayIcon.Dispose();
        }
        base.Dispose(disposing);
    }
}
