<?php

namespace App\Services;

use App\Models\TelemetryLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;

class PlantService
{
    /**
     * Get all configured plant topologies.
     */
    public static function getAllPlants(): array
    {
        return config('telemetry.plants', [
            '7' => [
                'plant' => '7',
                'name'  => 'Plant 7',
                'lines' => range(73, 78),
                'sides' => ['L', 'R'],
            ],
            '8' => [
                'plant' => '8',
                'name'  => 'Plant 8',
                'lines' => array_merge(range(83, 92), [105]),
                'sides' => ['L', 'R'],
            ],
            '9' => [
                'plant' => '9',
                'name'  => 'Plant 9',
                'lines' => range(93, 104),
                'sides' => ['L', 'R'],
            ],
        ]);
    }

    /**
     * Get topology configuration for a specific plant ID.
     */
    public static function getTopology(string $plant): array
    {
        $allPlants = self::getAllPlants();
        if (isset($allPlants[$plant])) {
            return $allPlants[$plant];
        }

        $defaultPlant = (string) config('telemetry.default_plant', '9');
        return $allPlants[$defaultPlant] ?? [
            'plant' => $plant,
            'name'  => "Plant {$plant}",
            'lines' => range(93, 104),
            'sides' => ['L', 'R'],
        ];
    }

    /**
     * Auto-detect the manufacturing plant this server is located in.
     *
     * Strategies:
     * 1. Telemetry Database: Identify the plant actively reporting edge telemetry.
     * 2. Hostname / Computer Name: Check if server hostname indicates plant number.
     * 3. Network Subnet: Match local IPv4 addresses against known plant subnets.
     * 4. Default Fallback: Plant 9.
     */
    public static function detectPlant(): array
    {
        $allPlants = self::getAllPlants();

        // ---------------------------------------------------------
        // Strategy 1: Database Telemetry Activity
        // ---------------------------------------------------------
        try {
            $latestPlant = DB::table('telemetry_logs')->orderByDesc('id')->value('plant');
            if (!empty($latestPlant) && isset($allPlants[(string) $latestPlant])) {
                $plantKey = (string) $latestPlant;
                $count = DB::table('telemetry_logs')->where('plant', $plantKey)->count();
                return [
                    'plant'  => $plantKey,
                    'source' => "Database ({$count} logs recorded for Plant {$plantKey})",
                ];
            }
        } catch (\Throwable $e) {
            Log::debug('Plant auto-detection DB check skipped: ' . $e->getMessage());
        }

        // ---------------------------------------------------------
        // Strategy 2: Hostname / Computer Name
        // ---------------------------------------------------------
        $hostname = strtolower((string) (gethostname() ?: (getenv('COMPUTERNAME') ?: '')));
        if (!empty($hostname)) {
            if (preg_match('/plant[-_ ]?([789])/i', $hostname, $m) && isset($allPlants[$m[1]])) {
                return [
                    'plant'  => $m[1],
                    'source' => "Server Hostname ({$hostname})",
                ];
            }
            if (preg_match('/(?:^|[^a-z0-9])p([789])(?:$|[^a-z0-9])/i', $hostname, $m) && isset($allPlants[$m[1]])) {
                return [
                    'plant'  => $m[1],
                    'source' => "Server Hostname ({$hostname})",
                ];
            }
        }

        // ---------------------------------------------------------
        // Strategy 3: Server IPv4 Subnet Matching
        // ---------------------------------------------------------
        $serverIps = self::getServerIps();
        $subnetMap = config('telemetry.subnet_map', []);
        foreach ($serverIps as $ip) {
            foreach ($subnetMap as $plantKey => $prefixes) {
                foreach ((array) $prefixes as $prefix) {
                    if (str_starts_with($ip, $prefix) && isset($allPlants[(string) $plantKey])) {
                        return [
                            'plant'  => (string) $plantKey,
                            'source' => "Subnet Match ({$ip})",
                        ];
                    }
                }
            }
        }

        // ---------------------------------------------------------
        // Strategy 4: Default Fallback
        // ---------------------------------------------------------
        $default = (string) config('telemetry.default_plant', '9');
        return [
            'plant'  => $default,
            'source' => "Default Fallback (Plant {$default})",
        ];
    }

    /**
     * Resolve the active plant for the current context/request.
     */
    public static function resolve(?Request $request = null): array
    {
        $allPlants = self::getAllPlants();
        $selectedPlant = null;
        $source = null;

        // 1. Manual user override via Query Parameter ?plant=...
        if ($request && $request->has('plant')) {
            $queryPlant = (string) $request->query('plant');
            if (strtolower($queryPlant) === 'auto') {
                if (session()) {
                    Session::forget('selected_plant');
                }
            } elseif (isset($allPlants[$queryPlant])) {
                $selectedPlant = $queryPlant;
                $source = "Manual Selection (Plant {$selectedPlant})";
                if (session()) {
                    Session::put('selected_plant', $selectedPlant);
                }
            }
        }

        // 2. Persistent session choice (if user previously switched plants in web UI)
        if (!$selectedPlant && session() && Session::has('selected_plant')) {
            $sessionPlant = (string) Session::get('selected_plant');
            if (isset($allPlants[$sessionPlant])) {
                $selectedPlant = $sessionPlant;
                $source = "Manual Selection (Plant {$selectedPlant})";
            }
        }

        // 3. Fixed configuration in .env / config (e.g. TELEMETRY_PLANT=7)
        if (!$selectedPlant) {
            $configuredPlant = (string) config('telemetry.configured_plant', env('TELEMETRY_PLANT', 'auto'));
            if ($configuredPlant !== 'auto' && isset($allPlants[$configuredPlant])) {
                $selectedPlant = $configuredPlant;
                $source = "Configured (.env: Plant {$selectedPlant})";
            }
        }

        // 4. Automatic detection
        $isAuto = false;
        if (!$selectedPlant) {
            $detected = self::detectPlant();
            $selectedPlant = $detected['plant'];
            $source = 'Auto: ' . $detected['source'];
            $isAuto = true;
        }

        // Fetch topology details
        $topology = self::getTopology($selectedPlant);

        // Dynamically synchronize runtime configuration
        self::syncConfig($selectedPlant, $topology);

        return [
            'plant'       => $selectedPlant,
            'name'        => $topology['name'] ?? "Plant {$selectedPlant}",
            'lines'       => $topology['lines'],
            'sides'       => $topology['sides'],
            'source'      => $source,
            'is_auto'     => $isAuto,
            'all_plants'  => $allPlants,
        ];
    }

    /**
     * Initialize configuration early in application lifecycle (e.g. AppServiceProvider).
     */
    public static function syncInitialConfig(): void
    {
        $allPlants = self::getAllPlants();
        $configuredPlant = (string) config('telemetry.configured_plant', env('TELEMETRY_PLANT', 'auto'));

        if ($configuredPlant !== 'auto' && isset($allPlants[$configuredPlant])) {
            $topology = self::getTopology($configuredPlant);
            self::syncConfig($configuredPlant, $topology);
            return;
        }

        // If auto, run lightweight auto-detection
        $detected = self::detectPlant();
        $topology = self::getTopology($detected['plant']);
        self::syncConfig($detected['plant'], $topology);
    }

    /**
     * Update runtime in-memory config for config('telemetry.*').
     */
    public static function syncConfig(string $plant, ?array $topology = null): void
    {
        $topology = $topology ?? self::getTopology($plant);

        config([
            'telemetry.plant' => $plant,
            'telemetry.lines' => $topology['lines'],
            'telemetry.sides' => $topology['sides'],
        ]);
    }

    /**
     * Retrieve local IPv4 addresses safely across platforms.
     */
    protected static function getServerIps(): array
    {
        $ips = [];
        if (!empty($_SERVER['SERVER_ADDR'])) {
            $ips[] = $_SERVER['SERVER_ADDR'];
        }
        if (!empty($_SERVER['LOCAL_ADDR'])) {
            $ips[] = $_SERVER['LOCAL_ADDR'];
        }

        $host = gethostname();
        if ($host) {
            $hostIps = @gethostbynamel($host);
            if (is_array($hostIps)) {
                $ips = array_merge($ips, $hostIps);
            }
        }

        return array_values(array_unique(array_filter(
            $ips,
            fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($ip, '127.')
        )));
    }
}
