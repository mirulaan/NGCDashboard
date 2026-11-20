# NGC Dashboard - Technology Stack Specification

The **NGC Dashboard** is an on-premise industrial IoT and manufacturing line monitoring platform designed for visual and optical inspection plants (e.g., glove manufacturing lines). The stack spans three distinct architectural tiers: the **central web backend & API**, the **frontend dashboard interface**, and the **edge telemetry agents & hardware sensors**.

---

## 1. Backend Application & API

* **Runtime:** PHP 8.3+
* **Framework:** [Laravel 13.8](file:///d:/Acer-File/Training/VibeCode/NGCDashboard/composer.json#L13)
* **Authentication & API:**
  * Laravel Session & Cookie-based Authentication (`AuthController`)
  * [Laravel Sanctum 4.0](file:///d:/Acer-File/Training/VibeCode/NGCDashboard/composer.json#L14) for token-authenticated API endpoints
* **Database Layer:**
  * **Default Engine:** SQLite (embedded, zero-configuration local database)
  * **Supported Engines:** MySQL / MariaDB / PostgreSQL via Laravel Eloquent ORM
* **Remote File Transfer & Storage:**
  * [Flysystem SFTP v3](file:///d:/Acer-File/Training/VibeCode/NGCDashboard/composer.json#L16) (`league/flysystem-sftp-v3`) for remote model discovery and line backup verification
* **Cache & Queues:**
  * Database driver cache (`plant_matrix` real-time state store)
  * Database queue worker for asynchronous background SFTP synchronization (`FetchSftpBackups` job)
* **Testing & Quality Assurance:**
  * [Pest PHP 4.7](file:///d:/Acer-File/Training/VibeCode/NGCDashboard/composer.json#L26) & `pestphp/pest-plugin-laravel`
  * Laravel Pint (code style and formatting)
  * Laravel Pail (real-time log tailing)
  * Laravel Boost (developer tooling)

---

## 2. Frontend Interface

* **Templating Engine:** Laravel Blade (`resources/views/`)
* **Styling & Design System:**
  * [Tailwind CSS v4](file:///d:/Acer-File/Training/VibeCode/NGCDashboard/package.json#L13) via `@tailwindcss/vite`
  * Standalone offline distribution bundled in `public/js/tailwindcss.js`
* **Asset Bundling & Build Pipeline:**
  * [Vite 8.0](file:///d:/Acer-File/Training/VibeCode/NGCDashboard/package.json#L14)
  * [laravel-vite-plugin 3.1](file:///d:/Acer-File/Training/VibeCode/NGCDashboard/package.json#L12)
* **Data Visualization:**
  * Chart.js v4 with `chartjs-plugin-trendline` (bundled locally in `public/js/` for 30-day wear/telemetry trend analysis)
* **Typography & Fonts:**
  * Self-hosted *Instrument Sans* & *Plus Jakarta Sans* (located in `public/fonts/` and `public/css/fonts.css`)
* **Air-Gapped & Offline Architecture:**
  * Zero external CDN dependencies; all scripts, stylesheets, fonts, and assets are hosted locally on-premise.

---

## 3. Edge Telemetry Agents & Diagnostics

The edge device telemetry and status monitoring tier consists of two complementary components: the **VisionGuard Desktop Status Monitor** (interactive operator WPF application) and the **NGC Telemetry Agent** (headless background telemetry & backup daemon).

### 3.1 VisionGuard — Industrial Device Status Monitor (WPF)
* **Project:** [DeviceStatusMonitor.csproj](file:///d:/Acer-File/Training/VibeCode/Device-Status/DeviceStatusMonitor.csproj)
* **Target Framework:** [.NET 9.0](file:///d:/Acer-File/Training/VibeCode/Device-Status/DeviceStatusMonitor.csproj#L5) (`net9.0-windows`)
* **Language:** C# 13 (Nullable reference types enabled, implicit global usings enabled)
* **Dependencies:** **Zero external third-party NuGet packages** — built entirely using .NET Base Class Libraries (BCL).
* **UI Framework & Design Pattern:**
  * Windows Presentation Foundation (**WPF**) with Model-View-ViewModel (**MVVM**)
  * Slate dark theme with dynamic XAML styles, control templates, and hardware-accelerated vector icons ([Resources/ModernStyles.xaml](file:///d:/Acer-File/Training/VibeCode/Device-Status/Resources/ModernStyles.xaml))
  * Value converters for status brushes, device types, and visibility ([Converters/Converters.cs](file:///d:/Acer-File/Training/VibeCode/Device-Status/Converters/Converters.cs))
  * `ViewModelBase` (`INotifyPropertyChanged`), `RelayCommand` (`ICommand`), and `ObservableCollection<T>`
* **Network Diagnostics Subsystem ([Services/NetworkScannerService.cs](file:///d:/Acer-File/Training/VibeCode/Device-Status/Services/NetworkScannerService.cs)):**
  * **ICMP Ping:** High-precision asynchronous ping (`SendPingAsync`) with rolling 6-ping sparklines
  * **TCP Socket Diagnostics:** Direct socket connectivity testing (`ConnectAsync`) for camera control interfaces, GigE ports, and PLC/Modbus endpoints
  * **Concurrency Throttling:** `System.Threading.SemaphoreSlim` limits concurrent diagnostics to 16 workers
  * **Latency Thresholds:** Automatic detection of degraded connections (>120 ms ping or >150 ms TCP)
* **Configuration & Persistence ([Services/ConfigService.cs](file:///d:/Acer-File/Training/VibeCode/Device-Status/Services/ConfigService.cs)):**
  * JSON serialization via `System.Text.Json`
  * Multi-tier (*Tier Top / Tier Bottom*, *Tier 1 / Tier 2*) and multi-station (*Pre*, *GSS*, *Post*, *FormerVision*) topology
  * Multi-path fallback persistence (`%APPDATA%\DeviceStatusMonitor\devices.json` with local [config/devices.json](file:///d:/Acer-File/Training/VibeCode/Device-Status/config/devices.json))
  * Timestamped CSV audit export (`exports/DeviceStatus_Log_YYYYMMDD_HHMMSS.csv`)
* **Audio & Alert Subsystem ([Services/SoundAlertService.cs](file:///d:/Acer-File/Training/VibeCode/Device-Status/Services/SoundAlertService.cs)):**
  * `System.Media.SystemSounds` with hardware frequency fallbacks (`System.Console.Beep`)
  * Debounce guards prevent audio stuttering during mass network disconnects or recoveries

### 3.2 NGC Telemetry Agent (Headless Daemon)
* **Project:** [NGCTelemetryAgent.csproj](file:///d:/Acer-File/Training/VibeCode/NGCDashboard/agent/NGCTelemetryAgent/NGCTelemetryAgent.csproj)
* **Target Framework:** [.NET 9.0](file:///d:/Acer-File/Training/VibeCode/NGCDashboard/agent/NGCTelemetryAgent/NGCTelemetryAgent.csproj#L5) (`net9.0-windows`), C# 13
* **Packaging:** Self-contained single-file win-x64 executable with compression (`PublishSingleFile=true`, `SelfContained=true`)
* **Execution:** System tray application context (`TrayApplicationContext.cs`) with automated Windows Task Scheduler autostart
* **Services:**
  * Telemetry collection (`TelemetryCollectorService.cs`): NVMe SMART (`smartctl`), CPU thermals (HWiNFO64 registry), GPU vitals (`nvidia-smi`), and Accusys HAIVIs config
  * Telemetry dispatcher (`TelemetryDispatcherService.cs`): Periodic HTTP POST to Dashboard API (`/api/system-status`)
  * Daily backup manager (`DailyBackupService.cs`): CPU-throttled daily plant backup at 6:00 PM with SFTP sync
  * Distribution Builder: [Build-AgentPackage.ps1](file:///d:/Acer-File/Training/VibeCode/NGCDashboard/agent/Build-AgentPackage.ps1) (.NET 9 offline packaging pipeline)

---

## 4. Hosting & Deployment Environment

* **Target Network:** Fully air-gapped / on-premise industrial plant local area network (LAN)
* **Supported Web Servers:**
  * Microsoft IIS (configured via `public/web.config`)
  * Nginx
  * Apache (configured via `public/.htaccess`)
* **External Integrations:**
  * Central SFTP storage server (`/Software/Model/` and `/BACKUP/Plant_{plant}/`)
  * External Configuration API (`CONFIG_API_URL`) for remote AI model weight dispatch
