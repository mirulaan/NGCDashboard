using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.IO;
using System.Linq;
using System.Threading.Tasks;
using System.Windows.Forms;
using NGCTelemetryAgent.Models;
using NGCTelemetryAgent.Services;

namespace NGCTelemetryAgent.UI;

public class MainWindowForm : Form
{
    // Color Palette - Industrial Dark Slate (100% Solid Colors - Zero Alpha for Crisp GDI+ Rendering)
    private static readonly Color BgDark = Color.FromArgb(15, 23, 42);          // #0F172A
    private static readonly Color BgHeader = Color.FromArgb(24, 34, 58);        // #18223A
    private static readonly Color BgCard = Color.FromArgb(30, 41, 59);          // #1E293B
    private static readonly Color BgCardInner = Color.FromArgb(20, 29, 44);     // #141D2C
    private static readonly Color BorderColor = Color.FromArgb(51, 65, 85);      // #334155
    private static readonly Color AccentCyan = Color.FromArgb(56, 189, 248);     // #38BDF8
    private static readonly Color AccentBlue = Color.FromArgb(96, 165, 250);     // #60A5FA
    private static readonly Color SuccessGreen = Color.FromArgb(34, 197, 94);    // #22C55E
    private static readonly Color SuccessBadgeBg = Color.FromArgb(6, 78, 59);    // #064E3B Solid Dark Emerald
    private static readonly Color WarningAmber = Color.FromArgb(245, 158, 11);   // #F59E0B
    private static readonly Color WarningBadgeBg = Color.FromArgb(120, 53, 15);  // #78350F Solid Dark Amber
    private static readonly Color DangerRed = Color.FromArgb(239, 68, 68);       // #EF4444
    private static readonly Color DangerBadgeBg = Color.FromArgb(127, 29, 29);   // #7F1D1D Solid Dark Crimson
    private static readonly Color CyanBadgeBg = Color.FromArgb(12, 74, 96);      // #0C4A60 Solid Dark Cyan
    private static readonly Color TextPrimary = Color.FromArgb(248, 250, 252);   // #F8FAFC
    private static readonly Color TextSecondary = Color.FromArgb(148, 163, 184); // #94A3B8
    private static readonly Color TextMuted = Color.FromArgb(100, 116, 139);     // #64748B

    private readonly AgentConfig _config;
    private readonly TelemetryCollectorService _collector;
    private readonly TelemetryDispatcherService _dispatcher;
    private readonly DailyBackupService _backupService;
    private readonly Action<string> _logger;
    private readonly Action<AgentConfig> _saveConfigAction;
    private readonly NotifyIcon _trayIcon;

    private readonly System.Windows.Forms.Timer _refreshTimer;
    private readonly Stopwatch _appUptime = Stopwatch.StartNew();

    [System.ComponentModel.DesignerSerializationVisibility(System.ComponentModel.DesignerSerializationVisibility.Hidden)]
    public bool AllowActualClose { get; set; } = false;

    // Header Controls
    private Label _lblHeaderIdentity = null!;
    private Label _lblHeaderStatus = null!;
    private Button _btnRefresh = null!;

    // Footer Controls
    private Label _lblFooterPushStatus = null!;
    private Label _lblFooterUptime = null!;
    private Button _btnForcePush = null!;

    // KPI Cards
    private StatCard _cardCpu = null!;
    private StatCard _cardGpu = null!;
    private StatCard _cardSsd = null!;
    private StatCard _cardStorage = null!;

    // Hardware Details Panel
    private Panel _pnlSsdCards = null!;
    private Label _lblCpuTempVal = null!;
    private Label _lblCpuThrottleVal = null!;
    private Label _lblCpuLoadVal = null!;
    private Label _lblCpuHiveVal = null!;
    private Label _lblGpuTempVal = null!;
    private Label _lblGpuPowerVal = null!;
    private Label _lblGpuClockVal = null!;
    private Label _lblGpuVramVal = null!;

    // Vision Details Controls
    private Label _lblVisionPlantLine = null!;
    private Label _lblVisionTier = null!;
    private Label _lblVisionIp = null!;
    private Label _lblVisionSkuBadge = null!;
    private Label _lblVisionAuditTimestamp = null!;
    private Button _btnRefreshVision = null!;
    private TableLayoutPanel _tblVisionModels = null!;
    private TableLayoutPanel _tblVisionVersions = null!;

    // Backup & Settings Controls
    private Label _lblBackupStatus = null!;
    private Label _lblBackupLastTime = null!;
    private Label _lblBackupTarget = null!;
    private Label _lblBackupThrottling = null!;
    private Button _btnTestBackup = null!;
    private TextBox _txtBackupSchedule = null!;
    private TextBox _txtSftpHost = null!;
    private NumericUpDown _numSftpPort = null!;
    private TextBox _txtSftpUser = null!;
    private TextBox _txtSftpPass = null!;
    private TextBox _txtServerUrl = null!;
    private NumericUpDown _numPushInterval = null!;
    private CheckBox _chkAutoStart = null!;
    private CheckBox _chkStartMinimized = null!;
    private Button _btnSaveConfig = null!;
    private Label _lblConfigSavedNotice = null!;

    // Logs Control
    private RichTextBox _rtbLogs = null!;

    public MainWindowForm(
        AgentConfig config,
        TelemetryCollectorService collector,
        TelemetryDispatcherService dispatcher,
        DailyBackupService backupService,
        Action<string> logger,
        Action<AgentConfig> saveConfigAction,
        NotifyIcon trayIcon)
    {
        _config = config;
        _collector = collector;
        _dispatcher = dispatcher;
        _backupService = backupService;
        _logger = logger;
        _saveConfigAction = saveConfigAction;
        _trayIcon = trayIcon;

        InitializeComponent();

        _refreshTimer = new System.Windows.Forms.Timer { Interval = 4000 };
        _refreshTimer.Tick += (s, e) => RefreshDashboardData();
        _refreshTimer.Start();

        // Initial Data Populate
        RefreshDashboardData();
        RefreshVisionAuditingData();
    }

    private void InitializeComponent()
    {
        this.Text = "NGC Telemetry & Edge Diagnostics Console";
        this.Size = new Size(1160, 780);
        this.MinimumSize = new Size(1000, 680);
        this.StartPosition = FormStartPosition.CenterScreen;
        this.BackColor = BgDark;
        this.ForeColor = TextPrimary;
        this.Font = new Font("Segoe UI", 9.5f, FontStyle.Regular);
        this.Icon = SystemIcons.Shield;
        this.DoubleBuffered = true;

        this.FormClosing += MainWindowForm_FormClosing;

        // Top Header
        var pnlHeader = CreateHeaderPanel();
        this.Controls.Add(pnlHeader);

        // Bottom Status Bar
        var pnlFooter = CreateFooterPanel();
        this.Controls.Add(pnlFooter);

        // Center Content Tabs
        var tabControl = CreateTabControl();
        this.Controls.Add(tabControl);

        tabControl.BringToFront();
    }

    private Panel CreateHeaderPanel()
    {
        var pnl = new Panel
        {
            Dock = DockStyle.Top,
            Height = 65,
            BackColor = BgHeader,
            Padding = new Padding(18, 10, 18, 10)
        };
        pnl.Paint += (s, e) =>
        {
            using var pen = new Pen(BorderColor, 1);
            e.Graphics.DrawLine(pen, 0, pnl.Height - 1, pnl.Width, pnl.Height - 1);
        };

        // Left Title, Version Badge, and Active Status
        var pnlTitle = new FlowLayoutPanel
        {
            Dock = DockStyle.Left,
            AutoSize = true,
            FlowDirection = FlowDirection.LeftToRight,
            WrapContents = false,
            BackColor = Color.Transparent
        };

        var lblTitle = new Label
        {
            Text = "NGC TELEMETRY  EDGE MONITOR",
            Font = new Font("Segoe UI", 12f, FontStyle.Bold),
            ForeColor = TextPrimary,
            AutoSize = true,
            Margin = new Padding(0, 8, 10, 0)
        };

        var lblBadge = new Label
        {
            Text = "v1.0.2",
            Font = new Font("Segoe UI", 8.5f, FontStyle.Bold),
            ForeColor = AccentCyan,
            BackColor = CyanBadgeBg,
            Padding = new Padding(6, 3, 6, 3),
            AutoSize = true,
            Margin = new Padding(0, 11, 15, 0)
        };

        _lblHeaderStatus = new Label
        {
            Text = "● ACTIVE RUNNING",
            Font = new Font("Segoe UI", 8.5f, FontStyle.Bold),
            ForeColor = SuccessGreen,
            AutoSize = true,
            Margin = new Padding(0, 13, 0, 0)
        };

        pnlTitle.Controls.Add(lblTitle);
        pnlTitle.Controls.Add(lblBadge);
        pnlTitle.Controls.Add(_lblHeaderStatus);

        // Right side: Identity & Refresh Button
        var pnlRight = new FlowLayoutPanel
        {
            Dock = DockStyle.Right,
            AutoSize = true,
            FlowDirection = FlowDirection.LeftToRight,
            WrapContents = false,
            BackColor = Color.Transparent
        };

        _lblHeaderIdentity = new Label
        {
            Text = "PLANT: -- | LINE: -- | TIER: -- | IP: --",
            Font = new Font("Consolas", 9.5f, FontStyle.Bold),
            ForeColor = TextSecondary,
            BackColor = BgCard,
            Padding = new Padding(10, 6, 10, 6),
            AutoSize = true,
            Margin = new Padding(0, 6, 12, 0)
        };

        _btnRefresh = new Button
        {
            Text = "⟳ Refresh Vitals",
            Font = new Font("Segoe UI", 9f, FontStyle.Bold),
            BackColor = BgCard,
            ForeColor = AccentCyan,
            FlatStyle = FlatStyle.Flat,
            Height = 32,
            Width = 125,
            Cursor = Cursors.Hand,
            Margin = new Padding(0, 6, 0, 0)
        };
        _btnRefresh.FlatAppearance.BorderColor = BorderColor;
        _btnRefresh.Click += (s, e) => RefreshDashboardData();

        pnlRight.Controls.Add(_lblHeaderIdentity);
        pnlRight.Controls.Add(_btnRefresh);

        pnl.Controls.Add(pnlTitle);
        pnl.Controls.Add(pnlRight);

        return pnl;
    }

    private Panel CreateFooterPanel()
    {
        var pnl = new Panel
        {
            Dock = DockStyle.Bottom,
            Height = 40,
            BackColor = BgHeader,
            Padding = new Padding(16, 5, 16, 5)
        };
        pnl.Paint += (s, e) =>
        {
            using var pen = new Pen(BorderColor, 1);
            e.Graphics.DrawLine(pen, 0, 0, pnl.Width, 0);
        };

        _lblFooterPushStatus = new Label
        {
            Text = "Telemetry Dispatch: Initializing...",
            ForeColor = TextSecondary,
            Font = new Font("Segoe UI", 9f),
            Dock = DockStyle.Left,
            AutoSize = true,
            TextAlign = ContentAlignment.MiddleLeft,
            Padding = new Padding(0, 5, 0, 0)
        };

        var pnlRightFooter = new FlowLayoutPanel
        {
            Dock = DockStyle.Right,
            AutoSize = true,
            FlowDirection = FlowDirection.LeftToRight,
            WrapContents = false,
            BackColor = Color.Transparent
        };

        _lblFooterUptime = new Label
        {
            Text = "Uptime: 00:00:00",
            ForeColor = TextMuted,
            Font = new Font("Consolas", 9f),
            AutoSize = true,
            Margin = new Padding(0, 6, 16, 0)
        };

        _btnForcePush = new Button
        {
            Text = "Force Push Now ➔",
            Font = new Font("Segoe UI", 8.5f, FontStyle.Bold),
            BackColor = BgCard,
            ForeColor = AccentCyan,
            FlatStyle = FlatStyle.Flat,
            Height = 28,
            Width = 135,
            Cursor = Cursors.Hand,
            Margin = new Padding(0, 1, 0, 0)
        };
        _btnForcePush.FlatAppearance.BorderColor = BorderColor;
        _btnForcePush.Click += async (s, e) =>
        {
            _btnForcePush.Enabled = false;
            _btnForcePush.Text = "Pushing...";
            try
            {
                var ok = await _dispatcher.PushNowAsync();
                RefreshDashboardData();
            }
            finally
            {
                _btnForcePush.Enabled = true;
                _btnForcePush.Text = "Force Push Now ➔";
            }
        };

        pnlRightFooter.Controls.Add(_lblFooterUptime);
        pnlRightFooter.Controls.Add(_btnForcePush);

        pnl.Controls.Add(_lblFooterPushStatus);
        pnl.Controls.Add(pnlRightFooter);

        return pnl;
    }

    private TabControl CreateTabControl()
    {
        var tabs = new TabControl
        {
            Dock = DockStyle.Fill,
            Padding = new Point(16, 8),
            Font = new Font("Segoe UI", 9.5f, FontStyle.Bold),
            BackColor = BgDark
        };

        // 1. Hardware Vitals Tab
        var tabHardware = new TabPage("  📊 Hardware Vitals & Sensors  ") { BackColor = BgDark };
        tabHardware.Controls.Add(CreateHardwareVitalsView());
        tabs.TabPages.Add(tabHardware);

        // 2. Vision & AI Models Tab
        var tabVision = new TabPage("  🔬 Vision Software & AI Models  ") { BackColor = BgDark };
        tabVision.Controls.Add(CreateVisionAuditingView());
        tabs.TabPages.Add(tabVision);

        // 3. Backup & Settings Tab
        var tabBackup = new TabPage("  💾 Daily Backup & Settings  ") { BackColor = BgDark };
        tabBackup.Controls.Add(CreateBackupSettingsView());
        tabs.TabPages.Add(tabBackup);

        // 4. Logs Tab
        var tabLogs = new TabPage("  📋 Agent Console Logs  ") { BackColor = BgDark };
        tabLogs.Controls.Add(CreateLogsView());
        tabs.TabPages.Add(tabLogs);

        return tabs;
    }

    #region View 1: Hardware Vitals & Sensors
    private Control CreateHardwareVitalsView()
    {
        // Root TableLayout for Tab 1: Row 0 = Top KPI Cards (Fixed 112px), Row 1 = Split Details (Fill 100%)
        var rootTable = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 2,
            BackColor = BgDark,
            Padding = new Padding(12)
        };
        rootTable.RowStyles.Add(new RowStyle(SizeType.Absolute, 112f)); // Row 0: KPI Cards
        rootTable.RowStyles.Add(new RowStyle(SizeType.Percent, 100f));   // Row 1: Split Details Panel

        // --- ROW 0: Top 4 KPI Cards ---
        var pnlKpis = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 4,
            RowCount = 1,
            BackColor = Color.Transparent,
            Margin = new Padding(0, 0, 0, 10)
        };
        pnlKpis.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 25f));
        pnlKpis.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 25f));
        pnlKpis.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 25f));
        pnlKpis.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 25f));

        _cardCpu = new StatCard("CPU PACKAGE TEMP", "-- °C", "Throttling: --", AccentBlue);
        _cardGpu = new StatCard("GPU TEMPERATURE", "-- °C", "Power: -- W | Clock: -- MHz", AccentCyan);
        _cardSsd = new StatCard("NVME SSD HEALTH", "--%", "SMART Health: PASSED", SuccessGreen);
        _cardStorage = new StatCard("STORAGE FREE", "-- GB", "Remaining Capacity", TextPrimary);

        pnlKpis.Controls.Add(_cardCpu, 0, 0);
        pnlKpis.Controls.Add(_cardGpu, 1, 0);
        pnlKpis.Controls.Add(_cardSsd, 2, 0);
        pnlKpis.Controls.Add(_cardStorage, 3, 0);

        rootTable.Controls.Add(pnlKpis, 0, 0);

        // --- ROW 1: Split Details (Left = SSD Health, Right = CPU & GPU Thermals) ---
        var splitTable = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 2,
            RowCount = 1,
            BackColor = Color.Transparent,
            Margin = new Padding(0)
        };
        splitTable.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 54f)); // 54% SSD
        splitTable.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 46f)); // 46% CPU/GPU

        // Left Container: NVMe SSD Endurance & SMART Diagnostics
        _pnlSsdCards = new Panel
        {
            Dock = DockStyle.Fill,
            AutoScroll = true,
            BackColor = Color.Transparent,
            Padding = new Padding(10)
        };
        var grpSsd = CreateSectionCard("NVMe SSD Endurance & SMART Diagnostics", _pnlSsdCards);
        splitTable.Controls.Add(grpSsd, 0, 0);

        // Right Container: Processor & Accelerator Vitals
        var pnlThermalBody = CreateThermalDetailsPanel();
        var grpThermal = CreateSectionCard("Processor & Accelerator Vitals", pnlThermalBody);
        splitTable.Controls.Add(grpThermal, 1, 0);

        rootTable.Controls.Add(splitTable, 0, 1);

        return rootTable;
    }

    private Control CreateThermalDetailsPanel()
    {
        var scroll = new Panel
        {
            Dock = DockStyle.Fill,
            AutoScroll = true,
            Padding = new Padding(14)
        };

        var tbl = new TableLayoutPanel
        {
            Dock = DockStyle.Top,
            AutoSize = true,
            ColumnCount = 2,
            RowCount = 11,
            BackColor = Color.Transparent
        };
        tbl.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 48f));
        tbl.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 52f));

        // CPU Section Header
        var lblCpuHeader = new Label
        {
            Text = "CPU SENSOR PACKAGE (HWiNFO64 VSB REGISTRY)",
            Font = new Font("Segoe UI", 9f, FontStyle.Bold),
            ForeColor = AccentBlue,
            Dock = DockStyle.Fill,
            Height = 26,
            TextAlign = ContentAlignment.MiddleLeft
        };
        tbl.Controls.Add(lblCpuHeader, 0, 0);
        tbl.SetColumnSpan(lblCpuHeader, 2);

        // CPU Rows
        _lblCpuTempVal = CreateValueLabel("-- °C", AccentBlue);
        AddMetricRow(tbl, 1, "CPU Package Temperature:", _lblCpuTempVal);

        _lblCpuThrottleVal = CreateValueLabel("No (Normal)", SuccessGreen);
        AddMetricRow(tbl, 2, "Core Thermal Throttling:", _lblCpuThrottleVal);

        _lblCpuLoadVal = CreateValueLabel("-- %", TextPrimary);
        AddMetricRow(tbl, 3, "CPU Utilization Load:", _lblCpuLoadVal);

        _lblCpuHiveVal = CreateValueLabel("HKCU:\\Software\\HWiNFO64\\VSB", TextMuted, isMonospace: true);
        AddMetricRow(tbl, 4, "Sensor Registry Hive:", _lblCpuHiveVal);

        // Separator
        var sep = new Panel { Height = 1, BackColor = BorderColor, Dock = DockStyle.Fill, Margin = new Padding(0, 12, 0, 12) };
        tbl.Controls.Add(sep, 0, 5);
        tbl.SetColumnSpan(sep, 2);

        // GPU Section Header
        var lblGpuHeader = new Label
        {
            Text = "GPU ACCELERATOR CORE (NVIDIA NVSMI INTERFACE)",
            Font = new Font("Segoe UI", 9f, FontStyle.Bold),
            ForeColor = AccentCyan,
            Dock = DockStyle.Fill,
            Height = 26,
            TextAlign = ContentAlignment.MiddleLeft
        };
        tbl.Controls.Add(lblGpuHeader, 0, 6);
        tbl.SetColumnSpan(lblGpuHeader, 2);

        // GPU Rows
        _lblGpuTempVal = CreateValueLabel("-- °C", AccentCyan);
        AddMetricRow(tbl, 7, "GPU Core Temperature:", _lblGpuTempVal);

        _lblGpuPowerVal = CreateValueLabel("-- W", TextPrimary);
        AddMetricRow(tbl, 8, "Active Board Power Draw:", _lblGpuPowerVal);

        _lblGpuClockVal = CreateValueLabel("-- MHz", TextPrimary);
        AddMetricRow(tbl, 9, "Graphics Clock Frequency:", _lblGpuClockVal);

        _lblGpuVramVal = CreateValueLabel("-- MB / -- MB", TextSecondary);
        AddMetricRow(tbl, 10, "Allocated VRAM Memory:", _lblGpuVramVal);

        scroll.Controls.Add(tbl);
        return scroll;
    }

    private static void AddMetricRow(TableLayoutPanel tbl, int row, string label, Control valControl)
    {
        var lbl = new Label
        {
            Text = label,
            Font = new Font("Segoe UI", 9f),
            ForeColor = TextSecondary,
            Dock = DockStyle.Fill,
            Height = 28,
            TextAlign = ContentAlignment.MiddleLeft
        };

        tbl.Controls.Add(lbl, 0, row);
        tbl.Controls.Add(valControl, 1, row);
    }

    private static Label CreateValueLabel(string text, Color color, bool isMonospace = false)
    {
        return new Label
        {
            Text = text,
            Font = new Font(isMonospace ? "Consolas" : "Segoe UI", 9f, isMonospace ? FontStyle.Regular : FontStyle.Bold),
            ForeColor = color,
            Dock = DockStyle.Fill,
            Height = 28,
            TextAlign = ContentAlignment.MiddleLeft
        };
    }
    #endregion

    #region View 2: Vision Software & AI Models
    private Control CreateVisionAuditingView()
    {
        var rootTable = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 2,
            BackColor = BgDark,
            Padding = new Padding(12)
        };
        rootTable.RowStyles.Add(new RowStyle(SizeType.Absolute, 76f));   // Row 0: Station Topology & Audit Control Bar
        rootTable.RowStyles.Add(new RowStyle(SizeType.Percent, 100f));  // Row 1: Split Content Grid (50% AI Models | 50% Binaries)

        // --- ROW 0: Station Topology & Audit Control Bar ---
        var pnlTopBar = new Panel
        {
            Dock = DockStyle.Fill,
            BackColor = BgCard,
            Margin = new Padding(4, 0, 4, 10),
            Padding = new Padding(14, 8, 14, 8)
        };
        pnlTopBar.Paint += (s, e) =>
        {
            using var pen = new Pen(BorderColor, 1);
            e.Graphics.DrawRectangle(pen, 0, 0, pnlTopBar.Width - 1, pnlTopBar.Height - 1);
        };

        // Left: 4 Station Parameter Badges/Chips
        var pnlLeftChips = new FlowLayoutPanel
        {
            Dock = DockStyle.Left,
            AutoSize = true,
            FlowDirection = FlowDirection.LeftToRight,
            WrapContents = false,
            BackColor = Color.Transparent
        };

        var chipLine = CreateTopologyChip("PRODUCTION LINE", out _lblVisionPlantLine, "Plant -- | Line --", AccentCyan);
        var chipTier = CreateTopologyChip("CAMERA HEAD POSITION", out _lblVisionTier, "Side -- (Tier --)", AccentBlue);
        var chipIp = CreateTopologyChip("HOST NETWORK IP", out _lblVisionIp, "Detecting...", TextPrimary, isMonospace: true);
        var chipSku = CreateTopologyChip("ACTIVE INSPECTION SKU", out _lblVisionSkuBadge, "● COLOR (Mode 0)", SuccessGreen, isBadge: true);

        pnlLeftChips.Controls.Add(chipLine);
        pnlLeftChips.Controls.Add(chipTier);
        pnlLeftChips.Controls.Add(chipIp);
        pnlLeftChips.Controls.Add(chipSku);

        // Right: Audit Timestamp & Dedicated Refresh Button
        var pnlRightControls = new FlowLayoutPanel
        {
            Dock = DockStyle.Right,
            AutoSize = true,
            FlowDirection = FlowDirection.LeftToRight,
            WrapContents = false,
            BackColor = Color.Transparent
        };

        _lblVisionAuditTimestamp = new Label
        {
            Text = "● Audited: --:--:--",
            Font = new Font("Consolas", 9f, FontStyle.Bold),
            ForeColor = TextSecondary,
            AutoSize = true,
            Margin = new Padding(0, 16, 14, 0)
        };

        _btnRefreshVision = new Button
        {
            Text = "⟳ Refresh Audit",
            Font = new Font("Segoe UI", 9f, FontStyle.Bold),
            BackColor = BgCardInner,
            ForeColor = AccentCyan,
            FlatStyle = FlatStyle.Flat,
            Height = 34,
            Width = 135,
            Cursor = Cursors.Hand,
            Margin = new Padding(0, 10, 0, 0)
        };
        _btnRefreshVision.FlatAppearance.BorderColor = BorderColor;
        _btnRefreshVision.Click += (s, e) =>
        {
            _btnRefreshVision.Enabled = false;
            _btnRefreshVision.Text = "Auditing...";
            RefreshVisionAuditingData();
            _btnRefreshVision.Text = "⟳ Refresh Audit";
            _btnRefreshVision.Enabled = true;
            AppendLog($"[Auditing] Manual inspection audit executed at {DateTime.Now:HH:mm:ss}");
        };

        pnlRightControls.Controls.Add(_lblVisionAuditTimestamp);
        pnlRightControls.Controls.Add(_btnRefreshVision);

        pnlTopBar.Controls.Add(pnlLeftChips);
        pnlTopBar.Controls.Add(pnlRightControls);
        rootTable.Controls.Add(pnlTopBar, 0, 0);

        // --- ROW 1: Split Content Grid (50% AI Models | 50% Platform Binaries) ---
        var splitTable = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 2,
            RowCount = 1,
            BackColor = Color.Transparent,
            Margin = new Padding(0)
        };
        splitTable.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50f));
        splitTable.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50f));

        // Left Container: AI Deep Learning Models
        var pnlModelsContainer = new Panel { Dock = DockStyle.Fill, BackColor = Color.Transparent };
        var pnlModelSubHeader = new Panel
        {
            Dock = DockStyle.Top,
            Height = 28,
            BackColor = Color.Transparent,
            Padding = new Padding(12, 4, 12, 0)
        };
        var lblModelSub = new Label
        {
            Dock = DockStyle.Left,
            Font = new Font("Segoe UI", 8.5f, FontStyle.Bold),
            ForeColor = AccentCyan,
            Text = "CONFIGURED INFERENCE PIPELINE STAGES",
            AutoSize = true
        };
        var lblModelPath = new Label
        {
            Dock = DockStyle.Right,
            Font = new Font("Consolas", 8f),
            ForeColor = TextMuted,
            Text = @"Config: C:\Accusys\HAIVIs\appsettings.json",
            AutoSize = true
        };
        pnlModelSubHeader.Controls.Add(lblModelSub);
        pnlModelSubHeader.Controls.Add(lblModelPath);

        _tblVisionModels = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 2,
            AutoScroll = true,
            Padding = new Padding(10, 4, 10, 8),
            BackColor = Color.Transparent
        };
        _tblVisionModels.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 38f));
        _tblVisionModels.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 62f));

        pnlModelsContainer.Controls.Add(_tblVisionModels);
        pnlModelsContainer.Controls.Add(pnlModelSubHeader);

        var grpModels = CreateSectionCard("Accusys HAIVIs — Deep Learning Defect Detection Models", pnlModelsContainer);
        splitTable.Controls.Add(grpModels, 0, 0);

        // Right Container: Optical Inspection Platform Binaries
        var pnlBinariesContainer = new Panel { Dock = DockStyle.Fill, BackColor = Color.Transparent };
        var pnlBinarySubHeader = new Panel
        {
            Dock = DockStyle.Top,
            Height = 28,
            BackColor = Color.Transparent,
            Padding = new Padding(12, 4, 12, 0)
        };
        var lblBinarySub = new Label
        {
            Dock = DockStyle.Left,
            Font = new Font("Segoe UI", 8.5f, FontStyle.Bold),
            ForeColor = AccentBlue,
            Text = "CORE INSPECTION RUNTIME & MODULES",
            AutoSize = true
        };
        var lblBinaryTarget = new Label
        {
            Dock = DockStyle.Right,
            Font = new Font("Consolas", 8f),
            ForeColor = TextMuted,
            Text = "Target Architecture: Win-x64",
            AutoSize = true
        };
        pnlBinarySubHeader.Controls.Add(lblBinarySub);
        pnlBinarySubHeader.Controls.Add(lblBinaryTarget);

        _tblVisionVersions = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 3,
            AutoScroll = true,
            Padding = new Padding(10, 4, 10, 8),
            BackColor = Color.Transparent
        };
        _tblVisionVersions.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 35f));
        _tblVisionVersions.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 42f));
        _tblVisionVersions.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 23f));

        pnlBinariesContainer.Controls.Add(_tblVisionVersions);
        pnlBinariesContainer.Controls.Add(pnlBinarySubHeader);

        var grpSoftware = CreateSectionCard("Industrial Optical Inspection Platform Binaries", pnlBinariesContainer);
        splitTable.Controls.Add(grpSoftware, 1, 0);

        rootTable.Controls.Add(splitTable, 0, 1);
        return rootTable;
    }

    private static Panel CreateTopologyChip(string category, out Label valueLabel, string initialVal, Color valColor, bool isMonospace = false, bool isBadge = false)
    {
        var pnl = new FlowLayoutPanel
        {
            FlowDirection = FlowDirection.TopDown,
            WrapContents = false,
            AutoSize = true,
            BackColor = Color.Transparent,
            Margin = new Padding(0, 4, 28, 4),
            Padding = new Padding(0)
        };

        var lblCat = new Label
        {
            Text = category,
            Font = new Font("Segoe UI", 6.8f, FontStyle.Bold),
            ForeColor = TextMuted,
            AutoSize = true,
            Margin = new Padding(0, 0, 0, 3)
        };

        valueLabel = new Label
        {
            Text = initialVal,
            Font = new Font(isMonospace ? "Consolas" : "Segoe UI", 9.5f, FontStyle.Bold),
            ForeColor = valColor,
            AutoSize = true,
            Margin = new Padding(0)
        };

        if (isBadge)
        {
            valueLabel.BackColor = CyanBadgeBg;
            valueLabel.Padding = new Padding(6, 2, 6, 2);
        }

        pnl.Controls.Add(lblCat);
        pnl.Controls.Add(valueLabel);
        return pnl;
    }
    #endregion

    #region View 3: Daily Backup & Settings
    private Control CreateBackupSettingsView()
    {
        var rootTable = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 2,
            BackColor = BgDark,
            Padding = new Padding(12)
        };
        rootTable.RowStyles.Add(new RowStyle(SizeType.Absolute, 160f)); // Row 0: Operational Status Card
        rootTable.RowStyles.Add(new RowStyle(SizeType.Percent, 100f));  // Row 1: Settings Form

        // Section A: Throttled Daily Backup Runtime Status
        var pnlStatusInner = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 2,
            RowCount = 1,
            Padding = new Padding(14)
        };
        pnlStatusInner.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 65f));
        pnlStatusInner.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 35f));

        var pnlStatusDetails = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            FlowDirection = FlowDirection.TopDown,
            WrapContents = false
        };

        _lblBackupStatus = new Label
        {
            Text = "Operational Status: IDLE / READY",
            Font = new Font("Segoe UI", 11f, FontStyle.Bold),
            ForeColor = SuccessGreen,
            AutoSize = true,
            Margin = new Padding(0, 0, 0, 5)
        };

        _lblBackupLastTime = new Label
        {
            Text = "Last Shift Backup: None recorded during this session",
            Font = new Font("Segoe UI", 9.5f),
            ForeColor = TextSecondary,
            AutoSize = true,
            Margin = new Padding(0, 0, 0, 4)
        };

        _lblBackupTarget = new Label
        {
            Text = "Archive & SFTP Target: C:\\Backup\\<line><tier>.zip ➔ /BACKUP/Plant_<N>/",
            Font = new Font("Consolas", 9f),
            ForeColor = TextMuted,
            AutoSize = true,
            Margin = new Padding(0, 0, 0, 4)
        };

        _lblBackupThrottling = new Label
        {
            Text = "Throttling Policy: Process Priority = IDLE | Core Affinity = Single Core (0% Inspection Impact)",
            Font = new Font("Segoe UI", 9f),
            ForeColor = AccentCyan,
            AutoSize = true
        };

        pnlStatusDetails.Controls.Add(_lblBackupStatus);
        pnlStatusDetails.Controls.Add(_lblBackupLastTime);
        pnlStatusDetails.Controls.Add(_lblBackupTarget);
        pnlStatusDetails.Controls.Add(_lblBackupThrottling);

        // Test Backup Button Column
        var pnlAction = new Panel { Dock = DockStyle.Fill };
        _btnTestBackup = new Button
        {
            Text = "▶  Run Backup Test Now",
            Font = new Font("Segoe UI", 10.5f, FontStyle.Bold),
            BackColor = Color.FromArgb(16, 185, 129),
            ForeColor = Color.White,
            FlatStyle = FlatStyle.Flat,
            Height = 48,
            Width = 230,
            Cursor = Cursors.Hand,
            Anchor = AnchorStyles.None
        };
        _btnTestBackup.FlatAppearance.BorderSize = 0;
        _btnTestBackup.Location = new Point((pnlAction.Width - _btnTestBackup.Width) / 2, (pnlAction.Height - _btnTestBackup.Height) / 2);
        _btnTestBackup.Click += async (s, e) =>
        {
            _btnTestBackup.Enabled = false;
            _lblBackupStatus.Text = "Operational Status: RUNNING TEST BACKUP...";
            _lblBackupStatus.ForeColor = WarningAmber;
            try
            {
                var ok = await _backupService.RunBackupAsync(isManual: true);
                _lblBackupStatus.Text = ok ? "Operational Status: TEST BACKUP COMPLETED (SUCCESS)" : "Operational Status: BACKUP TEST FAILED";
                _lblBackupStatus.ForeColor = ok ? SuccessGreen : DangerRed;
                _lblBackupLastTime.Text = $"Last Shift Backup: {DateTime.Now:yyyy-MM-dd HH:mm:ss} (Manual Test)";
            }
            finally
            {
                _btnTestBackup.Enabled = true;
            }
        };

        pnlStatusInner.Controls.Add(pnlStatusDetails, 0, 0);
        pnlStatusInner.Controls.Add(_btnTestBackup, 1, 0);

        var grpBackupStatus = CreateSectionCard("Automated Throttled Backup Operations", pnlStatusInner);
        rootTable.Controls.Add(grpBackupStatus, 0, 0);

        // Section B: Configuration & Settings Form
        var pnlForm = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 4,
            RowCount = 5,
            Padding = new Padding(15),
            AutoScroll = true
        };
        pnlForm.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 180f));
        pnlForm.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 45f));
        pnlForm.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 180f));
        pnlForm.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 55f));

        // Row 1: Schedule Time & Server URL
        pnlForm.Controls.Add(CreateFormLabel("Daily Backup Time:"), 0, 0);
        _txtBackupSchedule = CreateFormTextBox(_config.BackupScheduleTime);
        pnlForm.Controls.Add(_txtBackupSchedule, 1, 0);

        pnlForm.Controls.Add(CreateFormLabel("Dashboard Server URL:"), 2, 0);
        _txtServerUrl = CreateFormTextBox(_config.ServerUrl);
        pnlForm.Controls.Add(_txtServerUrl, 3, 0);

        // Row 2: SFTP Host & Push Interval
        pnlForm.Controls.Add(CreateFormLabel("SFTP Host Address:"), 0, 1);
        _txtSftpHost = CreateFormTextBox(_config.SftpHost);
        pnlForm.Controls.Add(_txtSftpHost, 1, 1);

        pnlForm.Controls.Add(CreateFormLabel("Push Interval (sec):"), 2, 1);
        _numPushInterval = new NumericUpDown
        {
            Minimum = 30,
            Maximum = 3600,
            Value = _config.PushIntervalSeconds,
            BackColor = Color.FromArgb(15, 23, 42),
            ForeColor = TextPrimary,
            Height = 30,
            Dock = DockStyle.Fill
        };
        pnlForm.Controls.Add(_numPushInterval, 3, 1);

        // Row 3: SFTP Port & SFTP Username
        pnlForm.Controls.Add(CreateFormLabel("SFTP Port:"), 0, 2);
        _numSftpPort = new NumericUpDown
        {
            Minimum = 1,
            Maximum = 65535,
            Value = _config.SftpPort,
            BackColor = Color.FromArgb(15, 23, 42),
            ForeColor = TextPrimary,
            Height = 30,
            Dock = DockStyle.Fill
        };
        pnlForm.Controls.Add(_numSftpPort, 1, 2);

        pnlForm.Controls.Add(CreateFormLabel("SFTP Username:"), 2, 2);
        _txtSftpUser = CreateFormTextBox(_config.SftpUser);
        pnlForm.Controls.Add(_txtSftpUser, 3, 2);

        // Row 4: SFTP Password & Startup options
        pnlForm.Controls.Add(CreateFormLabel("SFTP Password:"), 0, 3);
        _txtSftpPass = CreateFormTextBox(_config.SftpPass);
        _txtSftpPass.UseSystemPasswordChar = true;
        pnlForm.Controls.Add(_txtSftpPass, 1, 3);

        var pnlCheckboxes = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.LeftToRight };
        _chkAutoStart = new CheckBox { Text = "Auto-Start with Windows", Checked = _config.AutoStart, AutoSize = true, ForeColor = TextSecondary, Margin = new Padding(0, 5, 20, 0) };
        _chkStartMinimized = new CheckBox { Text = "Start Minimized in Tray", Checked = _config.StartMinimized, AutoSize = true, ForeColor = TextSecondary, Margin = new Padding(0, 5, 0, 0) };
        pnlCheckboxes.Controls.Add(_chkAutoStart);
        pnlCheckboxes.Controls.Add(_chkStartMinimized);
        pnlForm.Controls.Add(pnlCheckboxes, 3, 3);

        // Row 5: Save Button & Notice
        var pnlSave = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.LeftToRight, Margin = new Padding(0, 10, 0, 0) };
        _btnSaveConfig = new Button
        {
            Text = "💾 Save Configuration",
            Font = new Font("Segoe UI", 9.5f, FontStyle.Bold),
            BackColor = AccentCyan,
            ForeColor = Color.Black,
            FlatStyle = FlatStyle.Flat,
            Height = 36,
            Width = 190,
            Cursor = Cursors.Hand
        };
        _btnSaveConfig.FlatAppearance.BorderSize = 0;
        _btnSaveConfig.Click += (s, e) => SaveSettings();

        _lblConfigSavedNotice = new Label
        {
            Text = "",
            ForeColor = SuccessGreen,
            Font = new Font("Segoe UI", 9.5f, FontStyle.Bold),
            AutoSize = true,
            Margin = new Padding(15, 8, 0, 0)
        };

        pnlSave.Controls.Add(_btnSaveConfig);
        pnlSave.Controls.Add(_lblConfigSavedNotice);
        pnlForm.Controls.Add(pnlSave, 1, 4);

        var grpConfig = CreateSectionCard("Edge Agent & Schedule Configuration", pnlForm);
        rootTable.Controls.Add(grpConfig, 0, 1);

        return rootTable;
    }

    private void SaveSettings()
    {
        try
        {
            _config.BackupScheduleTime = _txtBackupSchedule.Text.Trim();
            _config.ServerUrl = _txtServerUrl.Text.Trim();
            _config.SftpHost = _txtSftpHost.Text.Trim();
            _config.SftpPort = (int)_numSftpPort.Value;
            _config.SftpUser = _txtSftpUser.Text.Trim();
            _config.SftpPass = _txtSftpPass.Text;
            _config.PushIntervalSeconds = (int)_numPushInterval.Value;
            _config.AutoStart = _chkAutoStart.Checked;
            _config.StartMinimized = _chkStartMinimized.Checked;

            _saveConfigAction(_config);

            _lblConfigSavedNotice.Text = "✓ Saved to config.json";
            _lblConfigSavedNotice.ForeColor = SuccessGreen;

            Task.Delay(3000).ContinueWith(_ =>
            {
                if (!this.IsDisposed && this.IsHandleCreated)
                    this.Invoke(new Action(() => _lblConfigSavedNotice.Text = ""));
            });
        }
        catch (Exception ex)
        {
            _lblConfigSavedNotice.Text = $"Error: {ex.Message}";
            _lblConfigSavedNotice.ForeColor = DangerRed;
        }
    }
    #endregion

    #region View 4: Live Agent Logs
    private Control CreateLogsView()
    {
        var pnl = new Panel { Dock = DockStyle.Fill, Padding = new Padding(14) };

        var toolbar = new FlowLayoutPanel
        {
            Dock = DockStyle.Top,
            Height = 38,
            FlowDirection = FlowDirection.LeftToRight,
            BackColor = Color.Transparent
        };

        var btnClear = new Button
        {
            Text = "Clear View",
            BackColor = BgCard,
            ForeColor = TextSecondary,
            FlatStyle = FlatStyle.Flat,
            Height = 30,
            Width = 100,
            Cursor = Cursors.Hand
        };
        btnClear.FlatAppearance.BorderColor = BorderColor;
        btnClear.Click += (s, e) => _rtbLogs.Clear();

        var btnCopy = new Button
        {
            Text = "Copy All Logs",
            BackColor = BgCard,
            ForeColor = TextSecondary,
            FlatStyle = FlatStyle.Flat,
            Height = 30,
            Width = 120,
            Cursor = Cursors.Hand,
            Margin = new Padding(10, 0, 0, 0)
        };
        btnCopy.FlatAppearance.BorderColor = BorderColor;
        btnCopy.Click += (s, e) =>
        {
            if (!string.IsNullOrEmpty(_rtbLogs.Text))
            {
                Clipboard.SetText(_rtbLogs.Text);
                MessageBox.Show("Logs copied to clipboard.", "NGC Agent", MessageBoxButtons.OK, MessageBoxIcon.Information);
            }
        };

        toolbar.Controls.Add(btnClear);
        toolbar.Controls.Add(btnCopy);

        _rtbLogs = new RichTextBox
        {
            Dock = DockStyle.Fill,
            BackColor = Color.FromArgb(2, 6, 23),
            ForeColor = Color.FromArgb(226, 232, 240),
            Font = new Font("Consolas", 9.5f),
            ReadOnly = true,
            BorderStyle = BorderStyle.None
        };

        pnl.Controls.Add(_rtbLogs);
        pnl.Controls.Add(toolbar);

        return pnl;
    }

    public void AppendLog(string message)
    {
        if (this.IsDisposed || !this.IsHandleCreated) return;

        this.BeginInvoke(new Action(() =>
        {
            try
            {
                Color color = TextSecondary;
                if (message.Contains("[ERROR]")) color = DangerRed;
                else if (message.Contains("[WARNING]")) color = WarningAmber;
                else if (message.Contains("[SUCCESS]")) color = SuccessGreen;
                else if (message.Contains("[Dispatcher]")) color = AccentCyan;

                _rtbLogs.SelectionStart = _rtbLogs.TextLength;
                _rtbLogs.SelectionLength = 0;
                _rtbLogs.SelectionColor = color;
                _rtbLogs.AppendText(message + Environment.NewLine);
                _rtbLogs.ScrollToCaret();
            }
            catch { }
        }));
    }
    #endregion

    #region Data Refresh Logic
    private void RefreshDashboardData()
    {
        try
        {
            var payload = _collector.Collect(_backupService.CurrentStatus);

            // 1. Header Updates
            _lblHeaderIdentity.Text = $"PLANT: {payload.Plant} | LINE: {payload.Line} | TIER: {payload.Tier1}-{payload.Tier2} | IP: {(string.IsNullOrEmpty(payload.IpAddress) ? "Detecting..." : payload.IpAddress)}";

            // 2. Hardware KPI Cards
            var cpuTempStr = payload.PcThermal.GetValueOrDefault("CPU Temp", "N/A");
            var cpuThrottle = payload.PcThermal.GetValueOrDefault("CPU Thermal Throttle", "No");
            _cardCpu.Value = $"{cpuTempStr} °C";
            _cardCpu.Subtitle = $"Throttling: {cpuThrottle}";
            _cardCpu.ValueColor = cpuThrottle == "Yes" ? DangerRed : (int.TryParse(cpuTempStr, out var ct) && ct > 75 ? WarningAmber : AccentBlue);

            var gpuTempStr = payload.PcThermal.GetValueOrDefault("GPU Temp", "N/A");
            var gpuPower = payload.PcThermal.GetValueOrDefault("GPU Power", "N/A");
            var gpuClock = payload.PcThermal.GetValueOrDefault("GPU Clock", "N/A");
            _cardGpu.Value = gpuTempStr != "N/A" ? $"{gpuTempStr} °C" : "N/A";
            _cardGpu.Subtitle = $"Power: {gpuPower}W | Clock: {gpuClock}MHz";

            var primarySsd = payload.SsdStatus.FirstOrDefault();
            if (primarySsd != null)
            {
                var wear = primarySsd.PercentageUsed;
                _cardSsd.Value = $"{100 - wear}% Life";
                _cardSsd.Subtitle = $"Wear: {wear}% | Health: {primarySsd.HealthStatus}";
                _cardSsd.ValueColor = wear > 80 ? DangerRed : (wear > 60 ? WarningAmber : SuccessGreen);

                _cardStorage.Value = $"{primarySsd.RemainingSpaceGB:F1} GB";
                _cardStorage.Subtitle = $"Total: {primarySsd.TotalSpaceGB:F1} GB ({Math.Round((primarySsd.RemainingSpaceGB / Math.Max(1, primarySsd.TotalSpaceGB)) * 100)}% Free)";
            }

            // 3. Populate SSD Drive Cards in Natural Drive Letter Order
            _pnlSsdCards.SuspendLayout();
            _pnlSsdCards.Controls.Clear();
            
            // Add drives in reverse order so that when docked Top, Drive C is at the top, followed by Drive D, etc.
            var orderedSsds = payload.SsdStatus.OrderByDescending(s => s.DriveLetter).ToList();
            foreach (var ssd in orderedSsds)
            {
                var ssdRow = CreateSsdRow(ssd);
                _pnlSsdCards.Controls.Add(ssdRow);
            }
            _pnlSsdCards.ResumeLayout();

            // 4. Update CPU & GPU Details Panel
            _lblCpuTempVal.Text = $"{cpuTempStr} °C";
            _lblCpuTempVal.ForeColor = int.TryParse(cpuTempStr, out var cTemp) && cTemp > 75 ? WarningAmber : AccentBlue;

            _lblCpuThrottleVal.Text = cpuThrottle == "Yes" ? "YES (THROTTLING DETECTED)" : "No (Normal)";
            _lblCpuThrottleVal.ForeColor = cpuThrottle == "Yes" ? DangerRed : SuccessGreen;

            _lblCpuLoadVal.Text = $"{payload.PcThermal.GetValueOrDefault("CPU Load Percent", "N/A")} %";

            _lblGpuTempVal.Text = gpuTempStr != "N/A" ? $"{gpuTempStr} °C" : "N/A";
            _lblGpuPowerVal.Text = gpuPower != "N/A" ? $"{gpuPower} W" : "N/A";
            _lblGpuClockVal.Text = gpuClock != "N/A" ? $"{gpuClock} MHz" : "N/A";

            var vramUsed = payload.PcThermal.GetValueOrDefault("GPU VRAM Used MB", "0");
            var vramTotal = payload.PcThermal.GetValueOrDefault("GPU VRAM Total MB", "0");
            _lblGpuVramVal.Text = $"{vramUsed} MB / {vramTotal} MB";

            // Note: Vision Software & AI Models is intentionally decoupled from this continuous 4s timer
            // and only refreshes on console open or on-demand via the 'Refresh Audit' button.

            // 5. Footer Status
            var lastPush = _dispatcher.LastSuccessfulPush;
            var pushErr = _dispatcher.LastPushError;
            if (lastPush.HasValue)
            {
                _lblFooterPushStatus.Text = $"Telemetry Dispatch: Active ➔ {_config.ServerUrl}  |  Last Push: {lastPush.Value:HH:mm:ss} (OK)";
                _lblFooterPushStatus.ForeColor = SuccessGreen;
            }
            else if (!string.IsNullOrEmpty(pushErr))
            {
                _lblFooterPushStatus.Text = $"Telemetry Dispatch Warning: {pushErr}";
                _lblFooterPushStatus.ForeColor = WarningAmber;
            }

            var up = _appUptime.Elapsed;
            _lblFooterUptime.Text = $"Uptime: {up.Hours:D2}:{up.Minutes:D2}:{up.Seconds:D2}";
        }
        catch { }
    }

    public void RefreshVisionAuditingData(TelemetryPayload? payload = null)
    {
        if (this.IsDisposed) return;
        if (this.InvokeRequired)
        {
            this.BeginInvoke(new Action(() => RefreshVisionAuditingData(payload)));
            return;
        }

        try
        {
            payload ??= _collector.Collect(_backupService.CurrentStatus);

            // 1. Station Topology Parameter Chips
            _lblVisionPlantLine.Text = $"Plant {payload.Plant}  •  Line {payload.Line}";
            _lblVisionTier.Text = $"Side {payload.Tier1} (Tier {payload.Tier2})";
            _lblVisionIp.Text = string.IsNullOrEmpty(payload.IpAddress) ? "172.16.x.x (Detecting)" : payload.IpAddress;

            var sku = payload.ConfigModel.GetValueOrDefault("SkuMode", "0");
            var skuText = sku switch { "1" => "BLACK", "2" => "WHITE", _ => "COLOR" };
            _lblVisionSkuBadge.Text = $"● {skuText} (Mode {sku})";
            _lblVisionSkuBadge.ForeColor = sku switch
            {
                "1" => TextPrimary,
                "2" => AccentCyan,
                _ => SuccessGreen
            };

            // 2. Audit Timestamp
            _lblVisionAuditTimestamp.Text = $"● Audited: {DateTime.Now:HH:mm:ss}";
            _lblVisionAuditTimestamp.ForeColor = SuccessGreen;

            // 3. AI Inference Models Table
            _tblVisionModels.SuspendLayout();
            _tblVisionModels.Controls.Clear();
            _tblVisionModels.RowCount = 0;
            _tblVisionModels.RowStyles.Clear();
            AddTableRow(_tblVisionModels, "Inspection Stage Target", "Configured Architecture / Weights File", isHeader: true);

            var modelEntries = payload.ConfigModel.Where(k => k.Key != "SkuMode").ToList();
            if (modelEntries.Count == 0)
            {
                AddTableRow(_tblVisionModels, "No Active Stages", "(No model configuration found in appsettings.json)");
            }
            else
            {
                foreach (var kvp in modelEntries)
                {
                    AddTableRow(_tblVisionModels, kvp.Key, kvp.Value);
                }
            }
            _tblVisionModels.ResumeLayout();

            // 4. Industrial Software Binaries Table
            _tblVisionVersions.SuspendLayout();
            _tblVisionVersions.Controls.Clear();
            _tblVisionVersions.RowCount = 0;
            _tblVisionVersions.RowStyles.Clear();
            AddThreeColRow(_tblVisionVersions, "Component Target", "Audited Version / Timestamp", "Audit Status", isHeader: true);

            var versionEntries = payload.Version.ToList();
            if (versionEntries.Count == 0)
            {
                AddThreeColRow(_tblVisionVersions, "Binaries", "Not detected", "WARNING");
            }
            else
            {
                foreach (var kvp in versionEntries)
                {
                    var isOk = kvp.Value != "N/A" && kvp.Value != "Unknown" && kvp.Value != "None";
                    AddThreeColRow(_tblVisionVersions, kvp.Key, kvp.Value, isOk ? "VERIFIED" : "WARNING");
                }
            }
            _tblVisionVersions.ResumeLayout();
        }
        catch (Exception ex)
        {
            _logger($"[ERROR] Vision Auditing refresh failed: {ex.Message}");
        }
    }

    private Control CreateSsdRow(SsdStatus ssd)
    {
        var card = new Panel
        {
            Dock = DockStyle.Top,
            Height = 142,
            BackColor = BgCardInner,
            Margin = new Padding(0, 0, 0, 12),
            Padding = new Padding(12)
        };

        int lifePct = Math.Clamp(100 - ssd.PercentageUsed, 0, 100);

        card.Paint += (s, e) =>
        {
            using var pen = new Pen(BorderColor, 1);
            e.Graphics.DrawRectangle(pen, 0, 0, card.Width - 1, card.Height - 1);

            // Draw Endurance Remaining Life Bar
            int barX = 14;
            int barY = 64;
            int barW = Math.Max(50, card.Width - 28);
            int barH = 7;

            // Track background
            using var brushTrack = new SolidBrush(Color.FromArgb(15, 23, 42));
            e.Graphics.FillRectangle(brushTrack, barX, barY, barW, barH);
            using var penTrack = new Pen(Color.FromArgb(40, 50, 70), 1);
            e.Graphics.DrawRectangle(penTrack, barX, barY, barW, barH);

            // Fill bar
            int fillW = (int)(barW * (lifePct / 100.0));
            Color barColor = lifePct > 40 ? SuccessGreen : (lifePct > 15 ? WarningAmber : DangerRed);

            using var brushFill = new SolidBrush(barColor);
            e.Graphics.FillRectangle(brushFill, barX, barY, fillW, barH);
        };

        card.Resize += (s, e) => card.Invalidate();

        // Row 1: Drive Letter + Model Name on left, Solid Badge on right
        var pnlHeader = new Panel { Dock = DockStyle.Top, Height = 26, BackColor = Color.Transparent };

        var lblDrive = new Label
        {
            Text = $"💾  {ssd.DriveLetter}   {ssd.DiskModel}",
            Font = new Font("Segoe UI", 10.5f, FontStyle.Bold),
            ForeColor = TextPrimary,
            Dock = DockStyle.Left,
            AutoSize = true,
            TextAlign = ContentAlignment.MiddleLeft
        };

        bool isPassed = ssd.HealthStatus == "PASSED";
        var badge = new Label
        {
            Text = isPassed ? "● HEALTHY (PASSED)" : "▲ ATTENTION (FAILED)",
            Font = new Font("Segoe UI", 8.5f, FontStyle.Bold),
            ForeColor = isPassed ? Color.FromArgb(52, 211, 153) : Color.FromArgb(248, 113, 113),
            BackColor = isPassed ? SuccessBadgeBg : DangerBadgeBg,
            Padding = new Padding(8, 3, 8, 3),
            Dock = DockStyle.Right,
            AutoSize = true,
            TextAlign = ContentAlignment.MiddleCenter
        };

        pnlHeader.Controls.Add(lblDrive);
        pnlHeader.Controls.Add(badge);

        // Row 2: Endurance Wear, Remaining Life, Temp, Capacity (Clean & compact)
        double freeSpace = ssd.RemainingSpaceGB;
        double totalSpace = Math.Max(1, ssd.TotalSpaceGB);
        int freePct = (int)Math.Round((freeSpace / totalSpace) * 100);

        var lblStats = new Label
        {
            Text = $"Wear: {ssd.PercentageUsed}% (Life: {lifePct}%)   |   Temp: {ssd.TemperatureCelsius} °C   |   Free: {freeSpace:F1} GB / {totalSpace:F1} GB ({freePct}% free)",
            Font = new Font("Segoe UI", 9f),
            ForeColor = TextSecondary,
            Location = new Point(14, 38),
            AutoSize = true
        };

        // Row 4: SMART Metrics 2x2 Responsive Grid (Never clips on narrow/resized windows)
        var tblSmart = new TableLayoutPanel
        {
            Location = new Point(12, 78),
            Width = card.Width - 24,
            Height = 52,
            ColumnCount = 2,
            RowCount = 2,
            BackColor = Color.Transparent,
            Anchor = AnchorStyles.Top | AnchorStyles.Left | AnchorStyles.Right
        };
        tblSmart.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50f));
        tblSmart.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50f));
        tblSmart.RowStyles.Add(new RowStyle(SizeType.Percent, 50f));
        tblSmart.RowStyles.Add(new RowStyle(SizeType.Percent, 50f));

        var lblSpare = new Label
        {
            Text = $"Available Spare:  {ssd.AvailableSparePercent}%",
            Font = new Font("Consolas", 8.5f),
            ForeColor = ssd.AvailableSparePercent >= 90 ? SuccessGreen : WarningAmber,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleLeft
        };

        int critWarn = ssd.CriticalWarning ?? 0;
        var lblCrit = new Label
        {
            Text = $"Critical Warning: 0x{critWarn:X2}",
            Font = new Font("Consolas", 8.5f),
            ForeColor = critWarn > 0 ? DangerRed : TextSecondary,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleLeft
        };

        long mediaErrors = ssd.MediaErrors ?? 0;
        var lblMedia = new Label
        {
            Text = $"Media Errors:     {mediaErrors}",
            Font = new Font("Consolas", 8.5f),
            ForeColor = mediaErrors > 0 ? DangerRed : SuccessGreen,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleLeft
        };

        long unsafeOff = ssd.UnsafeShutdowns ?? 0;
        var lblUnsafe = new Label
        {
            Text = $"Unsafe Shutdowns: {unsafeOff}",
            Font = new Font("Consolas", 8.5f),
            ForeColor = unsafeOff > 50 ? WarningAmber : TextPrimary,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleLeft
        };

        tblSmart.Controls.Add(lblSpare, 0, 0);
        tblSmart.Controls.Add(lblCrit, 1, 0);
        tblSmart.Controls.Add(lblMedia, 0, 1);
        tblSmart.Controls.Add(lblUnsafe, 1, 1);

        card.Controls.Add(pnlHeader);
        card.Controls.Add(lblStats);
        card.Controls.Add(tblSmart);

        // Wrapper container with 12px bottom spacing so multiple drives have a clean gap
        var wrapper = new Panel
        {
            Dock = DockStyle.Top,
            Height = 154,
            BackColor = Color.Transparent,
            Padding = new Padding(0, 0, 0, 12)
        };
        wrapper.Controls.Add(card);

        return wrapper;
    }

    private static void AddTableRow(TableLayoutPanel tbl, string col1, string col2, bool isHeader = false)
    {
        var row = tbl.RowCount++;
        tbl.RowStyles.Add(new RowStyle(SizeType.Absolute, isHeader ? 30f : 28f));

        var l1 = new Label
        {
            Text = isHeader ? col1 : $"▪  {col1}",
            Font = new Font("Segoe UI", isHeader ? 9f : 8.8f, isHeader ? FontStyle.Bold : FontStyle.Regular),
            ForeColor = isHeader ? AccentCyan : TextPrimary,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleLeft
        };

        bool isUnset = string.IsNullOrWhiteSpace(col2) || col2.Equals("not set", StringComparison.OrdinalIgnoreCase);
        var l2 = new Label
        {
            Text = isUnset ? "(Not Configured)" : col2,
            Font = new Font(isHeader ? "Segoe UI" : (isUnset ? "Segoe UI" : "Consolas"), isHeader ? 9f : 8.5f, isHeader ? FontStyle.Bold : (isUnset ? FontStyle.Italic : FontStyle.Regular)),
            ForeColor = isHeader ? AccentCyan : (isUnset ? TextMuted : TextSecondary),
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleLeft
        };

        tbl.Controls.Add(l1, 0, row);
        tbl.Controls.Add(l2, 1, row);
    }

    private static void AddThreeColRow(TableLayoutPanel tbl, string col1, string col2, string col3, bool isHeader = false)
    {
        var row = tbl.RowCount++;
        tbl.RowStyles.Add(new RowStyle(SizeType.Absolute, isHeader ? 30f : 28f));

        string friendlyName = col1 switch
        {
            "solw" => "Workspace Solution (.solw)",
            "MiddleWare" => "MiddleWare Service Engine",
            "DIMR" => "DIMR Inspection Engine",
            "HAIVIs" => "Accusys HAIVIs Core App",
            "MainApp" => "Station Main Application",
            "VisionAlg" => "Vision Algorithms Library",
            _ => col1
        };

        var l1 = new Label
        {
            Text = isHeader ? col1 : $"▪  {friendlyName}",
            Font = new Font("Segoe UI", isHeader ? 9f : 8.8f, isHeader ? FontStyle.Bold : FontStyle.Regular),
            ForeColor = isHeader ? AccentCyan : TextPrimary,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleLeft
        };

        var l2 = new Label
        {
            Text = col2,
            Font = new Font(isHeader ? "Segoe UI" : "Consolas", isHeader ? 9f : 8.5f, isHeader ? FontStyle.Bold : FontStyle.Regular),
            ForeColor = isHeader ? AccentCyan : TextSecondary,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleLeft
        };

        Control c3;
        if (isHeader)
        {
            c3 = new Label
            {
                Text = col3,
                Font = new Font("Segoe UI", 9f, FontStyle.Bold),
                ForeColor = AccentCyan,
                Dock = DockStyle.Fill,
                TextAlign = ContentAlignment.MiddleLeft
            };
        }
        else
        {
            bool isVerified = col3 == "VERIFIED";
            var badgePanel = new FlowLayoutPanel
            {
                Dock = DockStyle.Fill,
                FlowDirection = FlowDirection.LeftToRight,
                WrapContents = false,
                BackColor = Color.Transparent,
                Margin = new Padding(0)
            };
            var badge = new Label
            {
                Text = isVerified ? "● VERIFIED" : "▲ WARNING",
                Font = new Font("Segoe UI", 7.8f, FontStyle.Bold),
                ForeColor = isVerified ? Color.FromArgb(52, 211, 153) : Color.FromArgb(248, 113, 113),
                BackColor = isVerified ? SuccessBadgeBg : DangerBadgeBg,
                Padding = new Padding(6, 2, 6, 2),
                AutoSize = true,
                Margin = new Padding(0, 3, 0, 0),
                TextAlign = ContentAlignment.MiddleCenter
            };
            badgePanel.Controls.Add(badge);
            c3 = badgePanel;
        }

        tbl.Controls.Add(l1, 0, row);
        tbl.Controls.Add(l2, 1, row);
        tbl.Controls.Add(c3, 2, row);
    }
    #endregion

    #region Helper UI Builders
    private static TableLayoutPanel CreateSectionCard(string title, Control contentControl)
    {
        var card = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 2,
            BackColor = BgCard,
            Margin = new Padding(4),
            Padding = new Padding(0)
        };
        card.RowStyles.Add(new RowStyle(SizeType.Absolute, 34f)); // Header Row
        card.RowStyles.Add(new RowStyle(SizeType.Percent, 100f));  // Content Row

        card.Paint += (s, e) =>
        {
            using var pen = new Pen(BorderColor, 1);
            e.Graphics.DrawRectangle(pen, 0, 0, card.Width - 1, card.Height - 1);
        };

        var header = new Panel
        {
            Dock = DockStyle.Fill,
            BackColor = Color.FromArgb(24, 34, 52),
            Padding = new Padding(12, 0, 12, 0)
        };
        header.Paint += (s, e) =>
        {
            using var pen = new Pen(BorderColor, 1);
            e.Graphics.DrawLine(pen, 0, header.Height - 1, header.Width, header.Height - 1);
        };

        var lbl = new Label
        {
            Text = title.ToUpperInvariant(),
            Font = new Font("Segoe UI", 9f, FontStyle.Bold),
            ForeColor = TextSecondary,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleLeft
        };
        header.Controls.Add(lbl);

        contentControl.Dock = DockStyle.Fill;

        card.Controls.Add(header, 0, 0);
        card.Controls.Add(contentControl, 0, 1);

        return card;
    }

    private static Label CreateFormLabel(string text)
    {
        return new Label
        {
            Text = text,
            Font = new Font("Segoe UI", 9.5f, FontStyle.Bold),
            ForeColor = TextSecondary,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleLeft
        };
    }

    private static TextBox CreateFormTextBox(string val)
    {
        return new TextBox
        {
            Text = val,
            BackColor = Color.FromArgb(15, 23, 42),
            ForeColor = TextPrimary,
            BorderStyle = BorderStyle.FixedSingle,
            Font = new Font("Segoe UI", 9.5f),
            Dock = DockStyle.Fill
        };
    }

    public void ShowAndBringToFront()
    {
        if (this.IsDisposed) return;
        if (this.InvokeRequired)
        {
            this.BeginInvoke(new Action(ShowAndBringToFront));
            return;
        }

        if (this.WindowState == FormWindowState.Minimized)
            this.WindowState = FormWindowState.Normal;

        this.Show();
        this.BringToFront();
        this.Activate();

        // Refresh Vision Software & AI Model auditing when user opens Monitoring Console
        RefreshVisionAuditingData();
    }

    private void MainWindowForm_FormClosing(object? sender, FormClosingEventArgs e)
    {
        if (e.CloseReason == CloseReason.UserClosing && !AllowActualClose)
        {
            e.Cancel = true;
            this.Hide();

            _trayIcon.ShowBalloonTip(
                2000,
                "NGC Telemetry Agent",
                "Agent minimized to system tray. Telemetry collection and scheduled backups remain active.",
                ToolTipIcon.Info);
        }
    }
    #endregion
}

public class StatCard : Panel
{
    private readonly Label _lblTitle;
    private readonly Label _lblVal;
    private readonly Label _lblSub;

    [System.ComponentModel.DesignerSerializationVisibility(System.ComponentModel.DesignerSerializationVisibility.Hidden)]
    public string Value
    {
        get => _lblVal.Text;
        set => _lblVal.Text = value;
    }

    [System.ComponentModel.DesignerSerializationVisibility(System.ComponentModel.DesignerSerializationVisibility.Hidden)]
    public string Subtitle
    {
        get => _lblSub.Text;
        set => _lblSub.Text = value;
    }

    [System.ComponentModel.DesignerSerializationVisibility(System.ComponentModel.DesignerSerializationVisibility.Hidden)]
    public Color ValueColor
    {
        get => _lblVal.ForeColor;
        set => _lblVal.ForeColor = value;
    }

    public StatCard(string title, string initialVal, string sub, Color valColor)
    {
        this.Dock = DockStyle.Fill;
        this.BackColor = Color.FromArgb(30, 41, 59);
        this.Margin = new Padding(4);
        this.Padding = new Padding(14, 10, 14, 10);

        this.Paint += (s, e) =>
        {
            using var pen = new Pen(Color.FromArgb(51, 65, 85), 1);
            e.Graphics.DrawRectangle(pen, 0, 0, this.Width - 1, this.Height - 1);
        };

        _lblTitle = new Label
        {
            Text = title,
            Font = new Font("Segoe UI", 8.5f, FontStyle.Bold),
            ForeColor = Color.FromArgb(148, 163, 184),
            Dock = DockStyle.Top,
            Height = 20
        };

        _lblVal = new Label
        {
            Text = initialVal,
            Font = new Font("Segoe UI", 16f, FontStyle.Bold),
            ForeColor = valColor,
            Dock = DockStyle.Top,
            Height = 35
        };

        _lblSub = new Label
        {
            Text = sub,
            Font = new Font("Segoe UI", 8.5f),
            ForeColor = Color.FromArgb(100, 116, 139),
            Dock = DockStyle.Fill
        };

        this.Controls.Add(_lblSub);
        this.Controls.Add(_lblVal);
        this.Controls.Add(_lblTitle);
    }
}
