<?php

namespace App\Http\Controllers;

use App\Models\TelemetryLog;
use App\Services\NodeReportExcelService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function fleet(Request $request)
    {
        $plantInfo = \App\Services\PlantService::resolve($request);
        $plant = $plantInfo['plant']; 
        $lines = $plantInfo['lines'];
        $sides = $plantInfo['sides'];
        $allPlants = $plantInfo['all_plants'];
        $plantSource = $plantInfo['source'];
        $isAuto = $plantInfo['is_auto'];

        $cachedData = Cache::get('plant_matrix', []);
        $grid = [];

        foreach ($lines as $line) {
            foreach ($sides as $side) {
                $key = "{$plant}-{$line}-{$side}";
                
                // 1. Fetch latest log AND its attached SSDs from the database
                $latestLog = \App\Models\TelemetryLog::with('ssdLogs')
                    ->where('plant', $plant)
                    ->where('line', $line)
                    ->where('side', $side)
                    ->latest()
                    ->first();

                // 2. THE 30-MINUTE RULE
                $isOnline = $latestLog && $latestLog->created_at >= now()->subMinutes(30);

                // 3. Get data from cache first (Fastest)
                $nodeData = $cachedData[$key] ?? null;

                // 4. THE FIX: If cache is empty but DB has old data (happens when offline)
                // We rebuild the JSON structure manually from the database row!
                if (!$nodeData && $latestLog) {
                    $ssdArray = [];
                    if ($latestLog->ssdLogs && $latestLog->ssdLogs->isNotEmpty()) {
                        foreach ($latestLog->ssdLogs as $ssd) {
                            $ssdArray[] = [
                                'PercentageUsed' => $ssd->percentage_used,
                                'TotalSpaceGB' => $ssd->total_space_gb,
                                'RemainingSpaceGB' => $ssd->remaining_space_gb,
                                'DriveLetter' => $ssd->drive_letter,
                                'PhysicalDiskNumber' => $ssd->physical_disk_number,
                                'DiskModel' => $ssd->disk_model,
                                'TemperatureCelsius' => $ssd->temperature_celsius,
                                'CriticalWarning' => $ssd->critical_warning,
                                'MediaErrors' => $ssd->media_errors,
                                'ErrorLogEntries' => $ssd->error_log_entries,
                                'UnsafeShutdowns' => $ssd->unsafe_shutdowns,
                                'WarningTempTime' => $ssd->warning_temp_time,
                                'CriticalTempTime' => $ssd->critical_temp_time,
                                'AvailableSpare' => $ssd->available_spare,
                            ];
                        }
                    }
                    $nodeData = [
                        'SSD_Status' => $ssdArray,
                        'PC Thermal' => [
                            'CPU Temp' => $latestLog->cpu_temp,
                            'CPU Thermal Throttle' => $latestLog->cpu_thermal_throttle,
                            'GPU Temp' => $latestLog->gpu_temp,
                            'GPU Power' => $latestLog->gpu_power,
                            'GPU Clock' => $latestLog->gpu_clock,
                        ],
                    ];
                }

                $grid[] = [
                    'plant'  => $plant,
                    'line'   => $line,
                    'side'   => $side,
                    'status' => $isOnline, 
                    'data'   => $nodeData, 
                    'ip_address' => $latestLog?->ip_address,
                ];
            }
        }

        return view('fleet', compact('grid', 'plant', 'lines', 'sides', 'allPlants', 'plantInfo', 'plantSource', 'isAuto'));
    }

    // Updated webhook to sort into the correct line/side bucket
    public function store(Request $request)
    {
        $validated = $request->validate([
            'Plant' => 'required|string',
            'Line' => 'required|string',
            'Tier 1' => 'required|string',
            'Tier 2' => 'nullable|string',
            'IpAddress' => 'nullable|string',
            'Agent' => 'nullable|array',
            'Agent.Version' => 'nullable|string',
            'Version' => 'nullable|array',
            'Config Model' => 'nullable|array',
            'SSD_Status' => 'nullable|array',
            // ADD THESE 3 LINES:
            'PC Thermal' => 'nullable|array',
            'PC Thermal.CPU Temp' => 'nullable|string',
            'PC Thermal.CPU Thermal Throttle' => 'nullable|string',

            // ADD THESE 3 NEW GPU RULES:
            'PC Thermal.GPU Temp' => 'nullable|string',
            'PC Thermal.GPU Power' => 'nullable|string',
            'PC Thermal.GPU Clock' => 'nullable|string',
        ]);

        // Fix PowerShell's 1-item JSON quirk: If it's a single flat object, wrap it in an array
        if (isset($validated['SSD_Status']['DriveLetter'])) {
            $validated['SSD_Status'] = [$validated['SSD_Status']];
        }
        // ---------------------------

        $tier1 = strtoupper($validated['Tier 1']);
        $side = str_starts_with($tier1, 'L') ? 'L' : 'R';
        
        $plant = $validated['Plant'];
        $line = $validated['Line'];
        
        $key = "{$plant}-{$line}-{$side}";

        $clientIp = !empty($validated['IpAddress']) ? $validated['IpAddress'] : $request->ip();
        $validated['IpAddress'] = $clientIp;
        $agentVersion = $validated['Agent']['Version'] ?? $request->input('Agent.Version') ?? null;

        // Save telemetry data to database
        $log = TelemetryLog::create([
            'plant' => $plant,
            'line' => $line,
            'side' => $side,
            'ip_address' => $clientIp,
            'agent_version' => $agentVersion,
            'tier_1' => $validated['Tier 1'] ?? null,
            'tier_2' => $validated['Tier 2'] ?? null,
            'version_solw' => $validated['Version']['solw'] ?? null,
            'version_middleware' => $validated['Version']['MiddleWare'] ?? null,
            'version_dimr' => $validated['Version']['DIMR'] ?? null,
            'version_ioc' => $validated['Version']['IOC.bas'] ?? null,
            
            // ADDED: Read new "SkuMode" key
            'config_sku_mode' => $validated['Config Model']['SkuMode'] ?? null,
            
            'config_post' => $validated['Config Model']['Post'] ?? null,
            'config_noglove' => $validated['Config Model']['NoGlove'] ?? null,
            'config_pre' => $validated['Config Model']['Pre'] ?? null,
            'config_gss' => $validated['Config Model']['GSS Defect'] ?? $validated['Config Model']['GSS'] ?? null,
            'config_post_black' => $validated['Config Model']['Post Black'] ?? null,
            'config_noglove_black' => $validated['Config Model']['NoGlove Black'] ?? null,
            'config_pre_black' => $validated['Config Model']['Pre Black'] ?? null,
            'config_gss_black' => $validated['Config Model']['GSS Black'] ?? null,
            'config_gss_machine' => $validated['Config Model']['GSS Machine'] ?? null,

            'cpu_temp' => $validated['PC Thermal']['CPU Temp'] ?? null,
            'cpu_thermal_throttle' => $validated['PC Thermal']['CPU Thermal Throttle'] ?? null,
            'gpu_temp' => $validated['PC Thermal']['GPU Temp'] ?? null,
            'gpu_power' => $validated['PC Thermal']['GPU Power'] ?? null,
            'gpu_clock' => $validated['PC Thermal']['GPU Clock'] ?? null,
        ]);

        // 2. Loop through SSDs and save them linked to this log
        if (! empty($validated['SSD_Status'])) {
            foreach ($validated['SSD_Status'] as $idx => $ssd) {
                $criticalWarning = $this->parseSmartInt($ssd['CriticalWarning'] ?? $ssd['critical_warning'] ?? null);
                $mediaErrors = $this->parseSmartInt($ssd['MediaErrors'] ?? $ssd['media_errors'] ?? null);
                $errorLogEntries = $this->parseSmartInt($ssd['ErrorLogEntries'] ?? $ssd['error_log_entries'] ?? $ssd['num_err_log_entries'] ?? null);
                $unsafeShutdowns = $this->parseSmartInt($ssd['UnsafeShutdowns'] ?? $ssd['unsafe_shutdowns'] ?? null);
                $warningTempTime = $this->parseSmartInt($ssd['WarningTempTime'] ?? $ssd['warning_temp_time'] ?? null);
                $criticalTempTime = $this->parseSmartInt($ssd['CriticalTempTime'] ?? $ssd['critical_temp_time'] ?? null);
                $availableSpare = $this->parseSmartInt($ssd['AvailableSpare'] ?? $ssd['AvailableSparePercent'] ?? $ssd['available_spare'] ?? null);

                // Normalize cached SSD_Status payload so views see consistent integer SMART fields
                $validated['SSD_Status'][$idx]['CriticalWarning'] = $criticalWarning;
                $validated['SSD_Status'][$idx]['MediaErrors'] = $mediaErrors;
                $validated['SSD_Status'][$idx]['ErrorLogEntries'] = $errorLogEntries;
                $validated['SSD_Status'][$idx]['UnsafeShutdowns'] = $unsafeShutdowns;
                $validated['SSD_Status'][$idx]['WarningTempTime'] = $warningTempTime;
                $validated['SSD_Status'][$idx]['CriticalTempTime'] = $criticalTempTime;
                $validated['SSD_Status'][$idx]['AvailableSpare'] = $availableSpare;

                $log->ssdLogs()->create([
                    'disk_model' => $ssd['DiskModel'] ?? null,
                    'physical_disk_number' => $ssd['PhysicalDiskNumber'] ?? null,
                    'drive_letter' => $ssd['DriveLetter'] ?? null,
                    'total_space_gb' => $ssd['TotalSpaceGB'] ?? null,
                    'remaining_space_gb' => $ssd['RemainingSpaceGB'] ?? null,
                    'temperature_celsius' => $ssd['TemperatureCelsius'] ?? null,
                    'percentage_used' => $ssd['PercentageUsed'] ?? null,
                    'critical_warning' => $criticalWarning,
                    'media_errors' => $mediaErrors,
                    'error_log_entries' => $errorLogEntries,
                    'unsafe_shutdowns' => $unsafeShutdowns,
                    'warning_temp_time' => $warningTempTime,
                    'critical_temp_time' => $criticalTempTime,
                    'available_spare' => $availableSpare,
                ]);
            }
        }

        // 3. Save to Cache for real-time dashboard speed
        $matrix = Cache::get('plant_matrix', []);
        $matrix[$key] = $validated;
        
        Cache::forever('plant_matrix', $matrix);

        return response()->json(['message' => "Node {$key} updated and logged successfully"], 200);
    }

    // Displays the detailed node view
    public function show($plant, $line, $side)
    {
        // Fetch historical data for the table
        $history = TelemetryLog::with('ssdLogs')
            ->where('plant', $plant)
            ->where('line', $line)
            ->where('side', $side)
            ->latest()
            ->take(10)
            ->get();

        $data = $this->resolveNodeData($plant, $line, $side, $history->first());

        // Lightweight 30-day chart payload (avoids hydrating thousands of Eloquent models in memory)
        $chartData = $this->buildNodeChartData($plant, $line, $side);

        // Make sure to pass $chartData to the view
        return view('node', compact('data', 'plant', 'line', 'side', 'history', 'chartData'));
    }

    /**
     * Build a compact, memory-safe 30-day chart dataset for the node view.
     * Keeps full resolution for recent 24h logs and downsamples older history to prevent memory exhaustion.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildNodeChartData($plant, $line, $side): array
    {
        $now = now();
        $thirtyDaysAgo = $now->copy()->subDays(30)->format('Y-m-d H:i:s');
        $twentyFourHoursAgo = $now->copy()->subDay()->format('Y-m-d H:i:s');

        $selectedLogs = [];

        // 1. Older history (30 days ago .. 24 hours ago): stream via cursor and cap to ~1,000 points
        $olderCount = DB::table('telemetry_logs')
            ->where('plant', $plant)
            ->where('line', $line)
            ->where('side', $side)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->where('created_at', '<', $twentyFourHoursAgo)
            ->count();

        if ($olderCount > 0) {
            $olderStep = $olderCount > 1000 ? (int) ceil($olderCount / 1000) : 1;
            $idx = 0;
            $lastOlder = null;

            $olderCursor = DB::table('telemetry_logs')
                ->select('id', 'created_at', 'gpu_temp', 'gpu_power', 'gpu_clock')
                ->where('plant', $plant)
                ->where('line', $line)
                ->where('side', $side)
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->where('created_at', '<', $twentyFourHoursAgo)
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->cursor();

            foreach ($olderCursor as $row) {
                if ($idx % $olderStep === 0) {
                    $selectedLogs[] = $row;
                }
                $lastOlder = $row;
                $idx++;
            }

            if ($lastOlder !== null && (empty($selectedLogs) || end($selectedLogs)->id !== $lastOlder->id)) {
                $selectedLogs[] = $lastOlder;
            }
        }

        // 2. Recent 24 hours: keep full resolution (cap at ~1,500 points for extreme high-frequency nodes)
        $recentCount = DB::table('telemetry_logs')
            ->where('plant', $plant)
            ->where('line', $line)
            ->where('side', $side)
            ->where('created_at', '>=', $twentyFourHoursAgo)
            ->count();

        if ($recentCount > 0) {
            $recentStep = $recentCount > 1500 ? (int) ceil($recentCount / 1500) : 1;
            $idx = 0;
            $lastRecent = null;

            $recentCursor = DB::table('telemetry_logs')
                ->select('id', 'created_at', 'gpu_temp', 'gpu_power', 'gpu_clock')
                ->where('plant', $plant)
                ->where('line', $line)
                ->where('side', $side)
                ->where('created_at', '>=', $twentyFourHoursAgo)
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->cursor();

            foreach ($recentCursor as $row) {
                if ($idx % $recentStep === 0) {
                    $selectedLogs[] = $row;
                }
                $lastRecent = $row;
                $idx++;
            }

            if ($lastRecent !== null && (empty($selectedLogs) || end($selectedLogs)->id !== $lastRecent->id)) {
                $selectedLogs[] = $lastRecent;
            }
        }

        if (empty($selectedLogs)) {
            return [];
        }

        // 3. Fetch only SSD wear fields needed by the chart for the selected log IDs (chunked for SQLite parameter limits)
        $ssdByLogId = [];
        $logIds = array_map(fn ($row) => $row->id, $selectedLogs);

        foreach (array_chunk($logIds, 500) as $idChunk) {
            $ssdRows = DB::table('telemetry_ssd_logs')
                ->select('telemetry_log_id', 'drive_letter', 'percentage_used')
                ->whereIn('telemetry_log_id', $idChunk)
                ->get();

            foreach ($ssdRows as $ssd) {
                $ssdByLogId[$ssd->telemetry_log_id][] = [
                    'drive_letter' => $ssd->drive_letter,
                    'percentage_used' => $ssd->percentage_used,
                ];
            }
        }

        $chartData = [];
        foreach ($selectedLogs as $log) {
            $chartData[] = [
                'created_at' => Carbon::parse($log->created_at)->toJSON(),
                'gpu_temp' => $log->gpu_temp,
                'gpu_power' => $log->gpu_power,
                'gpu_clock' => $log->gpu_clock,
                'ssd_logs' => $ssdByLogId[$log->id] ?? [],
            ];
        }

        return $chartData;
    }

    // Generates and downloads a 1-month Excel (.xlsx) telemetry report for a single node
    public function exportReport($plant, $line, $side, NodeReportExcelService $excelService)
    {
        $latestRecord = TelemetryLog::with('ssdLogs')
            ->where('plant', $plant)
            ->where('line', $line)
            ->where('side', $side)
            ->latest('created_at')
            ->latest('id')
            ->first();

        $data = $this->resolveNodeData($plant, $line, $side, $latestRecord);

        $skuMode = isset($data['Config Model']['SkuMode']) ? (string) $data['Config Model']['SkuMode'] : null;
        $runningProduct = match ($skuMode) {
            '0' => 'Color Mode',
            '1' => 'Black Mode',
            '2' => 'White Mode',
            null => '-',
            default => 'Unknown Mode',
        };

        $nodeIp = ! empty($data['IpAddress']) ? $data['IpAddress'] : ($latestRecord?->ip_address ?? '-');
        $tier1 = $data['Tier 1'] ?? '-';
        $tier2 = $data['Tier 2'] ?? '-';

        $nodeMeta = [
            'plant' => $plant,
            'line' => $line,
            'side' => $side,
            'running_product' => $runningProduct,
            'ip_address' => $nodeIp,
            'tier_target' => "{$tier1} / {$tier2}",
            'solw' => $data['Version']['solw'] ?? '-',
            'middleware' => $data['Version']['MiddleWare'] ?? '-',
            'dimr' => $data['Version']['DIMR'] ?? '-',
            'ioc' => $data['Version']['IOC.bas'] ?? '-',
            'last_seen' => $latestRecord?->created_at?->format('Y-m-d H:i:s') ?? '-',
            'ssd_smart' => $data['SSD_Status'] ?? [],
        ];

        $periodEnd = now();
        $periodStart = $periodEnd->copy()->subDays(30);
        $cutoff = $periodStart->format('Y-m-d H:i:s');

        $ssdsByLog = [];
        $driveSet = [];

        $rawSsds = DB::table('telemetry_ssd_logs as s')
            ->join('telemetry_logs as t', 't.id', '=', 's.telemetry_log_id')
            ->select('s.telemetry_log_id', 's.drive_letter', 's.physical_disk_number', 's.temperature_celsius', 's.percentage_used')
            ->where('t.plant', $plant)
            ->where('t.line', $line)
            ->where('t.side', $side)
            ->where('t.created_at', '>=', $cutoff)
            ->orderBy('s.telemetry_log_id', 'asc')
            ->orderBy('s.drive_letter', 'asc')
            ->cursor();

        foreach ($rawSsds as $ssd) {
            $drive = trim((string) ($ssd->drive_letter ?? ''));
            if ($drive === '') {
                $drive = $ssd->physical_disk_number !== null ? "Disk {$ssd->physical_disk_number}" : 'SSD';
            }
            $driveSet[$drive] = true;
            $ssdsByLog[$ssd->telemetry_log_id][$drive] = [
                'temp' => $this->parseNumericMetric($ssd->temperature_celsius),
                'wear' => $this->parseNumericMetric($ssd->percentage_used),
            ];
        }

        $drives = array_keys($driveSet);
        sort($drives, SORT_NATURAL | SORT_FLAG_CASE);

        $rows = [];
        $rawLogs = DB::table('telemetry_logs')
            ->select('id', 'cpu_temp', 'created_at')
            ->where('plant', $plant)
            ->where('line', $line)
            ->where('side', $side)
            ->where('created_at', '>=', $cutoff)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->cursor();

        foreach ($rawLogs as $log) {
            $rows[] = [
                'timestamp' => (string) $log->created_at,
                'cpu_temp' => $this->parseNumericMetric($log->cpu_temp),
                'ssds' => $ssdsByLog[$log->id] ?? [],
            ];
            unset($ssdsByLog[$log->id]);
        }
        unset($ssdsByLog);

        $filePath = $excelService->generate($nodeMeta, $rows, $drives, $periodStart, $periodEnd);

        $safePlant = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $plant);
        $safeLine = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $line);
        $safeSide = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $side);
        $fileName = sprintf('NGC_Report_Plant%s_Line%s_%s_%s.xlsx', $safePlant, $safeLine, $safeSide, $periodEnd->format('Y-m-d'));

        return response()->download($filePath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function resolveNodeData($plant, $line, $side, ?TelemetryLog $latestRecord = null): array
    {
        $key = "{$plant}-{$line}-{$side}";
        $matrix = Cache::get('plant_matrix', []);

        if (isset($matrix[$key])) {
            $data = $matrix[$key];
            if (empty($data['IpAddress']) && $latestRecord && ! empty($latestRecord->ip_address)) {
                $data['IpAddress'] = $latestRecord->ip_address;
            }

            return $data;
        }

        if ($latestRecord) {
            $ssdArray = [];
            foreach ($latestRecord->ssdLogs as $ssd) {
                $ssdArray[] = [
                    'DiskModel' => $ssd->disk_model,
                    'PhysicalDiskNumber' => $ssd->physical_disk_number,
                    'DriveLetter' => $ssd->drive_letter,
                    'TotalSpaceGB' => $ssd->total_space_gb,
                    'RemainingSpaceGB' => $ssd->remaining_space_gb,
                    'TemperatureCelsius' => $ssd->temperature_celsius,
                    'PercentageUsed' => $ssd->percentage_used,
                    'CriticalWarning' => $ssd->critical_warning,
                    'MediaErrors' => $ssd->media_errors,
                    'ErrorLogEntries' => $ssd->error_log_entries,
                    'UnsafeShutdowns' => $ssd->unsafe_shutdowns,
                    'WarningTempTime' => $ssd->warning_temp_time,
                    'CriticalTempTime' => $ssd->critical_temp_time,
                    'AvailableSpare' => $ssd->available_spare,
                ];
            }

            return [
                'Plant' => $latestRecord->plant,
                'Line' => $latestRecord->line,
                'Tier 1' => $latestRecord->tier_1 ?? '-',
                'Tier 2' => $latestRecord->tier_2 ?? '-',
                'IpAddress' => $latestRecord->ip_address,
                'Version' => [
                    'solw' => $latestRecord->version_solw,
                    'MiddleWare' => $latestRecord->version_middleware,
                    'DIMR' => $latestRecord->version_dimr,
                    'IOC.bas' => $latestRecord->version_ioc,
                ],
                'Config Model' => array_filter([
                    'SkuMode' => $latestRecord->config_sku_mode,
                    'Post' => $latestRecord->config_post,
                    'NoGlove' => $latestRecord->config_noglove,
                    'Pre' => $latestRecord->config_pre,
                    'GSS Defect' => $latestRecord->config_gss,
                    'Post Black' => $latestRecord->config_post_black,
                    'NoGlove Black' => $latestRecord->config_noglove_black,
                    'Pre Black' => $latestRecord->config_pre_black,
                    'GSS Black' => $latestRecord->config_gss_black,
                    'GSS Machine' => $latestRecord->config_gss_machine,
                ], fn ($val) => ! is_null($val)),
                'SSD_Status' => $ssdArray,
                'PC Thermal' => [
                    'CPU Temp' => $latestRecord->cpu_temp,
                    'CPU Thermal Throttle' => $latestRecord->cpu_thermal_throttle,
                    'GPU Temp' => $latestRecord->gpu_temp,
                    'GPU Power' => $latestRecord->gpu_power,
                    'GPU Clock' => $latestRecord->gpu_clock,
                ],
            ];
        }

        return [
            'Plant' => $plant,
            'Line' => $line,
            'Tier 1' => '-',
            'Tier 2' => '-',
            'IpAddress' => null,
            'Version' => [],
            'Config Model' => [],
            'SSD_Status' => [],
        ];
    }

    private function parseNumericMetric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_string($value) && preg_match('/-?\d+(?:\.\d+)?/', $value, $matches)) {
            return (float) $matches[0];
        }

        return null;
    }

    private function parseSmartInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if (preg_match('/^0x([0-9a-f]+)$/i', $trimmed, $m)) {
                return (int) hexdec($m[1]);
            }
            if (preg_match('/^\d+$/', $trimmed)) {
                return (int) $trimmed;
            }
        }

        return null;
    }

    public function getPlantBackups($plant)
    {
        $directory = "/BACKUP/Plant_{$plant}";
        $backups = [];

        try {
            if (\Illuminate\Support\Facades\Storage::disk('sftp')->exists($directory)) {
                $files = \Illuminate\Support\Facades\Storage::disk('sftp')->files($directory);
                
                foreach ($files as $file) {
                    if (str_ends_with(strtolower($file), '.zip')) {
                        $sizeBytes = \Illuminate\Support\Facades\Storage::disk('sftp')->size($file);
                        $sizeFormatted = $sizeBytes > 1048576 
                            ? round($sizeBytes / 1048576, 2) . ' MB' 
                            : round($sizeBytes / 1024, 2) . ' KB';

                        $backups[] = [
                            'filename' => basename($file),
                            'size' => $sizeFormatted,
                            'changed_date' => date('Y-m-d H:i:s', \Illuminate\Support\Facades\Storage::disk('sftp')->lastModified($file))
                        ];
                    }
                }
            }
            return response()->json(['status' => 'success', 'data' => $backups]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}