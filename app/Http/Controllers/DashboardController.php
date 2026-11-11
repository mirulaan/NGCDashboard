<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\TelemetryLog;

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
                                // Adding these just in case you need them later
                                'PhysicalDiskNumber' => $ssd->physical_disk_number,
                                'DiskModel' => $ssd->disk_model,
                                'TemperatureCelsius' => $ssd->temperature_celsius
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

        // Save telemetry data to database
        $log = TelemetryLog::create([
            'plant' => $plant,
            'line' => $line,
            'side' => $side,
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
        if (!empty($validated['SSD_Status'])) {
            foreach ($validated['SSD_Status'] as $ssd) {
                $log->ssdLogs()->create([
                    'disk_model' => $ssd['DiskModel'] ?? null,
                    'physical_disk_number' => $ssd['PhysicalDiskNumber'] ?? null,
                    'drive_letter' => $ssd['DriveLetter'] ?? null,
                    'total_space_gb' => $ssd['TotalSpaceGB'] ?? null,
                    'remaining_space_gb' => $ssd['RemainingSpaceGB'] ?? null,
                    'temperature_celsius' => $ssd['TemperatureCelsius'] ?? null,
                    'percentage_used' => $ssd['PercentageUsed'] ?? null,
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
        $key = "{$plant}-{$line}-{$side}";
        $matrix = Cache::get('plant_matrix', []);

        // Fetch historical data for the table
        $history = TelemetryLog::with('ssdLogs')
            ->where('plant', $plant)
            ->where('line', $line)
            ->where('side', $side)
            ->latest()
            ->take(10)
            ->get();

        // If the node has data in cache, use it. Otherwise, rebuild from latest DB record
        $latestRecord = $history->first();
        if (isset($matrix[$key])) {
            $data = $matrix[$key];
        } elseif ($latestRecord) {
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
                ];
            }
            $data = [
                'Plant' => $latestRecord->plant,
                'Line' => $latestRecord->line,
                'Tier 1' => $latestRecord->tier_1 ?? '-',
                'Tier 2' => $latestRecord->tier_2 ?? '-',
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
                ], fn($val) => !is_null($val)),
                'SSD_Status' => $ssdArray,
                'PC Thermal' => [
                    'CPU Temp' => $latestRecord->cpu_temp,
                    'CPU Thermal Throttle' => $latestRecord->cpu_thermal_throttle,
                    'GPU Temp' => $latestRecord->gpu_temp,
                    'GPU Power' => $latestRecord->gpu_power,
                    'GPU Clock' => $latestRecord->gpu_clock,
                ],
            ];
        } else {
            $data = [
                'Plant' => $plant,
                'Line' => $line,
                'Tier 1' => '-',
                'Tier 2' => '-',
                'Version' => [],
                'Config Model' => [],
                'SSD_Status' => []
            ];
        }

        // NEW query for the Chart (Gets all logs from the last 30 days)
        $chartData = \App\Models\TelemetryLog::with('ssdLogs')
            ->where('plant', $plant)->where('line', $line)->where('side', $side)
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('created_at', 'asc') // Chart needs oldest to newest
            ->get();

        // Make sure to pass $chartData to the view
        return view('node', compact('data', 'plant', 'line', 'side', 'history', 'chartData'));
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